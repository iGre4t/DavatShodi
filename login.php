<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/api/lib/common.php';

if (databaseConfigRequiresInstallation()) {
  $configFile = __DIR__ . '/api/config.php';
  if (!is_file($configFile)) {
    header('Location: install.php');
    exit;
  }
}

if (!empty($_SESSION['authenticated'])) {
  header('Location: panel.php');
  exit;
}

$dbConfig = require __DIR__ . '/api/config.php';
$pdo = null;
$connectionError = null;
try {
  $dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $dbConfig['host'] ?? '127.0.0.1',
    $dbConfig['port'] ?? 3306,
    $dbConfig['dbname'] ?? '',
    $dbConfig['charset'] ?? 'utf8mb4'
  );
  $pdo = new PDO($dsn, $dbConfig['user'] ?? '', $dbConfig['password'] ?? '');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $error) {
  $connectionError = 'Database connection failed.';
}

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
    $errors[] = 'Username and password are required.';
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
        $connectionError = 'Database query failed.';
      }
    }

    $errors[] = $connectionError ?? 'Invalid username or password.';
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
  return 'Admin';
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Admin Panel</title>
    <link rel="icon" href="data:," />
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
        border-color: #e11d2e;
        box-shadow: 0 0 0 3px rgba(225, 29, 46, 0.18);
        outline: none;
      }

      .login-form button {
        margin-top: 4px;
        border: none;
        border-radius: 14px;
        padding: 14px 16px;
        font-size: 1rem;
        background: #e11d2e;
        color: #fff;
        cursor: pointer;
        transition: background 0.2s ease;
      }

      .login-form button:hover {
        background: #c51625;
      }

    </style>
  </head>
  <body class="login-body">
    <main>
      <section class="login-card">
        <div class="brand-logo">GN</div>
        <h1 class="brand-title">Admin Panel</h1>
        <p class="brand-subtitle">Sign in to continue managing</p>
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
            <span>Username</span>
            <input name="username" type="text" placeholder="e.g. admin" value="<?= escape($username) ?>" autofocus required />
          </label>
          <label class="field">
            <span>Password</span>
            <input name="password" type="password" placeholder="••••••••" required />
          </label>
          <button type="submit">Sign In</button>
        </form>
      </section>
    </main>
  </body>
</html>
