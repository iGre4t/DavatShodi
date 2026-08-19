<?php
declare(strict_types=1);

use DavatShodi\Modules\Minor\QrCode\QrCodeGenerator;
use DavatShodi\Modules\Minor\QrCode\QrCodeValidationException;

require_once __DIR__ . '/QrCodeGenerator.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function qrGeneratorJson(array $payload, int $status): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function qrGeneratorScalarText(mixed $value, string $fallback = ''): string
{
    return is_scalar($value) ? (string)$value : $fallback;
}

if (empty($_SESSION['authenticated'])) {
    qrGeneratorJson(['status' => 'error', 'message' => 'Authentication is required.'], 401);
}

$now = time();
$requests = array_values(array_filter(
    is_array($_SESSION['qr_generator_requests'] ?? null) ? $_SESSION['qr_generator_requests'] : [],
    static fn($timestamp): bool => is_int($timestamp) && $timestamp > ($now - 60)
));
if (count($requests) >= 120) {
    qrGeneratorJson(['status' => 'error', 'message' => 'QR code generation rate limit exceeded.'], 429);
}
$requests[] = $now;
$_SESSION['qr_generator_requests'] = $requests;

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$input = $_GET;
if ($method === 'POST') {
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || strlen($raw) > 65536) {
            qrGeneratorJson(['status' => 'error', 'message' => 'Invalid request body.'], 400);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            qrGeneratorJson(['status' => 'error', 'message' => 'Invalid JSON request body.'], 400);
        }
        $input = $decoded;
    } else {
        $input = $_POST;
    }
} elseif ($method !== 'GET') {
    header('Allow: GET, POST');
    qrGeneratorJson(['status' => 'error', 'message' => 'Method not allowed.'], 405);
}

if (($input['health'] ?? '') === '1') {
    qrGeneratorJson([
        'status' => 'ok',
        'module' => 'QR Code Generator',
        'formats' => ['svg', 'json'],
        'error_correction' => ['L', 'M', 'Q', 'H'],
        'max_data_bytes' => QrCodeGenerator::MAX_DATA_BYTES,
    ], 200);
}

$data = is_scalar($input['data'] ?? null) ? (string)$input['data'] : '';
$responseFormat = strtolower(trim(qrGeneratorScalarText($input['response'] ?? 'svg', 'svg')));
$options = [
    'size' => $input['size'] ?? QrCodeGenerator::DEFAULT_SIZE,
    'margin' => $input['margin'] ?? QrCodeGenerator::DEFAULT_MARGIN,
    'ecc' => $input['ecc'] ?? QrCodeGenerator::DEFAULT_ECC,
    'dark' => $input['dark'] ?? '#000000',
    'light' => $input['light'] ?? '#ffffff',
];

try {
    $generator = new QrCodeGenerator();
    $svg = $generator->generateSvg($data, $options);
    if ($responseFormat === 'json') {
        qrGeneratorJson([
            'status' => 'ok',
            'mime_type' => 'image/svg+xml',
            'data_uri' => 'data:image/svg+xml;base64,' . base64_encode($svg),
            'size' => (int)$options['size'],
            'ecc' => strtoupper(qrGeneratorScalarText($options['ecc'], QrCodeGenerator::DEFAULT_ECC)),
            'data_bytes' => strlen($data),
        ], 200);
    }
    if ($responseFormat !== 'svg') {
        throw new QrCodeValidationException('Response format must be svg or json.');
    }
    $filename = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim(qrGeneratorScalarText($input['filename'] ?? 'qr-code', 'qr-code'))) ?: 'qr-code';
    http_response_code(200);
    header('Content-Type: image/svg+xml; charset=UTF-8');
    header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'');
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');
    if (($input['download'] ?? '') === '1') {
        header('Content-Disposition: attachment; filename="' . $filename . '.svg"');
    }
    echo $svg;
} catch (QrCodeValidationException $error) {
    qrGeneratorJson(['status' => 'error', 'message' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('QR code generation failed: ' . $error->getMessage());
    qrGeneratorJson(['status' => 'error', 'message' => 'QR code generation failed.'], 500);
}
