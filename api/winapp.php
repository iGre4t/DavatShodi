<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/common.php';
require_once __DIR__ . '/lib/users.php';
require_once __DIR__ . '/lib/tab-permissions.php';
require_once __DIR__ . '/lib/egm-registry.php';
require_once __DIR__ . '/lib/egm-check-in.php';
require_once __DIR__ . '/lib/egm-invite-card-store.php';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
ini_set('session.cookie_secure', $isHttps ? '1' : '0');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function winAppJson(array $payload, int $status = 200): never
{
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
    if (!userHasPermissionId($user, 'event-guest-manager:main')) {
        winAppJson(['status' => 'error', 'message' => 'شما اجازه استفاده از کنترل مهمان را ندارید.'], 403);
    }
    return $user;
}

function winAppDatabase(): PDO
{
    $pdo = connectDatabase(loadConfig(__DIR__ . '/config.php'));
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('اتصال به پایگاه داده برقرار نشد.');
    }
    return $pdo;
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
    return in_array($level, ['full_access', 'scan_and_details', 'scan_and_event', 'scan_only'], true)
        ? $level
        : 'full_access';
}

function winAppAccessPolicy(string $missionDirectory, array $user): array
{
    $level = 'full_access';
    $userCode = strtolower(trim((string)($user['code'] ?? '')));
    $path = $missionDirectory . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . 'task-access.json';
    if ($userCode !== '' && is_file($path)) {
        $decoded = json_decode((string)file_get_contents($path), true);
        $users = is_array($decoded['users'] ?? null) ? $decoded['users'] : [];
        foreach ($users as $code => $entry) {
            if (strtolower(trim((string)$code)) !== $userCode || !is_array($entry)) continue;
            $level = winAppNormalizeAccessLevel($entry['winAppAccessLevel'] ?? ($entry['win_app_access_level'] ?? 'full_access'));
            break;
        }
    }
    $canViewUsers = in_array($level, ['full_access', 'scan_and_details'], true);
    return [
        'level' => $level,
        'can_scan' => true,
        'can_view_user_info' => $canViewUsers,
        'can_view_event_info' => $level !== 'scan_only',
        'can_manage_scan_actions' => $canViewUsers,
        'can_manage_settings' => $level === 'full_access',
        'can_use_printer' => $level === 'full_access',
    ];
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
    $pdo = $context['pdo'];
    $code = (string)($context['code'] ?? '');
    $settings = egmInstanceReadData($pdo, $code, 'settings', []);
    $printSettings = is_array($settings['printSettings'] ?? null) ? $settings['printSettings'] : [];
    $card = egmInstanceReadData($pdo, $code, 'print_card', null);
    $card = egmInviteCardHydrateAssets($pdo, $code, $card, 'print-card');
    $ticketSettings = is_array($settings['customNumberTicketSettings'] ?? null) ? $settings['customNumberTicketSettings'] : [];
    $ticketCard = egmInstanceReadData($pdo, $code, 'custom_number_ticket', null);
    $ticketCard = egmInviteCardHydrateAssets($pdo, $code, $ticketCard, 'custom-number-ticket');
    $tickets = array_map(static function (array $definition) use ($pdo, $code, $ticketCard): array {
        $isDefault = $definition['id'] === 'default';
        $card = $isDefault ? $ticketCard : egmInstanceReadData($pdo, $code, 'custom_number_ticket:' . $definition['id'], null);
        $card = $isDefault ? $card : egmInviteCardHydrateAssets($pdo, $code, $card, 'cnt-' . $definition['id']);
        if (!$isDefault && !egmCheckInTicketCardConfigured($card)) $card = $ticketCard;
        return $definition + ['configured' => egmCheckInTicketCardConfigured($card), 'card' => $card];
    }, egmCheckInTicketDefinitions($ticketSettings));
    $profile = [
        'auto_print' => (bool)($printSettings['autoPrint'] ?? false),
        'double_print' => (bool)($printSettings['doublePrint'] ?? false),
        'configured' => is_array($card) && !empty($card['imageData']) && is_array($card['qrRect'] ?? null) && is_array($card['textRect'] ?? null),
        'card' => $card,
        'ticket_active' => (bool)($ticketSettings['active'] ?? false),
        'ticket_only' => (bool)($ticketSettings['ticketOnly'] ?? false),
        'ticket_configured' => egmCheckInTicketCardConfigured($ticketCard),
        'ticket_card' => $ticketCard,
        'tickets' => $tickets,
    ];
    return $guestCode !== '' ? egmGroupsApplyPrintPolicy($context, $profile, $guestCode) : $profile;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$payload = $method === 'POST' ? winAppPayload() : $_GET;
$action = strtolower(trim((string)($payload['action'] ?? 'session')));

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
        if (!is_array($row) || !password_verify($password, (string)($row['password_hash'] ?? ''))) {
            winAppJson(['status' => 'error', 'message' => 'نام کاربری یا رمز عبور نادرست است.'], 401);
        }
        $permissions = normalizeTabPermissions($row['permissions'] ?? null, true);
        $user = [
            'code' => (string)$row['code'],
            'username' => (string)$row['username'],
            'fullname' => (string)($row['fullname'] ?? ''),
            'phone' => (string)($row['phone'] ?? ''),
            'email' => (string)($row['email'] ?? ''),
            'id_number' => (string)($row['id_number'] ?? ''),
            'work_id' => (string)($row['work_id'] ?? ''),
            'permissions' => $permissions,
        ];
        if (!userHasPermissionId($user, 'event-guest-manager:main')) {
            winAppJson(['status' => 'error', 'message' => 'حساب شما به کنترل مهمان دسترسی ندارد.'], 403);
        }
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
            ];
        }
        if ($events && !array_filter($events, static fn(array $event): bool => !empty($event['access']['can_manage_settings']))) {
            $events = [reset($events)];
        }
        winAppJson(['status' => 'ok', 'events' => $events]);
    }

    $eventCode = trim((string)($payload['event_code'] ?? ''));
    $missionDirectory = winAppMissionDirectory($pdo, $eventCode);
    $access = winAppAccessPolicy($missionDirectory, $_SESSION['user']);
    $context = egmCheckInContext(dirname(__DIR__), $missionDirectory);
    if ($method === 'POST' && $action === 'event_updates') {
        // Read-only refresh: do not transmit receipt images or trigger print queues.
        session_write_close();
        winAppJson([
            'status'=>'ok', 'access'=>$access, 'event'=>winAppEventSummary($context),
            'stats'=>!empty($access['can_view_event_info']) ? egmCheckInDashboardStats($context) : null,
            'logs'=>!empty($access['can_view_user_info']) ? egmCheckInRecentLogs($context, 30) : [],
            'admin_security'=>winAppAdminSecurity($context),
        ]);
    }
    if ($method === 'POST' && $action === 'reprint_options') {
        if (empty($access['can_use_printer'])) winAppJson(['status'=>'error', 'message'=>'اجازه چاپ ندارید.'], 403);
        $guestCode = egmCheckInNormalizeGuestCode($payload['guest_code'] ?? '');
        $user = egmCheckInFindUser($pdo, (string)$context['tables']['users'], $guestCode);
        if (!is_array($user)) throw new InvalidArgumentException('مهمان پیدا نشد.');
        $table = (string)$context['tables']['user_periods'];
        $statement = $pdo->prepare("SELECT ticket_numbers_json FROM `{$table}` WHERE user_id=:id AND period_code=:period LIMIT 1");
        $statement->execute([':id'=>(int)$user['id'], ':period'=>(string)($payload['period_code'] ?? $context['period_code'])]);
        $numbers = json_decode((string)($statement->fetchColumn() ?: ''), true);
        $profile = egmCheckInPrintProfile($context, $guestCode);
        if (!empty($profile['group_policy_applied']) && empty($profile['auto_print'])) $profile['configured'] = false;
        winAppJson(['status'=>'ok', 'print_profile'=>$profile, 'ticket_numbers'=>(object)(is_array($numbers) ? $numbers : [])]);
    }
    if ($method === 'POST' && $action === 'pending_invitees') {
        if (empty($access['can_view_user_info']) || empty($access['can_scan'])) {
            winAppJson(['status'=>'error', 'message'=>'اجازه مشاهده فهرست دعوت‌شدگان را ندارید.'], 403);
        }
        $periodCode = (string)($context['period_code'] ?? '');
        if ($periodCode === '' || empty($context['can_scan'])) {
            winAppJson(['status'=>'error', 'message'=>'بازه فعالی برای ثبت ورود وجود ندارد.'], 422);
        }
        $query = trim((string)($payload['q'] ?? ''));
        if (function_exists('mb_substr')) $query = mb_substr($query, 0, 100);
        else $query = substr($query, 0, 100);
        $page = max(1, min(10000, (int)($payload['page'] ?? 1)));
        $pageSize = 30;
        $offset = ($page - 1) * $pageSize;
        $usersTable = (string)$context['tables']['users'];
        $periodsTable = (string)$context['tables']['user_periods'];
        $where = "p.`period_code`=:period AND p.`entered_date` IS NULL AND p.`entered_time` IS NULL "
            . "AND COALESCE(p.`is_uninvited_guest`,0)=0 AND LOWER(TRIM(COALESCE(p.`invitation_source`,'')))<>'walk_in' "
            . "AND COALESCE(u.`is_active`,1)=1 "
            . "AND (u.`national_id` REGEXP '^[0-9]{10}$' OR u.`work_id` REGEXP '^[0-9]{4,9}$')";
        $params = [':period'=>$periodCode];
        if ($query !== '') {
            $where .= " AND (CONCAT_WS(' ',u.`first_name`,u.`last_name`) LIKE :name "
                . "OR u.`national_id` LIKE :national OR u.`work_id` LIKE :work "
                . "OR u.`guest_number` LIKE :guest_number OR u.`department` LIKE :department)";
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
            foreach ([':name', ':national', ':work', ':guest_number', ':department'] as $key) $params[$key] = $like;
        }
        $count = $pdo->prepare("SELECT COUNT(*) FROM `{$periodsTable}` p JOIN `{$usersTable}` u ON u.`id`=p.`user_id` WHERE {$where}");
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $statement = $pdo->prepare("SELECT u.`first_name`,u.`last_name`,u.`national_id`,u.`work_id`,u.`guest_number`,u.`department` "
            . "FROM `{$periodsTable}` p JOIN `{$usersTable}` u ON u.`id`=p.`user_id` "
            . "WHERE {$where} ORDER BY u.`last_name`,u.`first_name`,p.`id` LIMIT {$pageSize} OFFSET {$offset}");
        $statement->execute($params);
        $invitees = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $national = egmCheckInNormalizeNationalId($row['national_id'] ?? '');
            $work = egmCheckInNormalizeWorkId($row['work_id'] ?? '');
            $invitees[] = [
                'name'=>trim((string)$row['first_name'] . ' ' . (string)$row['last_name']),
                'guest_code'=>$national !== '' ? $national : $work,
                'guest_number'=>(string)($row['guest_number'] ?? ''),
                'department'=>(string)($row['department'] ?? ''),
            ];
        }
        winAppJson(['status'=>'ok', 'invitees'=>$invitees, 'total'=>$total, 'page'=>$page, 'page_size'=>$pageSize,
            'period_code'=>$periodCode]);
    }
    if ($method === 'POST' && $action === 'verify_admin_passcode') {
        winAppRequireCsrf($payload);
        if (!winAppVerifyAdminPasscode($context, $payload['passcode'] ?? '')) {
            winAppJson(['status' => 'error', 'message' => 'Admin Passcode نادرست است.'], 403);
        }
        winAppJson(['status' => 'ok', 'message' => 'دسترسی برای یک دقیقه باز شد.', 'admin_security' => winAppAdminSecurity($context)]);
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
        if (empty($access['can_manage_settings'])) {
            winAppJson(['status' => 'error', 'message' => 'شما اجازه تغییر تنظیمات چاپ این EGM را ندارید.'], 403);
        }
        $settings = egmInstanceReadData($pdo, (string)$context['code'], 'settings', []);
        if (!is_array($settings)) $settings = [];
        $settings['printSettings'] = [
            'autoPrint' => egmCheckInBool($payload['auto_print'] ?? false),
            'doublePrint' => egmCheckInBool($payload['double_print'] ?? false),
        ];
        $existingTicketSettings = is_array($settings['customNumberTicketSettings'] ?? null) ? $settings['customNumberTicketSettings'] : [];
        $settings['customNumberTicketSettings'] = [
            'active' => egmCheckInBool($payload['ticket_active'] ?? false),
            'ticketOnly' => egmCheckInBool($payload['ticket_only'] ?? false),
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
            'logs' => !empty($access['can_view_user_info']) ? egmCheckInRecentLogs($context, 30) : [],
            'print_profile' => winAppPrintProfile($context),
            'admin_security' => winAppAdminSecurity($context),
        ]);
    }
    if ($method === 'POST' && $action === 'scan') {
        winAppRequireCsrf($payload);
        $scanGuestCode = egmCheckInNormalizeGuestCode((string)($payload['guest_code'] ?? ''));
        $result = egmCheckInProcess($context, $scanGuestCode);
        if (empty($access['can_view_user_info'])) {
            $resultCode = (string)($result['result'] ?? '');
            $result = [
                'result' => $resultCode,
                'message' => winAppRestrictedScanMessage($resultCode),
            ];
        }
        $event = winAppEventSummary($context);
        if (empty($access['can_view_event_info'])) {
            $event['name'] = 'EGM';
            $event['period_code'] = '';
            $event['period_title'] = '';
        }
        $printGuest = null;
        if (in_array((string)($result['result'] ?? ''), ['success', 'force_entry_success'], true)) {
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
            'logs' => !empty($access['can_view_user_info']) ? egmCheckInRecentLogs($context, 30) : [],
            'print_profile' => egmCheckInAutomaticPrintProfile($context, $scanGuestCode),
            'print_guest' => $printGuest,
        ]);
    }
    if ($method === 'POST' && $action === 'record_ticket_number') {
        winAppRequireCsrf($payload);
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
            is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : []
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
            'print_profile' => egmCheckInAutomaticPrintProfile($context, (string)($payload['guest_code'] ?? ($payload['national_id'] ?? ($payload['work_id'] ?? '')))),
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
            $_SESSION['user']
        );
        winAppJson(['status' => 'ok'] + $result + [
            'access' => $access,
            'event' => winAppEventSummary($context),
            'stats' => egmCheckInDashboardStats($context),
            'logs' => egmCheckInRecentLogs($context, 30),
            'print_profile' => egmCheckInAutomaticPrintProfile($context, (string)($payload['guest_code'] ?? '')),
        ]);
    }
    winAppJson(['status' => 'error', 'message' => 'عملیات درخواستی پشتیبانی نمی‌شود.'], 404);
} catch (InvalidArgumentException $error) {
    winAppJson(['status' => 'error', 'message' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('WinApp API failed: ' . $error->getMessage());
    winAppJson(['status' => 'error', 'message' => 'سرویس کنترل مهمان موقتاً در دسترس نیست.'], 500);
}
