<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../api/lib/tab-permissions.php';
require_once __DIR__ . '/../../../api/lib/common.php';
require_once __DIR__ . '/../../../api/lib/users.php';
require_once __DIR__ . '/egm-security.php';
require_once __DIR__ . '/invitees_special_access.php';

$egmTaskAccessSessionUser = requireTabPermissionFromSession('event-guest-manager', true);
if (!userHasPermissionId($egmTaskAccessSessionUser, 'event-guest-manager:task-access')) {
  denyPanelAccess(403, 'You do not have permission to access this Event Guest Manager section.', true);
}
egmSecurityGetCsrfToken();

$tasksStorePath = __DIR__ . '/tasks/tasks.js';
$taskAccessPath = __DIR__ . '/tasks/task-access.json';

function egmTaskAccessReadJson(string $path, array $fallback = []): array
{
  if (!is_file($path)) {
    return $fallback;
  }
  $content = file_get_contents($path);
  if (!is_string($content) || trim($content) === '') {
    return $fallback;
  }
  $decoded = json_decode($content, true);
  return is_array($decoded) ? $decoded : $fallback;
}

function egmTaskAccessWriteJson(string $path, array $payload): bool
{
  $dir = dirname($path);
  if ($dir !== '' && !is_dir($dir) && !(mkdir($dir, 0777, true) || is_dir($dir))) {
    return false;
  }
  $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($encoded)) {
    return false;
  }
  return file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) !== false;
}

function egmTaskAccessNormalizeToken(string $value): string
{
  $token = trim($value);
  return $token === '' ? '' : strtolower($token);
}

function egmTaskAccessNormalizeBool($value): bool
{
  if (is_bool($value)) {
    return $value;
  }
  if (is_numeric($value)) {
    return ((int)$value) === 1;
  }
  $token = egmTaskAccessNormalizeToken((string)$value);
  return in_array($token, ['1', 'true', 'yes', 'on'], true);
}

function egmTaskAccessNormalizeTaskType(string $value): string
{
  $token = egmTaskAccessNormalizeToken($value);
  if (in_array($token, ['conditional_quiz', 'conditional-quiz', 'conditional quiz', 'conditional-quiz-task', 'conditional quiz task'], true)) {
    return 'conditional_quiz';
  }
  if (in_array($token, ['info', 'info-task', 'info task'], true)) {
    return 'info';
  }
  if (in_array($token, ['team_task', 'team-task', 'team task'], true)) {
    return 'team_task';
  }
  if (in_array($token, ['describe_photo', 'describe-photo', 'describe photo', 'describe-photo-task', 'describe photo task'], true)) {
    return 'describe_photo';
  }
  return 'quiz';
}

function egmTaskAccessResolvePaneKeys(string $taskType): array
{
  $type = egmTaskAccessNormalizeTaskType($taskType);
  if ($type === 'conditional_quiz') {
    return ['control', 'information', 'quiz', 'crisis-control'];
  }
  if ($type === 'quiz') {
    return ['control', 'information', 'quiz'];
  }
  if ($type === 'info') {
    return ['control', 'information', 'invitees-rate'];
  }
  if ($type === 'team_task') {
    return ['control', 'information', 'challenge-storage', 'team', 'invitees-rate'];
  }
  if ($type === 'describe_photo') {
    return ['control', 'information', 'photo', 'invitees-rate'];
  }
  return ['control', 'information', 'quiz'];
}

