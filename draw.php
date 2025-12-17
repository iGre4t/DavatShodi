<?php
session_start();

date_default_timezone_set('Asia/Tehran');

if (empty($_SESSION['authenticated'])) {
  header('Location: login.php');
  exit;
}

const GUEST_STORE_PATH = __DIR__ . '/data/guests.json';
const EVENTS_ROOT = __DIR__ . '/events';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
  header('Content-Type: application/json; charset=UTF-8');
  $rawInput = file_get_contents('php://input');
  $payload = json_decode($rawInput ?: '', true);
  $action = is_array($payload) ? (string)($payload['action'] ?? '') : '';

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
  $eventSlug = normalizeSlug((string)($guest['event_slug'] ?? ''));
  if ($eventSlug === '') {
    $eventSlug = normalizeSlug($eventName);
  }
  if ($eventSlug === '') {
    $eventSlug = 'event';
  }

  $eventDir = EVENTS_ROOT . '/' . $eventSlug;
  $winnersFile = buildWinnersFileName($eventName);
  $entry = [
    'timestamp' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
    'event_slug' => $eventSlug,
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
    'winners' => loadWinnersList(EVENTS_ROOT)
  ]);
  exit;
}

$guestPool = buildGuestPool(GUEST_STORE_PATH);
$winnersList = loadWinnersList(EVENTS_ROOT);

?>
<!doctype html>
<html lang="fa" dir="rtl">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Draw Stage</title>
    <link rel="icon" href="data:," />
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
        justify-content: center;
        background: radial-gradient(circle at top, #7cb7ff, #1a3edb 55%, #07103b 100%);
        color: #f0f8ff;
        text-align: center;
        padding: 32px 16px 48px;
        flex-direction: column;
        gap: 24px;
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
      }

      .code-display {
        font-size: clamp(4rem, 16vw, 9rem);
        letter-spacing: 1.2rem;
        font-weight: 700;
        color: #89c6ff;
        margin: 0;
        line-height: 1;
        direction: ltr;
      }

      .caption {
        font-size: 1.1rem;
        letter-spacing: 0.25em;
        color: rgba(255, 255, 255, 0.72);
        margin: 0;
      }

      .winner-message {
        font-size: 1.2rem;
        margin: 0;
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
        max-height: 320px;
        overflow-y: auto;
      }

      .winner-item {
        padding: 14px;
        border-radius: 20px;
        background: rgba(255, 255, 255, 0.04);
        border: 1px solid rgba(255, 255, 255, 0.08);
        display: grid;
        grid-template-columns: auto 1fr auto;
        gap: 12px;
        align-items: center;
      }

      .winner-code {
        font-size: 1.35rem;
        font-weight: 700;
        letter-spacing: 0.4rem;
        color: #8be1ff;
        direction: ltr;
      }

      .winner-info {
        text-align: left;
        font-size: 0.95rem;
        direction: rtl;
      }

      .winner-info span {
        display: block;
        color: rgba(255, 255, 255, 0.75);
        font-size: 0.85rem;
      }

      .winner-time {
        font-size: 0.75rem;
        color: rgba(255, 255, 255, 0.5);
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
    <div class="draw-shell" aria-live="polite">
      <p class="caption">میز آغاز قرعه‌کشی</p>
      <p id="code-display" class="code-display">0000</p>
      <p id="winner-name" class="winner-message">-- <span>----</span> is the winner --</p>
      <div class="cta-group">
        <button id="start-draw" class="start-btn" type="button">start draw</button>
        <button id="confirm-guest" class="confirm-btn" type="button" disabled>confirm guest</button>
      </div>
      <p id="status-text" class="status">ready for the next pick</p>
    </div>
    <div class="winners-panel" aria-live="polite">
      <h3>Confirmed winners</h3>
      <div id="winner-items" class="winner-items"></div>
    </div>

    <script>
      window.__GUEST_POOL = window.__GUEST_POOL || <?= json_encode($guestPool, JSON_UNESCAPED_UNICODE); ?>;
      window.__WINNERS_LIST = window.__WINNERS_LIST || <?= json_encode($winnersList, JSON_UNESCAPED_UNICODE); ?>;
      const guestPool = Array.isArray(window.__GUEST_POOL) ? window.__GUEST_POOL : [];
      let winnersList = Array.isArray(window.__WINNERS_LIST) ? window.__WINNERS_LIST : [];
      const codeDisplay = document.getElementById('code-display');
      const winnerNameEl = document.getElementById('winner-name');
      const statusText = document.getElementById('status-text');
      const startBtn = document.getElementById('start-draw');
      const confirmBtn = document.getElementById('confirm-guest');
      const winnersContainer = document.getElementById('winner-items');

      let animationInterval = null;
      let settleTimeout = null;
      let currentWinner = null;

      const randomCode = () => {
        return Array.from({ length: 4 }, () => Math.floor(Math.random() * 10)).join('');
      };

      const setCode = (value) => {
        codeDisplay.textContent = value.padStart(4, '0');
      };

      const cancelAnimation = () => {
        if (animationInterval !== null) {
          clearInterval(animationInterval);
          animationInterval = null;
        }
        if (settleTimeout !== null) {
          clearTimeout(settleTimeout);
          settleTimeout = null;
        }
      };

      const setWinnerText = (name) => {
        winnerNameEl.textContent = '';
        winnerNameEl.appendChild(document.createTextNode('-- '));
        const highlight = document.createElement('span');
        highlight.textContent = name;
        winnerNameEl.appendChild(highlight);
        winnerNameEl.appendChild(document.createTextNode(' is the winner --'));
      };

      const renderWinner = (winner) => {
        const name = winner?.full_name || '----';
        setWinnerText(name);
        statusText.textContent = winner ? `winner locked: ${name}` : 'ready for the next pick';
      };

      const formatWinnerItem = (entry) => {
        const container = document.createElement('div');
        container.className = 'winner-item';
        const codeEl = document.createElement('div');
        codeEl.className = 'winner-code';
        codeEl.textContent = (entry.code || entry.invite_code || '0000').toString();
        const infoEl = document.createElement('div');
        infoEl.className = 'winner-info';
        const displayName = entry.full_name || `${entry.firstname || ''} ${entry.lastname || ''}`.trim() || 'Guest';
        infoEl.innerHTML = `${displayName}<span>${entry.event_name || entry.event_slug || 'event'}</span>`;
        const timeEl = document.createElement('div');
        timeEl.className = 'winner-time';
        timeEl.textContent = entry.timestamp || '';
        container.append(codeEl, infoEl, timeEl);
        return container;
      };

      const renderWinnerList = (items) => {
        winnersList = Array.isArray(items) ? items.slice() : [];
        winnersContainer.innerHTML = '';
        if (!winnersList.length) {
          const placeholder = document.createElement('p');
          placeholder.className = 'status';
          placeholder.textContent = 'no winners confirmed yet';
          winnersContainer.appendChild(placeholder);
          return;
        }
        winnersList.forEach((row) => winnersContainer.appendChild(formatWinnerItem(row)));
      };

      const flashError = (message) => {
        statusText.textContent = message;
        confirmBtn.disabled = false;
      };

      startBtn.addEventListener('click', () => {
        if (!guestPool.length) {
          statusText.textContent = 'guest list is empty';
          return;
        }
        cancelAnimation();
        startBtn.disabled = true;
        confirmBtn.disabled = true;
        currentWinner = guestPool[Math.floor(Math.random() * guestPool.length)];
        statusText.textContent = 'drawing...';

        animationInterval = setInterval(() => {
          setCode(randomCode());
        }, 70);

        settleTimeout = setTimeout(() => {
          cancelAnimation();
          const winnerCode = currentWinner?.code ?? '0000';
          setCode(winnerCode);
          confirmBtn.disabled = false;
          startBtn.disabled = false;
          renderWinner(currentWinner);
        }, 2400);
      });

      confirmBtn.addEventListener('click', async () => {
        if (!currentWinner) {
          statusText.textContent = 'choose a winner first';
          return;
        }
        statusText.textContent = 'confirming winner...';
        confirmBtn.disabled = true;
        try {
          const response = await fetch('draw.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'confirm_winner', guest: currentWinner })
          });
          const data = await response.json();
          if (!data || data.status !== 'ok') {
            throw new Error(data?.message || 'Failed to save winner');
          }
          renderWinnerList(data.winners || []);
          window.__WINNERS_LIST = Array.isArray(data.winners) ? data.winners : [];
          statusText.textContent = `guest confirmed: ${currentWinner.full_name}`;
        } catch (error) {
          flashError('unable to save winner');
        }
      });

      if (!guestPool.length) {
        statusText.textContent = 'guest list is empty';
        startBtn.disabled = true;
      }

      renderWinnerList(winnersList);
    </script>
  </body>
