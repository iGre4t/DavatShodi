<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/api/lib/common.php';

$configFile = __DIR__ . '/api/config.php';
if (!empty($_SESSION['authenticated'])) {
  header('Location: panel.php');
  exit;
}

$dbConfig = loadConfig($configFile);
$pdo = connectDatabase($dbConfig);
$connectionError = $pdo ? null : 'اتصال به پایگاه داده برقرار نشد.';
$siteIconUrl = resolveSiteIconForLogin($dbConfig, $pdo);

if (!$connectionError && $pdo && !isInstallComplete()) {
  try {
    $countStmt = $pdo->query('SELECT COUNT(*) FROM `users`');
    if ((int)$countStmt->fetchColumn() > 0) {
      markInstallComplete();
    }
  } catch (PDOException $exception) {
    // Ignore; lock file will be created later when the installer finishes or a user logs in.
  }
}

$errors = [];
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $password = trim($_POST['password'] ?? '');

  if ($username === '' || $password === '') {
    $errors[] = 'نام کاربری و رمز عبور الزامی است.';
  } else {
    if (!$connectionError && $pdo) {
      try {
        $statement = $pdo->prepare('SELECT `code`, `username`, `fullname`, `phone`, `email`, `id_number`, `work_id`, `password_hash` FROM `users` WHERE `username` = :username LIMIT 1');
        $statement->execute(['username' => $username]);
        $user = $statement->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
          $_SESSION['authenticated'] = true;
          $sessionUser = [
            'code' => $user['code'],
            'username' => $user['username'],
            'fullname' => $user['fullname'],
            'phone' => $user['phone'] ?? '',
            'email' => $user['email'] ?? '',
            'id_number' => $user['id_number'] ?? '',
            'work_id' => $user['work_id'] ?? ''
          ];
          $sessionUser['display_name'] = buildUserDisplayName($sessionUser);
          $_SESSION['user'] = $sessionUser;
          header('Location: panel.php');
          exit;
        }
      } catch (PDOException $exception) {
        $connectionError = 'اجرای پرس وجوی پایگاه داده ناموفق بود.';
      }
    }

    $errors[] = $connectionError ?? 'نام کاربری یا رمز عبور نادرست است.';
  }
}

