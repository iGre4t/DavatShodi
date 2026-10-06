<?php
declare(strict_types=1);
ob_start();
$bufferLevel = ob_get_level();
function egmFacilitatorsJson(array $data, int $status = 200): never
{
    global $bufferLevel;
    while (ob_get_level() >= $bufferLevel) ob_end_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
try {
    require_once __DIR__ . '/egm-database-runtime.php';
    require_once __DIR__ . '/egm-security.php';
    require_once dirname(__DIR__, 2) . '/api/lib/tab-permissions.php';
    require_once dirname(__DIR__, 2) . '/api/lib/egm-facilitators.php';
    $user = requireTabPermissionFromSession('event-guest-manager', true);
    if (!userHasPermissionId($user, 'event-guest-manager:main') && !userHasPermissionId($user, 'event-guest-manager:task-access')) {
        egmFacilitatorsJson(['status' => 'error', 'message' => 'دسترسی مدیریت تسهیلگرها را ندارید.'], 403);
    }
    $context = egmDatabaseRuntimeContextForPath(__DIR__ . '/Setting.json');
    if (!is_array($context) || empty($context['code'])) throw new RuntimeException('رویداد ثبت نشده است.');
    $pdo = $context['pdo']; $code = (string)$context['code'];
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        egmFacilitatorsJson(['status' => 'ok', 'users' => egmFacilitatorsPublic(egmFacilitatorsRead($pdo, $code))]);
    }
    if ($method !== 'POST') egmFacilitatorsJson(['status' => 'error', 'message' => 'روش درخواست نامعتبر است.'], 405);
    $raw = $_POST['payload'] ?? file_get_contents('php://input');
    $input = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($input)) throw new InvalidArgumentException('درخواست نامعتبر است.');
    if (!egmSecurityIsValidCsrfToken((string)($input['csrf'] ?? ''))) egmFacilitatorsJson(['status' => 'error', 'message' => 'فرم منقضی شده است. صفحه را تازه‌سازی کنید.'], 403);
    $action = (string)($input['action'] ?? ''); $id = (string)($input['id'] ?? '');
    if ($action === 'reveal') {
        foreach (egmFacilitatorsRead($pdo, $code) as $row) {
            if ($row['id'] === $id) egmFacilitatorsJson(['status' => 'ok', 'password' => tcPasswordVaultDecrypt($row['password_encrypted'])]);
        }
        throw new InvalidArgumentException('تسهیلگر پیدا نشد.');
    }
    $rows = egmFacilitatorsMutate($pdo, $code, static function (array $rows) use ($action, $id, $input): array {
        if ($action === 'save') return egmFacilitatorsApplySave($rows, $input, $id);
        if ($action === 'import') {
            if (!is_array($input['rows'] ?? null) || !is_bool($input['update_existing'] ?? false)) throw new InvalidArgumentException('اطلاعات فایل نامعتبر است.');
            return egmFacilitatorsApplyImport($rows, $input['rows'], $input['update_existing'] ?? false);
        }
        if ($action === 'delete') {
            $remaining = array_values(array_filter($rows, static fn(array $row): bool => $row['id'] !== $id));
            if (count($remaining) === count($rows)) throw new InvalidArgumentException('تسهیلگر پیدا نشد.');
            return $remaining;
        }
        throw new InvalidArgumentException('عملیات نامعتبر است.');
    });
    egmFacilitatorsJson(['status' => 'ok', 'users' => $rows]);
} catch (InvalidArgumentException $error) {
    egmFacilitatorsJson(['status' => 'error', 'message' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('EGM facilitator request failed: ' . $error->getMessage());
    egmFacilitatorsJson(['status' => 'error', 'message' => 'مدیریت تسهیلگرها در دسترس نیست. گزارش خطای PHP را بررسی کنید.'], 503);
}
