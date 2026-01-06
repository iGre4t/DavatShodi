<?php
declare(strict_types=1);

/**
 * Lightweight loader that renders only the requested tab markup.
 * Keeping this separate lets panel.php avoid including every tab on every request.
 */

$allowedTabs = [
  'features' => __DIR__ . '/features.php',
  'gallery' => __DIR__ . '/gallery-tab.php',
  'guests' => __DIR__ . '/guests.php',
  'invite' => __DIR__ . '/invitepanel.php',
  'typography' => __DIR__ . '/typography.php',
  'winners' => __DIR__ . '/winnerstab.php'
];

$tab = trim((string)($_GET['tab'] ?? ''));

if ($tab === '' || !isset($allowedTabs[$tab])) {
  if (!headers_sent()) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
  }
  echo json_encode(
    ['status' => 'error', 'message' => 'Unknown panel tab requested.'],
    JSON_UNESCAPED_UNICODE
  );
  exit;
}

$path = $allowedTabs[$tab];

if (!is_file($path)) {
  if (!headers_sent()) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
  }
  echo json_encode(
    ['status' => 'error', 'message' => 'Tab file not found.'],
    JSON_UNESCAPED_UNICODE
  );
  exit;
}

ob_start();
include $path;
$content = (string)ob_get_clean();

if (!headers_sent()) {
  header('Content-Type: text/html; charset=utf-8');
}

echo $content;
