<?php
session_start();

$sessionUser = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];
$userId = trim((string)($sessionUser['code'] ?? $sessionUser['username'] ?? ''));

require_once __DIR__ . '/api/lib/common.php';
require_once __DIR__ . '/api/lib/activity-logger.php';

$pdo = null;
$configFile = __DIR__ . '/api/config.php';
if (is_file($configFile)) {
  $pdo = connectDatabase(loadConfig($configFile));
}

panelLogUserActivity([
  'level' => 'info',
  'user_id' => $userId !== '' ? $userId : null,
  'action' => 'user.logout',
  'entity_type' => 'user',
  'entity_id' => $userId !== '' ? $userId : null,
  'status' => 'success',
  'message' => 'User logged out.',
  'metadata' => [
    'username' => $sessionUser['username'] ?? null
  ],
  'audit' => true
], $pdo);

// remove all session data
$_SESSION = [];

// remove session cookie if it exists
if (ini_get('session.use_cookies')) {
  $params = session_get_cookie_params();
  setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

session_destroy();

header('Location: login.php');
exit;
?>
