<?php
declare(strict_types=1);

mb_internal_encoding('UTF-8');

const CSV_PATH = __DIR__ . '/../../events/event/purelist.csv';
const HERO_IMAGE_PATH = __DIR__ . '/../../events/eventcard/eventrawcard.jpg';

$inviteCode = getRequestedInviteCode();
if ($inviteCode === '') {
    respondNotFound();
}

$guest = findGuestFromCsv($inviteCode);
if ($guest === null) {
    respondNotFound($inviteCode);
}

$fullName = trim(($guest['firstname'] ?? ''));
$displayName = $fullName === '' ? 'مهمان گرامی' : $fullName;
$nationalId = trim($guest['national_id'] ?? '');

outputInvitePage($displayName, $inviteCode, $nationalId);

exit;

function getRequestedInviteCode(): string
{
    $pathInfo = $_SERVER['PATH_INFO'] ?? '';
    if ($pathInfo !== '') {
        return trim($pathInfo, '/');
    }

    $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

    if ($base !== '' && strpos($requestUri, $base) === 0) {
        $requestUri = substr($requestUri, strlen($base));
    }

    if (strpos($requestUri, '/index.php') === 0) {
        $requestUri = substr($requestUri, 10);
    }

    return trim($requestUri, '/');
}

function findGuestFromCsv(string $code): ?array
{
    $csvPath = realpath(CSV_PATH);
    if ($csvPath === false || !is_readable($csvPath)) {
        return null;
    }

    $handle = fopen($csvPath, 'rb');
    if ($handle === false) {
        return null;
    }

    $header = fgetcsv($handle);
    if (is_array($header) && count($header) > 0) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
    }

    while ($row = fgetcsv($handle)) {
        if (!is_array($header) || count($row) !== count($header)) {
            continue;
        }
        $mapped = array_combine($header, $row);
        if (!is_array($mapped)) {
            continue;
        }
        $smsLink = $mapped['sms_link'] ?? '';
        if ($smsLink === '') {
            continue;
        }
        if (!preg_match('@/invite/([^/?#]+)@', $smsLink, $matches)) {
            continue;
        }
        if ($matches[1] === $code) {
            fclose($handle);
            return $mapped;
        }
    }

    fclose($handle);
    return null;
}

function outputInvitePage(string $name, string $code, string $nationalId): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $heroPath = realpath(HERO_IMAGE_PATH);
    if ($heroPath === false || !is_readable($heroPath)) {
        respondError('پوستر رویداد پیدا نشد');
    }

    $heroUrl = '/events/eventcard/eventrawcard.jpg';
    $safeName = htmlspecialchars($name, ENT_QUOTES);
    $safeCode = htmlspecialchars($code, ENT_QUOTES);
    $watermark = $nationalId !== '' ? htmlspecialchars($nationalId, ENT_QUOTES) : '---';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'davatshodi.ir';
    $inviteUrl = $scheme . '://' . $host . '/mci/invite/' . $code;
    $qrData = rawurlencode($inviteUrl);
    $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=220x220&data={$qrData}&margin=12";

    echo <<<HTML
<!doctype html>
<html lang="fa" dir="rtl">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>$safeName</title>
    <style>
      @font-face {
        font-family: "Peyda";
        src: url("/style/fonts/PeydaWebFaNum-Regular.woff2") format("woff2"),
             url("/style/fonts/PeydaWebFaNum-Bold.woff2") format("woff2");
        font-weight: 400 700;
        font-display: swap;
      }
      * {
        box-sizing: border-box;
      }
      body {
        margin: 0;
        min-height: 100vh;
        background: #fff;
        font-family: "Peyda", "Peyda Web", "Segoe UI", sans-serif;
        color: #0f172a;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 18px;
      }
      .invite-shell {
        width: min(460px, 100%);
        background: #fff;
        border-radius: 32px;
        box-shadow: 0 30px 70px rgba(15,23,42,0.22);
        border: 1px solid rgba(15,23,42,0.08);
        overflow: hidden;
      }
      .hero {
        width: 100%;
        display: block;
        object-fit: cover;
        height: 320px;
      }
      .info-panel {
        padding: 36px 30px 38px;
        text-align: center;
        position: relative;
      }
      .info-panel .label {
        font-size: 18px;
        color: rgba(15,23,42,0.65);
        margin-bottom: 12px;
        letter-spacing: 0.08em;
      }
      .info-panel .name {
        font-size: 32px;
        font-weight: 600;
        margin: 0;
        line-height: 1.25;
      }
      .qr-shell {
        margin: 32px auto 0;
        width: 200px;
        height: 200px;
        position: relative;
      }
      .qr-shell img {
        width: 100%;
        height: 100%;
        border-radius: 18px;
        box-shadow: 0 12px 30px rgba(15,23,42,0.25);
        display: block;
      }
      .watermark {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 60px;
        color: rgba(15,23,42,0.08);
        letter-spacing: 0.35em;
        font-weight: 600;
        pointer-events: none;
        text-transform: uppercase;
      }
      .code-display {
        margin-top: 28px;
        font-size: 46px;
        letter-spacing: 0.25em;
        font-weight: 700;
      }
      @media (max-width: 500px) {
        .hero {
          height: 260px;
        }
        .info-panel {
          padding: 28px 20px 32px;
        }
        .code-display {
          font-size: 38px;
        }
      }
    </style>
  </head>
  <body>
    <div class="invite-shell">
      <img class="hero" src="$heroUrl" alt="پوستر رویداد" />
      <div class="info-panel" aria-live="polite">
        <div class="label">مهمان محترم</div>
        <p class="name">$safeName</p>
        <div class="qr-shell">
          <div class="watermark">$watermark</div>
          <img src="$qrUrl" alt="QR دعوت" loading="lazy" />
        </div>
        <div class="code-display">$safeCode</div>
      </div>
    </div>
  </body>
</html>
HTML;
}

function respondNotFound(string $code = ''): void
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    $message = $code === '' ? 'Invite not found' : sprintf('Invite code %s was not recognised', htmlspecialchars($code, ENT_QUOTES));
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Invite missing</title><meta name="viewport" content="width=device-width,initial-scale=1"></head>';
    echo '<body style="font-family:Arial,sans-serif;margin:0;padding:40px;text-align:center;background:#111;color:#fff;">';
    echo '<div style="max-width:600px;margin:0 auto;">';
    echo '<h1>Invite unavailable</h1>';
    echo '<p>' . $message . '</p>';
    echo '</div></body></html>';
    exit;
}

function respondError(string $detail): void
{
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $detail;
    exit;
}
