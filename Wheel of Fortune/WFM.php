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
    <title>Wheel of Fortune</title>
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
        margin: 0;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        background: radial-gradient(circle at top, #7cb7ff, #1a3edb 55%, #07103b 100%);
        color: #f0f8ff;
        padding: 24px;
      }

      .shell {
        width: min(560px, 100%);
        padding: 32px 28px;
        border-radius: 32px;
        background: linear-gradient(180deg, rgba(20, 35, 67, 0.92), rgba(6, 21, 57, 0.98));
        border: 1px solid rgba(255, 255, 255, 0.12);
        box-shadow: 0 16px 40px rgba(3, 20, 60, 0.55);
        display: flex;
        flex-direction: column;
        gap: 24px;
        align-items: center;
        text-align: center;
      }

      .title {
        font-size: 1.1rem;
        letter-spacing: 0.32em;
        color: rgba(255, 255, 255, 0.75);
        margin: 0;
      }

      .wheel-wrap {
        width: min(460px, 100%);
        display: grid;
        place-items: center;
      }

      canvas {
        width: min(420px, 86vw);
        height: auto;
        background: rgba(255, 255, 255, 0.92);
        border-radius: 50%;
        box-shadow: 0 16px 40px rgba(3, 20, 60, 0.35);
      }

      .result {
        font-size: 1.4rem;
        letter-spacing: 0.04em;
        color: #cde6ff;
        margin: 0;
      }

      .cta {
        display: flex;
        justify-content: center;
      }

      button {
        border: none;
        border-radius: 999px;
        padding: 14px 32px;
        font-size: 1rem;
        font-weight: 700;
        cursor: pointer;
        background: linear-gradient(135deg, #32c5ff, #0b74ff);
        color: #00112a;
        box-shadow: 0 12px 24px rgba(11, 116, 255, 0.45);
        transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease;
      }

      button:disabled {
        opacity: 0.45;
        cursor: not-allowed;
        transform: none;
        box-shadow: none;
      }

      button:hover:not(:disabled) {
        transform: translateY(-2px);
      }
    </style>
  </head>
  <body>
    <div class="shell">
      <p class="title">Wheel of Fortune</p>
      <div class="wheel-wrap">
        <canvas id="wf-wheel" width="420" height="420" aria-label="Prize wheel"></canvas>
      </div>
      <p id="wf-result" class="result">Result: --</p>
      <div class="cta">
        <button id="wf-spin" type="button">Spin</button>
      </div>
    </div>

    <script>
      const initialPrizes = Array.isArray(<?= json_encode($initialPrizes, JSON_UNESCAPED_UNICODE); ?>)
        ? <?= json_encode($initialPrizes, JSON_UNESCAPED_UNICODE); ?>
        : [];

      const canvas = document.getElementById('wf-wheel');
      const ctx = canvas.getContext('2d');
      const spinBtn = document.getElementById('wf-spin');
      const resultEl = document.getElementById('wf-result');

      let prizeNames = [];
      let currentAngle = 0;
      let spinning = false;

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
            expanded.push(String(item.name ?? ''));
          }
        });
        return expanded.filter(Boolean).length ? expanded.filter(Boolean) : ['No Prize'];
      };

      const drawWheel = (names, angle = 0) => {
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

      const initWheel = (list) => {
        const safeList = Array.isArray(list) && list.length
          ? list
          : [{ name: 'No Prize', quantity: 1, last: 1 }];
        prizeNames = expandPrizes(safeList);
        drawWheel(prizeNames, currentAngle);
      };

      const bootstrap = async () => {
        if (initialPrizes.length) {
          initWheel(initialPrizes);
          return;
        }
        const loaded = await loadPrizeStore();
        initWheel(loaded);
      };

      bootstrap();

      spinBtn.addEventListener('click', async () => {
        if (spinning || !prizeNames.length) {
          return;
        }
        spinning = true;
        spinBtn.disabled = true;
        resultEl.textContent = 'Result: --';

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
          const result = pickResult(prizeNames, currentAngle);
          resultEl.textContent = `Result: ${result}`;
          spinning = false;
          spinBtn.disabled = false;

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
        };

        requestAnimationFrame(animate);
      });
    </script>
  </body>
</html>
