<?php
if (!defined('EVENTS_ROOT')) {
  define('EVENTS_ROOT', __DIR__ . '/events');
}
const DEFAULT_DRAW_CAPTION = 'قرعه‌کشی مشهد مقدس';

function sanitizeEventCode(string $value): string
{
  return preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string)$value)) ?? '';
}

function ensureEventDirectory(string $eventCode): string
{
  $directory = rtrim(EVENTS_ROOT, '/\\') . DIRECTORY_SEPARATOR . $eventCode;
  if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
    return '';
  }
  return $directory;
}

function resolvePrizeCsvPath(string $eventCode): string
{
  $code = sanitizeEventCode($eventCode);
  if ($code === '') {
    return '';
  }
  $directory = ensureEventDirectory($code);
  if ($directory === '') {
    return '';
  }
  return $directory . '/prizelist.csv';
}

function resolveDrawSettingsPath(string $eventCode): string
{
  $code = sanitizeEventCode($eventCode);
  if ($code === '') {
    return '';
  }
  $directory = ensureEventDirectory($code);
  if ($directory === '') {
    return '';
  }
  return $directory . '/draw-settings.json';
}

function respondPrizesJson(array $payload, int $statusCode = 200): void
{
  if (!headers_sent()) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
  }
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function readPrizes(string $path): array
{
  if (!is_file($path)) {
    return [];
  }
  $handle = fopen($path, 'r');
  if ($handle === false) {
    return [];
  }
  $result = [];
  fgetcsv($handle);
  while (($line = fgetcsv($handle)) !== false) {
    if (!isset($line[0])) {
      continue;
    }
    $result[] = [
      'id' => (int)$line[0],
      'name' => isset($line[1]) ? (string)$line[1] : ''
    ];
  }
  fclose($handle);
  usort($result, fn ($a, $b) => $a['id'] <=> $b['id']);
  return $result;
}

function writePrizes(string $path, array $prizes): bool
{
  $dir = dirname($path);
  if ($dir === '' || (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir))) {
    return false;
  }
  $handle = fopen($path, 'c+');
  if ($handle === false) {
    return false;
  }
  if (!flock($handle, LOCK_EX)) {
    fclose($handle);
    return false;
  }
  ftruncate($handle, 0);
  rewind($handle);
  fputcsv($handle, ['id', 'name']);
  foreach ($prizes as $entry) {
    fputcsv($handle, [(int)$entry['id'], (string)($entry['name'] ?? '')]);
  }
  fflush($handle);
  flock($handle, LOCK_UN);
  fclose($handle);
  return true;
}

function readDrawSettings(string $path): array
{
  if ($path === '' || !is_file($path)) {
    return [];
  }
  $content = file_get_contents($path);
  if ($content === false) {
    return [];
  }
  $decoded = json_decode($content, true);
  return is_array($decoded) ? $decoded : [];
}

function writeDrawSettings(string $path, array $settings): bool
{
  $dir = dirname($path);
  if ($dir === '' || (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir))) {
    return false;
  }
  $encoded = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($encoded === false) {
    return false;
  }
  return file_put_contents($path, $encoded, LOCK_EX) !== false;
}

function getDrawCaption(string $eventCode): string
{
  $settingsPath = resolveDrawSettingsPath($eventCode);
  $settings = readDrawSettings($settingsPath);
  $caption = trim((string)($settings['caption'] ?? ''));
  return $caption !== '' ? $caption : DEFAULT_DRAW_CAPTION;
}

$eventCode = trim((string)($_REQUEST['event_code'] ?? ''));
$eventCode = sanitizeEventCode($eventCode);
if ($eventCode === '') {
  respondPrizesJson(['status' => 'error', 'message' => 'event_code is required.'], 400);
}
$action = strtolower(trim((string)($_REQUEST['prize_action'] ?? '')));
if ($action === '') {
  respondPrizesJson(['status' => 'error', 'message' => 'prize_action is required.'], 400);
}
$csvPath = resolvePrizeCsvPath($eventCode);
if ($csvPath === '') {
  respondPrizesJson(['status' => 'error', 'message' => 'Unable to access event prize list.'], 500);
}
$drawSettingsPath = resolveDrawSettingsPath($eventCode);
$prizes = readPrizes($csvPath);
switch ($action) {
  case 'list':
    respondPrizesJson([
      'status' => 'ok',
      'prizes' => $prizes,
      'draw_caption' => getDrawCaption($eventCode)
    ]);
    break;
  case 'add':
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
      respondPrizesJson(['status' => 'error', 'message' => 'Prize name is required.'], 422);
    }
    $ids = array_column($prizes, 'id');
    $nextId = $ids ? (max($ids) + 1) : 1;
    $prizes[] = ['id' => $nextId, 'name' => $name];
    if (!writePrizes($csvPath, $prizes)) {
      respondPrizesJson(['status' => 'error', 'message' => 'Unable to persist the prize list.'], 500);
    }
    respondPrizesJson([
      'status' => 'ok',
      'message' => 'Prize added.',
      'prizes' => readPrizes($csvPath),
      'draw_caption' => getDrawCaption($eventCode)
    ]);
    break;
  case 'update':
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($id <= 0 || $name === '') {
      respondPrizesJson(['status' => 'error', 'message' => 'Prize id and name are required.'], 422);
    }
    $found = false;
    foreach ($prizes as &$entry) {
      if ($entry['id'] === $id) {
        $entry['name'] = $name;
        $found = true;
        break;
      }
    }
    unset($entry);
    if (!$found) {
      respondPrizesJson(['status' => 'error', 'message' => 'Prize not found.'], 404);
    }
    if (!writePrizes($csvPath, $prizes)) {
      respondPrizesJson(['status' => 'error', 'message' => 'Unable to persist the prize list.'], 500);
    }
    respondPrizesJson([
      'status' => 'ok',
      'message' => 'Prize updated.',
      'prizes' => readPrizes($csvPath),
      'draw_caption' => getDrawCaption($eventCode)
    ]);
    break;
  case 'delete':
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
      respondPrizesJson(['status' => 'error', 'message' => 'Prize id is required for deletion.'], 422);
    }
    $filtered = array_filter($prizes, fn ($entry) => $entry['id'] !== $id);
    if (count($filtered) === count($prizes)) {
      respondPrizesJson(['status' => 'error', 'message' => 'Prize not found.'], 404);
    }
    if (!writePrizes($csvPath, array_values($filtered))) {
      respondPrizesJson(['status' => 'error', 'message' => 'Unable to persist the prize list.'], 500);
    }
    respondPrizesJson([
      'status' => 'ok',
      'message' => 'Prize removed.',
      'prizes' => readPrizes($csvPath),
      'draw_caption' => getDrawCaption($eventCode)
    ]);
    break;
  case 'save_draw_caption':
    $caption = trim((string)($_POST['caption'] ?? ''));
    if ($caption === '') {
      respondPrizesJson(['status' => 'error', 'message' => 'Draw title is required.'], 422);
    }
    $settings = readDrawSettings($drawSettingsPath);
    $settings['caption'] = $caption;
    if ($drawSettingsPath === '' || !writeDrawSettings($drawSettingsPath, $settings)) {
      respondPrizesJson(['status' => 'error', 'message' => 'Unable to persist the draw title.'], 500);
    }
    respondPrizesJson([
      'status' => 'ok',
      'message' => 'Draw title saved.',
      'prizes' => $prizes,
      'draw_caption' => getDrawCaption($eventCode)
    ]);
    break;
  default:
    respondPrizesJson(['status' => 'error', 'message' => 'Unknown action.'], 400);
    break;
}
