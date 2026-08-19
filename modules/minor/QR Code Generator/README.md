# QR Code Generator backend module

Self-hosted QR code generation for DavatShodi. The module produces standards-compliant SVG QR codes without contacting an external QR service and does not require GD or Imagick.

## PHP usage

```php
require_once __DIR__ . '/modules/minor/QR Code Generator/QrCodeGenerator.php';

use DavatShodi\Modules\Minor\QrCode\QrCodeGenerator;

$generator = new QrCodeGenerator();
$svg = $generator->generateSvg('https://example.com', [
    'size' => 320,
    'margin' => 4,
    'ecc' => 'M',
    'dark' => '#000000',
    'light' => '#ffffff',
]);
```

`generateDataUri()` returns an embeddable SVG data URI. `saveSvg()` atomically saves an SVG to an existing writable directory.

## HTTP endpoint

The endpoint requires an authenticated panel session.

```text
modules/minor/QR%20Code%20Generator/generate.php?data=https%3A%2F%2Fexample.com&size=320&ecc=M
```

Supported parameters:

- `data`: required content, up to 4096 bytes.
- `size`: 64–2048 pixels; default 320.
- `margin`: 0–20 QR modules; default 4.
- `ecc`: error correction level `L`, `M`, `Q`, or `H`.
- `dark` and `light`: hexadecimal colors.
- `response`: `svg` or `json`.
- `download=1`: returns the SVG as an attachment.

POST requests can use JSON or form fields. `?health=1` returns capability information.

## Dependency

The module pins `chillerlan/php-qrcode` and its settings-container dependency in `composer.lock`. The installed dependency sources and licenses are contained under `vendor/`.
