<?php
declare(strict_types=1);

namespace DavatShodi\Modules\Minor\QrCode;

use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/vendor/autoload.php';

final class QrCodeValidationException extends InvalidArgumentException
{
}

final class QrCodeGenerator
{
    public const MIN_SIZE = 64;
    public const MAX_SIZE = 2048;
    public const MAX_DATA_BYTES = 4096;
    public const DEFAULT_SIZE = 320;
    public const DEFAULT_MARGIN = 4;
    public const DEFAULT_ECC = 'M';

    /** @param array<string,mixed> $options */
    public function generateSvg(string $data, array $options = []): string
    {
        if (trim($data) === '') {
            throw new QrCodeValidationException('QR code data is required.');
        }
        if (strlen($data) > self::MAX_DATA_BYTES) {
            throw new QrCodeValidationException('QR code data must not exceed ' . self::MAX_DATA_BYTES . ' bytes.');
        }

        $size = $this->integerOption($options['size'] ?? self::DEFAULT_SIZE, self::MIN_SIZE, self::MAX_SIZE, 'size');
        $margin = $this->integerOption($options['margin'] ?? self::DEFAULT_MARGIN, 0, 20, 'margin');
        $eccValue = $options['ecc'] ?? self::DEFAULT_ECC;
        $ecc = strtoupper(trim(is_scalar($eccValue) ? (string)$eccValue : ''));
        if (!in_array($ecc, ['L', 'M', 'Q', 'H'], true)) {
            throw new QrCodeValidationException('Error correction level must be L, M, Q, or H.');
        }
        $dark = $this->colorOption($options['dark'] ?? '#000000', 'dark');
        $light = $this->colorOption($options['light'] ?? '#ffffff', 'light');
        if (strcasecmp($dark, $light) === 0) {
            throw new QrCodeValidationException('Dark and light colors must be different.');
        }

        try {
            $qrOptions = new QROptions([
                'outputInterface' => QRMarkupSVG::class,
                'outputBase64' => false,
                'eccLevel' => $ecc,
                'addQuietzone' => true,
                'quietzoneSize' => $margin,
                'svgAddXmlHeader' => true,
                'svgUseFillAttributes' => true,
                'connectPaths' => true,
            ]);
            $svg = (new QRCode($qrOptions))->render($data);
        } catch (Throwable $error) {
            throw new QrCodeValidationException('The supplied data is too large or cannot be encoded with these settings.', 0, $error);
        }

        $svg = str_replace(['fill="#fff"', 'fill="#000"'], ['fill="' . $light . '"', 'fill="' . $dark . '"'], $svg);
        $svg = preg_replace(
            '/<svg\s/u',
            '<svg width="' . $size . '" height="' . $size . '" role="img" aria-label="QR code" ',
            $svg,
            1
        );
        if (!is_string($svg) || !str_contains($svg, '<svg')) {
            throw new RuntimeException('QR code SVG generation failed.');
        }
        return $svg;
    }

    /** @param array<string,mixed> $options */
    public function generateDataUri(string $data, array $options = []): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode($this->generateSvg($data, $options));
    }

    /** @param array<string,mixed> $options */
    public function saveSvg(string $data, string $absolutePath, array $options = []): void
    {
        $directory = dirname($absolutePath);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('QR code destination directory is not writable.');
        }
        if (strtolower((string)pathinfo($absolutePath, PATHINFO_EXTENSION)) !== 'svg') {
            throw new QrCodeValidationException('QR code output file must use the .svg extension.');
        }
        $temporaryPath = $directory . DIRECTORY_SEPARATOR . '.qr-' . bin2hex(random_bytes(8)) . '.tmp';
        $svg = $this->generateSvg($data, $options);
        if (file_put_contents($temporaryPath, $svg, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write the QR code file.');
        }
        if (!@rename($temporaryPath, $absolutePath)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Failed to publish the QR code file.');
        }
    }

    private function integerOption(mixed $value, int $minimum, int $maximum, string $name): int
    {
        $text = trim(is_scalar($value) ? (string)$value : '');
        if ($text === '' || preg_match('/^\d+$/D', $text) !== 1) {
            throw new QrCodeValidationException("QR code {$name} must be an integer.");
        }
        $number = (int)$text;
        if ($number < $minimum || $number > $maximum) {
            throw new QrCodeValidationException("QR code {$name} must be between {$minimum} and {$maximum}.");
        }
        return $number;
    }

    private function colorOption(mixed $value, string $name): string
    {
        $color = strtolower(trim(is_scalar($value) ? (string)$value : ''));
        if (preg_match('/^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/D', $color) !== 1) {
            throw new QrCodeValidationException("QR code {$name} color must be a hexadecimal color such as #000000.");
        }
        if (strlen($color) === 4) {
            $color = '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
        }
        return $color;
    }
}
