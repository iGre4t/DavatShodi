<?php
declare(strict_types=1);

use DavatShodi\Modules\Minor\QrCode\QrCodeGenerator;
use DavatShodi\Modules\Minor\QrCode\QrCodeValidationException;

require_once dirname(__DIR__) . '/QrCodeGenerator.php';

function qrTestAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$generator = new QrCodeGenerator();
$composer = json_decode((string)file_get_contents(dirname(__DIR__) . '/composer.json'), true);
qrTestAssert(
    is_array($composer)
        && ($composer['require']['php'] ?? '') === '>=8.1'
        && ($composer['require']['chillerlan/php-qrcode'] ?? '') === '^5.0',
    'QR dependencies are not compatible with the production PHP 8.1 runtime.'
);
$payload = "  https://example.com/دعوت/۱۲۳  ";
$svg = $generator->generateSvg($payload, [
    'size' => 512,
    'margin' => 4,
    'ecc' => 'H',
    'dark' => '#123456',
    'light' => '#fafafa',
]);

qrTestAssert(str_contains($svg, '<svg'), 'SVG root is missing.');
qrTestAssert(str_contains($svg, 'width="512"'), 'Requested SVG size is missing.');
qrTestAssert(str_contains($svg, 'fill="#123456"'), 'Dark color was not applied.');
qrTestAssert(str_contains($svg, 'fill="#fafafa"'), 'Light color was not applied.');
qrTestAssert(str_starts_with($generator->generateDataUri('test'), 'data:image/svg+xml;base64,'), 'Data URI is invalid.');

$productionSvg = $generator->generateSvg('0073384161', [
    'size' => 1024,
    'margin' => 2,
    'ecc' => 'M',
]);
qrTestAssert(str_contains($productionSvg, 'width="1024"'), 'The production Invite Card QR request could not be rendered.');

$validationRaised = false;
try {
    $generator->generateSvg('');
} catch (QrCodeValidationException) {
    $validationRaised = true;
}
qrTestAssert($validationRaised, 'Empty data validation did not run.');

fwrite(STDOUT, "QR Code Generator test passed.\n");
