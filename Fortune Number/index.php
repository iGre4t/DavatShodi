<?php
session_start();

date_default_timezone_set('Asia/Tehran');

if (empty($_SESSION['authenticated'])) {
  header('Location: ' . buildLoginRedirectUrl());
  exit;
}

const STORE_PATH = __DIR__ . '/data/store.json';
const DEFAULT_PANEL_SETTINGS = [
  'panelName' => 'Great Panel',
  'siteIcon' => ''
];
$fnumPath = dirname(__DIR__) . '/data/fortune_number.json';

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

function getPublicBasePath(): string
{
  $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
  if ($scriptName === '') {
    return '';
  }

  $override = getenv('APP_PUBLIC_BASE_PATH');
  if ($override === false && defined('APP_PUBLIC_BASE_PATH')) {
    $override = APP_PUBLIC_BASE_PATH;
  }
  if (is_string($override)) {
    $trimmedOverride = trim($override);
    if ($trimmedOverride !== '') {
      $overridePath = '/' . ltrim($trimmedOverride, '/');
      if ($overridePath === '/') {
        return '';
      }
      return rtrim($overridePath, '/');
    }
  }

  if (preg_match('#^(.*?)/events/[^/]+/draw\\.php$#', $scriptName, $matches)) {
    $candidate = $matches[1];
    if ($candidate === '' || $candidate === '/') {
      return '';
    }
    return rtrim($candidate, '/');
  }

  $dir = dirname($scriptName);
  if ($dir === '/' || $dir === '\\' || $dir === '.') {
    return '';
  }

  return rtrim($dir, '/');
}

function buildLoginRedirectUrl(): string
{
  $basePath = getPublicBasePath();
  if ($basePath === '') {
    return '/login.php';
  }
  return $basePath . '/login.php';
}

function buildPublicAssetUrl(string $path): string
{
  $base = getPublicBasePath();
  $relative = ltrim(str_replace('\\', '/', $path), '/');
  if ($relative === '') {
    return $base === '' ? '' : $base;
  }
  if ($base === '') {
    return '/' . $relative;
  }
  return $base . '/' . $relative;
}

function loadFnumData(string $path): array
{
  $defaults = ['start' => '', 'end' => '', 'winners' => []];
  if (!is_file($path)) {
    return $defaults;
  }
  $content = file_get_contents($path);
  if ($content === false) {
    return $defaults;
  }
  $decoded = json_decode($content, true);
  if (!is_array($decoded)) {
    return $defaults;
  }
  $decoded['start'] = isset($decoded['start']) ? (string)$decoded['start'] : '';
  $decoded['end'] = isset($decoded['end']) ? (string)$decoded['end'] : '';
  $decoded['winners'] = is_array($decoded['winners'] ?? null) ? array_values($decoded['winners']) : [];
  return $decoded;
}

function parseInteger($value): ?int
{
  if (is_int($value)) {
    return $value;
  }
  $text = trim((string)$value);
  if ($text === '' || !preg_match('/^-?\d+$/', $text)) {
    return null;
  }
  return (int)$text;
}

function normalizeRange(array $data): ?array
{
  $start = parseInteger($data['start'] ?? null);
  $end = parseInteger($data['end'] ?? null);
  if ($start === null || $end === null) {
    return null;
  }
  if ($start > $end) {
    [$start, $end] = [$end, $start];
  }
  if ($start < 0 || $end > 9999) {
    return null;
  }
  return ['start' => $start, 'end' => $end];
}

function sanitizeWinners(array $winners, ?array $range): array
{
  if ($range === null) {
    return [];
  }
  $seen = [];
  $clean = [];
  foreach ($winners as $value) {
    $num = parseInteger($value);
    if ($num === null) {
      continue;
    }
    if ($num < $range['start'] || $num > $range['end']) {
      continue;
    }
    if (isset($seen[$num])) {
      continue;
    }
    $seen[$num] = true;
    $clean[] = $num;
  }
  return $clean;
}

function toPersianDigits(string $value): string
{
  $map = [
    '0' => '۰',
    '1' => '۱',
    '2' => '۲',
    '3' => '۳',
    '4' => '۴',
    '5' => '۵',
    '6' => '۶',
    '7' => '۷',
    '8' => '۸',
    '9' => '۹'
  ];
  return strtr($value, $map);
}

