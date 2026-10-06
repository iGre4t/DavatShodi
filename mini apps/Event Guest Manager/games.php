<?php
declare(strict_types=1);
$GLOBALS['egmGamesResponseBufferLevel'] = ob_get_level() + 1;
ob_start();
try {
require_once __DIR__ . '/egm-database-runtime.php';
require_once __DIR__ . '/egm-security.php';
require_once dirname(__DIR__, 2) . '/api/lib/tab-permissions.php';
require_once dirname(__DIR__, 2) . '/api/lib/egm-games.php';
$user = requireTabPermissionFromSession('event-guest-manager', true);
$canMain = userHasPermissionId($user, 'event-guest-manager:main');
handleEgmGamesRequest(__DIR__, $canMain, $canMain || userHasPermissionId($user, 'event-guest-manager:manage-tasks'), (string)($user['code'] ?? ''));
} catch (Throwable $error) {
    error_log('EGM games bootstrap failed: ' . (string)$error);
    while (ob_get_level() >= $GLOBALS['egmGamesResponseBufferLevel']) ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode(['status'=>'error', 'message'=>'راه‌اندازی مدیریت بازی‌ها ناموفق بود. گزارش خطای PHP هاست را بررسی کنید.'], JSON_UNESCAPED_UNICODE);
}