function egmTaskAccessLoadTasks(string $tasksStorePath): array
{
  if (!is_file($tasksStorePath)) {
    return [];
  }
  $content = file_get_contents($tasksStorePath);
  if (!is_string($content) || trim($content) === '') {
    return [];
  }
  $jsonPayload = '';
  if (preg_match('/window\.EGM_TASKS\s*=\s*(\[[\s\S]*\])\s*;?\s*$/', $content, $matches)) {
    $jsonPayload = (string)($matches[1] ?? '');
  } else {
    $start = strpos($content, '[');
    $end = strrpos($content, ']');
    if ($start !== false && $end !== false && $end >= $start) {
      $jsonPayload = substr($content, $start, $end - $start + 1);
    }
  }
  if ($jsonPayload === '') {
    return [];
  }
  $decoded = json_decode($jsonPayload, true);
  if (!is_array($decoded)) {
    return [];
  }
  $tasks = [];
  foreach ($decoded as $index => $item) {
    if (!is_array($item)) {
      continue;
    }
    $id = trim((string)($item['id'] ?? ''));
    $title = trim((string)($item['title'] ?? ''));
    $tagCode = trim((string)($item['tagCode'] ?? ''));
    if ($id === '' || $title === '' || $tagCode === '') {
      continue;
    }
    $order = (int)($item['order'] ?? ($index + 1));
    if ($order < 1) {
      $order = $index + 1;
    }
    $taskType = egmTaskAccessNormalizeTaskType((string)($item['taskType'] ?? 'quiz'));
    $tasks[] = [
      'id' => $id,
      'title' => $title,
      'tagCode' => $tagCode,
      'order' => $order,
      'taskType' => $taskType,
      'paneKeys' => egmTaskAccessResolvePaneKeys($taskType)
    ];
  }
  usort($tasks, static function (array $left, array $right): int {
    $leftOrder = (int)($left['order'] ?? 0);
    $rightOrder = (int)($right['order'] ?? 0);
    if ($leftOrder === $rightOrder) {
      return strcmp((string)($left['title'] ?? ''), (string)($right['title'] ?? ''));
    }
    return $leftOrder <=> $rightOrder;
  });
  return $tasks;
}

function egmTaskAccessLoadPanelUsers(): array
{
  $users = [];
  $config = loadConfig(__DIR__ . '/../../../api/config.php');
  $pdo = connectDatabase($config);
  if ($pdo instanceof PDO) {
    ensureUsersExtendedColumns($pdo);
    $users = loadUsersFromUsersTable($pdo);
  }
  if (!$users) {
    $store = egmTaskAccessReadJson(__DIR__ . '/../../../data/store.json', []);
    $storeUsers = is_array($store['users'] ?? null) ? $store['users'] : [];
    foreach ($storeUsers as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $code = trim((string)($entry['code'] ?? ''));
      if ($code === '') {
        continue;
      }
      $users[] = [
        'code' => $code,
        'username' => trim((string)($entry['username'] ?? '')),
        'fullname' => trim((string)($entry['fullname'] ?? $entry['name'] ?? '')),
        'permissions' => normalizeTabPermissions($entry['permissions'] ?? null, true)
      ];
    }
  }
  $normalized = [];
  foreach ($users as $entry) {
    if (!is_array($entry)) {
      continue;
    }
    $code = trim((string)($entry['code'] ?? ''));
    if ($code === '') {
      continue;
    }
    $permissions = normalizeTabPermissions($entry['permissions'] ?? null, true);
    if (!in_array('event-guest-manager', $permissions, true)) {
      continue;
    }
    $fullName = trim((string)($entry['fullname'] ?? $entry['name'] ?? ''));
    $username = trim((string)($entry['username'] ?? ''));
    $normalized[] = [
      'code' => $code,
      'username' => $username,
      'fullName' => $fullName !== '' ? $fullName : ($username !== '' ? $username : $code)
    ];
  }
  usort($normalized, static function (array $left, array $right): int {
    $nameDiff = strcmp((string)($left['fullName'] ?? ''), (string)($right['fullName'] ?? ''));
    if ($nameDiff !== 0) {
      return $nameDiff;
    }
    return strcmp((string)($left['code'] ?? ''), (string)($right['code'] ?? ''));
  });
  return $normalized;
}

function egmTaskAccessResolveManageTasksPermissionForUser(string $userCode): bool
{
  $normalizedCode = egmTaskAccessNormalizeToken($userCode);
  if ($normalizedCode === '') {
    return false;
  }

  $config = loadConfig(__DIR__ . '/../../../api/config.php');
  $pdo = connectDatabase($config);
  if ($pdo instanceof PDO) {
    ensureUsersExtendedColumns($pdo);
    $user = loadUserByCode($pdo, $userCode);
    if (is_array($user)) {
      return userHasPermissionId($user, 'event-guest-manager:manage-tasks');
    }
  }

  $store = egmTaskAccessReadJson(__DIR__ . '/../../../data/store.json', []);
  $storeUsers = is_array($store['users'] ?? null) ? $store['users'] : [];
  foreach ($storeUsers as $entry) {
    if (!is_array($entry)) {
      continue;
    }
    if (egmTaskAccessNormalizeToken((string)($entry['code'] ?? '')) !== $normalizedCode) {
      continue;
    }
    $permissions = normalizeTabPermissions($entry['permissions'] ?? null, true);
    return in_array('event-guest-manager:manage-tasks', $permissions, true);
  }

  return false;
}

