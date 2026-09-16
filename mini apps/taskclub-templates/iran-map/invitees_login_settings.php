<?php
declare(strict_types=1);


require_once __DIR__ . '/tc-database-runtime.php';
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../api/lib/tab-permissions.php';
require_once __DIR__ . '/tc-security.php';
require_once __DIR__ . '/invitees_special_access.php';

$tcInviteesLoginSettingsSessionUser = requireTabPermissionFromSession('task-club', true);
if (!userHasPermissionId($tcInviteesLoginSettingsSessionUser, 'task-club:invitees')) {
  denyPanelAccess(403, 'You do not have permission to access this Task Club section.', true);
}
$tcInviteesLoginSettingsSpecialAccess = tcInviteesSpecialAccessForPanelUser($tcInviteesLoginSettingsSessionUser, __DIR__ . '/tasks/task-access.json');
if (empty($tcInviteesLoginSettingsSpecialAccess['manageInvitees'])) {
  denyPanelAccess(403, 'You do not have permission to manage invitees.', true);
}
tcSecurityGetCsrfToken();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  http_response_code(405);
  echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
  exit;
}

$input = json_decode((string)tcDbFileGetContents('php://input'), true);
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

$minLength = (int)($input['minLength'] ?? ($input['min_length'] ?? 3));
if ($minLength < 1 || $minLength > 128) {
  echo json_encode(['status' => 'error', 'message' => 'Min Length must be between 1 and 128.']);
  exit;
}

$settings = [
  'anyPassword' => !empty($input['anyPassword']) || !empty($input['any_password']),
  'minLength' => $minLength
];

$baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'TC Event';
if (!is_dir($baseDir) && !(mkdir($baseDir, 0777, true) || is_dir($baseDir))) {
  echo json_encode(['status' => 'error', 'message' => 'Failed to prepare settings directory.']);
  exit;
}

$path = $baseDir . DIRECTORY_SEPARATOR . 'any-password-login.json';
$json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
if (!is_string($json) || tcDbFilePutContents($path, $json . PHP_EOL, LOCK_EX) === false) {
  echo json_encode(['status' => 'error', 'message' => 'Failed to save settings.']);
  exit;
}

echo json_encode([
  'status' => 'ok',
  'message' => 'Settings saved.',
  'settings' => $settings
], JSON_UNESCAPED_UNICODE);
