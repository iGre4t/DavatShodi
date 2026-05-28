<?php
declare(strict_types=1);

require_once __DIR__ . '/../../api/lib/tab-permissions.php';
require_once __DIR__ . '/useractivitylogs/activity-logger.php';
require_once __DIR__ . '/tc-security-loader.php';
$tcStoreSessionUser = requireTabPermissionFromSession('task-club', true);
tcSecurityGetCsrfToken();

header('Content-Type: application/json; charset=utf-8');

$baseDir = __DIR__;
$prizesFile = $baseDir . DIRECTORY_SEPARATOR . 'TC Prizes.json';
$prizeLevelsFile = $baseDir . DIRECTORY_SEPARATOR . 'TC Prize Levels.json';
$settingsFile = $baseDir . DIRECTORY_SEPARATOR . 'Setting.json';
$inviteesMappedFile = $baseDir . DIRECTORY_SEPARATOR . 'TC Event' . DIRECTORY_SEPARATOR . 'Invitees mapped.csv';
$inviteesMapFile = $baseDir . DIRECTORY_SEPARATOR . 'TC Event' . DIRECTORY_SEPARATOR . 'TC Mapped.json';

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

function readCsvFileRows(string $path): array
{
  if (!is_file($path)) {
    return [];
  }
  $rows = [];
  $handle = fopen($path, 'r');
  if ($handle === false) {
    return [];
  }
  if (!flock($handle, LOCK_SH)) {
    fclose($handle);
    return [];
  }
  while (($row = fgetcsv($handle)) !== false) {
    $rows[] = is_array($row) ? $row : [];
  }
  flock($handle, LOCK_UN);
  fclose($handle);
  return $rows;
}

