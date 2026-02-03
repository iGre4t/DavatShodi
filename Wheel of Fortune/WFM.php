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
        --bg-1: #07163b;
        --bg-2: #0f3474;
        --shell-1: rgba(13, 37, 82, 0.84);
        --shell-2: rgba(5, 22, 53, 0.94);
        --line: rgba(184, 219, 255, 0.2);
        --txt-main: #e8f4ff;
        --txt-soft: #a9c6e8;
        --btn-1: #62c2ff;
        --btn-2: #1a7dd8;
        --btn-txt: #032146;
        font-family: 'Peyda Fa Num', 'Segoe UI', Tahoma, Arial, sans-serif;
        color-scheme: dark;
      }

      @font-face {
        font-family: 'Peyda Fa Num';
        src:
          url('../style/fonts/PeydaWebFaNum-Regular.woff2') format('woff2'),
          url('/fonts/PeydaWebFaNum-Regular.woff2') format('woff2');
        font-weight: 400;
        font-style: normal;
        font-display: swap;
      }

      @font-face {
        font-family: 'Peyda Fa Num';
        src:
          url('../style/fonts/PeydaWebFaNum-Bold.woff2') format('woff2'),
          url('/fonts/PeydaWebFaNum-Bold.woff2') format('woff2');
        font-weight: 700;
        font-style: normal;
        font-display: swap;
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
        background:
          radial-gradient(circle at 15% 10%, rgba(126, 185, 255, 0.25), transparent 38%),
          radial-gradient(circle at 85% 85%, rgba(84, 141, 232, 0.2), transparent 44%),
          linear-gradient(135deg, var(--bg-1), var(--bg-2));
        color: var(--txt-main);
        padding: 24px;
      }

      .shell {
        width: min(560px, 100%);
        padding: 32px 28px;
        border-radius: 32px;
        background: linear-gradient(180deg, var(--shell-1), var(--shell-2));
        border: 1px solid var(--line);
        box-shadow:
          0 20px 46px rgba(2, 12, 36, 0.55),
          inset 0 1px 0 rgba(229, 242, 255, 0.14);
        backdrop-filter: blur(4px);
        display: flex;
        flex-direction: column;
        gap: 24px;
        align-items: center;
        text-align: center;
      }

      .title {
        font-size: 1rem;
        letter-spacing: 0.4em;
        color: var(--txt-soft);
        margin: 0;
        text-transform: uppercase;
      }

      .wheel-wrap {
        width: min(460px, 100%);
        display: grid;
        place-items: center;
      }

      canvas {
        width: min(420px, 86vw);
        height: auto;
        background: radial-gradient(circle, #edf5ff 0%, #d6e8ff 100%);
        border-radius: 50%;
        border: 9px solid #f8fcff;
        box-shadow:
          0 16px 34px rgba(4, 16, 45, 0.5),
          inset 0 0 0 3px rgba(110, 161, 226, 0.34);
      }

      .result {
        font-size: 1.4rem;
        letter-spacing: 0.04em;
        color: #dcedff;
        margin: 0;
        min-height: 1.4em;
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
        font-family: inherit;
        cursor: pointer;
        background: linear-gradient(135deg, var(--btn-1), var(--btn-2));
        color: var(--btn-txt);
        box-shadow:
          0 12px 24px rgba(12, 90, 191, 0.4),
          inset 0 1px 0 rgba(255, 255, 255, 0.44);
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

      @media (max-width: 560px) {
        .shell {
          padding: 24px 18px;
          border-radius: 24px;
        }
        .title {
          letter-spacing: 0.28em;
          font-size: 0.9rem;
        }
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

      const TWO_PI = Math.PI * 2;
      const BASE_PALETTE = ['#eff6ff', '#dbeafe', '#bfdbfe', '#93c5fd', '#60a5fa', '#3b82f6'];
      let wheelSegments = [];
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

      const normalizePrizeStore = (list) => {
        if (!Array.isArray(list)) {
          return [{ name: 'No Prize', weight: 1, canDecrement: false }];
        }
        const segments = list
          .map((item) => {
            const name = String(item?.name ?? '').trim();
            const quantityValue = Number.parseInt(item?.quantity ?? 0, 10);
            const quantity = Number.isFinite(quantityValue) && quantityValue > 0 ? quantityValue : 0;
            const hasLast = item && Object.prototype.hasOwnProperty.call(item, 'last');
            const lastValue = Number.parseInt(item?.last ?? quantity, 10);
            const available = hasLast
              ? (Number.isFinite(lastValue) ? Math.max(0, lastValue) : 0)
              : quantity;
            return {
              name,
              weight: available,
              canDecrement: name !== '' && available > 0
            };
          })
          .filter((segment) => segment.name !== '' && segment.weight > 0);

        if (segments.length) {
          return segments;
        }
        return [{ name: 'No Prize', weight: 1, canDecrement: false }];
      };

      const drawWheel = (segments, angle = 0) => {
        const size = canvas.width;
        const center = size / 2;
        const radius = center - 12;
        const count = segments.length || 1;
        const slice = TWO_PI / count;
        const fontSize = Math.max(11, Math.min(16, 260 / count));

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
          ctx.fillStyle = BASE_PALETTE[i % BASE_PALETTE.length];
          ctx.fill();
          ctx.lineWidth = 1.5;
          ctx.strokeStyle = 'rgba(7, 44, 99, 0.4)';
          ctx.stroke();

          ctx.save();
          ctx.rotate(start + slice / 2);
          ctx.textAlign = 'right';
          ctx.fillStyle = '#032a5c';
          ctx.font = `700 ${fontSize}px "Peyda Fa Num", "Segoe UI", sans-serif`;
          ctx.fillText(segments[i].name.slice(0, 20), radius - 14, 5);
          ctx.restore();
        }
        ctx.restore();

        ctx.beginPath();
        ctx.arc(center, center, radius, 0, TWO_PI);
        ctx.lineWidth = 4;
        ctx.strokeStyle = 'rgba(232, 243, 255, 0.75)';
        ctx.stroke();

        ctx.fillStyle = '#0b2f64';
        ctx.beginPath();
        ctx.moveTo(center, 10);
        ctx.lineTo(center - 10, 30);
        ctx.lineTo(center + 10, 30);
        ctx.closePath();
        ctx.fill();

        ctx.beginPath();
        ctx.arc(center, center, 20, 0, TWO_PI);
        ctx.fillStyle = '#0f3f80';
        ctx.fill();
        ctx.lineWidth = 3;
        ctx.strokeStyle = '#dbeeff';
        ctx.stroke();
      };

      const chooseWeightedIndex = (segments) => {
        const totalWeight = segments.reduce((sum, segment) => sum + segment.weight, 0);
        if (totalWeight <= 0) {
          return 0;
        }
        let roll = Math.random() * totalWeight;
        for (let i = 0; i < segments.length; i += 1) {
          roll -= segments[i].weight;
          if (roll <= 0) {
            return i;
          }
        }
        return segments.length - 1;
      };

      const initWheel = (list) => {
        wheelSegments = normalizePrizeStore(list);
        drawWheel(wheelSegments, currentAngle);
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
        if (spinning || !wheelSegments.length) {
          return;
        }
        spinning = true;
        spinBtn.disabled = true;
        resultEl.textContent = 'Result: --';

        const winnerIndex = chooseWeightedIndex(wheelSegments);
        const winner = wheelSegments[winnerIndex] ?? wheelSegments[0];
        const slice = TWO_PI / wheelSegments.length;
        const winnerCenter = (winnerIndex * slice) + (slice / 2);
        const desiredAngle = (-Math.PI / 2) - winnerCenter;
        const normalizedCurrent = ((currentAngle % TWO_PI) + TWO_PI) % TWO_PI;
        const delta = ((desiredAngle - normalizedCurrent) % TWO_PI + TWO_PI) % TWO_PI;
        const spinTurns = 7 + Math.floor(Math.random() * 3);
        const startAngle = currentAngle;
        const targetAngle = currentAngle + (spinTurns * TWO_PI) + delta;

        const startTime = performance.now();
        const duration = 2400;
        const easeOutCubic = (t) => 1 - Math.pow(1 - t, 3);

        const animate = async (now) => {
          const elapsed = now - startTime;
          const progress = Math.min(1, elapsed / duration);
          const eased = easeOutCubic(progress);
          currentAngle = startAngle + ((targetAngle - startAngle) * eased);
          drawWheel(wheelSegments, currentAngle);
          if (progress < 1) {
            requestAnimationFrame(animate);
            return;
          }
          currentAngle = ((targetAngle % TWO_PI) + TWO_PI) % TWO_PI;
          drawWheel(wheelSegments, currentAngle);
          const result = winner?.name ?? 'No Prize';
          resultEl.textContent = `Result: ${result}`;
          spinning = false;
          spinBtn.disabled = false;

          if (!winner?.canDecrement) {
            return;
          }

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
