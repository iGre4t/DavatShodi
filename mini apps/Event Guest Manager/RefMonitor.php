<?php
declare(strict_types=1);

$refResponseBufferLevel=null;
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&!in_array((string)($_POST['action']??''),['login','logout'],true)){
    ob_start();$refResponseBufferLevel=ob_get_level();
}
function refMonitorJsonResponse(array $data,int $status=200):never
{
    global $refResponseBufferLevel;
    if(is_int($refResponseBufferLevel))while(ob_get_level()>=$refResponseBufferLevel)ob_end_clean();
    http_response_code($status);header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
}

$projectRoot = realpath(__DIR__);
while (is_string($projectRoot) && !is_file($projectRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'config.php')) {
    $parent = dirname($projectRoot);
    $projectRoot = $parent === $projectRoot ? false : $parent;
}
if (!is_string($projectRoot)) {
    http_response_code(500);
    exit('Application configuration was not found.');
}
require_once $projectRoot . '/api/lib/common.php';
require_once $projectRoot . '/api/lib/users.php';
require_once $projectRoot . '/api/lib/egm-registry.php';
require_once $projectRoot . '/api/lib/egm-games.php';
require_once $projectRoot . '/api/lib/egm-refmonitor-teams.php';
require_once $projectRoot . '/api/lib/egm-facilitators.php';

// This cookie is independent of PHPSESSID (panel.php) and EGM's guest cookie.
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
    session_name('REFMONITOR' . substr(hash('sha256', (string)realpath(__DIR__)), 0, 12));
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'nonce-{$nonce}'; img-src 'self' data:; font-src 'self' data:; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");

if (!is_string($_SESSION['ref_monitor_csrf'] ?? null) || $_SESSION['ref_monitor_csrf'] === '') {
    $_SESSION['ref_monitor_csrf'] = bin2hex(random_bytes(32));
}
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$config = loadConfig($projectRoot . '/api/config.php');
$pdo = connectDatabase($config);
$eventName = 'RefMonitor';
$eventCode = '';
if ($pdo instanceof PDO) {
    $relativeDirectory = str_replace('\\', '/', substr(__DIR__, strlen($projectRoot) + 1));
    try {
        $registry = findEgmRegistryByDirectory($pdo, $relativeDirectory);
        $eventName = trim((string)($registry['name'] ?? '')) ?: $eventName;
        $eventCode = trim((string)($registry['code'] ?? ''));
    } catch (Throwable $error) {
        error_log('RefMonitor event lookup failed: ' . $error->getMessage());
    }
}

$currentUser = null;
$facilitatorId = (string)($_SESSION['ref_monitor_facilitator_id'] ?? '');
if ($facilitatorId !== '' && $pdo instanceof PDO && $eventCode !== '') {
    try {
        $currentUser = egmFacilitatorsSessionUser(egmFacilitatorsRead($pdo, $eventCode), $facilitatorId, (string)($_SESSION['ref_monitor_facilitator_version'] ?? ''));
    } catch (Throwable $error) {
        error_log('RefMonitor facilitator session failed: ' . $error->getMessage());
    }
    if (!$currentUser) unset($_SESSION['ref_monitor_facilitator_id'], $_SESSION['ref_monitor_facilitator_version']);
}
$sessionCode = trim((string)($_SESSION['ref_monitor_user_code'] ?? ''));
if (!$currentUser && $sessionCode !== '' && $pdo instanceof PDO) {
    $user = loadUserByCode($pdo, $sessionCode);
    if (is_array($user) && userHasPermissionId($user, 'event-guest-manager')) {
        $currentUser = $user;
    } else {
        unset($_SESSION['ref_monitor_user_code']);
    }
}

