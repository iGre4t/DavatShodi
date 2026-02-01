<?php
declare(strict_types=1);

session_start();

$loginFile = __DIR__ . '/testlogin.json';

if (!empty($_SESSION['fortune_number_authenticated'])) {
  header('Location: index.php');
  exit;
}

$errors = [];
$username = '';
$credentials = loadLoginData($loginFile);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $password = trim($_POST['password'] ?? '');

  if ($username === '' || $password === '') {
    $errors[] = 'Username and password are required.';
  } elseif (!$credentials) {
    $errors[] = 'Login configuration is missing.';
  } else {
    $validUser = (string)$credentials['username'];
    $validPass = (string)$credentials['password'];
    if (hash_equals($validUser, $username) && hash_equals($validPass, $password)) {
      $_SESSION['fortune_number_authenticated'] = true;
      $_SESSION['fortune_number_user'] = $validUser;
      header('Location: index.php');
      exit;
    }
    $errors[] = 'Invalid username or password.';
  }
}

function loadLoginData(string $path): ?array
{
  if (!is_file($path)) {
    return null;
  }
  $content = file_get_contents($path);
  if ($content === false) {
    return null;
  }
  $decoded = json_decode($content, true);
  if (!is_array($decoded)) {
    return null;
  }
  $username = trim((string)($decoded['username'] ?? ''));
  $password = trim((string)($decoded['password'] ?? ''));
  if ($username === '' || $password === '') {
    return null;
  }
  return [
    'username' => $username,
    'password' => $password
  ];
}

function escape(string $value): string
{
  return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Fortune Number Login</title>
    <link rel="icon" href="data:," />
    <script src="../General%20Setting/general-settings.js"></script>
    <script src="../style/appearance.js"></script>
    <link rel="stylesheet" href="../style/styles.css" />
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
        <div class="brand-logo">FN</div>
        <h1 class="brand-title">Fortune Number</h1>
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
            <input name="password" type="password" placeholder="********" required />
          </label>
          <button type="submit">Sign In</button>
        </form>
      </section>
    </main>
  </body>
</html>
