<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../api/lib/tab-permissions.php';
require_once __DIR__ . '/tc-security.php';
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

$filePath = $baseDir . DIRECTORY_SEPARATOR . 'Invitees mapped.csv';
$mapPath = $baseDir . DIRECTORY_SEPARATOR . 'TC Mapped.json';
$answersPath = $baseDir . DIRECTORY_SEPARATOR . 'Answers.csv';
$loginAttemptsPath = $baseDir . DIRECTORY_SEPARATOR . 'login_attempts.json';

if (file_put_contents($filePath, $csv) === false) {
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

if (file_put_contents($mapPath, json_encode($mapPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
  echo json_encode(['status' => 'error', 'message' => 'Failed to save mapping.']);
  exit;
}

$emptyAttempts = json_encode(new stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
if ($emptyAttempts === false || file_put_contents($loginAttemptsPath, $emptyAttempts . PHP_EOL, LOCK_EX) === false) {
  echo json_encode(['status' => 'error', 'message' => 'Failed to reset login attempts.']);
  exit;
}

if (file_put_contents($answersPath, '', LOCK_EX) === false) {
  echo json_encode(['status' => 'error', 'message' => 'Failed to reset answers table.']);
  exit;
}

if (!defined('TCQ_INCLUDE_ONLY')) {
  define('TCQ_INCLUDE_ONLY', true);
}
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TCQ.php';

tcqEnsureInviteesColumns($filePath);
$questions = tcqLoadStore(__DIR__ . DIRECTORY_SEPARATOR . 'TCQ list.json');
if (!tcqSyncAnswersSheet($answersPath, $questions, $questions)) {
  echo json_encode(['status' => 'error', 'message' => 'Failed to rebuild answers table.']);
  exit;
}

echo json_encode(['status' => 'ok']);