</html>

<?php
function buildGuestPool(string $storePath): array
{
  $pool = [];
  if (!is_file($storePath)) {
    return $pool;
  }
  $content = file_get_contents($storePath);
  if ($content === false) {
    return $pool;
  }
  $decoded = json_decode($content, true);
  if (!is_array($decoded)) {
    return $pool;
  }
  $events = $decoded['events'] ?? [];
  foreach ($events as $event) {
    if (!is_array($event)) {
      continue;
    }
    $eventName = trim((string)($event['name'] ?? 'event'));
    $slug = normalizeSlug((string)($event['slug'] ?? ''));
    if ($slug === '') {
      $slug = normalizeSlug($eventName);
    }
    if ($slug === '') {
      $slug = 'event';
    }
    $guests = $event['guests'] ?? [];
    foreach ($guests as $guest) {
      if (!is_array($guest)) {
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
        $fullName = 'Guest';
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
  $headers = ['timestamp', 'event_slug', 'event_name', 'code', 'number', 'firstname', 'lastname', 'gender', 'national_id', 'phone_number', 'invite_code'];
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

function loadWinnersList(string $eventsRoot): array
{
  $list = [];
  if (!is_dir($eventsRoot)) {
    return $list;
  }
  $eventDirs = scandir($eventsRoot);
  if ($eventDirs === false) {
    return $list;
  }
  foreach ($eventDirs as $dir) {
    if ($dir === '.' || $dir === '..') {
      continue;
    }
    $eventPath = $eventsRoot . '/' . $dir;
    if (!is_dir($eventPath)) {
      continue;
    }
    $files = glob($eventPath . '/winners of *.csv');
    if ($files === false) {
      continue;
    }
    foreach ($files as $filePath) {
      $rows = readCsvRows($filePath);
      if ($rows) {
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
