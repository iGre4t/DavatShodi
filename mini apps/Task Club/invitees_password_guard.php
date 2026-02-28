<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../api/lib/tab-permissions.php';
require_once __DIR__ . '/../../api/lib/common.php';
require_once __DIR__ . '/../../api/lib/users.php';
require_once __DIR__ . '/tc-security.php';
require_once __DIR__ . '/invitees_special_access.php';

$tcInviteesPasswordSessionUser = requireTabPermissionFromSession('task-club', true);
if (!userHasPermissionId($tcInviteesPasswordSessionUser, 'task-club:invitees')) {
  denyPanelAccess(403, 'You do not have permission to access this Task Club section.', true);
}
tcSecurityGetCsrfToken();

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

$csrfToken = tcSecurityReadCsrfFromRequest($input, 'csrf');
if (!tcSecurityIsValidCsrfToken($csrfToken)) {
  http_response_code(403);
  echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
  exit;
}

function inviteePasswordNormalizeToken(string $value): string
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

function inviteePasswordNormalizeHeaderToken(string $value): string
{
  return str_replace(['-', '_'], ' ', inviteePasswordNormalizeToken($value));
}

function inviteePasswordFindHeaderIndexByNames(array $header, array $names): int
{
  $targets = [];
  foreach ($names as $name) {
    if (!is_scalar($name)) {
      continue;
    }
    $token = inviteePasswordNormalizeHeaderToken((string)$name);
    if ($token !== '') {
      $targets[$token] = true;
    }
  }
  if (!$targets) {
    return -1;
  }
  foreach ($header as $index => $value) {
    $token = inviteePasswordNormalizeHeaderToken((string)$value);
    if ($token !== '' && isset($targets[$token])) {
      return (int)$index;
    }
  }
  return -1;
}

function inviteePasswordReadCsvRows(string $path): array
{
  if (!is_file($path)) {
    return [];
  }
  $handle = fopen($path, 'r');
  if ($handle === false) {
    return [];
  }
  $rows = [];
  while (($row = fgetcsv($handle)) !== false) {
    $rows[] = is_array($row) ? $row : [];
  }
  fclose($handle);
  return $rows;
}

function inviteePasswordWriteCsvRowsLocked(string $path, array $rows): bool
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

function inviteePasswordNormalizeCsvRows(array $rows): array
{
  if (!$rows || !is_array($rows[0] ?? null)) {
    return [];
  }
  $width = count($rows[0]);
  if ($width <= 0) {
    return [];
  }
  $normalized = [];
  foreach ($rows as $row) {
    $current = is_array($row) ? $row : [];
    if (count($current) < $width) {
      $current = array_pad($current, $width, '');
    } elseif (count($current) > $width) {
      $current = array_slice($current, 0, $width);
    }
    $normalized[] = $current;
  }
  return $normalized;
}

function inviteePasswordSessionKeyForUser(string $userCode): string
{
  return inviteePasswordNormalizeToken($userCode);
}

function inviteePasswordIsUnlocked(string $userCode): bool
{
  $sessionKey = inviteePasswordSessionKeyForUser($userCode);
  if ($sessionKey === '') {
    return false;
  }
  $all = is_array($_SESSION['tc_invite_password_unlock'] ?? null) ? $_SESSION['tc_invite_password_unlock'] : [];
  $until = (int)($all[$sessionKey] ?? 0);
  return $until > time();
}

function inviteePasswordSetUnlockWindow(string $userCode, int $seconds): int
{
  $sessionKey = inviteePasswordSessionKeyForUser($userCode);
  if ($sessionKey === '') {
    return 0;
  }
  $until = time() + max(1, $seconds);
  $all = is_array($_SESSION['tc_invite_password_unlock'] ?? null) ? $_SESSION['tc_invite_password_unlock'] : [];
  $all[$sessionKey] = $until;
  $_SESSION['tc_invite_password_unlock'] = $all;
  return $until;
}

$action = trim((string)($input['action'] ?? ''));
$sessionUserCode = trim((string)($tcInviteesPasswordSessionUser['code'] ?? ''));
if ($sessionUserCode === '') {
  echo json_encode(['status' => 'error', 'message' => 'Session user is not valid.']);
  exit;
}
$tcInviteesSensitiveAccess = tcInviteesSpecialAccessForPanelUser($tcInviteesPasswordSessionUser, __DIR__ . '/tasks/task-access.json');
$canRevealPassword = !empty($tcInviteesSensitiveAccess['revealPassword']);
$canResetInvitee = !empty($tcInviteesSensitiveAccess['resetInvitee']);
$canUseSensitiveAuth = $canRevealPassword || $canResetInvitee;
$isRevealAction = in_array($action, ['get_password', 'save_password'], true);
$isResetAction = ($action === 'reset_progress');
$isAuthAction = in_array($action, ['verify_unlock', 'check_unlock'], true);

if ($isRevealAction && !$canRevealPassword) {
  denyPanelAccess(403, 'You do not have permission to reveal invitee passwords.', true);
}
if ($isResetAction && !$canResetInvitee) {
  denyPanelAccess(403, 'You do not have permission to reset invitees.', true);
}
if ($isAuthAction && !$canUseSensitiveAuth) {
  denyPanelAccess(403, 'You do not have permission to run sensitive invitee actions.', true);
}

$baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'TC Event';
$mappedFile = $baseDir . DIRECTORY_SEPARATOR . 'Invitees mapped.csv';

if ($action === 'verify_unlock') {
  $password = (string)($input['password'] ?? '');
  if (trim($password) === '') {
    echo json_encode(['status' => 'error', 'message' => 'Password is required.']);
    exit;
  }
  $config = loadConfig(__DIR__ . '/../../api/config.php');
  $pdo = connectDatabase($config);
  if (!$pdo instanceof PDO) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection is not available.']);
    exit;
  }
  ensureUsersExtendedColumns($pdo);
  $userRow = loadUserByCode($pdo, $sessionUserCode);
  if (!is_array($userRow)) {
    echo json_encode(['status' => 'error', 'message' => 'User account was not found.']);
    exit;
  }
  $passwordHash = trim((string)($userRow['password_hash'] ?? ''));
  if ($passwordHash === '' || !password_verify($password, $passwordHash)) {
    echo json_encode(['status' => 'error', 'message' => 'Incorrect panel password.']);
    exit;
  }
  $unlockUntil = inviteePasswordSetUnlockWindow($sessionUserCode, 300);
  echo json_encode([
    'status' => 'ok',
    'message' => 'Password reveal unlocked for 5 minutes.',
    'unlockUntil' => $unlockUntil
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'check_unlock') {
  if (inviteePasswordIsUnlocked($sessionUserCode)) {
    echo json_encode(['status' => 'ok', 'message' => 'Unlocked.'], JSON_UNESCAPED_UNICODE);
  } else {
    echo json_encode([
      'status' => 'auth_required',
      'message' => 'Please verify your panel password for sensitive invitee actions.'
    ], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

if (!inviteePasswordIsUnlocked($sessionUserCode)) {
  echo json_encode([
    'status' => 'auth_required',
    'message' => 'Please verify your panel password for sensitive invitee actions.'
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

$rows = inviteePasswordNormalizeCsvRows(inviteePasswordReadCsvRows($mappedFile));
if (!$rows) {
  echo json_encode(['status' => 'error', 'message' => 'Invitees mapped CSV is not ready.']);
  exit;
}

$header = is_array($rows[0] ?? null) ? $rows[0] : [];

$rowNumber = (int)($input['row'] ?? 0);
$rowIndex = $rowNumber - 1;
if ($rowIndex < 1 || $rowIndex >= count($rows)) {
  echo json_encode(['status' => 'error', 'message' => 'Invitee row was not found.']);
  exit;
}

if ($action === 'get_password') {
  $passwordIndex = inviteePasswordFindHeaderIndexByNames($header, ['password']);
  if ($passwordIndex < 0) {
    echo json_encode(['status' => 'error', 'message' => 'Password column is not available in mapped CSV.']);
    exit;
  }
  echo json_encode([
    'status' => 'ok',
    'password' => (string)($rows[$rowIndex][$passwordIndex] ?? '')
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'save_password') {
  $passwordIndex = inviteePasswordFindHeaderIndexByNames($header, ['password']);
  if ($passwordIndex < 0) {
    echo json_encode(['status' => 'error', 'message' => 'Password column is not available in mapped CSV.']);
    exit;
  }
  $newPassword = trim((string)($input['new_password'] ?? ''));
  if ($newPassword === '') {
    echo json_encode(['status' => 'error', 'message' => 'New password cannot be empty.']);
    exit;
  }
  $rows[$rowIndex][$passwordIndex] = $newPassword;
  if (!inviteePasswordWriteCsvRowsLocked($mappedFile, $rows)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to save password in mapped CSV.']);
    exit;
  }
  echo json_encode(['status' => 'ok', 'message' => 'Password updated successfully.'], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'reset_progress') {
  $resetColumns = [
    [['logins counts'], '0'],
    [['logins'], ''],
    [['count of rolls'], '0'],
    [['invitees'], ''],
    [['prize won'], ''],
    [['prize won at'], ''],
    [['answers'], ''],
    [['score'], '0'],
    [['answered'], ''],
    [['task completed'], ''],
    [['task score map'], ''],
    [['info tasks'], ''],
    [['describe photo task'], ''],
    [['team task'], ''],
    [['card flips count'], '0'],
    [['each level won prize'], ''],
    [['total prize won', 'مجموع جوایز برنده شده'], '0'],
    [['out of value rewards'], '']
  ];
  foreach ($resetColumns as [$names, $value]) {
    $index = inviteePasswordFindHeaderIndexByNames($header, is_array($names) ? $names : []);
    if ($index < 0) {
      continue;
    }
    $rows[$rowIndex][$index] = (string)$value;
  }
  if (!inviteePasswordWriteCsvRowsLocked($mappedFile, $rows)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to reset invitee progress in mapped CSV.']);
    exit;
  }
  echo json_encode(['status' => 'ok', 'message' => 'Invitee progress reset successfully.'], JSON_UNESCAPED_UNICODE);
  exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