function saveFnumData(string $path, string $start, string $end, array $winners): bool
{
  $payload = [
    'start' => $start,
    'end' => $end,
    'winners' => array_values($winners)
  ];
  $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($encoded === false) {
    return false;
  }
  return file_put_contents($path, $encoded, LOCK_EX) !== false;
}

function buildAvailableNumbers(array $range, array $winners): array
{
  $used = array_fill_keys($winners, true);
  $available = [];
  for ($num = $range['start']; $num <= $range['end']; $num += 1) {
    if (!isset($used[$num])) {
      $available[] = $num;
    }
  }
  return $available;
}

$panelSettings = loadPanelSettings();
$pageTitle = (string)($panelSettings['panelName'] ?? DEFAULT_PANEL_SETTINGS['panelName']);
$faviconUrl = formatSiteIconUrlForHtml((string)($panelSettings['siteIcon'] ?? ''));

$fnumData = loadFnumData($fnumPath);
$range = normalizeRange($fnumData);
$winners = sanitizeWinners($fnumData['winners'] ?? [], $range);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
  header('Content-Type: application/json; charset=UTF-8');
  $rawInput = file_get_contents('php://input');
  $payload = json_decode($rawInput ?: '', true);
  $action = is_array($payload) ? (string)($payload['action'] ?? '') : '';

  if ($action === 'reset_winners') {
    $saved = saveFnumData($fnumPath, $fnumData['start'], $fnumData['end'], []);
    if (!$saved) {
      echo json_encode(['status' => 'error', 'message' => 'Unable to reset winners list.']);
      exit;
    }
    echo json_encode([
      'status' => 'ok',
      'message' => 'Winners list cleared.',
      'winners' => []
    ]);
    exit;
  }

  if ($action !== 'draw_number') {
    echo json_encode(['status' => 'error', 'message' => 'Unsupported action.']);
    exit;
  }

  if ($range === null) {
    echo json_encode(['status' => 'error', 'message' => 'Start/end range is invalid.']);
    exit;
  }

  $available = buildAvailableNumbers($range, $winners);
  if (count($available) === 0) {
    echo json_encode(['status' => 'error', 'message' => 'No numbers left to draw.']);
    exit;
  }

  $chosen = $available[array_rand($available)];
  $winners[] = $chosen;
  if (!saveFnumData($fnumPath, $fnumData['start'], $fnumData['end'], $winners)) {
    echo json_encode(['status' => 'error', 'message' => 'Unable to save draw result.']);
    exit;
  }

  echo json_encode([
    'status' => 'ok',
    'number' => $chosen,
    'winners' => $winners,
    'remaining' => count($available) - 1
  ]);
  exit;
}

$fontRegularUrl = htmlspecialchars(buildPublicAssetUrl('style/fonts/PeydaWebFaNum-Regular.woff2'), ENT_QUOTES, 'UTF-8');
$fontBoldUrl = htmlspecialchars(buildPublicAssetUrl('style/fonts/PeydaWebFaNum-Bold.woff2'), ENT_QUOTES, 'UTF-8');

