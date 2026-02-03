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
      echo json_encode(['status' => 'error', 'message' => 'نام جایزه ارسال نشده است.']);
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
      echo json_encode(['status' => 'error', 'message' => 'ذخیره جایزه‌ها انجام نشد.']);
      exit;
    }
    echo json_encode(['status' => 'ok', 'data' => $updated]);
    exit;
  }

  echo json_encode(['status' => 'error', 'message' => 'درخواست پشتیبانی نمی‌شود.']);
  exit;
}

$initialPrizes = readPrizeStore($prizeStorePath);
?>
<!doctype html>
<html lang="fa" dir="rtl">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>چرخ شانس</title>
    <link rel="icon" href="data:," />
    <style>
      :root {
        --bg: #f4f7fb;
        --phone: #ffffff;
        --ink: #1f2a44;
        --muted: #7f8baa;
        --line: #e8edf6;
        --accent: #2f8fff;
        --accent-ink: #ffffff;
        --soft-pink: #eef5ff;
        --soft-blue: #eef5ff;
        font-family: 'Peyda Fa Num', 'Segoe UI', Tahoma, Arial, sans-serif;
        color-scheme: light;
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
          linear-gradient(160deg, rgba(206, 227, 255, 0.5), rgba(244, 247, 251, 0) 40%),
          linear-gradient(330deg, rgba(215, 230, 255, 0.5), rgba(244, 247, 251, 0) 42%),
          var(--bg);
        color: var(--ink);
        padding: 18px;
        direction: rtl;
        text-align: right;
      }

      .app {
        width: min(460px, 100%);
      }

      .phone {
        width: 100%;
        min-height: min(860px, calc(100vh - 36px));
        background: var(--phone);
        border: 1px solid var(--line);
        border-radius: 28px;
        box-shadow:
          0 26px 50px rgba(29, 55, 96, 0.14),
          inset 0 1px 0 #fff;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        position: relative;
      }

      .topbar {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        padding: 14px 16px 10px;
      }

      .brand {
        margin: 0;
        font-size: 0.96rem;
        color: #506081;
        letter-spacing: 0.12em;
      }

      .hero {
        display: grid;
        place-items: center;
        padding: 10px 18px 4px;
      }

      .question {
        width: 82px;
        height: 82px;
        border-radius: 50%;
        display: grid;
        place-items: center;
        font-size: 2rem;
        font-weight: 700;
        color: #8da0c4;
        background: linear-gradient(180deg, #f9fbff, #eff4fb);
        border: 1px solid #e4ebf7;
      }

      .hint {
        margin: 10px 0 0;
        font-size: 0.85rem;
        color: var(--muted);
      }

      .result {
        margin: 10px auto 0;
        width: min(300px, calc(100% - 32px));
        border: 1px solid #e8edf6;
        border-radius: 14px;
        background: #f9fbff;
        display: grid;
        gap: 2px;
        justify-items: end;
        padding: 8px 10px;
      }

      .result-label {
        font-size: 0.78rem;
        color: #8a97b2;
      }

      .result-value {
        margin: 0;
        font-size: 1.2rem;
        font-weight: 700;
        color: #29365b;
        min-height: 1.3em;
        direction: rtl;
        unicode-bidi: plaintext;
        text-align: center;
        justify-self: stretch;
      }

      .wheel-shell {
        margin-top: auto;
        position: relative;
        height: min(360px, 47vh);
        background:
          linear-gradient(180deg, var(--soft-pink), #fff 44%, var(--soft-blue));
        border-top: 1px solid #edf1f8;
        display: grid;
        place-items: center;
        overflow: hidden;
      }

      .wheel-shell::before {
        content: '';
        position: absolute;
        width: 640px;
        height: 640px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.46);
        border: 1px solid #ecf0f7;
        left: 50%;
        top: 58%;
        transform: translate(-50%, -50%);
      }

      canvas {
        width: min(520px, 122vw);
        height: auto;
        background: #fff;
        border-radius: 50%;
        border: 2px solid #edf2fa;
        box-shadow:
          0 18px 34px rgba(30, 62, 108, 0.12),
          inset 0 0 0 1px rgba(255, 255, 255, 0.84);
        position: absolute;
        left: 50%;
        top: 14px;
        transform: translateX(-50%);
        z-index: 1;
      }

      .pointer {
        position: absolute;
        top: 10px;
        left: 50%;
        width: 0;
        height: 0;
        transform: translateX(-50%);
        border-left: 12px solid transparent;
        border-right: 12px solid transparent;
        border-top: 22px solid #2f8fff;
        z-index: 3;
        filter: drop-shadow(0 4px 8px rgba(41, 115, 214, 0.35));
      }

      .center-spin {
        position: absolute;
        left: 50%;
        top: calc(14px + min(520px, 122vw) / 2 - 30px);
        transform: translateX(-50%);
        border: none;
        border-radius: 999px;
        width: 90px;
        height: 90px;
        font-size: 0.95rem;
        font-weight: 700;
        font-family: inherit;
        color: var(--accent-ink);
        background: var(--accent);
        box-shadow:
          0 14px 24px rgba(47, 143, 255, 0.34),
          inset 0 1px 0 rgba(255, 255, 255, 0.46);
        cursor: pointer;
        z-index: 4;
        transition: transform 0.2s ease, opacity 0.2s ease;
      }

      .center-spin:hover:not(:disabled) {
        transform: translateX(-50%) translateY(-2px);
      }

      .center-spin:disabled {
        opacity: 0.45;
        cursor: not-allowed;
      }

      .wheel-count {
        position: absolute;
        right: 12px;
        top: 12px;
        z-index: 4;
        font-size: 0.72rem;
        font-weight: 600;
        color: #6f7c98;
        background: rgba(255, 255, 255, 0.82);
        border: 1px solid #e5ecf7;
        border-radius: 999px;
        padding: 4px 8px;
        direction: rtl;
        unicode-bidi: plaintext;
      }

      @media (max-width: 440px) {
        body {
          padding: 10px;
        }

        .phone {
          border-radius: 22px;
          min-height: calc(100vh - 20px);
        }

        .wheel-shell {
          height: min(340px, 48vh);
        }

        .center-spin {
          width: 82px;
          height: 82px;
          font-size: 0.88rem;
        }
      }
    </style>
  </head>
  <body>
    <main class="app">
      <section class="phone">
        <div class="topbar">
          <p class="brand">چرخ شانس</p>
        </div>

        <div class="hero">
          <div class="question">؟</div>
          <p class="hint">شانس خودت رو امتحان کن و جایزه ببر</p>
        </div>

        <div class="result">
          <span class="result-label">نتیجه</span>
          <p id="wf-result" class="result-value">—</p>
        </div>

        <div class="wheel-shell">
          <div class="pointer" aria-hidden="true"></div>
          <div id="wf-count" class="wheel-count">تعداد آیتم‌ها: —</div>
          <canvas id="wf-wheel" width="420" height="420" aria-label="چرخ جایزه"></canvas>
          <button id="wf-spin" class="center-spin" type="button">بچرخون</button>
        </div>
      </section>
    </main>

    <script>
      const initialPrizes = Array.isArray(<?= json_encode($initialPrizes, JSON_UNESCAPED_UNICODE); ?>)
        ? <?= json_encode($initialPrizes, JSON_UNESCAPED_UNICODE); ?>
        : [];

      const canvas = document.getElementById('wf-wheel');
      const ctx = canvas.getContext('2d');
      const spinBtn = document.getElementById('wf-spin');
      const resultEl = document.getElementById('wf-result');
      const countEl = document.getElementById('wf-count');
      const toFaDigits = (value) => String(value ?? '').replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[Number(d)]);

      const TWO_PI = Math.PI * 2;
      const MIN_VISIBLE_SEGMENTS = 10;
      const MAX_VISIBLE_SEGMENTS = 18;
      const SEGMENT_COLORS = [
        '#ffffff', '#f8fbff', '#f3f8ff', '#edf4ff', '#e8f1ff',
        '#e4efff', '#deebff', '#d9e7ff', '#d4e3ff', '#cfe0ff'
      ];

      let sourcePrizes = [];
      let wheelSegments = [];
      let currentAngle = 0;
      let spinning = false;
      let wheelSize = 420;

      const loadPrizeStore = async () => {
        try {
          const response = await fetch('WF%20Prizes.json', { cache: 'no-store' });
          const payload = await response.json();
          return Array.isArray(payload) ? payload : [];
        } catch {
          return [];
        }
      };

      const normalizeSourcePrizes = (list) => {
        if (!Array.isArray(list)) {
          return [{ name: 'بدون جایزه', wheelLabel: 'بدون جایزه', weight: 1, canDecrement: false }];
        }

        const normalized = list
          .map((item) => {
            const name = String(item?.name ?? '').trim();
            const onWheelName = String(item?.onWheelName ?? name).trim();
            const quantityValue = Number.parseInt(item?.quantity ?? 0, 10);
            const quantity = Number.isFinite(quantityValue) && quantityValue > 0 ? quantityValue : 0;
            const hasLast = item && Object.prototype.hasOwnProperty.call(item, 'last');
            const lastValue = Number.parseInt(item?.last ?? quantity, 10);
            const remaining = hasLast
              ? (Number.isFinite(lastValue) ? Math.max(0, lastValue) : 0)
              : quantity;
            return {
              name,
              wheelLabel: onWheelName || name,
              weight: remaining,
              canDecrement: name !== '' && remaining > 0
            };
          })
          .filter((prize) => prize.name !== '' && prize.weight > 0);

        return normalized.length
          ? normalized
          : [{ name: 'بدون جایزه', wheelLabel: 'بدون جایزه', weight: 1, canDecrement: false }];
      };

      const buildDisplaySegments = (prizes) => {
        if (!prizes.length) {
          return Array.from({ length: MIN_VISIBLE_SEGMENTS }, () => ({
            label: 'بدون جایزه',
            source: 'بدون جایزه',
            canDecrement: false
          }));
        }

        const maxWeight = Math.max(...prizes.map((prize) => prize.weight), 1);
        const counters = prizes.map((prize) => {
          const relative = prize.weight / maxWeight;
          return {
            name: prize.name,
            wheelLabel: prize.wheelLabel || prize.name,
            weight: prize.weight,
            canDecrement: prize.canDecrement,
            repeats: Math.max(2, Math.min(6, Math.round(relative * 4) + 1))
          };
        });

        let totalRepeats = counters.reduce((sum, item) => sum + item.repeats, 0);
        let fillIndex = 0;
        while (totalRepeats < MIN_VISIBLE_SEGMENTS) {
          counters[fillIndex % counters.length].repeats += 1;
          totalRepeats += 1;
          fillIndex += 1;
        }

        const minPerItem = (counters.length * 2 <= MAX_VISIBLE_SEGMENTS) ? 2 : 1;
        while (totalRepeats > MAX_VISIBLE_SEGMENTS) {
          const target = counters
            .filter((item) => item.repeats > minPerItem)
            .sort((a, b) => b.repeats - a.repeats)[0];
          if (!target) {
            break;
          }
          target.repeats -= 1;
          totalRepeats -= 1;
        }

        const sequence = [];
        const queue = counters.map((item) => ({
          name: item.name,
          wheelLabel: item.wheelLabel,
          canDecrement: item.canDecrement,
          remaining: item.repeats
        }));

        let lastName = '';
        while (true) {
          const candidates = queue
            .filter((item) => item.remaining > 0)
            .sort((a, b) => b.remaining - a.remaining);
          if (!candidates.length) {
            break;
          }
          const picked = candidates.find((item) => item.name !== lastName) || candidates[0];
          picked.remaining -= 1;
          sequence.push({
            label: picked.wheelLabel,
            source: picked.name,
            canDecrement: picked.canDecrement
          });
          lastName = picked.name;
        }

        if (sequence.length > 2 && sequence[0].source === sequence[sequence.length - 1].source) {
          const edgeName = sequence[0].source;
          let swapIndex = -1;
          for (let i = 1; i < sequence.length - 1; i += 1) {
            if (sequence[i].source !== edgeName) {
              swapIndex = i;
              break;
            }
          }
          if (swapIndex !== -1) {
            const last = sequence.length - 1;
            const temp = sequence[last];
            sequence[last] = sequence[swapIndex];
            sequence[swapIndex] = temp;
          }
        }

        return sequence;
      };

      const configureCanvas = () => {
        const rect = canvas.getBoundingClientRect();
        const cssSize = Math.max(320, Math.round(rect.width || 420));
        const dpr = window.devicePixelRatio || 1;
        canvas.width = Math.round(cssSize * dpr);
        canvas.height = Math.round(cssSize * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        wheelSize = cssSize;
      };

      const drawWheel = (segments, angle = 0) => {
        const size = wheelSize;
        const center = size / 2;
        const radius = center - 12;
        const count = Math.max(segments.length, 1);
        const slice = TWO_PI / count;
        const fontSize = Math.max(10, Math.min(14, 220 / count));

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
          ctx.fillStyle = SEGMENT_COLORS[i % SEGMENT_COLORS.length];
          ctx.fill();
          ctx.lineWidth = 1.15;
          ctx.strokeStyle = '#e5ecf8';
          ctx.stroke();

          ctx.save();
          ctx.rotate(start + (slice / 2));
          ctx.textAlign = 'right';
          ctx.textBaseline = 'middle';
          ctx.font = `700 ${fontSize}px "Peyda Fa Num", "Segoe UI", sans-serif`;
          ctx.fillStyle = '#33456e';
          const label = String(segments[i]?.label ?? '').slice(0, 16);
          ctx.fillText(label, radius - 14, 0);
          ctx.restore();
        }

        ctx.restore();
        ctx.beginPath();
        ctx.arc(center, center, radius, 0, TWO_PI);
        ctx.lineWidth = 2.6;
        ctx.strokeStyle = '#eef3fb';
        ctx.stroke();

        ctx.beginPath();
        ctx.arc(center, center, 24, 0, TWO_PI);
        ctx.fillStyle = '#2f8fff';
        ctx.fill();
        ctx.lineWidth = 3;
        ctx.strokeStyle = '#ffffff';
        ctx.stroke();
      };

      const weightedPrizePick = (prizes) => {
        const total = prizes.reduce((sum, prize) => sum + prize.weight, 0);
        if (total <= 0) {
          return prizes[0] ?? { name: 'بدون جایزه', canDecrement: false };
        }
        let roll = Math.random() * total;
        for (let i = 0; i < prizes.length; i += 1) {
          roll -= prizes[i].weight;
          if (roll <= 0) {
            return prizes[i];
          }
        }
        return prizes[prizes.length - 1];
      };

      const pickDisplayIndexForPrize = (segments, prizeName) => {
        const matches = [];
        segments.forEach((segment, index) => {
          if (segment.source === prizeName) {
            matches.push(index);
          }
        });
        if (!matches.length) {
          return 0;
        }
        return matches[Math.floor(Math.random() * matches.length)];
      };

      const initWheel = (list) => {
        sourcePrizes = normalizeSourcePrizes(list);
        wheelSegments = buildDisplaySegments(sourcePrizes);
        countEl.textContent = `تعداد آیتم‌ها: ${toFaDigits(wheelSegments.length)}`;
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

      configureCanvas();
      bootstrap();

      window.addEventListener('resize', () => {
        configureCanvas();
        drawWheel(wheelSegments, currentAngle);
      });

      spinBtn.addEventListener('click', async () => {
        if (spinning || !wheelSegments.length || !sourcePrizes.length) {
          return;
        }
        spinning = true;
        spinBtn.disabled = true;
        resultEl.textContent = '—';

        const winnerPrize = weightedPrizePick(sourcePrizes);
        const winnerIndex = pickDisplayIndexForPrize(wheelSegments, winnerPrize.name);
        const slice = TWO_PI / wheelSegments.length;
        const winnerCenter = (winnerIndex * slice) + (slice / 2);
        const desiredAngle = (-Math.PI / 2) - winnerCenter;
        const normalizedCurrent = ((currentAngle % TWO_PI) + TWO_PI) % TWO_PI;
        const delta = ((desiredAngle - normalizedCurrent) % TWO_PI + TWO_PI) % TWO_PI;
        const spinTurns = 7 + Math.floor(Math.random() * 3);
        const startAngle = currentAngle;
        const targetAngle = currentAngle + (spinTurns * TWO_PI) + delta;
        const startTime = performance.now();
        const duration = 2500;
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
          resultEl.textContent = winnerPrize?.name ?? 'بدون جایزه';
          spinning = false;
          spinBtn.disabled = false;

          if (!winnerPrize?.canDecrement) {
            return;
          }

          try {
            const response = await fetch('WFM.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ action: 'decrement_prize', name: winnerPrize.name })
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
