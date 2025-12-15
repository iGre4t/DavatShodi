<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/common.php';

const THUMB_MODES = [
    'default' => [
        'maxWidth' => 360,
        'maxHeight' => 280,
        'quality' => 65,
    ],
    'preview' => [
        'maxWidth' => 260,
        'maxHeight' => 200,
        'quality' => 50,
    ],
];
const DEFAULT_THUMB_MODE = 'default';

function sendNotFound(): void
{
    http_response_code(404);
    exit;
}

function outputImageFile(string $path, string $mime = 'image/jpeg'): void
{
    if (!is_file($path)) {
        sendNotFound();
    }
    $mime = trim($mime);
    if ($mime === '') {
        $mime = 'application/octet-stream';
    }
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=604800');
    header('Content-Length: ' . (string)filesize($path));
    readfile($path);
    exit;
}

function createSourceImage(string $path)
{
    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    switch ($extension) {
        case 'jpg':
        case 'jpeg':
            return function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : null;
        case 'png':
            return function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : null;
        case 'gif':
            return function_exists('imagecreatefromgif') ? @imagecreatefromgif($path) : null;
        case 'webp':
            return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null;
        default:
            return null;
    }
}

function detectImageMimeType(string $path): string
{
    if (function_exists('mime_content_type')) {
        $mime = mime_content_type($path);
        if (is_string($mime) && $mime !== '') {
            return $mime;
        }
    }
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return match ($extension) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        default => 'application/octet-stream',
    };
}

function streamOriginalImage(string $sourcePath): void
{
    outputImageFile($sourcePath, detectImageMimeType($sourcePath));
}

$photoId = (int)($_GET['id'] ?? 0);
if ($photoId <= 0) {
    sendNotFound();
}

$config = loadConfig(__DIR__ . '/config.php');
$pdo = connectDatabase($config);
if (!$pdo) {
    sendNotFound();
}

$stmt = $pdo->prepare('SELECT `filename` FROM `gallery` WHERE `photo_id` = :id LIMIT 1');
$stmt->execute([':id' => $photoId]);
$filename = trim((string)$stmt->fetchColumn());
if ($filename === '') {
    sendNotFound();
}

$uploadsBase = realpath(__DIR__ . '/../uploads/gallery');
if ($uploadsBase === false) {
    sendNotFound();
}

$sanitized = ltrim(str_replace(['..\\', '../'], '', $filename), '/\\');
$sourcePath = realpath(__DIR__ . '/../' . $sanitized);
$normalizedBase = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $uploadsBase);
$normalizedSource = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sourcePath ?: '');
if ($sourcePath === false || stripos($normalizedSource, $normalizedBase) !== 0) {
    sendNotFound();
}

$mode = strtolower(trim((string)($_GET['mode'] ?? DEFAULT_THUMB_MODE)));
if ($mode === '' || !isset(THUMB_MODES[$mode])) {
    $mode = DEFAULT_THUMB_MODE;
}
$modeConfig = THUMB_MODES[$mode];

$thumbDir = $uploadsBase . DIRECTORY_SEPARATOR . 'thumbs';
if (!is_dir($thumbDir) && !mkdir($thumbDir, 0755, true) && !is_dir($thumbDir)) {
    sendNotFound();
}

$thumbFilename = "{$photoId}.{$mode}.jpg";
$thumbPath = $thumbDir . DIRECTORY_SEPARATOR . $thumbFilename;
$sourceMTime = filemtime($sourcePath);
if ($sourceMTime === false) {
    sendNotFound();
}
$thumbMTime = is_file($thumbPath) ? filemtime($thumbPath) : 0;
if ($thumbMTime !== false && $thumbMTime >= $sourceMTime) {
    outputImageFile($thumbPath);
}

$sourceImage = createSourceImage($sourcePath);
if (!$sourceImage) {
    streamOriginalImage($sourcePath);
}

$width = imagesx($sourceImage);
$height = imagesy($sourceImage);
if ($width <= 0 || $height <= 0) {
    imagedestroy($sourceImage);
    sendNotFound();
}

$scale = min(1, $modeConfig['maxWidth'] / $width, $modeConfig['maxHeight'] / $height);
$scale = max($scale, 0.1);
$newWidth = max(1, (int)round($width * $scale));
$newHeight = max(1, (int)round($height * $scale));

$thumbImage = imagecreatetruecolor($newWidth, $newHeight);
imagefill($thumbImage, 0, 0, imagecolorallocate($thumbImage, 255, 255, 255));
imagecopyresampled(
    $thumbImage,
    $sourceImage,
    0,
    0,
    0,
    0,
    $newWidth,
    $newHeight,
    $width,
    $height
);

$created = imagejpeg($thumbImage, $thumbPath, $modeConfig['quality']);
imagedestroy($sourceImage);
imagedestroy($thumbImage);

if (!$created) {
    @unlink($thumbPath);
    streamOriginalImage($sourcePath);
}

outputImageFile($thumbPath);