$errors = [];
$username = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrf = (string)($_POST['csrf'] ?? '');
    if (!hash_equals((string)$_SESSION['ref_monitor_csrf'], $csrf)) {
        http_response_code(403);
        if (in_array((string)($_POST['action'] ?? ''), ['push_config', 'push_register', 'push_watch', 'push_remove', 'period_status', 'search_invitees', 'create_team', 'start_team', 'list_teams', 'team_detail', 'update_name', 'update_members', 'update_cover_color', 'update_team_details', 'complete_level', 'submit_score'], true)) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['status' => 'error', 'message' => 'فرم منقضی شده است. صفحه را تازه‌سازی کنید.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $errors[] = 'فرم منقضی شده است. صفحه را تازه‌سازی کنید.';
    } elseif (($_POST['action'] ?? '') === 'logout') {
        if ($currentUser && $pdo instanceof PDO && !empty($_SESSION['ref_push_device'])) {
            egmRefPushRemove($pdo,$eventCode,(string)$currentUser['code'],(string)$_SESSION['ref_push_device']);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $cookie = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $cookie['path'] ?? '/', $cookie['domain'] ?? '', (bool)($cookie['secure'] ?? false), (bool)($cookie['httponly'] ?? true));
        }
        session_destroy();
        header('Location: RefMonitor.php');
        exit;
    } elseif (($_POST['action'] ?? '') === 'login') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $attempts = array_values(array_filter((array)($_SESSION['ref_monitor_failures'] ?? []),
            static fn($time): bool => is_int($time) && $time > time() - 600));
        if (count($attempts) >= 5) {
            http_response_code(429);
            $errors[] = 'تلاش‌های زیادی انجام شده است. چند دقیقه دیگر دوباره امتحان کنید.';
        } elseif (!$pdo instanceof PDO) {
            http_response_code(503);
            $errors[] = 'اتصال به پایگاه داده برقرار نشد.';
        } elseif ($username === '' || $password === '') {
            $errors[] = 'نام کاربری و رمز عبور الزامی است.';
        } else {
            try {
                $select = '`code`, `username`, `fullname`, `password_hash`';
                if (usersTableHasColumn($pdo, 'permissions')) $select .= ', `permissions`';
                $statement = $pdo->prepare("SELECT {$select} FROM `users` WHERE `username` = :username LIMIT 1");
                $statement->execute([':username' => $username]);
                $user = $statement->fetch(PDO::FETCH_ASSOC);
                if (is_array($user)
                    && password_verify($password, (string)($user['password_hash'] ?? ''))
                    && userHasPermissionId($user, 'event-guest-manager')) {
                    session_regenerate_id(true);
                    unset($_SESSION['ref_monitor_facilitator_id'], $_SESSION['ref_monitor_facilitator_version']);
                    $_SESSION['ref_monitor_user_code'] = (string)$user['code'];
                    $_SESSION['ref_monitor_csrf'] = bin2hex(random_bytes(32));
                    unset($_SESSION['ref_monitor_failures']);
                    header('Location: RefMonitor.php');
                    exit;
                }
                $facilitator = $eventCode !== '' ? egmFacilitatorsAuthenticateRows(egmFacilitatorsRead($pdo, $eventCode), $username, $password) : null;
                if ($facilitator) {
                    session_regenerate_id(true);
                    unset($_SESSION['ref_monitor_user_code'], $_SESSION['ref_monitor_failures']);
                    $_SESSION['ref_monitor_facilitator_id'] = $facilitator['id'];
                    $_SESSION['ref_monitor_facilitator_version'] = $facilitator['auth_version'];
                    $_SESSION['ref_monitor_csrf'] = bin2hex(random_bytes(32));
                    header('Location: RefMonitor.php');
                    exit;
                }
                $errors[] = 'نام کاربری یا رمز عبور معتبر نیست.';
            } catch (Throwable $error) {
                error_log('RefMonitor login failed: ' . $error->getMessage());
                http_response_code(503);
                $errors[] = 'بررسی حساب کاربری ناموفق بود.';
            }
        }
        if ($errors !== []) {
            $attempts[] = time();
            $_SESSION['ref_monitor_failures'] = $attempts;
        }
    } elseif (in_array((string)($_POST['action'] ?? ''), ['push_config', 'push_register', 'push_watch', 'push_remove', 'period_status', 'search_invitees', 'create_team', 'start_team', 'list_teams', 'team_detail', 'update_name', 'update_members', 'update_cover_color', 'update_team_details', 'complete_level', 'submit_score'], true)) {
        header('Content-Type: application/json; charset=UTF-8');
        if (!$currentUser || !$pdo instanceof PDO || $eventCode === '') {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'دسترسی معتبر نیست. دوباره وارد شوید.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            $pushAction=(string)($_POST['action']??'');
            if($pushAction==='push_config'){
                $keys=egmRefPushKeys($pdo);refMonitorJsonResponse(['status'=>'ok','public_key'=>$keys['publicKey']]);
            }
            if($pushAction==='push_register'){
                $raw=(string)($_POST['subscription']??'');if(strlen($raw)>10000)throw new InvalidArgumentException('اطلاعات اعلان نامعتبر است.');
                $subscription=json_decode($raw,true);if(!is_array($subscription))throw new InvalidArgumentException('اطلاعات اعلان نامعتبر است.');
                $host=(string)($_SERVER['HTTP_HOST']??'');if(!preg_match('/^[a-zA-Z0-9.\-:\[\]]+$/D',$host))throw new InvalidArgumentException('نشانی پنل نامعتبر است.');
                $url='https://'.$host.(string)($_SERVER['SCRIPT_NAME']??'');
                $device=egmRefPushRegister($pdo,$eventCode,(string)$currentUser['code'],$subscription,$url);$_SESSION['ref_push_device']=$device;
                refMonitorJsonResponse(['status'=>'ok','device_id'=>$device]);
            }
            if($pushAction==='push_remove'){
                egmRefPushRemove($pdo,$eventCode,(string)$currentUser['code'],(string)($_POST['device_id']??''));unset($_SESSION['ref_push_device']);
                refMonitorJsonResponse(['status'=>'ok']);
            }
            if (($_POST['action'] ?? '') === 'period_status') {
                try {
                    $period = egmRefMonitorActivePeriod($pdo, $eventCode);
                } catch (InvalidArgumentException $error) {
                    $period = null;
                }
                echo json_encode(['status' => 'ok', 'period_code' => $period['code'] ?? ''], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $activePeriod = egmRefMonitorActivePeriod($pdo, $eventCode);
            $periodCode = $activePeriod['code'];
            if (trim((string)($_POST['period_code'] ?? '')) !== $periodCode) {
                throw new InvalidArgumentException('بازهٔ فعال تغییر کرده است. صفحه را تازه‌سازی کنید.');
            }
            $gameId = trim((string)($_POST['game_id'] ?? ''));
            egmRefMonitorGameTable($pdo, $eventCode, $periodCode, $gameId);
            $action = (string)$_POST['action'];
            $teamId = (int)($_POST['team_id'] ?? 0);
            if ($action === 'push_watch') {
                if($teamId>0)egmRefMonitorReadTeam($pdo,egmRefMonitorGameTable($pdo,$eventCode,$periodCode,$gameId),$teamId);
                egmRefPushWatch($pdo,$eventCode,(string)$currentUser['code'],(string)($_POST['device_id']??''),$periodCode,$gameId,$teamId);
                $result=[];
            } elseif ($action === 'search_invitees') {
                $result = ['invitees' => egmRefMonitorSearchInvitees($pdo, $eventCode, $periodCode, (string)($_POST['query'] ?? ''), $gameId)];
            } elseif ($action === 'create_team') {
                $members = $_POST['members'] ?? [];
                if (!is_array($members)) throw new InvalidArgumentException('اعضای تیم معتبر نیستند.');
                if (!is_string($_POST['cover_color'] ?? '')) throw new InvalidArgumentException('رنگ پوشش معتبر نیست.');
                $result = ['team' => egmRefMonitorCreateTeam($pdo, $eventCode, $periodCode, $gameId, (string)($_POST['name'] ?? ''), $members, (string)$currentUser['code'], is_string($_POST['cover_color'] ?? '') ? ($_POST['cover_color'] ?? '') : '')];
            } elseif ($action === 'start_team') {
                $result = ['team' => egmRefMonitorStartTeam($pdo, $eventCode, $periodCode, $gameId, $teamId, (string)$currentUser['code'])];
            } elseif ($action === 'team_detail') {
                $result = ['team' => egmRefMonitorGetTeam($pdo, $eventCode, $periodCode, $gameId, $teamId)];
            } elseif ($action === 'update_name') {
                $result = ['team' => egmRefMonitorUpdateName($pdo, $eventCode, $periodCode, $gameId, $teamId, (string)($_POST['name'] ?? ''))];
            } elseif ($action === 'update_members') {
                $members = $_POST['members'] ?? [];
                if (!is_array($members)) throw new InvalidArgumentException('اعضای تیم معتبر نیستند.');
                $result = ['team' => egmRefMonitorUpdateMembers($pdo, $eventCode, $periodCode, $gameId, $teamId, $members)];
            } elseif ($action === 'update_team_details') {
                if (!is_string($_POST['name'] ?? null) || !is_string($_POST['cover_color'] ?? null)) throw new InvalidArgumentException('مشخصات تیم معتبر نیست.');
                $result = ['team' => egmRefMonitorUpdateDetails($pdo, $eventCode, $periodCode, $gameId, $teamId, $_POST['name'], $_POST['cover_color'])];
            } elseif ($action === 'update_cover_color') {
                if (!is_string($_POST['cover_color'] ?? null)) throw new InvalidArgumentException('رنگ پوشش معتبر نیست.');
                $result = ['team' => egmRefMonitorUpdateCoverColor($pdo, $eventCode, $periodCode, $gameId, $teamId, $_POST['cover_color'])];
            } elseif ($action === 'complete_level') {
                $result = ['team' => egmRefMonitorCompleteLevel($pdo, $eventCode, $periodCode, $gameId, $teamId, (string)($_POST['level_id'] ?? ''), (string)$currentUser['code'])];
            } elseif ($action === 'submit_score') {
                $result = ['team' => egmRefMonitorSubmitScore($pdo, $eventCode, $periodCode, $gameId, $teamId, (string)($_POST['level_id'] ?? ''), (string)($_POST['score'] ?? ''), (string)$currentUser['code'])];
            } else {
                $result = ['teams' => egmRefMonitorListTeams($pdo, $eventCode, $periodCode, $gameId)];
            }
            if (isset($result['team'])) $result['team'] = egmGameEnrichTeam($pdo, $eventCode, $periodCode, $gameId, $result['team']);
            refMonitorJsonResponse(['status' => 'ok'] + $result);
        } catch (InvalidArgumentException $error) {
            refMonitorJsonResponse(['status' => 'error', 'message' => $error->getMessage()],422);
        } catch (Throwable $error) {
            error_log('RefMonitor team request failed: ' . $error->getMessage());
            $isPush=str_starts_with((string)($_POST['action']??''),'push_');
            $message=$error instanceof EgmRefPushSetupException?$error->getMessage():($isPush?'ذخیرهٔ تنظیمات اعلان روی هاست ناموفق بود. دسترسی پایگاه داده و گزارش خطای PHP هاست را بررسی کنید.':'درخواست انجام نشد. دوباره تلاش کنید.');
            refMonitorJsonResponse(['status'=>'error','message'=>$message],$isPush?503:500);
        }
        exit;
    } else {
        http_response_code(400);
        $errors[] = 'درخواست نامعتبر است.';
    }
}
$displayName = trim((string)($currentUser['fullname'] ?? $currentUser['name'] ?? '')) ?: 'تسهیلگر';
$games = [];
$activePeriod = null;
$gamesError = '';
if ($currentUser) {
    if ($pdo instanceof PDO && $eventCode !== '') {
        try {
            $activePeriod = egmRefMonitorActivePeriod($pdo, $eventCode);
            $gameState = egmGamesState(['pdo' => $pdo, 'code' => $eventCode]);
            $enabledIds = $gameState['enabled'][$activePeriod['code']] ?? [];
            $games = array_values(array_filter($gameState['games'], static fn(array $game): bool => in_array($game['id'], $enabledIds, true)));
        } catch (InvalidArgumentException $error) {
            $gamesError = $error->getMessage();
        } catch (Throwable $error) {
            error_log('RefMonitor games lookup failed: ' . $error->getMessage());
            $gamesError = 'فهرست بازی‌ها در دسترس نیست. صفحه را دوباره بارگذاری کنید.';
        }
    } else {
        $gamesError = 'فهرست بازی‌ها در دسترس نیست.';
    }
}
$adminDisplayName = $displayName;
$assetRootUrl = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''), substr_count(str_replace('\\', '/', substr(__DIR__, strlen($projectRoot) + 1)), '/') + 2)), '/') . '/';
$panelStore = is_file($projectRoot . '/data/store.json') ? json_decode((string)file_get_contents($projectRoot . '/data/store.json'), true) : [];
if (!is_array($panelStore)) $panelStore = [];
if ($pdo instanceof PDO) {
    try { $dbStore = loadDataFromDb($pdo, $config); if (is_array($dbStore)) $panelStore = $dbStore; }
    catch (Throwable $error) { error_log('RefMonitor panel icon lookup failed.'); }
}
$panelIcon = trim((string)($panelStore['settings']['siteIcon'] ?? ''));
if ($panelIcon !== '' && !preg_match('~^(?:data:|https?://|//|/)~i', $panelIcon)) $panelIcon = $assetRootUrl . ltrim($panelIcon, './');
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title>پنل تسهیلگر</title>
  <link rel="manifest" href="RefMonitor-manifest.php">
  <meta name="theme-color" content="#ffffff">
  <link rel="apple-touch-icon" href="RefMonitor-icon.php">
  <link rel="icon" id="site-icon-link" href="<?= $escape($panelIcon ?: 'data:,') ?>">
  <style nonce="<?= $escape($nonce) ?>">
    .ref-room-arrival{position:fixed;inset:auto auto 24px 50%;margin:0;transform:translateX(-50%);z-index:20;width:min(390px,calc(100vw - 32px));border:1px solid #d9e7f7;border-radius:22px;padding:26px;background:#fff;color:#202329;box-shadow:0 18px 70px rgba(47,143,255,.22);font-family:Peyda,Tahoma,sans-serif;text-align:center}.ref-room-arrival::backdrop{background:rgba(18,30,48,.3)}.ref-room-arrival h2{margin:0 0 12px;font-size:1.15rem}.ref-room-arrival p{margin:10px 0;line-height:1.8}.ref-room-arrival strong{display:block;font-size:2rem;color:#147ac4;overflow-wrap:anywhere}.ref-room-arrival button{width:100%;margin-top:14px}
    @font-face{font-family:Peyda;src:url('../../style/fonts/PeydaWebFaNum-Regular.woff2') format('woff2');font-display:swap;font-weight:400}
    @font-face{font-family:Peyda;src:url('../../style/fonts/PeydaWebFaNum-Bold.woff2') format('woff2');font-display:swap;font-weight:700}
    :root{--ink:#202329;--muted:#646971;--line:#e7e8eb;--surface:#fff;--shadow:0 4px 18px rgba(20,24,33,.05);--focus:0 0 0 4px rgba(65,105,190,.1)}
    *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:18px;direction:rtl;text-align:right;font-family:Peyda,Tahoma,Arial,sans-serif;color:var(--ink);background:#f4f4f6}button,input{font:inherit}button{cursor:pointer}button:disabled{cursor:not-allowed;opacity:.45}.hidden{display:none!important}
    .app{width:min(460px,100%)}.phone{height:calc(100dvh - 36px);min-height:min(860px,calc(100dvh - 36px));display:flex;flex-direction:column;overflow:hidden;background:#fff;border:1px solid var(--line);border-radius:26px;box-shadow:0 24px 70px rgba(20,24,33,.09),0 2px 7px rgba(20,24,33,.03)}
    .topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:18px 22px;border-bottom:1px solid #f0f0f2;background:#fff}.brand{font-size:.91rem;font-weight:700;color:var(--ink)}.topbar-actions{display:flex;gap:8px}.topbar button{border:0;background:none;color:var(--muted);padding:5px;font-size:.85rem}.topbar form{margin:0}
    .login-area,.main-area{flex:1;min-height:0;overflow-y:auto;padding:24px 22px calc(20px + env(safe-area-inset-bottom,0px));display:flex;flex-direction:column;gap:18px;animation:view-in .22s ease-out}.main-area h1{font-size:1.35rem;line-height:1.5;margin:0;letter-spacing:-.02em}.login-hero{text-align:center;display:grid;justify-items:center;gap:12px}.login-icon{width:76px;height:76px;display:grid;place-items:center;border-radius:22px;background:#f5f5f7;color:var(--ink);font-size:1.6rem;font-weight:700}.login-title{margin:0;font-size:1.2rem}.login-subtitle{margin:0;font-size:.88rem;color:var(--muted)}.login-form,.stack{display:flex;flex-direction:column;gap:14px}
    .login-field{display:grid;gap:8px;font-size:.86rem;font-weight:700;color:#444950}.login-input{width:100%;min-width:0;border:1px solid #dedfe3;border-radius:12px;padding:12px 14px;font-size:1rem;background:#fff;color:var(--ink);outline:none;transition:border-color .18s,box-shadow .18s}.login-input::placeholder{color:#878b92;font-weight:400}.login-input:focus{border-color:#8998b5;box-shadow:var(--focus)}.password-wrap{position:relative}.password-wrap .login-input{padding-left:46px}.password-toggle{position:absolute;left:6px;top:50%;transform:translateY(-50%);border:0;background:none;color:var(--muted);width:34px;height:34px}
    .primary-action,.login-btn,.secondary-action{border-radius:13px;padding:13px 16px;font-weight:700;font-size:.95rem;transition:transform .16s,box-shadow .18s,background .18s}.primary-action,.login-btn{border:1px solid #24272d;background:#24272d;color:#fff;box-shadow:0 5px 16px rgba(20,24,33,.14)}.primary-action:hover:not(:disabled),.login-btn:hover{background:#343941;box-shadow:0 6px 22px rgba(20,24,33,.21)}.primary-action:active:not(:disabled),.secondary-action:active{transform:scale(.985)}.secondary-action{border:1px solid var(--line);background:#fff;color:#444950;box-shadow:0 2px 4px rgba(20,24,33,.025)}.secondary-action:hover{background:#f7f7f8}.login-hint{margin:0;min-height:1.2em;color:#ab343f;font-size:.82rem;text-align:center}.login-help-card,.placeholder{padding:16px;border:1px solid var(--line);border-radius:14px;background:#fff}.login-help-card p{margin:0;color:var(--muted);font-size:.81rem;line-height:1.9}
    .games-section{display:grid;gap:12px}.games-heading,.section-label{margin:0;color:#41464e;font-size:.93rem;font-weight:700}.games-list,.team-list,.result-list,.selected-list{list-style:none;margin:0;padding:0;display:grid;gap:10px}.game-card{display:flex;align-items:center;gap:12px;min-height:72px;padding:16px;border:1px solid var(--line);border-radius:16px;background:#fff;color:var(--ink);font-weight:700;box-shadow:var(--shadow)}button.game-card,button.team-card{width:100%;font:inherit;text-align:right;color:var(--ink);transition:border-color .18s,box-shadow .18s,transform .18s}button.game-card:hover,button.team-card:hover{border-color:#c9ccd3;box-shadow:0 7px 24px rgba(20,24,33,.08);transform:translateY(-1px)}.game-icon{width:36px;height:36px;flex:none;display:grid;place-items:center;border-radius:10px;background:#f5f5f7;color:#555b65;font-size:1.2rem}.game-arrow{margin-right:auto;color:#828790}
    .team-card,.person-card{padding:15px;border:1px solid var(--line);border-radius:14px;background:#fff;box-shadow:var(--shadow)}.team-card strong,.person-card strong{display:block;color:var(--ink);font-weight:700;font-size:1rem;overflow-wrap:anywhere}.team-card small,.person-card small{display:block;color:var(--muted);font-size:.8rem;line-height:1.8}.person-card{display:flex;align-items:center;justify-content:space-between;gap:12px}.person-card>span{min-width:0;overflow-wrap:anywhere}.person-card button{flex:none;font-size:.8rem;padding:8px 11px;box-shadow:none}.selected-list .person-card{border-color:#cfd2d8}.status-text{margin:0;color:var(--muted);font-size:.83rem;line-height:1.85}.status-text:empty{display:none}.status-text.error{color:#ab343f}.status-text.success{color:#41464e}
    .team-group{list-style:none;display:grid;gap:10px}.team-group-title{margin:12px 0 0;color:#777c85;font-size:.82rem;font-weight:700}.team-state{display:none!important}.team-room-name{display:block;margin-top:8px;font-size:1.12rem;font-weight:700;overflow-wrap:anywhere}.team-room-name::before{content:'اتاق فعلی · ';font-size:.78rem;font-weight:400;color:var(--muted)}
    .team-title-row,.section-heading-row{display:flex;align-items:center;justify-content:space-between;gap:10px}.team-title-row{justify-content:flex-start}.flow-intro{display:grid;gap:6px}.flow-intro h2{margin:0;font-size:1rem}.flow-intro p{margin:0;font-size:.83rem;color:var(--muted);line-height:1.8}.team-name-form{display:flex;gap:8px;align-items:center}.team-name-form .login-input{flex:1;min-width:0}.icon-action{width:34px;height:34px;flex:none;border:1px solid var(--line);border-radius:10px;background:#fff;color:#626873;font-size:1.15rem}.inline-action{border:0;background:none;padding:5px 0;color:#525d70;font-size:.84rem;font-weight:700}.game-context{margin:0;max-width:100%;color:var(--muted);font-size:.84rem;overflow-wrap:anywhere}.team-total-score{align-self:flex-start;font-size:.84rem;color:var(--muted);font-weight:400}
    .room-guidance{display:grid;gap:10px;padding:28px 18px;border:1px solid #e0e1e5;border-radius:20px;background:#fff;text-align:center;box-shadow:0 12px 34px rgba(20,24,33,.07),0 1px 3px rgba(20,24,33,.03);scroll-margin-top:16px}.room-instruction{margin:0;font-size:.88rem;color:var(--muted);line-height:1.8}.room-guidance .room-name{margin:0;font-size:clamp(2.1rem,9vw,3.3rem);font-weight:700;line-height:1.35;letter-spacing:-.035em;color:#202329;overflow-wrap:anywhere}.room-stage{margin:0;color:#7b8088;font-size:.8rem;line-height:1.8}.room-score-hint{margin:8px 0 0;font-size:.83rem;color:#646971;line-height:1.85}.room-guidance.waiting{box-shadow:var(--shadow)}.room-guidance.waiting .room-name{font-size:1.65rem}.room-guidance.waiting .room-instruction::before{content:'';display:inline-block;width:6px;height:6px;margin-inline-end:8px;border-radius:50%;background:#8d929b;animation:waiting-pulse 1.8s ease-in-out infinite}.room-guidance.room-arrived{animation:room-arrival .65s ease-out}
    .member-section{border-top:1px solid var(--line);padding-top:16px}.member-section .inline-action{margin:6px 0 8px}.detail-members{list-style:none;margin:0;padding:0;counter-reset:member;display:grid;gap:0}.detail-members li{counter-increment:member;display:flex;align-items:center;gap:12px;padding:13px 0;border-bottom:1px solid #f0f0f2;background:#fff}.detail-members li:last-child{border-bottom:0}.detail-members li::before{content:counter(member);display:grid;place-items:center;flex:none;width:32px;height:32px;border:1px solid #ebecf0;border-radius:50%;color:#6c727d;background:#fafafa;font-size:.85rem}.member-info{display:grid;gap:3px;min-width:0}.member-name{font-size:.97rem;font-weight:700;overflow-wrap:anywhere}.member-code{font-size:.76rem;color:#858a93;overflow-wrap:anywhere}.review-members{list-style:none;padding:0;margin:0;display:grid;gap:0}.review-members li{padding:12px 0;border-bottom:1px solid #eee;color:#41464e;font-size:.94rem;overflow-wrap:anywhere}.review-members li:last-child{border:0}
    .flow-panel{display:grid;gap:18px}.flow-card,.detail-card{display:grid;gap:14px;padding:18px;border:1px solid var(--line);border-radius:16px;background:#fff;box-shadow:var(--shadow)}.flow-card h2{margin:0;font-size:1rem;color:var(--ink)}.step-track{height:3px;border-radius:10px;background:#eee;overflow:hidden}.step-track span{display:block;height:100%;width:33.33%;background:#606773;transition:width .25s}.step-indicator{margin:0;font-size:.77rem;color:#858a93}.action-dock{position:sticky;bottom:-1px;z-index:5;flex:none;margin-top:auto;padding:14px 0 max(12px,env(safe-area-inset-bottom,0px));background:linear-gradient(to bottom,rgba(255,255,255,.94),#fff 22%)}.flow-action{width:100%;margin:0}.action-dock .status-text{margin-top:8px}
    .score-context{position:sticky;top:-24px;z-index:2;background:rgba(255,255,255,.96);backdrop-filter:blur(12px);padding:14px 0;border-bottom:1px solid var(--line)}.score-context h1{font-size:1.3rem}.score-context #ref-score-team{font-size:.86rem;color:#5f6570}.score-form{display:grid;gap:14px}.score-number{font-size:2rem;font-weight:700;text-align:center;padding:18px;background:#fff}.score-number::placeholder{font-size:.96rem;font-weight:400}.score-help{margin:0;color:var(--muted);font-size:.82rem;line-height:1.9}.score-calculation summary{cursor:pointer;color:#777c85;font-size:.8rem}.score-calculation[open] summary{margin-bottom:8px}.progress-list{list-style:none;padding:0;margin:0;display:grid;gap:10px}.progress-list li{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px;border:1px solid var(--line);border-radius:12px;background:#fff;color:#545b65;font-size:.87rem}
    .completion-card{display:grid;gap:14px;padding:26px 18px;border:1px solid var(--line);border-radius:20px;background:#fff;box-shadow:var(--shadow);text-align:center;scroll-margin-top:16px}.completion-card h2{margin:0;font-size:1.05rem;color:#4d545e}.completion-card strong{font-size:1.85rem;color:var(--ink)}.completion-card p{margin:0;font-size:.84rem;color:var(--muted);line-height:1.9}
    .cover-picker{display:grid;gap:12px;padding:0;border:0;background:#fff}.cover-detail{display:grid;gap:10px;padding:0;border:0;background:#fff}.cover-detail strong{font-size:.84rem;font-weight:400;color:#646971;overflow-wrap:anywhere}.cover-choices{display:flex;flex-wrap:wrap;gap:8px}.cover-choice{display:flex;align-items:center;gap:7px;padding:9px 11px;min-height:42px;border:1px solid var(--line);border-radius:10px;background:#fff;color:#545b65;font-size:.84rem;transition:border-color .18s,box-shadow .18s}.cover-choice[aria-pressed="true"]{border-color:#7d8490;box-shadow:0 0 0 2px rgba(50,60,78,.08)}.cover-swatch{width:14px;height:14px;flex:none;border:1px solid rgba(0,0,0,.16);border-radius:50%}.required-note{font-size:.72rem;font-weight:400;color:#888d96;margin-inline-start:5px}.cover-badge{display:block;width:fit-content;max-width:100%;margin:6px 0 0;font-size:.79rem;font-weight:400;color:#707681;overflow-wrap:anywhere}.cover-swatch[data-color="آبی"]{background:#357be2}.cover-swatch[data-color="قرمز"]{background:#d94b55}.cover-swatch[data-color="سبز"]{background:#3f9474}.cover-swatch[data-color="زرد"]{background:#e1b939}.cover-swatch[data-color="نارنجی"]{background:#e58b37}.cover-swatch[data-color="بنفش"]{background:#8a63bb}.cover-swatch[data-color="مشکی"]{background:#303642}.cover-swatch[data-color="سفید"]{background:#fff}
    .score-confirm{width:min(420px,calc(100% - 28px));max-height:calc(100dvh - 40px);overflow:auto;border:1px solid var(--line);border-radius:22px;padding:26px;color:var(--ink);background:#fff;font:inherit;text-align:right;box-shadow:0 30px 80px rgba(0,0,0,.18)}.score-confirm[open]{animation:view-in .2s ease-out}.score-confirm::backdrop{background:rgba(25,28,34,.35);backdrop-filter:blur(5px)}.score-confirm form{display:grid;gap:16px}.score-confirm h2,.score-confirm p{margin:0;line-height:1.85}.score-confirm h2{font-size:1.2rem}.score-confirm p{color:#646971;font-size:.9rem}.score-confirm strong{font-size:2rem;padding:20px 0;border-top:1px solid var(--line);border-bottom:1px solid var(--line);text-align:center}.score-confirm #ref-confirm-warning{font-size:.82rem}.confirm-actions{display:grid;gap:9px}
    button:focus-visible,summary:focus-visible{outline:3px solid #8796b0;outline-offset:3px}.quiet-details{border-top:1px solid var(--line);padding-top:12px}.quiet-details summary{cursor:pointer;color:var(--muted);font-size:.84rem}.quiet-details[open] summary{margin-bottom:12px}
    @keyframes view-in{from{opacity:0;transform:translateY(7px)}to{opacity:1;transform:translateY(0)}}@keyframes waiting-pulse{0%,100%{opacity:.35}50%{opacity:1}}@keyframes room-arrival{from{box-shadow:0 0 0 5px rgba(60,75,100,.12),0 12px 34px rgba(20,24,33,.07)}to{box-shadow:0 12px 34px rgba(20,24,33,.07)}}
    [data-ref-view="team-detail"]{gap:14px;padding-top:18px}.team-title-row{justify-content:space-between;gap:12px}.team-title-row h1{font-size:1.2rem;min-width:0;overflow-wrap:anywhere;line-height:1.5}.team-meta{display:flex;align-items:center;flex-wrap:wrap;gap:5px 12px;color:#747983;font-size:.78rem}.team-meta>*{margin:0;font-size:inherit}.team-meta #ref-detail-cover{font-weight:400;font-size:.78rem;color:#747983}.team-meta .status-text{font-size:.78rem}.room-guidance{padding:16px;gap:5px;border-radius:16px;box-shadow:0 5px 20px rgba(20,24,33,.055)}.room-guidance .room-name{font-size:2rem;line-height:1.25;letter-spacing:-.025em}.room-instruction{font-size:.76rem}.room-stage{font-size:.75rem}.room-guidance.waiting .room-name{font-size:1.35rem}.member-section{padding-top:10px}.member-section h2{margin:0;padding:4px 0;color:#41464e;font-size:.84rem;font-weight:700}.detail-members{margin-top:8px}.detail-members li{padding:9px 0;gap:10px}.member-name{font-size:.88rem}.member-code{font-size:.73rem}.detail-members li::before{width:28px;height:28px;font-size:.77rem}.completion-card{padding:18px;gap:10px}.completion-card h2{font-size:.94rem}.completion-card p{display:none}#ref-team-edit .login-input{font-size:.94rem}#ref-team-edit #ref-edit-members{font-size:.84rem;padding:10px}#ref-team-edit #ref-member-hint{font-size:.75rem}
    /* MCI-inspired sky blue: strong actions, soft guidance, readable surfaces. */
    :root{--ink:#17364a;--muted:#607888;--line:#dceaf2;--blue:#0076ad;--blue-gradient:linear-gradient(120deg,#00699f 0%,#0089bc 58%,#009fc9 100%);--shadow:0 4px 18px rgba(0,105,159,.075);--focus:0 0 0 4px rgba(0,153,205,.16)}
    body{background:radial-gradient(ellipse at 15% 10%,#d6f2fc,transparent 55%),linear-gradient(145deg,#edf8fd,#e4f0f8)}
    .phone{border-color:#cfe5f1;box-shadow:0 24px 70px rgba(0,105,159,.14),0 2px 7px rgba(0,105,159,.05)}
    .topbar{background:#fff;border-bottom-color:var(--line)}.brand{color:var(--ink)}.topbar button{color:var(--blue);border-radius:8px}.topbar button:hover{background:#f0f8fc}.welcome-name{color:var(--blue)}.main-area h1.welcome{color:#20252c}
    .brand{display:flex;align-items:center;gap:9px;min-width:0}.brand-icon-badge{display:grid;place-items:center;width:36px;height:36px;flex:none;background:#fff;border-radius:11px;box-shadow:0 3px 10px rgba(0,62,98,.15)}.brand-icon{width:25px;height:24px;object-fit:contain}.brand-name{min-width:0;overflow-wrap:anywhere;line-height:1.5}.topbar-actions{flex:none}
    .main-area h1.roadmap,.team-title-row h1.roadmap{display:flex;align-items:baseline;flex-wrap:wrap;gap:5px 8px;margin:0;font-size:1rem;line-height:1.6;letter-spacing:0}.roadmap-parent{color:var(--blue);font-size:.83rem;font-weight:400;overflow-wrap:anywhere}.roadmap-separator{direction:ltr;unicode-bidi:isolate;color:#82aec5;font-size:1rem;font-weight:400}.roadmap-current{min-width:0;overflow-wrap:anywhere}
    .primary-action,.login-btn{border-color:transparent;background:var(--blue-gradient);box-shadow:0 5px 16px rgba(0,118,173,.22)}
    .primary-action:hover:not(:disabled),.login-btn:hover{background:linear-gradient(120deg,#005b8b,#007cad 58%,#008fb8);box-shadow:0 7px 22px rgba(0,118,173,.3)}
    .secondary-action{color:#00699f;border-color:#cee5f1;background:linear-gradient(120deg,#fff,#f4fbff)}.secondary-action:hover{background:#eaf7fd}
    .login-icon,.game-icon{color:#0076ad;background:linear-gradient(135deg,#e0f5fd,#eff9ff)}.game-arrow,.inline-action{color:var(--blue)}
    button.game-card:hover,button.team-card:hover{border-color:#8ccfe9;box-shadow:0 7px 24px rgba(0,118,173,.12)}.selected-list .person-card{border-color:#9cd6ec}
    .login-input:focus{border-color:#0096c7}.icon-action{color:var(--blue);background:#f0faff;border-color:#d0eaf5}.icon-action:hover{box-shadow:var(--focus)}
    .room-guidance{background:linear-gradient(120deg,#e9f8ff 0%,#f5fcff 65%,#def3fc 100%);border-color:#bce3f4;box-shadow:0 5px 20px rgba(0,118,173,.1)}.room-guidance .room-name{color:#00699f}.room-instruction,.room-stage{color:#4f748b}.room-guidance.waiting .room-instruction::before{background:#0099cd}
    .detail-members li{border-bottom-color:#e6f1f7}.detail-members li::before{color:#0076ad;border-color:#cdeaf6;background:linear-gradient(135deg,#e4f6fd,#f4fbff)}.member-section h2,.games-heading,.section-label{color:#285873}
    .step-track{background:#e4f1f8}.step-track span{background:var(--blue-gradient)}.completion-card{background:linear-gradient(135deg,#f5fcff,#eaf7fd);border-color:#c5e6f4}.completion-card h2,.completion-card strong{color:#00699f}
    .score-confirm{border-color:#c9e6f3;box-shadow:0 30px 80px rgba(0,76,118,.2)}.score-confirm strong{color:#00699f}.score-confirm::backdrop{background:rgba(15,55,78,.35)}
    .person-card.prize-walk-in,.detail-members li.prize-walk-in,.review-members li.prize-walk-in{background:#fff9e9;border-color:#f1dfab}.person-card.prize-past,.detail-members li.prize-past,.review-members li.prize-past{background:#fff1f2;border-color:#f0ced2}.prize-warning{display:block;margin-top:4px;font-size:.73rem;font-weight:400;line-height:1.7;color:#856520}.prize-past .prize-warning{color:#a34c59}.detail-members li.prize-walk-in,.detail-members li.prize-past,.review-members li.prize-walk-in,.review-members li.prize-past{padding-inline:10px;border-radius:10px}
    .room-guidance:not(.waiting){background:linear-gradient(115deg,#005f97,#0084b8,#0076ad,#00649f);background-size:300% 300%;border-color:#0087ba;box-shadow:0 7px 24px rgba(0,118,173,.22);animation:room-gradient 7s ease-in-out infinite}
    .room-guidance:not(.waiting) .room-name,.room-guidance:not(.waiting) .room-instruction,.room-guidance:not(.waiting) .room-stage{color:#fff}.room-guidance:not(.waiting) .room-name{text-shadow:0 2px 8px rgba(0,49,83,.2)}
    .room-guidance.room-arrived:not(.waiting){animation:room-gradient 7s ease-in-out infinite,room-arrival .65s ease-out}
    @keyframes room-gradient{0%,100%{background-position:0% 50%}50%{background-position:100% 50%}}
    @keyframes room-arrival{from{box-shadow:0 0 0 5px rgba(0,153,205,.18),0 8px 26px rgba(0,118,173,.25)}to{box-shadow:0 7px 24px rgba(0,118,173,.22)}}
    #ref-detail-dock #ref-completion-back{margin-top:8px}
    @media(max-width:440px){body{padding:8px}.phone{height:calc(100dvh - 16px);min-height:calc(100dvh - 16px);border-radius:22px}.topbar{padding:16px 18px}.login-area,.main-area{padding:20px 18px calc(16px + env(safe-area-inset-bottom,0px))}.score-context{top:-20px}}
    @media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important;scroll-behavior:auto!important}}
  </style>
  <style nonce="<?= $escape($nonce) ?>">
    .ref-loader{position:fixed;inset:0;width:100%;height:100%;max-width:none;max-height:none;margin:0;padding:0;border:0;background:radial-gradient(circle at top,rgba(223,236,255,.96),rgba(244,247,251,.97) 50%,rgba(255,255,255,.98));color:#516089}
    .ref-loader[open]{display:grid;place-items:center}.ref-loader::backdrop{background:transparent}.ref-loader-card{width:min(300px,80vw);display:grid;justify-items:center;gap:12px;text-align:center}.ref-loader svg{width:84px;height:62px;overflow:visible;margin:20px}.ref-loader-fill{fill:rgba(47,143,255,.16)}.ref-loader-path{fill:none;stroke:#2f8fff;stroke-width:22;stroke-linecap:round;stroke-linejoin:round;stroke-dasharray:100;stroke-dashoffset:100;animation:ref-icon-outline 1.2s linear infinite}.ref-loader p{margin:0;font-size:.9rem}.ref-loader-message{font-size:.78rem!important;line-height:1.8;color:#7886a0}.ref-loader-actions{display:none;gap:8px}.ref-loader.failed .ref-loader-actions{display:flex}.ref-loader.failed .ref-loader-path{animation:none;stroke-dashoffset:0;stroke:#a95a68}.ref-loader.failed .ref-loader-fill{fill:rgba(169,90,104,.12)}.ref-loader-failure-mark{display:none;fill:none;stroke:#a95a68;stroke-width:26;stroke-linecap:round}.ref-loader.failed .ref-loader-failure-mark{display:block}
    .ref-loader svg{width:72px;height:48px;margin:24px 12px;overflow:visible}.ref-loader-path{stroke-dasharray:950 1250;stroke-dashoffset:0;animation:ref-icon-outline 2.4s linear infinite}.ref-loader.failed .ref-loader-path{stroke-dasharray:none}
    @keyframes ref-icon-outline{0%{stroke-dashoffset:0;opacity:.85}50%{opacity:1}100%{stroke-dashoffset:-2200;opacity:.85}}
    .cover-native{position:absolute;width:1px;height:1px;opacity:0;pointer-events:none}.cover-trigger{display:flex;align-items:center;justify-content:space-between;gap:12px;width:100%;font:inherit;text-align:right}.cover-trigger::after{content:'⌄';color:var(--blue)}.cover-color-dialog{box-sizing:border-box;width:min(340px,calc(100vw - 32px));max-height:calc(100dvh - 48px);padding:18px;border:1px solid var(--line);border-radius:18px;box-shadow:0 24px 70px rgba(15,55,78,.2);font:inherit;color:var(--ink)}.cover-color-dialog::backdrop{background:rgba(15,55,78,.28)}.cover-color-dialog h2{margin:0 0 12px;font-size:1rem}.cover-color-options{display:grid;gap:5px;max-height:min(340px,55dvh);overflow-y:auto;overscroll-behavior:contain;padding:2px}.cover-color-options button{display:flex;align-items:center;gap:10px;width:100%;padding:11px;border:1px solid transparent;border-radius:10px;background:#fff;color:inherit;font:inherit;text-align:right}.cover-color-options button:hover,.cover-color-options button:focus-visible,.cover-color-options button[aria-selected=true]{background:#f0f8fc;border-color:#bce3f4;outline:none}.cover-color-dot{width:17px;height:17px;border-radius:50%;border:1px solid rgba(0,0,0,.16);flex:none}.cover-color-dialog>.secondary-action{margin-top:12px;width:100%}
    @media(prefers-reduced-motion:reduce){.ref-loader-path{animation:none;stroke-dashoffset:0}}
  </style>
</head>
<body>
<main class="app"><section class="phone">
  <header class="topbar"><div class="brand"><span class="brand-icon-badge"><svg class="brand-icon" viewBox="0 0 1173 773" role="img" aria-label="همراه اول"><path fill="#0095DA" d="M1173 407.266V773C791.7 589.486 381.3 521.402 0 573.591V16.8977C319.721 -26.5479 659.341 13.9796 985.446 136.213C1099.03 178.364 1173 286.979 1173 406.947V407.266Z"/></svg></span><span class="brand-name"><?= $escape($adminDisplayName) ?></span></div><div class="topbar-actions">
    <?php if ($currentUser): ?>
      <button type="button" id="ref-back" class="hidden">برگشت</button>
      <form method="post" id="ref-logout-form"><input type="hidden" name="csrf" value="<?= $escape($_SESSION['ref_monitor_csrf']) ?>"><input type="hidden" name="action" value="logout"><button type="submit">خروج</button></form>
    <?php endif; ?>
  </div></header>
  <?php if (!$currentUser): ?>
    <div class="login-area"><div class="login-hero"><div class="login-icon" aria-hidden="true">داور</div><h1 class="login-title"><?= $escape($eventName) ?></h1><p class="login-subtitle">ورود به پنل داور</p></div>
      <form class="login-form" method="post" autocomplete="on"><input type="hidden" name="action" value="login"><input type="hidden" name="csrf" value="<?= $escape($_SESSION['ref_monitor_csrf']) ?>">
        <label class="login-field"><span>نام کاربری</span><input class="login-input" name="username" autocomplete="username" value="<?= $escape($username) ?>" required></label>
        <label class="login-field"><span>رمز عبور</span><span class="password-wrap"><input class="login-input" id="ref-password" name="password" type="password" autocomplete="current-password" required><button class="password-toggle" id="ref-password-toggle" type="button" aria-label="نمایش رمز عبور">◉</button></span></label>
        <button class="login-btn" type="submit">ورود</button><p class="login-hint" role="alert"><?= $escape(implode(' ', $errors)) ?></p>
      </form><div class="login-help-card"><p>با حساب تسهیلگر این رویداد یا حساب مدیریت دارای دسترسی EGM وارد شوید.</p></div>
    </div>
  <?php else: ?>
    <div class="main-area" data-ref-view="home"><h1 class="welcome"><span class="welcome-name"><?= $escape($displayName) ?></span> عزیز، خوش آمدید</h1>
      <button type="button" class="secondary-action" id="ref-room-alerts" aria-pressed="false">فعال‌کردن صدای اتاق و اعلان</button><p class="status-text" id="ref-alert-status" role="status"></p>
      <?php if ($activePeriod): ?><p class="status-text">بازهٔ فعال: <?= $escape($activePeriod['title']) ?></p><?php endif; ?>
      <section class="games-section" aria-labelledby="games-heading"><h2 id="games-heading" class="games-heading">بازی‌ها</h2>
        <?php if ($gamesError !== ''): ?><div class="placeholder" role="alert"><?= $escape($gamesError) ?></div>
        <?php elseif ($games === []): ?><div class="placeholder">در این بازه بازی فعالی وجود ندارد.</div>
        <?php else: ?><ul class="games-list">
          <?php foreach ($games as $game): ?><li><button type="button" class="game-card" data-open-game="<?= $escape($game['id']) ?>"><span class="game-icon" aria-hidden="true">♟</span><span><?= $escape($game['name']) ?></span><span class="game-arrow" aria-hidden="true">‹</span></button></li><?php endforeach; ?>
        </ul><?php endif; ?>
      </section>
    </div>
    <div class="main-area hidden" data-ref-view="game"><h1 class="roadmap" id="ref-game-title"></h1>
      <p class="status-text" id="ref-game-period"></p>
      <section class="games-section"><h2 class="games-heading">تیم‌های ثبت‌شده</h2><p class="status-text" id="ref-teams-status" role="status"></p><ul class="team-list" id="ref-teams-list"></ul></section>
      <div class="action-dock"><button type="button" class="primary-action flow-action" id="ref-new-team">تشکیل تیم</button></div>
    </div>
    <div class="main-area hidden" data-ref-view="team"><h1 id="ref-team-form-title">تشکیل تیم</h1><p class="status-text" id="ref-team-context"></p>
      <div class="step-track" aria-hidden="true"><span id="ref-step-fill"></span></div><p class="step-indicator" id="ref-step-label"></p>
      <section class="flow-panel" data-team-step="name">
        <label class="login-field"><span>نام تیم</span><input class="login-input" id="ref-team-name" maxlength="100" autocomplete="off" required></label><section class="cover-picker hidden" id="ref-cover-picker"><label class="login-field"><span>رنگ پوشش تیم</span><select class="login-input" id="ref-team-cover-color"><option value="">انتخاب رنگ</option></select></label></section></section>
      <section class="flow-panel hidden" data-team-step="members">
        <label class="login-field"><span>جستجوی عضو</span><input class="login-input" id="ref-search-input" autocomplete="off" placeholder="نام یا کد پرسنلی"></label>
        <p class="status-text" id="ref-search-status" role="status"></p><ul class="result-list" id="ref-search-results"></ul>
        <div class="flow-card hidden" id="ref-selected-card"><h2>اعضا <span id="ref-member-count">(۰)</span></h2><ul class="selected-list" id="ref-selected-list"></ul></div></section>
      <section class="flow-panel hidden" data-team-step="review">
        <div class="flow-card"><h2 id="ref-review-name"></h2><p class="cover-badge hidden" id="ref-review-cover"></p><p class="status-text" id="ref-review-count"></p><ol class="review-members" id="ref-review-members"></ol></div></section>
      <div class="action-dock" data-team-action="name"><button type="button" class="primary-action flow-action" id="ref-team-next-name">ادامه</button></div>
      <div class="action-dock hidden" data-team-action="members"><button type="button" class="primary-action flow-action" id="ref-team-next-members" disabled>ادامه</button></div>
      <div class="action-dock hidden" data-team-action="review"><button type="button" class="primary-action flow-action" id="ref-submit-team">ثبت تیم</button><p class="status-text" id="ref-submit-status" role="status"></p></div>
    </div>
    <div class="main-area hidden" data-ref-view="team-detail"><div class="flow-intro"><div class="team-title-row"><h1 class="roadmap" id="ref-detail-title"></h1><button class="icon-action" type="button" id="ref-edit-name" aria-label="ویرایش تیم" title="ویرایش تیم">✎</button></div>
      </div><div class="team-meta"><p class="status-text" id="ref-detail-state"></p><span class="hidden" id="ref-cover-detail"><strong id="ref-detail-cover"></strong></span></div><section class="room-guidance hidden" id="ref-room-card" aria-live="polite" aria-atomic="true"></section><strong class="team-total-score hidden" id="ref-total-score"></strong>
      <p class="status-text" id="ref-journey-status" role="status" aria-live="polite"></p><section class="completion-card hidden" id="ref-completion-card"><h2>بازی این تیم پایان یافت</h2><strong id="ref-completion-score"></strong><p>امتیازها ثبت شدند. می‌توانید به فهرست تیم‌ها برگردید.</p></section><p class="status-text" id="ref-start-hint" role="status"></p>
      <section class="member-section" id="ref-member-section" aria-labelledby="ref-detail-members-title"><h2 id="ref-detail-members-title">اعضای تیم</h2><ul class="detail-members" id="ref-detail-members"></ul></section><p class="status-text" id="ref-score-status" role="status"></p>
      <div class="action-dock" id="ref-detail-dock"><button class="primary-action flow-action hidden" type="button" id="ref-start-team">شروع بازی</button><button class="primary-action flow-action hidden" type="button" id="ref-open-scores">ثبت امتیاز</button><button class="primary-action flow-action hidden" type="button" id="ref-detail-home">بازگشت به خانه</button><button type="button" class="secondary-action flow-action hidden" id="ref-completion-back">بازگشت به تیم‌ها</button></div>
    </div>
    <div class="main-area hidden" data-ref-view="score"><div class="flow-intro"><h1>ثبت امتیاز</h1><p class="status-text" id="ref-levels-team"></p></div><ul class="progress-list" id="ref-score-levels"></ul><p class="status-text" id="ref-levels-status" role="status"></p></div>
    <div class="main-area hidden" data-ref-view="score-entry"><div class="flow-intro score-context"><h1 id="ref-score-title">مرحله بازی</h1><p class="status-text" id="ref-score-team"></p></div>
      <div class="flow-card" id="ref-score-stage"></div><p class="status-text" id="ref-score-step-status" role="status"></p>
      <div class="action-dock" id="ref-score-dock"><button class="primary-action flow-action" type="submit" form="ref-score-form" id="ref-score-submit">ثبت امتیاز و ادامه</button></div>
      <div class="action-dock hidden" id="ref-score-home-dock"><button class="primary-action flow-action" type="button" id="ref-score-home">بازگشت به خانه</button></div>
    </div>
  <?php endif; ?>
</section></main>
<dialog id="ref-team-edit" class="score-confirm" aria-labelledby="ref-team-edit-title"><form id="ref-team-edit-form"><h2 id="ref-team-edit-title">ویرایش تیم</h2><label class="login-field"><span>نام تیم</span><input class="login-input" id="ref-detail-name" maxlength="100" required></label><label class="login-field" id="ref-edit-color-field"><span>رنگ پوشش</span><select class="login-input" id="ref-detail-cover-input"></select></label><button class="secondary-action hidden" type="button" id="ref-edit-members">ویرایش اعضا</button><p class="status-text" id="ref-member-hint"></p><p class="status-text" id="ref-edit-status" role="status"></p><div class="confirm-actions"><button type="submit" class="primary-action" id="ref-edit-save">ذخیره تغییرات</button><button type="button" class="secondary-action" id="ref-edit-cancel">لغو</button></div></form></dialog>
<dialog id="ref-score-confirm" class="score-confirm" aria-labelledby="ref-confirm-title" aria-describedby="ref-confirm-warning"><form method="dialog"><h2 id="ref-confirm-title">تأیید امتیاز</h2><p id="ref-confirm-team"></p><p id="ref-confirm-room"></p><strong id="ref-confirm-score"></strong><p id="ref-confirm-warning">پس از ثبت، این امتیاز قابل تغییر نیست. نام تیم، اتاق و مجموع امتیاز را بررسی کنید.</p><p class="status-text error" id="ref-confirm-status" role="alert"></p><div class="confirm-actions"><button type="button" class="primary-action" id="ref-confirm-save">تأیید و ثبت امتیاز</button><button type="submit" class="secondary-action" id="ref-confirm-cancel">برگشت و اصلاح</button></div></form></dialog>
<script nonce="<?= $escape($nonce) ?>">
(() => {
  // The same MCI silhouette as Task Club, drawing its complete outline each cycle.
  const loader = document.createElement('dialog'); loader.className = 'ref-loader'; loader.id = 'ref-loader';
  loader.setAttribute('aria-label', 'وضعیت بارگذاری');
  const iconPath = 'M1173 407.266V773C791.7 589.486 381.3 521.402 0 573.591V16.8977C319.721 -26.5479 659.341 13.9796 985.446 136.213C1099.03 178.364 1173 286.979 1173 406.947V407.266Z';
  loader.innerHTML = `<div class="ref-loader-card"><svg viewBox="0 0 1173 773" aria-hidden="true"><path class="ref-loader-fill" d="${iconPath}"/><path class="ref-loader-path" d="${iconPath}"/><path class="ref-loader-failure-mark" d="M555 250v160m0 85v5"/></svg><p role="status" aria-live="polite">در حال آماده‌سازی</p><p class="ref-loader-message"></p><div class="ref-loader-actions"><button type="button" class="secondary-action" data-loader-close>بازگشت</button><button type="button" class="primary-action" data-loader-reload>تازه‌سازی صفحه</button></div></div>`;
  document.body.append(loader);
  let loaderDepth = 0, loaderStarted = 0, loaderGeneration = 0;
  const loaderCycle = () => matchMedia('(prefers-reduced-motion: reduce)').matches ? 200 : 2400;
  const beginLoading = () => {
    if (!loader.open) {
      loader.classList.remove('failed'); loader.querySelector('.ref-loader-message').textContent = '';
      loader.querySelector('[role="status"]').textContent = 'در حال آماده‌سازی';
      loader.showModal(); loaderStarted = performance.now(); loaderGeneration++;
      const path = loader.querySelector('.ref-loader-path'); path.style.animation = 'none'; void path.getBoundingClientRect(); path.style.animation = '';
    }
    loaderDepth++;
    return loaderGeneration;
  };
  const finishLoading = async (generation, error = null) => {
    const elapsed = performance.now() - loaderStarted, cycle = loaderCycle();
    await new Promise(resolve => setTimeout(resolve, Math.max(0, Math.ceil(Math.max(1, elapsed) / cycle) * cycle - elapsed)));
    if (generation !== loaderGeneration) return;
    loaderDepth = Math.max(0, loaderDepth - 1);
    if (error) {
      loader.classList.add('failed'); loader.querySelector('[role="status"]').textContent = 'بارگذاری انجام نشد';
      loader.querySelector('.ref-loader-message').textContent = error.message || 'اتصال را بررسی کنید و دوباره تلاش کنید.';
      loader.querySelector('[data-loader-close]').focus();
    } else if (!loaderDepth && !loader.classList.contains('failed')) loader.close();
  };
  const withLoading = async work => {
    const generation = beginLoading();
    try { const result = await work(); await finishLoading(generation); return result; }
    catch (error) { await finishLoading(generation, error.name === 'AbortError' ? null : error); throw error; }
  };
  const dismissLoader = () => { loaderGeneration++; loaderDepth = 0; loader.close(); };
  loader.querySelector('[data-loader-close]').addEventListener('click', dismissLoader);
  loader.querySelector('[data-loader-reload]').addEventListener('click', () => location.reload());
  loader.addEventListener('cancel', event => { event.preventDefault(); if (loader.classList.contains('failed')) dismissLoader(); });
  // Fetch begins immediately; even cached data waits for one complete outline.
  const initialGeneration = beginLoading();
  const pageReady = document.readyState === 'complete' ? Promise.resolve() : new Promise(resolve => window.addEventListener('load', resolve, {once:true}));
  let bootTimer;
  const bootTimeout = new Promise((_, reject) => { bootTimer = setTimeout(() => reject(new Error('آماده‌سازی صفحه طول کشید. صفحه را تازه‌سازی کنید.')), 30000); });
  Promise.race([Promise.all([pageReady, document.fonts?.ready || Promise.resolve()]), bootTimeout]).then(() => {
    const errorText = document.querySelector('.login-hint[role="alert"], .placeholder[role="alert"]')?.textContent.trim();
    return finishLoading(initialGeneration, errorText ? new Error(errorText) : null);
  }, error => finishLoading(initialGeneration, error)).finally(() => clearTimeout(bootTimer));
  const pageFailed = () => { const generation = beginLoading(); void finishLoading(generation, new Error('آماده‌سازی صفحه با خطا روبه‌رو شد. صفحه را تازه‌سازی کنید.')); };
  window.addEventListener('error', event => { if (event instanceof ErrorEvent) pageFailed(); });
  window.addEventListener('unhandledrejection', pageFailed);
  document.querySelectorAll('form[method="post"]').forEach(form => form.addEventListener('submit', async event => {
    if (event.defaultPrevented) return;
    event.preventDefault();
    const generation = beginLoading();
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 30000);
    try {
      const response = await fetch(form.action || location.href, {method:'POST', credentials:'same-origin', body:new URLSearchParams(new FormData(form)), signal:controller.signal});
      const html = await response.text();
      const message = new DOMParser().parseFromString(html, 'text/html').querySelector('.login-hint[role="alert"]')?.textContent.trim();
      if (!response.ok || message || !response.redirected) throw new Error(message || 'ورود یا خروج انجام نشد. صفحه را تازه‌سازی کنید.');
      const elapsed = performance.now() - loaderStarted, cycle = loaderCycle();
      await new Promise(resolve => setTimeout(resolve, Math.max(0, Math.ceil(Math.max(1, elapsed) / cycle) * cycle - elapsed)));
      location.replace(response.url);
    } catch (error) {
      await finishLoading(generation, new Error(error.name === 'AbortError' ? 'پاسخی از سرور دریافت نشد. اتصال را بررسی کنید.' : error.name === 'TypeError' ? 'ارتباط با سرور برقرار نشد. اتصال را بررسی کنید.' : error.message || 'ارتباط با سرور برقرار نشد.'));
    } finally { clearTimeout(timeout); }
  }));
  const password = document.getElementById('ref-password');
  document.getElementById('ref-password-toggle')?.addEventListener('click', () => { password.type = password.type === 'password' ? 'text' : 'password'; });
  const back = document.getElementById('ref-back');
  if (!back) return;
  const logoutForm = document.getElementById('ref-logout-form');
  const games = <?= json_encode($games, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const currentPeriod = <?= json_encode($activePeriod, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const csrf = <?= json_encode($_SESSION['ref_monitor_csrf'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const views = [...document.querySelectorAll('[data-ref-view]')];
  const gameTitle = document.getElementById('ref-game-title');
  const renderRoadmap = (heading, gameName, currentPage) => {
    const parent = document.createElement('bdi'); parent.className = 'roadmap-parent'; parent.textContent = gameName;
    const separator = document.createElement('span'); separator.className = 'roadmap-separator'; separator.textContent = '‹'; separator.setAttribute('aria-hidden', 'true');
    const current = document.createElement('bdi'); current.className = 'roadmap-current'; current.textContent = currentPage;
    heading.replaceChildren(parent, separator, current);
    heading.setAttribute('aria-label', `${gameName}، ${currentPage}`);
  };
  const teamsList = document.getElementById('ref-teams-list');
  const selectedList = document.getElementById('ref-selected-list');
  const resultList = document.getElementById('ref-search-results');
  const selected = new Map();
  let activeGame = null;
  const activePeriod = currentPeriod?.code || '';
  let activeTeam = null;
  let teamsCache = [];
  let pendingScore = null;
  let scoreSaving = false;
  let activeScoreLevelId = null;
  let editingTeamId = null;
  let results = [];
  let memberDeadline = 0;
  let teamStep = 'name';
  let searchTimer = 0;
  let searchController = null;
  let searchSequence = 0;
  const setStatus = (id, message, kind = '') => {
    const node = document.getElementById(id);
    node.textContent = message;
    node.className = 'status-text' + (kind ? ' ' + kind : '');
  };
  const request = async (action, fields = {}, options = {}) => {
    const work = async () => {
    const body = new URLSearchParams({action, csrf, period_code: activePeriod, game_id: activeGame?.id || ''});
    for (const [key, value] of Object.entries(fields)) {
      if (Array.isArray(value)) value.forEach(item => body.append(key + '[]', String(item)));
      else body.set(key, String(value));
    }
    const controller = new AbortController();
    const abort = () => controller.abort(options.signal?.reason);
    if (options.signal?.aborted) abort(); else options.signal?.addEventListener('abort', abort, {once:true});
    let timedOut = false;
    const timeout = setTimeout(() => { timedOut = true; controller.abort(); }, 30000);
    try {
    const response = await fetch(location.pathname, {method:'POST', credentials:'same-origin', body, signal:controller.signal});
    let data;
    try {
      data = await response.json();
    } catch {
      throw new Error(`پاسخ سرور معتبر نیست (HTTP ${response.status}). صفحه را تازه‌سازی کنید و دوباره تلاش کنید.`);
    }
    if (!response.ok || data.status !== 'ok') throw new Error(data.message || 'درخواست انجام نشد.');
    return data;
    } catch (error) {
      if (timedOut) throw new Error('دریافت اطلاعات طول کشید. اتصال را بررسی کنید و دوباره تلاش کنید.');
      if (error.name === 'TypeError') throw new Error('ارتباط با سرور برقرار نشد. اتصال را بررسی کنید و دوباره تلاش کنید.');
      throw error;
    } finally { clearTimeout(timeout); options.signal?.removeEventListener('abort', abort); }
    };
    return options.silent ? work() : withLoading(work);
  };
  const addPrizeHint = (item, details, person) => {
    if (!person.prize_warning) return;
    item.classList.add(person.prize_warning_tone === 'past' ? 'prize-past' : 'prize-walk-in');
    const hint = document.createElement('span'); hint.className = 'prize-warning'; hint.textContent = person.prize_warning; details.append(hint);
  };
  const personItem = (person, action, label) => {
    const item = document.createElement('li');
    item.className = 'person-card';
    const details = document.createElement('span');
    const name = document.createElement('strong');
    name.textContent = person.name || 'بدون نام';
    const codes = document.createElement('small');
    codes.textContent = [person.work_id ? `کد پرسنلی ${person.work_id}` : '', activeGame?.gender_mode === 'separated' ? (person.gender === 'male' ? 'مرد' : person.gender === 'female' ? 'زن' : 'جنسیت نامشخص') : ''].filter(Boolean).join(' · ');
    details.append(name, codes);
    addPrizeHint(item, details, person);
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'secondary-action';
    button.textContent = label;
    button.addEventListener('click', action);
    item.append(details, button);
    return item;
  };
  const coverColors = <?= json_encode(EGM_REF_COVER_COLORS, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const coverInput = document.getElementById('ref-team-cover-color');
  const colorDialog = document.createElement('dialog'); colorDialog.className = 'cover-color-dialog'; colorDialog.id = 'ref-color-picker';
  colorDialog.setAttribute('aria-labelledby', 'ref-color-picker-title');
  colorDialog.innerHTML = '<h2 id="ref-color-picker-title">رنگ پوشش تیم</h2><div class="cover-color-options" role="listbox" aria-label="رنگ پوشش"></div><button type="button" class="secondary-action">انصراف</button>';
  document.body.append(colorDialog);
  let colorTarget = null;
  const colorButtons = new Map();
  const colorHex = {'سفید':'#fff','مشکی':'#222','قرمز':'#d83a45','آبی':'#1976d2','سرمه‌ای':'#243c64','سبز':'#44965d','زرد':'#eed747','نارنجی':'#ed8739','بنفش':'#875bb5','صورتی':'#e78cae','خاکستری':'#9298a0','طوسی':'#9298a0','قهوه‌ای':'#8b6651','زرشکی':'#8f3148','فیروزه‌ای':'#43b6bf','آبی روشن':'#86c9ed','سبز روشن':'#a4c973','کرم':'#ecdfbd','طلایی':'#c9a94b','نقره‌ای':'#c8ced5'};
  const paintColorButton = select => { const button = colorButtons.get(select); if (button) button.textContent = select.value || 'انتخاب رنگ'; };
  const closeColorDialog = () => colorDialog.close();
  colorDialog.querySelector('.secondary-action').addEventListener('click', closeColorDialog);
  colorDialog.addEventListener('close', () => { colorButtons.get(colorTarget)?.setAttribute('aria-expanded', 'false'); });
  const prepareColorPicker = select => {
    if (colorButtons.has(select)) { paintColorButton(select); return; }
    select.classList.add('cover-native'); select.tabIndex = -1;
    const trigger = document.createElement('button'); trigger.type = 'button'; trigger.className = 'login-input cover-trigger';
    trigger.setAttribute('aria-haspopup', 'dialog'); trigger.setAttribute('aria-expanded', 'false'); trigger.setAttribute('aria-controls', 'ref-color-picker');
    trigger.setAttribute('aria-label', 'انتخاب رنگ پوشش تیم');
    select.after(trigger); colorButtons.set(select, trigger); paintColorButton(select);
    select.addEventListener('change', () => paintColorButton(select));
    select.addEventListener('invalid', event => { event.preventDefault(); trigger.focus(); trigger.setAttribute('aria-invalid', 'true'); });
    trigger.addEventListener('click', () => {
      colorTarget = select; const list = colorDialog.querySelector('.cover-color-options'); list.replaceChildren();
      for (const option of [...select.options].filter(option => option.value)) {
        const button = document.createElement('button'); button.type = 'button'; button.setAttribute('role','option'); button.setAttribute('aria-selected', String(option.value === select.value));
        const dot = document.createElement('span'); dot.className = 'cover-color-dot'; dot.style.backgroundColor = colorHex[option.value] || '#e8edf2'; dot.setAttribute('aria-hidden','true');
        const name = document.createElement('span'); name.textContent = option.textContent; button.append(dot,name);
        button.addEventListener('click', () => { select.value = option.value; select.dispatchEvent(new Event('change', {bubbles:true})); trigger.removeAttribute('aria-invalid'); closeColorDialog(); });
        list.append(button);
      }
      trigger.setAttribute('aria-expanded','true'); colorDialog.showModal();
      const current = list.querySelector('[aria-selected=true]') || list.firstElementChild; current?.focus(); current?.scrollIntoView({block:'nearest'});
    });
  };
  colorDialog.addEventListener('keydown', event => {
    if (!['ArrowDown','ArrowUp','Home','End'].includes(event.key)) return;
    const options = [...colorDialog.querySelectorAll('[role=option]')]; if (!options.length) return; event.preventDefault();
    const index = options.indexOf(document.activeElement);
    options[event.key === 'Home' ? 0 : event.key === 'End' ? options.length - 1 : (index + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length].focus();
  });
  const fillCoverSelect = (select, value = '') => {
    select.replaceChildren(new Option('انتخاب رنگ', ''));
    for (const color of coverColors) select.append(new Option(color, color));
    if (value && !coverColors.includes(value)) select.append(new Option(`${value} (ثبت قبلی)`, value));
    select.value = value;
    prepareColorPicker(select);
  };
  fillCoverSelect(coverInput);
  const syncCoverChoices = () => { coverInput.setCustomValidity(coverInput.required && !coverInput.value ? 'رنگ پوشش تیم را انتخاب کنید.' : ''); paintColorButton(coverInput); };
  coverInput.addEventListener('change', syncCoverChoices);
  const editDialog = document.getElementById('ref-team-edit');
  let editSaving = false;
  document.getElementById('ref-edit-name').addEventListener('click', () => {
    if (!activeTeam || activeTeam.ended_at) return;
    document.getElementById('ref-detail-name').value = activeTeam.name;
    const colorSelect = document.getElementById('ref-detail-cover-input');
    fillCoverSelect(colorSelect, activeTeam.cover_color || '');
    colorSelect.required = !!activeGame.require_cover_color;
    document.getElementById('ref-edit-color-field').classList.toggle('hidden', !activeGame.require_cover_color && !activeTeam.cover_color);
    updateMemberTimer(); setStatus('ref-edit-status', ''); editDialog.showModal();
  });
  editDialog.addEventListener('cancel', event => { if (editSaving) event.preventDefault(); });
  document.getElementById('ref-edit-cancel').addEventListener('click', () => { if (!editSaving) editDialog.close(); });
  document.getElementById('ref-team-edit-form').addEventListener('submit', async event => {
    event.preventDefault();
    if (!activeTeam || activeTeam.ended_at || editSaving || !event.target.reportValidity()) return;
    editSaving = true;
    const controls = [...event.target.querySelectorAll('button')]; controls.forEach(button => button.disabled = true);
    try {
      const team = (await request('update_team_details', {team_id:activeTeam.id, name:document.getElementById('ref-detail-name').value.trim(), cover_color:document.getElementById('ref-detail-cover-input').value})).team;
      activeTeam = team; editDialog.close(); renderTeamDetail();
    } catch (error) { setStatus('ref-edit-status', error.message, 'error'); }
    finally { editSaving = false; controls.forEach(button => button.disabled = false); }
  });
  const renderSelected = () => {
    selectedList.replaceChildren();
    for (const person of selected.values()) selectedList.append(personItem(person, () => { selected.delete(person.id); renderSelected(); renderResults(); }, 'حذف'));
    document.getElementById('ref-member-count').textContent = `(${selected.size})`;
    document.getElementById('ref-selected-card').classList.toggle('hidden', selected.size === 0);
    document.getElementById('ref-team-next-members').disabled = selected.size === 0 || selected.size > (activeGame?.max_players || 20);
  };
  const renderReview = () => {
    const submitButton = document.getElementById('ref-submit-team');
    submitButton.disabled = false;
    document.getElementById('ref-review-name').textContent = editingTeamId === null ? document.getElementById('ref-team-name').value.trim() : activeTeam?.name || 'تیم';
    document.getElementById('ref-review-count').textContent = `${selected.size} عضو`;
    const cover = document.getElementById('ref-review-cover');
    const color = editingTeamId === null ? document.getElementById('ref-team-cover-color').value.trim() : activeTeam?.cover_color || '';
    cover.textContent = `رنگ پوشش: ${color}`; cover.classList.toggle('hidden', !color);
    const list = document.getElementById('ref-review-members');
    list.replaceChildren();
    for (const person of selected.values()) {
      const item = document.createElement('li'); item.textContent = person.name || person.work_id || 'بدون نام'; addPrizeHint(item, item, person); list.append(item);
    }
    submitButton.textContent = editingTeamId === null ? 'ثبت تیم' : 'ذخیره اعضا';
  };
  const renderResults = () => {
    resultList.replaceChildren();
    for (const person of results) {
      if (!selected.has(person.id)) resultList.append(personItem(person, () => {
        if (selected.size >= (activeGame?.max_players || 20)) { setStatus('ref-search-status', `هر تیم در این بازی حداکثر ${activeGame?.max_players || 20} عضو دارد.`, 'error'); return; }
        if (activeGame?.gender_mode === 'separated') {
          if (!person.gender) { setStatus('ref-search-status', 'جنسیت این فرد مشخص نیست و نمی‌توان او را به تیم افزود.', 'error'); return; }
          if ([...selected.values()].some(member => member.gender && member.gender !== person.gender)) {
            setStatus('ref-search-status', 'اعضای این تیم باید همگی مرد یا همگی زن باشند.', 'error'); return;
          }
        }
        selected.set(person.id, person); renderSelected(); renderResults();
      }, 'افزودن'));
    }
  };
  const resetSearch = () => {
    window.clearTimeout(searchTimer);
    searchController?.abort();
    searchSequence += 1;
    document.getElementById('ref-search-input').value = '';
    results = []; renderResults();
    setStatus('ref-search-status', '');
  };
  const searchInvitees = async query => {
    searchController?.abort();
    searchController = new AbortController();
    const sequence = ++searchSequence;
    setStatus('ref-search-status', 'در حال جستجو…');
    try {
      const data = await request('search_invitees', {query}, {signal:searchController.signal, silent:true});
      if (sequence !== searchSequence) return;
      results = data.invitees; renderResults();
      setStatus('ref-search-status', results.length ? '' : 'موردی پیدا نشد؛ مهمانان ثبت‌شدهٔ بازهٔ فعال را جستجو کنید.');
    } catch (error) {
      if (error.name === 'AbortError' || sequence !== searchSequence) return;
      results = []; renderResults(); setStatus('ref-search-status', error.message, 'error');
    }
  };
  const teamState = team => team.ended_at ? 'completed' : team.waiting_for_room ? 'waiting' : team.started_at ? 'playing' : 'ready';
  const renderTeams = () => {
    teamsList.replaceChildren();
    setStatus('ref-teams-status', teamsCache.length ? '' : 'هنوز تیمی ثبت نشده است.');
    for (const [key, label] of [['ready','آماده شروع'], ['waiting','در انتظار اتاق'], ['playing','در حال بازی'], ['completed','پایان‌یافته']]) {
      const teams = teamsCache.filter(team => teamState(team) === key);
      if (!teams.length) continue;
      const group = document.createElement('li'); group.className = 'team-group';
      const heading = document.createElement('h3'); heading.className = 'team-group-title'; heading.textContent = label;
      const list = document.createElement('ul'); list.className = 'team-list';
      for (const team of teams) {
        const item = document.createElement('li');
        const button = document.createElement('button'); button.type = 'button'; button.className = 'team-card';
        const title = document.createElement('strong'); title.textContent = team.name;
        const members = document.createElement('small'); members.textContent = `${team.members.length} عضو`;
        const state = document.createElement('small'); state.className = `team-state ${key}`; state.textContent = label;
        button.append(title, members, state);
        if (team.cover_color) { const color = document.createElement('span'); color.className = 'cover-badge'; color.textContent = `پوشش: ${team.cover_color}`; button.append(color); }
        if (activeGame.auto_room_manager && team.room_assignment && !team.ended_at) {
          const room = document.createElement('span'); room.className = 'team-room-name'; room.textContent = team.room_assignment.room_name; button.append(room);
        }
        button.addEventListener('click', () => { activeTeam = team; open('team-detail', activeGame.id, activePeriod, team.id); });
        item.append(button); list.append(item);
      }
      group.append(heading, list); teamsList.append(group);
    }
  };
  const loadTeams = async (silent = false) => {
    if (!activePeriod) { setStatus('ref-teams-status', 'در حال حاضر بازهٔ فعالی وجود ندارد.'); return; }
    const gameId = activeGame?.id;
    if (!silent) setStatus('ref-teams-status', 'در حال بارگذاری تیم‌ها…');
    try {
      const data = await request('list_teams', {}, {silent});
      if (gameId !== activeGame?.id) return;
      teamsCache = data.teams; renderTeams();
    } catch (error) { setStatus('ref-teams-status', error.message, 'error'); }
  };
  const updateMemberTimer = () => {
    if (!activeTeam) return;
    const left = activeTeam.started_at ? Math.max(0, Math.ceil((memberDeadline - Date.now()) / 1000)) : null;
    const canEdit = activeTeam.can_edit_members && (left === null || left > 0);
    document.getElementById('ref-edit-members').classList.toggle('hidden', !canEdit);
    setStatus('ref-member-hint', !canEdit || left === null ? ''
      : `ویرایش اعضا: ${Math.floor(left / 60).toString().padStart(2, '0')}:${(left % 60).toString().padStart(2, '0')}`);
  };
  const scoreSteps = game => game.has_levels ? (game.levels || []) : [{id:'game_total', name:game.no_score_needed ? 'بازی' : 'امتیاز بازی'}];
  const renderRoomGuidance = (card, assignment) => {
    const key = assignment ? `${assignment.level_id}:${assignment.room_id || assignment.room_name}` : '';
    const changed = assignment && card.dataset.assignment !== key;
    card.dataset.assignment = key;
    card.classList.toggle('room-arrived', !!changed);
    card.replaceChildren();
    card.classList.toggle('waiting', !assignment);
    const instruction = document.createElement('p'); instruction.className = 'room-instruction';
    instruction.textContent = assignment ? 'مقصد تیم' : 'تخصیص اتاق';
    const name = document.createElement('h2'); name.className = 'room-name';
    name.textContent = assignment ? assignment.room_name : 'در انتظار اتاق';
    const stage = document.createElement('p'); stage.className = 'room-stage';
    stage.textContent = assignment ? assignment.level_name : 'به‌صورت خودکار مشخص می‌شود';
    card.append(instruction, name, stage);
  };
  const renderTeamDetail = () => {
    if (!activeTeam || !activeGame) return;
    queueMicrotask(syncRoomWatch);
    renderRoadmap(document.getElementById('ref-detail-title'), activeGame.name, activeTeam.name);
    setStatus('ref-detail-state', activeTeam.ended_at ? 'پایان‌یافته' : activeTeam.started_at ? 'در جریان' : 'آماده شروع', activeTeam.ended_at ? 'success' : '');
    const color = activeTeam.cover_color || '';
    document.getElementById('ref-cover-detail').classList.toggle('hidden', !activeGame.require_cover_color && !color);
    document.getElementById('ref-detail-cover').textContent = color ? `رنگ پوشش: ${color}` : 'رنگ پوشش ثبت نشده است';



    const roomCard = document.getElementById('ref-room-card');
    roomCard.classList.toggle('hidden', !activeGame.auto_room_manager || !activeTeam.started_at || !!activeTeam.ended_at);
    if (activeGame.auto_room_manager && activeTeam.started_at && !activeTeam.ended_at) {
      renderRoomGuidance(roomCard, activeTeam.room_assignment);
    }
    document.getElementById('ref-completion-card').classList.toggle('hidden', !activeTeam.ended_at);
    document.getElementById('ref-completion-score').textContent = `امتیاز نهایی: ${activeTeam.total_score}`;
    document.getElementById('ref-completion-score').classList.toggle('hidden', !!activeGame.no_score_needed);
    const startButton = document.getElementById('ref-start-team');
    startButton.classList.toggle('hidden', !!activeTeam.started_at || !!activeTeam.ended_at);
    const scoreButton = document.getElementById('ref-open-scores');
    scoreButton.textContent = activeGame.has_levels ? 'ثبت امتیاز و مرحله بعد' : activeGame.auto_room_manager ? 'پایان بازی و ثبت امتیاز' : 'ثبت امتیاز';
    if (activeGame.no_score_needed) scoreButton.textContent = activeGame.has_levels ? 'پایان مرحله' : 'پایان بازی';
    const hasPendingScores = scoreSteps(activeGame).some(level => !activeTeam.scores?.[level.id]);
    scoreButton.classList.toggle('hidden', !activeTeam.started_at || !!activeTeam.ended_at || !hasPendingScores || (activeGame.auto_room_manager && !activeTeam.room_assignment));
    const homeButton = document.getElementById('ref-detail-home');
    homeButton.classList.add('hidden');
    const teamsButton = document.getElementById('ref-completion-back');
    teamsButton.classList.toggle('hidden', !activeTeam.ended_at);
    document.getElementById('ref-detail-dock').classList.toggle('hidden', startButton.classList.contains('hidden') && scoreButton.classList.contains('hidden') && homeButton.classList.contains('hidden') && teamsButton.classList.contains('hidden'));
    const count = activeTeam.members.length;
    const withinLimits = count >= activeGame.min_players && count <= activeGame.max_players;
    const missingColor = activeGame.require_cover_color && !color;
    startButton.disabled = !withinLimits || missingColor;
    setStatus('ref-start-hint', activeTeam.started_at || activeTeam.ended_at ? ''
      : missingColor ? 'برای شروع بازی، ابتدا رنگ پوشش تیم را ثبت کنید.'
      : withinLimits ? ''
      : `برای شروع بازی، تیم باید بین ${activeGame.min_players} تا ${activeGame.max_players} عضو داشته باشد.`);

    document.getElementById('ref-edit-name').classList.toggle('hidden', !!activeTeam.ended_at);
    if (!editDialog.open) document.getElementById('ref-detail-name').value = activeTeam.name;
    const membersList = document.getElementById('ref-detail-members');
    document.getElementById('ref-detail-members-title').textContent = `اعضای تیم · ${activeTeam.members.length} نفر`;
    membersList.replaceChildren();
    for (const member of activeTeam.members) {
      const item = document.createElement('li');
      const info = document.createElement('span'); info.className = 'member-info';
      const name = document.createElement('span'); name.className = 'member-name';
      name.textContent = member.name || member.work_id || 'بدون نام'; info.append(name);
      if (member.work_id) {
        const code = document.createElement('span'); code.className = 'member-code';
        code.textContent = `کد پرسنلی: ${member.work_id}`; info.append(code);
      }
      item.append(info);
      addPrizeHint(item, info, member);
      membersList.append(item);
    }
    memberDeadline = activeTeam.started_at ? Date.now() + (activeTeam.member_edit_seconds_left || 0) * 1000 : 0;
    updateMemberTimer();
    const totalScore = document.getElementById('ref-total-score');
    totalScore.textContent = `امتیاز کل: ${activeTeam.total_score}`;
    totalScore.classList.toggle('hidden', !!activeGame.no_score_needed || !activeGame.has_levels || !activeTeam.started_at || !Object.keys(activeTeam.scores || {}).length);
  };
  const renderScoreLevels = () => {
    if (!activeTeam || !activeGame) return;
    const levels = scoreSteps(activeGame);
    const list = document.getElementById('ref-score-levels');
    list.replaceChildren();
    setStatus('ref-levels-team', `${activeTeam.name} · ${activeGame.name}`);
    if (activeGame.auto_room_manager) {
      setStatus('ref-levels-status', activeTeam.room_assignment ? `${activeTeam.room_assignment.level_name} · اتاق ${activeTeam.room_assignment.room_name}` : 'در انتظار اتاق');
      if (activeTeam.room_assignment) {
        const item = document.createElement('li');
        const title = document.createElement('span'); title.textContent = `${activeTeam.room_assignment.level_name} · ${activeTeam.room_assignment.room_name}`;
        const button = document.createElement('button'); button.type = 'button'; button.className = 'inline-action'; button.textContent = activeGame.has_levels ? 'ثبت امتیاز و مرحله بعد' : 'ثبت امتیاز';
        button.addEventListener('click', () => open('score-entry', activeGame.id, activePeriod, activeTeam.id, activeTeam.room_assignment.level_id));
        item.append(title, button); list.append(item);
      }
      return;
    }
    for (const [index, level] of levels.entries()) {
      const item = document.createElement('li');
      const title = document.createElement('span');
      title.textContent = activeGame.has_levels ? `${index + 1}. ${level.name}` : level.name;
      const score = activeTeam.scores?.[level.id];
      if (score) {
        const value = document.createElement('span'); value.textContent = score.completion_only ? 'پایان‌یافته' : `${score.score} امتیاز`;
        item.append(title, value);
      } else if (activeTeam.ended_at) {
        const value = document.createElement('span'); value.textContent = 'ثبت نشده'; item.append(title, value);
      } else {
        const button = document.createElement('button'); button.type = 'button'; button.className = 'inline-action';
        button.textContent = activeGame.has_levels ? 'ثبت امتیاز و مرحله بعد' : 'ثبت امتیاز';
        button.addEventListener('click', () => open('score-entry', activeGame.id, activePeriod, activeTeam.id, level.id));
        item.append(title, button);
      }
      list.append(item);
    }
    if (!levels.length) setStatus('ref-levels-status', 'هنوز مرحله‌ای برای این بازی تعریف نشده است.');
    else setStatus('ref-levels-status', '');
  };
  const renderScoreStep = () => {
    if (!activeTeam || !activeGame) return;
    const levels = scoreSteps(activeGame);
    const nextLevel = activeGame.auto_room_manager
      ? levels.find(level => level.id === activeTeam.room_assignment?.level_id && !activeTeam.scores?.[level.id])
      : levels.find(level => level.id === activeScoreLevelId && !activeTeam.scores?.[level.id]) || levels.find(level => !activeTeam.scores?.[level.id]);
    const stage = document.getElementById('ref-score-stage');
    const scoreDock = document.getElementById('ref-score-dock');
    const homeDock = document.getElementById('ref-score-home-dock');
    document.getElementById('ref-score-submit').disabled = false;
    document.getElementById('ref-score-submit').textContent = 'ثبت امتیاز';
    if (activeGame.auto_room_manager) document.getElementById('ref-score-submit').textContent = 'ثبت مجموع امتیاز دورها';
    if (activeGame.has_levels) document.getElementById('ref-score-submit').textContent = 'ثبت امتیاز و مرحله بعد';
    stage.replaceChildren(); scoreDock.classList.add('hidden'); homeDock.classList.add('hidden');
    setStatus('ref-score-team', `${activeTeam.name}${activeGame.auto_room_manager ? '' : ` · ${activeGame.name}`}${activeTeam.cover_color ? ` · پوشش ${activeTeam.cover_color}` : ''}`);
    if (!levels.length) {
      document.getElementById('ref-score-title').textContent = 'مرحله‌ای تعریف نشده است';
      const message = document.createElement('p'); message.className = 'status-text'; message.textContent = 'مدیر EGM باید ابتدا مراحل این بازی را تعریف کند.'; stage.append(message);
      return;
    }
    if (activeTeam.ended_at || (!nextLevel && !activeGame.auto_room_manager)) {
      document.getElementById('ref-score-title').textContent = 'بازی پایان یافت';
      const message = document.createElement('p'); message.className = 'status-text success'; message.textContent = activeGame.no_score_needed ? 'همهٔ مراحل پایان یافت.' : `امتیاز نهایی: ${activeTeam.total_score}`; stage.append(message);
      homeDock.classList.remove('hidden');
      return;
    }
    if (activeGame.auto_room_manager && !nextLevel) {
      document.getElementById('ref-score-title').textContent = 'در انتظار اتاق';
      const message = document.createElement('p'); message.className = 'status-text'; message.textContent = 'پس از آزاد شدن اتاق، مرحلهٔ بعد نمایش داده می‌شود.'; stage.append(message);
      return;
    }
    document.getElementById('ref-score-title').textContent = activeGame.auto_room_manager ? activeTeam.room_assignment.room_name : nextLevel.name;
    if (activeGame.no_score_needed) {
      const form = document.createElement('form'); form.id = 'ref-score-form'; form.dataset.levelId = nextLevel.id;
      const text = document.createElement('p'); text.className = 'status-text'; text.textContent = 'پس از پایان بازی در این مرحله، پایان را تأیید کنید.';
      form.append(text); stage.append(form); scoreDock.classList.remove('hidden');
      document.getElementById('ref-score-submit').textContent = activeGame.has_levels ? 'پایان مرحله' : 'پایان بازی';
      return;
    }
    const intro = document.createElement('p'); intro.className = 'status-text'; intro.textContent = activeGame.auto_room_manager ? 'بازی تمام شد؟ مجموع امتیاز دورها را وارد کنید.' : activeGame.has_levels ? 'امتیاز نهایی این مرحله را وارد کنید.' : 'امتیاز نهایی بازی را وارد کنید.';
    const form = document.createElement('form'); form.className = 'score-form'; form.id = 'ref-score-form'; form.dataset.levelId = nextLevel.id;
    const input = document.createElement('input'); input.className = 'login-input score-number'; input.type = 'number'; input.min = '0'; input.max = '999999.99'; input.step = '0.01'; input.required = true; input.placeholder = 'امتیاز'; input.setAttribute('aria-label', `امتیاز ${nextLevel.name}`);
    if (activeGame.auto_room_manager) {
      input.id = 'ref-rounds-total'; input.placeholder = '۰';
      input.setAttribute('aria-label', 'مجموع امتیاز تمام دورهای این اتاق');
      const label = document.createElement('label'); label.className = 'login-field'; label.htmlFor = input.id;
      const title = document.createElement('span'); title.textContent = 'مجموع امتیاز تمام دورها'; label.append(title, input);
      const help = document.createElement('p'); help.className = 'score-help'; help.id = 'ref-rounds-help';
      help.textContent = 'امتیاز دورها را با هم جمع کنید و عدد نهایی را وارد کنید؛ مثلاً ۱۰ + ۱۵ + ۲۰ = ۴۵.';
      const calculation = document.createElement('details'); calculation.className = 'score-calculation';
      const summary = document.createElement('summary'); summary.textContent = 'روش محاسبهٔ مجموع امتیاز'; calculation.append(summary, help);
      input.setAttribute('aria-describedby', help.id); form.append(label, calculation);
    } else form.append(input);
    stage.append(intro, form); scoreDock.classList.remove('hidden');
  };
  const loadTeamDetail = async teamId => {
    const scoreListView = views.some(view => view.dataset.refView === 'score' && !view.classList.contains('hidden'));
    const scoreEntryView = views.some(view => view.dataset.refView === 'score-entry' && !view.classList.contains('hidden'));
    const statusId = scoreEntryView ? 'ref-score-step-status' : scoreListView ? 'ref-levels-status' : 'ref-score-status';
    setStatus(statusId, 'در حال بارگذاری تیم…');
    try {
      activeTeam = (await request('team_detail', {team_id:teamId})).team;
      renderTeamDetail();
      if (scoreListView) renderScoreLevels();
      if (scoreEntryView) renderScoreStep();
      setStatus(statusId, '');
    } catch (error) { setStatus(statusId, error.message, 'error'); }
  };
  const show = (name, state = {}) => {
    const roomNotice=document.getElementById('ref-room-arrival');if(roomNotice?.open)roomNotice.close();
    queueMicrotask(syncRoomWatch);
    if (name === 'home' || name === 'team') void withLoading(async () => {});
    if (confirmation?.open && !scoreSaving) confirmation.close();
    if (editDialog.open && !editSaving) editDialog.close();
    const target = views.find(view => view.dataset.refView === name) || views[0];
    for (const view of views) view.classList.toggle('hidden', view !== target);
    const isHome = target === views[0];
    back.classList.toggle('hidden', isHome);
    logoutForm.classList.toggle('hidden', !isHome);
    if (name === 'game' || name === 'team' || name === 'team-detail' || name === 'score' || name === 'score-entry') {
      activeGame = games.find(game => game.id === state.gameId) || activeGame;
      if (!activeGame) return;
      if (name === 'game') {
        teamsCache = []; teamsList.replaceChildren();
        renderRoadmap(gameTitle, activeGame.name, 'تیم‌های شما');
        setStatus('ref-game-period', `بازهٔ فعال: ${currentPeriod?.title || activePeriod}`);
        loadTeams();
      } else if (name === 'team') {
        const coverInput = document.getElementById('ref-team-cover-color');
        document.getElementById('ref-cover-picker').classList.toggle('hidden', !activeGame.require_cover_color || !!state.teamId);
        coverInput.required = !!activeGame.require_cover_color && !state.teamId;
        syncCoverChoices();
        setStatus('ref-team-context', `${activeGame.name} · ${currentPeriod?.title || activePeriod}`);
        const editing = !!state.teamId;
        editingTeamId = editing ? Number(state.teamId) : null;
        document.getElementById('ref-team-form-title').textContent = editing ? 'ویرایش اعضای تیم' : 'تشکیل تیم';
        teamStep = state.step || (editing ? 'members' : 'name');
        for (const panel of document.querySelectorAll('[data-team-step]')) panel.classList.toggle('hidden', panel.dataset.teamStep !== teamStep);
        for (const dock of document.querySelectorAll('[data-team-action]')) dock.classList.toggle('hidden', dock.dataset.teamAction !== teamStep);
        const stepNumber = editing ? (teamStep === 'members' ? 1 : 2) : teamStep === 'name' ? 1 : teamStep === 'members' ? 2 : 3;
        const stepTotal = editing ? 2 : 3;
        document.getElementById('ref-step-fill').style.width = `${stepNumber / stepTotal * 100}%`;
        setStatus('ref-step-label', `${stepNumber} از ${stepTotal} · ${teamStep === 'name' ? 'نام تیم' : teamStep === 'members' ? 'اعضا' : 'بازبینی'}`);
        if (teamStep === 'review') renderReview();
      } else if (name === 'team-detail' || name === 'score' || name === 'score-entry') {

        setStatus('ref-journey-status', '');
        activeScoreLevelId = name === 'score-entry' ? (state.step || null) : null;
        if (name === 'score-entry') {
          document.getElementById('ref-score-stage').replaceChildren();
          document.getElementById('ref-score-dock').classList.add('hidden');
          document.getElementById('ref-score-home-dock').classList.add('hidden');
        }
        loadTeamDetail(state.teamId || activeTeam?.id);
      }
    }
  };
  history.replaceState({refMonitor:true,view:'home'}, '', location.href);
  const open = (view, gameId = activeGame?.id, periodCode = activePeriod, teamId = null, step = null) => {
    const state = {refMonitor:true, view, gameId, periodCode, teamId, step};
    history.pushState(state, '', location.href);
    show(view, state);
  };
  const replaceView = (view, gameId = activeGame?.id, periodCode = activePeriod, teamId = null, step = null) => {
    const state = {refMonitor:true, view, gameId, periodCode, teamId, step};
    history.replaceState(state, '', location.href);
    show(view, state);
  };
  window.RefMonitorNavigation = {open, back() {
    if (back.classList.contains('hidden') || scoreSaving || editSaving) return;
    const state = history.state || {};
    if (state.view === 'team') {
      const step = state.step || (state.teamId ? 'members' : 'name');
      if (step === 'review') { replaceView('team', activeGame.id, activePeriod, state.teamId, 'members'); return; }
      if (step === 'members' && !state.teamId) { replaceView('team', activeGame.id, activePeriod, null, 'name'); return; }
      if (state.teamId) { replaceView('team-detail', activeGame.id, activePeriod, state.teamId); return; }
      replaceView('game'); return;
    }
    if (state.view === 'score' || state.view === 'score-entry') { replaceView('team-detail', activeGame.id, activePeriod, state.teamId || activeTeam?.id); return; }
    if (state.view === 'team-detail') { replaceView('game'); return; }
    replaceView('home');
  }};
  document.querySelectorAll('[data-open-game]').forEach(button => button.addEventListener('click', () => open('game', button.dataset.openGame)));
  document.getElementById('ref-new-team').addEventListener('click', () => {
    if (!activePeriod) return;
    activeTeam = null;
    selected.clear(); renderSelected(); resetSearch();
    document.getElementById('ref-team-name').value = '';
    document.getElementById('ref-team-cover-color').value = '';
    syncCoverChoices();
    setStatus('ref-submit-status', '');
    open('team', activeGame.id, activePeriod, null, 'name');
  });
  document.getElementById('ref-team-next-name').addEventListener('click', () => {
    if (!document.getElementById('ref-team-name').reportValidity()) return;
    if (activeGame.require_cover_color && !document.getElementById('ref-team-cover-color').reportValidity()) return;
    open('team', activeGame.id, activePeriod, null, 'members');
  });
  document.getElementById('ref-team-next-members').addEventListener('click', () => {
    if (!selected.size || selected.size > activeGame.max_players) return;
    open('team', activeGame.id, activePeriod, editingTeamId, 'review');
  });
  document.getElementById('ref-edit-members').addEventListener('click', () => {
    if (!activeTeam?.can_edit_members || editSaving) return;
    editDialog.close();
    selected.clear(); resetSearch();
    for (const member of activeTeam.members) selected.set(member.id, member);
    renderSelected();
    setStatus('ref-submit-status', '');
    open('team', activeGame.id, activePeriod, activeTeam.id, 'members');
  });
  document.getElementById('ref-start-team').addEventListener('click', async event => {
    if (!activeTeam || activeTeam.started_at || activeTeam.ended_at) return;
    const button = event.currentTarget;
    button.disabled = true;
    setStatus('ref-start-hint', 'در حال شروع بازی…');
    try {
      activeTeam = (await request('start_team', {team_id:activeTeam.id})).team;
      announceRoom(activeTeam);
      renderTeamDetail();
      if (activeGame.auto_room_manager) {
        document.getElementById('ref-room-card').scrollIntoView({block:'start', behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'});
        setStatus('ref-journey-status', activeTeam.room_assignment ? 'بازی شروع شد.' : 'منتظر اتاق بمانید.', 'success');
      }
      setStatus('ref-start-hint', '');
    } catch (error) { setStatus('ref-start-hint', error.message, 'error'); button.disabled = false; }
  });
  document.getElementById('ref-open-scores').addEventListener('click', () => {
    if (!activeTeam?.started_at || activeTeam.ended_at) return;
    if (activeGame.no_score_needed) {
      const level = activeGame.auto_room_manager ? scoreSteps(activeGame).find(item => item.id === activeTeam.room_assignment?.level_id) : scoreSteps(activeGame).find(item => !activeTeam.scores?.[item.id]);
      if (level) confirmResult(level.id);
      return;
    }
    if (activeGame.auto_room_manager) {
      if (activeTeam.room_assignment) open('score-entry', activeGame.id, activePeriod, activeTeam.id, activeTeam.room_assignment.level_id);
      return;
    }
    const nextLevel = scoreSteps(activeGame).find(level => !activeTeam.scores?.[level.id]);
    if (nextLevel) open('score-entry', activeGame.id, activePeriod, activeTeam.id, nextLevel.id);
  });
  document.getElementById('ref-score-home').addEventListener('click', () => open('home'));
  document.getElementById('ref-detail-home').addEventListener('click', () => open('home'));
  document.getElementById('ref-completion-back').addEventListener('click', () => {
    history.replaceState({refMonitor:true, view:'game', gameId:activeGame.id, periodCode:activePeriod}, '', location.href);
    show('game', {gameId:activeGame.id});
  });
  const confirmation = document.getElementById('ref-score-confirm');
  confirmation.addEventListener('cancel', event => { if (scoreSaving) event.preventDefault(); });
  confirmation.addEventListener('close', () => { if (!scoreSaving) pendingScore = null; });
  const confirmResult = (levelId, score = null) => {
    const level = scoreSteps(activeGame).find(item => item.id === levelId);
    const completionOnly = !!activeGame.no_score_needed;
    pendingScore = {team_id:activeTeam.id, game_id:activeGame.id, level_id:levelId, action:completionOnly ? 'complete_level' : 'submit_score'};
    if (!completionOnly) pendingScore.score = score;
    document.getElementById('ref-confirm-title').textContent = completionOnly ? (activeGame.has_levels ? 'تأیید پایان مرحله' : 'تأیید پایان بازی') : 'تأیید امتیاز';
    document.getElementById('ref-confirm-team').textContent = `تیم: ${activeTeam.name}${activeTeam.cover_color ? ` · پوشش ${activeTeam.cover_color}` : ''}`;
    document.getElementById('ref-confirm-room').textContent = activeGame.auto_room_manager ? `اتاق: ${activeTeam.room_assignment?.room_name || 'تعیین نشده'} · ${level?.name || ''}` : `${activeGame.name} · ${level?.name || ''}`;
    document.getElementById('ref-confirm-score').textContent = completionOnly ? (activeGame.has_levels ? `پایان ${level?.name || 'مرحله'}` : 'پایان بازی') : `مجموع امتیاز: ${score}`;
    document.getElementById('ref-confirm-warning').textContent = completionOnly ? 'این مرحله به پایان رسیده است؟ پس از تأیید، بسته می‌شود.' : 'پس از ثبت، این امتیاز قابل تغییر نیست. نام تیم، اتاق و مجموع امتیاز را بررسی کنید.';
    document.getElementById('ref-confirm-save').textContent = completionOnly ? 'تأیید پایان' : 'تأیید و ثبت امتیاز';
    document.getElementById('ref-confirm-cancel').textContent = completionOnly ? 'ادامه بازی' : 'برگشت و اصلاح';
    setStatus('ref-confirm-status', ''); confirmation.showModal();
    document.getElementById('ref-confirm-cancel').focus();
  };
  document.getElementById('ref-score-stage').addEventListener('submit', event => {
    const form = event.target.closest('[data-level-id]');
    if (!form || !activeTeam || scoreSaving) return;
    event.preventDefault();
    if (!form.reportValidity()) return;
    confirmResult(form.dataset.levelId, form.querySelector('input')?.value ?? null);
  });
  document.getElementById('ref-confirm-save').addEventListener('click', async () => {
    if (!pendingScore || scoreSaving) return;
    if (pendingScore.team_id !== activeTeam?.id || pendingScore.game_id !== activeGame?.id) { confirmation.close(); return; }
    const {action, ...fields} = pendingScore;
    const completionOnly = action === 'complete_level';
    scoreSaving = true;
    const save = document.getElementById('ref-confirm-save');
    const cancel = document.getElementById('ref-confirm-cancel');
    save.disabled = true; cancel.disabled = true;
    setStatus('ref-confirm-status', completionOnly ? 'در حال ثبت پایان…' : 'در حال ثبت امتیاز…');
    try {
      activeTeam = (await request(action, fields)).team;
      announceRoom(activeTeam);
      scoreSaving = false; pendingScore = null; confirmation.close();
      renderTeamDetail();
      if (activeGame.auto_room_manager || activeTeam.ended_at || completionOnly) {
        const state = {refMonitor:true, view:'team-detail', gameId:activeGame.id, periodCode:activePeriod, teamId:activeTeam.id};
        history.replaceState(state, '', location.href);
        for (const view of views) view.classList.toggle('hidden', view.dataset.refView !== 'team-detail');
        setStatus('ref-journey-status', completionOnly ? (activeTeam.ended_at ? 'بازی پایان یافت.' : 'مرحله پایان یافت.') : activeTeam.ended_at ? 'امتیاز نهایی ثبت شد.' : activeTeam.room_assignment ? 'امتیاز ثبت شد؛ به اتاق بعدی بروید.' : 'امتیاز ثبت شد؛ منتظر اتاق بعدی بمانید.', 'success');
        document.getElementById(activeTeam.ended_at ? 'ref-completion-card' : activeGame.auto_room_manager ? 'ref-room-card' : 'ref-detail-title').scrollIntoView({block:'start', behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'});
      } else {
        const nextLevel = scoreSteps(activeGame).find(level => !activeTeam.scores?.[level.id]);
        activeScoreLevelId = nextLevel?.id || null;
        history.replaceState({refMonitor:true, view:'score-entry', gameId:activeGame.id, periodCode:activePeriod, teamId:activeTeam.id, step:activeScoreLevelId}, '', location.href);
        renderScoreStep();
        setStatus('ref-score-step-status', 'امتیاز ثبت شد؛ مرحلهٔ بعد را وارد کنید.', 'success');
        document.querySelector('#ref-score-form input')?.focus();
      }
    } catch (error) {
      scoreSaving = false;
      setStatus('ref-confirm-status', error.message, 'error');
    } finally { save.disabled = false; cancel.disabled = false; }
  });
  document.getElementById('ref-search-input').addEventListener('input', event => {
    if (event.isComposing) return;
    window.clearTimeout(searchTimer);
    searchController?.abort();
    searchSequence += 1;
    results = []; renderResults();
    const query = event.currentTarget.value.trim();
    if ([...query].length < 2) {
      setStatus('ref-search-status', query ? 'حداقل ۲ نویسه' : '');
      return;
    }
    setStatus('ref-search-status', 'در حال جستجو…');
    searchTimer = window.setTimeout(() => searchInvitees(query), 280);
  });
  document.getElementById('ref-search-input').addEventListener('compositionend', event => event.currentTarget.dispatchEvent(new Event('input', {bubbles:true})));
  document.getElementById('ref-submit-team').addEventListener('click', async event => {
    const button = event.currentTarget;
    if (!selected.size) return;
    const editing = editingTeamId !== null;
    const teamName = document.getElementById('ref-team-name');
    if (!editing && !teamName.reportValidity()) return;
    if (!editing && activeGame.require_cover_color && !document.getElementById('ref-team-cover-color').reportValidity()) return;
    button.disabled = true;
    setStatus('ref-submit-status', editing ? 'در حال ذخیرهٔ اعضا…' : 'در حال ثبت تیم…');
    try {
      const fields = {members:[...selected.keys()]};
      if (editing) fields.team_id = editingTeamId; else { fields.name = teamName.value.trim(); fields.cover_color = activeGame.require_cover_color ? document.getElementById('ref-team-cover-color').value.trim() : ''; }
      const data = await request(editing ? 'update_members' : 'create_team', fields);
      activeTeam = data.team;
      setStatus('ref-submit-status', editing ? 'اعضای تیم ذخیره شدند.' : 'تیم ثبت شد.', 'success');
      replaceView('team-detail', activeGame.id, activePeriod, data.team.id);
    } catch (error) { setStatus('ref-submit-status', error.message, 'error'); button.disabled = false; }
  });
  back.addEventListener('click', () => window.RefMonitorNavigation.back());
  window.addEventListener('popstate', event => {
    show(event.state?.refMonitor ? event.state.view : 'home', event.state || {});
  });
  const alertButton=document.getElementById('ref-room-alerts');
  const alertPreference='ref-room-alerts:'+location.pathname;
  const readPreference=()=>{try{return localStorage.getItem(alertPreference)==='1';}catch{return false;}};
  let alertsEnabled=readPreference(),audioContext=null,pushDevice='',watchKey='',watchQueue=Promise.resolve(),roomPolling=false;
  const alertedAssignments=new Set();
  const paintAlerts=()=>{alertButton.setAttribute('aria-pressed',String(alertsEnabled));alertButton.textContent=alertsEnabled?(pushDevice?'صدای اتاق و اعلان: روشن':'صدای اتاق: روشن'):'فعال‌کردن صدای اتاق و اعلان';};
  paintAlerts();
  const unlockAudio=async()=>{
    if(!alertsEnabled)return;const Audio=window.AudioContext||window.webkitAudioContext;if(!Audio)return;
    if(!audioContext)audioContext=new Audio();if(audioContext.state==='suspended')await audioContext.resume();
  };
  document.addEventListener('pointerdown',()=>{void unlockAudio().catch(()=>{});},{capture:true});
  const ding=()=>{
    if(!alertsEnabled||!audioContext||audioContext.state!=='running')return;
    [880,1320].forEach((frequency,index)=>{
      const oscillator=audioContext.createOscillator(),gain=audioContext.createGain();const start=audioContext.currentTime+index*.16;
      oscillator.type='sine';oscillator.frequency.value=frequency;gain.gain.setValueAtTime(.0001,start);gain.gain.exponentialRampToValueAtTime(.14,start+.015);gain.gain.exponentialRampToValueAtTime(.0001,start+.6);
      oscillator.connect(gain);gain.connect(audioContext.destination);oscillator.start(start);oscillator.stop(start+.65);
    });
  };
  const attentionNotes=new Set();
  const stopRoomAttention=()=>{for(const oscillator of attentionNotes){try{oscillator.stop();}catch{}}attentionNotes.clear();};
  const roomAttention=()=>{
    stopRoomAttention();
    if(!alertsEnabled||!audioContext||audioContext.state!=='running')return;
    for(let repeat=0;repeat<3;repeat++)[740,988,1320].forEach((frequency,index)=>{
      const oscillator=audioContext.createOscillator(),gain=audioContext.createGain(),start=audioContext.currentTime+repeat*2+index*.36;
      oscillator.type='sine';oscillator.frequency.value=frequency;gain.gain.setValueAtTime(.0001,start);gain.gain.exponentialRampToValueAtTime(.16,start+.025);gain.gain.exponentialRampToValueAtTime(.0001,start+.85);
      oscillator.connect(gain);gain.connect(audioContext.destination);attentionNotes.add(oscillator);oscillator.onended=()=>attentionNotes.delete(oscillator);oscillator.start(start);oscillator.stop(start+.9);
    });
    if(typeof navigator.vibrate==='function')navigator.vibrate([250,120,250,120,400]);
  };
  const roomNotice=document.createElement('dialog');roomNotice.id='ref-room-arrival';roomNotice.className='ref-room-arrival';roomNotice.setAttribute('aria-labelledby','ref-room-arrival-title');
  roomNotice.innerHTML='<h2 id="ref-room-arrival-title">اتاق تیم شما آماده شد</h2><p data-arrival-team></p><strong data-arrival-room></strong><p>تیم را به این اتاق ببرید.</p><form method="dialog"><button class="primary-action">متوجه شدم، می‌رویم</button></form>';
  document.body.append(roomNotice);roomNotice.addEventListener('close',stopRoomAttention);roomNotice.addEventListener('cancel',stopRoomAttention);
  const workerReady=window.isSecureContext&&'serviceWorker'in navigator?navigator.serviceWorker.register('RefMonitor-sw.js',{scope:'./'}).then(()=>navigator.serviceWorker.ready):Promise.resolve(null);
  workerReady.catch(()=>{});
  const syncRoomWatch=()=>{
    if(!pushDevice||!activeGame||!activePeriod)return;
    const state=history.state||{},teamId=['team-detail','score','score-entry'].includes(state.view)?Number(state.teamId||activeTeam?.id||0):0;
    const fields={game_id:activeGame.id,team_id:teamId,device_id:pushDevice};const key=`${activePeriod}:${fields.game_id}:${teamId}`;
    if(key===watchKey)return;watchKey=key;
    watchQueue=watchQueue.catch(()=>{}).then(()=>request('push_watch',fields,{silent:true})).catch(()=>{if(watchKey===key)watchKey='';});
  };
  const registerPush=async permission=>{
    if(!window.isSecureContext)throw new Error('برای اعلان هنگام قفل بودن گوشی، پنل را با HTTPS باز کنید.');
    if(!('PushManager'in window)||!('Notification'in window))throw new Error('برای اعلان در آیفون، این پنل را به صفحهٔ اصلی گوشی اضافه کنید و از همان‌جا باز کنید.');
    if(permission!=='granted')throw new Error('صدای اتاق روشن است؛ برای اعلان روی گوشی، اجازهٔ اعلان را در تنظیمات مرورگر فعال کنید.');
    const registration=await workerReady;if(!registration)throw new Error('فعال‌سازی اعلان ممکن نشد.');
    const config=await request('push_config',{}, {silent:true});
    const encoded=config.public_key;const binary=atob(encoded.replace(/-/g,'+').replace(/_/g,'/')+'='.repeat((4-encoded.length%4)%4));
    const key=Uint8Array.from(binary,char=>char.charCodeAt(0));
    const subscription=await registration.pushManager.getSubscription()||await registration.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:key});
    const response=await request('push_register',{subscription:JSON.stringify(subscription.toJSON())},{silent:true});pushDevice=response.device_id;paintAlerts();watchKey='';syncRoomWatch();
    setStatus('ref-alert-status','اعلان اتاق فعال شد؛ صدای اعلان هنگام قفل بودن گوشی تابع تنظیمات گوشی است.');
  };
  alertButton.addEventListener('click',async()=>{
    alertButton.disabled=true;
    if(alertsEnabled){
      stopRoomAttention();
      alertsEnabled=false;paintAlerts();try{localStorage.setItem(alertPreference,'0');}catch{}
      try{if(pushDevice)await request('push_remove',{device_id:pushDevice},{silent:true});const registration=await workerReady;const subscription=await registration?.pushManager.getSubscription();await subscription?.unsubscribe();}catch{}
      pushDevice='';watchKey='';setStatus('ref-alert-status','صدای اتاق و اعلان خاموش شد.');alertButton.disabled=false;return;
    }
    alertsEnabled=true;paintAlerts();try{localStorage.setItem(alertPreference,'1');}catch{}
    const permission=('Notification'in window)?Notification.requestPermission():Promise.resolve('unsupported');
    try{await unlockAudio();ding();setStatus('ref-alert-status','صدای اتاق روشن شد.');await registerPush(await permission);}
    catch(error){setStatus('ref-alert-status',error.message,'error');}finally{alertButton.disabled=false;}
  });
  if(alertsEnabled&&'Notification'in window&&Notification.permission==='granted')void registerPush('granted').catch(error=>setStatus('ref-alert-status',error.message,'error'));
  const announceRoom=(team,fromWaiting=false)=>{
    const room=team.room_assignment;if(!room)return;const key=`${team.id}:${room.level_id}:${room.room_id}:${room.assigned_at}`;
    if(alertedAssignments.has(key))return;alertedAssignments.add(key);
    if(fromWaiting){
      roomNotice.querySelector('[data-arrival-team]').textContent=team.name;
      roomNotice.querySelector('[data-arrival-room]').textContent=room.room_name;
      if(!roomNotice.open)roomNotice.show();roomAttention();
    }else ding();
  };
  const refreshRoom=async()=>{
    if(roomPolling||document.hidden||!activeGame?.auto_room_manager)return;
    const gameVisible=views.some(view=>view.dataset.refView==='game'&&!view.classList.contains('hidden'));
    const detailVisible=views.some(view=>view.dataset.refView==='team-detail'&&!view.classList.contains('hidden'));
    if(!gameVisible&&(!detailVisible||!activeTeam?.started_at||activeTeam.ended_at))return;
    roomPolling=true;
    try{
      if(gameVisible){await loadTeams(true);return;}
      const teamId=activeTeam.id,gameId=activeGame.id,previous=activeTeam.room_assignment,wasWaiting=activeTeam.waiting_for_room||!previous;
      const updated=(await request('team_detail',{team_id:teamId},{silent:true})).team;
      if(teamId!==activeTeam?.id||gameId!==activeGame?.id||history.state?.view!=='team-detail')return;
      const arrived=updated.room_assignment&&(!previous||previous.room_id!==updated.room_assignment.room_id||previous.level_id!==updated.room_assignment.level_id);
      const changed=JSON.stringify(updated)!==JSON.stringify(activeTeam);activeTeam=updated;if(changed)renderTeamDetail();
      if(!updated.room_assignment&&roomNotice.open)roomNotice.close();
      setStatus('ref-journey-status',updated.ended_at?'':updated.room_assignment?'':'در انتظار اتاق · بررسی خودکار');
      if(arrived){announceRoom(updated,wasWaiting);document.getElementById('ref-room-card').scrollIntoView({block:'start',behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});}
    }catch{setStatus('ref-journey-status','بررسی اتاق ناموفق بود؛ اتصال را بررسی کنید. تلاش خودکار ادامه دارد.','error');}
    finally{roomPolling=false;}
  };
  if('serviceWorker'in navigator)navigator.serviceWorker.addEventListener('message',event=>{
    if(event.data?.type==='ref-room-ready')void refreshRoom();
    if(event.data?.type==='ref-room-open'){
      const payload=event.data.payload;if(payload?.periodCode===activePeriod&&games.some(game=>game.id===payload.gameId))open('team-detail',payload.gameId,activePeriod,Number(payload.teamId));
    }
  });
  const notificationParams=new URLSearchParams(location.search);
  if(notificationParams.get('room_period')===activePeriod&&games.some(game=>game.id===notificationParams.get('room_game'))&&Number(notificationParams.get('room_team'))>0)replaceView('team-detail',notificationParams.get('room_game'),activePeriod,Number(notificationParams.get('room_team')));
  const checkPeriod = async () => {
    try {
      const data = await request('period_status', {}, {silent:true});
      if (data.period_code !== activePeriod) location.reload();
    } catch (_) { /* The next action will display the connection error. */ }
  };
  window.setInterval(checkPeriod, 30000);
  window.setInterval(updateMemberTimer, 1000);
  window.setInterval(refreshRoom,1000);
  document.addEventListener('visibilitychange',()=>{if(!document.hidden){void checkPeriod();void refreshRoom();}});
  window.addEventListener('online',()=>{void checkPeriod();void refreshRoom();});
})();
</script>
</body></html>
