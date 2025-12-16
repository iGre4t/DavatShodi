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
    <script>
      (function applyStoredAppearance() {
        const defaults = {
          primary: "#e11d2e",
          background: "#ffffff",
          text: "#111111",
          toggle: "#e11d2e"
        };
        const storageKeys = {
          primary: "frontend_appearance_primary",
          background: "frontend_appearance_background",
          text: "frontend_appearance_text",
          toggle: "frontend_appearance_toggle"
        };
        const isHex = (value = "") => /^#(?:[0-9a-fA-F]{3}){1,2}$/.test(value.trim());
        const getHex = (key) => {
          try {
            const raw = localStorage.getItem(key) || "";
            const trimmed = raw.trim();
            return isHex(trimmed) ? trimmed : "";
          } catch (_) {
            return "";
          }
        };
        const hexToRgb = (hex) => {
          if (!isHex(hex)) return null;
          const normalized = hex.length === 4
            ? `#${hex[1]}${hex[1]}${hex[2]}${hex[2]}${hex[3]}${hex[3]}`
            : hex;
          const int = parseInt(normalized.slice(1), 16);
          return {
            r: (int >> 16) & 255,
            g: (int >> 8) & 255,
            b: int & 255
          };
        };
        const rgbToHsl = (r, g, b) => {
          r /= 255; g /= 255; b /= 255;
          const max = Math.max(r, g, b), min = Math.min(r, g, b);
          const delta = max - min;
          let h = 0, s = 0;
          const l = (max + min) / 2;
          if (delta !== 0) {
            s = l > 0.5 ? delta / (2 - max - min) : delta / (max + min);
            switch (max) {
              case r: h = (g - b) / delta + (g < b ? 6 : 0); break;
              case g: h = (b - r) / delta + 2; break;
              default: h = (r - g) / delta + 4; break;
            }
            h /= 6;
          }
          return { h, s, l };
        };
        const hslToHex = ({ h, s, l }) => {
          const hue = h * 6;
          const c = (1 - Math.abs(2 * l - 1)) * s;
          const x = c * (1 - Math.abs((hue % 2) - 1));
          const m = l - c / 2;
          let r = 0, g = 0, b = 0;
          if (hue >= 0 && hue < 1) { r = c; g = x; }
          else if (hue < 2) { r = x; g = c; }
          else if (hue < 3) { g = c; b = x; }
          else if (hue < 4) { g = x; b = c; }
          else if (hue < 5) { r = x; b = c; }
          else { r = c; b = x; }
          const toHex = (v) => {
            const hex = Math.round((v + m) * 255).toString(16).padStart(2, "0");
            return hex;
          };
          return `#${toHex(r)}${toHex(g)}${toHex(b)}`;
        };
        const adjustHexLightness = (hex, delta) => {
          const rgb = hexToRgb(hex);
          if (!rgb) return "";
          const hsl = rgbToHsl(rgb.r, rgb.g, rgb.b);
          hsl.l = Math.max(0, Math.min(1, hsl.l + delta));
          return hslToHex(hsl);
        };
        const hexToRgba = (hex, alpha) => {
          const rgb = hexToRgb(hex);
          return rgb ? `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${alpha})` : "";
        };
        const root = document.documentElement;
        const primary = getHex(storageKeys.primary) || defaults.primary;
        const bg = getHex(storageKeys.background) || defaults.background;
        const text = getHex(storageKeys.text) || defaults.text;
        const toggle = getHex(storageKeys.toggle) || defaults.toggle;
        root.style.setProperty("--primary", primary);
        root.style.setProperty("--primary-600", adjustHexLightness(primary, -0.18) || primary);
        root.style.setProperty("--bg", bg);
        root.style.setProperty("--text", text);
        const toggleBg = hexToRgba(toggle, 0.12) || "rgba(225, 29, 46, 0.08)";
        const toggleBorder = hexToRgba(toggle, 0.22) || "rgba(225, 29, 46, 0.22)";
        root.style.setProperty("--sidebar-active", toggleBg);
        root.style.setProperty("--sidebar-active-border", toggleBorder);
      })();
    </script>
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
