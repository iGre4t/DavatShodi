<?php
declare(strict_types=1);

mb_internal_encoding('UTF-8');

const CSV_PATH = __DIR__ . '/../../events/event/purelist.csv';
const CARD_PATH = __DIR__ . '/../../events/eventcard/eventrawcard.jpg';
const FONT_CANDIDATES = [
    'C:/Windows/Fonts/arial.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
    '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
    '/usr/share/fonts/truetype/noto/NotoSans-Regular.ttf',
    '/usr/share/fonts/truetype/msttcorefonts/Arial.ttf',
];

$inviteCode = getRequestedInviteCode();
if ($inviteCode === '') {
    respondNotFound();
}

$guest = findGuestFromCsv($inviteCode);
if ($guest === null) {
    respondNotFound($inviteCode);
}

$fullName = trim(($guest['firstname'] ?? '') . ' ' . ($guest['lastname'] ?? ''));
$fullName = $fullName === '' ? 'Guest invite' : $fullName;

$textLines = array_filter([
    $fullName,
    isset($guest['national_id']) && $guest['national_id'] !== '' ? 'National ID: ' . $guest['national_id'] : '',
    isset($guest['phone_number']) && $guest['phone_number'] !== '' ? 'Phone: ' . $guest['phone_number'] : '',
    'Invite code: ' . $inviteCode,
]);

$imageData = buildInviteImage($textLines);
outputInvitePage($imageData, $fullName, $inviteCode);

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

function buildInviteImage(array $lines): string
{
    $cardPath = realpath(CARD_PATH);
    if ($cardPath === false || !is_readable($cardPath)) {
        respondError('Raw event card not available');
    }
    if (!function_exists('imagecreatefromjpeg')) {
        respondError('GD JPEG support missing');
    }

    $image = imagecreatefromjpeg($cardPath);
    if ($image === false) {
        respondError('Unable to open event card');
    }

    $width = imagesx($image);
    $height = imagesy($image);

    $fontPath = resolveFontPath();
    if ($fontPath === null) {
        respondError('Descriptive font not found');
    }

    $fontSize = max(26, min(48, (int) round($width / 24)));
    $lineSpacing = (int) round($fontSize * 1.4);

    $textBlockHeight = count($lines) * $lineSpacing + 20;
    $overlayHeight = max($textBlockHeight + 32, (int) round($height * 0.27));
    $overlayTop = $height - $overlayHeight;

    $overlayColor = imagecolorallocatealpha($image, 0, 0, 0, 70);
    imagefilledrectangle($image, 0, $overlayTop, $width, $height, $overlayColor);

    $textColor = imagecolorallocate($image, 255, 255, 255);

    $x = (int) round($width * 0.06);
    $y = $overlayTop + 16 + $fontSize;

    $availableWidth = $width - $x * 2;
    foreach ($lines as $content) {
        $wrapped = wrapText($content, $fontSize, $fontPath, $availableWidth);
        foreach ($wrapped as $line) {
            imagettftext($image, $fontSize, 0, $x, $y, $textColor, $fontPath, $line);
            $y += $lineSpacing;
        }
        $y += (int) round($lineSpacing * 0.1);
    }

    ob_start();
    imagejpeg($image, null, 86);
    imagedestroy($image);
    $data = ob_get_clean();

    if (!is_string($data)) {
        respondError('Failed to encode invite image');
    }

    return $data;
}

function wrapText(string $text, int $fontSize, string $fontPath, int $maxWidth): array
{
    $words = preg_split('/\s+/u', $text);
    $lines = [];
    $current = '';
    foreach ($words as $word) {
        if ($current === '') {
            $candidate = $word;
        } else {
            $candidate = $current . ' ' . $word;
        }

        if (getTextWidth($candidate, $fontSize, $fontPath) <= $maxWidth) {
            $current = $candidate;
            continue;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        if (getTextWidth($word, $fontSize, $fontPath) <= $maxWidth) {
            $current = $word;
        } else {
        $current = trim(mb_substr($word, 0, max(1, (int) mb_strlen($word) / 2)));
    }
    }

    if ($current !== '') {
        $lines[] = $current;
    }

    return $lines;
}

function getTextWidth(string $text, int $fontSize, string $fontPath): int
{
    $box = imagettfbbox($fontSize, 0, $fontPath, $text);
    if ($box === false) {
        return mb_strlen($text) * $fontSize;
    }
    return abs($box[2] - $box[0]);
}

function resolveFontPath(): ?string
{
    foreach (FONT_CANDIDATES as $path) {
        if (is_file($path) && is_readable($path)) {
            return $path;
        }
    }
    return null;
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

function outputInvitePage(string $imageData, string $title, string $code): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    $imageBase64 = base64_encode($imageData);
    $safeTitle = htmlspecialchars($title, ENT_QUOTES);
    $safeCode = htmlspecialchars($code, ENT_QUOTES);
    echo <<<HTML
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>$safeTitle - Invite</title>
    <style>
      :root { background:#050505; }
      body {
        margin:0;
        min-height:100vh;
        display:flex;
        align-items:center;
        justify-content:center;
        background:#050505;
        color:#fff;
        font-family: Arial, sans-serif;
      }
      .invite-shell {
        width:100%;
        max-width:420px;
        max-height:90vh;
        padding:16px;
        box-sizing:border-box;
      }
      .invite-shell img {
        width:100%;
        height:auto;
        aspect-ratio:9/16;
        object-fit:cover;
        border-radius:22px;
        box-shadow:0 24px 45px rgba(0,0,0,0.45);
      }
      .invite-shell .code {
        margin-top:12px;
        text-align:center;
        font-weight:600;
        letter-spacing:0.25em;
      }
    </style>
  </head>
  <body>
    <div class="invite-shell">
      <img alt="Invite card for $safeTitle" src="data:image/jpeg;base64,$imageBase64" />
      <div class="code">Invite code: $safeCode</div>
    </div>
  </body>
</html>
HTML;
}
