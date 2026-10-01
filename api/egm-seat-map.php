<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/common.php';
require_once __DIR__ . '/lib/tab-permissions.php';
require_once __DIR__ . '/lib/egm-period-invites.php';
require_once __DIR__ . '/lib/egm-seat-map.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

try {
    $user = requireTabPermissionFromSession('event-guest-manager', true);
    $input = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
        ? json_decode((string)file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR)
        : $_GET;
    if (!is_array($input)) throw new InvalidArgumentException('درخواست نامعتبر است.');
    $code = normalizeEgmInstanceCode($input['instance_code'] ?? '');
    if ($code === '') throw new InvalidArgumentException('کد EGM نامعتبر است.');

    $config = loadConfig(__DIR__ . '/config.php');
    $registryPdo = connectDatabase($config);
    if (!$registryPdo instanceof PDO) throw new RuntimeException('اتصال به پایگاه داده برقرار نشد.');
    $find = $registryPdo->prepare('SELECT `directory` FROM `egm` WHERE `code`=:code LIMIT 1');
    $find->execute([':code' => $code]);
    $directory = (string)$find->fetchColumn();
    $root = realpath(dirname(__DIR__) . '/mini apps/EGMs');
    $missionDir = realpath(dirname(__DIR__) . '/' . str_replace('\\', '/', $directory));
    $developmentDir = realpath(dirname(__DIR__) . '/mini apps/Event Guest Manager');
    $insideInstances = is_string($root) && is_string($missionDir)
        && str_starts_with(str_replace('\\', '/', $missionDir), rtrim(str_replace('\\', '/', $root), '/') . '/');
    if (!$insideInstances && ($code !== EGM_DEVELOP_CODE || $missionDir !== $developmentDir)) {
        throw new InvalidArgumentException('EGM پیدا نشد.');
    }

    $periodCode = trim((string)($input['period_code'] ?? ''));
    $canManage = $periodCode === ''
        ? userHasPermissionId($user, 'event-guest-manager:main')
        : userHasPermissionId($user, 'event-guest-manager:manage-tasks');
    if (!$canManage) throw new RuntimeException('دسترسی به تنظیمات سالن مجاز نیست.', 403);
    $context = egmPeriodInvitesContext($missionDir);
    if ($context['code'] !== $code) throw new InvalidArgumentException('EGM پیدا نشد.');
    if ($periodCode !== '') $periodCode = egmPeriodInvitesValidatePeriod($context, $periodCode);
    $pdo = $context['pdo'];
    $key = $periodCode === '' ? 'seat_map:default' : 'seat_map:' . $periodCode;

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $provided = trim((string)($input['csrf'] ?? ''));
        $expected = (string)($_SESSION['task_club_csrf'] ?? '');
        if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
            throw new RuntimeException('توکن امنیتی نامعتبر است.', 403);
        }
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
    // The CDN replaces 422 responses with its HTML error page. Keep validation
    // failures as JSON so the panel can show the actual corrective message.
    http_response_code($status === 422 ? 200 : $status);
    echo json_encode(['status' => 'error', 'code' => $status, 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
