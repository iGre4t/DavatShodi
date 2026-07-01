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

function normalizeLandingSection($section, int $index): ?array
{
  if (!is_array($section)) {
    return null;
  }
  $title = trim((string)($section['title'] ?? ''));
  $text = trim(str_replace(["\r\n", "\r"], "\n", (string)($section['text'] ?? ($section['html'] ?? ''))));
  if ($title === '' && $text === '') {
    return null;
  }
  return [
    'id' => trim((string)($section['id'] ?? ('landing_section_' . ($index + 1)))),
    'title' => $title,
    'text' => $text
  ];
}

function normalizeLandingSettings($value): array
{
  $source = is_array($value) ? $value : [];
  $sections = [];
  foreach ((array)($source['sections'] ?? []) as $index => $section) {
    $normalized = normalizeLandingSection($section, (int)$index);
    if ($normalized !== null) {
      $sections[] = $normalized;
    }
  }
  return [
    'title' => trim((string)($source['title'] ?? '')),
    'subtitle' => trim(str_replace(["\r\n", "\r"], "\n", (string)($source['subtitle'] ?? ''))),
    'sections' => $sections
  ];
}

function sanitizeLandingHtml(string $html): string
{
  $clean = strip_tags($html, '<b><strong><i><em><u><a><ul><ol><li><br><p><h3>');
  $clean = preg_replace('/\s+on[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean);
  $clean = preg_replace('/\s+style\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean);
  $clean = preg_replace_callback('/<a\b([^>]*)>/i', static function (array $matches): string {
    $attrs = (string)($matches[1] ?? '');
    if (!preg_match('/\bhref\s*=\s*([\'"])(.*?)\1/i', $attrs, $hrefMatch)) {
      return '<a>';
    }
    $href = trim((string)($hrefMatch[2] ?? ''));
    if ($href === '' || !preg_match('/^(?:https?:\/\/|mailto:|tel:|#|\/)/i', $href)) {
      return '<a>';
    }
    return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" rel="noopener noreferrer">';
  }, $clean);
  return is_string($clean) ? $clean : '';
}

function formatLandingBodyHtml(string $text): string
{
  if (preg_match('/<\s*[a-z][\s\S]*>/i', $text)) {
    return sanitizeLandingHtml($text);
  }
  return nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), false);
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
$landing = normalizeLandingSettings($settings['landing'] ?? []);
$landingHasCustom = $landing['title'] !== '' || $landing['subtitle'] !== '' || count($landing['sections']) > 0;
$eventColors = is_array($settings['eventColors'] ?? null) ? $settings['eventColors'] : [];
$eventSecondary = normalizeHexColor($eventColors['secondary'] ?? '', '#2F8FFF');
$eventHighlight = normalizeHexColor($eventColors['highlight'] ?? '', '#20C997');
$eventAccentSoft = normalizeHexColor($eventColors['accentSoft'] ?? '', '#FFB347');
$eventLogoUrl = formatAssetUrl((string)($settings['eventLogo'] ?? ''));
$siteIconUrl = formatAssetUrl((string)($panelSettings['siteIcon'] ?? ''));
$faviconUrl = $eventLogoUrl !== '' ? $eventLogoUrl : $siteIconUrl;
$eventName = trim((string)($settings['eventName'] ?? ''));
$pageTitle = $eventName !== '' ? $eventName : ($landing['title'] !== '' ? $landing['title'] : 'مسابقه به‌دست آوردیم');
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
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

    .section p,
    .section .section-body {
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

    .section .section-body ul,
    .section .section-body ol {
      margin: 0;
      padding-right: 18px;
    }

    .list li { margin-bottom: 6px; }
    .section .section-body li { margin-bottom: 6px; }

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
        <?php if ($landingHasCustom && $landing['title'] !== ''): ?>
        <h1 class="title"><?= nl2br(htmlspecialchars($landing['title'], ENT_QUOTES, 'UTF-8'), false) ?></h1>
        <?php else: ?>
        <h1 class="title">
  <span class="ohterparts">مسابقه</span>
  <span class="highlight">«به‌دست آوردیم»</span>
  <br>
  <span class="ohterparts">به دنیای دستاوردها خوش آمدید…</span>
</h1>
        <?php endif; ?>
        <?php if ($landingHasCustom && $landing['subtitle'] !== ''): ?>
        <p class="subtitle">
          <?= nl2br(htmlspecialchars($landing['subtitle'], ENT_QUOTES, 'UTF-8'), false) ?>
        </p>
        <?php else: ?>
        <p class="subtitle">
          در این مسابقه، هر محتوا فقط یک روایت نیست؛ یک فرصت برای ساختن امتیاز و نزدیک‌تر شدن به کارت‌های جایزه است.
        </p>
        <?php endif; ?>
      </section>

      <div class="content">
        <?php if ($landingHasCustom && count($landing['sections']) > 0): ?>
          <?php foreach ($landing['sections'] as $section): ?>
            <section class="section">
              <?php if ($section['title'] !== ''): ?>
                <h3><?= htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8') ?></h3>
              <?php endif; ?>
              <?php if ($section['text'] !== ''): ?>
                <div class="section-body"><?= formatLandingBodyHtml((string)$section['text']) ?></div>
              <?php endif; ?>
            </section>
          <?php endforeach; ?>
        <?php else: ?>
        <section class="section">
          <h3>چگونه شرکت کنیم؟</h3>
          <ul class="list">
            <li>ویدیوها و پست‌های هر بخش را با دقت ببینید.</li>
            <li>به سوالات مسابقه پاسخ دهید.</li>
            <li>امتیاز جمع کنید و جایگاه خود را ارتقا دهید.</li>
            <li>هرچه امتیاز بیشتری کسب کنید، شانس شما برای باز کردن کارت‌های جایزه بیشتر می‌شود.</li>
          </ul>
        </section>

        <section class="section">
          <h3>سیستم امتیازدهی</h3>
          <p>
            برای پاسخ دادن به هر چالش، ۲ روز فرصت طلایی در نظر گرفته شده است. اگر در این مدت به سوالات پاسخ دهید، می‌توانید امتیاز کامل آن چالش را دریافت کنید.
            <br><br>
            در هر چالش، ۳ سوال از شما پرسیده می‌شود و هر پاسخ صحیح، ۱۰ امتیاز دارد.
            <br><br>
            در صورتی که در فرصت طلایی به سوالات پاسخ ندهید، همچنان می‌توانید در چالش شرکت کنید؛ اما برای هر پاسخ صحیح، تنها ۵ امتیاز دریافت خواهید کرد.
          </p>
        </section>

        <section class="section">
          <h3>طرح سوالات</h3>
          <p>
            سوالات هر چالش با دقت و به‌صورت ریزبینانه، فقط از ویدیوی مربوط به «به دست آوردیم» معاونت‌ها طراحی می‌شود.
            <br><br>
            پس قبل از شروع هر چالش، حتما ویدیوی «به دست آوردیم» آن معاونت را از طریق کانال ارتباطات کارکنان همراه اول با دقت مشاهده کنید.
          </p>
        </section>

        <section class="section">
          <h3>نحوه دریافت جوایز</h3>
          <p>
            در این مسابقه، شما می‌توانید ۳ کارت اعتباری دریافت کنید. همچنین اگر به تمام سوالات در فرصت طلایی پاسخ صحیح بدهید، وارد قرعه‌کشی ویژه «به دست آوردیم» خواهید شد.
          </p>
        </section>

        <section class="section">
          <h3>آماده‌ای؟</h3>
          <p>
            ویدیوها را با دقت دنبال کنید، به سوالات درست پاسخ دهید و شانس خود را برای رسیدن به کارت‌های جایزه افزایش دهید.
            <br><br>
            به دست آوردیم… و حالا نوبت شماست.
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
        <?php endif; ?>

        <div class="cta-wrap">
          <a class="cta" href="TCM.php">شرکت در چالش</a>
          <p class="support">برای ادامه، روی دکمه بالا بزنید و وارد صفحه چالش شوید.</p>
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
