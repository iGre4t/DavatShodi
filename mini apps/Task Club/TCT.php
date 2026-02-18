<?php
declare(strict_types=1);

require_once __DIR__ . '/../../api/lib/tab-permissions.php';
$tctIsJsonRequest = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') && isset($_POST['tct_action']);
requireTabPermissionFromSession('task-club', $tctIsJsonRequest);

$tctTasksDir = __DIR__ . '/tasks';
$tctStorePath = $tctTasksDir . '/tasks.js';
const TCT_SCORE_SETTINGS_FILE = 'task-score.json';

function tctNormalizeTaskType(string $value): string
{
  $token = strtolower(trim($value));
  if (in_array($token, ['quiz', 'quiz-task', 'quiz task'], true)) {
    return 'quiz';
  }
  return 'quiz';
}

function tctNormalizeBoolValue($value): bool
{
  if (is_bool($value)) {
    return $value;
  }
  if (is_int($value) || is_float($value)) {
    return ((int)$value) === 1;
  }
  $token = strtolower(trim((string)$value));
  return in_array($token, ['1', 'true', 'on', 'yes'], true);
}

function tctNormalizeDateValue(string $value): string
{
  $trimmed = trim($value);
  if ($trimmed === '') {
    return '';
  }
  return preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) ? $trimmed : '';
}

function tctNormalizeTimeValue(string $value): string
{
  $trimmed = trim($value);
  if ($trimmed === '') {
    return '';
  }
  if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $trimmed, $m)) {
    return ($m[1] ?? '00') . ':' . ($m[2] ?? '00');
  }
  return '';
}

function tctEnsureTasksStorage(string $tasksDir, string $storePath): bool
{
  if (!is_dir($tasksDir) && !mkdir($tasksDir, 0777, true) && !is_dir($tasksDir)) {
    return false;
  }
  if (!is_file($storePath)) {
    return file_put_contents($storePath, "window.TC_TASKS = [];\n", LOCK_EX) !== false;
  }
  return true;
}

function tctMakeTaskId(): string
{
  try {
    return 't_' . bin2hex(random_bytes(6));
  } catch (Throwable $e) {
    return 't_' . str_replace('.', '', uniqid('', true));
  }
}

function tctNormalizeTagCode(string $value): string
{
  $upper = strtoupper(trim($value));
  $clean = preg_replace('/[^A-Z0-9_-]+/', '', $upper);
  return is_string($clean) ? $clean : '';
}

function tctGenerateNextTagCode(array $tasks): string
{
  $maxNumber = 0;
  $used = [];
  foreach ($tasks as $task) {
    if (!is_array($task)) {
      continue;
    }
    $tagCode = tctNormalizeTagCode((string)($task['tagCode'] ?? ''));
    if ($tagCode === '') {
      continue;
    }
    $used[strtolower($tagCode)] = true;
    if (preg_match('/^\d+$/', $tagCode)) {
      $num = (int)$tagCode;
      if ($num > $maxNumber) {
        $maxNumber = $num;
      }
    }
  }

  $next = max(1, $maxNumber + 1);
  while (true) {
    $candidate = str_pad((string)$next, 3, '0', STR_PAD_LEFT);
    if (!isset($used[strtolower($candidate)])) {
      return $candidate;
    }
    $next += 1;
  }
}

function tctNormalizeScoreValue($value): int
{
  if (!is_scalar($value)) {
    return 0;
  }
  $token = trim((string)$value);
  if ($token === '' || !is_numeric($token)) {
    return 0;
  }
  $number = (int)floor((float)$token);
  return $number > 0 ? $number : 0;
}

function tctBuildTaskDirPath(string $tasksDir, string $tagCode): string
{
  $normalizedTagCode = tctNormalizeTagCode($tagCode);
  if ($normalizedTagCode === '') {
    return '';
  }
  return $tasksDir . DIRECTORY_SEPARATOR . $normalizedTagCode;
}

function tctBuildTaskScoreSettingsPath(string $tasksDir, string $tagCode): string
{
  $taskDir = tctBuildTaskDirPath($tasksDir, $tagCode);
  if ($taskDir === '') {
    return '';
  }
  return $taskDir . DIRECTORY_SEPARATOR . TCT_SCORE_SETTINGS_FILE;
}

