<?php
declare(strict_types=1);

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
$eventName = trim((string)($settings['eventName'] ?? ''));
$maintenanceTitle = ($eventName !== '' ? $eventName : 'کمپین به دست آوردیم') . ' - در حال بروزرسانی';
$maintenanceMode = (bool)($settings['maintenanceMode'] ?? false);
if (!$maintenanceMode) {
  header('Location: index.php');
  exit;
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title><?= htmlspecialchars($maintenanceTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="icon" href="<?= htmlspecialchars($faviconUrl ?: 'data:,', ENT_QUOTES, 'UTF-8') ?>" />
  <style>
    :root {
      --bg: #f4f7fb;
      --phone: #ffffff;
      --ink: #1f2a44;
      --muted: #6f7d9a;
      --line: #e5ecf7;
      --egm-secondary: <?= htmlspecialchars($eventSecondary, ENT_QUOTES, 'UTF-8') ?>;
      --egm-highlight: <?= htmlspecialchars($eventHighlight, ENT_QUOTES, 'UTF-8') ?>;
      --egm-accent-soft: <?= htmlspecialchars($eventAccentSoft, ENT_QUOTES, 'UTF-8') ?>;
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
        radial-gradient(circle at top right, color-mix(in srgb, var(--egm-highlight) 18%, transparent), transparent 46%),
        radial-gradient(circle at bottom left, color-mix(in srgb, var(--egm-secondary) 18%, transparent), transparent 45%),
        var(--bg);
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
      object-fit: contain;
      background: transparent;
    }

    .hero {
      padding: 24px 18px 8px;
      text-align: center;
    }

    .event-logo {
      height: 88px;
      width: auto;
      max-width: min(240px, 72vw);
      object-fit: contain;
      margin-bottom: 8px;
    }

    .title {
      margin: 0;
      font-size: 1.22rem;
      color: var(--egm-highlight);
      font-weight: 700;
    }

    .subtitle {
      margin: 10px 0 0;
      color: var(--muted);
      font-size: .9rem;
      line-height: 1.95;
    }

    .badge {
      margin: 14px auto 0;
      width: fit-content;
      border-radius: 999px;
      padding: 7px 14px;
      font-size: .82rem;
      font-weight: 700;
      color: #0f3f6e;
      background: color-mix(in srgb, var(--egm-accent-soft) 36%, #fff);
      border: 1px solid color-mix(in srgb, var(--egm-accent-soft) 55%, #fff);
    }

    .content {
      padding: 10px 16px 20px;
      overflow-y: auto;
      flex: 1;
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
      color: var(--egm-highlight);
      font-size: .95rem;
      font-weight: 700;
    }

    .section p {
      margin: 0;
      color: #334b74;
      font-size: .87rem;
      line-height: 1.95;
    }

    .hint {
      text-align: center;
      color: var(--muted);
      font-size: .8rem;
      margin-top: 10px;
    }

    @media (min-width: 680px) {
      body { padding: 24px; }
      .phone { min-height: min(860px, calc(100vh - 48px)); }
    }
  </style>
</head>
<body>
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
        <h1 class="title">در حال بروزرسانی</h1>
        <p class="subtitle">
          سامانه باشگاه تعاملی در حال بروزرسانی است تا قابلیت‌های جدید، پایداری بهتر و تجربه روان‌تری ارائه شود.
          <br>
          پس از تکمیل بروزرسانی، دسترسی مجدد به‌صورت خودکار برقرار خواهد شد.
        </p>
        <div class="badge">بروزرسانی در حال انجام است</div>
      </section>

      <div class="content">
        <section class="section">
          <h3>علت عدم دسترسی</h3>
          <p>
            در این بازه، تغییرات فنی و بهینه‌سازی‌های ساختاری روی سامانه انجام می‌شود تا عملکرد و امنیت سرویس ارتقا پیدا کند.
          </p>
        </section>
        <section class="section">
          <h3>راهنمای پیگیری</h3>
          <p>
            لطفا کمی بعد دوباره وارد شوید. در صورت نیاز فوری، وضعیت دسترسی را از مسیرهای اطلاع‌رسانی داخلی سازمان پیگیری کنید.
          </p>
        </section>
        <p class="hint">سپاس از همراهی شما در زمان بروزرسانی.</p>
      </div>
    </section>
  </main>
</body>
</html>
