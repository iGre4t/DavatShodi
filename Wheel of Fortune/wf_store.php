<?php
header('Content-Type: application/json; charset=utf-8');

$baseDir = __DIR__;
$prizesFile = $baseDir . DIRECTORY_SEPARATOR . 'WF Prizes.json';
$settingsFile = $baseDir . DIRECTORY_SEPARATOR . 'Setting.json';

function readJsonFile($path, $fallback) {
  if (!is_file($path)) {
    return $fallback;
  }
  $content = file_get_contents($path);
  if ($content === false) {
    return $fallback;
  }
  $data = json_decode($content, true);
  return $data === null ? $fallback : $data;
}

function writeJsonFile($path, $data) {
  $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($encoded === false) {
    return false;
  }
  return file_put_contents($path, $encoded, LOCK_EX) !== false;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'get_prizes') {
  $prizes = readJsonFile($prizesFile, []);
  echo json_encode(['status' => 'ok', 'data' => $prizes], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'save_prizes') {
  $payload = json_decode(file_get_contents('php://input'), true);
  $prizes = $payload['prizes'] ?? [];
  if (!is_array($prizes)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid prizes.']);
    exit;
  }
  $normalized = [];
  foreach ($prizes as $prize) {
    if (!is_array($prize)) {
      continue;
    }
    $name = trim((string)($prize['name'] ?? ''));
    if ($name === '') {
      continue;
    }
    $onWheelName = trim((string)($prize['onWheelName'] ?? $name));
    if ($onWheelName === '') {
      $onWheelName = $name;
    }
    $quantity = (int)($prize['quantity'] ?? 0);
    $last = (int)($prize['last'] ?? $quantity);
    if ($quantity < 0) {
      $quantity = 0;
    }
    if ($last < 0) {
      $last = 0;
    }
    if ($last > $quantity) {
      $last = $quantity;
    }
    $normalized[] = [
      'name' => $name,
      'onWheelName' => $onWheelName,
      'quantity' => $quantity,
      'last' => $last
    ];
  }
  if (!writeJsonFile($prizesFile, $normalized)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to save prizes.']);
    exit;
  }
  echo json_encode(['status' => 'ok']);
  exit;
}

if ($action === 'get_settings') {
  $settings = readJsonFile($settingsFile, [
    'active' => false,
    'duration' => false,
    'startDate' => '',
    'startTime' => '',
    'endDate' => '',
    'endTime' => '',
    'hint' => 'شانس خودت رو امتحان کن و جایزه ببر'
  ]);
  echo json_encode(['status' => 'ok', 'data' => $settings], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'save_settings') {
  $payload = json_decode(file_get_contents('php://input'), true);
  $settings = $payload['settings'] ?? [];
  if (!is_array($settings)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid settings.']);
    exit;
  }
  $settings['hint'] = is_string($settings['hint'] ?? null) ? trim($settings['hint']) : '';
  if (!writeJsonFile($settingsFile, $settings)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to save settings.']);
    exit;
  }
  echo json_encode(['status' => 'ok']);
  exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
