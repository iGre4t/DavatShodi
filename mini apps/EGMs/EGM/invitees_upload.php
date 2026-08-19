<?php
declare(strict_types=1);


require_once __DIR__ . '/egm-database-runtime.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../api/lib/tab-permissions.php';
require_once __DIR__ . '/../../../api/lib/common.php';
require_once __DIR__ . '/../../../api/lib/egm-instance-storage.php';
require_once __DIR__ . '/egm-security.php';
require_once __DIR__ . '/invitees_csv_safety.php';
require_once __DIR__ . '/invitees_special_access.php';
$egmInviteesUploadSessionUser = requireTabPermissionFromSession('event-guest-manager', true);
if (!userHasPermissionId($egmInviteesUploadSessionUser, 'event-guest-manager:invitees')) {
  denyPanelAccess(403, 'You do not have permission to access this Event Guest Manager section.', true);
}
$egmInviteesUploadSpecialAccess = egmInviteesSpecialAccessForPanelUser($egmInviteesUploadSessionUser, __DIR__ . '/tasks/task-access.json');
if (empty($egmInviteesUploadSpecialAccess['manageInvitees'])) {
  denyPanelAccess(403, 'You do not have permission to manage invitees.', true);
}
egmSecurityGetCsrfToken();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  http_response_code(405);
  echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
  exit;
}

$input = json_decode(egmDbFileGetContents('php://input'), true);
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

$csv = (string)($input['csv'] ?? '');
$mapping = $input['mapping'] ?? null;
if ($csv === '' || !is_array($mapping)) {
  echo json_encode(['status' => 'error', 'message' => 'Missing data.']);
  exit;
}

$baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'EGM Event';
if (!is_dir($baseDir)) {
  mkdir($baseDir, 0777, true);
}

function egmInviteesUploadWriteTextLocked(string $path, string $content): bool
{
  $handle = egmDbFopen($path, 'c+');
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

function egmInviteesUploadReadHeader(string $csv): array
{
  $handle = egmDbFopen('php://temp', 'w+b');
  if ($handle === false || fwrite($handle, $csv) === false || rewind($handle) === false) {
    if (is_resource($handle)) fclose($handle);
    return [];
  }
  $header = fgetcsv($handle, null, ',', '"', '\\');
  fclose($handle);
  return is_array($header) ? $header : [];
}

$uploadHeader = egmInviteesUploadReadHeader($csv);
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
$mapPath = $baseDir . DIRECTORY_SEPARATOR . 'EGM Mapped.json';
$answersPath = $baseDir . DIRECTORY_SEPARATOR . 'Answers.csv';
$loginAttemptsPath = $baseDir . DIRECTORY_SEPARATOR . 'login_attempts.json';

if (!egmInviteesCsvBeginTransaction($filePath) || !egmInviteesCsvReplaceText($filePath, $csv, false)) {
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
if (!is_string($mapJson) || !egmInviteesUploadWriteTextLocked($mapPath, $mapJson)) {
  echo json_encode(['status' => 'error', 'message' => 'Failed to save mapping.']);
  exit;
}

$emptyAttempts = json_encode(new stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
if ($emptyAttempts === false || !egmInviteesUploadWriteTextLocked($loginAttemptsPath, $emptyAttempts . PHP_EOL)) {
  echo json_encode(['status' => 'error', 'message' => 'Failed to reset login attempts.']);
  exit;
}

if (!egmInviteesUploadWriteTextLocked($answersPath, '')) {
  echo json_encode(['status' => 'error', 'message' => 'Failed to reset answers table.']);
  exit;
}

if (!defined('EGMQ_INCLUDE_ONLY')) {
  define('EGMQ_INCLUDE_ONLY', true);
}
require_once __DIR__ . DIRECTORY_SEPARATOR . 'EGMQ.php';

if (!tcqEnsureInviteesColumns($filePath)) {
  echo json_encode(['status' => 'error', 'message' => 'Failed to prepare invitees columns.']);
  exit;
}
$questions = tcqLoadStore(__DIR__ . DIRECTORY_SEPARATOR . 'EGMQ list.json');
if (!tcqSyncAnswersSheet($answersPath, $questions, $questions)) {
  echo json_encode(['status' => 'error', 'message' => 'Failed to rebuild answers table.']);
  exit;
}

try {
  egmInstanceSyncMissionUsersUsingProjectConfig(dirname(__DIR__, 3), __DIR__, $filePath, $mapPath, true);
} catch (Throwable $error) {
  error_log('Failed to synchronize EGM invitees database table: ' . $error->getMessage());
  http_response_code(500);
  echo json_encode(['status' => 'error', 'message' => 'Invitees were saved to CSV, but failed to synchronize the EGM users database table.']);
  exit;
}

echo json_encode(['status' => 'ok']);