function egmTaskAccessNormalizeRulesForTasks(array $rawRules, array $tasksByIdLower): array
{
  $rules = [];
  foreach ($rawRules as $taskId => $rule) {
    if (!is_array($rule)) {
      continue;
    }
    $taskToken = egmTaskAccessNormalizeToken((string)$taskId);
    if ($taskToken === '' || !isset($tasksByIdLower[$taskToken])) {
      continue;
    }
    $task = $tasksByIdLower[$taskToken];
    $actualTaskId = (string)($task['id'] ?? '');
    if ($actualTaskId === '') {
      continue;
    }
    $paneKeys = is_array($task['paneKeys'] ?? null) ? $task['paneKeys'] : [];
    $enabled = egmTaskAccessNormalizeBool($rule['enabled'] ?? true);
    $rawPanes = is_array($rule['panes'] ?? null) ? $rule['panes'] : [];
    $paneMap = [];
    foreach ($paneKeys as $paneKey) {
      $value = $rawPanes[$paneKey] ?? true;
      $paneMap[$paneKey] = egmTaskAccessNormalizeBool($value);
    }
    $rules[$actualTaskId] = [
      'enabled' => $enabled,
      'panes' => $paneMap
    ];
  }
  return $rules;
}

function egmTaskAccessNormalizeInviteesSpecialAccess($raw): array
{
  return egmInviteesSpecialAccessNormalize($raw);
}

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? 'bootstrap'));
$tasks = egmTaskAccessLoadTasks($tasksStorePath);
$tasksByIdLower = [];
foreach ($tasks as $task) {
  $token = egmTaskAccessNormalizeToken((string)($task['id'] ?? ''));
  if ($token !== '') {
    $tasksByIdLower[$token] = $task;
  }
}

