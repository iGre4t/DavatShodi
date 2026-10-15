<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/common.php';
require_once __DIR__ . '/lib/users.php';
require_once __DIR__ . '/lib/tab-permissions.php';
require_once __DIR__ . '/lib/egm-registry.php';
require_once __DIR__ . '/lib/egm-check-in.php';
require_once __DIR__ . '/lib/egm-invite-card-store.php';
require_once __DIR__ . '/lib/egm-winapp-access.php';
require_once __DIR__ . '/lib/egm-staff-roles.php';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
ini_set('session.cookie_secure', $isHttps ? '1' : '0');
if (session_status() !== PHP_SESSION_ACTIVE) {
    // The seat-map web bridge uses the panel session; desktop authentication is separate.
    if ((string)($_GET['seat'] ?? '') !== '1') session_name('EGMWINAPPSESSID');
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function winAppJson(array $payload, int $status = 200): never
{
    // The hosting CDN replaces non-2xx API responses with an HTML error page.
    // Keep the application error in JSON so the Windows client can handle it.
    if ($status >= 400) {
        $payload['http_status'] = $status;
        $status = 200;
    }
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function winAppPayload(): array
{
    $payload = json_decode((string)file_get_contents('php://input'), true);
    return is_array($payload) ? $payload : $_POST;
}

function winAppCsrfToken(bool $rotate = false): string
{
    if ($rotate || !is_string($_SESSION['winapp_csrf'] ?? null) || $_SESSION['winapp_csrf'] === '') {
        $_SESSION['winapp_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['winapp_csrf'];
}

function winAppRequireCsrf(array $payload): void
{
    $provided = trim((string)($payload['csrf'] ?? ($_SERVER['HTTP_X_WINAPP_CSRF'] ?? '')));
    $expected = winAppCsrfToken();
    if ($provided === '' || !hash_equals($expected, $provided)) {
        winAppJson(['status' => 'error', 'message' => 'توکن امنیتی نامعتبر است.'], 403);
    }
}

function winAppRequireUser(): array
{
    $user = $_SESSION['user'] ?? null;
    if (empty($_SESSION['authenticated']) || !is_array($user)) {
        winAppJson(['status' => 'error', 'message' => 'لطفاً دوباره وارد شوید.', 'code' => 'unauthenticated'], 401);
    }
    $pdo = winAppDatabase();
    if (($user['auth_type'] ?? '') === 'facilitator') {
        $user = egmWinAppRefreshFacilitator($pdo, $user);
        if (!$user) {
            $_SESSION = [];
            winAppJson(['status'=>'error', 'code'=>'unauthenticated', 'message'=>'دسترسی تسهیلگر پایان یافته است. دوباره وارد شوید.'], 401);
        }
    } else {
        $record = loadUserByCode($pdo, (string)($user['code'] ?? ''));
        if (!$record) {
            $_SESSION = [];
            winAppJson(['status'=>'error', 'code'=>'unauthenticated', 'message'=>'حساب شما دیگر در دسترس نیست.'], 401);
        }
        unset($record['password_hash']);
        $user = array_merge($user, $record);
        $user['permissions'] = normalizeTabPermissions($record['permissions'] ?? null, true);
        $events = listEgmRegistry($pdo);
        if (!array_filter($events, static fn ($event) => egmWinAppCanAccessEvent($pdo, $user, $event))) {
            winAppJson(['status'=>'error', 'message'=>'شما اجازه استفاده از کنترل مهمان را ندارید.'], 403);
        }
    }
    $_SESSION['user'] = $user;
    return $user;
}

function winAppDatabase(): PDO
{
    static $connection;
    if ($connection instanceof PDO) return $connection;
    $pdo = connectDatabase(loadConfig(__DIR__ . '/config.php'));
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('اتصال به پایگاه داده برقرار نشد.');
    }
    return $connection = $pdo;
}

function winAppAbsoluteAssetUrl(string $value): string
{
    $value = trim($value);
    if ($value === '' || preg_match('/^(?:data:|https?:\/\/)/i', $value) === 1) return $value;
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
    $scheme = $isHttps ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
    if (str_starts_with($value, '//')) return $scheme . ':' . $value;
    if (str_starts_with($value, '/')) return $scheme . '://' . $host . $value;
    $scriptDirectory = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/api/winapp.php')));
    $projectBase = rtrim(str_replace('\\', '/', dirname($scriptDirectory)), '/');
    return $scheme . '://' . $host . ($projectBase === '' ? '' : $projectBase) . '/' . ltrim(preg_replace('#^(?:\./)+#', '', $value) ?? $value, '/');
}

function winAppBranding(): array
{
    $settings = GENERAL_SETTINGS_DEFAULTS;
    try {
        $config = loadConfig(__DIR__ . '/config.php');
        $pdo = connectDatabase($config);
        if ($pdo instanceof PDO) {
            $data = loadDataFromDb($pdo, $config);
            if (is_array($data['settings'] ?? null)) {
                $settings = array_replace_recursive($settings, $data['settings']);
            }
        }
    } catch (Throwable $error) {
        error_log('WinApp branding fallback: ' . $error->getMessage());
    }
    $appearance = is_array($settings['appearance'] ?? null) ? $settings['appearance'] : [];
    $primary = normalizeAppearanceColor((string)($appearance['primary'] ?? '')) ?: '#1d75e1';
    $panelName = trim((string)($settings['panelName'] ?? '')) ?: trim((string)($settings['title'] ?? ''));
    return [
        'panel_name' => $panelName !== '' ? $panelName : 'DavatShodi',
        'primary_color' => $primary,
        'logo_url' => winAppAbsoluteAssetUrl((string)($settings['siteIcon'] ?? '')),
    ];
}

function winAppEventBranding(PDO $pdo, string $code, string $fallbackName): array
{
    $settings = egmInstanceReadData($pdo, $code, 'settings', []);
    if (!is_array($settings)) $settings = [];
    $colors = is_array($settings['eventColors'] ?? null) ? $settings['eventColors'] : [];
    $panelBranding = winAppBranding();
    $primary = normalizeAppearanceColor((string)($colors['secondary'] ?? ''))
        ?: (string)$panelBranding['primary_color'];
    $name = trim((string)($settings['eventName'] ?? '')) ?: trim($fallbackName);
    return [
        'name' => $name !== '' ? $name : 'رویداد',
        'primary_color' => $primary,
        'logo_url' => winAppAbsoluteAssetUrl((string)($settings['eventLogo'] ?? '')),
    ];
}

function winAppMissionDirectory(PDO $pdo, string $eventCode): string
{
    $code = normalizeEgmRegistryCode($eventCode);
    $record = $code === '' ? null : findEgmRegistryByCode($pdo, $code);
    if (!is_array($record)) {
        throw new InvalidArgumentException('رویداد انتخاب‌شده پیدا نشد.');
    }
    $relative = normalizeEgmRegistryDirectory($record['directory'] ?? '');
    $projectRoot = dirname(__DIR__);
    $resolved = $relative === '' ? false : realpath($projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    $resolvedRoot = realpath($projectRoot);
    if (!is_string($resolved) || !is_string($resolvedRoot) || !str_starts_with($resolved, $resolvedRoot . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('پوشه رویداد در دسترس نیست.');
    }
    return $resolved;
}

function winAppNormalizeAccessLevel($value): string
{
    $level = strtolower(trim((string)$value));
    return in_array($level, ['full_access', 'scan_and_details', 'scan_and_actions', 'scan_and_event', 'scan_only'], true)
        ? $level
        : 'full_access';
}

function winAppAccessPolicy(string $missionDirectory, array $user): array
{
    $level = 'full_access';
    $userCode = strtolower(trim((string)($user['code'] ?? '')));
    $path = $missionDirectory . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . 'task-access.json';
    if (($user['auth_type'] ?? '') !== 'facilitator' && $userCode !== '' && is_file($path)) {
        $decoded = json_decode((string)file_get_contents($path), true);
        $users = is_array($decoded['users'] ?? null) ? $decoded['users'] : [];
        foreach ($users as $code => $entry) {
            if (strtolower(trim((string)$code)) !== $userCode || !is_array($entry)) continue;
            $level = winAppNormalizeAccessLevel($entry['winAppAccessLevel'] ?? ($entry['win_app_access_level'] ?? 'full_access'));
            break;
        }
    }
    if (($user['auth_type'] ?? '') === 'facilitator') {
        $registry = egmInstanceRegistryForDirectory(winAppDatabase(), $missionDirectory);
        $row = is_array($registry) ? egmWinAppFacilitatorForEvent(winAppDatabase(), $user, (string)$registry['code']) : null;
        if (!$row) winAppJson(['status'=>'error', 'message'=>'شما در این رویداد تسهیلگر نیستید.'], 403);
        $level = egmFacilitatorWindowsAccessLevel($row['win_app_access_level'] ?? null);
    }
    return egmWinAppPolicy($level);
}

function winAppAdminSecurity(array $context, bool $includeHash = false): array
{
    $settings = egmInstanceReadData($context['pdo'], (string)($context['code'] ?? ''), 'settings', []);
    $admin = is_array($settings['adminPasscode'] ?? null) ? $settings['adminPasscode'] : [];
    if (trim((string)($admin['hash'] ?? '')) === '') {
        require_once __DIR__ . '/lib/egm-database-runtime.php';
        $registry = findEgmRegistryByCode($context['pdo'], (string)$context['code']);
        $directory = (string)($registry['directory'] ?? '');
        if ($directory !== '') {
            $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . 'Setting.json';
            $legacy = egmDbIsFile($path) ? json_decode((string)egmDbFileGetContents($path), true) : null;
            $savedAdmin = is_array($legacy) && is_array($legacy['adminPasscode'] ?? null) ? $legacy['adminPasscode'] : [];
            if (trim((string)($savedAdmin['hash'] ?? '')) !== '') {
                $admin = $savedAdmin;
                $settings['adminPasscode'] = $admin;
                egmInstanceWriteData($context['pdo'], (string)$context['code'], 'settings', $settings);
            }
        }
    }
    $locks = is_array($admin['pageLocks'] ?? null) ? $admin['pageLocks'] : [];
    $result = [
        'configured' => trim((string)($admin['hash'] ?? '')) !== '',
        'page_locks' => [
            'scan' => (bool)($locks['scan'] ?? false),
            'event-info' => (bool)($locks['event-info'] ?? false),
            'printer' => (bool)($locks['printer'] ?? false),
            'settings' => (bool)($locks['settings'] ?? false),
        ],
    ];
    if ($includeHash) $result['hash'] = trim((string)($admin['hash'] ?? ''));
    return $result;
}

function winAppVerifyAdminPasscode(array $context, $value): bool
{
    $attemptKey = (string)($context['code'] ?? '');
    $attempt = $_SESSION['admin_passcode_attempts'][$attemptKey] ?? [];
    if ((int)($attempt['blocked_until'] ?? 0) > time()) {
        winAppJson(['status' => 'error', 'message' => 'تلاش‌های ناموفق زیاد است. یک دقیقه صبر کنید.'], 429);
    }
    $passcode = trim((string)$value);
    $security = winAppAdminSecurity($context, true);
    $hash = (string)($security['hash'] ?? '');
    $valid = preg_match('/^\d{4,6}$/', $passcode) && $hash !== '' && password_verify($passcode, $hash);
    if ($valid) {
        unset($_SESSION['admin_passcode_attempts'][$attemptKey]);
        return true;
    }
    $failures = (int)($attempt['failures'] ?? 0) + 1;
    $_SESSION['admin_passcode_attempts'][$attemptKey] = [
        'failures' => $failures >= 5 ? 0 : $failures,
        'blocked_until' => $failures >= 5 ? time() + 60 : 0,
    ];
    return false;
}

function winAppRestrictedScanMessage(string $result): string
{
    return match ($result) {
        'success', 'force_entry_success' => 'ورود با موفقیت ثبت شد.',
        'quit_success', 'force_quit_success' => 'خروج با موفقیت ثبت شد.',
        'duplicate' => 'ورود قبلاً ثبت شده است.',
        'quit_duplicate' => 'خروج قبلاً ثبت شده است.',
        'minimum_stay' => 'حداقل مدت حضور کامل نشده است.',
        'not_found' => 'شناسه مهمان پیدا نشد.',
        'not_invited', 'invited_other_period' => 'این شناسه برای بازه فعال مجاز نیست.',
        'no_active_period' => 'بازه فعالی برای اسکن وجود ندارد.',
        default => 'بررسی شناسه انجام شد.',
    };
}

function winAppEventSummary(array $context): array
{
    $period = is_array($context['period'] ?? null) ? $context['period'] : [];
    $state = is_array($context['period_state'] ?? null) ? $context['period_state'] : [];
    $availability = is_array($state['availability'] ?? null) ? $state['availability'] : [];
    $eventBranding = winAppEventBranding($context['pdo'], (string)($context['code'] ?? ''), (string)($context['name'] ?? ''));
    return [
        'code' => (string)($context['code'] ?? ''),
        'name' => (string)$eventBranding['name'],
        'primary_color' => (string)$eventBranding['primary_color'],
        'logo_url' => (string)$eventBranding['logo_url'],
        'can_scan' => (bool)($context['can_scan'] ?? false),
        'period_code' => (string)($context['period_code'] ?? ''),
        'period_title' => (string)($period['title'] ?? ''),
        'phase' => (string)($availability['reason'] ?? ($state['result'] ?? 'no_active_period')),
    ];
}

function winAppPrintProfile(array $context, string $guestCode = ''): array
{
    return egmCheckInPrintProfile($context, $guestCode);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$payload = $method === 'POST' ? winAppPayload() : $_GET;
$action = strtolower(trim((string)($payload['action'] ?? 'session')));

// Route seat-map saves through this established API path. The `events` action
// keeps the request compatible with the CDN's existing POST policy; the
// `seat=1` query selects the seat-map handler before event listing.
if ($method === 'POST' && $action === 'events' && (string)($_GET['seat'] ?? '') === '1') {
    require __DIR__ . '/egm-seat-map.php';
    exit;
}

try {
    if ($action === 'branding') {
        winAppJson(['status' => 'ok', 'branding' => winAppBranding()]);
    }
    if ($method === 'POST' && $action === 'login') {
        // A new login attempt must never inherit an older authenticated identity.
        unset($_SESSION['authenticated'], $_SESSION['user'], $_SESSION['winapp_csrf']);
        $username = trim((string)($payload['username'] ?? ''));
        $password = (string)($payload['password'] ?? '');
        if ($username === '' || $password === '') {
            winAppJson(['status' => 'error', 'message' => 'نام کاربری و رمز عبور الزامی است.'], 422);
        }
        $pdo = winAppDatabase();
        ensureUsersExtendedColumns($pdo);
        $columns = ['`code`', '`username`', '`fullname`', '`phone`', '`email`', '`id_number`', '`work_id`', '`password_hash`'];
        if (usersTableHasColumn($pdo, 'permissions')) $columns[] = '`permissions`';
        $statement = $pdo->prepare('SELECT ' . implode(', ', $columns) . ' FROM `users` WHERE `username` = :username LIMIT 1');
        $statement->execute([':username' => $username]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $user = null;
        if (is_array($row) && password_verify($password, (string)($row['password_hash'] ?? ''))) {
            unset($row['password_hash']);
            $row['permissions'] = normalizeTabPermissions($row['permissions'] ?? null, true);
            $events = listEgmRegistry($pdo);
            if (array_filter($events, static fn ($event) => egmWinAppCanAccessEvent($pdo, $row, $event))) $user = $row;
        }
        $user ??= egmWinAppAuthenticateFacilitator($pdo, listEgmRegistry($pdo), $username, $password);
        if (!$user) winAppJson(['status'=>'error', 'message'=>'نام کاربری، رمز عبور یا دسترسی رویداد معتبر نیست.'], 401);
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['user'] = $user;
        winAppJson([
            'status' => 'ok',
            'csrf' => winAppCsrfToken(true),
            'user' => ['code' => $user['code'], 'username' => $user['username'], 'name' => trim($user['fullname']) ?: $user['username']],
            'branding' => winAppBranding(),
        ]);
    }

    if ($method === 'POST' && $action === 'logout') {
        winAppRequireUser();
        winAppRequireCsrf($payload);
        $_SESSION = [];
        session_destroy();
        winAppJson(['status' => 'ok']);
    }

    winAppRequireUser();

    if ($action === 'session') {
        $user = $_SESSION['user'];
        winAppJson([
            'status' => 'ok',
            'csrf' => winAppCsrfToken(),
            'user' => ['code' => (string)($user['code'] ?? ''), 'username' => (string)($user['username'] ?? ''), 'name' => trim((string)($user['fullname'] ?? '')) ?: (string)($user['username'] ?? '')],
        ]);
    }

    $pdo = winAppDatabase();
    if ($action === 'events') {
        $events = [];
        foreach (listEgmRegistry($pdo) as $record) {
            if (!egmWinAppCanAccessEvent($pdo, $_SESSION['user'], $record)) continue;
            $relative = normalizeEgmRegistryDirectory($record['directory'] ?? '');
            $missionDirectory = $relative === '' ? '' : dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if ($missionDirectory === '' || !is_dir($missionDirectory)) continue;
            $eventBranding = winAppEventBranding($pdo, (string)$record['code'], (string)$record['name']);
            $access = winAppAccessPolicy($missionDirectory, $_SESSION['user']);
            $events[] = [
                'code' => (string)$record['code'],
                'name' => !empty($access['can_view_event_info']) ? (string)$eventBranding['name'] : 'EGM',
                'primary_color' => (string)$eventBranding['primary_color'],
                'logo_url' => (string)$eventBranding['logo_url'],
                'access' => $access,
                'event_role' => ($_SESSION['user']['auth_type'] ?? '') === 'facilitator'
                    ? egmStaffRole(egmWinAppFacilitatorForEvent($pdo, $_SESSION['user'], (string)$record['code'])['event_role'] ?? null)
                    : egmStaffPanelRole($pdo, (string)$record['code'], (string)$_SESSION['user']['code']),
            ];
        }
        if (($_SESSION['user']['auth_type'] ?? '') !== 'facilitator' && $events && !array_filter($events, static fn(array $event): bool => !empty($event['access']['can_manage_settings']))) {
            $events = [reset($events)];
        }
        winAppJson(['status' => 'ok', 'events' => $events]);
    }

    $eventCode = trim((string)($payload['event_code'] ?? ''));
    $eventRecord = findEgmRegistryByCode($pdo, $eventCode);
    if (!is_array($eventRecord) || !egmWinAppCanAccessEvent($pdo, $_SESSION['user'], $eventRecord)) {
        winAppJson(['status'=>'error', 'message'=>'شما به این رویداد دسترسی ندارید.'], 403);
    }
    if (($_SESSION['user']['auth_type'] ?? '') === 'facilitator') {
        $eventFacilitator = egmWinAppFacilitatorForEvent($pdo, $_SESSION['user'], $eventCode);
        $_SESSION['user'] = array_merge($_SESSION['user'], egmFacilitatorsSessionUser([$eventFacilitator], $eventFacilitator['id'], $eventFacilitator['auth_version']));
    } else {
        $_SESSION['user']['event_role'] = egmStaffPanelRole($pdo, $eventCode, (string)$_SESSION['user']['code']);
    }
    $missionDirectory = winAppMissionDirectory($pdo, $eventCode);
    $access = winAppAccessPolicy($missionDirectory, $_SESSION['user']);
    $context = egmCheckInContext(dirname(__DIR__), $missionDirectory);
    $context['scan_input'] = egmScanInputNormalize($payload['scan_input'] ?? []);
    if ($method === 'POST' && $action === 'lookup_scan_candidates') {
        if (empty($access['can_scan'])) winAppJson(['status'=>'error','message'=>'اجازه بررسی مهمان ندارید.'],403);
        session_write_close();
        winAppJson(['status'=>'ok'] + egmScanLookupCandidates($context,(string)($payload['typed_code']??''),(string)($payload['scanned_code']??'')));
    }
    if ($method === 'POST' && $action === 'event_updates') {
        // Read-only refresh: do not transmit receipt images or trigger print queues.
        session_write_close();
        $event = winAppEventSummary($context);
        if (empty($access['can_view_event_info'])) {
            $event['name'] = 'EGM';
            $event['period_code'] = '';
            $event['period_title'] = '';
        }
        winAppJson([
            'status'=>'ok', 'access'=>$access, 'event'=>$event,
            'stats'=>!empty($access['can_view_event_info']) ? egmCheckInDashboardStats($context) : null,
            'logs'=>!empty($access['can_view_logs']) ? egmCheckInRecentLogs($context, 30) : [],
            'seat_map'=>is_array($context['period'] ?? null)
                ? egmSeatMapEffective($context, egmCheckInPeriodCode($context['period'])) : ['enabled'=>false],
            'admin_security'=>winAppAdminSecurity($context),
        ]);
    }
    if ($method === 'POST' && $action === 'reprint_options') {
        if (empty($access['can_use_printer'])) winAppJson(['status'=>'error', 'message'=>'اجازه چاپ ندارید.'], 403);
        winAppJson(['status'=>'ok'] + egmCheckInGuestPrintData($context, (string)($payload['guest_code'] ?? ''), (string)($payload['period_code'] ?? '')));
    }
    if ($method === 'POST' && $action === 'pending_invitees') {
        if (empty($access['can_view_user_info']) || empty($access['can_scan'])) {
            winAppJson(['status'=>'error', 'message'=>'اجازه مشاهده فهرست دعوت‌شدگان را ندارید.'], 403);
        }
        winAppJson(['status'=>'ok'] + egmCheckInPendingInvitees($context, (string)($payload['q'] ?? ''), (int)($payload['page'] ?? 1)));
    }
    if ($method === 'POST' && $action === 'report_to_management') {
        winAppRequireCsrf($payload);
        if (empty($access['can_manage_scan_actions'])) winAppJson(['status'=>'error','message'=>'اجازه ارسال گزارش ندارید.'],403);
        require_once __DIR__ . '/lib/system-telegram.php';
        winAppJson(['status'=>'ok'] + systemBotsCreateReport($context, (int)($payload['log_id'] ?? 0), is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : []));
    }
    if ($method === 'POST' && $action === 'reset_guest_entry') {
        winAppRequireCsrf($payload);
        if (empty($access['can_reset_guest_entry'])) {
            winAppJson(['status' => 'error', 'message' => 'شما اجازه بازنشانی ورود مهمان را ندارید.'], 403);
        }
        $result = egmCheckInResetGuestEntry($context,
            (string)($payload['guest_code'] ?? ''), (string)($payload['period_code'] ?? ''),
            is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : []);
        winAppJson(['status' => 'ok'] + $result + [
            'access' => $access,
            'event' => winAppEventSummary($context),
            'stats' => egmCheckInDashboardStats($context),
            'logs' => egmCheckInRecentLogs($context, 30),
            'print_profile' => !empty($access['can_use_printer']) ? winAppPrintProfile($context) : new stdClass(),
            'seat_map' => is_array($context['period'] ?? null)
                ? egmSeatMapEffective($context, egmCheckInPeriodCode($context['period'])) : ['enabled' => false],
        ]);
    }
    if ($method === 'POST' && $action === 'verify_admin_passcode') {
        winAppRequireCsrf($payload);
        if (!winAppVerifyAdminPasscode($context, $payload['passcode'] ?? '')) {
            winAppJson(['status' => 'error', 'message' => 'Admin Passcode نادرست است.'], 403);
        }
        winAppJson(['status' => 'ok', 'message' => 'تنظیمات باز شد.', 'admin_security' => winAppAdminSecurity($context), 'print_profile' => winAppPrintProfile($context)]);
    }
    if ($method === 'POST' && $action === 'set_page_lock') {
        winAppRequireCsrf($payload);
        if (empty($access['can_manage_settings'])) {
            winAppJson(['status' => 'error', 'message' => 'شما اجازه تغییر قفل صفحات را ندارید.'], 403);
        }
        if (!winAppVerifyAdminPasscode($context, $payload['passcode'] ?? '')) {
            winAppJson(['status' => 'error', 'message' => 'Admin Passcode نادرست است.'], 403);
        }
        $pageKey = strtolower(trim((string)($payload['page_key'] ?? '')));
        if (!in_array($pageKey, ['scan', 'event-info', 'printer', 'settings'], true)) {
            throw new InvalidArgumentException('صفحه انتخاب‌شده معتبر نیست.');
        }
        $settings = egmInstanceReadData($pdo, (string)$context['code'], 'settings', []);
        if (!is_array($settings)) $settings = [];
        $admin = is_array($settings['adminPasscode'] ?? null) ? $settings['adminPasscode'] : [];
        $locks = is_array($admin['pageLocks'] ?? null) ? $admin['pageLocks'] : [];
        $locks[$pageKey] = egmCheckInBool($payload['locked'] ?? false);
        $admin['pageLocks'] = $locks;
        $settings['adminPasscode'] = $admin;
        egmInstanceWriteData($pdo, (string)$context['code'], 'settings', $settings);
        winAppJson(['status' => 'ok', 'message' => $locks[$pageKey] ? 'صفحه قفل شد.' : 'قفل صفحه برداشته شد.', 'admin_security' => winAppAdminSecurity($context)]);
    }
    if ($method === 'POST' && $action === 'save_print_settings') {
        winAppRequireCsrf($payload);
        if (empty($access['can_manage_settings']) && !winAppVerifyAdminPasscode($context, $payload['passcode'] ?? '')) {
            winAppJson(['status' => 'error', 'message' => 'برای تغییر تنظیمات چاپ، کد مدیریت معتبر لازم است.'], 403);
        }
        $settings = egmInstanceReadData($pdo, (string)$context['code'], 'settings', []);
        if (!is_array($settings)) $settings = [];
        $settings['printSettings'] = [
            'autoPrint' => egmCheckInBool($payload['auto_print'] ?? false),
            'doublePrint' => egmCheckInBool($payload['double_print'] ?? false),
            'cardActive' => array_key_exists('card_active', $payload)
                ? egmCheckInBool($payload['card_active'])
                : (!egmCheckInBool($payload['ticket_active'] ?? false) || !egmCheckInBool($payload['ticket_only'] ?? false)),
        ];
        $existingTicketSettings = is_array($settings['customNumberTicketSettings'] ?? null) ? $settings['customNumberTicketSettings'] : [];
        $settings['customNumberTicketSettings'] = [
            'active' => egmCheckInBool($payload['ticket_active'] ?? false),
            'ticketOnly' => !$settings['printSettings']['cardActive'],
            'tickets' => egmCheckInTicketDefinitions($existingTicketSettings),
        ];
        egmInstanceWriteData($pdo, (string)$context['code'], 'settings', $settings);
        winAppJson([
            'status' => 'ok',
            'message' => 'تنظیمات چاپ ذخیره و با پنل وب همگام شد.',
            'print_profile' => winAppPrintProfile($context),
        ]);
    }
    if ($action === 'uninvited_options') {
        if (empty($access['can_manage_scan_actions'])) {
            winAppJson(['status' => 'error', 'message' => 'شما اجازه ثبت مهمان ناخوانده را ندارید.'], 403);
        }
        winAppJson(['status' => 'ok', 'options' => egmCheckInUninvitedOptions($context)]);
    }
    if ($action === 'event_status') {
        $event = winAppEventSummary($context);
        if (empty($access['can_view_event_info'])) {
            $event['name'] = 'EGM';
            $event['period_code'] = '';
            $event['period_title'] = '';
        }
        winAppJson([
            'status' => 'ok',
            'access' => $access,
            'event' => $event,
            'stats' => !empty($access['can_view_event_info']) ? egmCheckInDashboardStats($context) : null,
            'logs' => !empty($access['can_view_logs']) ? egmCheckInRecentLogs($context, 30) : [],
            'print_profile' => !empty($access['can_use_printer']) ? winAppPrintProfile($context) : new stdClass(),
            'seat_map' => is_array($context['period'] ?? null)
                ? egmSeatMapEffective($context, egmCheckInPeriodCode($context['period'])) : ['enabled' => false],
            'admin_security' => winAppAdminSecurity($context),
        ]);
    }
    if ($method === 'POST' && $action === 'seat_requirement') {
        if (empty($access['can_scan'])) winAppJson(['status' => 'error', 'message' => 'اجازه بررسی صندلی ندارید.'], 403);
        winAppRequireCsrf($payload);
        winAppJson(['status' => 'ok'] + egmCheckInSeatRequirement($context, (string)($payload['guest_code'] ?? '')));
    }
    if ($method === 'POST' && $action === 'scan') {
        if (!empty($payload['period_code']) && (string)$payload['period_code'] !== egmCheckInPeriodCode((array)($context['period']??[]))) throw new InvalidArgumentException('بازهٔ فعال تغییر کرده است؛ مهمان را دوباره بررسی کنید.');
        winAppRequireCsrf($payload);
        $scanGuestCode = egmCheckInNormalizeDigits((string)($payload['guest_code'] ?? ''));
        $result = egmCheckInProcess($context, $scanGuestCode, null, null, [], isset($payload['seat_ticket_count']) ? (string)$payload['seat_ticket_count'] : null, egmCheckInBool($payload['allow_split_seats'] ?? false), egmCheckInBool($payload['allow_free_seat'] ?? false));
        if (empty($access['can_view_user_info'])) {
            $resultCode = (string)($result['result'] ?? '');
            $result = [
                'result' => $resultCode,
                'message' => !empty($result['suggested_guests']) ? $result['message'] : winAppRestrictedScanMessage($resultCode),
                'suggested_guests' => $result['suggested_guests'] ?? [],
            ];
        }
        $event = winAppEventSummary($context);
        if (empty($access['can_view_event_info'])) {
            $event['name'] = 'EGM';
            $event['period_code'] = '';
            $event['period_title'] = '';
        }
        $printGuest = null;
        if (!empty($access['can_use_printer']) && in_array((string)($result['result'] ?? ''), ['success', 'force_entry_success'], true)) {
            $recentPrintLogs = egmCheckInRecentLogs($context, 5);
            foreach ($recentPrintLogs as $printLog) {
                $logNationalId = egmCheckInNormalizeGuestCode((string)($printLog['national_id'] ?? ''));
                $logWorkId = egmCheckInNormalizeGuestCode((string)($printLog['work_id'] ?? ''));
                if (in_array((string)($printLog['status'] ?? ''), ['success', 'force_entry_success'], true)
                    && ($logNationalId === $scanGuestCode || $logWorkId === $scanGuestCode)) {
                    $printGuest = $printLog;
                    break;
                }
            }
        }
        winAppJson(['status' => 'ok'] + $result + [
            'access' => $access,
            'event' => $event,
            'stats' => !empty($access['can_view_event_info']) ? egmCheckInDashboardStats($context) : null,
            'logs' => !empty($access['can_view_logs']) ? egmCheckInRecentLogs($context, 30) : [],
            'print_profile' => !empty($access['can_use_printer']) ? egmCheckInAutomaticPrintProfile($context, $scanGuestCode) : new stdClass(),
            'seat_map' => is_array($context['period'] ?? null)
                ? egmSeatMapEffective($context, egmCheckInPeriodCode($context['period'])) : ['enabled' => false],
            'print_guest' => !empty($access['can_use_printer']) ? $printGuest : null,
        ]);
    }
    if ($method === 'POST' && $action === 'record_ticket_number') {
        winAppRequireCsrf($payload);
        if (empty($access['can_use_printer'])) winAppJson(['status'=>'error', 'message'=>'اجازه چاپ ندارید.'], 403);
        $recorded = egmCheckInRecordTicketNumber(
            $context,
            (string)($payload['guest_code'] ?? ''),
            (string)($payload['number_of_ticket'] ?? ''),
            (string)($payload['ticket_id'] ?? 'default')
        );
        winAppJson(['status' => 'ok', 'message' => 'Number of Ticket برای مهمان ثبت شد.'] + $recorded);
    }
    if ($method === 'POST' && $action === 'record_manual_ticket') {
        winAppRequireCsrf($payload);
        if (empty($access['can_use_printer'])) {
            winAppJson(['status'=>'error', 'message'=>'اجازه چاپ و ثبت بلیت دستی ندارید.'], 403);
        }
        $recorded = egmCheckInRecordManualTicket(
            $context,
            (string)($payload['ticket_id'] ?? ''),
            (string)($payload['quantity'] ?? ''),
            (string)($payload['client_token'] ?? ''),
            is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [],
            (string)($payload['seat_mode'] ?? 'none'),
            egmCheckInBool($payload['allow_split_seats'] ?? false)
        );
        winAppJson(['status'=>'ok', 'message'=>'بلیت دستی مهمان در پایگاه داده ثبت شد.'] + $recorded);
    }
    if ($method === 'POST' && $action === 'register_uninvited') {
        winAppRequireCsrf($payload);
        if (empty($access['can_manage_scan_actions'])) {
            winAppJson(['status' => 'error', 'message' => 'شما اجازه ثبت مهمان ناخوانده را ندارید.'], 403);
        }
        $result = egmCheckInRegisterUninvited($context, $payload, $_SESSION['user']);
        winAppJson(['status' => 'ok'] + $result + [
            'access' => $access,
            'event' => winAppEventSummary($context),
            'stats' => egmCheckInDashboardStats($context),
            'logs' => egmCheckInRecentLogs($context, 30),
            'print_profile' => !empty($access['can_use_printer']) ? egmCheckInAutomaticPrintProfile($context, (string)($payload['guest_code'] ?? ($payload['national_id'] ?? ($payload['work_id'] ?? '')))) : new stdClass(),
        ]);
    }
    if ($method === 'POST' && $action === 'force_attendance') {
        winAppRequireCsrf($payload);
        if (empty($access['can_manage_scan_actions'])) {
            winAppJson(['status' => 'error', 'message' => 'شما اجازه ثبت عملیات حضور اجباری را ندارید.'], 403);
        }
        $attendanceAction = strtolower(trim((string)($payload['attendance_action'] ?? '')));
        if (!in_array($attendanceAction, ['entry', 'quit'], true)) {
            throw new InvalidArgumentException('عملیات حضور اجباری معتبر نیست.');
        }
        $result = egmCheckInProcess(
            $context,
            (string)($payload['guest_code'] ?? ''),
            null,
            $attendanceAction,
            $_SESSION['user'],
            isset($payload['seat_ticket_count']) ? (string)$payload['seat_ticket_count'] : null,
            egmCheckInBool($payload['allow_split_seats'] ?? false),
            egmCheckInBool($payload['allow_free_seat'] ?? false)
        );
        winAppJson(['status' => 'ok'] + $result + [
            'access' => $access,
            'event' => winAppEventSummary($context),
            'stats' => egmCheckInDashboardStats($context),
            'logs' => egmCheckInRecentLogs($context, 30),
            'print_profile' => !empty($access['can_use_printer']) ? egmCheckInAutomaticPrintProfile($context, (string)($payload['guest_code'] ?? '')) : new stdClass(),
        ]);
    }
    winAppJson(['status' => 'error', 'message' => 'عملیات درخواستی پشتیبانی نمی‌شود.'], 404);
} catch (EgmSeatSplitRequiredException $error) {
    winAppJson(['status' => 'error', 'code' => 'seat_split_confirmation_required', 'message' => $error->getMessage(), 'suggested_seats' => $error->suggestedSeats], 409);
} catch (EgmSeatCapacityException $error) {
    winAppJson(['status' => 'error', 'code' => 'seat_capacity_insufficient', 'message' => $error->getMessage()], 409);
} catch (InvalidArgumentException $error) {
    winAppJson(['status' => 'error', 'message' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('WinApp API failed: ' . $error->getMessage());
    winAppJson(['status' => 'error', 'message' => 'سرویس کنترل مهمان موقتاً در دسترس نیست.'], 500);
}
