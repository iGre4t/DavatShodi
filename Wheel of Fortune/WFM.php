<?php
$prizeStorePath = __DIR__ . '/WF Prizes.json';

function readPrizeStore(string $path): array
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

function writePrizeStore(string $path, array $payload): bool
{
  $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($json === false) {
    return false;
  }
  return file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  header('Content-Type: application/json; charset=UTF-8');
  $rawInput = file_get_contents('php://input');
  $payload = json_decode($rawInput ?: '', true);
  $action = is_array($payload) ? (string)($payload['action'] ?? '') : '';

  if ($action === 'decrement_prize') {
    $name = trim((string)($payload['name'] ?? ''));
    if ($name === '') {
      echo json_encode(['status' => 'error', 'message' => 'Missing prize name.']);
      exit;
    }
    $prizes = readPrizeStore($prizeStorePath);
    $updated = [];
    foreach ($prizes as $item) {
      if (!is_array($item)) {
        continue;
      }
      $itemName = trim((string)($item['name'] ?? ''));
      $quantity = (int)($item['quantity'] ?? 0);
      $last = (int)($item['last'] ?? $quantity);
      if ($itemName !== '' && $itemName === $name) {
        $last = max(0, $last - 1);
      }
      if ($itemName !== '') {
        $updated[] = [
          'name' => $itemName,
          'quantity' => $quantity > 0 ? $quantity : 0,
          'last' => $last > 0 ? $last : 0
        ];
      }
    }
    if (!writePrizeStore($prizeStorePath, $updated)) {
      echo json_encode(['status' => 'error', 'message' => 'Unable to save prizes.']);
      exit;
    }
    echo json_encode(['status' => 'ok', 'data' => $updated]);
    exit;
  }

  echo json_encode(['status' => 'error', 'message' => 'Unsupported action.']);
  exit;
}

