<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/api/lib/common.php';

if (databaseConfigIsComplete()) {
    header('Location: login.php');
    exit;
}

$errors = [];
$flash = $_SESSION['install_notice'] ?? '';
unset($_SESSION['install_notice']);

$storedConfig = loadDatabaseConfigOverrides();
$storedDbName = trim((string)($storedConfig['dbname'] ?? ''));
$storedUser = trim((string)($storedConfig['user'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbname = trim($_POST['dbname'] ?? '');
    $user = trim($_POST['user'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($dbname === '' || $user === '' || $password === '') {
        $errors[] = 'Database name, username, and password are required.';
    } else {
        $payload = sanitizeDatabaseConfigPayload([
            'host' => '127.0.0.1',
            'port' => 3306,
            'dbname' => $dbname,
            'user' => $user,
            'password' => $password,
            'table' => 'great_panel_store',
            'record' => 'store'
        ]);

        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;charset=utf8mb4',
                $payload['host'],
                $payload['port']
            );
            $pdo = new PDO($dsn, $payload['user'], $payload['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            setupPanelSchema($pdo, $payload['dbname'], $payload['table']);
            if (!persistDatabaseConfigOverrides($payload)) {
                $errors[] = 'Failed to save the database configuration.';
            } else {
                markInstallComplete();
                $_SESSION['install_notice'] = 'Database connected. Please sign in.';
                header('Location: login.php');
                exit;
            }
        } catch (PDOException $exception) {
            $errors[] = 'Database connection failed: ' . $exception->getMessage();
        }
    }
}

function setupPanelSchema(PDO $pdo, string $dbname, string $storeTable): void
{
    $charset = 'utf8mb4';
    $collation = 'utf8mb4_unicode_ci';
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}` CHARACTER SET {$charset} COLLATE {$collation}");
    $pdo->exec("USE `{$dbname}`");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$storeTable}` (
      `id` varchar(64) NOT NULL,
      `payload` longtext NOT NULL,
      `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `gallery_category` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `name` VARCHAR(128) NOT NULL,
      `slug` VARCHAR(64) NOT NULL,
      `mother_category_id` INT UNSIGNED DEFAULT NULL,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uniq_gallery_category_slug` (`slug`),
      KEY `idx_gallery_category_mother` (`mother_category_id`),
      CONSTRAINT `fk_gallery_category_mother` FOREIGN KEY (`mother_category_id`)
        REFERENCES `gallery_category` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `gallery` (
      `photo_id` INT(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
      `filename` VARCHAR(255) NOT NULL,
      `category_id` INT UNSIGNED DEFAULT NULL,
      `title` VARCHAR(255) NOT NULL,
      `alt_text` VARCHAR(255) NOT NULL,
      `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`photo_id`),
      KEY `idx_gallery_category` (`category_id`),
      CONSTRAINT `fk_gallery_category` FOREIGN KEY (`category_id`) REFERENCES `gallery_category` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
      `code` VARCHAR(16) NOT NULL,
      `username` VARCHAR(64) NOT NULL,
      `fullname` VARCHAR(128) NOT NULL DEFAULT '0',
      `phone` CHAR(11) NOT NULL,
      `email` VARCHAR(255) NOT NULL,
      `id_number` CHAR(10) NOT NULL DEFAULT '0000000000',
      `work_id` VARCHAR(64) NOT NULL DEFAULT '0',
      `password_hash` VARCHAR(255) NOT NULL,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`code`),
      UNIQUE KEY `uniq_username` (`username`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
}

function getFieldValue(array $values, string $key, string $default = ''): string
{
    return htmlspecialchars($values[$key] ?? $default, ENT_QUOTES, 'UTF-8');
}

?>
<!doctype html>
<html lang="fa" dir="rtl">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Installation</title>
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
        width: min(520px, 95vw);
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
        margin: 6px 0 14px;
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

      .alert.success {
        background: #ecfdf3;
        color: #047857;
      }

      .install-form {
        display: grid;
        gap: 16px;
        margin-top: 10px;
      }

      .field span {
        font-size: 14px;
        color: #6b7280;
        display: block;
        text-align: right;
        margin-bottom: 6px;
      }

      .install-form input {
        width: 100%;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: 14px 16px;
        font-size: 1rem;
        background: #fdfdfd;
        transition: all 0.2s ease;
      }

      .install-form input:focus {
        border-color: #e11d2e;
        box-shadow: 0 0 0 3px rgba(225, 29, 46, 0.18);
        outline: none;
      }

      .install-form button {
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

      .install-form button:hover {
        background: #c51625;
      }

      .hint {
        font-size: 0.95rem;
        color: #6b7280;
        text-align: right;
        margin-top: 6px;
      }
    </style>
  </head>
  <body class="login-body">
    <main>
      <section class="login-card">
        <div class="brand-logo">GN</div>
        <h1 class="brand-title">Installation wizard</h1>
        <p class="brand-subtitle">Provide the database credentials only</p>
        <?php if ($flash): ?>
          <div class="alert success" role="status" aria-live="polite">
            <?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endif; ?>
        <?php if ($errors): ?>
          <div class="alert" role="alert" aria-live="assertive">
            <ul>
              <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <form method="post" class="install-form" novalidate>
          <label class="field">
            <span>Database name</span>
            <input name="dbname" type="text" value="<?= htmlspecialchars($storedDbName, ENT_QUOTES, 'UTF-8') ?>" required />
          </label>
          <label class="field">
            <span>Username</span>
            <input name="user" type="text" value="<?= htmlspecialchars($storedUser, ENT_QUOTES, 'UTF-8') ?>" required />
          </label>
          <label class="field">
            <span>Password</span>
            <input name="password" type="password" autocomplete="new-password" required />
          </label>
          <button type="submit">Save database settings</button>
        </form>
      </section>
    </main>
  </body>
</html>
