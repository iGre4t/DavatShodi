<?php
header('Content-Type: application/json; charset=utf-8');

$baseDir = __DIR__;
$prizesFile = $baseDir . DIRECTORY_SEPARATOR . 'TC Prizes.json';
$prizeLevelsFile = $baseDir . DIRECTORY_SEPARATOR . 'TC Prize Levels.json';
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

function normalizePositiveNumber($value) {
  if (!is_scalar($value)) {
    return 0;
  }
  $normalized = preg_replace('/[,\s]+/', '', (string)$value);
  if (!is_string($normalized) || $normalized === '' || !is_numeric($normalized)) {
    return 0;
  }
  $parsed = (float)$normalized;
  if (!is_finite($parsed) || $parsed < 0) {
    return 0;
  }
  return $parsed;
}

function normalizePositiveInt($value) {
  if (!is_scalar($value)) {
    return 0;
  }
  $parsed = (int)$value;
  return $parsed > 0 ? $parsed : 0;
}

function normalizeLevelName($value, $fallback = '') {
  $name = trim((string)$value);
  if ($name === '') {
    $name = trim((string)$fallback);
  }
  return $name;
}

function normalizeLevelType($value) {
  $token = strtolower(trim((string)$value));
  if ($token === 'out_of_value') {
    return 'out_of_value';
  }
  return 'value_sum';
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
    $value = normalizePositiveNumber($prize['value'] ?? 0);
    $isFake = (bool)($prize['isFake'] ?? false);
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
      'last' => $last,
      'value' => $value,
      'isFake' => $isFake
    ];
  }
  if (!writeJsonFile($prizesFile, $normalized)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to save prizes.']);
    exit;
  }
  echo json_encode(['status' => 'ok']);
  exit;
}

if ($action === 'get_prize_levels') {
  $levels = readJsonFile($prizeLevelsFile, []);
  $normalized = [];
  foreach ($levels as $item) {
    if (!is_array($item)) {
      continue;
    }
    $score = normalizePositiveInt($item['score'] ?? 0);
    if ($score <= 0) {
      continue;
    }
    $name = normalizeLevelName($item['name'] ?? '', 'Level ' . $score);
    if ($name === '') {
      continue;
    }
    $type = normalizeLevelType($item['type'] ?? 'value_sum');
    $id = trim((string)($item['id'] ?? ''));
    if ($id === '') {
      $id = uniqid('lvl_', true);
    }
    $normalized[] = [
      'id' => $id,
      'name' => $name,
      'type' => $type,
      'score' => $score
    ];
  }
  usort($normalized, static function ($a, $b) {
    return (int)($a['score'] ?? 0) <=> (int)($b['score'] ?? 0);
  });
  echo json_encode(['status' => 'ok', 'data' => $normalized], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'save_prize_levels') {
  $payload = json_decode(file_get_contents('php://input'), true);
  $levels = $payload['levels'] ?? [];
  if (!is_array($levels)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid levels.']);
    exit;
  }

  $normalized = [];
  foreach ($levels as $item) {
    if (!is_array($item)) {
      continue;
    }
    $score = normalizePositiveInt($item['score'] ?? 0);
    if ($score <= 0) {
      continue;
    }
    $name = normalizeLevelName($item['name'] ?? '', 'Level ' . $score);
    if ($name === '') {
      continue;
    }
    $type = normalizeLevelType($item['type'] ?? 'value_sum');
    $id = trim((string)($item['id'] ?? ''));
    if ($id === '') {
      $id = uniqid('lvl_', true);
    }
    $normalized[] = [
      'id' => $id,
      'name' => $name,
      'type' => $type,
      'score' => $score
    ];
  }

  usort($normalized, static function ($a, $b) {
    return (int)($a['score'] ?? 0) <=> (int)($b['score'] ?? 0);
  });

  if (!writeJsonFile($prizeLevelsFile, $normalized)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to save levels.']);
    exit;
  }
  echo json_encode(['status' => 'ok']);
  exit;
}

if ($action === 'get_settings') {
  $defaults = [
    'active' => false,
    'duration' => false,
    'startDate' => '',
    'startTime' => '',
    'endDate' => '',
    'endTime' => '',
    'hint' => 'شانس خودت رو امتحان کن و جایزه ببر',
    'hintHtml' => '',
    'hintAlign' => 'right'
  ];
  $stored = readJsonFile($settingsFile, []);
  $settings = array_merge($defaults, is_array($stored) ? $stored : []);
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
  $settings['hintHtml'] = is_string($settings['hintHtml'] ?? null) ? trim($settings['hintHtml']) : '';
  $settings['hintAlign'] = is_string($settings['hintAlign'] ?? null) ? trim($settings['hintAlign']) : 'right';
  if (!writeJsonFile($settingsFile, $settings)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to save settings.']);
    exit;
  }
  echo json_encode(['status' => 'ok']);
  exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);