function escape(string $value): string
{
  return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function buildUserDisplayName(array $user = []): string
{
  foreach (['fullname', 'display_name', 'username'] as $field) {
    if (!array_key_exists($field, $user)) {
      continue;
    }
    $value = trim((string)$user[$field]);
    if ($value === '' || $value === '0') {
      continue;
    }
    return $value;
  }
  return 'مدیر';
}

function loadJsonPayload(string $path): array
{
  if (!is_file($path)) {
    return [];
  }
  $content = file_get_contents($path);
  if ($content === false) {
    return [];
  }
  $decoded = json_decode($content, true);
  return is_array($decoded) ? $decoded : [];
}

function formatSiteIconUrlForHtml(string $value = ''): string
{
  $trimmed = trim($value);
  if ($trimmed === '') {
    return '';
  }
  if (preg_match('/^(?:data:|https?:\/\/|\/\/)/i', $trimmed)) {
    return $trimmed;
  }
  if (strncmp($trimmed, '/', 1) === 0 || strncmp($trimmed, './', 2) === 0 || strncmp($trimmed, '../', 3) === 0) {
    return $trimmed;
  }
  return "./{$trimmed}";
}

function resolveSiteIconForLogin(array $dbConfig, ?PDO $pdo): string
{
  $settings = [];
  $fileData = loadJsonPayload(__DIR__ . '/data/store.json');
  if (isset($fileData['settings']) && is_array($fileData['settings'])) {
    $settings = $fileData['settings'];
  }
  if ($pdo) {
    $dbData = loadDataFromDb($pdo, $dbConfig);
    if (isset($dbData['settings']) && is_array($dbData['settings'])) {
      $settings = $dbData['settings'];
    }
  }
  return formatSiteIconUrlForHtml((string)($settings['siteIcon'] ?? ''));
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>ورود به پنل مدیریت</title>
    <link rel="icon" id="site-icon-link" href="<?= escape($siteIconUrl !== '' ? $siteIconUrl : 'data:,') ?>" />
    <script src="General%20Setting/general-settings.js"></script>
    <script src="style/appearance.js"></script>
    <link rel="stylesheet" href="style/styles.css" />
    <style>
      body.login-body {
        min-height: 100vh;
        margin: 0;
        display: flex;
        justify-content: center;
        align-items: center;
        background: radial-gradient(circle at top, #ffffff 0%, #f5f5f7 50%, #f0f0f3 100%);
      }

      main {
        width: 100%;
      }

      .login-card {
        margin: 0 auto;
        width: min(420px, 92vw);
        padding: 40px 36px;
        border-radius: 32px;
        background: #ffffff;
        box-shadow: 0 25px 55px rgba(0, 0, 0, 0.08);
        text-align: center;
      }

      .brand-logo {
        width: 64px;
        height: 64px;
        border-radius: 16px;
        margin: 0 auto 18px;
        background: #111;
        color: #fff;
        font-weight: 700;
        font-size: 26px;
        display: grid;
        place-items: center;
        letter-spacing: 0.8px;
        overflow: hidden;
        border: 1px solid transparent;
      }

      .brand-logo.has-site-icon {
        background: #fff;
        border-color: #e5e7eb;
      }

      .brand-logo img {
        width: 100%;
        height: 100%;
        display: block;
        object-fit: cover;
      }

      .brand-title {
        margin: 0;
        font-size: 1.55rem;
        font-weight: 700;
      }

      .brand-subtitle {
        margin: 6px 0 28px;
        color: #6b7280;
        font-size: 1rem;
      }

      .alert {
        background: #fdecea;
        color: #b91c1c;
        border-radius: 12px;
        padding: 12px 14px;
        margin-bottom: 18px;
        text-align: right;
        font-size: 0.95rem;
        line-height: 1.4;
      }

      .login-form {
        display: grid;
        gap: 16px;
      }

      .field span {
        font-size: 14px;
        color: #6b7280;
        display: block;
        text-align: right;
        margin-bottom: 6px;
      }

      .login-form input {
        width: 100%;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: 14px 16px;
        font-size: 1rem;
        background: #fdfdfd;
        transition: all 0.2s ease;
      }

      .login-form input:focus {
        border-color: var(--primary);
        box-shadow: 0 0 0 3px var(--primary-focus);
        outline: none;
      }

      .login-form button {
        margin-top: 4px;
        border: none;
        border-radius: 14px;
        padding: 14px 16px;
        font-size: 1rem;
        background: var(--primary);
        color: #fff;
        cursor: pointer;
        transition: background 0.2s ease;
      }

      .login-form button:hover {
        background: var(--primary-600);
      }
    </style>
  </head>
  <body class="login-body">
    <main>
      <section class="login-card">
        <div class="brand-logo<?= $siteIconUrl !== '' ? ' has-site-icon' : '' ?>">
          <?php if ($siteIconUrl !== ''): ?>
            <img src="<?= escape($siteIconUrl) ?>" alt="آیکون سایت" />
          <?php else: ?>
            GN
          <?php endif; ?>
        </div>
        <h1 class="brand-title">ورود به پنل مدیریت</h1>
        <p class="brand-subtitle">برای ادامه وارد حساب کاربری خود شوید</p>
        <?php if ($errors): ?>
          <div class="alert" role="alert" aria-live="assertive">
            <ul>
              <?php foreach ($errors as $error): ?>
                <li><?= escape($error) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
        <form method="post" class="login-form" novalidate>
          <label class="field">
            <span>نام کاربری</span>
            <input name="username" type="text" placeholder="مثال: admin" value="<?= escape($username) ?>" autofocus required />
          </label>
          <label class="field">
            <span>رمز عبور</span>
            <input name="password" type="password" placeholder="رمز عبور" required />
          </label>
          <button type="submit">ورود</button>
        </form>
      </section>
    </main>
  </body>
</html>
