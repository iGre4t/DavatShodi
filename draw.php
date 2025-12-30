<?php
session_start();

date_default_timezone_set('Asia/Tehran');

if (empty($_SESSION['authenticated'])) {
  header('Location: login.php');
  exit;
}

const GUEST_STORE_PATH = __DIR__ . '/data/guests.json';
const EVENTS_ROOT = __DIR__ . '/events';
const STORE_PATH = __DIR__ . '/data/store.json';
const DEFAULT_PANEL_SETTINGS = [
  'panelName' => 'Great Panel',
  'siteIcon' => ''
];
const DRAW_TIMEZONE = 'Asia/Tehran';
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

$panelSettings = loadPanelSettings();
$pageTitle = (string)($panelSettings['panelName'] ?? DEFAULT_PANEL_SETTINGS['panelName']);
$faviconUrl = formatSiteIconUrlForHtml((string)($panelSettings['siteIcon'] ?? ''));

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$drawEventCode = '';
if (defined('EVENT_SCOPED_EVENT_CODE')) {
  $drawEventCode = trim((string)EVENT_SCOPED_EVENT_CODE);
} else {
  $drawEventCode = trim((string)($_GET['event_code'] ?? ''));
}
$drawEventCode = preg_replace('/[^A-Za-z0-9_-]+/', '', $drawEventCode);

if ($method === 'POST') {
  header('Content-Type: application/json; charset=UTF-8');
  $rawInput = file_get_contents('php://input');
  $payload = json_decode($rawInput ?: '', true);
  $action = is_array($payload) ? (string)($payload['action'] ?? '') : '';

  if ($action === 'reset_winners') {
    if (!deleteWinnerRecords(EVENTS_ROOT, $drawEventCode)) {
      echo json_encode(['status' => 'error', 'message' => 'Unable to remove winner records.']);
      exit;
    }
    echo json_encode([
      'status' => 'ok',
      'message' => 'Winners list cleared.',
      'winners' => []
    ]);
    exit;
  }

  if ($action !== 'confirm_winner') {
    echo json_encode(['status' => 'error', 'message' => 'Unsupported action.']);
    exit;
  }

  if (!is_array($payload['guest'] ?? null)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing guest payload.']);
    exit;
  }

  $guest = $payload['guest'];
  $code = preg_replace('/\D+/', '', (string)($guest['code'] ?? ''));
  if ($code === '') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid guest code.']);
    exit;
  }

  $eventName = trim((string)($guest['event_name'] ?? 'event'));
  $eventCode = trim((string)($guest['event_code'] ?? ''));
  $eventSlug = normalizeSlug((string)($guest['event_slug'] ?? ''));
  $dirCandidate = $eventCode !== '' ? $eventCode : ($eventSlug !== '' ? $eventSlug : normalizeSlug($eventName));
  if ($dirCandidate === '') {
    $dirCandidate = 'event';
  }

  $eventDir = EVENTS_ROOT . '/' . $dirCandidate;
  $winnersFile = buildWinnersFileName($eventName);
  $entry = [
    'timestamp' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
    'event_code' => $eventCode !== '' ? $eventCode : $dirCandidate,
    'event_name' => $eventName ?: 'event',
    'code' => $code,
    'number' => (int)($guest['number'] ?? 0),
    'firstname' => trim((string)($guest['firstname'] ?? '')),
    'lastname' => trim((string)($guest['lastname'] ?? '')),
    'gender' => trim((string)($guest['gender'] ?? '')),
    'national_id' => trim((string)($guest['national_id'] ?? '')),
    'phone_number' => trim((string)($guest['phone_number'] ?? '')),
    'invite_code' => $code
  ];

  if (!appendWinnerRecord($eventDir, $winnersFile, $entry)) {
    echo json_encode(['status' => 'error', 'message' => 'Unable to persist winner.']);
    exit;
  }

  echo json_encode([
    'status' => 'ok',
    'message' => 'Winner saved.',
    'winners' => loadWinnersList(EVENTS_ROOT, $drawEventCode)
  ]);
  exit;
}

