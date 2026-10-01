<?php
declare(strict_types=1);

require_once __DIR__ . '/egm-database-runtime.php';
require_once __DIR__ . '/egm-security.php';
require_once dirname(__DIR__, 2) . '/api/lib/tab-permissions.php';
require_once dirname(__DIR__, 2) . '/api/lib/egm-period-invites.php';
require_once dirname(__DIR__, 2) . '/api/lib/egm-seat-map.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

try {
    $user = requireTabPermissionFromSession('event-guest-manager', true);
    $input = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
        ? json_decode((string)file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR)
        : $_GET;
    if (!is_array($input)) throw new InvalidArgumentException('درخواست نامعتبر است.');
    $periodCode = trim((string)($input['period_code'] ?? ''));
    $canManage = $periodCode === ''
        ? userHasPermissionId($user, 'event-guest-manager:main')
        : userHasPermissionId($user, 'event-guest-manager:manage-tasks');
    if (!$canManage) throw new RuntimeException('دسترسی به تنظیمات سالن مجاز نیست.', 403);
    $context = egmPeriodInvitesContext(__DIR__);
    if ($periodCode !== '') $periodCode = egmPeriodInvitesValidatePeriod($context, $periodCode);
    if ($context['code'] === '') throw new RuntimeException('سالن فقط برای EGM ثبت‌شده قابل تنظیم است.');
    $code = (string)$context['code'];
    $pdo = $context['pdo'];
    $key = $periodCode === '' ? 'seat_map:default' : 'seat_map:' . $periodCode;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!egmSecurityIsValidCsrfToken((string)($input['csrf'] ?? ''))) throw new RuntimeException('توکن امنیتی نامعتبر است.', 403);
        $map = null;
        if ($periodCode === '' || ($input['mode'] ?? '') !== 'inherit') {
            $map = egmSeatMapNormalize($input['map'] ?? []);
            egmSeatMapValidateTicket($context, $map);
        }
        $pdo->beginTransaction();
        try {
            egmSeatMapLock($context);
            if ($map !== null) {
                egmSeatMapValidateOccupied($context, $periodCode, $map);
                egmSeatMapValidateCapacity($context, $periodCode, $map);
            } elseif ($periodCode !== '') {
                $defaultMap = egmSeatMapNormalize(egmInstanceReadData($pdo, $code, 'seat_map:default', []));
                egmSeatMapValidateOccupied($context, $periodCode, $defaultMap);
                egmSeatMapValidateCapacity($context, $periodCode, $defaultMap);
            }
            egmInstanceWriteData($pdo, $code, $key, $map === null ? ['mode' => 'inherit'] : ($periodCode === '' ? $map : ['mode' => 'custom', 'map' => $map]));
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
    $stored = egmInstanceReadData($pdo, $code, $key, []);
    $default = egmInstanceReadData($pdo, $code, 'seat_map:default', []);
    echo json_encode([
        'status' => 'ok',
        'mode' => $periodCode === '' ? 'custom' : (is_array($stored) && ($stored['mode'] ?? '') === 'custom' ? 'custom' : 'inherit'),
        'map' => $periodCode === '' ? egmSeatMapNormalize($stored) : egmSeatMapNormalize($stored['map'] ?? $default),
        'default_map' => egmSeatMapNormalize($default),
        'tickets' => egmPeriodInvitesTicketDefinitions($context),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    $status = $error instanceof InvalidArgumentException ? 422 : ($error->getCode() === 403 ? 403 : 500);
    http_response_code($status);
    echo json_encode(['status' => 'error', 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