if ($action === 'bootstrap') {
  $rawConfig = egmTaskAccessReadJson($taskAccessPath, []);
  $rawUsers = is_array($rawConfig['users'] ?? null) ? $rawConfig['users'] : [];
  $accessByUser = [];
  foreach ($rawUsers as $rawCode => $entry) {
    $userCode = egmTaskAccessNormalizeToken((string)$rawCode);
    if ($userCode === '' || !is_array($entry)) {
      continue;
    }
    $rules = egmTaskAccessNormalizeRulesForTasks(is_array($entry['tasks'] ?? null) ? $entry['tasks'] : [], $tasksByIdLower);
    $accessByUser[$userCode] = [
      'allowManageTasksTab' => egmTaskAccessNormalizeBool($entry['allowManageTasksTab'] ?? ($entry['allow_manage_tasks_tab'] ?? false)),
      'tasks' => $rules,
      'inviteesSpecialAccess' => egmTaskAccessNormalizeInviteesSpecialAccess($entry['inviteesSpecialAccess'] ?? null)
    ];
  }
  echo json_encode([
    'status' => 'ok',
    'data' => [
      'currentUserCode' => trim((string)($egmTaskAccessSessionUser['code'] ?? '')),
      'users' => egmTaskAccessLoadPanelUsers(),
      'tasks' => $tasks,
      'access' => $accessByUser
    ]
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'save_user_access') {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
  }
  $payload = json_decode((string)file_get_contents('php://input'), true);
  if (!is_array($payload)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload.']);
    exit;
  }
  $csrfToken = egmSecurityReadCsrfFromRequest($payload, 'csrf');
  if (!egmSecurityIsValidCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
    exit;
  }
  $userCodeRaw = trim((string)($payload['userCode'] ?? ''));
  $userCode = egmTaskAccessNormalizeToken($userCodeRaw);
  if ($userCodeRaw === '' || $userCode === '') {
    echo json_encode(['status' => 'error', 'message' => 'User code is required.']);
    exit;
  }
  $rawRules = is_array($payload['rules'] ?? null) ? $payload['rules'] : [];
  $allowManageTasksTab = egmTaskAccessNormalizeBool($payload['allowManageTasksTab'] ?? ($payload['allow_manage_tasks_tab'] ?? false));
  $normalizedRules = egmTaskAccessNormalizeRulesForTasks($rawRules, $tasksByIdLower);

  $config = egmTaskAccessReadJson($taskAccessPath, []);
  $users = is_array($config['users'] ?? null) ? $config['users'] : [];
  $existingEntry = is_array($users[$userCode] ?? null) ? $users[$userCode] : [];
  $inviteesSpecialAccess = egmTaskAccessNormalizeInviteesSpecialAccess($existingEntry['inviteesSpecialAccess'] ?? null);
  $users[$userCode] = [
    'updatedAt' => date('Y-m-d H:i:s'),
    'allowManageTasksTab' => $allowManageTasksTab,
    'tasks' => $normalizedRules,
    'inviteesSpecialAccess' => $inviteesSpecialAccess
  ];
  $config['users'] = $users;
  $config['updatedAt'] = date('Y-m-d H:i:s');
  if (!egmTaskAccessWriteJson($taskAccessPath, $config)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to save task access settings.']);
    exit;
  }
  echo json_encode([
    'status' => 'ok',
    'message' => 'Task access saved.',
    'data' => [
      'userCode' => $userCodeRaw,
      'allowManageTasksTab' => $allowManageTasksTab,
      'rules' => $normalizedRules,
      'inviteesSpecialAccess' => $inviteesSpecialAccess
    ]
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'save_user_special_access') {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
  }
  $payload = json_decode((string)file_get_contents('php://input'), true);
  if (!is_array($payload)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload.']);
    exit;
  }
  $csrfToken = egmSecurityReadCsrfFromRequest($payload, 'csrf');
  if (!egmSecurityIsValidCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
    exit;
  }
  $userCodeRaw = trim((string)($payload['userCode'] ?? ''));
  $userCode = egmTaskAccessNormalizeToken($userCodeRaw);
  if ($userCodeRaw === '' || $userCode === '') {
    echo json_encode(['status' => 'error', 'message' => 'User code is required.']);
    exit;
  }
  $specialAccess = egmTaskAccessNormalizeInviteesSpecialAccess($payload['inviteesSpecialAccess'] ?? null);

  $config = egmTaskAccessReadJson($taskAccessPath, []);
  $users = is_array($config['users'] ?? null) ? $config['users'] : [];
  $existingEntry = is_array($users[$userCode] ?? null) ? $users[$userCode] : [];
  $existingRules = egmTaskAccessNormalizeRulesForTasks(is_array($existingEntry['tasks'] ?? null) ? $existingEntry['tasks'] : [], $tasksByIdLower);
  if (array_key_exists('allowManageTasksTab', $existingEntry) || array_key_exists('allow_manage_tasks_tab', $existingEntry)) {
    $allowManageTasksTab = egmTaskAccessNormalizeBool($existingEntry['allowManageTasksTab'] ?? ($existingEntry['allow_manage_tasks_tab'] ?? false));
  } else {
    $allowManageTasksTab = egmTaskAccessResolveManageTasksPermissionForUser($userCodeRaw);
  }
  $users[$userCode] = [
    'updatedAt' => date('Y-m-d H:i:s'),
    'allowManageTasksTab' => $allowManageTasksTab,
    'tasks' => $existingRules,
    'inviteesSpecialAccess' => $specialAccess
  ];
  $config['users'] = $users;
  $config['updatedAt'] = date('Y-m-d H:i:s');
  if (!egmTaskAccessWriteJson($taskAccessPath, $config)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to save special access settings.']);
    exit;
  }

  echo json_encode([
    'status' => 'ok',
    'message' => 'Special access saved.',
    'data' => [
      'userCode' => $userCodeRaw,
      'inviteesSpecialAccess' => $specialAccess
    ]
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
