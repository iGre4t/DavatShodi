<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../api/lib/tab-permissions.php';
require_once __DIR__ . '/tc-security.php';
require_once __DIR__ . '/invitees_csv_safety.php';
require_once __DIR__ . '/invitees_special_access.php';
$tcInviteesUploadSessionUser = requireTabPermissionFromSession('task-club', true);
if (!userHasPermissionId($tcInviteesUploadSessionUser, 'task-club:invitees')) {
  denyPanelAccess(403, 'You do not have permission to access this Task Club section.', true);
}
$tcInviteesUploadSpecialAccess = tcInviteesSpecialAccessForPanelUser($tcInviteesUploadSessionUser, __DIR__ . '/tasks/task-access.json');
if (empty($tcInviteesUploadSpecialAccess['manageInvitees'])) {
  denyPanelAccess(403, 'You do not have permission to manage invitees.', true);
}
tcSecurityGetCsrfToken();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  http_response_code(405);
  echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
  exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
  echo json_encode(['status' => 'error', 'message' => 'Invalid payload.']);
  exit;
}

$csrfToken = tcSecurityReadCsrfFromRequest($input, 'csrf');
if (!tcSecurityIsValidCsrfToken($csrfToken)) {
  http_response_code(403);
  echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
  exit;
}

$csv = (string)($input['csv'] ?? '');
$mapping = $input['mapping'] ?? null;
if ($csv === '' || !is_array($mapping)) {
  echo json_encode(['status' => 'error', 'message' => 'Missing data.']);
  exit;
}

$baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'TC Event';
if (!is_dir($baseDir)) {
  mkdir($baseDir, 0777, true);
}

function tcInviteesUploadWriteTextLocked(string $path, string $content): bool
{
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
  $remaining = $content;
  while ($remaining !== '') {
    $written = fwrite($handle, $remaining);
    if (!is_int($written) || $written <= 0) {
      flock($handle, LOCK_UN);
      fclose($handle);
      return false;
    }
    $remaining = (string)substr($remaining, $written);
  }
  fflush($handle);
  flock($handle, LOCK_UN);
  fclose($handle);
  return true;
}

function tcInviteesUploadReadHeader(string $csv): array
{
  $handle = fopen('php://temp', 'w+b');
  if ($handle === false || fwrite($handle, $csv) === false || rewind($handle) === false) {
    if (is_resource($handle)) fclose($handle);
    return [];
  }
  $header = fgetcsv($handle, null, ',', '"', '\\');
  fclose($handle);
  return is_array($header) ? $header : [];
}

$uploadHeader = tcInviteesUploadReadHeader($csv);
$uploadColumnCount = count($uploadHeader);
$workIdMapping = (int)($mapping['workId'] ?? -1);
if ($uploadColumnCount === 0 || $workIdMapping < 0 || $workIdMapping >= $uploadColumnCount) {
  echo json_encode(['status' => 'error', 'message' => 'The uploaded CSV or Work ID mapping is invalid.']);
  exit;
}
foreach (['firstName', 'lastName', 'nationalId', 'phoneNumber'] as $mappingKey) {
  $mappingIndex = (int)($mapping[$mappingKey] ?? -1);
  if ($mappingIndex < -1 || $mappingIndex >= $uploadColumnCount) {
    echo json_encode(['status' => 'error', 'message' => 'One or more mapped columns are outside the uploaded CSV.']);
    exit;
  }
}

$filePath = $baseDir . DIRECTORY_SEPARATOR . 'Invitees mapped.csv';
$mapPath = $baseDir . DIRECTORY_SEPARATOR . 'TC Mapped.json';
$answersPath = $baseDir . DIRECTORY_SEPARATOR . 'Answers.csv';
$loginAttemptsPath = $baseDir . DIRECTORY_SEPARATOR . 'login_attempts.json';

if (!tcInviteesCsvBeginTransaction($filePath) || !tcInviteesCsvReplaceText($filePath, $csv, false)) {
  echo json_encode(['status' => 'error', 'message' => 'Failed to save file.']);
  exit;
}

$mapPayload = [
  'workId' => (int)($mapping['workId'] ?? -1),
  'firstName' => (int)($mapping['firstName'] ?? -1),
  'lastName' => (int)($mapping['lastName'] ?? -1),
  'nationalId' => (int)($mapping['nationalId'] ?? -1),
  'phoneNumber' => (int)($mapping['phoneNumber'] ?? -1)
];

$mapJson = json_encode($mapPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
if (!is_string($mapJson) || !tcInviteesUploadWriteTextLocked($mapPath, $mapJson)) {
  echo json_encode(['status' => 'error', 'message' => 'Failed to save mapping.']);
  exit;
}

$emptyAttempts = json_encode(new stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
if ($emptyAttempts === false || !tcInviteesUploadWriteTextLocked($loginAttemptsPath, $emptyAttempts . PHP_EOL)) {
  echo json_encode(['status' => 'error', 'message' => 'Failed to reset login attempts.']);
  exit;
}

if (!tcInviteesUploadWriteTextLocked($answersPath, '')) {
  echo json_encode(['status' => 'error', 'message' => 'Failed to reset answers table.']);
  exit;
}

if (!defined('TCQ_INCLUDE_ONLY')) {
  define('TCQ_INCLUDE_ONLY', true);
}
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TCQ.php';

if (!tcqEnsureInviteesColumns($filePath)) {
  echo json_encode(['status' => 'error', 'message' => 'Failed to prepare invitees columns.']);
  exit;
}
$questions = tcqLoadStore(__DIR__ . DIRECTORY_SEPARATOR . 'TCQ list.json');
if (!tcqSyncAnswersSheet($answersPath, $questions, $questions)) {
  echo json_encode(['status' => 'error', 'message' => 'Failed to rebuild answers table.']);
  exit;
}

echo json_encode(['status' => 'ok']);

