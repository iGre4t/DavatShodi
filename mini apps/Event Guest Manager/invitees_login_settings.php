<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../api/lib/tab-permissions.php';
require_once __DIR__ . '/egm-security.php';
require_once __DIR__ . '/invitees_special_access.php';

$egmInviteesLoginSettingsSessionUser = requireTabPermissionFromSession('event-guest-manager', true);
if (!userHasPermissionId($egmInviteesLoginSettingsSessionUser, 'event-guest-manager:invitees')) {
  denyPanelAccess(403, 'You do not have permission to access this Event Guest Manager section.', true);
}
$egmInviteesLoginSettingsSpecialAccess = egmInviteesSpecialAccessForPanelUser($egmInviteesLoginSettingsSessionUser, __DIR__ . '/tasks/task-access.json');
if (empty($egmInviteesLoginSettingsSpecialAccess['manageInvitees'])) {
  denyPanelAccess(403, 'You do not have permission to manage invitees.', true);
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

$minLength = (int)($input['minLength'] ?? ($input['min_length'] ?? 3));
if ($minLength < 1 || $minLength > 128) {
  echo json_encode(['status' => 'error', 'message' => 'Min Length must be between 1 and 128.']);
  exit;
}

$settings = [
  'anyPassword' => !empty($input['anyPassword']) || !empty($input['any_password']),
  'minLength' => $minLength
];

$baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'EGM Event';
if (!is_dir($baseDir) && !(mkdir($baseDir, 0777, true) || is_dir($baseDir))) {
  echo json_encode(['status' => 'error', 'message' => 'Failed to prepare settings directory.']);
  exit;
}

$path = $baseDir . DIRECTORY_SEPARATOR . 'any-password-login.json';
$json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
if (!is_string($json) || file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
  echo json_encode(['status' => 'error', 'message' => 'Failed to save settings.']);
  exit;
}

echo json_encode([
  'status' => 'ok',
  'message' => 'Settings saved.',
  'settings' => $settings
], JSON_UNESCAPED_UNICODE);
