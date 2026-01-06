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

$panelSettings = loadPanelSettings();
$pageTitle = (string)($panelSettings['panelName'] ?? DEFAULT_PANEL_SETTINGS['panelName']);
$faviconUrl = formatSiteIconUrlForHtml((string)($panelSettings['siteIcon'] ?? ''));
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
        src: url('<?= htmlspecialchars(buildPublicAssetUrl('style/fonts/PeydaWebFaNum-Regular.woff2'), ENT_QUOTES, 'UTF-8') ?>') format('woff2');
      }
      @font-face {
        font-family: 'Peyda';
        font-weight: 700;
        font-style: normal;
        src: url('<?= htmlspecialchars(buildPublicAssetUrl('style/fonts/PeydaWebFaNum-Bold.woff2'), ENT_QUOTES, 'UTF-8') ?>') format('woff2');
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
        width: min(520px, 100%);
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
        font-size: clamp(3rem, 7vw, 5rem);
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

      .cta-group {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 16px;
      }

      button,
      .start-btn,
      .confirm-btn {
        font-family: 'Peyda', 'Segoe UI', Tahoma, Arial, sans-serif;
        border: none;
        border-radius: 999px;
        padding: 14px 32px;
        font-size: 1rem;
        font-weight: 700;
        cursor: pointer;
        transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease;
      }

      button:disabled,
      .start-btn:disabled,
      .confirm-btn:disabled {
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

      .confirm-btn {
        background: transparent;
        color: #a8e0ff;
        border: 1px solid rgba(168, 224, 255, 0.6);
        box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.2);
      }

      .confirm-btn:hover:not(:disabled) {
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
        <a class="menu-item active" href="numberpicker.php">قرعه کشی اعداد</a>
      </div>
    </nav>
    <div class="draw-shell" aria-live="polite">
      <p class="caption">قرعه کشی مشهد مقدس</p>
      <p id="code-display" class="code-display" aria-live="polite" aria-label="نمایش شماره انتخابی">
        <?php for ($idx = 0; $idx < 3; $idx++): ?>
          <span class="code-digit code-digit--animating" data-index="<?= $idx ?>"></span>
        <?php endfor; ?>
      </p>
      <p id="status-text" class="winner-message winner-message--idle">برای شروع قرعه‌کشی را بزنید</p>
      <div class="cta-group">
        <button id="start-draw" class="start-btn" type="button">قرعه کشی</button>
        <button id="confirm-number" class="confirm-btn" type="button" disabled>ذخیره</button>
      </div>
    </div>
    <div class="winners-panel" aria-live="polite">
      <h3>شماره‌های ذخیره‌شده</h3>
      <div id="number-items" class="winner-items"></div>
    </div>

    <script>
      const MIN_NUMBER = 1;
      const MAX_NUMBER = 300;
      const DIGIT_COUNT = 3;
      const TOTAL_NUMBERS = MAX_NUMBER - MIN_NUMBER + 1;
      const STORAGE_KEY = 'numberpicker-saved-numbers';
      const codeDisplay = document.getElementById('code-display');
      const statusText = document.getElementById('status-text');
      const startBtn = document.getElementById('start-draw');
      const confirmBtn = document.getElementById('confirm-number');
      const numberItems = document.getElementById('number-items');
      const digitElements = Array.from(codeDisplay.querySelectorAll('.code-digit'));
      const pressedShortcutKeys = new Set();
      let resetShortcutLocked = false;

      const loadSavedNumbers = () => {
        try {
          const payload = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
          return Array.isArray(payload) ? payload : [];
        } catch (error) {
          console.error('Failed to read saved numbers from storage.', error);
          return [];
        }
      };

      const persistSavedNumbers = (list) => {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(list));
      };

      let animationInterval = null;
      let stopTimeouts = [];
      let currentNumber = null;
      let savedNumbers = loadSavedNumbers();
      const randomDigit = () => Math.floor(Math.random() * 10).toString();

      const remainingNumbers = () => {
        const used = new Set(savedNumbers.map((entry) => entry.number));
        const pool = [];
        for (let num = MIN_NUMBER; num <= MAX_NUMBER; num += 1) {
          if (!used.has(num)) {
            pool.push(num);
          }
        }
        return pool;
      };

      const generateUniqueNumber = () => {
        const pool = remainingNumbers();
        if (!pool.length) {
          return null;
        }
        return pool[Math.floor(Math.random() * pool.length)];
      };

      const isNumberSaved = (value) => savedNumbers.some((entry) => entry.number === value);

      const updateDrawAvailability = () => {
        if (remainingNumbers().length === 0) {
          startBtn.disabled = true;
          confirmBtn.disabled = true;
          statusText.textContent = 'تمام شماره‌ها انتخاب شده‌اند';
          statusText.classList.add('winner-message--active');
          statusText.classList.remove('winner-message--idle');
        }
      };

      const padDigits = (value) => {
        const cleaned = (value ?? '').toString().replace(/\D+/g, '');
        const normalized = cleaned === '' ? ''.padStart(DIGIT_COUNT, '0') : cleaned;
        if (normalized.length >= DIGIT_COUNT) {
          return normalized.slice(-DIGIT_COUNT);
        }
        return normalized.padStart(DIGIT_COUNT, '0');
      };

      const formatNumberDigits = (value) => {
        const normalized = Number.isNaN(Number(value)) ? MIN_NUMBER : Math.max(MIN_NUMBER, Math.min(MAX_NUMBER, Math.floor(Number(value))));
        return normalized.toString().padStart(DIGIT_COUNT, '0');
      };

      const defaultLocks = () => Array(DIGIT_COUNT).fill(false);

      const renderDigits = (digits, locks = defaultLocks()) => {
        const normalized = padDigits(digits);
        digitElements.forEach((element) => {
          const index = Number(element.dataset.index);
          const char = normalized[index] ?? '0';
          element.textContent = char;
          const locked = Boolean(locks[index]);
          element.classList.toggle('code-digit--locked', locked);
          element.classList.toggle('code-digit--animating', !locked);
        });
      };

      const showIdleText = () => {
        statusText.textContent = 'برای شروع قرعه‌کشی را بزنید';
        statusText.classList.add('winner-message--idle');
        statusText.classList.remove('winner-message--active');
      };

      const setNumberText = (value) => {
        statusText.textContent = `شماره انتخاب‌شده: ${formatNumberDigits(value)}`;
        statusText.classList.add('winner-message--active');
        statusText.classList.remove('winner-message--idle');
      };

      const renderNumberItem = (entry) => {
        const container = document.createElement('div');
        container.className = 'winner-item';
        const codeEl = document.createElement('div');
        codeEl.className = 'winner-code';
        codeEl.textContent = formatNumberDigits(entry.number);
        const infoEl = document.createElement('div');
        infoEl.className = 'winner-info';
        infoEl.textContent = 'شماره برنده';
        container.append(codeEl, infoEl);
        return container;
      };

      const clearSavedNumbers = () => {
        savedNumbers = [];
        persistSavedNumbers(savedNumbers);
        renderNumberList(savedNumbers);
        showIdleText();
        startBtn.disabled = false;
        confirmBtn.disabled = true;
        updateDrawAvailability();
      };

      const renderNumberList = (items) => {
        const list = Array.isArray(items) ? items.slice() : [];
        numberItems.innerHTML = '';
        if (!list.length) {
          const placeholder = document.createElement('p');
          placeholder.className = 'status';
          placeholder.textContent = 'هنوز عددی ذخیره نشده است.';
          numberItems.appendChild(placeholder);
          return;
        }
        list.forEach((row) => numberItems.appendChild(renderNumberItem(row)));
      };

      const cancelAnimation = () => {
        if (animationInterval !== null) {
          clearInterval(animationInterval);
          animationInterval = null;
        }
        stopTimeouts.forEach(clearTimeout);
        stopTimeouts = [];
      };

      const saveCurrentNumber = () => {
        if (currentNumber === null) {
          return;
        }
        if (isNumberSaved(currentNumber)) {
          statusText.textContent = 'این شماره قبلاً ذخیره شده';
          statusText.classList.add('winner-message--active');
          statusText.classList.remove('winner-message--idle');
          confirmBtn.disabled = true;
          startBtn.disabled = false;
          return;
        }
        const entry = { number: currentNumber };
        savedNumbers = [entry, ...savedNumbers];
        persistSavedNumbers(savedNumbers);
        renderNumberList(savedNumbers);
        statusText.textContent = `شماره ثبت‌شده: ${formatNumberDigits(currentNumber)}`;
        statusText.classList.add('winner-message--active');
        statusText.classList.remove('winner-message--idle');
        confirmBtn.disabled = true;
        startBtn.disabled = false;
        updateDrawAvailability();
      };

      startBtn.addEventListener('click', () => {
        cancelAnimation();
        startBtn.disabled = true;
        confirmBtn.disabled = true;
        const targetNumber = generateUniqueNumber();
        if (targetNumber === null) {
          updateDrawAvailability();
          return;
        }
        currentNumber = targetNumber;
        const digits = formatNumberDigits(targetNumber).split('');
        const currentDigits = Array(DIGIT_COUNT).fill('0');
        const locks = defaultLocks();
        animationInterval = setInterval(() => {
          for (let i = 0; i < DIGIT_COUNT; i += 1) {
            if (!locks[i]) {
              currentDigits[i] = randomDigit();
            }
          }
          renderDigits(currentDigits.join(''), locks);
        }, 90);
        const stopDelays = [1200, 2400, 3600];
        stopDelays.forEach((delay, index) => {
          const timeout = setTimeout(() => {
            locks[index] = true;
            currentDigits[index] = digits[index];
            renderDigits(currentDigits.join(''), locks);
            if (index === DIGIT_COUNT - 1) {
              cancelAnimation();
              confirmBtn.disabled = false;
              startBtn.disabled = false;
              setNumberText(currentNumber);
            }
          }, delay);
          stopTimeouts.push(timeout);
        });
      });

      confirmBtn.addEventListener('click', () => {
        if (currentNumber === null) {
          return;
        }
        saveCurrentNumber();
      });

      const handleShortcutReset = () => {
        clearSavedNumbers();
        statusText.textContent = 'شماره‌ها پاک شد';
        statusText.classList.add('winner-message--active');
        statusText.classList.remove('winner-message--idle');
      };

      document.addEventListener('keydown', (event) => {
        pressedShortcutKeys.add(event.code);
        if (event.code === 'Enter') {
          if (!startBtn.disabled) {
            startBtn.click();
          }
        } else if (event.code === 'Space') {
          event.preventDefault();
          if (!confirmBtn.disabled) {
            confirmBtn.click();
          }
        }

        if (!resetShortcutLocked &&
            pressedShortcutKeys.has('Numpad8') &&
            pressedShortcutKeys.has('Numpad9')) {
          resetShortcutLocked = true;
          event.preventDefault();
          handleShortcutReset();
        }
      });

      document.addEventListener('keyup', (event) => {
        pressedShortcutKeys.delete(event.code);
        if (event.code === 'Numpad8' || event.code === 'Numpad9') {
          resetShortcutLocked = false;
        }
      });

      showIdleText();
      renderDigits('0');
      renderNumberList(savedNumbers);
      updateDrawAvailability();
    </script>
  </body>
</html>

<?php
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
  if (preg_match('/^(?:data:|https?:\/\/|\/\/)/i', $trimmed)) {
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
