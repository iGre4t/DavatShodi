<?php
session_start();

date_default_timezone_set('Asia/Tehran');

if (empty($_SESSION['authenticated'])) {
  header('Location: login.php');
  exit;
}

const STORE_PATH = __DIR__ . '/data/store.json';
const DEFAULT_PANEL_SETTINGS = [
  'panelName' => 'Great Panel',
  'siteIcon' => ''
];
const PRIZE_LIST_PATH = __DIR__ . '/prizelist.csv';

function loadPanelSettings(): array
{
  $payload = loadJsonPayload(STORE_PATH);
  $settings = is_array($payload['settings'] ?? null) ? $payload['settings'] : [];
  return array_merge(DEFAULT_PANEL_SETTINGS, $settings);
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

function formatSiteIconUrlForHtml(string $value): string
{
  $trimmed = trim($value);
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

function loadPrizeList(string $path): array
{
  if (!is_file($path)) {
    return [];
  }
  $handle = fopen($path, 'r');
  if ($handle === false) {
    return [];
  }
  $list = [];
  fgetcsv($handle);
  while (($row = fgetcsv($handle)) !== false) {
    if (!is_array($row)) {
      continue;
    }
    $name = trim((string)($row[1] ?? $row[0] ?? ''));
    if ($name === '') {
      continue;
    }
    $list[] = [
      'id' => (int)($row[0] ?? 0),
      'name' => $name
    ];
  }
  fclose($handle);
  usort($list, static fn ($a, $b) => ($a['id'] ?? 0) <=> ($b['id'] ?? 0));
  return array_values($list);
}

$panelSettings = loadPanelSettings();
$pageTitle = (string)($panelSettings['panelName'] ?? DEFAULT_PANEL_SETTINGS['panelName']);
$faviconUrl = formatSiteIconUrlForHtml((string)($panelSettings['siteIcon'] ?? ''));
$prizeList = loadPrizeList(PRIZE_LIST_PATH);

?>
<!doctype html>
<html lang="fa" dir="rtl">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" href="<?= htmlspecialchars($faviconUrl ?: 'data:,', ENT_QUOTES, 'UTF-8') ?>" />
    <style>
      @font-face {
        font-family: 'Peyda';
        font-weight: 400;
        font-style: normal;
        src: url('style/fonts/PeydaWebFaNum-Regular.woff2') format('woff2');
      }
      @font-face {
        font-family: 'Peyda';
        font-weight: 700;
        font-style: normal;
        src: url('style/fonts/PeydaWebFaNum-Bold.woff2') format('woff2');
      }

      :root {
        font-family: 'Peyda', 'Segoe UI', Tahoma, Arial, sans-serif;
        color-scheme: dark;
      }

      * {
        box-sizing: border-box;
      }

      body {
        margin: 0;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: flex-start;
        background: radial-gradient(circle at top, #7cb7ff, #1a3edb 55%, #07103b 100%);
        color: #f0f8ff;
        text-align: center;
        padding: 32px 16px 48px;
        flex-direction: column;
        gap: 24px;
        position: relative;
        overflow-x: hidden;
      }

      .background-icon {
        position: fixed;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        pointer-events: none;
        z-index: 0;
        opacity: 0.25;
        padding: 0 17.5vw;
      }

      .background-icon svg {
        width: 100%;
        max-width: 65vw;
        height: auto;
        filter: drop-shadow(0 24px 48px rgba(3, 9, 43, 0.5));
      }

      .icon-outline {
        fill: none;
        stroke: rgba(255, 255, 255, 0.45);
        stroke-width: 10;
        stroke-linecap: round;
        stroke-linejoin: round;
        stroke-dasharray: 2000;
        stroke-dashoffset: 2000;
        animation: draw-icon 5s ease-in-out infinite alternate;
      }

      @keyframes draw-icon {
        to {
          stroke-dashoffset: 0;
          stroke: rgba(255, 255, 255, 0.9);
        }
      }

      .page-header {
        width: min(640px, 100%);
        display: flex;
        justify-content: center;
        align-items: center;
        position: relative;
        z-index: 2;
      }

      .page-menu {
        display: flex;
        gap: 24px;
        padding: 12px 24px;
        background: rgba(255, 255, 255, 0.05);
        border-radius: 999px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(6px);
        z-index: 1;
      }

      .menu-item {
        color: #f5f5f7;
        text-decoration: none;
        font-size: 1rem;
        font-weight: 600;
        letter-spacing: 0.08em;
        padding: 4px 16px;
        border-radius: 999px;
        transition: background 0.2s ease, color 0.2s ease;
      }

      .menu-item.active {
        background: rgba(255, 255, 255, 0.2);
        color: #ffffff;
      }

      .menu-item:hover:not(.active) {
        background: rgba(255, 255, 255, 0.08);
      }

      .draw-shell {
        width: min(540px, 100%);
        padding: 32px;
        border-radius: 32px;
        background: linear-gradient(180deg, rgba(20, 35, 67, 0.92), rgba(6, 21, 57, 0.98));
        border: 1px solid rgba(255, 255, 255, 0.12);
        box-shadow: 0 16px 40px rgba(3, 20, 60, 0.55);
        display: flex;
        flex-direction: column;
        gap: 24px;
        position: relative;
        z-index: 1;
      }

      .caption {
        font-size: 1.1rem;
        letter-spacing: 0.25em;
        color: rgba(255, 255, 255, 0.72);
        margin: 0;
      }

      .prize-display {
        min-height: clamp(110px, 14vw, 160px);
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
      }

      .prize-value {
        font-size: clamp(1.8rem, 4vw, 3rem);
        font-weight: 700;
        letter-spacing: 0.2em;
        color: #cde6ff;
        background: rgba(255, 255, 255, 0.08);
        border-radius: 24px;
        padding: 20px 34px;
        border: 1px solid rgba(255, 255, 255, 0.25);
        min-width: clamp(220px, 40vw, 360px);
        line-height: 1.2;
        white-space: nowrap;
        direction: rtl;
        transition: background 0.3s ease, color 0.3s ease, transform 0.3s ease;
      }

      .prize-value--animating {
        background: linear-gradient(180deg, #d7ecff, #b4d8ff);
        color: #07245d;
        transform: scale(1.02);
      }

      .prize-value--locked {
        background: #173972;
        color: #e9f5ff;
        border-color: rgba(255, 255, 255, 0.4);
        box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.08);
      }

      .winner-message {
        font-size: 1.6rem;
        margin: 0;
        letter-spacing: 0.02em;
      }

      .winner-message--idle {
        color: rgba(205, 230, 255, 0.6);
      }

      .winner-message--active {
        color: #cde6ff;
      }

      .cta-group {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 16px;
      }

      button {
        border: none;
        border-radius: 999px;
        padding: 14px 28px;
        font-size: 1rem;
        font-weight: 700;
        cursor: pointer;
        transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease;
      }

      button:disabled {
        opacity: 0.35;
        cursor: not-allowed;
        transform: none;
        box-shadow: none;
      }

      .start-btn {
        background: linear-gradient(135deg, #32c5ff, #0b74ff);
        color: #00112a;
        box-shadow: 0 12px 24px rgba(11, 116, 255, 0.45);
      }

      .start-btn:hover:not(:disabled) {
        transform: translateY(-2px);
      }

      .status {
        font-size: 0.85rem;
        color: rgba(255, 255, 255, 0.8);
        letter-spacing: 0.2em;
        margin: 0;
      }

      @media (max-width: 480px) {
        .draw-shell {
          padding: 20px;
        }
        .prize-value {
          font-size: clamp(1.8rem, 10vw, 2.8rem);
          padding: 16px 24px;
        }
      }

      @media (max-width: 480px) {
        .draw-shell,
        .winners-panel {
          padding: 20px;
        }
        .prize-value {
          font-size: clamp(1.8rem, 10vw, 2.8rem);
          padding: 16px 24px;
        }
      }
    </style>
  </head>
  <body>
    <div class="background-icon" aria-hidden="true">
      <svg viewBox="0 0 1173 773" role="presentation" xmlns="http://www.w3.org/2000/svg">
        <path class="icon-outline" d="M1173 407.266V773C791.7 589.486 381.3 521.402 0 573.591V16.8977C319.721 -26.5479 659.341 13.9796 985.446 136.213C1099.03 178.364 1173 286.979 1173 406.947V407.266Z" />
      </svg>
    </div>
    <nav class="page-header">
      <div class="page-menu">
        <a class="menu-item" href="draw.php">&#x0642;&#x0631;&#x0639;&#x0647; &#x06A9;&#x0634;&#x06CC;</a>
        <a class="menu-item active" href="prizes.php">&#x062C;&#x0648;&#x0627;&#x06CC;&#x0632; &#x0645;&#x0633;&#x0627;&#x0628;&#x0642;&#x0627;&#x062A;</a>
      </div>
    </nav>
    <div class="draw-shell" aria-live="polite">
      <p class="caption">قرعه‌کشی جوایز برندگان مسابقات</p>
      <div id="prize-display" class="prize-display" aria-live="polite" aria-label="Prize reveal">
        <span id="prize-value" class="prize-value">---</span>
      </div>
      <p id="winner-name" class="winner-message winner-message--idle">waiting for new selection</p>
      <div class="cta-group">
        <button id="start-draw" class="start-btn" type="button">start draw</button>
      </div>
    </div>
    <script>
      window.__PRIZE_LIST = window.__PRIZE_LIST || <?= json_encode($prizeList, JSON_UNESCAPED_UNICODE); ?>;
      const prizeList = Array.isArray(window.__PRIZE_LIST) ? window.__PRIZE_LIST : [];
      const prizeValueEl = document.getElementById('prize-value');
      const winnerMessageEl = document.getElementById('winner-name');
      const startBtn = document.getElementById('start-draw');
      let animationInterval = null;
      let revealTimeout = null;
      let selectedPrizeName = '';

      const resetValueState = () => {
        prizeValueEl.classList.remove('prize-value--animating', 'prize-value--locked');
      };

      const setAnimatingState = () => {
        prizeValueEl.classList.add('prize-value--animating');
        prizeValueEl.classList.remove('prize-value--locked');
      };

      const setLockedState = () => {
        prizeValueEl.classList.remove('prize-value--animating');
        prizeValueEl.classList.add('prize-value--locked');
      };

      const cancelAnimation = () => {
        if (animationInterval) {
          clearInterval(animationInterval);
          animationInterval = null;
        }
        if (revealTimeout) {
          clearTimeout(revealTimeout);
          revealTimeout = null;
        }
        resetValueState();
      };

      const randomPrizeName = () => {
        if (!prizeList.length) {
          return '';
        }
        const idx = Math.floor(Math.random() * prizeList.length);
        return prizeList[idx]?.name || '';
      };

      const showIdleState = () => {
        prizeValueEl.textContent = '---';
        winnerMessageEl.textContent = 'ready for a new draw';
        winnerMessageEl.classList.add('winner-message--idle');
        winnerMessageEl.classList.remove('winner-message--active');
        resetValueState();
      };

      startBtn.addEventListener('click', () => {
        if (!prizeList.length) {
        return;
      }
        cancelAnimation();
        startBtn.disabled = true;
        winnerMessageEl.textContent = 'selecting a prize...';
        winnerMessageEl.classList.remove('winner-message--idle');
        setAnimatingState();
        selectedPrizeName = randomPrizeName();
        animationInterval = setInterval(() => {
          prizeValueEl.textContent = randomPrizeName() || '---';
        }, 90);
        const revealDelay = 2600 + Math.random() * 1400;
        revealTimeout = setTimeout(() => {
          cancelAnimation();
          prizeValueEl.textContent = selectedPrizeName || '---';
          if (selectedPrizeName) {
            winnerMessageEl.textContent = `prize picked: ${selectedPrizeName}`;
            winnerMessageEl.classList.add('winner-message--active');
            winnerMessageEl.classList.remove('winner-message--idle');
            setLockedState();
          } else {
            winnerMessageEl.textContent = 'no prize picked';
            winnerMessageEl.classList.add('winner-message--idle');
            resetValueState();
          }
          startBtn.disabled = false;
        }, revealDelay);
      });

      document.addEventListener('keydown', (event) => {
        if (event.target && ['INPUT', 'TEXTAREA'].includes(event.target.tagName)) {
          return;
        }
        if (event.code === 'Enter' && !startBtn.disabled) {
          event.preventDefault();
          startBtn.click();
        }
      });

      if (!prizeList.length) {
        startBtn.disabled = true;
      }

      showIdleState();
    </script>
  </body>
</html>
