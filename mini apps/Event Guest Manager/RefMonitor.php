<?php
declare(strict_types=1);

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
$sessionCode = trim((string)($_SESSION['ref_monitor_user_code'] ?? ''));
if ($sessionCode !== '' && $pdo instanceof PDO) {
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
        if (in_array((string)($_POST['action'] ?? ''), ['period_status', 'search_invitees', 'create_team', 'start_team', 'list_teams', 'team_detail', 'update_name', 'update_members', 'submit_score'], true)) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['status' => 'error', 'message' => 'فرم منقضی شده است. صفحه را تازه‌سازی کنید.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $errors[] = 'فرم منقضی شده است. صفحه را تازه‌سازی کنید.';
    } elseif (($_POST['action'] ?? '') === 'logout') {
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
        $password = trim((string)($_POST['password'] ?? ''));
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
                    $_SESSION['ref_monitor_user_code'] = (string)$user['code'];
                    $_SESSION['ref_monitor_csrf'] = bin2hex(random_bytes(32));
                    unset($_SESSION['ref_monitor_failures']);
                    header('Location: RefMonitor.php');
                    exit;
                }
                $errors[] = 'نام کاربری، رمز عبور یا دسترسی EGM معتبر نیست.';
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
    } elseif (in_array((string)($_POST['action'] ?? ''), ['period_status', 'search_invitees', 'create_team', 'start_team', 'list_teams', 'team_detail', 'update_name', 'update_members', 'submit_score'], true)) {
        header('Content-Type: application/json; charset=UTF-8');
        if (!$currentUser || !$pdo instanceof PDO || $eventCode === '') {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'دسترسی معتبر نیست. دوباره وارد شوید.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
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
            if ($action === 'search_invitees') {
                $result = ['invitees' => egmRefMonitorSearchInvitees($pdo, $eventCode, $periodCode, (string)($_POST['query'] ?? ''))];
            } elseif ($action === 'create_team') {
                $members = $_POST['members'] ?? [];
                if (!is_array($members)) throw new InvalidArgumentException('اعضای تیم معتبر نیستند.');
                $result = ['team' => egmRefMonitorCreateTeam($pdo, $eventCode, $periodCode, $gameId, (string)($_POST['name'] ?? ''), $members, (string)$currentUser['code'])];
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
            } elseif ($action === 'submit_score') {
                $result = ['team' => egmRefMonitorSubmitScore($pdo, $eventCode, $periodCode, $gameId, $teamId, (string)($_POST['level_id'] ?? ''), (string)($_POST['score'] ?? ''), (string)$currentUser['code'])];
            } else {
                $result = ['teams' => egmRefMonitorListTeams($pdo, $eventCode, $periodCode, $gameId)];
            }
            echo json_encode(['status' => 'ok'] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (InvalidArgumentException $error) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $error) {
            error_log('RefMonitor team request failed: ' . $error->getMessage());
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'درخواست انجام نشد. دوباره تلاش کنید.'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    } else {
        http_response_code(400);
        $errors[] = 'درخواست نامعتبر است.';
    }
}
$displayName = trim((string)($currentUser['fullname'] ?? $currentUser['username'] ?? 'مدیر'));
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
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title><?= $escape($eventName) ?> · پنل داور</title>
  <style nonce="<?= $escape($nonce) ?>">
    @font-face{font-family:Peyda;src:url('../../style/fonts/PeydaWebFaNum-Regular.woff2') format('woff2');font-display:swap;font-weight:400}
    @font-face{font-family:Peyda;src:url('../../style/fonts/PeydaWebFaNum-Bold.woff2') format('woff2');font-display:swap;font-weight:700}
    *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:18px;direction:rtl;text-align:right;font-family:Peyda,Tahoma,Arial,sans-serif;color:#253752;background:radial-gradient(circle at top right,#e1efff,transparent 48%),radial-gradient(circle at bottom left,#e6f3ff,transparent 45%),#f4f7fb}
    .app{width:min(460px,100%)}.phone{height:calc(100vh - 36px);min-height:min(860px,calc(100vh - 36px));display:flex;flex-direction:column;overflow:hidden;background:#fff;border:1px solid #dce5f2;border-radius:28px;box-shadow:0 8px 24px rgba(29,55,96,.1),inset 0 1px 0 #fff}
    .topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px 10px}.brand{font-size:.96rem;font-weight:700;color:#2f669f}.topbar-actions{display:flex;gap:8px}.topbar button{border:0;background:none;color:#6b7a99;font:inherit;cursor:pointer;padding:5px}
    .login-area,.main-area{flex:1;min-height:0;overflow-y:auto;padding:22px 22px calc(24px + env(safe-area-inset-bottom,0px));display:flex;flex-direction:column;gap:20px}.login-hero{text-align:center;display:flex;flex-direction:column;align-items:center;gap:10px}.login-icon{width:92px;height:92px;display:grid;place-items:center;border-radius:24px;background:#eaf4ff;color:#2f8fff;font-size:2rem;font-weight:700}.login-title{margin:0;font-size:1.08rem;color:#2f669f}.login-subtitle{margin:0;color:#6b7a99;font-size:.85rem}
    .login-form{display:flex;flex-direction:column;gap:12px}.login-field{display:flex;flex-direction:column;gap:6px;font-size:.82rem;color:#516089}.login-input{width:100%;border:1px solid #e1e8f4;border-radius:12px;padding:10px 12px;font:inherit;font-size:.95rem;background:#f8fbff;color:#253752;outline:none}.login-input:focus{border-color:#9bbcff;box-shadow:0 0 0 3px rgba(47,143,255,.12);background:#fff}.password-wrap{position:relative}.password-wrap .login-input{padding-left:46px}.password-toggle{position:absolute;left:6px;top:50%;transform:translateY(-50%);border:0;border-radius:10px;background:transparent;color:#5f7196;width:34px;height:34px;cursor:pointer}.login-btn{margin-top:4px;border:0;border-radius:14px;padding:12px;font:inherit;font-weight:700;font-size:.95rem;background:#2f8fff;color:#fff;cursor:pointer}.login-hint{margin:4px 0 0;min-height:1.2em;font-size:.82rem;color:#c53742;text-align:center}.login-help-card,.placeholder{border:1px solid #e3ecfa;border-radius:14px;background:#f8fbff;padding:14px}.login-help-card p{margin:0;font-size:.8rem;line-height:1.8;color:#5a6f95}.main-area h1{font-size:1.1rem;margin:0}.placeholder{color:#526789;line-height:1.8}.hidden{display:none!important}
    .games-section{display:flex;flex-direction:column;gap:12px}.games-heading{margin:0;font-size:1rem;color:#2f669f}.games-list{list-style:none;margin:0;padding:0;display:grid;gap:10px}.game-card{display:flex;align-items:center;gap:12px;min-height:64px;padding:12px 14px;border:1px solid #e3ecfa;border-radius:16px;background:#f8fbff;color:#253752;font-weight:700}.game-icon{width:38px;height:38px;flex:none;display:grid;place-items:center;border-radius:12px;background:#e5f1ff;color:#2f8fff;font-size:1.2rem}
    button.game-card{width:100%;font:inherit;text-align:right;cursor:pointer}button.game-card:hover,button.game-card:focus-visible{border-color:#9bbcff;background:#eef7ff}.game-arrow{margin-right:auto;color:#7990b0}.stack{display:flex;flex-direction:column;gap:12px}.primary-action,.secondary-action{border:0;border-radius:13px;padding:11px 14px;font:inherit;font-weight:700;cursor:pointer}.primary-action{background:#2f8fff;color:#fff}.secondary-action{background:#eaf4ff;color:#2f669f}.primary-action:disabled{opacity:.5;cursor:not-allowed}.section-label{font-size:.86rem;font-weight:700;color:#526789}.team-list,.result-list,.selected-list{list-style:none;margin:0;padding:0;display:grid;gap:9px}.team-card,.person-card{border:1px solid #e3ecfa;border-radius:13px;background:#f8fbff;padding:11px 12px}.team-card strong,.person-card strong{display:block}.team-card small,.person-card small{display:block;color:#647899;line-height:1.7}.person-card{display:flex;align-items:center;justify-content:space-between;gap:9px}.person-card button{flex:none}.status-text{margin:0;color:#647899;font-size:.83rem;line-height:1.7}.status-text.error{color:#c53742}.status-text.success{color:#247955}
    button.team-card{width:100%;font:inherit;text-align:right;cursor:pointer;color:inherit}button.team-card:hover,button.team-card:focus-visible{border-color:#9bbcff;background:#eef7ff}.detail-card{border:1px solid #e3ecfa;border-radius:13px;background:#f8fbff;padding:12px;display:grid;gap:8px}.detail-card strong{color:#2f669f}.detail-members{list-style:none;margin:0;padding:0;display:grid;gap:7px}.detail-members li{padding:8px 10px;border-radius:9px;background:#f8fbff;color:#526789}.score-form{display:grid;gap:12px}.score-form input{min-width:0}.team-title-row,.section-heading-row{display:flex;align-items:center;justify-content:space-between;gap:8px}.team-title-row{justify-content:flex-start}.team-name-form{display:flex;align-items:center;gap:7px}.team-name-form .login-input{min-width:0;flex:1}.icon-action{width:34px;height:34px;flex:none;display:grid;place-items:center;border:0;border-radius:10px;background:#eaf4ff;color:#2f669f;font:inherit;font-size:1.25rem;cursor:pointer}.icon-action:hover,.icon-action:focus-visible{background:#d9ecff;outline:2px solid #9bbcff}.inline-action{border:0;background:none;color:#2f669f;font:inherit;font-size:.85rem;font-weight:700;cursor:pointer;padding:4px 0}.step-indicator{font-size:.78rem;font-weight:700;color:#2f669f;letter-spacing:.01em}.step-track{height:5px;background:#e7effa;border-radius:20px;overflow:hidden}.step-track span{display:block;height:100%;width:33.33%;background:#2f8fff;border-radius:20px;transition:width .2s}.flow-panel{display:grid;gap:16px}.flow-intro{display:grid;gap:6px}.flow-intro h2{font-size:1rem;margin:0;color:#253752}.flow-intro p{margin:0;color:#647899;font-size:.83rem;line-height:1.7}.flow-card{border:1px solid #e3ecfa;border-radius:16px;background:#f8fbff;padding:15px;display:grid;gap:12px}.flow-card h2{font-size:.94rem;color:#2f669f;margin:0}.flow-action{width:100%;margin-top:4px}.action-dock{position:sticky;bottom:-1px;z-index:5;flex:none;margin-top:auto;padding:12px 0 max(16px,env(safe-area-inset-bottom,0px));background:linear-gradient(to bottom,rgba(255,255,255,.93),#fff 22%);box-shadow:0 -8px 18px rgba(255,255,255,.88)}.action-dock .flow-action{margin:0}.quiet-details{border-top:1px solid #e8eef8;padding-top:12px}.quiet-details summary{cursor:pointer;color:#526789;font-size:.85rem;list-style:none}.quiet-details summary::-webkit-details-marker{display:none}.quiet-details[open] summary{margin-bottom:14px}.progress-list{list-style:none;margin:0;padding:0;display:grid;gap:8px}.progress-list li{display:flex;justify-content:space-between;gap:10px;padding:9px 11px;border-radius:10px;background:#f8fbff;color:#526789;font-size:.84rem}.progress-list li.current{background:#eaf4ff;color:#2f669f;font-weight:700}.score-number{font-size:1.4rem;font-weight:700;text-align:center;padding:14px}.review-members{margin:0;padding-right:20px;color:#526789;line-height:1.8}
    .game-context{display:inline-flex;align-items:center;gap:7px;width:max-content;margin:0;padding:6px 10px;border-radius:9px;background:#eff6ff;color:#42648f;font-size:.84rem;font-weight:700}.game-context::before{content:'🎮';font-size:.92rem}.team-total-score{align-self:flex-start;border-radius:999px;background:#eaf4ff;color:#2f669f;padding:5px 10px;font-size:.82rem}
    @media(max-width:440px){body{padding:10px}.phone{height:calc(100vh - 20px);min-height:calc(100vh - 20px);border-radius:22px}.login-area,.main-area{padding:18px 16px calc(20px + env(safe-area-inset-bottom,0px))}}
  </style>
</head>
<body>
<main class="app"><section class="phone">
  <header class="topbar"><div class="brand">پنل داور</div><div class="topbar-actions">
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
      </form><div class="login-help-card"><p>با نام کاربری و رمز عبور پنل مدیریت وارد شوید. ورود پنل داور مستقل از ورود پنل اصلی است.</p></div>
    </div>
  <?php else: ?>
    <div class="main-area" data-ref-view="home"><h1><?= $escape($displayName) ?> عزیز، خوش آمدید</h1>
      <?php if ($activePeriod): ?><p class="status-text">بازهٔ فعال: <?= $escape($activePeriod['title']) ?></p><?php endif; ?>
      <section class="games-section" aria-labelledby="games-heading"><h2 id="games-heading" class="games-heading">بازی‌ها</h2>
        <?php if ($gamesError !== ''): ?><div class="placeholder" role="alert"><?= $escape($gamesError) ?></div>
        <?php elseif ($games === []): ?><div class="placeholder">در این بازه بازی فعالی وجود ندارد.</div>
        <?php else: ?><ul class="games-list">
          <?php foreach ($games as $game): ?><li><button type="button" class="game-card" data-open-game="<?= $escape($game['id']) ?>"><span class="game-icon" aria-hidden="true">♟</span><span><?= $escape($game['name']) ?></span><span class="game-arrow" aria-hidden="true">‹</span></button></li><?php endforeach; ?>
        </ul><?php endif; ?>
      </section>
    </div>
    <div class="main-area hidden" data-ref-view="game"><h1 id="ref-game-title"></h1>
      <p class="status-text" id="ref-game-period"></p>
      <section class="games-section"><h2 class="games-heading">تیم‌های ثبت‌شده</h2><p class="status-text" id="ref-teams-status"></p><ul class="team-list" id="ref-teams-list"></ul></section>
      <div class="action-dock"><button type="button" class="primary-action flow-action" id="ref-new-team">تشکیل تیم</button></div>
    </div>
    <div class="main-area hidden" data-ref-view="team"><h1 id="ref-team-form-title">تشکیل تیم</h1><p class="status-text" id="ref-team-context"></p>
      <div class="step-track" aria-hidden="true"><span id="ref-step-fill"></span></div><p class="step-indicator" id="ref-step-label"></p>
      <section class="flow-panel" data-team-step="name">
        <label class="login-field"><span>نام تیم</span><input class="login-input" id="ref-team-name" maxlength="100" autocomplete="off" required></label></section>
      <section class="flow-panel hidden" data-team-step="members">
        <label class="login-field"><span>جستجوی عضو</span><input class="login-input" id="ref-search-input" autocomplete="off" placeholder="نام، کد ملی یا کد پرسنلی"></label>
        <p class="status-text" id="ref-search-status" role="status"></p><ul class="result-list" id="ref-search-results"></ul>
        <div class="flow-card hidden" id="ref-selected-card"><h2>اعضا <span id="ref-member-count">(۰)</span></h2><ul class="selected-list" id="ref-selected-list"></ul></div></section>
      <section class="flow-panel hidden" data-team-step="review">
        <div class="flow-card"><h2 id="ref-review-name"></h2><p class="status-text" id="ref-review-count"></p><ol class="review-members" id="ref-review-members"></ol></div></section>
      <div class="action-dock" data-team-action="name"><button type="button" class="primary-action flow-action" id="ref-team-next-name">ادامه</button></div>
      <div class="action-dock hidden" data-team-action="members"><button type="button" class="primary-action flow-action" id="ref-team-next-members" disabled>ادامه</button></div>
      <div class="action-dock hidden" data-team-action="review"><button type="button" class="primary-action flow-action" id="ref-submit-team">ثبت تیم</button><p class="status-text" id="ref-submit-status" role="status"></p></div>
    </div>
    <div class="main-area hidden" data-ref-view="team-detail"><div class="flow-intro"><div class="team-title-row"><h1 id="ref-detail-title"></h1><button class="icon-action" type="button" id="ref-edit-name" aria-label="ویرایش نام تیم" title="ویرایش نام تیم">✎</button></div><p class="status-text" id="ref-detail-state"></p>
      <form class="team-name-form hidden" id="ref-name-form" aria-label="ویرایش نام تیم"><input class="login-input" id="ref-detail-name" maxlength="100" aria-label="نام جدید تیم" required><button class="icon-action" type="submit" aria-label="ذخیره نام" title="ذخیره نام">✓</button><button class="icon-action" type="button" id="ref-cancel-name" aria-label="لغو ویرایش" title="لغو">×</button></form><p class="status-text" id="ref-name-status" role="status"></p></div>
      <p class="game-context status-text" id="ref-detail-overview"></p><div class="detail-card hidden" id="ref-room-card"><strong id="ref-room-title"></strong><span id="ref-room-name"></span></div><strong class="team-total-score hidden" id="ref-total-score"></strong>
      <p class="status-text" id="ref-start-hint" role="status"></p>
      <section class="games-section"><div class="section-heading-row"><h2 class="games-heading" id="ref-detail-members-title">اعضا</h2><button class="inline-action hidden" type="button" id="ref-edit-members">ویرایش</button></div><ul class="detail-members" id="ref-detail-members"></ul><p class="status-text" id="ref-member-hint"></p></section><p class="status-text" id="ref-score-status" role="status"></p>
      <div class="action-dock" id="ref-detail-dock"><button class="primary-action flow-action hidden" type="button" id="ref-start-team">شروع بازی</button><button class="primary-action flow-action hidden" type="button" id="ref-open-scores">ثبت امتیاز</button></div>
    </div>
    <div class="main-area hidden" data-ref-view="score"><div class="flow-intro"><h1>ثبت امتیاز</h1><p class="status-text" id="ref-levels-team"></p></div><ul class="progress-list" id="ref-score-levels"></ul><p class="status-text" id="ref-levels-status" role="status"></p></div>
    <div class="main-area hidden" data-ref-view="score-entry"><div class="flow-intro"><p class="step-indicator" id="ref-score-progress"></p><h1 id="ref-score-title">مرحله بازی</h1><p class="status-text" id="ref-score-team"></p></div>
      <div class="step-track" id="ref-score-track" aria-hidden="true"><span id="ref-score-fill"></span></div>
      <div class="flow-card" id="ref-score-stage"></div><p class="status-text" id="ref-score-step-status" role="status"></p>
      <div class="action-dock" id="ref-score-dock"><button class="primary-action flow-action" type="submit" form="ref-score-form" id="ref-score-submit">ثبت امتیاز و ادامه</button></div>
      <div class="action-dock hidden" id="ref-score-home-dock"><button class="primary-action flow-action" type="button" id="ref-score-home">بازگشت به خانه</button></div>
    </div>
  <?php endif; ?>
</section></main>
<script nonce="<?= $escape($nonce) ?>">
(() => {
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
  const teamsList = document.getElementById('ref-teams-list');
  const selectedList = document.getElementById('ref-selected-list');
  const resultList = document.getElementById('ref-search-results');
  const selected = new Map();
  let activeGame = null;
  const activePeriod = currentPeriod?.code || '';
  let activeTeam = null;
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
    const body = new URLSearchParams({action, csrf, period_code: activePeriod, game_id: activeGame?.id || ''});
    for (const [key, value] of Object.entries(fields)) {
      if (Array.isArray(value)) value.forEach(item => body.append(key + '[]', String(item)));
      else body.set(key, String(value));
    }
    const response = await fetch(location.pathname, {method:'POST', credentials:'same-origin', body, signal:options.signal});
    const data = await response.json();
    if (!response.ok || data.status !== 'ok') throw new Error(data.message || 'درخواست انجام نشد.');
    return data;
  };
  const personItem = (person, action, label) => {
    const item = document.createElement('li');
    item.className = 'person-card';
    const details = document.createElement('span');
    const name = document.createElement('strong');
    name.textContent = person.name || 'بدون نام';
    const codes = document.createElement('small');
    codes.textContent = `${person.work_id ? `کد پرسنلی ${person.work_id}` : `کد ملی ${person.national_id || '—'}`}${activeGame?.gender_mode === 'separated' ? ` · ${person.gender === 'male' ? 'مرد' : person.gender === 'female' ? 'زن' : 'جنسیت نامشخص'}` : ''}`;
    details.append(name, codes);
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'secondary-action';
    button.textContent = label;
    button.addEventListener('click', action);
    item.append(details, button);
    return item;
  };
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
    const list = document.getElementById('ref-review-members');
    list.replaceChildren();
    for (const person of selected.values()) {
      const item = document.createElement('li'); item.textContent = person.name || person.work_id || person.national_id || 'بدون نام'; list.append(item);
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
      const data = await request('search_invitees', {query}, {signal:searchController.signal});
      if (sequence !== searchSequence) return;
      results = data.invitees; renderResults();
      setStatus('ref-search-status', results.length ? '' : 'موردی پیدا نشد؛ فقط دعوت‌شدگان بازهٔ فعال نمایش داده می‌شوند.');
    } catch (error) {
      if (error.name === 'AbortError' || sequence !== searchSequence) return;
      results = []; renderResults(); setStatus('ref-search-status', error.message, 'error');
    }
  };
  const loadTeams = async (silent = false) => {
    if (!activePeriod) { setStatus('ref-teams-status', 'در حال حاضر بازهٔ فعالی وجود ندارد.'); return; }
    if (!silent) setStatus('ref-teams-status', 'در حال بارگذاری تیم‌ها…');
    try {
      const data = await request('list_teams');
      teamsList.replaceChildren();
      setStatus('ref-teams-status', data.teams.length ? '' : 'هنوز تیمی ثبت نشده است.');
      for (const team of data.teams) {
        const item = document.createElement('li');
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'team-card';
        const title = document.createElement('strong');
        title.textContent = team.name;
        const members = document.createElement('small');
        members.textContent = `${team.members.length} عضو`;
        const state = document.createElement('small');
        state.textContent = `${team.ended_at ? 'پایان‌یافته' : activeGame.auto_room_manager && team.room_assignment ? `${team.room_assignment.level_name} · ${team.room_assignment.room_name}` : activeGame.auto_room_manager && team.waiting_for_room ? 'در انتظار اتاق' : team.started_at ? 'در جریان' : 'آماده شروع'}${team.started_at ? ` · امتیاز ${team.total_score}` : ''}`;
        button.append(title, members, state);
        button.addEventListener('click', () => { activeTeam = team; open('team-detail', activeGame.id, activePeriod, team.id); });
        item.append(button);
        teamsList.append(item);
      }
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
  const scoreSteps = game => game.has_levels ? (game.levels || []) : [{id:'game_total', name:'امتیاز بازی'}];
  const renderTeamDetail = () => {
    if (!activeTeam || !activeGame) return;
    document.getElementById('ref-detail-title').textContent = activeTeam.name;
    setStatus('ref-detail-state', activeTeam.ended_at ? 'پایان‌یافته' : activeTeam.started_at ? 'در جریان' : 'آماده شروع', activeTeam.ended_at ? 'success' : '');
    document.getElementById('ref-detail-overview').textContent = activeGame.name;
    const roomCard = document.getElementById('ref-room-card');
    roomCard.classList.toggle('hidden', !activeGame.auto_room_manager || !activeTeam.started_at || !!activeTeam.ended_at);
    if (activeGame.auto_room_manager && activeTeam.started_at && !activeTeam.ended_at) {
      document.getElementById('ref-room-title').textContent = activeTeam.room_assignment ? activeTeam.room_assignment.level_name : 'در انتظار اتاق';
      document.getElementById('ref-room-name').textContent = activeTeam.room_assignment ? `اتاق: ${activeTeam.room_assignment.room_name}` : 'به محض آزاد شدن اتاق، اینجا نمایش داده می‌شود.';
    }
    const startButton = document.getElementById('ref-start-team');
    startButton.classList.toggle('hidden', !!activeTeam.started_at || !!activeTeam.ended_at);
    const scoreButton = document.getElementById('ref-open-scores');
    const hasPendingScores = scoreSteps(activeGame).some(level => !activeTeam.scores?.[level.id]);
    scoreButton.classList.toggle('hidden', !activeTeam.started_at || !!activeTeam.ended_at || !hasPendingScores || (activeGame.auto_room_manager && !activeTeam.room_assignment));
    document.getElementById('ref-detail-dock').classList.toggle('hidden', startButton.classList.contains('hidden') && scoreButton.classList.contains('hidden'));
    const count = activeTeam.members.length;
    const withinLimits = count >= activeGame.min_players && count <= activeGame.max_players;
    startButton.disabled = !withinLimits;
    setStatus('ref-start-hint', activeTeam.started_at || activeTeam.ended_at ? ''
      : withinLimits ? ''
      : `برای شروع بازی، تیم باید بین ${activeGame.min_players} تا ${activeGame.max_players} عضو داشته باشد.`);
    const nameForm = document.getElementById('ref-name-form');
    nameForm.classList.add('hidden');
    document.getElementById('ref-edit-name').classList.toggle('hidden', !!activeTeam.ended_at);
    document.getElementById('ref-detail-name').value = activeTeam.name;
    const membersList = document.getElementById('ref-detail-members');
    document.getElementById('ref-detail-members-title').textContent = `اعضا (${activeTeam.members.length})`;
    membersList.replaceChildren();
    for (const member of activeTeam.members) {
      const item = document.createElement('li');
      item.textContent = member.name || member.work_id || 'بدون نام';
      membersList.append(item);
    }
    memberDeadline = activeTeam.started_at ? Date.now() + (activeTeam.member_edit_seconds_left || 0) * 1000 : 0;
    updateMemberTimer();
    const totalScore = document.getElementById('ref-total-score');
    totalScore.textContent = `امتیاز کل: ${activeTeam.total_score}`;
    totalScore.classList.toggle('hidden', !activeGame.has_levels || !activeTeam.started_at);
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
        const button = document.createElement('button'); button.type = 'button'; button.className = 'inline-action'; button.textContent = 'ثبت امتیاز';
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
        const value = document.createElement('span'); value.textContent = `${score.score} امتیاز`;
        item.append(title, value);
      } else if (activeTeam.ended_at) {
        const value = document.createElement('span'); value.textContent = 'ثبت نشده'; item.append(title, value);
      } else {
        const button = document.createElement('button'); button.type = 'button'; button.className = 'inline-action';
        button.textContent = 'ثبت امتیاز';
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
    const completed = levels.filter(level => activeTeam.scores?.[level.id]);
    const nextLevel = activeGame.auto_room_manager
      ? levels.find(level => level.id === activeTeam.room_assignment?.level_id && !activeTeam.scores?.[level.id])
      : levels.find(level => level.id === activeScoreLevelId && !activeTeam.scores?.[level.id]) || levels.find(level => !activeTeam.scores?.[level.id]);
    const stage = document.getElementById('ref-score-stage');
    const scoreDock = document.getElementById('ref-score-dock');
    const homeDock = document.getElementById('ref-score-home-dock');
    document.getElementById('ref-score-submit').disabled = false;
    document.getElementById('ref-score-submit').textContent = 'ثبت امتیاز';
    stage.replaceChildren(); scoreDock.classList.add('hidden'); homeDock.classList.add('hidden');
    const singleScoreGame = !activeGame.has_levels;
    document.getElementById('ref-score-progress').classList.toggle('hidden', singleScoreGame);
    document.getElementById('ref-score-track').classList.toggle('hidden', singleScoreGame);
    document.getElementById('ref-score-progress').textContent = singleScoreGame ? ''
      : activeTeam.ended_at ? `${completed.length} مرحله از ${levels.length} ثبت شد`
      : levels.length ? `${completed.length} از ${levels.length} مرحله ثبت شده` : 'مراحل بازی';
    document.getElementById('ref-score-fill').style.width = levels.length ? `${completed.length / levels.length * 100}%` : '0%';
    setStatus('ref-score-team', activeTeam.name);
    if (!levels.length) {
      document.getElementById('ref-score-title').textContent = 'مرحله‌ای تعریف نشده است';
      const message = document.createElement('p'); message.className = 'status-text'; message.textContent = 'مدیر EGM باید ابتدا مراحل این بازی را تعریف کند.'; stage.append(message);
      return;
    }
    if (activeTeam.ended_at || (!nextLevel && !activeGame.auto_room_manager)) {
      document.getElementById('ref-score-title').textContent = 'بازی پایان یافت';
      const message = document.createElement('p'); message.className = 'status-text success'; message.textContent = `امتیاز نهایی: ${activeTeam.total_score}`; stage.append(message);
      homeDock.classList.remove('hidden');
      return;
    }
    if (activeGame.auto_room_manager && !nextLevel) {
      document.getElementById('ref-score-title').textContent = 'در انتظار اتاق';
      const message = document.createElement('p'); message.className = 'status-text'; message.textContent = 'پس از آزاد شدن اتاق، مرحلهٔ بعد نمایش داده می‌شود.'; stage.append(message);
      return;
    }
    document.getElementById('ref-score-title').textContent = nextLevel.name;
    const intro = document.createElement('p'); intro.className = 'status-text'; intro.textContent = activeGame.auto_room_manager ? `اتاق ${activeTeam.room_assignment.room_name}` : activeGame.has_levels ? 'امتیاز این مرحله پس از ثبت قابل تغییر نیست.' : 'امتیاز بازی پس از ثبت قابل تغییر نیست.';
    const form = document.createElement('form'); form.className = 'score-form'; form.id = 'ref-score-form'; form.dataset.levelId = nextLevel.id;
    const input = document.createElement('input'); input.className = 'login-input score-number'; input.type = 'number'; input.min = '0'; input.max = '999999.99'; input.step = '0.01'; input.required = true; input.placeholder = 'امتیاز'; input.setAttribute('aria-label', `امتیاز ${nextLevel.name}`);
    form.append(input); stage.append(intro, form); scoreDock.classList.remove('hidden');
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
    const target = views.find(view => view.dataset.refView === name) || views[0];
    for (const view of views) view.classList.toggle('hidden', view !== target);
    const isHome = target === views[0];
    back.classList.toggle('hidden', isHome);
    logoutForm.classList.toggle('hidden', !isHome);
    if (name === 'game' || name === 'team' || name === 'team-detail' || name === 'score' || name === 'score-entry') {
      activeGame = games.find(game => game.id === state.gameId) || activeGame;
      if (!activeGame) return;
      if (name === 'game') {
        gameTitle.textContent = activeGame.name;
        setStatus('ref-game-period', `بازهٔ فعال: ${currentPeriod?.title || activePeriod}`);
        loadTeams();
      } else if (name === 'team') {
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
        activeScoreLevelId = name === 'score-entry' ? (state.step || null) : null;
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
  window.RefMonitorNavigation = {open, back() { if (!back.classList.contains('hidden')) history.back(); }};
  document.querySelectorAll('[data-open-game]').forEach(button => button.addEventListener('click', () => open('game', button.dataset.openGame)));
  document.getElementById('ref-new-team').addEventListener('click', () => {
    if (!activePeriod) return;
    activeTeam = null;
    selected.clear(); renderSelected(); resetSearch();
    document.getElementById('ref-team-name').value = '';
    setStatus('ref-submit-status', '');
    open('team', activeGame.id, activePeriod, null, 'name');
  });
  document.getElementById('ref-team-next-name').addEventListener('click', () => {
    if (!document.getElementById('ref-team-name').reportValidity()) return;
    open('team', activeGame.id, activePeriod, null, 'members');
  });
  document.getElementById('ref-team-next-members').addEventListener('click', () => {
    if (!selected.size || selected.size > activeGame.max_players) return;
    open('team', activeGame.id, activePeriod, editingTeamId, 'review');
  });
  document.getElementById('ref-edit-members').addEventListener('click', () => {
    if (!activeTeam?.can_edit_members) return;
    selected.clear(); resetSearch();
    for (const member of activeTeam.members) selected.set(member.id, member);
    renderSelected();
    setStatus('ref-submit-status', '');
    open('team', activeGame.id, activePeriod, activeTeam.id, 'members');
  });
  document.getElementById('ref-edit-name').addEventListener('click', () => {
    if (!activeTeam || activeTeam.ended_at) return;
    document.getElementById('ref-edit-name').classList.add('hidden');
    document.getElementById('ref-name-form').classList.remove('hidden');
    setStatus('ref-name-status', '');
    const input = document.getElementById('ref-detail-name');
    input.value = activeTeam.name;
    input.focus(); input.select();
  });
  document.getElementById('ref-cancel-name').addEventListener('click', () => {
    document.getElementById('ref-name-form').classList.add('hidden');
    document.getElementById('ref-edit-name').classList.toggle('hidden', !!activeTeam?.ended_at);
    setStatus('ref-name-status', '');
  });
  document.getElementById('ref-start-team').addEventListener('click', async event => {
    if (!activeTeam || activeTeam.started_at || activeTeam.ended_at) return;
    const button = event.currentTarget;
    button.disabled = true;
    setStatus('ref-start-hint', 'در حال شروع بازی…');
    try {
      activeTeam = (await request('start_team', {team_id:activeTeam.id})).team;
      renderTeamDetail();
      setStatus('ref-start-hint', '');
    } catch (error) { setStatus('ref-start-hint', error.message, 'error'); button.disabled = false; }
  });
  document.getElementById('ref-open-scores').addEventListener('click', () => {
    if (!activeTeam?.started_at || activeTeam.ended_at) return;
    if (activeGame.auto_room_manager) {
      if (activeTeam.room_assignment) open('score-entry', activeGame.id, activePeriod, activeTeam.id, activeTeam.room_assignment.level_id);
      return;
    }
    open('score', activeGame.id, activePeriod, activeTeam.id);
  });
  document.getElementById('ref-score-home').addEventListener('click', () => open('home'));
  document.getElementById('ref-name-form').addEventListener('submit', async event => {
    event.preventDefault();
    if (!activeTeam || activeTeam.ended_at) return;
    setStatus('ref-name-status', 'در حال ذخیره…');
    try {
      activeTeam = (await request('update_name', {team_id:activeTeam.id, name:document.getElementById('ref-detail-name').value})).team;
      renderTeamDetail(); setStatus('ref-name-status', '');
    } catch (error) { setStatus('ref-name-status', error.message, 'error'); }
  });
  document.getElementById('ref-score-stage').addEventListener('submit', async event => {
    const form = event.target.closest('[data-level-id]');
    if (!form || !activeTeam) return;
    event.preventDefault();
    const button = document.getElementById('ref-score-submit'); button.disabled = true;
    setStatus('ref-score-step-status', 'در حال ثبت امتیاز…');
    try {
      activeTeam = (await request('submit_score', {team_id:activeTeam.id, level_id:form.dataset.levelId, score:form.querySelector('input').value})).team;
      if (!activeTeam.ended_at) { history.back(); return; }
      renderTeamDetail(); renderScoreStep();
      setStatus('ref-score-step-status', '');
    } catch (error) { setStatus('ref-score-step-status', error.message, 'error'); button.disabled = false; }
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
    button.disabled = true;
    setStatus('ref-submit-status', editing ? 'در حال ذخیرهٔ اعضا…' : 'در حال ثبت تیم…');
    try {
      const fields = {members:[...selected.keys()]};
      if (editing) fields.team_id = editingTeamId; else fields.name = teamName.value.trim();
      const data = await request(editing ? 'update_members' : 'create_team', fields);
      activeTeam = editing ? data.team : null;
      setStatus('ref-submit-status', editing ? 'اعضای تیم ذخیره شدند.' : 'تیم ثبت شد.', 'success');
      history.go(editing ? -2 : -3);
    } catch (error) { setStatus('ref-submit-status', error.message, 'error'); button.disabled = false; }
  });
  back.addEventListener('click', () => window.RefMonitorNavigation.back());
  window.addEventListener('popstate', event => show(event.state?.refMonitor ? event.state.view : 'home', event.state || {}));
  const checkPeriod = async () => {
    try {
      const data = await request('period_status');
      if (data.period_code !== activePeriod) location.reload();
    } catch (_) { /* The next action will display the connection error. */ }
  };
  window.setInterval(checkPeriod, 30000);
  window.setInterval(updateMemberTimer, 1000);
  window.setInterval(async () => {
    if (document.hidden || !activeGame?.auto_room_manager) return;
    const gameVisible = views.some(view => view.dataset.refView === 'game' && !view.classList.contains('hidden'));
    if (gameVisible) { void loadTeams(true); return; }
    if (!activeTeam?.waiting_for_room) return;
    const detailVisible = views.some(view => view.dataset.refView === 'team-detail' && !view.classList.contains('hidden'));
    if (!detailVisible) return;
    try {
      const updated = (await request('team_detail', {team_id:activeTeam.id})).team;
      if (updated.room_assignment || updated.ended_at) { activeTeam = updated; renderTeamDetail(); }
    } catch (_) { /* The next action will display the connection error. */ }
  }, 3000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) checkPeriod(); });
})();
</script>
</body></html>
