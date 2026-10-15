<?php
declare(strict_types=1);
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/tab-permissions.php';
require_once __DIR__ . '/egm-facilitators.php';

function egmDrawSessionStart(string $missionDir): void
{
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    session_name('EGMDRAW' . substr(hash('sha256', realpath($missionDir) ?: $missionDir), 0, 12));
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443';
    session_set_cookie_params(['lifetime' => 2592000, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    $directory = sys_get_temp_dir() . '/egm-draw-' . substr(hash('sha256', __DIR__), 0, 20);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('ذخیره نشست امکان‌پذیر نیست.');
    session_save_path($directory);
    ini_set('session.gc_maxlifetime', '2592000');
    session_start();
}

function egmDrawSessionUser(PDO $pdo, string $eventCode = ''): ?array
{
    $facilitatorId = (string)($_SESSION['draw_facilitator_id'] ?? '');
    if ($facilitatorId !== '') {
        if ($eventCode === '' || !hash_equals($eventCode, (string)($_SESSION['draw_facilitator_event'] ?? ''))) return null;
        $user = egmFacilitatorsSessionUser(egmFacilitatorsRead($pdo, $eventCode), $facilitatorId, (string)($_SESSION['draw_auth_version'] ?? ''));
        return $user === null ? null : $user + ['egm_draw_facilitator_event' => $eventCode];
    }
    $code = (string)($_SESSION['draw_user_code'] ?? '');
    if ($code === '') return null;
    $query = $pdo->prepare('SELECT * FROM `users` WHERE `code` = :code LIMIT 1');
    $query->execute([':code' => $code]);
    $user = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($user) || !hash_equals((string)($_SESSION['draw_auth_version'] ?? ''), hash('sha256', (string)$user['password_hash']))) return null;
    return $user;
}

function egmDrawRequireUser(array $context, bool $json = false): array
{
    if (!$json && ($_POST['draw_logout'] ?? '') === '1' && egmSecurityIsValidCsrfToken((string)($_POST['csrf'] ?? ''))) {
        unset($_SESSION['draw_user_code'], $_SESSION['draw_auth_version'], $_SESSION['draw_facilitator_id'], $_SESSION['draw_facilitator_event']);
        session_regenerate_id(true);
        header('Location: general_draw.php?level_id=' . rawurlencode((string)($_GET['level_id'] ?? '')), true, 303);
        exit;
    }
    $eventCode = (string)$context['code'];
    $user = egmDrawSessionUser($context['pdo'], $eventCode);
    if ($user !== null && (isset($user['egm_draw_facilitator_event']) || userHasTabPermission($user, 'event-guest-manager'))) return $user;
    if ($json) denyPanelAccess(401, 'برای ادامه وارد حساب کاربری شوید.', true);
    $errors = [];
    $username = '';
    $csrf = egmSecurityGetCsrfToken();
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $username = trim((string)($_POST['username'] ?? ''));
        $failures = array_filter((array)($_SESSION['draw_failures'] ?? []), static fn($t) => is_int($t) && $t > time() - 600);
        if (!egmSecurityIsValidCsrfToken((string)($_POST['csrf'] ?? ''))) $errors[] = 'صفحه را تازه‌سازی کنید.';
        elseif (count($failures) >= 5) { http_response_code(429); $errors[] = 'چند دقیقه دیگر دوباره امتحان کنید.'; }
        else {
            $query = $context['pdo']->prepare('SELECT * FROM `users` WHERE `username` = :username LIMIT 1');
            $query->execute([':username' => $username]);
            $found = $query->fetch(PDO::FETCH_ASSOC);
            if (is_array($found) && password_verify((string)($_POST['password'] ?? ''), (string)$found['password_hash']) && userHasTabPermission($found, 'event-guest-manager')) {
                session_regenerate_id(true);
                $_SESSION['draw_user_code'] = (string)$found['code'];
                unset($_SESSION['draw_facilitator_id'], $_SESSION['draw_facilitator_event']);
                $_SESSION['draw_auth_version'] = hash('sha256', (string)$found['password_hash']);
                unset($_SESSION['draw_failures'], $_SESSION['task_club_csrf']);
                header('Location: general_draw.php?level_id=' . rawurlencode((string)($_GET['level_id'] ?? '')), true, 303);
                exit;
            }
            $facilitator = egmFacilitatorsAuthenticateRows(egmFacilitatorsRead($context['pdo'], $eventCode), $username, (string)($_POST['password'] ?? ''));
            if ($facilitator !== null) {
                session_regenerate_id(true);
                unset($_SESSION['draw_user_code'], $_SESSION['draw_failures'], $_SESSION['task_club_csrf']);
                $_SESSION['draw_facilitator_id'] = (string)$facilitator['id'];
                $_SESSION['draw_facilitator_event'] = $eventCode;
                $_SESSION['draw_auth_version'] = (string)$facilitator['auth_version'];
                header('Location: general_draw.php?level_id=' . rawurlencode((string)($_GET['level_id'] ?? '')), true, 303);
                exit;
            }
            $failures[] = time(); $_SESSION['draw_failures'] = $failures;
            $errors[] = 'نام کاربری یا رمز عبور معتبر نیست.';
        }
    }
    $siteSettings = loadDataFromDb($context['pdo'], loadConfig(dirname(__DIR__) . '/config.php'));
    $siteIconUrl = trim((string)($siteSettings['settings']['siteIcon'] ?? ''));
    if ($siteIconUrl !== '' && !preg_match('/^(?:data:|https?:\/\/|\/)/i', $siteIconUrl)) $siteIconUrl = '/' . $siteIconUrl;
    $cspNonce = egmSecurityCreateCspNonce();
    egmSecuritySendPageHeaders($cspNonce);
    require __DIR__ . '/egm-draw-login-view.php';
    exit;
}
