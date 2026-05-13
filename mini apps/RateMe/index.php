<?php
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

function formatAssetUrl(string $value): string
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
  return "../../{$trimmed}";
}

function normalizeHexColor($value, string $fallback): string
{
  $color = strtoupper(trim((string)$value));
  if (preg_match('/^#[0-9A-F]{6}$/', $color)) {
    return $color;
  }
  return strtoupper($fallback);
}

function loadPanelSettings(): array
{
  $defaults = ['siteIcon' => ''];
  $payload = loadJsonPayload(__DIR__ . '/../../data/store.json');
  $settings = is_array($payload['settings'] ?? null) ? $payload['settings'] : [];
  return array_merge($defaults, $settings);
}

$settings = loadJsonPayload(__DIR__ . '/Setting.json');
$panelSettings = loadPanelSettings();
$eventColors = is_array($settings['eventColors'] ?? null) ? $settings['eventColors'] : [];
$eventSecondary = normalizeHexColor($eventColors['secondary'] ?? '', '#2F8FFF');
$eventHighlight = normalizeHexColor($eventColors['highlight'] ?? '', '#20C997');
$eventAccentSoft = normalizeHexColor($eventColors['accentSoft'] ?? '', '#FFB347');
$eventLogoUrl = formatAssetUrl((string)($settings['eventLogo'] ?? ''));
$siteIconUrl = formatAssetUrl((string)($panelSettings['siteIcon'] ?? ''));
$faviconUrl = $eventLogoUrl !== '' ? $eventLogoUrl : $siteIconUrl;
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title>تست زندگی ادامه دارد</title>
  <link rel="icon" href="<?= htmlspecialchars($faviconUrl ?: 'data:,', ENT_QUOTES, 'UTF-8') ?>" />
  <style>
    :root {
      --bg: #f4f7fb;
      --phone: #ffffff;
      --ink: #1f2a44;
      --muted: #6f7d9a;
      --line: #e5ecf7;
      --tc-secondary: <?= htmlspecialchars($eventSecondary, ENT_QUOTES, 'UTF-8') ?>;
      --tc-highlight: <?= htmlspecialchars($eventHighlight, ENT_QUOTES, 'UTF-8') ?>;
      --tc-accent-soft: <?= htmlspecialchars($eventAccentSoft, ENT_QUOTES, 'UTF-8') ?>;
      font-family: 'Peyda Fa Num', 'Segoe UI', Tahoma, Arial, sans-serif;
      color-scheme: light;
    }

    @font-face {
      font-family: 'Peyda Fa Num';
      src: url('../../style/fonts/PeydaWebFaNum-Regular.woff2') format('woff2');
      font-weight: 400;
      font-style: normal;
      font-display: swap;
    }

    @font-face {
      font-family: 'Peyda Fa Num';
      src: url('../../style/fonts/PeydaWebFaNum-Bold.woff2') format('woff2');
      font-weight: 700;
      font-style: normal;
      font-display: swap;
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 14px;
      color: var(--ink);
      background:
        radial-gradient(circle at top right, color-mix(in srgb, var(--tc-highlight) 20%, transparent), transparent 46%),
        radial-gradient(circle at bottom left, color-mix(in srgb, var(--tc-secondary) 18%, transparent), transparent 45%),
        var(--bg);
    }

    .page-loading {
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
      stroke-dasharray: 950 1250;
      stroke-dashoffset: 0;
      animation: tc-icon-stroke 2.4s linear infinite;
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

    @keyframes tc-icon-stroke {
      0% {
        stroke-dashoffset: 0;
        opacity: 0.85;
      }
      50% {
        opacity: 1;
      }
      100% {
        stroke-dashoffset: -2200;
        opacity: 0.85;
      }
    }

    .app {
      width: min(460px, 100%);
    }

    .phone {
      width: 100%;
      min-height: calc(100vh - 28px);
      background: var(--phone);
      border: 1px solid var(--line);
      border-radius: 28px;
      box-shadow: 0 24px 52px rgba(29, 55, 96, 0.16), inset 0 1px 0 #fff;
      overflow: hidden;
      display: flex;
      flex-direction: column;
    }

    .topbar {
      padding: 16px 16px 10px;
      border-bottom: 1px solid #eef3fb;
      background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .brand {
      margin: 0;
      color: #24395f;
      font-size: .9rem;
      font-weight: 600;
    }

    .brand-icon {
      width: auto;
      height: 24px;
      max-width: 72px;
      border: 0;
      border-radius: 0;
      box-shadow: none;
      object-fit: contain;
      background: transparent;
    }

    .hero {
      padding: 18px 18px 8px;
      text-align: center;
    }

    .event-logo {
      height: 88px;
      width: auto;
      max-width: min(240px, 72vw);
      object-fit: contain;
      margin-bottom: 10px;
    }


    .title {
      margin: 0;
      font-size: 1.2rem;
      color: var(--tc-highlight);
      font-weight: 700;
    }

    .title .highlight {
      color: var(--tc-highlight);
    }

    .title .ohterparts {
      color: #444;
}

    .subtitle {
      margin: 8px 0 0;
      color: var(--muted);
      font-size: .9rem;
      line-height: 1.9;
    }

    .badge {
      margin: 12px auto 0;
      width: fit-content;
      border-radius: 999px;
      padding: 6px 12px;
      font-size: .8rem;
      font-weight: 700;
      color: #0f3f6e;
      background: color-mix(in srgb, var(--tc-accent-soft) 36%, #fff);
      border: 1px solid color-mix(in srgb, var(--tc-accent-soft) 55%, #fff);
    }

    .content {
      padding: 8px 16px 18px;
      overflow-y: auto;
    }

    .section {
      border: 1px solid #e9eff9;
      border-radius: 16px;
      background: #fdfefe;
      padding: 12px 12px 10px;
      margin-bottom: 10px;
    }

    .section h3 {
      margin: 0 0 8px;
      color: var(--tc-highlight);
      font-size: .95rem;
      font-weight: 700;
    }

    .section p {
      margin: 0;
      color: #334b74;
      font-size: .87rem;
      line-height: 1.95;
    }

    .list {
      margin: 0;
      padding-right: 18px;
      color: #334b74;
      font-size: .87rem;
      line-height: 1.95;
    }

    .list li { margin-bottom: 6px; }

    .cta-wrap {
      padding: 4px 16px 16px;
    }

    .cta {
      width: 100%;
      border: 0;
      border-radius: 14px;
      font-family: inherit;
      font-size: .95rem;
      font-weight: 700;
      color: #fff;
      background: var(--tc-secondary);
      padding: 13px 16px;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 14px 30px color-mix(in srgb, var(--tc-secondary) 40%, transparent);
    }

    .support {
      text-align: center;
      font-size: .8rem;
      color: var(--muted);
      margin-top: 8px;
    }

    .support b { color: var(--tc-secondary); }

    @media (min-width: 680px) {
      body { padding: 24px; }
      .phone { min-height: min(860px, calc(100vh - 48px)); }
    }
  </style>
</head>
<body class="page-loading">
  <div id="tc-loader" class="loader-overlay" role="status" aria-live="polite">
    <div class="loader-card">
      <div class="loader-icon-wrap" aria-hidden="true">
        <svg class="loader-icon-svg" viewBox="0 0 1173 773" aria-hidden="true" focusable="false">
          <path class="loader-icon-fill" d="M1173 407.266V773C791.7 589.486 381.3 521.402 0 573.591V16.8977C319.721 -26.5479 659.341 13.9796 985.446 136.213C1099.03 178.364 1173 286.979 1173 406.947V407.266Z" />
          <path class="loader-icon-path" d="M1173 407.266V773C791.7 589.486 381.3 521.402 0 573.591V16.8977C319.721 -26.5479 659.341 13.9796 985.446 136.213C1099.03 178.364 1173 286.979 1173 406.947V407.266Z" />
        </svg>
      </div>
      <p class="loader-text">در حال آماده سازی</p>
      <p class="loader-subtext">لطفا چند لحظه صبر کنید</p>
    </div>
  </div>

  <main class="app">
    <section class="phone">
      <header class="topbar">
        <?php if ($siteIconUrl !== ''): ?>
          <img class="brand-icon" src="<?= htmlspecialchars($siteIconUrl, ENT_QUOTES, 'UTF-8') ?>" alt="آیکن سایت" />
        <?php endif; ?>
        <p class="brand">همراه‌اول</p>
      </header>

      <section class="hero">
        <?php if ($eventLogoUrl !== ''): ?>
          <img class="event-logo" src="<?= htmlspecialchars($eventLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="لوگوی رویداد" />
        <?php endif; ?>
        <h1 class="title">
  <span class="ohterparts">تست روان‌شناختی</span>
  <span class="highlight">«زندگی ادامه دارد»</span>
  <br>
  <span class="ohterparts">فرصتی برای مکث و خودارزیابی</span>
</h1>
        <p class="subtitle">
          «زندگی ادامه دارد» یک تست کوتاه روان‌شناختی و خودارزیابی است که از پنجشنبه 24 اردیبهشت تا شنبه 26 اردیبهشت در دسترس خواهد بود. این صفحه فقط برای معرفی این تست و توضیح نحوه شرکت در آن آماده شده است.
        </p>
        <div class="badge">زمان برگزاری: پنجشنبه 24 اردیبهشت تا شنبه 26 اردیبهشت</div>
      </section>

      <div class="content">
        <section class="section">
          <h3>درباره تست</h3>
          <p>
            تست «زندگی ادامه دارد» برای این طراحی شده است که افراد بتوانند نگاهی کوتاه‌تر و دقیق‌تر به وضعیت روانی، ذهنی و حال این روزهای خود داشته باشند. این صفحه یک فضای ساده برای انجام یک ارزیابی فردی است، نه یک مسیر رقابتی یا چالشی.
          </p>
        </section>

        <section class="section">
          <h3>محتوای این صفحه</h3>
          <ul class="list">
            <li><b>معرفی تست:</b> توضیح کوتاهی درباره هدف و فضای ارزیابی.</li>
            <li><b>راهنمای شرکت:</b> اطلاعات لازم برای ورود و انجام تست.</li>
            <li><b>پشتیبانی:</b> مسیر ارتباط در صورت بروز سوال یا ابهام.</li>
          </ul>
        </section>

        <section class="section">
          <h3>نحوه شرکت</h3>
          <p>
            کافی است در بازه اعلام‌شده وارد صفحه شوید، با نام کاربری و رمز عبور خود وارد شوید و پرسش‌های تست را براساس وضعیت واقعی خود پاسخ دهید. این صفحه فقط برای انجام همان تست طراحی شده است.
          </p>
        </section>

        <section class="section">
          <h3>نکات مهم</h3>
          <ul class="list">
            <li>زمان انجام تست از <b>پنجشنبه 24 اردیبهشت</b> تا <b>شنبه 26 اردیبهشت</b> است.</li>
            <li>این صفحه مربوط به یک <b>تست روان‌شناختی</b> است و بخش چالشی یا رقابتی ندارد.</li>
            <li>پاسخ‌ها را براساس وضعیت واقعی خود ثبت کنید.</li>
            <li>در این تست امتیازدهی، جایزه یا سازوکار رقابتی فعال نیست.</li>
          </ul>
        </section>

        <section class="section">
          <h3>یادآوری</h3>
          <p>
            «زندگی ادامه دارد» فقط یک تست برای خودارزیابی و شناخت بهتر حال این روزهاست. هدف این صفحه، فراهم کردن یک تجربه ساده، آرام و روشن برای پاسخ‌دادن به پرسش‌هاست.
          </p>
        </section>

        <section class="section">
          <h3>ورود و اطلاعات حساب</h3>
          <p>
            نام کاربری و رمز عبور اختصاصی هر فرد از طریق سرشماره <b>8919</b> به شماره تلفن همراه ثبت‌شده در سازمان پیامک می‌شود.اطلاعات ورود کاملاً محرمانه و شخصی است و استفاده مشترک از حساب کاربری مجاز نیست.
          </p>
        </section>

        <section class="section">
          <h3>پشتیبانی</h3>
          <p>
            در صورت وجود سوال یا ابهام، از طریق روبیکا با آیدی زیر با همکاران پشتیبان در ارتباط باشید. 
            <br>
            <b>@ero_admin</b>
          </p>
        </section>

        <div class="cta-wrap">
          <a class="cta" href="rmsM.php">شروع تست</a>
          <p class="support">برای ادامه، روی دکمه بالا بزنید و وارد صفحه تست شوید.</p>
        </div>
      </div>
    </section>
  </main>

  <script>
    (() => {
      const startedAt = Date.now();
      const minimumVisibleMs = 1500;
      const watchdog = setTimeout(() => {
        if (!document.body.classList.contains('page-loading')) return;
        window.location.replace(`${window.location.pathname}?t=${Date.now()}`);
      }, 9000);

      window.addEventListener('load', () => {
        const elapsed = Date.now() - startedAt;
        const delay = Math.max(0, minimumVisibleMs - elapsed);
        setTimeout(() => {
          clearTimeout(watchdog);
          const loader = document.getElementById('tc-loader');
          document.body.classList.remove('page-loading');
          if (!loader) return;
          loader.classList.add('loader-hidden');
          setTimeout(() => loader.remove(), 360);
        }, delay);
      }, { once: true });
    })();
  </script>
</body>
</html>
