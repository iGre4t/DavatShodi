<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../api/lib/tab-permissions.php';
require_once __DIR__ . '/egm-security.php';
require_once __DIR__ . '/invitees_special_access.php';
require_once __DIR__ . '/invitees_csv_safety.php';

$egmInviteesUpdateSessionUser = requireTabPermissionFromSession('event-guest-manager', true);
if (!userHasPermissionId($egmInviteesUpdateSessionUser, 'event-guest-manager:invitees')) {
  denyPanelAccess(403, 'You do not have permission to access this Event Guest Manager section.', true);
}
$egmInviteesUpdateSpecialAccess = egmInviteesSpecialAccessForPanelUser($egmInviteesUpdateSessionUser, __DIR__ . '/tasks/task-access.json');
if (empty($egmInviteesUpdateSpecialAccess['editInvitee'])) {
  denyPanelAccess(403, 'You do not have permission to edit invitees.', true);
}
egmSecurityGetCsrfToken();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  http_response_code(405);
  echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
  exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
  echo json_encode(['status' => 'error', 'message' => 'Invalid payload.']);
  exit;
}

$csrfToken = egmSecurityReadCsrfFromRequest($input, 'csrf');
if (!egmSecurityIsValidCsrfToken($csrfToken)) {
  http_response_code(403);
  echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
  exit;
}

function normalizeToken(string $value): string
{
  $normalized = trim($value);
  if ($normalized === '') {
    return '';
  }
  $normalized = preg_replace('/\s+/u', '', $normalized) ?? $normalized;
  if (function_exists('mb_strtolower')) {
    return mb_strtolower($normalized, 'UTF-8');
  }
  return strtolower($normalized);
}