$fnumState = [
  'range' => $range,
  'winners' => $winners
];

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
        src: url('<?= $fontRegularUrl ?>') format('woff2');
      }
      @font-face {
        font-family: 'Peyda';
        font-weight: 700;
        font-style: normal;
        src: url('<?= $fontBoldUrl ?>') format('woff2');
      }

      :root {
        font-family: 'Peyda', 'Segoe UI', Tahoma, Arial, sans-serif;
        color-scheme: dark;
      }

      * {
        box-sizing: border-box;
      }

      body {
        margin: 40px 0 0;
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
        gap: 28px;
        position: relative;
        z-index: 1;
      }

      .code-display {
        display: flex;
        justify-content: center;
        gap: clamp(0.35rem, 1vw, 0.8rem);
        margin: 0 auto;
        direction: ltr;
        unicode-bidi: isolate;
      }

      .code-digit {
        width: clamp(60px, 14vw, 90px);
        height: clamp(80px, 20vw, 120px);
        background: rgba(255, 255, 255, 0.95);
        position: relative;
        border-radius: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-family: 'Peyda', 'Segoe UI', sans-serif;
        font-size: clamp(3.5rem, 8vw, 7rem);
        letter-spacing: 0;
        color: #0042a4;
        font-weight: 700;
        line-height: 1;
        padding-top: clamp(6px, 1.2vw, 12px);
        padding-bottom: clamp(4px, 1vw, 10px);
        box-shadow: inset 0 0 0 1px rgba(4, 12, 38, 0.15);
        transition: background 0.3s ease, color 0.3s ease;
        direction: ltr;
        text-align: center;
      }

      .code-digit::after {
        content: '';
        position: absolute;
        inset: 0;
        border-radius: inherit;
        border: 2px solid rgba(0, 66, 164, 0.3);
        pointer-events: none;
      }

      .code-digit--animating {
        background: linear-gradient(180deg, #d7ecff, #b4d8ff);
        color: #07245d;
      }

      .code-digit--locked {
        background: #173972;
        color: #e9f5ff;
      }

      .caption {
        font-size: 1.1rem;
        letter-spacing: 0.25em;
        color: rgba(255, 255, 255, 0.72);
        margin: 0;
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

      .cta-group button {
        font-family: 'Peyda', 'Segoe UI', Tahoma, Arial, sans-serif;
        letter-spacing: 0.04em;
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

      .winners-panel {
        width: min(540px, 100%);
        border-radius: 28px;
        background: rgba(4, 12, 38, 0.72);
        border: 1px solid rgba(255, 255, 255, 0.08);
        padding: 24px;
        box-shadow: 0 16px 30px rgba(3, 20, 60, 0.4);
        position: relative;
        z-index: 1;
      }

      .winners-panel h3 {
        margin: 0 0 12px;
        font-size: 1rem;
        letter-spacing: 0.2em;
        color: rgba(255, 255, 255, 0.6);
        text-transform: uppercase;
      }

      .winner-items {
        display: flex;
        flex-direction: column;
        gap: 12px;
      }

      .winner-item {
        padding: 14px;
        border-radius: 20px;
        background: rgba(255, 255, 255, 0.04);
        border: 1px solid rgba(255, 255, 255, 0.08);
        display: grid;
        grid-template-columns: auto 1fr;
        gap: 12px;
        align-items: center;
        direction: ltr;
      }

      .winner-code {
        font-size: 1.35rem;
        font-weight: 700;
        letter-spacing: 0.4rem;
        color: #8be1ff;
        direction: ltr;
      }

      .winner-info {
        text-align: right;
        font-size: 1rem;
        direction: rtl;
        color: #ffffff;
        font-weight: 600;
      }

      @media (max-width: 480px) {
        .draw-shell,
        .winners-panel {
          padding: 20px;
        }
        .code-display {
          letter-spacing: 0.6rem;
          font-size: clamp(3.2rem, 20vw, 7rem);
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
        <a class="menu-item active" href="draw.php">قرعه کشی</a>
        <a class="menu-item" href="prizes.php">جوایز مسابقات</a>
      </div>
    </nav>
    <div class="draw-shell" aria-live="polite">
      <p class="caption">قرعه‌کشی مشهد مقدس</p>
      <p id="code-display" class="code-display" aria-live="polite" aria-label="کد قرعه‌کشی فعلی">
        <?php for ($idx = 0; $idx < 4; $idx++): ?>
          <span class="code-digit code-digit--animating" data-index="<?= $idx ?>"></span>
        <?php endfor; ?>
      </p>
      <p id="winner-name" class="winner-message winner-message--idle">برنده قرعه کشی</p>
      <div class="cta-group">
        <button id="start-draw" class="start-btn" type="button">قرعه کشی</button>
      </div>
    </div>
    <div class="winners-panel" aria-live="polite">
      <h3>برندگان</h3>
      <div id="winner-items" class="winner-items"></div>
    </div>

    <script>
      const FNUM_STATE = <?= json_encode($fnumState, JSON_UNESCAPED_UNICODE); ?>;
      const range = (FNUM_STATE && typeof FNUM_STATE === 'object' && FNUM_STATE.range) ? FNUM_STATE.range : null;
      const DRAW_API_PATH = window.location.href;
      const winnersContainer = document.getElementById('winner-items');
      const codeDisplay = document.getElementById('code-display');
      const winnerNameEl = document.getElementById('winner-name');
      const startBtn = document.getElementById('start-draw');
      const digitElements = Array.from(codeDisplay.querySelectorAll('.code-digit'));
      const persianDigits = ['\u06F0', '\u06F1', '\u06F2', '\u06F3', '\u06F4', '\u06F5', '\u06F6', '\u06F7', '\u06F8', '\u06F9'];

      let animationInterval = null;
      let stopTimeouts = [];
      let currentNumber = null;
      let pendingWinners = null;
      let pendingWinnerText = null;
      const pressedShortcutKeys = new Set();
      let resetShortcutLocked = false;
      let winnersList = Array.isArray(FNUM_STATE && FNUM_STATE.winners ? FNUM_STATE.winners : null)
        ? FNUM_STATE.winners.slice()
        : [];

      const toPersianDigits = (value) => (value === null || value === undefined)
        ? ''
        : value.toString().replace(/\d/g, (digit) => persianDigits[digit] || digit);

      const randomDigit = () => Math.floor(Math.random() * 10).toString();

      const normalizeCode = (value) => {
        const text = (value === null || value === undefined) ? '' : value.toString().trim();
        const digits = text.replace(/\D+/g, '');
        if (digits.length === 0) {
          return '0000';
        }
        return digits.slice(-4).padStart(4, '0');
      };

      const defaultLocks = () => Array(4).fill(false);

      const renderDigits = (digits, locks = defaultLocks()) => {
        const normalized = normalizeCode(digits);
        digitElements.forEach((element) => {
          const index = Number(element.dataset.index);
          const char = (normalized[index] === undefined) ? '0' : normalized[index];
          element.textContent = toPersianDigits(char);
          const locked = Boolean(locks[index]);
          element.classList.toggle('code-digit--locked', locked);
          element.classList.toggle('code-digit--animating', !locked);
        });
      };

      const setCode = (value, locks = defaultLocks()) => {
        const normalized = normalizeCode(value);
        renderDigits(normalized, locks);
      };

      const showIdleWinnerText = () => {
        winnerNameEl.textContent = 'برنده قرعه کشی';
        winnerNameEl.classList.add('winner-message--idle');
        winnerNameEl.classList.remove('winner-message--active');
      };

      const setWinnerText = (value) => {
        winnerNameEl.textContent = value;
        winnerNameEl.classList.add('winner-message--active');
        winnerNameEl.classList.remove('winner-message--idle');
      };

      const formatWinnerItem = (entry, index) => {
        const container = document.createElement('div');
        container.className = 'winner-item';
        const codeEl = document.createElement('div');
        codeEl.className = 'winner-code';
        codeEl.textContent = toPersianDigits(normalizeCode(entry));
        const infoEl = document.createElement('div');
        infoEl.className = 'winner-info';
        infoEl.textContent = `برنده ${toPersianDigits(index + 1)}`;
        container.append(codeEl, infoEl);
        return container;
      };

      const renderWinnerList = (items) => {
        winnersList = Array.isArray(items) ? items.slice() : [];
        winnersContainer.innerHTML = '';
        if (!winnersList.length) {
          const placeholder = document.createElement('p');
          placeholder.className = 'status';
          placeholder.textContent = 'هنوز برنده‌ای تایید نشده است';
          winnersContainer.appendChild(placeholder);
          return;
        }
        winnersList.forEach((row, idx) => winnersContainer.appendChild(formatWinnerItem(row, idx)));
      };

      const cancelAnimation = () => {
        if (animationInterval !== null) {
          clearInterval(animationInterval);
          animationInterval = null;
        }
        stopTimeouts.forEach(clearTimeout);
        stopTimeouts = [];
      };

      const flashError = (message) => {
        console.error(message);
        setWinnerText(message);
      };

      const hasRange = () => range && Number.isInteger(range.start) && Number.isInteger(range.end);

      const getRemainingCount = () => {
        if (!hasRange()) {
          return 0;
        }
        const total = range.end - range.start + 1;
        return Math.max(0, total - winnersList.length);
      };

      const startRollingAnimation = (targetDigits) => {
        const digits = normalizeCode(targetDigits).split('');
        const currentDigits = ['0', '0', '0', '0'];
        const locks = [false, false, false, false];
        animationInterval = setInterval(() => {
          for (let i = 0; i < 4; i += 1) {
            if (!locks[i]) {
              currentDigits[i] = randomDigit();
            }
          }
          setCode(currentDigits.join(''), locks);
        }, 90);
        const stopDelays = [1200, 3200, 5200, 7200];
        stopDelays.forEach((delay, index) => {
          const timeout = setTimeout(() => {
            locks[index] = true;
            currentDigits[index] = digits[index];
            setCode(currentDigits.join(''), locks);
            if (index === 3) {
              cancelAnimation();
              startBtn.disabled = getRemainingCount() <= 0;
              if (pendingWinners) {
                renderWinnerList(pendingWinners);
                pendingWinners = null;
              }
              if (pendingWinnerText) {
                setWinnerText(pendingWinnerText);
                pendingWinnerText = null;
              }
            }
          }, delay);
          stopTimeouts.push(timeout);
        });
      };

      const resetWinnersList = async () => {
        cancelAnimation();
        startBtn.disabled = true;
        try {
          const response = await fetch(DRAW_API_PATH, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'reset_winners' })
          });
          const payload = await response.json();
          if (!response.ok || payload.status !== 'ok') {
            throw new Error((payload && payload.message) ? payload.message : 'Unable to reset winners list.');
          }
          winnersList = [];
          currentNumber = null;
          showIdleWinnerText();
          setCode('0000');
          renderWinnerList(payload.winners || []);
          startBtn.disabled = !hasRange();
        } catch (error) {
          flashError('Unable to reset winners list.');
          startBtn.disabled = getRemainingCount() <= 0 || !hasRange();
        }
      };

      setCode('0000');
      showIdleWinnerText();
      renderWinnerList(winnersList);

      startBtn.addEventListener('click', async () => {
        if (!hasRange()) {
          flashError('Start/end range is not set.');
          startBtn.disabled = true;
          return;
        }
        if (getRemainingCount() <= 0) {
          startBtn.disabled = true;
          return;
        }
        cancelAnimation();
        startBtn.disabled = true;
        showIdleWinnerText();
        animationInterval = setInterval(() => {
          renderDigits(Array.from({ length: 4 }, randomDigit).join(''));
        }, 90);
        try {
          const response = await fetch(DRAW_API_PATH, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'draw_number' })
          });
          const data = await response.json();
          if (!response.ok || data.status !== 'ok') {
            throw new Error((data && data.message) ? data.message : 'Draw failed.');
          }
          currentNumber = data.number;
          winnersList = Array.isArray(data.winners) ? data.winners : winnersList;
          cancelAnimation();
          startRollingAnimation(currentNumber);
          pendingWinnerText = `برنده: ${toPersianDigits(normalizeCode(currentNumber))}`;
          pendingWinners = winnersList.slice();
        } catch (error) {
          cancelAnimation();
          flashError((error && error.message) ? error.message : 'Draw failed.');
          startBtn.disabled = getRemainingCount() <= 0;
        }
      });

      document.addEventListener('keydown', (event) => {
        pressedShortcutKeys.add(event.code);
        const targetTag = event.target && event.target.tagName ? event.target.tagName : '';
        if (['INPUT', 'TEXTAREA'].includes(targetTag)) {
          return;
        }
        if (event.code === 'Enter') {
          if (!startBtn.disabled) {
            startBtn.click();
          }
        }
        if (!resetShortcutLocked && pressedShortcutKeys.has('Numpad8') && pressedShortcutKeys.has('Numpad9')) {
          resetShortcutLocked = true;
          event.preventDefault();
          resetWinnersList();
        }
      });

      document.addEventListener('keyup', (event) => {
        pressedShortcutKeys.delete(event.code);
        if (event.code === 'Numpad8' || event.code === 'Numpad9') {
          resetShortcutLocked = false;
        }
      });

      if (!hasRange() || getRemainingCount() <= 0) {
        startBtn.disabled = true;
      }
    </script>
  </body>
</html>
