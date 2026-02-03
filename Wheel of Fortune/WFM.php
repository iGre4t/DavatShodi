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
        --ink: #e8f2ff;
        --muted: #a7bdd8;
        --panel-top: rgba(20, 43, 84, 0.8);
        --panel-bottom: rgba(8, 22, 53, 0.92);
        --ring: rgba(175, 213, 255, 0.26);
        --btn-top: #87ceff;
        --btn-bottom: #2f7dcf;
        --btn-text: #042342;
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
          radial-gradient(circle at 12% 15%, rgba(141, 199, 255, 0.32), transparent 42%),
          radial-gradient(circle at 85% 20%, rgba(98, 150, 232, 0.28), transparent 46%),
          linear-gradient(135deg, #020d24 0%, #06204b 45%, #0d3f7e 100%);
        color: var(--ink);
        padding: 24px;
        overflow: hidden;
      }

      body::before,
      body::after {
        content: '';
        position: fixed;
        width: 360px;
        height: 360px;
        border-radius: 50%;
        filter: blur(6px);
        border: 1px solid rgba(161, 209, 255, 0.22);
        pointer-events: none;
      }

      body::before {
        top: -130px;
        left: -110px;
        background: radial-gradient(circle, rgba(118, 176, 255, 0.22) 0%, transparent 70%);
      }

      body::after {
        right: -120px;
        bottom: -150px;
        background: radial-gradient(circle, rgba(96, 164, 250, 0.18) 0%, transparent 70%);
      }

      .shell {
        width: min(560px, 100%);
        padding: 32px 28px;
        border-radius: 32px;
        background:
          linear-gradient(180deg, var(--panel-top), var(--panel-bottom)),
          radial-gradient(circle at 25% 15%, rgba(148, 209, 255, 0.15), transparent 50%);
        border: 1px solid var(--ring);
        box-shadow:
          0 20px 42px rgba(1, 11, 33, 0.62),
          inset 0 1px 0 rgba(222, 239, 255, 0.18);
        backdrop-filter: blur(8px);
        display: flex;
        flex-direction: column;
        gap: 24px;
        align-items: center;
        text-align: center;
        animation: shellIn 500ms ease-out;
      }

      @keyframes shellIn {
        from {
          opacity: 0;
          transform: translateY(14px) scale(0.985);
        }
        to {
          opacity: 1;
          transform: translateY(0) scale(1);
        }
      }

      .title {
        font-size: 1rem;
        letter-spacing: 0.44em;
        color: var(--muted);
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
        background: radial-gradient(circle, #eff7ff 0%, #d7e9ff 100%);
        border-radius: 50%;
        border: 9px solid #f6fbff;
        box-shadow:
          0 16px 36px rgba(5, 15, 40, 0.52),
          inset 0 0 0 3px rgba(95, 146, 219, 0.38);
      }

      .result {
        font-size: 1.4rem;
        letter-spacing: 0.03em;
        color: #d8ebff;
        margin: 0;
        font-weight: 700;
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
        background: linear-gradient(135deg, var(--btn-top), var(--btn-bottom));
        color: var(--btn-text);
        box-shadow:
          0 12px 24px rgba(20, 86, 170, 0.42),
          inset 0 1px 0 rgba(255, 255, 255, 0.48);
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
          letter-spacing: 0.27em;
          font-size: 0.92rem;
        }

        .result {
          font-size: 1.2rem;
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
          const lastValue = Number.parseInt(item.last ?? item.quantity ?? 0, 10);
          const qtyValue = Number.parseInt(item.quantity ?? 0, 10);
          const qty = Number.isFinite(lastValue) && lastValue > 0
            ? lastValue
            : (Number.isFinite(qtyValue) && qtyValue > 0 ? qtyValue : 0);
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
        const palette = ['#eff6ff', '#dbeafe', '#bfdbfe', '#93c5fd', '#60a5fa', '#3b82f6'];
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
          ctx.fillStyle = palette[i % palette.length];
          ctx.fill();
          ctx.strokeStyle = 'rgba(11, 53, 116, 0.4)';
          ctx.lineWidth = 1.5;
          ctx.stroke();
          ctx.save();
          ctx.rotate(start + slice / 2);
          ctx.textAlign = 'right';
          ctx.fillStyle = '#042955';
          ctx.font = '700 14px "Peyda Fa Num", "Segoe UI", sans-serif';
          ctx.fillText(names[i], radius - 12, 5);
          ctx.restore();
        }
        ctx.restore();

        ctx.fillStyle = '#0a3266';
        ctx.beginPath();
        ctx.moveTo(center, 10);
        ctx.lineTo(center - 10, 30);
        ctx.lineTo(center + 10, 30);
        ctx.closePath();
        ctx.fill();

        ctx.beginPath();
        ctx.arc(center, center, 18, 0, Math.PI * 2);
        ctx.fillStyle = '#0f3e7d';
        ctx.fill();
        ctx.lineWidth = 3;
        ctx.strokeStyle = '#d9ecff';
        ctx.stroke();
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

        const animate = async (now) => {
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
