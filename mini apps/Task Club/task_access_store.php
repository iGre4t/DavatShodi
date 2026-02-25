<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../api/lib/tab-permissions.php';
require_once __DIR__ . '/../../api/lib/common.php';
require_once __DIR__ . '/../../api/lib/users.php';
require_once __DIR__ . '/tc-security.php';

$tcTaskAccessSessionUser = requireTabPermissionFromSession('task-club', true);
if (!userHasPermissionId($tcTaskAccessSessionUser, 'task-club:task-access')) {
  denyPanelAccess(403, 'You do not have permission to access this Task Club section.', true);
}
tcSecurityGetCsrfToken();

$tasksStorePath = __DIR__ . '/tasks/tasks.js';
$taskAccessPath = __DIR__ . '/tasks/task-access.json';

function tcTaskAccessReadJson(string $path, array $fallback = []): array
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

function tcTaskAccessWriteJson(string $path, array $payload): bool
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

function tcTaskAccessNormalizeToken(string $value): string
{
  $token = trim($value);
  return $token === '' ? '' : strtolower($token);
}

function tcTaskAccessNormalizeBool($value): bool
{
  if (is_bool($value)) {
    return $value;
  }
  if (is_numeric($value)) {
    return ((int)$value) === 1;
  }
  $token = tcTaskAccessNormalizeToken((string)$value);
  return in_array($token, ['1', 'true', 'yes', 'on'], true);
}