$initialPrizes = readPrizeStore($prizeStorePath);
?>
<!doctype html>
<html lang="en" dir="ltr">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Wheel of Fortune Draw</title>
    <link rel="icon" href="data:," />
    <style>
      :root {
        font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
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
        display: none;
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

      .wheel-wrap {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 12px;
      }

      .wheel-canvas {
        width: min(420px, 80vw);
        height: auto;
        background: rgba(255, 255, 255, 0.9);
        border-radius: 50%;
        box-shadow: 0 16px 40px rgba(3, 20, 60, 0.35);
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
        font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
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
        text-align: left;
        font-size: 1rem;
        direction: ltr;
        color: #ffffff;
        font-weight: 600;
      }

      @media (max-width: 480px) {
        .draw-shell,
        .winners-panel {
          padding: 20px;
        }
        .wheel-canvas {
          width: min(320px, 78vw);
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
        <a class="menu-item active" href="WFM.php">Wheel Draw</a>
      </div>
    </nav>
    <div class="draw-shell" aria-live="polite">
      <p class="caption">Wheel of Fortune Draw</p>
      <div class="wheel-wrap" aria-live="polite">
        <canvas id="wf-wheel-canvas" class="wheel-canvas" width="420" height="420" aria-label="Prize wheel"></canvas>
      </div>
      <p id="winner-name" class="winner-message winner-message--idle">Draw winner</p>
      <div class="cta-group">
        <button id="start-draw" class="start-btn" type="button">Spin & Draw</button>
      </div>
    </div>

    <script>
      const guestPool = [
        { code: '1201', firstname: 'Alex', lastname: 'Farhadi', full_name: 'Alex Farhadi' },
        { code: '8453', firstname: 'Sara', lastname: 'Karimi', full_name: 'Sara Karimi' },
        { code: '9920', firstname: 'Reza', lastname: 'Mohammadi', full_name: 'Reza Mohammadi' },
        { code: '4378', firstname: 'Mariam', lastname: 'Neshat', full_name: 'Mariam Neshat' }
      ];

      const winnerNameEl = document.getElementById('winner-name');
      const startBtn = document.getElementById('start-draw');
      const canvas = document.getElementById('wf-wheel-canvas');
      const ctx = canvas?.getContext('2d');

      let currentWinner = null;
      const pressedShortcutKeys = new Set();
      let resetShortcutLocked = false;
      let spinning = false;
      let currentAngle = 0;
      let prizeNames = [];

      const createGuestSelectionKey = (guest) => {
        if (!guest || typeof guest !== 'object') {
          return '';
        }
        const code = String(guest.code ?? '').replace(/\D+/g, '').slice(-4).padStart(4, '0');
        const number = (guest.number ?? '').toString();
        return `${code}|${number}`;
      };

      const chosenGuestKeys = new Set();

      const getAvailableGuests = () => guestPool.filter((guest) => {
        const key = createGuestSelectionKey(guest);
        return key !== '' && !chosenGuestKeys.has(key);
      });

      const showIdleWinnerText = () => {
        winnerNameEl.textContent = 'Result: --';
        winnerNameEl.classList.add('winner-message--idle');
        winnerNameEl.classList.remove('winner-message--active');
      };

      const setWinnerText = (name) => {
        winnerNameEl.textContent = name;
        winnerNameEl.classList.add('winner-message--active');
        winnerNameEl.classList.remove('winner-message--idle');
      };

      const prizePool = Array.isArray(<?= json_encode($initialPrizes, JSON_UNESCAPED_UNICODE); ?>)
        ? <?= json_encode($initialPrizes, JSON_UNESCAPED_UNICODE); ?>
        : [];

      const loadPrizeStore = async () => {
        try {
          const response = await fetch('WF%20Prizes.json', { cache: 'no-store' });
          const payload = await response.json();
          return Array.isArray(payload) ? payload : [];
        } catch {
          return [];
        }
      };

      const expandPrizes = (list) => {
        const expanded = [];
        list.forEach((item) => {
          const qty = Number.isFinite(item.last) && item.last > 0
            ? item.last
            : (Number.isFinite(item.quantity) && item.quantity > 0 ? item.quantity : 0);
          for (let i = 0; i < qty; i += 1) {
            expanded.push(item.name);
          }
        });
        return expanded.length ? expanded : ['No Prize'];
      };

      const drawWheel = (names, angle = 0) => {
        if (!canvas || !ctx) {
          return;
        }
        const size = canvas.width;
        const center = size / 2;
        const radius = center - 10;
        const count = names.length || 1;
        const slice = (Math.PI * 2) / count;
        ctx.clearRect(0, 0, size, size);
        ctx.save();
        ctx.translate(center, center);
        ctx.rotate(angle);
        for (let i = 0; i < count; i += 1) {
          const start = i * slice;
          const end = start + slice;
          ctx.beginPath();
          ctx.moveTo(0, 0);
          ctx.arc(0, 0, radius, start, end);
          ctx.closePath();
          ctx.fillStyle = i % 2 === 0 ? '#f1f5f9' : '#e2e8f0';
          ctx.fill();
          ctx.strokeStyle = '#cbd5f5';
          ctx.stroke();
          ctx.save();
          ctx.rotate(start + slice / 2);
          ctx.textAlign = 'right';
          ctx.fillStyle = '#111';
          ctx.font = '14px sans-serif';
          ctx.fillText(names[i], radius - 12, 5);
          ctx.restore();
        }
        ctx.restore();

        ctx.fillStyle = '#111';
        ctx.beginPath();
        ctx.moveTo(center, 10);
        ctx.lineTo(center - 10, 30);
        ctx.lineTo(center + 10, 30);
        ctx.closePath();
        ctx.fill();
      };

      const pickResult = (names, angle) => {
        const count = names.length || 1;
        const slice = (Math.PI * 2) / count;
        const normalized = (Math.PI * 2 - (angle % (Math.PI * 2)) + slice / 2) % (Math.PI * 2);
        const index = Math.floor(normalized / slice);
        return names[index] ?? names[0] ?? 'No Prize';
      };

      const initWheel = (list = prizePool) => {
        const safeList = Array.isArray(list) && list.length ? list : [{ name: 'No Prize', quantity: 1, last: 1 }];
        prizeNames = expandPrizes(safeList);
        drawWheel(prizeNames, currentAngle);
      };

      const resetWinnersList = () => {
        chosenGuestKeys.clear();
        currentWinner = null;
        showIdleWinnerText();
        startBtn.disabled = getAvailableGuests().length === 0;
      };

      showIdleWinnerText();
      initWheel();
      if (!prizePool.length) {
        loadPrizeStore().then((loaded) => {
          if (loaded.length) {
            initWheel(loaded);
          }
        });
      }

      startBtn.addEventListener('click', () => {
        if (spinning) {
          return;
        }
        const availableGuests = getAvailableGuests();
        if (!availableGuests.length) {
          startBtn.disabled = true;
          return;
        }
        startBtn.disabled = true;
        showIdleWinnerText();
        spinning = true;
        const spinTurns = 6 + Math.random() * 3;
        const targetAngle = currentAngle + spinTurns * Math.PI * 2 + Math.random() * Math.PI * 2;
        const start = performance.now();
        const duration = 2400;

        const easeOutCubic = (t) => 1 - Math.pow(1 - t, 3);

        const animate = (now) => {
          const elapsed = now - start;
          const progress = Math.min(1, elapsed / duration);
          const eased = easeOutCubic(progress);
          currentAngle = currentAngle + (targetAngle - currentAngle) * eased;
          drawWheel(prizeNames, currentAngle);
          if (progress < 1) {
            requestAnimationFrame(animate);
            return;
          }
          spinning = false;
          const result = pickResult(prizeNames, currentAngle);
          setWinnerText(`Result: ${result}`);
          try {
            const response = await fetch('WFM.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ action: 'decrement_prize', name: result })
            });
            const payload = await response.json();
            if (response.ok && payload?.status === 'ok' && Array.isArray(payload.data)) {
              initWheel(payload.data);
            }
          } catch {}
          currentWinner = availableGuests[Math.floor(Math.random() * availableGuests.length)];
          const selectionKey = createGuestSelectionKey(currentWinner);
          if (selectionKey !== '') {
            chosenGuestKeys.add(selectionKey);
          }
          const hasRemaining = getAvailableGuests().length > 0;
          startBtn.disabled = !hasRemaining;
        };

        requestAnimationFrame(animate);
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
          window.location.href = 'WFM.php';
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
    </script>
  </body>
</html>

