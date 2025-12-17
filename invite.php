<?php
session_start();

require_once __DIR__ . '/api/lib/common.php';
require_once __DIR__ . '/api/lib/users.php';

const DEFAULT_PANEL_SETTINGS = [
  'title' => 'Great Panel',
  'timezone' => 'Asia/Tehran',
  'panelName' => 'Panel in progress',
  'siteIcon' => '',
  'backupSettings' => [
    'autoIntervalMinutes' => 0,
    'autoLimit' => 0,
    'lastAutoBackupAt' => null
  ]
];

function formatSiteIconUrlForHtml($value = '') {
  $trimmed = trim((string)$value);
  if ($trimmed === '') {
    return '';
  }
  if (preg_match('/^(?:data:|https?:\\/\\/|\\/\\/)/i', $trimmed)) {
    return $trimmed;
  }
  if (strncmp($trimmed, '/', 1) === 0 || strncmp($trimmed, './', 2) === 0 || strncmp($trimmed, '../', 3) === 0) {
    return $trimmed;
  }
  return "./{$trimmed}";
}

function loadJsonPayload(string $path): array {
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

function loadPanelSettings(): array {
  $data = loadJsonPayload(__DIR__ . '/data/store.json');
  $config = loadConfig(__DIR__ . '/api/config.php');
  $pdo = connectDatabase($config);
  if ($pdo) {
    $dbData = loadDataFromDb($pdo, $config);
    if (is_array($dbData)) {
      $data = $dbData;
    }
  }
  $settings = [];
  if (isset($data['settings']) && is_array($data['settings'])) {
    $settings = $data['settings'];
  }
  return array_merge(DEFAULT_PANEL_SETTINGS, $settings);
}

if (empty($_SESSION['authenticated'])) {
  header('Location: login.php');
  exit;
}

$panelSettings = loadPanelSettings();
$panelTitle = $panelSettings['panelName'] ?? DEFAULT_PANEL_SETTINGS['panelName'];
if (!is_string($panelTitle) || $panelTitle === '') {
  $panelTitle = DEFAULT_PANEL_SETTINGS['panelName'];
}
$panelSiteIconUrl = formatSiteIconUrlForHtml($panelSettings['siteIcon'] ?? '');
?>

<!doctype html>
<html lang="fa" dir="rtl">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Invite | <?= htmlspecialchars($panelTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="color-scheme" content="light" />
    <script src="General%20Setting/general-settings.js"></script>
    <script src="style/appearance.js"></script>
    <link rel="icon" id="site-icon-link" href="<?= htmlspecialchars($panelSiteIconUrl ?: 'data:,', ENT_QUOTES, 'UTF-8') ?>" />
    <link rel="preload" href="style/fonts/remixicon.woff2" as="font" type="font/woff2" crossorigin="anonymous" />
    <link rel="stylesheet" href="style/styles.css" />
    <link rel="stylesheet" href="style/remixicon.css" />
    <style>
      /* Keep the invite tab visible in standalone mode. */
      #tab-invite { display: block; }
      body.invite-page {
        display: flex;
        justify-content: center;
        padding: 20px 0;
      }
      body.invite-page main.content {
        width: 100%;
        min-height: 100vh;
        height: auto;
      }
      body.invite-page main.content > header {
        width: 100%;
      }
      .invite-panel-shell {
        width: clamp(320px, 45vw, 640px);
        margin: 0 auto;
      }
    </style>
  </head>
  <body class="invite-page">
    <main class="content">
      <header class="topbar">
        <h2 id="page-title">Invite</h2>
        <div class="spacer"></div>
        <div id="live-clock" class="clock" aria-live="polite">
          <span class="time"></span>
          <span class="date"></span>
        </div>
      </header>

      <div class="invite-panel-shell">
        <?php include __DIR__ . '/invitepanel.php'; ?>
      </div>
    </main>

    <script>
      (function activateInviteTab() {
        document.getElementById("tab-invite")?.classList.add("active");
      })();

      (function startClock() {
        const clock = document.getElementById("live-clock");
        if (!clock) return;
        const timeEl = clock.querySelector(".time");
        const dateEl = clock.querySelector(".date");

        function render() {
          const now = new Date();
          if (timeEl) {
            const hh = String(now.getHours()).padStart(2, "0");
            const mm = String(now.getMinutes()).padStart(2, "0");
            const ss = String(now.getSeconds()).padStart(2, "0");
            timeEl.textContent = `${hh}:${mm}:${ss}`;
          }
          if (dateEl) {
            dateEl.textContent = now.toLocaleDateString();
          }
        }

        render();
        setInterval(render, 1000);
      })();
    </script>
  </body>
</html>