function writeCsvFileRows(string $path, array $rows): bool
{
  $dir = dirname($path);
  if ($dir !== '' && !is_dir($dir) && !(mkdir($dir, 0777, true) || is_dir($dir))) {
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
  if (!ftruncate($handle, 0) || fseek($handle, 0) !== 0) {
    flock($handle, LOCK_UN);
    fclose($handle);
    return false;
  }
  foreach ($rows as $row) {
    if (fputcsv($handle, is_array($row) ? $row : []) === false) {
      flock($handle, LOCK_UN);
      fclose($handle);
      return false;
    }
  }
  fflush($handle);
  flock($handle, LOCK_UN);
  fclose($handle);
  return true;
}

function readInviteesMappingConfig(string $path): array
{
  if (!is_file($path)) {
    return [];
  }
  $content = file_get_contents($path);
  if ($content === false) {
    return [];
  }
  $data = json_decode($content, true);
  return is_array($data) ? $data : [];
}

function normalizeUnicodeDigits(string $value): string
{
  return strtr($value, [
    '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
    '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
    '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'
  ]);
}

function normalizeLookupToken(string $value): string
{
  $normalized = normalizeUnicodeDigits($value);
  $normalized = preg_replace('/\s+/u', '', $normalized);
  if (!is_string($normalized)) {
    $normalized = '';
  }
  $normalized = trim($normalized);
  if ($normalized === '') {
    return '';
  }
  if (function_exists('mb_strtolower')) {
    return mb_strtolower($normalized, 'UTF-8');
  }
  return strtolower($normalized);
}

function normalizeHeaderToken(string $value): string
{
  $token = normalizeLookupToken($value);
  return str_replace(['-', '_'], ' ', $token);
}

function findHeaderIndexByNames(array $header, array $names): int
{
  $targets = [];
  foreach ($names as $name) {
    if (!is_scalar($name)) {
      continue;
    }
    $token = normalizeHeaderToken((string)$name);
    if ($token !== '') {
      $targets[$token] = true;
    }
  }
  if (!$targets) {
    return -1;
  }
  foreach ($header as $index => $value) {
    $token = normalizeHeaderToken((string)$value);
    if ($token !== '' && isset($targets[$token])) {
      return (int)$index;
    }
  }
  return -1;
}

function resolveMappedColumnIndex(array $header, array $mapping, array $mappingKeys, array $fallbackNames): int
{
  foreach ($mappingKeys as $mappingKey) {
    $mappedIndex = $mapping[(string)$mappingKey] ?? null;
    if (is_numeric($mappedIndex)) {
      $index = (int)$mappedIndex;
      if ($index >= 0 && isset($header[$index])) {
        return $index;
      }
    }
  }
  return findHeaderIndexByNames($header, $fallbackNames);
}

function normalizeRowsWidth(array &$rows, int $width): void
{
  if ($width <= 0) {
    return;
  }
  foreach ($rows as $index => $row) {
    if (!is_array($row)) {
      $rows[$index] = array_fill(0, $width, '');
      continue;
    }
    if (count($row) < $width) {
      $rows[$index] = array_pad($row, $width, '');
      continue;
    }
    if (count($row) > $width) {
      $rows[$index] = array_slice($row, 0, $width);
    }
  }
}

function ensureAdminColumn(array &$rows): int
{
  if (!$rows || !is_array($rows[0])) {
    return -1;
  }
  $header = $rows[0];
  $existingIndex = findHeaderIndexByNames($header, ['Admin', 'TC Admin', 'is admin', 'ادمین']);
  if ($existingIndex >= 0) {
    normalizeRowsWidth($rows, count($header));
    return $existingIndex;
  }

  $header[] = 'Admin';
  $rows[0] = $header;
  normalizeRowsWidth($rows, count($header));
  return count($header) - 1;
}

function readAdminColumnIndex(array $rows): int
{
  if (!$rows || !is_array($rows[0])) {
    return -1;
  }
  return findHeaderIndexByNames($rows[0], ['Admin', 'TC Admin', 'is admin', 'ادمین']);
}

function isAdminCellValue($value): bool
{
  if (is_bool($value)) {
    return $value;
  }
  if (is_numeric($value)) {
    return ((float)$value) > 0;
  }
  $token = normalizeLookupToken((string)$value);
  if ($token === '') {
    return false;
  }
  return in_array($token, ['1', 'true', 'yes', 'admin', 'ادمین'], true);
}

function resolveInviteeRowByWorkId(array $rows, int $workIdIndex, string $workId): int
{
  if ($workIdIndex < 0) {
    return -1;
  }
  $needle = normalizeLookupToken($workId);
  if ($needle === '') {
    return -1;
  }
  for ($i = 1; $i < count($rows); $i += 1) {
    $row = is_array($rows[$i] ?? null) ? $rows[$i] : [];
    $candidate = normalizeLookupToken((string)($row[$workIdIndex] ?? ''));
    if ($candidate !== '' && $candidate === $needle) {
      return $i;
    }
  }
  return -1;
}

function buildInviteeAdminItem(
  array $header,
  array $mapping,
  array $row,
  int $workIdIndex,
  int $adminIndex
): array {
  $firstNameIndex = resolveMappedColumnIndex(
    $header,
    $mapping,
    ['firstName', 'first_name', 'name', 'first name'],
    ['First Name', 'first name', 'name', 'نام']
  );
  $lastNameIndex = resolveMappedColumnIndex(
    $header,
    $mapping,
    ['lastName', 'last_name', 'family', 'surname', 'last name'],
    ['Last Name', 'last name', 'family', 'surname', 'نام خانوادگی']
  );
  $phoneIndex = resolveMappedColumnIndex(
    $header,
    $mapping,
    ['phoneNumber', 'phone', 'mobile', 'phone_number'],
    ['Phone Number', 'phone', 'mobile', 'شماره موبایل', 'شماره تلفن']
  );
  $nationalIdIndex = resolveMappedColumnIndex(
    $header,
    $mapping,
    ['nationalId', 'national_id', 'national id'],
    ['National ID', 'national id', 'کد ملی']
  );

  $firstName = trim((string)($row[$firstNameIndex] ?? ''));
  $lastName = trim((string)($row[$lastNameIndex] ?? ''));
  $workId = trim((string)($row[$workIdIndex] ?? ''));
  $phone = trim((string)($row[$phoneIndex] ?? ''));
  $nationalId = trim((string)($row[$nationalIdIndex] ?? ''));
  $fullName = trim($firstName . ' ' . $lastName);

  return [
    'workId' => $workId,
    'firstName' => $firstName,
    'lastName' => $lastName,
    'fullName' => $fullName,
    'phone' => $phone,
    'nationalId' => $nationalId,
    'isAdmin' => $adminIndex >= 0 ? isAdminCellValue($row[$adminIndex] ?? '') : false
  ];
}

function collectAssignedAdmins(string $inviteesMappedFile, string $inviteesMapFile): array
{
  $rows = readCsvFileRows($inviteesMappedFile);
  if (!$rows || !is_array($rows[0])) {
    return [];
  }
  $header = $rows[0];
  $mapping = readInviteesMappingConfig($inviteesMapFile);
  $workIdIndex = resolveMappedColumnIndex(
    $header,
    $mapping,
    ['workId', 'username'],
    ['Work ID', 'work id', 'workid', 'username', 'کد پرسنلی', 'کد ملی']
  );
  if ($workIdIndex < 0) {
    return [];
  }
  $adminIndex = readAdminColumnIndex($rows);
  if ($adminIndex < 0) {
    return [];
  }

  $items = [];
  for ($i = 1; $i < count($rows); $i += 1) {
    $row = is_array($rows[$i] ?? null) ? $rows[$i] : [];
    if (!isAdminCellValue($row[$adminIndex] ?? '')) {
      continue;
    }
    $item = buildInviteeAdminItem($header, $mapping, $row, $workIdIndex, $adminIndex);
    if ($item['workId'] === '') {
      continue;
    }
    $items[] = $item;
  }
  usort($items, static function ($left, $right) {
    $leftName = trim(((string)($left['firstName'] ?? '')) . ' ' . ((string)($left['lastName'] ?? '')));
    $rightName = trim(((string)($right['firstName'] ?? '')) . ' ' . ((string)($right['lastName'] ?? '')));
    if ($leftName === $rightName) {
      return strcmp((string)($left['workId'] ?? ''), (string)($right['workId'] ?? ''));
    }
    return strcmp($leftName, $rightName);
  });
  return $items;
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

function normalizeHexColor($value, $fallback = '') {
  $color = strtoupper(trim((string)$value));
  if ($color === '' && is_string($fallback) && $fallback !== '') {
    $color = strtoupper(trim($fallback));
  }
  if (preg_match('/^#[0-9A-F]{6}$/', $color)) {
    return $color;
  }
  return '';
}

function requireTcStoreCsrf(?array $payload = null): void
{
  $csrfToken = tcSecurityReadCsrfFromRequest($payload, 'csrf');
  if (!tcSecurityIsValidCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
    exit;
  }
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

$tcStoreMainActions = [
  'get_prizes',
  'save_prizes',
  'get_prize_levels',
  'save_prize_levels',
  'get_reward_guide',
  'save_reward_guide',
  'search_invitee_admin',
  'get_admin_assignments',
  'set_admin_assignment'
];

if (in_array($action, $tcStoreMainActions, true) && !userHasPermissionId($tcStoreSessionUser, 'task-club:main')) {
  denyPanelAccess(403, 'You do not have permission to access this Task Club section.', true);
}

if (in_array($action, ['get_settings', 'save_settings'], true)) {
  $canMain = userHasPermissionId($tcStoreSessionUser, 'task-club:main');
  $canEventStyle = userHasPermissionId($tcStoreSessionUser, 'task-club:event-style');
  if (!$canMain && !$canEventStyle) {
    denyPanelAccess(403, 'You do not have permission to access this Task Club section.', true);
  }
}

if ($action === 'get_prizes') {
  $prizes = readJsonFile($prizesFile, []);
  echo json_encode(['status' => 'ok', 'data' => $prizes], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'save_prizes') {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
  }
  $payload = json_decode(file_get_contents('php://input'), true);
  if (!is_array($payload)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload.']);
    exit;
  }
  requireTcStoreCsrf($payload);
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
    $score = normalizePositiveInt(
      $item['score']
      ?? $item['levelScore']
      ?? $item['level_score']
      ?? 0
    );
    if ($score <= 0) {
      continue;
    }
    $name = normalizeLevelName(
      $item['name']
      ?? $item['levelName']
      ?? $item['level_name']
      ?? $item['label']
      ?? '',
      'Level ' . $score
    );
    if ($name === '') {
      continue;
    }
    $type = normalizeLevelType(
      $item['type']
      ?? $item['levelType']
      ?? $item['level_type']
      ?? 'value_sum'
    );
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
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
  }
  $payload = json_decode(file_get_contents('php://input'), true);
  if (!is_array($payload)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload.']);
    exit;
  }
  requireTcStoreCsrf($payload);
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
    $score = normalizePositiveInt(
      $item['score']
      ?? $item['levelScore']
      ?? $item['level_score']
      ?? 0
    );
    if ($score <= 0) {
      continue;
    }
    $name = normalizeLevelName(
      $item['name']
      ?? $item['levelName']
      ?? $item['level_name']
      ?? $item['label']
      ?? '',
      'Level ' . $score
    );
    if ($name === '') {
      continue;
    }
    $type = normalizeLevelType(
      $item['type']
      ?? $item['levelType']
      ?? $item['level_type']
      ?? 'value_sum'
    );
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

if ($action === 'get_reward_guide') {
  $settings = readJsonFile($settingsFile, []);
  $guide = is_array($settings['rewardGuide'] ?? null) ? $settings['rewardGuide'] : [];
  echo json_encode([
    'status' => 'ok',
    'data' => [
      'title' => trim((string)($guide['title'] ?? 'راهنمای دریافت جایزه')),
      'text' => trim((string)($guide['text'] ?? ''))
    ]
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'save_reward_guide') {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
  }
  $payload = json_decode(file_get_contents('php://input'), true);
  if (!is_array($payload)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload.']);
    exit;
  }
  requireTcStoreCsrf($payload);
  $guide = is_array($payload['rewardGuide'] ?? null) ? $payload['rewardGuide'] : [];
  $settings = readJsonFile($settingsFile, []);
  $settings['rewardGuide'] = [
    'title' => trim((string)($guide['title'] ?? 'راهنمای دریافت جایزه')),
    'text' => trim((string)($guide['text'] ?? ''))
  ];
  if (!writeJsonFile($settingsFile, $settings)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to save reward guide.']);
    exit;
  }
  echo json_encode(['status' => 'ok'], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'get_settings') {
  $defaults = [
    'active' => false,
    'duration' => false,
    'maintenanceMode' => false,
    'startDate' => '',
    'startTime' => '',
    'endDate' => '',
    'endTime' => '',
    'hint' => 'شانس خودت رو امتحان کن و جایزه ببر',
    'hintHtml' => '',
    'hintAlign' => 'right',
    'eventLogo' => '',
    'eventColors' => [
      'secondary' => '#2F8FFF',
      'highlight' => '#20C997',
      'accentSoft' => '#FFB347'
    ]
  ];
  $stored = readJsonFile($settingsFile, []);
  $settings = array_merge($defaults, is_array($stored) ? $stored : []);
  $settings['maintenanceMode'] = (bool)($settings['maintenanceMode'] ?? false);
  $storedColors = is_array($settings['eventColors'] ?? null) ? $settings['eventColors'] : [];
  $settings['eventLogo'] = trim((string)($settings['eventLogo'] ?? ''));
  $settings['eventColors'] = [
    'secondary' => normalizeHexColor($storedColors['secondary'] ?? '', $defaults['eventColors']['secondary']),
    'highlight' => normalizeHexColor($storedColors['highlight'] ?? '', $defaults['eventColors']['highlight']),
    'accentSoft' => normalizeHexColor($storedColors['accentSoft'] ?? '', $defaults['eventColors']['accentSoft'])
  ];
  echo json_encode(['status' => 'ok', 'data' => $settings], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'save_settings') {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
  }
  $payload = json_decode(file_get_contents('php://input'), true);
  if (!is_array($payload)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload.']);
    exit;
  }
  requireTcStoreCsrf($payload);
  $incomingSettings = $payload['settings'] ?? [];
  if (!is_array($incomingSettings)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid settings.']);
    exit;
  }
  $storedSettings = readJsonFile($settingsFile, []);
  $settings = array_merge(is_array($storedSettings) ? $storedSettings : [], $incomingSettings);
  $settings['hint'] = is_string($settings['hint'] ?? null) ? trim($settings['hint']) : '';
  $settings['hintHtml'] = is_string($settings['hintHtml'] ?? null) ? trim($settings['hintHtml']) : '';
  $settings['hintAlign'] = is_string($settings['hintAlign'] ?? null) ? trim($settings['hintAlign']) : 'right';
  $settings['maintenanceMode'] = (bool)($settings['maintenanceMode'] ?? false);
  $incomingColors = is_array($settings['eventColors'] ?? null) ? $settings['eventColors'] : [];
  $settings['eventLogo'] = is_string($settings['eventLogo'] ?? null) ? trim($settings['eventLogo']) : '';
  $settings['eventColors'] = [
    'secondary' => normalizeHexColor($incomingColors['secondary'] ?? '', '#2F8FFF') ?: '#2F8FFF',
    'highlight' => normalizeHexColor($incomingColors['highlight'] ?? '', '#20C997') ?: '#20C997',
    'accentSoft' => normalizeHexColor($incomingColors['accentSoft'] ?? '', '#FFB347') ?: '#FFB347'
  ];
  if (!writeJsonFile($settingsFile, $settings)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to save settings.']);
    exit;
  }
  echo json_encode(['status' => 'ok']);
  exit;
}

if ($action === 'search_invitee_admin') {
  $workId = trim((string)($_GET['work_id'] ?? $_POST['work_id'] ?? ''));
  if ($workId === '') {
    echo json_encode(['status' => 'error', 'message' => 'Please enter Work ID.']);
    exit;
  }

  $rows = readCsvFileRows($inviteesMappedFile);
  if (!$rows || !is_array($rows[0])) {
    echo json_encode(['status' => 'error', 'message' => 'Invitees mapped CSV is not ready.']);
    exit;
  }
  $header = $rows[0];
  $mapping = readInviteesMappingConfig($inviteesMapFile);
  $workIdIndex = resolveMappedColumnIndex(
    $header,
    $mapping,
    ['workId', 'username'],
    ['Work ID', 'work id', 'workid', 'username', 'کد پرسنلی', 'کد ملی']
  );
  if ($workIdIndex < 0) {
    echo json_encode(['status' => 'error', 'message' => 'Work ID column was not found in Invitees mapped CSV.']);
    exit;
  }
  $rowIndex = resolveInviteeRowByWorkId($rows, $workIdIndex, $workId);
  if ($rowIndex < 0) {
    echo json_encode(['status' => 'error', 'message' => 'No user was found with this Work ID.']);
    exit;
  }
  $adminIndex = readAdminColumnIndex($rows);
  $item = buildInviteeAdminItem(
    $header,
    $mapping,
    is_array($rows[$rowIndex] ?? null) ? $rows[$rowIndex] : [],
    $workIdIndex,
    $adminIndex
  );
  echo json_encode(['status' => 'ok', 'data' => ['invitee' => $item]], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'get_admin_assignments') {
  $admins = collectAssignedAdmins($inviteesMappedFile, $inviteesMapFile);
  echo json_encode(['status' => 'ok', 'data' => ['admins' => $admins]], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'set_admin_assignment') {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
  }
  $payload = json_decode(file_get_contents('php://input'), true);
  if (!is_array($payload)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload.']);
    exit;
  }
  requireTcStoreCsrf($payload);

  $workId = trim((string)($payload['workId'] ?? $payload['work_id'] ?? ''));
  $isAdmin = (bool)($payload['isAdmin'] ?? $payload['is_admin'] ?? false);
  if ($workId === '') {
    echo json_encode(['status' => 'error', 'message' => 'Please enter Work ID.']);
    exit;
  }

  $rows = readCsvFileRows($inviteesMappedFile);
  if (!$rows || !is_array($rows[0])) {
    echo json_encode(['status' => 'error', 'message' => 'Invitees mapped CSV is not ready.']);
    exit;
  }
  $header = $rows[0];
  $mapping = readInviteesMappingConfig($inviteesMapFile);
  $workIdIndex = resolveMappedColumnIndex(
    $header,
    $mapping,
    ['workId', 'username'],
    ['Work ID', 'work id', 'workid', 'username', 'کد پرسنلی', 'کد ملی']
  );
  if ($workIdIndex < 0) {
    echo json_encode(['status' => 'error', 'message' => 'Work ID column was not found in Invitees mapped CSV.']);
    exit;
  }
  $rowIndex = resolveInviteeRowByWorkId($rows, $workIdIndex, $workId);
  if ($rowIndex < 0) {
    echo json_encode(['status' => 'error', 'message' => 'No user was found with this Work ID.']);
    exit;
  }

  $adminIndex = ensureAdminColumn($rows);
  if ($adminIndex < 0) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to prepare Admin column.']);
    exit;
  }
  if (!is_array($rows[$rowIndex])) {
    $rows[$rowIndex] = [];
  }
  $headerWidth = count(is_array($rows[0]) ? $rows[0] : []);
  if ($headerWidth > 0 && count($rows[$rowIndex]) < $headerWidth) {
    $rows[$rowIndex] = array_pad($rows[$rowIndex], $headerWidth, '');
  }
  $rows[$rowIndex][$adminIndex] = $isAdmin ? '1' : '';

  if (!writeCsvFileRows($inviteesMappedFile, $rows)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to save Admin assignment.']);
    exit;
  }

  $updatedItem = buildInviteeAdminItem(
    is_array($rows[0]) ? $rows[0] : [],
    $mapping,
    is_array($rows[$rowIndex] ?? null) ? $rows[$rowIndex] : [],
    $workIdIndex,
    $adminIndex
  );
  $admins = collectAssignedAdmins($inviteesMappedFile, $inviteesMapFile);
  tcActivityLogUserActivity([
    'level' => 'info',
    'action' => 'taskclub.admin_assignment_changed',
    'entity_type' => 'taskclub_user',
    'entity_id' => $workId,
    'status' => 'success',
    'message' => $isAdmin ? 'Task Club admin assigned.' : 'Task Club admin removed.',
    'metadata' => [
      'work_id' => $workId,
      'is_admin' => $isAdmin,
      'full_name' => $updatedItem['fullName'] ?? null
    ],
    'audit' => true
  ]);
  echo json_encode([
    'status' => 'ok',
    'message' => $isAdmin ? 'Admin assigned successfully.' : 'Admin removed successfully.',
    'data' => [
      'invitee' => $updatedItem,
      'admins' => $admins
    ]
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);