$guestPool = buildGuestPool(GUEST_STORE_PATH, $drawEventCode);
$winnersList = loadWinnersList(EVENTS_ROOT, $drawEventCode);

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
        <button id="confirm-guest" class="confirm-btn" type="button" disabled>تایید مهمان</button>
      </div>
    </div>
    <div class="winners-panel" aria-live="polite">
      <h3>برندگان</h3>
      <div id="winner-items" class="winner-items"></div>
    </div>

    <script>
      window.__GUEST_POOL = window.__GUEST_POOL || <?= json_encode($guestPool, JSON_UNESCAPED_UNICODE); ?>;
      window.__WINNERS_LIST = window.__WINNERS_LIST || <?= json_encode($winnersList, JSON_UNESCAPED_UNICODE); ?>;
      const EVENT_CODE = <?= json_encode($drawEventCode, JSON_UNESCAPED_UNICODE); ?>;
      const DRAW_API_PATH = 'draw.php' + (EVENT_CODE ? '?event_code=' + encodeURIComponent(EVENT_CODE) : '');
      const guestPool = Array.isArray(window.__GUEST_POOL) ? window.__GUEST_POOL : [];
      let winnersList = Array.isArray(window.__WINNERS_LIST) ? window.__WINNERS_LIST : [];
      const codeDisplay = document.getElementById('code-display');
      const winnerNameEl = document.getElementById('winner-name');
      const startBtn = document.getElementById('start-draw');
      const confirmBtn = document.getElementById('confirm-guest');
      const winnersContainer = document.getElementById('winner-items');
      const digitElements = Array.from(codeDisplay.querySelectorAll('.code-digit'));

      let animationInterval = null;
      let stopTimeouts = [];
      let currentWinner = null;
      const pressedShortcutKeys = new Set();
      let resetShortcutLocked = false;

      const randomDigit = () => Math.floor(Math.random() * 10).toString();

      const normalizeCode = (value) => {
        const text = (value ?? '').toString().trim();
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
          const char = normalized[index] ?? '0';
          element.textContent = char;
          const locked = Boolean(locks[index]);
          element.classList.toggle('code-digit--locked', locked);
          element.classList.toggle('code-digit--animating', !locked);
        });
      };

      const setCode = (value, locks = defaultLocks()) => {
        const normalized = normalizeCode(value);
        renderDigits(normalized, locks);
      };

      const createGuestSelectionKey = (guest) => {
        if (!guest || typeof guest !== 'object') {
          return '';
        }
        const eventCode = (guest.event_code ?? guest.event_slug ?? '').toString();
        const code = normalizeCode(guest.code ?? guest.invite_code ?? '');
        const number = (guest.number ?? '').toString();
        return `${eventCode}|${code}|${number}`;
      };

      const chosenGuestKeys = new Set();

      const getAvailableGuests = () => guestPool.filter((guest) => {
        const key = createGuestSelectionKey(guest);
        return key !== '' && !chosenGuestKeys.has(key);
      });

      const cancelAnimation = () => {
        if (animationInterval !== null) {
          clearInterval(animationInterval);
          animationInterval = null;
        }
        stopTimeouts.forEach(clearTimeout);
        stopTimeouts = [];
      };

      const showIdleWinnerText = () => {
        winnerNameEl.textContent = 'برنده قرعه کشی';
        winnerNameEl.classList.add('winner-message--idle');
        winnerNameEl.classList.remove('winner-message--active');
      };

      const setWinnerText = (name) => {
        winnerNameEl.textContent = name;
        winnerNameEl.classList.add('winner-message--active');
        winnerNameEl.classList.remove('winner-message--idle');
      };

      const renderWinner = (winner) => {
        const name = winner?.full_name || '----';
        setWinnerText(name);
      };

      const formatWinnerItem = (entry) => {
        const container = document.createElement('div');
        container.className = 'winner-item';
        const codeEl = document.createElement('div');
        codeEl.className = 'winner-code';
        codeEl.textContent = (entry.code || entry.invite_code || '0000').toString();
        const infoEl = document.createElement('div');
        infoEl.className = 'winner-info';
        const displayName = entry.full_name || `${entry.firstname || ''} ${entry.lastname || ''}`.trim() || 'مهمان';
        infoEl.textContent = displayName;
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
        winnersList.forEach((row) => winnersContainer.appendChild(formatWinnerItem(row)));
      };

      const flashError = (message) => {
        console.error(message);
        confirmBtn.disabled = false;
      };

      const resetWinnersList = async () => {
        cancelAnimation();
        confirmBtn.disabled = true;
        try {
          const response = await fetch(DRAW_API_PATH, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'reset_winners' })
          });
          const payload = await response.json();
          if (!response.ok || payload.status !== 'ok') {
            throw new Error(payload?.message || 'Unable to reset winners list.');
          }
          const winners = Array.isArray(payload.winners) ? payload.winners : [];
          chosenGuestKeys.clear();
          currentWinner = null;
          showIdleWinnerText();
          setCode('0000');
          renderWinnerList(winners);
          window.__WINNERS_LIST = winners;
          startBtn.disabled = getAvailableGuests().length === 0;
          confirmBtn.disabled = true;
        } catch (error) {
          flashError('Unable to reset winners list.');
          confirmBtn.disabled = true;
          startBtn.disabled = getAvailableGuests().length === 0;
        }
      };

      setCode('0000');
      showIdleWinnerText();

      startBtn.addEventListener('click', () => {
        const availableGuests = getAvailableGuests();
        if (!availableGuests.length) {
          startBtn.disabled = true;
          return;
        }
        cancelAnimation();
        startBtn.disabled = true;
        confirmBtn.disabled = true;
        currentWinner = availableGuests[Math.floor(Math.random() * availableGuests.length)];
        const selectionKey = createGuestSelectionKey(currentWinner);
        if (selectionKey !== '') {
          chosenGuestKeys.add(selectionKey);
        }
        showIdleWinnerText();
        const targetCode = normalizeCode(currentWinner?.code ?? currentWinner?.invite_code);
        const digits = targetCode.split('');
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
              confirmBtn.disabled = false;
              const hasRemaining = getAvailableGuests().length > 0;
              startBtn.disabled = !hasRemaining;
              renderWinner(currentWinner);
            }
          }, delay);
          stopTimeouts.push(timeout);
        });
      });

      confirmBtn.addEventListener('click', async () => {
        if (!currentWinner) {
          return;
        }
        confirmBtn.disabled = true;
        try {
          const response = await fetch(DRAW_API_PATH, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'confirm_winner', guest: currentWinner })
          });
          const data = await response.json();
          if (!data || data.status !== 'ok') {
            throw new Error(data?.message || 'ثبت برنده ممکن نشد');
          }
          renderWinnerList(data.winners || []);
          window.__WINNERS_LIST = Array.isArray(data.winners) ? data.winners : [];
      } catch (error) {
        flashError('ذخیره برنده با مشکل مواجه شد');
      }
    });

      document.addEventListener('keydown', (event) => {
        pressedShortcutKeys.add(event.code);
        if (event.code === 'Numpad1') {
          event.preventDefault();
          window.location.href = 'prizes.php';
          return;
        }
        if (event.code === 'Numpad2') {
          event.preventDefault();
          window.location.href = 'draw.php';
          return;
        }
        const targetTag = event.target?.tagName ?? '';
        if (['INPUT', 'TEXTAREA'].includes(targetTag)) {
          return;
        }
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

      if (!getAvailableGuests().length) {
        startBtn.disabled = true;
      }

      renderWinnerList(winnersList);
    </script>
  </body>
</html>

<?php

function buildGuestPool(string $storePath, string $targetEventCode = ''): array
{
  $store = loadGuestStoreForDraw($storePath);
  $events = $store['events'] ?? [];
  $activeCode = trim((string)($store['active_event_code'] ?? ''));
  $targetEventCode = trim($targetEventCode);
  $pool = [];

  foreach ($events as $event) {
    if (!is_array($event)) {
      continue;
    }
    $eventCode = trim((string)($event['code'] ?? ''));
    $slug = normalizeSlug((string)($event['slug'] ?? ''));
    if ($slug === '') {
      $slug = normalizeSlug((string)($event['name'] ?? ''));
    }
    if ($eventCode === '') {
      $eventCode = $slug;
    }
    if ($eventCode === '') {
      $eventCode = 'event';
    }
    if ($targetEventCode !== '') {
      if ($eventCode !== $targetEventCode) {
        continue;
      }
    } elseif ($activeCode !== '' && $eventCode !== $activeCode) {
      continue;
    }
    if (!isEventActive($event)) {
      continue;
    }
    $eventName = trim((string)($event['name'] ?? 'event'));
    $guests = $event['guests'] ?? [];
    foreach ($guests as $guest) {
      if (!is_array($guest)) {
        continue;
      }
      $entered = trim((string)($guest['join_date'] ?? $guest['date_entered'] ?? ''));
      $exited = trim((string)($guest['left_date'] ?? $guest['date_exited'] ?? ''));
      if ($entered === '' || $exited !== '') {
        continue;
      }
      $code = preg_replace('/\D+/', '', (string)($guest['invite_code'] ?? ''));
      if ($code === '') {
        continue;
      }
      $code = substr($code, -4);
      $code = str_pad($code, 4, '0', STR_PAD_LEFT);
      $firstName = trim((string)($guest['firstname'] ?? ''));
      $lastName = trim((string)($guest['lastname'] ?? ''));
      $fullName = trim(implode(' ', array_filter([$firstName, $lastName], static fn ($value) => $value !== '')));
      if ($fullName === '') {
        $fullName = 'مهمان';
      }
      $pool[] = [
        'code' => $code,
        'number' => (int)($guest['number'] ?? 0),
        'firstname' => $firstName,
        'lastname' => $lastName,
        'full_name' => $fullName,
        'gender' => trim((string)($guest['gender'] ?? '')),
        'national_id' => trim((string)($guest['national_id'] ?? '')),
        'phone_number' => trim((string)($guest['phone_number'] ?? '')),
        'invite_code' => $code,
        'event_name' => $eventName,
        'event_code' => $eventCode,
        'event_slug' => $slug
      ];
    }
  }
  return $pool;
}

function normalizeSlug(string $value): string
{
  $value = trim($value);
  if ($value === '') {
    return '';
  }
  if (function_exists('iconv')) {
    $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($normalized !== false) {
      $value = $normalized;
    }
  }
  $value = preg_replace('/[^a-zA-Z0-9]+/', '-', $value);
  $value = trim($value, '-');
  return strtolower($value);
}

function isEventActive(array $event): bool
{
  $flagKeys = ['active_event', 'is_active', 'active', 'enabled'];
  $hasFlag = false;
  foreach ($flagKeys as $key) {
    if (!array_key_exists($key, $event)) {
      continue;
    }
    $hasFlag = true;
    $value = $event[$key];
    if ($value === true) {
      return true;
    }
    if (is_string($value) && in_array(strtolower($value), ['1', 'true', 'yes', 'فعال'], true)) {
      return true;
    }
    if (is_int($value) && $value > 0) {
      return true;
    }
  }
  return !$hasFlag;
}

function loadGuestStoreForDraw(string $storePath): array
{
  if (!is_file($storePath)) {
    return ['events' => [], 'active_event_slug' => '', 'active_event_code' => ''];
  }
  $content = file_get_contents($storePath);
  if ($content === false) {
    return ['events' => [], 'active_event_slug' => '', 'active_event_code' => ''];
  }
  $decoded = json_decode($content, true);
  if (!is_array($decoded)) {
    return ['events' => [], 'active_event_slug' => '', 'active_event_code' => ''];
  }
  $decoded['events'] = is_array($decoded['events'] ?? null) ? array_values($decoded['events']) : [];
  $decoded['active_event_slug'] = trim((string)($decoded['active_event_slug'] ?? ''));
  $decoded['active_event_code'] = trim((string)($decoded['active_event_code'] ?? ''));
  if ($decoded['active_event_code'] === '') {
    $decoded['active_event_code'] = $decoded['active_event_slug'];
  }
  return $decoded;
}

function buildWinnersFileName(string $eventName): string
{
  $label = trim($eventName);
  if ($label === '') {
    $label = 'event';
  }
  $safe = normalizeFileName($label);
  if ($safe === '') {
    $safe = 'event';
  }
  return 'winners of ' . $safe . '.csv';
}

function normalizeFileName(string $value): string
{
  $stripped = preg_replace('/[\/\\\\:*?"<>|\r\n]+/', '', $value);
  $collapsed = preg_replace('/\s+/', ' ', $stripped);
  return trim($collapsed);
}

function appendWinnerRecord(string $eventDir, string $fileName, array $row): bool
{
  if (!is_dir($eventDir) && !mkdir($eventDir, 0755, true) && !is_dir($eventDir)) {
    return false;
  }
  $filePath = $eventDir . '/' . $fileName;
  $headers = ['timestamp', 'event_code', 'event_name', 'code', 'number', 'firstname', 'lastname', 'gender', 'national_id', 'phone_number', 'invite_code'];
  $isNew = !is_file($filePath);
  $handle = fopen($filePath, 'a');
  if ($handle === false) {
    return false;
  }
  if ($isNew || filesize($filePath) === 0) {
    fputcsv($handle, $headers);
  }
  $line = [];
  foreach ($headers as $name) {
    $line[] = $row[$name] ?? '';
  }
  fputcsv($handle, $line);
  fclose($handle);
  return true;
}

function loadWinnersList(string $eventsRoot, string $targetEventCode = ''): array
{
  $list = [];
  if (!is_dir($eventsRoot)) {
    return $list;
  }
  $eventDirs = scandir($eventsRoot);
  if ($eventDirs === false) {
    return $list;
  }
  $targetEventCode = trim($targetEventCode);
  foreach ($eventDirs as $dir) {
    if ($dir === '.' || $dir === '..') {
      continue;
    }
    $eventPath = $eventsRoot . '/' . $dir;
    if (!is_dir($eventPath)) {
      continue;
    }
    if ($targetEventCode !== '' && $dir !== $targetEventCode) {
      continue;
    }
    $files = glob($eventPath . '/winners of *.csv');
    if ($files === false) {
      continue;
    }
    foreach ($files as $filePath) {
      $rows = readCsvRows($filePath);
      if ($rows) {
        foreach ($rows as &$row) {
          $codeValue = trim((string)($row['event_code'] ?? ''));
          if ($codeValue === '') {
            $row['event_code'] = $dir;
          }
          $slugValue = trim((string)($row['event_slug'] ?? ''));
          if ($slugValue !== '' && trim((string)($row['event_code'] ?? '')) === '') {
            $row['event_code'] = $slugValue;
          }
        }
        unset($row);
        $list = array_merge($list, $rows);
      }
    }
  }
  usort($list, static fn ($a, $b) => strcmp((string)($b['timestamp'] ?? ''), (string)($a['timestamp'] ?? '')));
  return array_values($list);
}

function readCsvRows(string $path): array
{
  $rows = [];
  if (!is_file($path)) {
    return $rows;
  }
  $handle = fopen($path, 'r');
  if ($handle === false) {
    return $rows;
  }
  $headers = fgetcsv($handle);
  if ($headers === false) {
    fclose($handle);
    return $rows;
  }
  while (($line = fgetcsv($handle)) !== false) {
    if (count($line) !== count($headers)) {
      $line = array_pad($line, count($headers), '');
    }
    $combined = @array_combine($headers, $line);
    if ($combined !== false) {
      $rows[] = $combined;
    }
  }
  fclose($handle);
  return $rows;
}

function deleteWinnerRecords(string $eventsRoot, string $targetEventCode = ''): bool
{
  if ($targetEventCode !== '') {
    $eventDir = $eventsRoot . '/' . trim($targetEventCode);
    return deleteWinnerFilesInDirectory($eventDir);
  }
  if (!is_dir($eventsRoot)) {
    return true;
  }
  $success = true;
  $entries = scandir($eventsRoot);
  if ($entries === false) {
    return true;
  }
  foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..') {
      continue;
    }
    $eventPath = $eventsRoot . '/' . $entry;
    if (!is_dir($eventPath)) {
      continue;
    }
    if (!deleteWinnerFilesInDirectory($eventPath)) {
      $success = false;
    }
  }
  return $success;
}

function deleteWinnerFilesInDirectory(string $eventPath): bool
{
  if (!is_dir($eventPath)) {
    return true;
  }
  $success = true;
  $files = glob($eventPath . '/winners of *.csv');
  if ($files === false) {
    return true;
  }
  foreach ($files as $filePath) {
    if (!is_file($filePath)) {
      continue;
    }
    if (!@unlink($filePath)) {
      $success = false;
    }
  }
  return $success;
}
