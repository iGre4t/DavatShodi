<?php
declare(strict_types=1);

require_once __DIR__ . '/egm-database-runtime.php';

$relative = trim(str_replace('\\', '/', rawurldecode((string)($_GET['path'] ?? ''))), '/');
if ($relative === ''
    || preg_match('#^(?:tasks/[A-Za-z0-9._-]+/(?:photos/)?|InviteCards/)[A-Za-z0-9._-]+$#D', $relative) !== 1
    || !in_array(strtolower((string)pathinfo($relative, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf'], true)) {
  http_response_code(404);
  exit;
}

$path = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
$asset = egmDatabaseRuntimeRead($path);
if (!is_array($asset)) {
  http_response_code(404);
  exit;
}

$types = [
  'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
  'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
  'pdf' => 'application/pdf',
];
$extension = strtolower((string)pathinfo($relative, PATHINFO_EXTENSION));
$etag = '"' . hash('sha256', (string)$asset['content']) . '"';
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
  http_response_code(304);
  exit;
}
header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
header('Content-Length: ' . strlen((string)$asset['content']));
header('Cache-Control: private, max-age=300');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
echo $asset['content'];
