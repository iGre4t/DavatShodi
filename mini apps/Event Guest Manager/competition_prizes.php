<?php
declare(strict_types=1);

require_once __DIR__ . '/egm-database-runtime.php';
require_once __DIR__ . '/egm-security.php';
require_once __DIR__ . '/prize_inventory_store.php';
require_once dirname(__DIR__, 2) . '/api/lib/tab-permissions.php';
require_once dirname(__DIR__, 2) . '/api/lib/egm-competition-prizes.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
    $user = requireTabPermissionFromSession('event-guest-manager', true);
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) throw new InvalidArgumentException('درخواست نامعتبر است.');
    if (!egmSecurityIsValidCsrfToken(egmSecurityReadCsrfFromRequest($input))) { http_response_code(403); exit; }
    $action = (string)($input['action'] ?? '');
    $context = egmPeriodInvitesContext(__DIR__);
    if (in_array($action, ['settings_state', 'save_queue'], true)) {
        if (!userHasPermissionId($user, 'event-guest-manager:main')) denyPanelAccess(403, 'دسترسی تنظیم صف مجاز نیست.', true);
        $period = '__settings__';
        $serviceAction = $action === 'settings_state' ? 'state' : $action;
    } else {
        $period = egmPeriodDrawActiveCode($context);
        if (!egmPeriodDrawCanAccess($context, $user, $period)) denyPanelAccess(403, 'دسترسی جوایز رقابت مجاز نیست.', true);
        $serviceAction = $action;
    }
    $actor = (string)($user['code'] ?? '');
    if ($actor === '') denyPanelAccess(403, 'کاربر نامعتبر است.', true);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $result = egmCompetitionRun($context, $period, $serviceAction, $input, $actor);
    echo json_encode(['status' => 'ok'] + $result, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('EGM competition prizes: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'ثبت جایزه ناموفق بود. دوباره تلاش کنید.'], JSON_UNESCAPED_UNICODE);
}