function tctLoadTaskScoreSettings(string $tasksDir, string $tagCode): array
{
  $defaults = [
    'score' => 0,
    'afterEndtimeScore' => 0
  ];
  $path = tctBuildTaskScoreSettingsPath($tasksDir, $tagCode);
  if ($path === '' || !is_file($path)) {
    return $defaults;
  }
  $content = file_get_contents($path);
  if ($content === false) {
    return $defaults;
  }
  $decoded = json_decode($content, true);
  if (!is_array($decoded)) {
    return $defaults;
  }
  return [
    'score' => tctNormalizeScoreValue($decoded['score'] ?? 0),
    'afterEndtimeScore' => tctNormalizeScoreValue($decoded['afterEndtimeScore'] ?? ($decoded['after_endtime_score'] ?? 0))
  ];
}

function tctSaveTaskScoreSettings(string $tasksDir, string $tagCode, array $settings): bool
{
  if (!tctEnsureTaskFolder($tasksDir, $tagCode)) {
    return false;
  }
  $path = tctBuildTaskScoreSettingsPath($tasksDir, $tagCode);
  if ($path === '') {
    return false;
  }
  $payload = [
    'score' => tctNormalizeScoreValue($settings['score'] ?? 0),
    'afterEndtimeScore' => tctNormalizeScoreValue($settings['afterEndtimeScore'] ?? ($settings['after_endtime_score'] ?? 0))
  ];
  $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($json === false) {
    return false;
  }
  return file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

function tctMergeTaskScores(array $tasks, string $tasksDir): array
{
  $merged = [];
  foreach ($tasks as $task) {
    if (!is_array($task)) {
      continue;
    }
    $tagCode = (string)($task['tagCode'] ?? '');
    $scoreSettings = tctLoadTaskScoreSettings($tasksDir, $tagCode);
    $task['score'] = $scoreSettings['score'];
    $task['afterEndtimeScore'] = $scoreSettings['afterEndtimeScore'];
    $merged[] = $task;
  }
  return $merged;
}

function tctNormalizeTask(array $task, int $fallbackOrder): array
{
  $id = trim((string)($task['id'] ?? ''));
  if ($id === '') {
    $id = tctMakeTaskId();
  }
  $title = trim((string)($task['title'] ?? ''));
  $tagCode = tctNormalizeTagCode((string)($task['tagCode'] ?? ($task['tag_code'] ?? '')));
  $taskType = tctNormalizeTaskType((string)($task['taskType'] ?? ($task['task_type'] ?? 'quiz')));
  $active = tctNormalizeBoolValue($task['active'] ?? false);
  $duration = tctNormalizeBoolValue($task['duration'] ?? false);
  $startDate = tctNormalizeDateValue((string)($task['startDate'] ?? ($task['start_date'] ?? '')));
  $startTime = tctNormalizeTimeValue((string)($task['startTime'] ?? ($task['start_time'] ?? '')));
  $endDate = tctNormalizeDateValue((string)($task['endDate'] ?? ($task['end_date'] ?? '')));
  $endTime = tctNormalizeTimeValue((string)($task['endTime'] ?? ($task['end_time'] ?? '')));
  $order = (int)($task['order'] ?? $fallbackOrder);
  if ($order < 1) {
    $order = $fallbackOrder;
  }
  $createdAt = trim((string)($task['createdAt'] ?? ''));
  if ($createdAt === '') {
    $createdAt = date('Y-m-d H:i:s');
  }
  return [
    'id' => $id,
    'title' => $title,
    'tagCode' => $tagCode,
    'taskType' => $taskType,
    'active' => $active,
    'duration' => $duration,
    'startDate' => $startDate,
    'startTime' => $startTime,
    'endDate' => $endDate,
    'endTime' => $endTime,
    'order' => $order,
    'createdAt' => $createdAt
  ];
}

function tctReadStoreTasks(string $storePath): array
{
  if (!is_file($storePath)) {
    return [];
  }
  $content = file_get_contents($storePath);
  if ($content === false) {
    return [];
  }

  $jsonPayload = '';
  if (preg_match('/window\.TC_TASKS\s*=\s*(\[[\s\S]*\])\s*;?\s*$/', $content, $m)) {
    $jsonPayload = (string)($m[1] ?? '');
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
  return is_array($decoded) ? $decoded : [];
}

function tctReindexTasks(array $tasks): array
{
  $normalized = [];
  foreach ($tasks as $index => $row) {
    if (!is_array($row)) {
      continue;
    }
    $normalized[] = tctNormalizeTask($row, $index + 1);
  }
  usort($normalized, static function (array $a, array $b): int {
    $left = (int)($a['order'] ?? 0);
    $right = (int)($b['order'] ?? 0);
    if ($left === $right) {
      return strcmp((string)($a['createdAt'] ?? ''), (string)($b['createdAt'] ?? ''));
    }
    return $left <=> $right;
  });

  $byTagCode = [];
  $byId = [];
  $result = [];
  $order = 1;
  foreach ($normalized as $task) {
    $id = (string)($task['id'] ?? '');
    $title = trim((string)($task['title'] ?? ''));
    $tagCode = tctNormalizeTagCode((string)($task['tagCode'] ?? ''));
    if ($id === '' || $title === '' || $tagCode === '') {
      continue;
    }
    $idKey = strtolower($id);
    $tagKey = strtolower($tagCode);
    if (isset($byId[$idKey]) || isset($byTagCode[$tagKey])) {
      continue;
    }
    $task['id'] = $id;
    $task['title'] = $title;
    $task['tagCode'] = $tagCode;
    $task['taskType'] = tctNormalizeTaskType((string)($task['taskType'] ?? 'quiz'));
    $task['order'] = $order;
    $result[] = $task;
    $byId[$idKey] = true;
    $byTagCode[$tagKey] = true;
    $order += 1;
  }
  return $result;
}

function tctSaveStoreTasks(string $storePath, array $tasks): bool
{
  $json = json_encode(array_values($tasks), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    return false;
  }
  $payload = "window.TC_TASKS = {$json};\n";
  return file_put_contents($storePath, $payload, LOCK_EX) !== false;
}

function tctReadCsvRows(string $path): array
{
  if (!is_file($path)) {
    return [];
  }
  $rows = [];
  $handle = fopen($path, 'r');
  if ($handle === false) {
    return [];
  }
  while (($row = fgetcsv($handle)) !== false) {
    $rows[] = $row;
  }
  fclose($handle);
  return $rows;
}

function tctWriteCsvRows(string $path, array $rows): bool
{
  $dir = dirname($path);
  if (!is_dir($dir) && !(mkdir($dir, 0777, true) || is_dir($dir))) {
    return false;
  }
  $handle = fopen($path, 'c+');
  if ($handle === false) {
    return false;
  }
  if (!flock($handle, LOCK_EX)) {
    fclose($handle);
    return false;
  }
  ftruncate($handle, 0);
  rewind($handle);
  foreach ($rows as $row) {
    fputcsv($handle, is_array($row) ? $row : []);
  }
  fflush($handle);
  flock($handle, LOCK_UN);
  fclose($handle);
  return true;
}

function tctNormalizeHeaderName(string $value): string
{
  $normalized = strtolower(trim($value));
  $normalized = preg_replace('/\s+/', ' ', $normalized);
  return is_string($normalized) ? $normalized : '';
}

function tctFindHeaderIndex(array $header, string $name): int
{
  $needle = tctNormalizeHeaderName($name);
  foreach ($header as $index => $value) {
    if (tctNormalizeHeaderName((string)$value) === $needle) {
      return (int)$index;
    }
  }
  return -1;
}

function tctEnsureInviteesMappedColumns(string $filePath): bool
{
  $required = ['Work ID', 'count of rolls', 'invitees', 'prize won', 'answers', 'score', 'Answered'];
  $rows = tctReadCsvRows($filePath);
  if (!$rows || !isset($rows[0]) || !is_array($rows[0])) {
    return tctWriteCsvRows($filePath, [$required]);
  }

  $header = $rows[0];
  $changed = false;
  foreach ($required as $columnName) {
    $existingIndex = tctFindHeaderIndex($header, $columnName);
    if ($existingIndex >= 0) {
      continue;
    }
    $header[] = $columnName;
    $newIndex = count($header) - 1;
    for ($i = 1; $i < count($rows); $i += 1) {
      if (!is_array($rows[$i])) {
        $rows[$i] = [];
      }
      $rows[$i][$newIndex] = '';
    }
    $changed = true;
  }
  if (!$changed) {
    return true;
  }
  $rows[0] = $header;
  return tctWriteCsvRows($filePath, $rows);
}

function tctEnsureTaskFolder(string $tasksDir, string $tagCode): bool
{
  $normalizedTagCode = tctNormalizeTagCode($tagCode);
  if ($normalizedTagCode === '') {
    return false;
  }
  $taskDir = $tasksDir . DIRECTORY_SEPARATOR . $normalizedTagCode;
  if (!is_dir($taskDir) && !(mkdir($taskDir, 0777, true) || is_dir($taskDir))) {
    return false;
  }

  $defaultJsonFiles = [
    'TCQ list.json' => [],
    'TCQ code state.json' => ['nextNumber' => 1],
    'TCQ settings.json' => [
      'answerTimeLimit' => true,
      'randomOrder' => true
    ],
    TCT_SCORE_SETTINGS_FILE => [
      'score' => 0,
      'afterEndtimeScore' => 0
    ]
  ];
  foreach ($defaultJsonFiles as $fileName => $payload) {
    $filePath = $taskDir . DIRECTORY_SEPARATOR . $fileName;
    if (is_file($filePath)) {
      continue;
    }
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
      return false;
    }
    if (file_put_contents($filePath, $json . PHP_EOL, LOCK_EX) === false) {
      return false;
    }
  }

  $defaultCsvFiles = [
    'Answers.csv' => ['Work ID'],
    'Invitees mapped.csv' => ['Work ID', 'count of rolls', 'invitees', 'prize won', 'answers', 'score', 'Answered']
  ];
  foreach ($defaultCsvFiles as $fileName => $header) {
    $filePath = $taskDir . DIRECTORY_SEPARATOR . $fileName;
    if (is_file($filePath)) {
      continue;
    }
    $handle = fopen($filePath, 'c+');
    if ($handle === false) {
      return false;
    }
    if (!flock($handle, LOCK_EX)) {
      fclose($handle);
      return false;
    }
    ftruncate($handle, 0);
    rewind($handle);
    fputcsv($handle, $header);
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
  }

  if (!tctEnsureInviteesMappedColumns($taskDir . DIRECTORY_SEPARATOR . 'Invitees mapped.csv')) {
    return false;
  }

  return true;
}

if (!defined('TCT_INCLUDE_ONLY')) {
  define('TCT_INCLUDE_ONLY', false);
}

if (!TCT_INCLUDE_ONLY && (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') && isset($_POST['tct_action'])) {
  header('Content-Type: application/json; charset=utf-8');

  if (!tctEnsureTasksStorage($tctTasksDir, $tctStorePath)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to prepare task storage.'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $action = trim((string)($_POST['tct_action'] ?? ''));
  $tasks = tctReindexTasks(tctReadStoreTasks($tctStorePath));

  if ($action === 'list') {
    foreach ($tasks as $task) {
      tctEnsureTaskFolder($tctTasksDir, (string)($task['tagCode'] ?? ''));
    }
    tctSaveStoreTasks($tctStorePath, $tasks);
    echo json_encode(['status' => 'ok', 'tasks' => tctMergeTaskScores($tasks, $tctTasksDir)], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'add') {
    $title = trim((string)($_POST['title'] ?? ''));
    $taskType = tctNormalizeTaskType((string)($_POST['task_type'] ?? 'quiz'));
    if ($title === '') {
      echo json_encode(['status' => 'error', 'message' => 'Task title is required.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $tagCode = tctGenerateNextTagCode($tasks);
    if (!tctEnsureTaskFolder($tctTasksDir, $tagCode)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to create task folder.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $tasks[] = [
      'id' => tctMakeTaskId(),
      'title' => $title,
      'tagCode' => $tagCode,
      'taskType' => $taskType,
      'order' => count($tasks) + 1,
      'createdAt' => date('Y-m-d H:i:s')
    ];
    $tasks = tctReindexTasks($tasks);
    if (!tctSaveStoreTasks($tctStorePath, $tasks)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to save task list.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    echo json_encode([
      'status' => 'ok',
      'message' => "Task added. Tag Code: {$tagCode}",
      'generatedTagCode' => $tagCode,
      'tasks' => tctMergeTaskScores($tasks, $tctTasksDir)
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'remove') {
    $id = trim((string)($_POST['id'] ?? ''));
    if ($id === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid task id.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $beforeCount = count($tasks);
    $tasks = array_values(array_filter($tasks, static fn(array $task): bool => (string)($task['id'] ?? '') !== $id));
    if ($beforeCount === count($tasks)) {
      echo json_encode(['status' => 'error', 'message' => 'Task not found.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $tasks = tctReindexTasks($tasks);
    if (!tctSaveStoreTasks($tctStorePath, $tasks)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to save task list.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    echo json_encode(['status' => 'ok', 'message' => 'Task removed.', 'tasks' => tctMergeTaskScores($tasks, $tctTasksDir)], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'save_task_settings') {
    $id = trim((string)($_POST['id'] ?? ''));
    if ($id === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid task id.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $active = tctNormalizeBoolValue($_POST['active'] ?? '0');
    $duration = tctNormalizeBoolValue($_POST['duration'] ?? '0');
    $startDate = tctNormalizeDateValue((string)($_POST['start_date'] ?? ''));
    $startTime = tctNormalizeTimeValue((string)($_POST['start_time'] ?? ''));
    $endDate = tctNormalizeDateValue((string)($_POST['end_date'] ?? ''));
    $endTime = tctNormalizeTimeValue((string)($_POST['end_time'] ?? ''));

    $found = false;
    foreach ($tasks as $index => $task) {
      if ((string)($task['id'] ?? '') !== $id) {
        continue;
      }
      $tasks[$index]['active'] = $active;
      $tasks[$index]['duration'] = $duration;
      $tasks[$index]['startDate'] = $startDate;
      $tasks[$index]['startTime'] = $startTime;
      $tasks[$index]['endDate'] = $endDate;
      $tasks[$index]['endTime'] = $endTime;
      $found = true;
      break;
    }
    if (!$found) {
      echo json_encode(['status' => 'error', 'message' => 'Task not found.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $tasks = tctReindexTasks($tasks);
    if (!tctSaveStoreTasks($tctStorePath, $tasks)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to save task settings.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    echo json_encode(['status' => 'ok', 'message' => 'Task settings saved.', 'tasks' => tctMergeTaskScores($tasks, $tctTasksDir)], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'save_task_score_system') {
    $id = trim((string)($_POST['id'] ?? ''));
    if ($id === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid task id.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $score = tctNormalizeScoreValue($_POST['score'] ?? 0);
    $afterEndtimeScore = tctNormalizeScoreValue($_POST['after_endtime_score'] ?? 0);

    $targetTagCode = '';
    foreach ($tasks as $task) {
      if ((string)($task['id'] ?? '') !== $id) {
        continue;
      }
      $targetTagCode = tctNormalizeTagCode((string)($task['tagCode'] ?? ''));
      break;
    }
    if ($targetTagCode === '') {
      echo json_encode(['status' => 'error', 'message' => 'Task not found.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    if (!tctSaveTaskScoreSettings($tctTasksDir, $targetTagCode, [
      'score' => $score,
      'afterEndtimeScore' => $afterEndtimeScore
    ])) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to save score settings.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    echo json_encode(['status' => 'ok', 'message' => 'Score settings saved.', 'tasks' => tctMergeTaskScores($tasks, $tctTasksDir)], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'reorder') {
    $rawOrderedIds = (string)($_POST['ordered_ids'] ?? '[]');
    $orderedIds = json_decode($rawOrderedIds, true);
    if (!is_array($orderedIds)) {
      echo json_encode(['status' => 'error', 'message' => 'Invalid reorder payload.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $taskMap = [];
    foreach ($tasks as $task) {
      $taskMap[(string)($task['id'] ?? '')] = $task;
    }
    $seen = [];
    $reordered = [];
    foreach ($orderedIds as $rawId) {
      $id = trim((string)$rawId);
      if ($id === '' || isset($seen[$id]) || !isset($taskMap[$id])) {
        continue;
      }
      $seen[$id] = true;
      $reordered[] = $taskMap[$id];
    }
    if (count($reordered) !== count($taskMap)) {
      echo json_encode(['status' => 'error', 'message' => 'Incomplete reorder list.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $tasks = tctReindexTasks($reordered);
    if (!tctSaveStoreTasks($tctStorePath, $tasks)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to save task order.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    echo json_encode(['status' => 'ok', 'message' => 'Task order updated.', 'tasks' => tctMergeTaskScores($tasks, $tctTasksDir)], JSON_UNESCAPED_UNICODE);
    exit;
  }

  echo json_encode(['status' => 'error', 'message' => 'Unsupported action.'], JSON_UNESCAPED_UNICODE);
  exit;
}

if (TCT_INCLUDE_ONLY) {
  return;
}
?>

<div class="card">
  <div class="section-header">
    <h3>Create Task</h3>
  </div>
  <form id="tct-form" class="form" style="gap:12px;">
    <label class="field standard-width">
      <span>Task Title</span>
      <input id="tct-title" name="title" type="text" autocomplete="off" required />
    </label>
    <label class="field standard-width">
      <span>Tag Code</span>
      <input id="tct-tag-code-auto" type="text" value="Auto-generated (001, 002, ...)" readonly />
      <small class="muted">Folder path: <code>/tasks/&lt;Auto Tag Code&gt;</code></small>
    </label>
    <label class="field standard-width">
      <span>Task Type</span>
      <select id="tct-task-type" name="task_type" required>
        <option value="quiz">Quiz Task</option>
      </select>
    </label>
    <div class="field full">
      <button type="submit" class="btn primary standard-primary-button">Add Task</button>
    </div>
    <p id="tct-status" class="muted small" aria-live="polite"></p>
  </form>
</div>

<div class="card">
  <div class="section-header">
    <h3>Tasks List</h3>
  </div>
  <div class="table-wrapper">
    <table class="tct-list-table">
      <thead>
        <tr>
          <th>Order</th>
          <th>Task</th>
          <th>Tag Code</th>
          <th>Action</th>
          <th class="tct-drag-cell">Sort</th>
        </tr>
      </thead>
      <tbody id="tct-list-body">
        <tr><td colspan="5" class="muted">Loading tasks...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<script>
(() => {
  const endpoint = 'mini%20apps/Task%20Club/TCT.php';
  const form = document.getElementById('tct-form');
  const titleInput = document.getElementById('tct-title');
  const taskTypeInput = document.getElementById('tct-task-type');
  const statusEl = document.getElementById('tct-status');
  const listBody = document.getElementById('tct-list-body');
  if (!form || !titleInput || !taskTypeInput || !statusEl || !listBody) return;

  let tasks = [];
  let draggedTaskId = '';

  const esc = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

  const normalizeTaskType = (value) => {
    const token = String(value ?? '').trim().toLowerCase();
    if (token === 'quiz' || token === 'quiz-task' || token === 'quiz task') {
      return 'quiz';
    }
    return 'quiz';
  };

  const normalizeScore = (value) => {
    const parsed = Number.parseInt(String(value ?? '').trim(), 10);
    if (!Number.isFinite(parsed) || parsed < 0) {
      return 0;
    }
    return parsed;
  };

  const setStatus = (message, isError = false) => {
    statusEl.textContent = message || '';
    statusEl.style.color = isError ? '#d1434a' : '';
  };

  const emitTasksChanged = () => {
    const snapshot = tasks.map((task, index) => ({
      id: String(task.id || ''),
      title: String(task.title || ''),
      tagCode: String(task.tagCode || '').trim(),
      taskType: normalizeTaskType(task.taskType || 'quiz'),
      active: Boolean(task.active),
      duration: Boolean(task.duration),
      startDate: String(task.startDate || ''),
      startTime: String(task.startTime || ''),
      endDate: String(task.endDate || ''),
      endTime: String(task.endTime || ''),
      score: normalizeScore(task.score),
      afterEndtimeScore: normalizeScore(task.afterEndtimeScore),
      order: Number.parseInt(task.order, 10) || (index + 1),
      createdAt: String(task.createdAt || '')
    }));
    try {
      window.TC_TASKS = snapshot;
      window.dispatchEvent(new CustomEvent('tcTasksChanged', {
        detail: { tasks: snapshot }
      }));
    } catch {}
  };

  const clearDragVisuals = () => {
    Array.from(listBody.querySelectorAll('.tct-row-drop-target')).forEach((node) => {
      node.classList.remove('tct-row-drop-target');
    });
    Array.from(listBody.querySelectorAll('.tct-row-dragging')).forEach((node) => {
      node.classList.remove('tct-row-dragging');
    });
  };

  const reorderById = (dragId, targetId, placeAfter) => {
    const fromIndex = tasks.findIndex((item) => item.id === dragId);
    const toIndex = tasks.findIndex((item) => item.id === targetId);
    if (fromIndex < 0 || toIndex < 0 || fromIndex === toIndex) return false;
    const [moved] = tasks.splice(fromIndex, 1);
    let insertIndex = toIndex;
    if (fromIndex < toIndex) {
      insertIndex = placeAfter ? toIndex : toIndex - 1;
    } else {
      insertIndex = placeAfter ? toIndex + 1 : toIndex;
    }
    insertIndex = Math.max(0, Math.min(tasks.length, insertIndex));
    tasks.splice(insertIndex, 0, moved);
    tasks = tasks.map((task, index) => ({ ...task, order: index + 1 }));
    return true;
  };

  const renderTasks = () => {
    if (!tasks.length) {
      listBody.innerHTML = '<tr><td colspan="5" class="muted">No tasks created yet.</td></tr>';
      return;
    }
    listBody.innerHTML = tasks.map((task, index) => `
      <tr data-task-id="${esc(task.id)}" draggable="true">
        <td>${index + 1}</td>
        <td>${esc(task.title)}</td>
        <td><code>${esc(task.tagCode)}</code></td>
        <td>
          <div class="tct-action-wrap">
            <button type="button" class="btn ghost" data-remove-id="${esc(task.id)}">Remove</button>
          </div>
        </td>
        <td class="tct-drag-cell">
          <button type="button" class="tct-drag-handle" data-drag-handle="1" title="Drag to reorder" aria-label="Drag to reorder">&#9776;</button>
        </td>
      </tr>
    `).join('');
  };

  const postAction = async (action, payload = {}) => {
    const formData = new FormData();
    formData.append('tct_action', action);
    Object.entries(payload).forEach(([key, value]) => {
      formData.append(key, String(value ?? ''));
    });
    const response = await fetch(endpoint, { method: 'POST', body: formData });
    const data = await response.json();
    if (!response.ok || data?.status !== 'ok') {
      throw new Error(data?.message || 'Request failed.');
    }
    return data;
  };

  const syncTasks = async () => {
    const data = await postAction('list');
    const loaded = Array.isArray(data.tasks) ? data.tasks : [];
    tasks = loaded.map((task, index) => ({
      id: String(task.id || ''),
      title: String(task.title || ''),
      tagCode: String(task.tagCode || '').trim(),
      taskType: normalizeTaskType(task.taskType || 'quiz'),
      active: Boolean(task.active),
      duration: Boolean(task.duration),
      startDate: String(task.startDate || ''),
      startTime: String(task.startTime || ''),
      endDate: String(task.endDate || ''),
      endTime: String(task.endTime || ''),
      score: normalizeScore(task.score),
      afterEndtimeScore: normalizeScore(task.afterEndtimeScore),
      order: Number.parseInt(task.order, 10) || (index + 1),
      createdAt: String(task.createdAt || '')
    }));
    tasks.sort((a, b) => a.order - b.order);
    renderTasks();
    emitTasksChanged();
  };

  const persistOrder = async () => {
    const orderedIds = tasks.map((task) => task.id);
    const data = await postAction('reorder', { ordered_ids: JSON.stringify(orderedIds) });
    const loaded = Array.isArray(data.tasks) ? data.tasks : [];
    tasks = loaded.map((task, index) => ({
      id: String(task.id || ''),
      title: String(task.title || ''),
      tagCode: String(task.tagCode || '').trim(),
      taskType: normalizeTaskType(task.taskType || 'quiz'),
      active: Boolean(task.active),
      duration: Boolean(task.duration),
      startDate: String(task.startDate || ''),
      startTime: String(task.startTime || ''),
      endDate: String(task.endDate || ''),
      endTime: String(task.endTime || ''),
      score: normalizeScore(task.score),
      afterEndtimeScore: normalizeScore(task.afterEndtimeScore),
      order: Number.parseInt(task.order, 10) || (index + 1),
      createdAt: String(task.createdAt || '')
    }));
    tasks.sort((a, b) => a.order - b.order);
    renderTasks();
    emitTasksChanged();
    setStatus(data.message || 'Task order updated.');
  };

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const title = String(titleInput.value || '').trim();
    const taskType = normalizeTaskType(taskTypeInput.value);
    if (!title) {
      setStatus('Task title is required.', true);
      titleInput.focus();
      return;
    }
    const submitButton = form.querySelector('button[type="submit"]');
    if (submitButton instanceof HTMLButtonElement) {
      submitButton.disabled = true;
    }
    try {
      const data = await postAction('add', { title, task_type: taskType });
      tasks = Array.isArray(data.tasks) ? data.tasks : tasks;
      tasks.sort((a, b) => Number(a.order) - Number(b.order));
      renderTasks();
      emitTasksChanged();
      setStatus(data.message || 'Task added.');
      form.reset();
      taskTypeInput.value = 'quiz';
      titleInput.focus();
    } catch (error) {
      setStatus(error?.message || 'Failed to add task.', true);
    } finally {
      if (submitButton instanceof HTMLButtonElement) {
        submitButton.disabled = false;
      }
    }
  });

  listBody.addEventListener('click', async (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const removeBtn = target.closest('[data-remove-id]');
    if (!removeBtn) return;
    const id = removeBtn.getAttribute('data-remove-id') || '';
    if (!id) return;
    if (!window.confirm('Remove this task?')) return;
    try {
      const data = await postAction('remove', { id });
      tasks = Array.isArray(data.tasks) ? data.tasks : [];
      tasks.sort((a, b) => Number(a.order) - Number(b.order));
      renderTasks();
      emitTasksChanged();
      setStatus(data.message || 'Task removed.');
    } catch (error) {
      setStatus(error?.message || 'Failed to remove task.', true);
    }
  });

  listBody.addEventListener('dragstart', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const handle = target.closest('[data-drag-handle]');
    if (!handle) {
      event.preventDefault();
      return;
    }
    const row = handle.closest('tr[data-task-id]');
    if (!(row instanceof HTMLTableRowElement)) {
      event.preventDefault();
      return;
    }
    draggedTaskId = row.getAttribute('data-task-id') || '';
    if (!draggedTaskId) {
      event.preventDefault();
      return;
    }
    row.classList.add('tct-row-dragging');
    if (event.dataTransfer) {
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', draggedTaskId);
    }
  });

  listBody.addEventListener('dragover', (event) => {
    if (!draggedTaskId) return;
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const row = target.closest('tr[data-task-id]');
    if (!(row instanceof HTMLTableRowElement)) return;
    const targetId = row.getAttribute('data-task-id') || '';
    if (!targetId || targetId === draggedTaskId) return;
    event.preventDefault();
    clearDragVisuals();
    row.classList.add('tct-row-drop-target');
  });

  listBody.addEventListener('drop', async (event) => {
    if (!draggedTaskId) return;
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const row = target.closest('tr[data-task-id]');
    if (!(row instanceof HTMLTableRowElement)) return;
    const targetId = row.getAttribute('data-task-id') || '';
    if (!targetId || targetId === draggedTaskId) return;
    event.preventDefault();
    const rect = row.getBoundingClientRect();
    const placeAfter = event.clientY > (rect.top + rect.height / 2);
    try {
      if (reorderById(draggedTaskId, targetId, placeAfter)) {
        renderTasks();
        await persistOrder();
      }
    } catch (error) {
      setStatus(error?.message || 'Failed to save task order.', true);
      await syncTasks();
    } finally {
      draggedTaskId = '';
      clearDragVisuals();
    }
  });

  listBody.addEventListener('dragend', () => {
    draggedTaskId = '';
    clearDragVisuals();
  });

  syncTasks()
    .then(() => setStatus(''))
    .catch((error) => {
      tasks = [];
      renderTasks();
      emitTasksChanged();
      setStatus(error?.message || 'Failed to load tasks.', true);
    });
})();
</script>
