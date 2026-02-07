<?php
const SETTINGS_STORE_PATH = __DIR__ . '/../data/store.json';
const DEFAULT_PANEL_SETTINGS = [
  'siteIcon' => ''
];

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

function loadPanelSettings(): array
{
  $payload = loadJsonPayload(SETTINGS_STORE_PATH);
  $settings = is_array($payload['settings'] ?? null) ? $payload['settings'] : [];
  return array_merge(DEFAULT_PANEL_SETTINGS, $settings);
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
  return "../{$trimmed}";
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
      $onWheelName = trim((string)($item['onWheelName'] ?? $itemName));
      $isFake = (bool)($item['isFake'] ?? false);
      $quantity = (int)($item['quantity'] ?? 0);
      $last = (int)($item['last'] ?? $quantity);
      if ($itemName !== '' && $itemName === $name && !$isFake) {
        $last = max(0, $last - 1);
      }
      if ($itemName !== '') {
        $updated[] = [
          'name' => $itemName,
          'onWheelName' => $onWheelName !== '' ? $onWheelName : $itemName,
          'quantity' => $quantity > 0 ? $quantity : 0,
          'last' => $last > 0 ? $last : 0,
          'isFake' => $isFake
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
$wheelSettings = loadJsonPayload(__DIR__ . '/Setting.json');
$panelSettings = loadPanelSettings();
$faviconUrl = formatSiteIconUrlForHtml((string)($panelSettings['siteIcon'] ?? ''));
function sanitizeHintHtml(string $html): string
{
  $allowed = '<br><b><strong><em><a><div><span><p>';
  $clean = strip_tags($html, $allowed);
  $clean = preg_replace('/\s+on\w+="[^"]*"/i', '', $clean);
  $clean = preg_replace("/\s+on\w+='[^']*'/i", '', $clean);
  $clean = preg_replace_callback('/\sclass="([^"]*)"/i', function ($matches) {
    $classes = preg_split('/\s+/', trim($matches[1]));
    $allowedClasses = array_filter($classes, function ($class) {
      return preg_match('/^ql-align-(right|center|left|justify)$/', $class);
    });
    if (!$allowedClasses) {
      return '';
    }
    return ' class="' . implode(' ', $allowedClasses) . '"';
  }, $clean);
  $clean = preg_replace_callback('/<a\s+[^>]*href=(["\'])(.*?)\1[^>]*>/i', function ($matches) {
    $href = trim($matches[2]);
    if (!preg_match('#^(https?:|mailto:|tel:|/|#)#i', $href)) {
      $href = '#';
    }
    $tag = $matches[0];
    $tag = preg_replace('/\s+href=(["\']).*?\1/i', ' href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"', $tag);
    if (!preg_match('/\s+rel=/i', $tag)) {
      $tag = rtrim($tag, '>') . ' rel="noopener">';
    }
    if (!preg_match('/\s+target=/i', $tag)) {
      $tag = rtrim($tag, '>') . ' target="_blank">';
    }
    return $tag;
  }, $clean);
  $clean = trim($clean);
  if ($clean === '') {
    return '';
  }
  // Normalize Quill paragraphs into a single paragraph with <br> separators.
  $clean = preg_replace('/<p>\s*<\/p>/i', '', $clean);
  $clean = preg_replace('/<\/p>\s*<p[^>]*>/i', '<br>', $clean);
  $clean = preg_replace('/<p[^>]*>/i', '', $clean);
  $clean = str_replace('</p>', '', $clean);
  $clean = preg_replace('/(<br>\s*){2,}/i', '<br>', $clean);
  return trim($clean);
}

$rawHintHtml = (string)($wheelSettings['hintHtml'] ?? '');
$hintTextFallback = trim((string)($wheelSettings['hint'] ?? ''));
if ($rawHintHtml === '' && $hintTextFallback !== '') {
  $rawHintHtml = htmlspecialchars($hintTextFallback, ENT_QUOTES, 'UTF-8');
}
if ($rawHintHtml === '') {
  $rawHintHtml = htmlspecialchars('شانس خودت رو امتحان کن و جایزه ببر', ENT_QUOTES, 'UTF-8');
}
$hintHtml = sanitizeHintHtml($rawHintHtml);
$hintAlign = trim((string)($wheelSettings['hintAlign'] ?? 'right'));
$hintAlign = in_array($hintAlign, ['right', 'center', 'left'], true) ? $hintAlign : 'right';
?>
<!doctype html>
<html lang="fa" dir="rtl">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>چرخ جایزه شگفتانه همراه‌اول</title>
    <link rel="icon" href="<?= htmlspecialchars($faviconUrl ?: 'data:,', ENT_QUOTES, 'UTF-8') ?>" />
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

      @supports (height: 100dvh) {
        body {
          min-height: 100dvh;
        }
      }

      .page-loading body {
        overflow: hidden;
      }

      .page-loading .app {
        opacity: 0;
        pointer-events: none;
      }

      .loader-overlay {
        position: fixed;
        inset: 0;
        background:
          radial-gradient(circle at top, rgba(223, 236, 255, 0.9), rgba(244, 247, 251, 0.92) 50%, rgba(255, 255, 255, 0.95));
        display: grid;
        place-items: center;
        z-index: 9999;
        transition: opacity 0.35s ease;
      }

      .loader-card {
        width: min(280px, 80vw);
        padding: 10px 8px;
        text-align: center;
        display: grid;
        gap: 12px;
        background: transparent;
        border: none;
        box-shadow: none;
      }

      .loader-icon-wrap {
        width: 96px;
        height: 96px;
        margin: 0 auto;
        position: relative;
        display: grid;
        place-items: center;
      }

      .loader-icon-svg {
        width: 72px;
        height: 48px;
        display: block;
      }

      .loader-icon-fill {
        fill: rgba(47, 143, 255, 0.16);
      }

      .loader-icon-path {
        fill: none;
        stroke: #2f8fff;
        stroke-width: 22;
        stroke-linecap: round;
        stroke-linejoin: round;
        stroke-dasharray: 2200;
        stroke-dashoffset: 2200;
        animation: wf-icon-stroke 1.6s ease-in-out infinite;
      }

      .loader-text {
        margin: 0;
        font-size: 0.9rem;
        color: #516089;
        font-weight: 600;
      }

      .loader-subtext {
        margin: 0;
        font-size: 0.78rem;
        color: #8a97b2;
      }

      .loader-hidden {
        opacity: 0;
        pointer-events: none;
      }

      @keyframes wf-icon-stroke {
        0% {
          stroke-dashoffset: 2200;
          opacity: 0.6;
        }
        50% {
          stroke-dashoffset: 900;
          opacity: 1;
        }
        100% {
          stroke-dashoffset: 0;
          opacity: 0.75;
        }
      }

      .app {
        width: min(460px, 100%);
      }

      .phone {
        width: 100%;
        min-height: min(860px, calc(100vh - 36px));
        height: calc(100vh - 36px);
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

      .main-area {
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: center;
        gap: 12px;
        padding: 10px 18px 4px;
      }

      .hero {
        display: grid;
        place-items: center;
      }

      .question {
        width: 82px;
        height: 82px;
        border-radius: 50%;
        display: grid;
        place-items: center;
        background: #f3f7ff;
        border: 1px solid #e4ebf7;
        overflow: hidden;
      }

      .hero-icon {
        width: 82px;
        height: 82px;
        object-fit: contain;
        display: block;
      }

      .question span {
        font-size: 2rem;
        font-weight: 700;
        color: #8da0c4;
      }

      .hint {
        margin: 10px 0 0;
        font-size: 0.85rem;
        color: var(--muted);
        padding: 0 16px;
        line-height: 1.35;
      }

      .hint p {
        margin: 0;
      }

      .hint-align-right {
        text-align: right;
      }

      .hint-align-center {
        text-align: center;
      }

      .hint-align-left {
        text-align: left;
      }

      .ql-align-center {
        text-align: center;
        padding: 0 30px;
      }

      .ql-align-right {
        text-align: right;
      }

      .ql-align-left {
        text-align: left;
      }

      .ql-align-justify {
        text-align: justify;
      }

      .result {
        margin: 0 auto;
        width: min(300px, calc(100% - 32px));
        border: 1px solid #e8edf6;
        border-radius: 14px;
        background: #f9fbff;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 2px;
        padding: 8px 10px;
        height: 3.2em;
        position: relative;
        overflow: hidden;
      }

      .result::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(120deg, rgba(47, 143, 255, 0) 0%, rgba(47, 143, 255, 0.65) 45%, rgba(47, 143, 255, 0) 75%);
        transform: translateX(-130%);
        opacity: 0;
        pointer-events: none;
      }

      .result.result-shine::after {
        animation: result-shine 1.5s ease-in-out infinite;
      }

      .result.result-shake {
        animation: result-shake 0.4s ease-in-out 2;
      }

      @keyframes result-shake {
        0% { transform: translateX(0); }
        20% { transform: translateX(-4px); }
        40% { transform: translateX(4px); }
        60% { transform: translateX(-3px); }
        80% { transform: translateX(3px); }
        100% { transform: translateX(0); }
      }

      .confetti-layer {
        position: fixed;
        inset: 0;
        pointer-events: none;
        overflow: hidden;
        z-index: 9998;
      }

      .confetti-piece {
        position: absolute;
        width: 8px;
        height: 16px;
        opacity: 0;
        animation: confetti-fall 1.6s ease-out forwards;
        --drift: 0px;
      }

      @keyframes confetti-fall {
        0% {
          transform: translate3d(0, -20px, 0) rotate(0deg);
          opacity: 0;
        }
        10% {
          opacity: 1;
        }
        100% {
          transform: translate3d(var(--drift), 280px, 0) rotate(240deg);
          opacity: 0;
        }
      }

      @keyframes result-shine {
        0% {
          transform: translateX(-150%);
          opacity: 0;
        }
        40% {
          opacity: 0.75;
        }
        100% {
          transform: translateX(150%);
          opacity: 0;
        }
      }

      .result-label {
        display: none;
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
        align-self: stretch;
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
        opacity: 1;
        cursor: not-allowed;
        background: #c7d2e5;
        color: #6b7a99;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.6);
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

        .main-area {
          padding: 6px 16px 2px;
        }

        .center-spin {
          width: 82px;
          height: 82px;
          font-size: 0.88rem;
        }
      }

      @supports (height: 100dvh) {
        .phone {
          min-height: min(860px, calc(100dvh - 36px));
          height: calc(100dvh - 36px);
        }
      }
    </style>
  </head>
  <body class="page-loading">
    <div id="wf-loader" class="loader-overlay" role="status" aria-live="polite">
      <div class="loader-card">
        <div class="loader-icon-wrap" aria-hidden="true">
          <svg class="loader-icon-svg" viewBox="0 0 1173 773" aria-hidden="true" focusable="false">
            <path class="loader-icon-fill" d="M1173 407.266V773C791.7 589.486 381.3 521.402 0 573.591V16.8977C319.721 -26.5479 659.341 13.9796 985.446 136.213C1099.03 178.364 1173 286.979 1173 406.947V407.266Z" />
            <path class="loader-icon-path" d="M1173 407.266V773C791.7 589.486 381.3 521.402 0 573.591V16.8977C319.721 -26.5479 659.341 13.9796 985.446 136.213C1099.03 178.364 1173 286.979 1173 406.947V407.266Z" />
          </svg>
        </div>
        <p class="loader-text">در حال آماده‌سازی شگفتانه شما</p>
        <p class="loader-subtext">لطفاً چند لحظه صبر کنید</p>
      </div>
    </div>
    <div id="wf-confetti" class="confetti-layer" aria-hidden="true"></div>
    <main class="app">
      <section class="phone">
        <div class="topbar">
          <p class="brand">چرخ جایزه شگفتانه همراه‌اول</p>
        </div>

        <div class="main-area">
          <div class="hero">
            <?php if ($faviconUrl !== ''): ?>
              <img class="hero-icon" src="<?= htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8') ?>" alt="آیکون سایت" />
            <?php else: ?>
              <div class="question">
                <span>؟</span>
              </div>
            <?php endif; ?>
            <p class="hint hint-align-<?= htmlspecialchars($hintAlign, ENT_QUOTES, 'UTF-8') ?>"><?= $hintHtml ?></p>
          </div>

          <div class="result">
            <span class="result-label">نتیجه</span>
            <p id="wf-result" class="result-value">—</p>
          </div>
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
      const loaderEl = document.getElementById('wf-loader');
      const bodyEl = document.body;
      const loaderStart = performance.now();
      const minLoaderDuration = 1300;

      const waitForFonts = async () => {
        if (!document.fonts) {
          return;
        }
        try {
          await Promise.all([
            document.fonts.load('400 16px "Peyda Fa Num"'),
            document.fonts.load('700 16px "Peyda Fa Num"'),
            document.fonts.ready
          ]);
        } catch {}
      };

      const revealPage = () => {
        bodyEl.classList.remove('page-loading');
        if (loaderEl) {
          loaderEl.classList.add('loader-hidden');
          setTimeout(() => loaderEl.remove(), 450);
        }
      };

      const bootReady = async () => {
        try {
          await Promise.all([
            waitForFonts(),
            new Promise(resolve => window.addEventListener('load', resolve, { once: true }))
          ]);
        } catch {}
        const elapsed = performance.now() - loaderStart;
        if (elapsed < minLoaderDuration) {
          await new Promise(resolve => setTimeout(resolve, minLoaderDuration - elapsed));
        }
        revealPage();
      };

      bootReady();

      const initialPrizes = Array.isArray(<?= json_encode($initialPrizes, JSON_UNESCAPED_UNICODE); ?>)
        ? <?= json_encode($initialPrizes, JSON_UNESCAPED_UNICODE); ?>
        : [];

      const canvas = document.getElementById('wf-wheel');
      const ctx = canvas.getContext('2d');
      const spinBtn = document.getElementById('wf-spin');
      const resultEl = document.getElementById('wf-result');
      const resultBox = document.querySelector('.result');
      const confettiLayer = document.getElementById('wf-confetti');
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
            const isFake = Boolean(item?.isFake);
            const hasLast = item && Object.prototype.hasOwnProperty.call(item, 'last');
            const lastValue = Number.parseInt(item?.last ?? quantity, 10);
            const remaining = hasLast
              ? (Number.isFinite(lastValue) ? Math.max(0, lastValue) : 0)
              : quantity;
            return {
              name,
              wheelLabel: onWheelName || name,
              weight: remaining,
              canDecrement: name !== '' && remaining > 0 && !isFake,
              isFake,
              displayWeight: remaining > 0 ? remaining : 1
            };
          })
          .filter((prize) => prize.name !== '');

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

        const maxWeight = Math.max(...prizes.map((prize) => prize.displayWeight), 1);
        const counters = prizes.map((prize) => {
          const relative = prize.displayWeight / maxWeight;
          return {
            name: prize.name,
            wheelLabel: prize.wheelLabel || prize.name,
            weight: prize.weight,
            canDecrement: prize.canDecrement,
            isFake: prize.isFake,
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
          isFake: item.isFake,
          remaining: item.repeats
        }));

        let lastName = '';
        let lastWasFake = false;
        while (true) {
          const candidates = queue
            .filter((item) => item.remaining > 0)
            .sort((a, b) => b.remaining - a.remaining);
          if (!candidates.length) {
            break;
          }
          let picked = candidates.find((item) => item.name !== lastName && (!lastWasFake || !item.isFake));
          if (!picked) {
            picked = candidates.find((item) => item.name !== lastName) || candidates[0];
          }
          picked.remaining -= 1;
          sequence.push({
            label: picked.wheelLabel,
            source: picked.name,
            canDecrement: picked.canDecrement,
            isFake: picked.isFake
          });
          lastName = picked.name;
          lastWasFake = picked.isFake;
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

        if (sequence.length > 2 && sequence[0].isFake && sequence[sequence.length - 1].isFake) {
          let swapIndex = -1;
          for (let i = 1; i < sequence.length - 1; i += 1) {
            if (!sequence[i].isFake) {
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

      const initApp = async () => {
        await waitForFonts();
        configureCanvas();
        await bootstrap();

        window.addEventListener('resize', () => {
          configureCanvas();
          drawWheel(wheelSegments, currentAngle);
        });
      };

      initApp();

      spinBtn.addEventListener('click', async () => {
        if (spinning || !wheelSegments.length || !sourcePrizes.length) {
          return;
        }
        spinning = true;
        spinBtn.disabled = true;
        resultEl.textContent = '—';
        if (resultBox) {
          resultBox.classList.remove('result-shine');
        }

        const fakeItems = sourcePrizes.filter((item) => item.isFake);
        const realItems = sourcePrizes.filter((item) => !item.isFake && item.weight > 0);
        let winnerPrize;
        if (fakeItems.length && realItems.length) {
          const pickFake = Math.random() < 0.5;
          winnerPrize = pickFake
            ? fakeItems[Math.floor(Math.random() * fakeItems.length)]
            : weightedPrizePick(realItems);
        } else if (fakeItems.length) {
          winnerPrize = fakeItems[Math.floor(Math.random() * fakeItems.length)];
        } else {
          winnerPrize = weightedPrizePick(realItems.length ? realItems : sourcePrizes);
        }
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
        const duration = 7000;
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
          if (resultBox) {
            resultBox.classList.remove('result-shine');
            resultBox.classList.remove('result-shake');
          }
          const isFake = Boolean(winnerPrize?.isFake);
          if (resultBox && isFake) {
            void resultBox.offsetWidth;
            resultBox.classList.add('result-shake');
            const finalText = 'دوباره امتحان کن';
            let flips = 0;
            const flipMax = 5;
            const flipTimer = setInterval(() => {
              resultEl.textContent = (flips % 2 === 0) ? finalText : (winnerPrize?.name ?? finalText);
              flips += 1;
              if (flips >= flipMax) {
                clearInterval(flipTimer);
                resultEl.textContent = finalText;
              }
            }, 140);
          }
          if (resultBox && !isFake) {
            resultBox.classList.add('result-shine');
          }
          if (confettiLayer && !isFake) {
            const colors = ['#1f7bdc', '#2f8fff', '#4da3ff', '#6bb6ff', '#8ac8ff', '#b3dcff'];
            const width = window.innerWidth;
            const height = window.innerHeight;
            const count = Math.max(28, Math.min(52, Math.round(width / 22)));
            confettiLayer.innerHTML = '';
            for (let i = 0; i < count; i += 1) {
              const piece = document.createElement('span');
              piece.className = 'confetti-piece';
              const fromLeft = Math.random() < 0.5;
              const sideOffset = Math.random() * 26 + 2;
              piece.style[fromLeft ? 'left' : 'right'] = `${sideOffset}px`;
              piece.style.top = `${Math.random() * (height * 0.45)}px`;
              piece.style.background = colors[i % colors.length];
              piece.style.animationDelay = `${Math.random() * 0.25}s`;
              const drift = fromLeft ? (Math.random() * 120 + 40) : -(Math.random() * 120 + 40);
              piece.style.setProperty('--drift', `${drift}px`);
              piece.style.transform = `translate3d(0, 0, 0) rotate(${Math.random() * 180}deg)`;
              confettiLayer.appendChild(piece);
            }
            setTimeout(() => {
              confettiLayer.innerHTML = '';
            }, 2000);
          }
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