function normalizeHeaderToken(string $value): string
{
  return str_replace(['-', '_'], ' ', normalizeToken($value));
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

function resolveMappedIndex(array $header, array $mapping, string $mappingKey, array $fallbackNames): int
{
  $mappedIndex = $mapping[$mappingKey] ?? null;
  if (is_numeric($mappedIndex)) {
    $index = (int)$mappedIndex;
    if ($index >= 0 && $index < count($header)) {
      return $index;
    }
  }
  return findHeaderIndexByNames($header, $fallbackNames);
}

function readJsonArray(string $path): array
{
  if (!is_file($path)) {
    return [];
  }
  $content = file_get_contents($path);
  if (!is_string($content) || $content === '') {
    return [];
  }
  $decoded = json_decode($content, true);
  return is_array($decoded) ? $decoded : [];
}

function readCsvRows(string $path): array
{
  if (egmInviteesCsvIsManagedPath($path)) {
    return egmInviteesCsvReadRowsForUpdate($path);
  }
  if (!is_file($path)) {
    return [];
  }
  $handle = fopen($path, 'r');
  if ($handle === false) {
    return [];
  }
  $rows = [];
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

function writeCsvRowsLocked(string $path, array $rows): bool
{
  if (egmInviteesCsvIsManagedPath($path)) {
    return egmInviteesCsvCommitRows($path, $rows);
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

$rowNumber = (int)($input['row'] ?? 0);
$invitee = is_array($input['invitee'] ?? null) ? $input['invitee'] : $input;

$workId = trim((string)($invitee['workId'] ?? $invitee['work_id'] ?? ''));
$firstName = trim((string)($invitee['firstName'] ?? $invitee['first_name'] ?? ''));
$lastName = trim((string)($invitee['lastName'] ?? $invitee['last_name'] ?? ''));
$nationalId = trim((string)($invitee['nationalId'] ?? $invitee['national_id'] ?? ''));
$phoneNumber = trim((string)($invitee['phoneNumber'] ?? $invitee['phone_number'] ?? ''));

if ($rowNumber <= 1) {
  echo json_encode(['status' => 'error', 'message' => 'Invalid invitee row.']);
  exit;
}
if ($workId === '' || $firstName === '' || $lastName === '' || $nationalId === '' || $phoneNumber === '') {
  echo json_encode(['status' => 'error', 'message' => 'All fields are required.']);
  exit;
}

$baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'EGM Event';
$mappedFile = $baseDir . DIRECTORY_SEPARATOR . 'Invitees mapped.csv';
$mapFile = $baseDir . DIRECTORY_SEPARATOR . 'EGM Mapped.json';

$rows = readCsvRows($mappedFile);
if (!$rows || !is_array($rows[0] ?? null)) {
  echo json_encode(['status' => 'error', 'message' => 'Invitees mapped CSV is not ready.']);
  exit;
}

$header = $rows[0];
$width = count($header);
if ($width <= 0) {
  echo json_encode(['status' => 'error', 'message' => 'Invitees mapped CSV is not valid.']);
  exit;
}

for ($i = 0; $i < count($rows); $i += 1) {
  $row = is_array($rows[$i] ?? null) ? $rows[$i] : [];
  if (count($row) < $width) {
    $row = array_pad($row, $width, '');
  } elseif (count($row) > $width) {
    $row = array_slice($row, 0, $width);
  }
  $rows[$i] = $row;
}

$rowIndex = $rowNumber - 1;
if ($rowIndex < 1 || $rowIndex >= count($rows)) {
  echo json_encode(['status' => 'error', 'message' => 'Invitee row not found.']);
  exit;
}

$mapping = readJsonArray($mapFile);
$workIdIndex = resolveMappedIndex($header, $mapping, 'workId', ['Work ID', 'work id', 'username', 'user name']);
$firstNameIndex = resolveMappedIndex($header, $mapping, 'firstName', ['First Name', 'first name', 'name']);
$lastNameIndex = resolveMappedIndex($header, $mapping, 'lastName', ['Last Name', 'last name', 'family', 'surname']);
$nationalIdIndex = resolveMappedIndex($header, $mapping, 'nationalId', ['National ID', 'national id']);
$phoneNumberIndex = resolveMappedIndex($header, $mapping, 'phoneNumber', ['Phone Number', 'phone number', 'phone', 'mobile']);

if ($workIdIndex < 0 || $firstNameIndex < 0 || $lastNameIndex < 0 || $nationalIdIndex < 0 || $phoneNumberIndex < 0) {
  echo json_encode(['status' => 'error', 'message' => 'Mapped columns are incomplete.']);
  exit;
}

$duplicateByIndex = static function (array $allRows, int $index, string $value, int $skipRowIndex): int {
  if ($index < 0 || $value === '') {
    return -1;
  }
  $needle = normalizeToken($value);
  if ($needle === '') {
    return -1;
  }
  for ($i = 1; $i < count($allRows); $i += 1) {
    if ($i === $skipRowIndex) {
      continue;
    }
    $row = is_array($allRows[$i] ?? null) ? $allRows[$i] : [];
    $candidate = normalizeToken((string)($row[$index] ?? ''));
    if ($candidate !== '' && $candidate === $needle) {
      return $i + 1;
    }
  }
  return -1;
};

$duplicateWork = $duplicateByIndex($rows, $workIdIndex, $workId, $rowIndex);
if ($duplicateWork >= 0) {
  echo json_encode(['status' => 'error', 'message' => "Work ID already exists (row {$duplicateWork})."]);
  exit;
}

$duplicateNational = $duplicateByIndex($rows, $nationalIdIndex, $nationalId, $rowIndex);
if ($duplicateNational >= 0) {
  echo json_encode(['status' => 'error', 'message' => "National ID already exists (row {$duplicateNational})."]);
  exit;
}

$duplicatePhone = $duplicateByIndex($rows, $phoneNumberIndex, $phoneNumber, $rowIndex);
if ($duplicatePhone >= 0) {
  echo json_encode(['status' => 'error', 'message' => "Phone Number already exists (row {$duplicatePhone})."]);
  exit;
}

$rows[$rowIndex][$workIdIndex] = $workId;
$rows[$rowIndex][$firstNameIndex] = $firstName;
$rows[$rowIndex][$lastNameIndex] = $lastName;
$rows[$rowIndex][$nationalIdIndex] = $nationalId;
$rows[$rowIndex][$phoneNumberIndex] = $phoneNumber;

if (!writeCsvRowsLocked($mappedFile, $rows)) {
  echo json_encode(['status' => 'error', 'message' => 'Failed to update Invitees mapped CSV.']);
  exit;
}

echo json_encode(['status' => 'ok', 'message' => 'Invitee updated successfully.']);
