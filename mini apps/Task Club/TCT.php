<?php
declare(strict_types=1);

require_once __DIR__ . '/../../api/lib/tab-permissions.php';
$tctIsJsonRequest = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') && isset($_POST['tct_action']);
requireTabPermissionFromSession('task-club', $tctIsJsonRequest);

$tctTasksDir = __DIR__ . '/tasks';
$tctStorePath = $tctTasksDir . '/tasks.js';
$tctEventInviteesPath = __DIR__ . '/TC Event/Invitees mapped.csv';
$tctEventInviteesMapPath = __DIR__ . '/TC Event/TC Mapped.json';
const TCT_SCORE_SETTINGS_FILE = 'task-score.json';
const TCT_INFO_SETTINGS_FILE = 'info-task.json';
const TCT_INFO_SCORES_FILE = 'info-task-scores.json';
const TCT_DESCRIBE_PHOTO_DIR = 'photos';
const TCT_DESCRIBE_PHOTO_META_FILE = 'photos.json';

function tctNormalizeTaskType(string $value): string
{
  $token = strtolower(trim($value));
  if (in_array($token, ['quiz', 'quiz-task', 'quiz task'], true)) {
    return 'quiz';
  }
  if (in_array($token, ['info', 'info-task', 'info task'], true)) {
    return 'info';
  }
  if (in_array($token, ['describe_photo', 'describe-photo', 'describe photo', 'describe-photo-task', 'describe photo task'], true)) {
    return 'describe_photo';
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

function tctNormalizeTaskTitle(string $value): string
{
  $title = trim($value);
  $title = preg_replace('/\s+/u', ' ', $title);
  if (!is_string($title)) {
    return '';
  }
  return trim($title);
}

function tctParseTaskCompletedIds(string $value): array
{
  $parts = preg_split('/\s*,\s*/', trim($value));
  if (!is_array($parts)) {
    return [];
  }
  $seen = [];
  $result = [];
  foreach ($parts as $part) {
    $token = trim((string)$part);
    if ($token === '' || isset($seen[$token])) {
      continue;
    }
    $seen[$token] = true;
    $result[] = $token;
  }
  return $result;
}

function tctSerializeTaskCompletedIds(array $ids): string
{
  $seen = [];
  $result = [];
  foreach ($ids as $id) {
    $token = trim((string)$id);
    if ($token === '' || isset($seen[$token])) {
      continue;
    }
    $seen[$token] = true;
    $result[] = $token;
  }
  return implode(',', $result);
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

function tctBuildTaskInfoSettingsPath(string $tasksDir, string $tagCode): string
{
  $taskDir = tctBuildTaskDirPath($tasksDir, $tagCode);
  if ($taskDir === '') {
    return '';
  }
  return $taskDir . DIRECTORY_SEPARATOR . TCT_INFO_SETTINGS_FILE;
}

function tctBuildTaskInfoScoresPath(string $tasksDir, string $tagCode): string
{
  $taskDir = tctBuildTaskDirPath($tasksDir, $tagCode);
  if ($taskDir === '') {
    return '';
  }
  return $taskDir . DIRECTORY_SEPARATOR . TCT_INFO_SCORES_FILE;
}

function tctLoadTaskInfoSettings(string $tasksDir, string $tagCode): array
{
  $defaults = [
    'title' => '',
    'text' => ''
  ];
  $path = tctBuildTaskInfoSettingsPath($tasksDir, $tagCode);
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
    'title' => trim((string)($decoded['title'] ?? '')),
    'text' => trim((string)($decoded['text'] ?? ''))
  ];
}

function tctSaveTaskInfoSettings(string $tasksDir, string $tagCode, array $payload): bool
{
  if (!tctEnsureTaskFolder($tasksDir, $tagCode)) {
    return false;
  }
  $path = tctBuildTaskInfoSettingsPath($tasksDir, $tagCode);
  if ($path === '') {
    return false;
  }
  $safePayload = [
    'title' => trim((string)($payload['title'] ?? '')),
    'text' => trim((string)($payload['text'] ?? ''))
  ];
  $json = json_encode($safePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($json === false) {
    return false;
  }
  return file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

function tctLoadTaskInfoScores(string $tasksDir, string $tagCode): array
{
  $path = tctBuildTaskInfoScoresPath($tasksDir, $tagCode);
  if ($path === '' || !is_file($path)) {
    return [];
  }
  $content = file_get_contents($path);
  if ($content === false) {
    return [];
  }
  $decoded = json_decode($content, true);
  if (!is_array($decoded)) {
    return [];
  }
  $map = [];
  foreach ($decoded as $workId => $rawScore) {
    $normalizedWorkId = trim((string)$workId);
    if ($normalizedWorkId === '') {
      continue;
    }
    $map[$normalizedWorkId] = tctNormalizeScoreValue($rawScore);
  }
  return $map;
}

function tctSaveTaskInfoScores(string $tasksDir, string $tagCode, array $scoreMap): bool
{
  if (!tctEnsureTaskFolder($tasksDir, $tagCode)) {
    return false;
  }
  $path = tctBuildTaskInfoScoresPath($tasksDir, $tagCode);
  if ($path === '') {
    return false;
  }
  $safeMap = [];
  foreach ($scoreMap as $workId => $rawScore) {
    $normalizedWorkId = trim((string)$workId);
    if ($normalizedWorkId === '') {
      continue;
    }
    $safeMap[$normalizedWorkId] = tctNormalizeScoreValue($rawScore);
  }
  $json = json_encode($safeMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($json === false) {
    return false;
  }
  return file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

function tctBuildTaskDescribePhotoDirPath(string $tasksDir, string $tagCode): string
{
  $taskDir = tctBuildTaskDirPath($tasksDir, $tagCode);
  if ($taskDir === '') {
    return '';
  }
  return $taskDir . DIRECTORY_SEPARATOR . TCT_DESCRIBE_PHOTO_DIR;
}

function tctBuildTaskDescribePhotoMetaPath(string $tasksDir, string $tagCode): string
{
  $photoDir = tctBuildTaskDescribePhotoDirPath($tasksDir, $tagCode);
  if ($photoDir === '') {
    return '';
  }
  return $photoDir . DIRECTORY_SEPARATOR . TCT_DESCRIBE_PHOTO_META_FILE;
}

function tctProjectRootPath(): string
{
  static $cached = null;
  if (is_string($cached) && $cached !== '') {
    return $cached;
  }
  $resolved = realpath(__DIR__ . '/../../');
  if (!is_string($resolved) || $resolved === '') {
    $resolved = dirname(__DIR__, 2);
  }
  $cached = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $resolved);
  return $cached;
}

function tctNormalizeRelativePath(string $value): string
{
  $trimmed = trim($value);
  if ($trimmed === '') {
    return '';
  }
  $normalized = str_replace('\\', '/', $trimmed);
  $normalized = preg_replace('#/+#', '/', $normalized);
  if (!is_string($normalized)) {
    return '';
  }
  $normalized = ltrim($normalized, '/');
  if ($normalized === '' || strpos($normalized, '..') !== false) {
    return '';
  }
  return $normalized;
}

function tctResolveAbsolutePathFromRelative(string $relativePath): string
{
  $normalizedRelative = tctNormalizeRelativePath($relativePath);
  if ($normalizedRelative === '') {
    return '';
  }
  $root = tctProjectRootPath();
  if ($root === '') {
    return '';
  }
  $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedRelative);
  $real = realpath($candidate);
  if (!is_string($real) || $real === '' || !is_file($real)) {
    return '';
  }
  $rootNormalized = str_replace('\\', '/', rtrim($root, DIRECTORY_SEPARATOR));
  $realNormalized = str_replace('\\', '/', $real);
  if ($realNormalized !== $rootNormalized && strpos($realNormalized, $rootNormalized . '/') !== 0) {
    return '';
  }
  return $real;
}

function tctBuildTaskDescribePhotoUrl(string $tagCode, string $fileName): string
{
  $normalizedTagCode = tctNormalizeTagCode($tagCode);
  $safeFileName = basename(trim($fileName));
  if ($normalizedTagCode === '' || $safeFileName === '') {
    return '';
  }
  $segments = [
    'mini apps',
    'Task Club',
    'tasks',
    $normalizedTagCode,
    TCT_DESCRIBE_PHOTO_DIR,
    $safeFileName
  ];
  return implode('/', array_map(static fn(string $segment): string => rawurlencode($segment), $segments));
}

function tctMakeTaskDescribePhotoId(): string
{
  try {
    return 'tp_' . bin2hex(random_bytes(6));
  } catch (Throwable $e) {
    return 'tp_' . str_replace('.', '', uniqid('', true));
  }
}

function tctBuildTaskDescribePhotoDisplayName(string $inputName, string $fallbackTitle, string $sourceFilename): string
{
  $name = trim($inputName);
  if ($name !== '') {
    return $name;
  }
  $title = trim($fallbackTitle);
  if ($title !== '') {
    return $title;
  }
  $base = trim(pathinfo(basename($sourceFilename), PATHINFO_FILENAME));
  return $base !== '' ? $base : 'Photo';
}

function tctEnsureTaskDescribePhotoStore(string $tasksDir, string $tagCode): bool
{
  $photoDir = tctBuildTaskDescribePhotoDirPath($tasksDir, $tagCode);
  if ($photoDir === '') {
    return false;
  }
  if (!is_dir($photoDir) && !(mkdir($photoDir, 0777, true) || is_dir($photoDir))) {
    return false;
  }
  $metaPath = tctBuildTaskDescribePhotoMetaPath($tasksDir, $tagCode);
  if ($metaPath === '') {
    return false;
  }
  if (!is_file($metaPath)) {
    $json = json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
      return false;
    }
    if (file_put_contents($metaPath, $json . PHP_EOL, LOCK_EX) === false) {
      return false;
    }
  }
  return true;
}

function tctLoadTaskDescribePhotos(string $tasksDir, string $tagCode): array
{
  $metaPath = tctBuildTaskDescribePhotoMetaPath($tasksDir, $tagCode);
  if ($metaPath === '' || !is_file($metaPath)) {
    return [];
  }
  $content = file_get_contents($metaPath);
  if ($content === false) {
    return [];
  }
  $decoded = json_decode($content, true);
  if (!is_array($decoded)) {
    return [];
  }

  $photos = [];
  foreach ($decoded as $item) {
    if (!is_array($item)) {
      continue;
    }
    $id = trim((string)($item['id'] ?? ''));
    $name = trim((string)($item['name'] ?? ''));
    $fileName = basename(trim((string)($item['fileName'] ?? ($item['filename'] ?? ''))));
    if ($id === '' || $fileName === '') {
      continue;
    }
    if ($name === '') {
      $name = trim(pathinfo($fileName, PATHINFO_FILENAME));
    }
    if ($name === '') {
      $name = 'Photo';
    }
    $photo = [
      'id' => $id,
      'name' => $name,
      'fileName' => $fileName,
      'sourcePhotoId' => max(0, (int)($item['sourcePhotoId'] ?? ($item['source_photo_id'] ?? 0))),
      'sourceFilename' => trim((string)($item['sourceFilename'] ?? ($item['source_filename'] ?? ''))),
      'createdAt' => trim((string)($item['createdAt'] ?? ($item['created_at'] ?? '')))
    ];
    $photo['url'] = tctBuildTaskDescribePhotoUrl($tagCode, $fileName);
    $photos[] = $photo;
  }
  return $photos;
}

function tctSaveTaskDescribePhotos(string $tasksDir, string $tagCode, array $photos): bool
{
  if (!tctEnsureTaskDescribePhotoStore($tasksDir, $tagCode)) {
    return false;
  }
  $metaPath = tctBuildTaskDescribePhotoMetaPath($tasksDir, $tagCode);
  if ($metaPath === '') {
    return false;
  }
  $safe = [];
  $seen = [];
  foreach ($photos as $item) {
    if (!is_array($item)) {
      continue;
    }
    $id = trim((string)($item['id'] ?? ''));
    $fileName = basename(trim((string)($item['fileName'] ?? ($item['filename'] ?? ''))));
    if ($id === '' || $fileName === '' || isset($seen[$id])) {
      continue;
    }
    $seen[$id] = true;
    $name = trim((string)($item['name'] ?? ''));
    if ($name === '') {
      $name = trim(pathinfo($fileName, PATHINFO_FILENAME));
    }
    if ($name === '') {
      $name = 'Photo';
    }
    $safe[] = [
      'id' => $id,
      'name' => $name,
      'fileName' => $fileName,
      'sourcePhotoId' => max(0, (int)($item['sourcePhotoId'] ?? ($item['source_photo_id'] ?? 0))),
      'sourceFilename' => trim((string)($item['sourceFilename'] ?? ($item['source_filename'] ?? ''))),
      'createdAt' => trim((string)($item['createdAt'] ?? ($item['created_at'] ?? '')))
    ];
  }
  $json = json_encode($safe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($json === false) {
    return false;
  }
  return file_put_contents($metaPath, $json . PHP_EOL, LOCK_EX) !== false;
}

function tctMergeTaskScores(array $tasks, string $tasksDir): array
{
  $merged = [];
  foreach ($tasks as $task) {
    if (!is_array($task)) {
      continue;
    }
    $tagCode = (string)($task['tagCode'] ?? '');
    $taskType = tctNormalizeTaskType((string)($task['taskType'] ?? 'quiz'));
    $scoreSettings = tctLoadTaskScoreSettings($tasksDir, $tagCode);
    $task['score'] = $scoreSettings['score'];
    $task['afterEndtimeScore'] = $scoreSettings['afterEndtimeScore'];
    if ($taskType === 'info' || $taskType === 'describe_photo') {
      $info = tctLoadTaskInfoSettings($tasksDir, $tagCode);
      $task['infoTitle'] = (string)($info['title'] ?? '');
      $task['infoText'] = (string)($info['text'] ?? '');
      $task['taskPhotos'] = $taskType === 'describe_photo'
        ? tctLoadTaskDescribePhotos($tasksDir, $tagCode)
        : [];
    } else {
      $task['infoTitle'] = '';
      $task['infoText'] = '';
      $task['taskPhotos'] = [];
    }
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
  $clean = str_replace("\xEF\xBB\xBF", '', $value);
  $normalized = strtolower(trim($clean));
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

function tctFindFirstHeaderIndex(array $header, array $names): int
{
  foreach ($names as $name) {
    $index = tctFindHeaderIndex($header, (string)$name);
    if ($index >= 0) {
      return $index;
    }
  }
  return -1;
}

function tctReadJsonArrayFromFile(string $path): array
{
  if (!is_file($path)) {
    return [];
  }
  $content = file_get_contents($path);
  if ($content === false) {
    return [];
  }
  $decoded = json_decode($content, true);
  return is_array($decoded) ? $decoded : [];
}

function tctResolveInviteesForRateTable(string $inviteesPath, string $mapPath = ''): array
{
  $rows = tctReadCsvRows($inviteesPath);
  if (!$rows || !isset($rows[0]) || !is_array($rows[0])) {
    return [];
  }
  $header = $rows[0];
  $mapping = tctReadJsonArrayFromFile($mapPath);
  $workIdIndex = isset($mapping['workId']) && is_numeric($mapping['workId'])
    ? (int)$mapping['workId']
    : tctFindFirstHeaderIndex($header, ['Work ID', 'work id', 'workid', 'کد پرسنلی']);
  if ($workIdIndex < 0) {
    return [];
  }
  $firstNameIndex = isset($mapping['firstName']) && is_numeric($mapping['firstName'])
    ? (int)$mapping['firstName']
    : tctFindFirstHeaderIndex($header, ['first name', 'firstname', 'نام']);
  $lastNameIndex = isset($mapping['lastName']) && is_numeric($mapping['lastName'])
    ? (int)$mapping['lastName']
    : tctFindFirstHeaderIndex($header, ['last name', 'lastname', 'نام خانوادگی']);
  $phoneIndex = isset($mapping['phoneNumber']) && is_numeric($mapping['phoneNumber'])
    ? (int)$mapping['phoneNumber']
    : tctFindFirstHeaderIndex($header, ['phone', 'phone number', 'mobile', 'cell', 'شماره موبایل', 'موبایل']);
  $nameIndex = tctFindFirstHeaderIndex($header, ['full name', 'fullname', 'name', 'نام و نام خانوادگی']);

  $invitees = [];
  for ($i = 1; $i < count($rows); $i += 1) {
    $row = is_array($rows[$i] ?? null) ? $rows[$i] : [];
    $workId = trim((string)($row[$workIdIndex] ?? ''));
    if ($workId === '') {
      continue;
    }
    $firstName = $firstNameIndex >= 0 ? trim((string)($row[$firstNameIndex] ?? '')) : '';
    $lastName = $lastNameIndex >= 0 ? trim((string)($row[$lastNameIndex] ?? '')) : '';
    if (($firstName === '' || $lastName === '') && $nameIndex >= 0) {
      $fullName = trim((string)($row[$nameIndex] ?? ''));
      if ($fullName !== '') {
        $parts = preg_split('/\s+/u', $fullName) ?: [];
        if ($firstName === '') {
          $firstName = trim((string)($parts[0] ?? ''));
        }
        if ($lastName === '' && count($parts) > 1) {
          $lastName = trim((string)implode(' ', array_slice($parts, 1)));
        }
      }
    }
    $phone = $phoneIndex >= 0 ? trim((string)($row[$phoneIndex] ?? '')) : '';
    $invitees[] = [
      'workId' => $workId,
      'firstName' => $firstName,
      'lastName' => $lastName,
      'phone' => $phone
    ];
  }
  return $invitees;
}

function tctResolveWorkIdIndexFromHeaderAndMap(array $header, string $mapPath = ''): int
{
  $mapping = tctReadJsonArrayFromFile($mapPath);
  $mappedIndex = $mapping['workId'] ?? null;
  if (is_numeric($mappedIndex)) {
    $index = (int)$mappedIndex;
    if ($index >= 0) {
      return $index;
    }
  }
  return tctFindFirstHeaderIndex($header, ['Work ID', 'work id', 'workid', 'کد پرسنلی']);
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

function tctEnsureInviteesColumns(array &$rows, array $requiredColumns): array
{
  if (!$rows || !isset($rows[0]) || !is_array($rows[0])) {
    $rows = [$requiredColumns];
    $indexMap = [];
    foreach ($requiredColumns as $i => $name) {
      $indexMap[tctNormalizeHeaderName($name)] = (int)$i;
    }
    return $indexMap;
  }
  $header = $rows[0];
  $changed = false;
  foreach ($requiredColumns as $columnName) {
    $normalized = tctNormalizeHeaderName($columnName);
    if (tctFindHeaderIndex($header, $columnName) >= 0) {
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
  if ($changed) {
    $rows[0] = $header;
  }
  $indexMap = [];
  foreach ($header as $i => $name) {
    $normalized = tctNormalizeHeaderName((string)$name);
    if ($normalized === '' || array_key_exists($normalized, $indexMap)) {
      continue;
    }
    $indexMap[$normalized] = (int)$i;
  }
  return $indexMap;
}

function tctParseInfoTasksMap(string $value): array
{
  $map = [];
  $parts = preg_split('/\s*,\s*/', trim($value));
  if (!is_array($parts)) {
    return $map;
  }
  foreach ($parts as $part) {
    $chunk = trim((string)$part);
    if ($chunk === '') {
      continue;
    }
    $sepPos = strpos($chunk, '::');
    if ($sepPos === false) {
      continue;
    }
    $taskId = trim(substr($chunk, 0, $sepPos));
    $scoreRaw = trim(substr($chunk, $sepPos + 2));
    if ($taskId === '') {
      continue;
    }
    $map[$taskId] = tctNormalizeScoreValue($scoreRaw);
  }
  return $map;
}

function tctSerializeInfoTasksMap(array $map): string
{
  $pairs = [];
  foreach ($map as $taskId => $score) {
    $normalizedTaskId = trim((string)$taskId);
    if ($normalizedTaskId === '') {
      continue;
    }
    $pairs[] = $normalizedTaskId . '::' . (string)tctNormalizeScoreValue($score);
  }
  return implode(', ', $pairs);
}

function tctResolveTaskScoreColumnByType(string $taskType): string
{
  return $taskType === 'describe_photo' ? 'Describe Photo Task' : 'Info Tasks';
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
    ],
    TCT_INFO_SETTINGS_FILE => [
      'title' => '',
      'text' => ''
    ],
    TCT_INFO_SCORES_FILE => []
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

  if (!tctEnsureTaskDescribePhotoStore($tasksDir, $normalizedTagCode)) {
    return false;
  }

  return true;
}

function tctRemoveDirectoryRecursive(string $path): bool
{
  if (!is_dir($path)) {
    return true;
  }
  $entries = scandir($path);
  if (!is_array($entries)) {
    return false;
  }
  foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..') {
      continue;
    }
    $full = $path . DIRECTORY_SEPARATOR . $entry;
    if (is_dir($full)) {
      if (!tctRemoveDirectoryRecursive($full)) {
        return false;
      }
      continue;
    }
    if (!@unlink($full)) {
      return false;
    }
  }
  return @rmdir($path);
}

function tctCleanupInviteesMappedForRemovedTask(string $inviteesPath, array $task, string $tasksDir): bool
{
  $rows = tctReadCsvRows($inviteesPath);
  if (!$rows || !isset($rows[0]) || !is_array($rows[0])) {
    return true;
  }

  $header = $rows[0];
  $taskId = trim((string)($task['id'] ?? ''));
  $tagCode = tctNormalizeTagCode((string)($task['tagCode'] ?? ''));
  $taskScore = 0;
  if ($tagCode !== '') {
    $scoreSettings = tctLoadTaskScoreSettings($tasksDir, $tagCode);
    $taskScore = max(0, (int)($scoreSettings['score'] ?? 0));
  }

  $scoreIndex = tctFindHeaderIndex($header, 'score');
  $completedIdsIndex = tctFindHeaderIndex($header, 'task completed ids');
  if ($completedIdsIndex >= 0 && $taskId !== '') {
    for ($i = 1; $i < count($rows); $i += 1) {
      if (!is_array($rows[$i])) {
        $rows[$i] = [];
      }
      $ids = tctParseTaskCompletedIds((string)($rows[$i][$completedIdsIndex] ?? ''));
      $hadTask = in_array($taskId, $ids, true);
      if (!$hadTask) {
        continue;
      }
      $ids = array_values(array_filter($ids, static fn(string $value): bool => $value !== $taskId));
      $rows[$i][$completedIdsIndex] = tctSerializeTaskCompletedIds($ids);

      if ($scoreIndex >= 0 && $taskScore > 0) {
        $currentScore = max(0, (int)($rows[$i][$scoreIndex] ?? 0));
        $rows[$i][$scoreIndex] = (string)max(0, $currentScore - $taskScore);
      }
    }
  }

  // Remove task-specific columns if they exist in this mapped CSV.
  $dropCandidates = [];
  if ($taskId !== '') {
    $dropCandidates[] = $taskId;
  }
  if ($tagCode !== '') {
    $dropCandidates[] = $tagCode;
  }
  $dropIndexes = [];
  foreach ($dropCandidates as $candidate) {
    $idx = tctFindHeaderIndex($header, $candidate);
    if ($idx >= 0) {
      $dropIndexes[$idx] = true;
    }
  }
  if ($dropIndexes) {
    $indexes = array_keys($dropIndexes);
    rsort($indexes, SORT_NUMERIC);
    foreach ($rows as $rowIdx => $row) {
      if (!is_array($row)) {
        $row = [];
      }
      foreach ($indexes as $colIdx) {
        array_splice($row, $colIdx, 1);
      }
      $rows[$rowIdx] = $row;
    }
  }

  return tctWriteCsvRows($inviteesPath, $rows);
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
    $title = tctNormalizeTaskTitle((string)($_POST['title'] ?? ''));
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
    $targetTask = null;
    foreach ($tasks as $task) {
      if ((string)($task['id'] ?? '') === $id) {
        $targetTask = $task;
        break;
      }
    }
    if (!is_array($targetTask)) {
      echo json_encode(['status' => 'error', 'message' => 'Task not found.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $nextTasks = array_values(array_filter($tasks, static fn(array $task): bool => (string)($task['id'] ?? '') !== $id));

    if (!tctCleanupInviteesMappedForRemovedTask($tctEventInviteesPath, $targetTask, $tctTasksDir)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to cleanup invitees mapped CSV.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $targetTagCode = tctNormalizeTagCode((string)($targetTask['tagCode'] ?? ''));
    if ($targetTagCode !== '') {
      $taskDir = $tctTasksDir . DIRECTORY_SEPARATOR . $targetTagCode;
      if (!tctRemoveDirectoryRecursive($taskDir)) {
        echo json_encode(['status' => 'error', 'message' => 'Task removed from list, but cleanup of task files failed.'], JSON_UNESCAPED_UNICODE);
        exit;
      }
    }

    $tasks = tctReindexTasks($nextTasks);
    if (!tctSaveStoreTasks($tctStorePath, $tasks)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to save task list.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    echo json_encode(['status' => 'ok', 'message' => 'Task removed and cleaned up.', 'tasks' => tctMergeTaskScores($tasks, $tctTasksDir)], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'save_task_title') {
    $id = trim((string)($_POST['id'] ?? ''));
    $title = tctNormalizeTaskTitle((string)($_POST['title'] ?? ''));
    if ($id === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid task id.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if ($title === '') {
      echo json_encode(['status' => 'error', 'message' => 'Task title is required.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $found = false;
    foreach ($tasks as $index => $task) {
      if ((string)($task['id'] ?? '') !== $id) {
        continue;
      }
      $tasks[$index]['title'] = $title;
      $found = true;
      break;
    }
    if (!$found) {
      echo json_encode(['status' => 'error', 'message' => 'Task not found.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $tasks = tctReindexTasks($tasks);
    if (!tctSaveStoreTasks($tctStorePath, $tasks)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to save task title.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    echo json_encode(['status' => 'ok', 'message' => 'Task title saved.', 'tasks' => tctMergeTaskScores($tasks, $tctTasksDir)], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'save_task_settings') {
    $id = trim((string)($_POST['id'] ?? ''));
    $title = tctNormalizeTaskTitle((string)($_POST['title'] ?? ''));
    if ($id === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid task id.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if ($title === '') {
      echo json_encode(['status' => 'error', 'message' => 'Task title is required.'], JSON_UNESCAPED_UNICODE);
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
      $tasks[$index]['title'] = $title;
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
    $targetTaskType = 'quiz';
    foreach ($tasks as $task) {
      if ((string)($task['id'] ?? '') !== $id) {
        continue;
      }
      $targetTagCode = tctNormalizeTagCode((string)($task['tagCode'] ?? ''));
      $targetTaskType = tctNormalizeTaskType((string)($task['taskType'] ?? 'quiz'));
      break;
    }
    if ($targetTagCode === '') {
      echo json_encode(['status' => 'error', 'message' => 'Task not found.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    if ($targetTaskType === 'info' || $targetTaskType === 'describe_photo') {
      $afterEndtimeScore = 0;
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

  if ($action === 'save_info_task_content') {
    $id = trim((string)($_POST['id'] ?? ''));
    if ($id === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid task id.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $targetTask = null;
    foreach ($tasks as $task) {
      if ((string)($task['id'] ?? '') === $id) {
        $targetTask = $task;
        break;
      }
    }
    if (!is_array($targetTask)) {
      echo json_encode(['status' => 'error', 'message' => 'Task not found.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $targetTaskType = tctNormalizeTaskType((string)($targetTask['taskType'] ?? 'quiz'));
    if ($targetTaskType !== 'info' && $targetTaskType !== 'describe_photo') {
      echo json_encode(['status' => 'error', 'message' => 'This action is only for Info Task.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $tagCode = tctNormalizeTagCode((string)($targetTask['tagCode'] ?? ''));
    if ($tagCode === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid task tag code.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $title = trim((string)($_POST['info_title'] ?? ''));
    $text = trim((string)($_POST['info_text'] ?? ''));
    if (!tctSaveTaskInfoSettings($tctTasksDir, $tagCode, [
      'title' => $title,
      'text' => $text
    ])) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to save information content.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    echo json_encode([
      'status' => 'ok',
      'message' => 'Information content saved.',
      'tasks' => tctMergeTaskScores($tasks, $tctTasksDir)
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'add_describe_task_photo') {
    $id = trim((string)($_POST['id'] ?? ''));
    if ($id === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid task id.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $targetTask = null;
    foreach ($tasks as $task) {
      if ((string)($task['id'] ?? '') === $id) {
        $targetTask = $task;
        break;
      }
    }
    if (!is_array($targetTask)) {
      echo json_encode(['status' => 'error', 'message' => 'Task not found.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $targetTaskType = tctNormalizeTaskType((string)($targetTask['taskType'] ?? 'quiz'));
    if ($targetTaskType !== 'describe_photo') {
      echo json_encode(['status' => 'error', 'message' => 'This action is only for Describe Photo Task.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $tagCode = tctNormalizeTagCode((string)($targetTask['tagCode'] ?? ''));
    if ($tagCode === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid task tag code.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $rawPhotoJson = (string)($_POST['photo_json'] ?? '');
    $photoPayload = json_decode($rawPhotoJson, true);
    if (!is_array($photoPayload)) {
      echo json_encode(['status' => 'error', 'message' => 'Invalid photo payload.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $sourceFilename = trim((string)($photoPayload['filename'] ?? ''));
    $sourceAbsolutePath = tctResolveAbsolutePathFromRelative($sourceFilename);
    if ($sourceAbsolutePath === '') {
      echo json_encode(['status' => 'error', 'message' => 'Selected photo file was not found.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    if (!tctEnsureTaskFolder($tctTasksDir, $tagCode)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to prepare task folder.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $photosDirPath = tctBuildTaskDescribePhotoDirPath($tctTasksDir, $tagCode);
    if ($photosDirPath === '' || (!is_dir($photosDirPath) && !(mkdir($photosDirPath, 0777, true) || is_dir($photosDirPath)))) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to prepare photos directory.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $extension = strtolower(trim((string)pathinfo($sourceAbsolutePath, PATHINFO_EXTENSION)));
    if ($extension === '') {
      $extension = 'jpg';
    }
    try {
      $token = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
      $token = str_replace('.', '', uniqid('', true));
    }
    $destinationFileName = 'task-photo-' . date('YmdHis') . '-' . $token . '.' . $extension;
    $destinationAbsolutePath = $photosDirPath . DIRECTORY_SEPARATOR . $destinationFileName;
    if (!@copy($sourceAbsolutePath, $destinationAbsolutePath)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to copy selected photo.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $customName = trim((string)($_POST['photo_name'] ?? ''));
    $titleFallback = trim((string)($photoPayload['title'] ?? ''));
    $displayName = tctBuildTaskDescribePhotoDisplayName($customName, $titleFallback, $sourceFilename);

    $photos = tctLoadTaskDescribePhotos($tctTasksDir, $tagCode);
    $photos[] = [
      'id' => tctMakeTaskDescribePhotoId(),
      'name' => $displayName,
      'fileName' => $destinationFileName,
      'sourcePhotoId' => max(0, (int)($photoPayload['id'] ?? 0)),
      'sourceFilename' => $sourceFilename,
      'createdAt' => date('Y-m-d H:i:s')
    ];
    if (!tctSaveTaskDescribePhotos($tctTasksDir, $tagCode, $photos)) {
      @unlink($destinationAbsolutePath);
      echo json_encode(['status' => 'error', 'message' => 'Failed to save photo metadata.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    echo json_encode([
      'status' => 'ok',
      'message' => 'Photo added.',
      'tasks' => tctMergeTaskScores($tasks, $tctTasksDir)
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'rename_describe_task_photo') {
    $id = trim((string)($_POST['id'] ?? ''));
    $photoId = trim((string)($_POST['photo_id'] ?? ''));
    if ($id === '' || $photoId === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid photo payload.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $targetTask = null;
    foreach ($tasks as $task) {
      if ((string)($task['id'] ?? '') === $id) {
        $targetTask = $task;
        break;
      }
    }
    if (!is_array($targetTask)) {
      echo json_encode(['status' => 'error', 'message' => 'Task not found.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $targetTaskType = tctNormalizeTaskType((string)($targetTask['taskType'] ?? 'quiz'));
    if ($targetTaskType !== 'describe_photo') {
      echo json_encode(['status' => 'error', 'message' => 'This action is only for Describe Photo Task.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $tagCode = tctNormalizeTagCode((string)($targetTask['tagCode'] ?? ''));
    if ($tagCode === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid task tag code.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $newName = trim((string)($_POST['photo_name'] ?? ''));
    if ($newName === '') {
      echo json_encode(['status' => 'error', 'message' => 'Photo name is required.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $photos = tctLoadTaskDescribePhotos($tctTasksDir, $tagCode);
    $found = false;
    foreach ($photos as $index => $photo) {
      if (!is_array($photo) || trim((string)($photo['id'] ?? '')) !== $photoId) {
        continue;
      }
      $photos[$index]['name'] = $newName;
      $found = true;
      break;
    }
    if (!$found) {
      echo json_encode(['status' => 'error', 'message' => 'Photo not found.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if (!tctSaveTaskDescribePhotos($tctTasksDir, $tagCode, $photos)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to update photo name.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    echo json_encode([
      'status' => 'ok',
      'message' => 'Photo name updated.',
      'tasks' => tctMergeTaskScores($tasks, $tctTasksDir)
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'remove_describe_task_photo') {
    $id = trim((string)($_POST['id'] ?? ''));
    $photoId = trim((string)($_POST['photo_id'] ?? ''));
    if ($id === '' || $photoId === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid photo payload.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $targetTask = null;
    foreach ($tasks as $task) {
      if ((string)($task['id'] ?? '') === $id) {
        $targetTask = $task;
        break;
      }
    }
    if (!is_array($targetTask)) {
      echo json_encode(['status' => 'error', 'message' => 'Task not found.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $targetTaskType = tctNormalizeTaskType((string)($targetTask['taskType'] ?? 'quiz'));
    if ($targetTaskType !== 'describe_photo') {
      echo json_encode(['status' => 'error', 'message' => 'This action is only for Describe Photo Task.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $tagCode = tctNormalizeTagCode((string)($targetTask['tagCode'] ?? ''));
    if ($tagCode === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid task tag code.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $photos = tctLoadTaskDescribePhotos($tctTasksDir, $tagCode);
    $nextPhotos = [];
    $removedPhoto = null;
    foreach ($photos as $photo) {
      if (!is_array($photo)) {
        continue;
      }
      if ($removedPhoto === null && trim((string)($photo['id'] ?? '')) === $photoId) {
        $removedPhoto = $photo;
        continue;
      }
      $nextPhotos[] = $photo;
    }
    if (!is_array($removedPhoto)) {
      echo json_encode(['status' => 'error', 'message' => 'Photo not found.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $photosDirPath = tctBuildTaskDescribePhotoDirPath($tctTasksDir, $tagCode);
    $removedFileName = basename(trim((string)($removedPhoto['fileName'] ?? '')));
    if ($photosDirPath !== '' && $removedFileName !== '') {
      $removedAbsolutePath = $photosDirPath . DIRECTORY_SEPARATOR . $removedFileName;
      if (is_file($removedAbsolutePath)) {
        @unlink($removedAbsolutePath);
      }
    }

    if (!tctSaveTaskDescribePhotos($tctTasksDir, $tagCode, $nextPhotos)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to update photo list.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    echo json_encode([
      'status' => 'ok',
      'message' => 'Photo removed.',
      'tasks' => tctMergeTaskScores($tasks, $tctTasksDir)
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'get_info_task_rate_data') {
    $id = trim((string)($_POST['id'] ?? ''));
    if ($id === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid task id.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $targetTask = null;
    foreach ($tasks as $task) {
      if ((string)($task['id'] ?? '') === $id) {
        $targetTask = $task;
        break;
      }
    }
    if (!is_array($targetTask)) {
      echo json_encode(['status' => 'error', 'message' => 'Task not found.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $targetTaskType = tctNormalizeTaskType((string)($targetTask['taskType'] ?? 'quiz'));
    if ($targetTaskType !== 'info' && $targetTaskType !== 'describe_photo') {
      echo json_encode(['status' => 'error', 'message' => 'This action is only for Info Task.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $tagCode = tctNormalizeTagCode((string)($targetTask['tagCode'] ?? ''));
    if ($tagCode === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid task tag code.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if (!is_file($tctEventInviteesPath)) {
      echo json_encode(['status' => 'error', 'message' => 'Invitees mapped file not found in TC Event.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $scoreSettings = tctLoadTaskScoreSettings($tctTasksDir, $tagCode);
    $maxScore = max(0, (int)($scoreSettings['score'] ?? 0));
    $taskId = trim((string)($targetTask['id'] ?? ''));
    $invitees = tctResolveInviteesForRateTable($tctEventInviteesPath, $tctEventInviteesMapPath);
    $eventRows = tctReadCsvRows($tctEventInviteesPath);
    $eventHeader = (isset($eventRows[0]) && is_array($eventRows[0])) ? $eventRows[0] : [];
    $workIdIndex = tctResolveWorkIdIndexFromHeaderAndMap($eventHeader, $tctEventInviteesMapPath);
    $taskScoreColumn = tctResolveTaskScoreColumnByType($targetTaskType);
    $infoTasksIndex = tctFindHeaderIndex($eventHeader, $taskScoreColumn);
    $infoTaskScoreByWorkId = [];
    if ($workIdIndex >= 0 && $infoTasksIndex >= 0) {
      for ($rowIndex = 1; $rowIndex < count($eventRows); $rowIndex += 1) {
        $row = is_array($eventRows[$rowIndex] ?? null) ? $eventRows[$rowIndex] : [];
        $workId = trim((string)($row[$workIdIndex] ?? ''));
        if ($workId === '') {
          continue;
        }
        $infoMap = tctParseInfoTasksMap((string)($row[$infoTasksIndex] ?? ''));
        $infoTaskScoreByWorkId[$workId] = tctNormalizeScoreValue($infoMap[$taskId] ?? 0);
      }
    }
    foreach ($invitees as $index => $invitee) {
      $workId = trim((string)($invitee['workId'] ?? ''));
      $invitees[$index]['customScore'] = max(0, min($maxScore, (int)($infoTaskScoreByWorkId[$workId] ?? 0)));
    }
    echo json_encode([
      'status' => 'ok',
      'maxScore' => $maxScore,
      'invitees' => $invitees
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'save_info_task_scores') {
    $id = trim((string)($_POST['id'] ?? ''));
    if ($id === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid task id.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $targetTask = null;
    foreach ($tasks as $task) {
      if ((string)($task['id'] ?? '') === $id) {
        $targetTask = $task;
        break;
      }
    }
    if (!is_array($targetTask)) {
      echo json_encode(['status' => 'error', 'message' => 'Task not found.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $targetTaskType = tctNormalizeTaskType((string)($targetTask['taskType'] ?? 'quiz'));
    if ($targetTaskType !== 'info' && $targetTaskType !== 'describe_photo') {
      echo json_encode(['status' => 'error', 'message' => 'This action is only for Info Task.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $tagCode = tctNormalizeTagCode((string)($targetTask['tagCode'] ?? ''));
    if ($tagCode === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid task tag code.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if (!is_file($tctEventInviteesPath)) {
      echo json_encode(['status' => 'error', 'message' => 'Invitees mapped file not found in TC Event.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $scoreSettings = tctLoadTaskScoreSettings($tctTasksDir, $tagCode);
    $maxScore = max(0, (int)($scoreSettings['score'] ?? 0));
    $taskId = trim((string)($targetTask['id'] ?? ''));
    $mode = strtolower(trim((string)($_POST['mode'] ?? 'custom')));
    $rawWorkIds = (string)($_POST['work_ids'] ?? '[]');
    $decodedWorkIds = json_decode($rawWorkIds, true);
    if (!is_array($decodedWorkIds)) {
      $decodedWorkIds = [];
    }
    $workIds = [];
    $seen = [];
    foreach ($decodedWorkIds as $rawWorkId) {
      $workId = trim((string)$rawWorkId);
      if ($workId === '' || isset($seen[$workId])) {
        continue;
      }
      $seen[$workId] = true;
      $workIds[] = $workId;
    }
    if (!$workIds) {
      echo json_encode(['status' => 'error', 'message' => 'No invitee selected.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $assignedScore = $mode === 'max'
      ? $maxScore
      : max(0, min($maxScore, tctNormalizeScoreValue($_POST['custom_score'] ?? 0)));

    $rows = tctReadCsvRows($tctEventInviteesPath);
    $taskScoreColumn = tctResolveTaskScoreColumnByType($targetTaskType);
    $columnIndexByName = tctEnsureInviteesColumns($rows, ['Work ID', 'score', $taskScoreColumn]);
    $header = (isset($rows[0]) && is_array($rows[0])) ? $rows[0] : [];
    $workIdIndex = tctResolveWorkIdIndexFromHeaderAndMap($header, $tctEventInviteesMapPath);
    if ($workIdIndex < 0) {
      $workIdIndex = (int)($columnIndexByName[tctNormalizeHeaderName('Work ID')] ?? -1);
    }
    $scoreIndex = (int)($columnIndexByName[tctNormalizeHeaderName('score')] ?? -1);
    $infoTasksIndex = (int)($columnIndexByName[tctNormalizeHeaderName($taskScoreColumn)] ?? -1);
    if ($workIdIndex < 0 || $scoreIndex < 0 || $infoTasksIndex < 0) {
      echo json_encode(['status' => 'error', 'message' => 'Required invitees columns are missing.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $workIdLookup = [];
    for ($rowIndex = 1; $rowIndex < count($rows); $rowIndex += 1) {
      $row = is_array($rows[$rowIndex] ?? null) ? $rows[$rowIndex] : [];
      $rowWorkId = trim((string)($row[$workIdIndex] ?? ''));
      if ($rowWorkId !== '' && !isset($workIdLookup[$rowWorkId])) {
        $workIdLookup[$rowWorkId] = $rowIndex;
      }
    }

    $scoreMap = tctLoadTaskInfoScores($tctTasksDir, $tagCode);
    $updatedCount = 0;
    foreach ($workIds as $workId) {
      $rowIndex = $workIdLookup[$workId] ?? null;
      if (!is_int($rowIndex) || $rowIndex < 1) {
        continue;
      }
      if (!is_array($rows[$rowIndex])) {
        $rows[$rowIndex] = [];
      }
      $row = &$rows[$rowIndex];
      $infoMap = tctParseInfoTasksMap((string)($row[$infoTasksIndex] ?? ''));
      $previousScore = tctNormalizeScoreValue($infoMap[$taskId] ?? 0);
      $delta = $assignedScore - $previousScore;
      $currentTotal = tctNormalizeScoreValue($row[$scoreIndex] ?? 0);
      $row[$scoreIndex] = (string)max(0, $currentTotal + $delta);
      $infoMap[$taskId] = $assignedScore;
      $row[$infoTasksIndex] = tctSerializeInfoTasksMap($infoMap);
      $scoreMap[$workId] = $assignedScore;
      $updatedCount += 1;
      unset($row);
    }

    if ($updatedCount === 0) {
      echo json_encode(['status' => 'error', 'message' => 'No matching Work ID was found in Invitees mapped CSV.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    if (!tctWriteCsvRows($tctEventInviteesPath, $rows)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to update invitees mapped CSV.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if (!tctSaveTaskInfoScores($tctTasksDir, $tagCode, $scoreMap)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to save invitees scores.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    echo json_encode([
      'status' => 'ok',
      'message' => 'Invitees scores updated.',
      'assignedScore' => $assignedScore
    ], JSON_UNESCAPED_UNICODE);
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
        <option value="info">Info Task</option>
        <option value="describe_photo">Describe Photo Task</option>
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
          <th>Move</th>
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
    if (token === 'info' || token === 'info-task' || token === 'info task') {
      return 'info';
    }
    if (token === 'describe_photo' || token === 'describe-photo' || token === 'describe photo' || token === 'describe-photo-task' || token === 'describe photo task') {
      return 'describe_photo';
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

  const normalizeTasksPayload = (payloadTasks) => {
    const loaded = Array.isArray(payloadTasks) ? payloadTasks : [];
    const normalized = loaded.map((task, index) => ({
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
    normalized.sort((a, b) => a.order - b.order);
    return normalized;
  };

  const setTasks = (payloadTasks) => {
    tasks = normalizeTasksPayload(payloadTasks);
  };

  const moveTaskByOffset = (id, offset) => {
    const fromIndex = tasks.findIndex((item) => item.id === id);
    if (fromIndex < 0) {
      return false;
    }
    const toIndex = fromIndex + offset;
    if (toIndex < 0 || toIndex >= tasks.length) {
      return false;
    }
    const [moved] = tasks.splice(fromIndex, 1);
    tasks.splice(toIndex, 0, moved);
    tasks = tasks.map((task, index) => ({ ...task, order: index + 1 }));
    return true;
  };

  const renderTasks = () => {
    if (!tasks.length) {
      listBody.innerHTML = '<tr><td colspan="5" class="muted">No tasks created yet.</td></tr>';
      return;
    }
    listBody.innerHTML = tasks.map((task, index) => `
      <tr data-task-id="${esc(task.id)}">
        <td>${index + 1}</td>
        <td>
          <div class="tct-task-title-editor">
            <input
              type="text"
              class="tct-task-title-input"
              data-task-title-id="${esc(task.id)}"
              value="${esc(task.title)}"
              autocomplete="off"
            />
            <button type="button" class="btn ghost" data-save-title-id="${esc(task.id)}">Save</button>
          </div>
        </td>
        <td><code>${esc(task.tagCode)}</code></td>
        <td>
          <div class="tct-action-wrap">
            <button type="button" class="btn ghost" data-remove-id="${esc(task.id)}">Remove</button>
          </div>
        </td>
        <td>
          <div class="tct-order-actions">
            <button type="button" class="btn ghost" data-move-up-id="${esc(task.id)}" ${index === 0 ? 'disabled' : ''}>Up</button>
            <button type="button" class="btn ghost" data-move-down-id="${esc(task.id)}" ${index === (tasks.length - 1) ? 'disabled' : ''}>Down</button>
          </div>
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
    setTasks(data.tasks);
    renderTasks();
    emitTasksChanged();
  };

  const persistOrder = async () => {
    const orderedIds = tasks.map((task) => task.id);
    const data = await postAction('reorder', { ordered_ids: JSON.stringify(orderedIds) });
    setTasks(data.tasks);
    renderTasks();
    emitTasksChanged();
    setStatus(data.message || 'Task order updated.');
  };

  const saveTaskTitle = async (id, nextTitle) => {
    const title = String(nextTitle || '').trim();
    if (!id) {
      throw new Error('Invalid task id.');
    }
    if (!title) {
      throw new Error('Task title is required.');
    }
    const data = await postAction('save_task_title', { id, title });
    setTasks(data.tasks);
    renderTasks();
    emitTasksChanged();
    setStatus(data.message || 'Task title saved.');
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
      setTasks(Array.isArray(data.tasks) ? data.tasks : tasks);
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
    const titleSaveBtn = target.closest('[data-save-title-id]');
    if (titleSaveBtn instanceof HTMLButtonElement) {
      const id = titleSaveBtn.getAttribute('data-save-title-id') || '';
      const row = titleSaveBtn.closest('tr[data-task-id]');
      const titleField = row?.querySelector('[data-task-title-id]');
      if (!id || !(titleField instanceof HTMLInputElement)) return;
      titleSaveBtn.disabled = true;
      try {
        await saveTaskTitle(id, titleField.value);
      } catch (error) {
        setStatus(error?.message || 'Failed to save task title.', true);
      } finally {
        titleSaveBtn.disabled = false;
      }
      return;
    }

    const moveUpBtn = target.closest('[data-move-up-id]');
    if (moveUpBtn instanceof HTMLButtonElement) {
      const id = moveUpBtn.getAttribute('data-move-up-id') || '';
      if (!id) return;
      const moved = moveTaskByOffset(id, -1);
      if (!moved) return;
      renderTasks();
      try {
        await persistOrder();
      } catch (error) {
        setStatus(error?.message || 'Failed to save task order.', true);
        await syncTasks();
      }
      return;
    }

    const moveDownBtn = target.closest('[data-move-down-id]');
    if (moveDownBtn instanceof HTMLButtonElement) {
      const id = moveDownBtn.getAttribute('data-move-down-id') || '';
      if (!id) return;
      const moved = moveTaskByOffset(id, 1);
      if (!moved) return;
      renderTasks();
      try {
        await persistOrder();
      } catch (error) {
        setStatus(error?.message || 'Failed to save task order.', true);
        await syncTasks();
      }
      return;
    }

    const removeBtn = target.closest('[data-remove-id]');
    if (!(removeBtn instanceof HTMLButtonElement)) return;
    const id = removeBtn.getAttribute('data-remove-id') || '';
    if (!id) return;
    if (!window.confirm('Remove this task?')) return;
    removeBtn.disabled = true;
    try {
      const data = await postAction('remove', { id });
      setTasks(Array.isArray(data.tasks) ? data.tasks : []);
      renderTasks();
      emitTasksChanged();
      setStatus(data.message || 'Task removed.');
    } catch (error) {
      setStatus(error?.message || 'Failed to remove task.', true);
    } finally {
      removeBtn.disabled = false;
    }
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
