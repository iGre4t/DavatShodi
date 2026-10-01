<?php
declare(strict_types=1);
require_once __DIR__ . '/egm-database-runtime.php';
require_once __DIR__ . '/egm-security.php';
require_once dirname(__DIR__, 2) . '/api/lib/tab-permissions.php';
require_once dirname(__DIR__, 2) . '/api/lib/egm-period-draws.php';
$user = requireTabPermissionFromSession('event-guest-manager', true);
header('Cache-Control: no-store');
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST'], true)) { http_response_code(405); exit; }
    $input = $method === 'POST' ? json_decode((string)file_get_contents('php://input'), true) : $_GET;
    if (!is_array($input)) throw new InvalidArgumentException('درخواست نامعتبر است.');
    $action = (string)($input['action'] ?? 'list');
    if (!in_array($action, ['list', 'state', 'export'], true) && $method !== 'POST') { http_response_code(405); exit; }
    if ($method === 'POST' && !egmSecurityIsValidCsrfToken(egmSecurityReadCsrfFromRequest($input))) { http_response_code(403); exit; }
    $context = egmPeriodInvitesContext(__DIR__);
    $period = egmPeriodInvitesValidatePeriod($context, (string)($input['period_code'] ?? ''));
    if (!egmPeriodDrawCanAccess($context, $user, $period)) denyPanelAccess(403, 'دسترسی قرعه‌کشی این بازه مجاز نیست.', true);
    $actor = (string)($user['code'] ?? '');
    if ($actor === '') denyPanelAccess(403, 'Invalid panel user.', true);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $result = egmPeriodDrawRun($context, $period, $action, $input, $actor);
    if ($action === 'export') {
        $type = (string)($input['type'] ?? 'winners');
        if (!in_array($type, ['winners', 'reached_non_winners'], true)) throw new InvalidArgumentException('نوع خروجی نامعتبر است.');
        $participants = $type === 'winners' ? $result['winners'] : $result['eligibleParticipants'];
        $records = array_map(static fn(array $row): array => [
            'بازه' => $period, 'قرعه‌کشی' => $result['draw']['name'], 'جایزه' => $result['draw']['prizeName'],
            'شماره مهمان' => $row['guestNumber'], 'نام' => $row['fullName'], 'کد پرسنلی' => $row['workId'],
            'کد ملی' => $row['nationalId'], 'شماره همراه' => $row['phoneNumber'],
            'گروه' => $row['guestType'] === 'walk_in' ? 'مهمان ناخوانده' : 'ورود ثبت‌شده',
            'زمان تأیید' => $row['selectedAt'] ?? '',
        ], $participants);
        appXlsxSend(appXlsxFromSpreadsheetXml(egmPeriodExportSpreadsheetXml('قرعه‌کشی', $records)), 'period-draw.xlsx');
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'ok'] + $result, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('EGM period draw: ' . $error->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'ذخیره یا بارگذاری قرعه‌کشی ناموفق بود. دوباره تلاش کنید.'], JSON_UNESCAPED_UNICODE);
}