function tcTaskAccessNormalizeTaskType(string $value): string
{
  $token = tcTaskAccessNormalizeToken($value);
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

function tcTaskAccessResolvePaneKeys(string $taskType): array
{
  $type = tcTaskAccessNormalizeTaskType($taskType);
  if ($type === 'info') {
    return ['control', 'information', 'invitees-rate'];
  }
  if ($type === 'team_task') {
    return ['control', 'information', 'challenge-storage', 'team', 'invitees-rate'];
  }
  if ($type === 'describe_photo') {
    return ['control', 'information', 'photo', 'invitees-rate'];
  }
  return ['control', 'quiz'];
}

function tcTaskAccessLoadTasks(string $tasksStorePath): array
{
  if (!is_file($tasksStorePath)) {
    return [];
  }
  $content = file_get_contents($tasksStorePath);
  if (!is_string($content) || trim($content) === '') {
    return [];
  }
  $jsonPayload = '';
  if (preg_match('/window\.TC_TASKS\s*=\s*(\[[\s\S]*\])\s*;?\s*$/', $content, $matches)) {
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
    $taskType = tcTaskAccessNormalizeTaskType((string)($item['taskType'] ?? 'quiz'));
    $tasks[] = [
      'id' => $id,
      'title' => $title,
      'tagCode' => $tagCode,
      'order' => $order,
      'taskType' => $taskType,
      'paneKeys' => tcTaskAccessResolvePaneKeys($taskType)
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

function tcTaskAccessLoadPanelUsers(): array
{
  $users = [];
  $config = loadConfig(__DIR__ . '/../../api/config.php');
  $pdo = connectDatabase($config);
  if ($pdo instanceof PDO) {
    ensureUsersExtendedColumns($pdo);
    $users = loadUsersFromUsersTable($pdo);
  }
  if (!$users) {
    $store = tcTaskAccessReadJson(__DIR__ . '/../../data/store.json', []);
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
        'fullname' => trim((string)($entry['fullname'] ?? $entry['name'] ?? ''))
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

function tcTaskAccessNormalizeRulesForTasks(array $rawRules, array $tasksByIdLower): array
{
  $rules = [];
  foreach ($rawRules as $taskId => $rule) {
    if (!is_array($rule)) {
      continue;
    }
    $taskToken = tcTaskAccessNormalizeToken((string)$taskId);
    if ($taskToken === '' || !isset($tasksByIdLower[$taskToken])) {
      continue;
    }
    $task = $tasksByIdLower[$taskToken];
    $actualTaskId = (string)($task['id'] ?? '');
    if ($actualTaskId === '') {
      continue;
    }
    $paneKeys = is_array($task['paneKeys'] ?? null) ? $task['paneKeys'] : [];
    $enabled = tcTaskAccessNormalizeBool($rule['enabled'] ?? true);
    $rawPanes = is_array($rule['panes'] ?? null) ? $rule['panes'] : [];
    $paneMap = [];
    foreach ($paneKeys as $paneKey) {
      $value = $rawPanes[$paneKey] ?? true;
      $paneMap[$paneKey] = tcTaskAccessNormalizeBool($value);
    }
    $rules[$actualTaskId] = [
      'enabled' => $enabled,
      'panes' => $paneMap
    ];
  }
  return $rules;
}

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? 'bootstrap'));
$tasks = tcTaskAccessLoadTasks($tasksStorePath);
$tasksByIdLower = [];
foreach ($tasks as $task) {
  $token = tcTaskAccessNormalizeToken((string)($task['id'] ?? ''));
  if ($token !== '') {
    $tasksByIdLower[$token] = $task;
  }
}

if ($action === 'bootstrap') {
  $rawConfig = tcTaskAccessReadJson($taskAccessPath, []);
  $rawUsers = is_array($rawConfig['users'] ?? null) ? $rawConfig['users'] : [];
  $accessByUser = [];
  foreach ($rawUsers as $rawCode => $entry) {
    $userCode = tcTaskAccessNormalizeToken((string)$rawCode);
    if ($userCode === '' || !is_array($entry)) {
      continue;
    }
    $rules = tcTaskAccessNormalizeRulesForTasks(is_array($entry['tasks'] ?? null) ? $entry['tasks'] : [], $tasksByIdLower);
    $accessByUser[$userCode] = [
      'allowManageTasksTab' => tcTaskAccessNormalizeBool($entry['allowManageTasksTab'] ?? ($entry['allow_manage_tasks_tab'] ?? false)),
      'tasks' => $rules
    ];
  }
  echo json_encode([
    'status' => 'ok',
    'data' => [
      'currentUserCode' => trim((string)($tcTaskAccessSessionUser['code'] ?? '')),
      'users' => tcTaskAccessLoadPanelUsers(),
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
  $csrfToken = tcSecurityReadCsrfFromRequest($payload, 'csrf');
  if (!tcSecurityIsValidCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
    exit;
  }
  $userCodeRaw = trim((string)($payload['userCode'] ?? ''));
  $userCode = tcTaskAccessNormalizeToken($userCodeRaw);
  if ($userCodeRaw === '' || $userCode === '') {
    echo json_encode(['status' => 'error', 'message' => 'User code is required.']);
    exit;
  }
  $rawRules = is_array($payload['rules'] ?? null) ? $payload['rules'] : [];
  $allowManageTasksTab = tcTaskAccessNormalizeBool($payload['allowManageTasksTab'] ?? ($payload['allow_manage_tasks_tab'] ?? false));
  $normalizedRules = tcTaskAccessNormalizeRulesForTasks($rawRules, $tasksByIdLower);

  $config = tcTaskAccessReadJson($taskAccessPath, []);
  $users = is_array($config['users'] ?? null) ? $config['users'] : [];
  $users[$userCode] = [
    'updatedAt' => date('Y-m-d H:i:s'),
    'allowManageTasksTab' => $allowManageTasksTab,
    'tasks' => $normalizedRules
  ];
  $config['users'] = $users;
  $config['updatedAt'] = date('Y-m-d H:i:s');
  if (!tcTaskAccessWriteJson($taskAccessPath, $config)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to save task access settings.']);
    exit;
  }
  echo json_encode([
    'status' => 'ok',
    'message' => 'Task access saved.',
    'data' => [
      'userCode' => $userCodeRaw,
      'allowManageTasksTab' => $allowManageTasksTab,
      'rules' => $normalizedRules
    ]
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
