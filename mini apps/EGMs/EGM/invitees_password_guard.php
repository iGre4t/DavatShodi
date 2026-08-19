<?php
declare(strict_types=1);


require_once __DIR__ . '/egm-database-runtime.php';
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../api/lib/tab-permissions.php';
require_once __DIR__ . '/../../../api/lib/common.php';
require_once __DIR__ . '/../../../api/lib/users.php';
require_once __DIR__ . '/egm-security.php';
require_once __DIR__ . '/invitees_special_access.php';
require_once __DIR__ . '/invitees_csv_safety.php';

$egmInviteesPasswordSessionUser = requireTabPermissionFromSession('event-guest-manager', true);
if (!userHasPermissionId($egmInviteesPasswordSessionUser, 'event-guest-manager:invitees')) {
  denyPanelAccess(403, 'You do not have permission to access this Event Guest Manager section.', true);
}
egmSecurityGetCsrfToken();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  http_response_code(405);
  echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
  exit;
}

$input = json_decode((string)egmDbFileGetContents('php://input'), true);
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
  if (egmInviteesCsvIsManagedPath($path)) {
    return egmInviteesCsvReadRowsForUpdate($path);
  }
  if (!egmDbIsFile($path)) {
    return [];
  }
  $handle = egmDbFopen($path, 'r');
  if ($handle === false) {
    return [];
  }
  $rows = [];
  if (!flock($handle, LOCK_SH)) {
    fclose($handle);
    return [];
  }
  while (($row = fgetcsv($handle)) !== false) {
    $rows[] = is_array($row) ? $row : [];
  }
  flock($handle, LOCK_UN);
  fclose($handle);
  return $rows;
}

function inviteePasswordWriteCsvRowsLocked(string $path, array $rows): bool
{
  if (egmInviteesCsvIsManagedPath($path)) {
    return egmInviteesCsvCommitRows($path, $rows);
  }
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

function inviteePasswordReadJsonPayload(string $path): array
{
  if (!egmDbIsFile($path)) {
    return [];
  }
  $content = egmDbFileGetContents($path);
  if (!is_string($content) || trim($content) === '') {
    return [];
  }
  $decoded = json_decode($content, true);
  return is_array($decoded) ? $decoded : [];
}

function inviteePasswordWriteJsonPayload(string $path, array $payload): bool
{
  $dir = dirname($path);
  if ($dir !== '' && !is_dir($dir) && !(mkdir($dir, 0777, true) || is_dir($dir))) {
    return false;
  }
  $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    return false;
  }
  return egmDbFilePutContents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

function inviteePasswordReadMappedConfig(string $path): array
{
  return inviteePasswordReadJsonPayload($path);
}

function inviteePasswordResolveMappedColumnIndex(array $header, array $mapping, array $mappingKeys, array $fallbackNames): int
{
  foreach ($mappingKeys as $mappingKey) {
    $mappedIndex = $mapping[(string)$mappingKey] ?? null;
    if (is_numeric($mappedIndex)) {
      $index = (int)$mappedIndex;
      if ($index >= 0 && $index < count($header)) {
        return $index;
      }
    }
  }
  return inviteePasswordFindHeaderIndexByNames($header, $fallbackNames);
}

function inviteePasswordNormalizeTaskType(string $value): string
{
  $token = strtolower(trim($value));
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

function inviteePasswordTaskTypeLabel(string $taskType): string
{
  $type = inviteePasswordNormalizeTaskType($taskType);
  if ($type === 'conditional_quiz') {
    return 'Conditional Quiz';
  }
  if ($type === 'info') {
    return 'Info Task';
  }
  if ($type === 'team_task') {
    return 'Team Task';
  }
  if ($type === 'describe_photo') {
    return 'Describe Photo';
  }
  return 'Quiz';
}

function inviteePasswordNormalizeScoreValue($value): int
{
  if (!is_scalar($value)) {
    return 0;
  }
  $token = trim((string)$value);
  if ($token === '' || !is_numeric($token)) {
    return 0;
  }
  $score = (int)floor((float)$token);
  return $score > 0 ? $score : 0;
}

function inviteePasswordNormalizeBoolValue($value): bool
{
  if (is_bool($value)) {
    return $value;
  }
  if (is_numeric($value)) {
    return (int)$value === 1;
  }
  $token = strtolower(trim((string)$value));
  return in_array($token, ['1', 'true', 'yes', 'on', 'active', 'enabled'], true);
}

function inviteePasswordBuildTaskDirPath(string $tasksDir, string $tagCode): string
{
  $safeTag = strtoupper(trim($tagCode));
  $safeTag = preg_replace('/[^A-Z0-9_-]+/', '', $safeTag);
  if (!is_string($safeTag) || $safeTag === '') {
    return '';
  }
  return $tasksDir . DIRECTORY_SEPARATOR . $safeTag;
}

function inviteePasswordReadTaskScoreSettings(string $tasksDir, string $tagCode): array
{
  $defaults = [
    'score' => 0,
    'afterEndtimeScore' => 0,
    'anotherChanceIfZero' => false
  ];
  $taskDir = inviteePasswordBuildTaskDirPath($tasksDir, $tagCode);
  if ($taskDir === '') {
    return $defaults;
  }
  $payload = inviteePasswordReadJsonPayload($taskDir . DIRECTORY_SEPARATOR . 'task-score.json');
  if (!$payload) {
    return $defaults;
  }
  return [
    'score' => inviteePasswordNormalizeScoreValue($payload['score'] ?? 0),
    'afterEndtimeScore' => inviteePasswordNormalizeScoreValue($payload['afterEndtimeScore'] ?? ($payload['after_endtime_score'] ?? 0)),
    'anotherChanceIfZero' => !empty($payload['anotherChanceIfZero']) || !empty($payload['another_chance_if_zero'])
  ];
}

function inviteePasswordReadTasks(string $tasksPath, string $tasksDir): array
{
  if (!egmDbIsFile($tasksPath)) {
    return [];
  }
  $content = egmDbFileGetContents($tasksPath);
  if (!is_string($content) || trim($content) === '') {
    return [];
  }
  $json = '';
  if (preg_match('/window\.EGM_TASKS\s*=\s*(\[[\s\S]*\])\s*;?\s*$/', $content, $matches)) {
    $json = (string)($matches[1] ?? '');
  } else {
    $start = strpos($content, '[');
    $end = strrpos($content, ']');
    if ($start !== false && $end !== false && $end >= $start) {
      $json = substr($content, $start, $end - $start + 1);
    }
  }
  if ($json === '') {
    return [];
  }
  $decoded = json_decode($json, true);
  if (!is_array($decoded)) {
    return [];
  }
  $tasks = [];
  foreach ($decoded as $index => $rawTask) {
    if (!is_array($rawTask)) {
      continue;
    }
    $id = trim((string)($rawTask['id'] ?? ''));
    $tagCode = strtoupper(trim((string)($rawTask['tagCode'] ?? ($rawTask['tag_code'] ?? ''))));
    if ($id === '' || $tagCode === '') {
      continue;
    }
    $scoreSettings = inviteePasswordReadTaskScoreSettings($tasksDir, $tagCode);
    $tasks[] = [
      'id' => $id,
      'title' => trim((string)($rawTask['title'] ?? ('Task ' . ((int)$index + 1)))),
      'tagCode' => $tagCode,
      'taskType' => inviteePasswordNormalizeTaskType((string)($rawTask['taskType'] ?? ($rawTask['task_type'] ?? 'quiz'))),
      'active' => inviteePasswordNormalizeBoolValue($rawTask['active'] ?? true),
      'duration' => inviteePasswordNormalizeBoolValue($rawTask['duration'] ?? false),
      'startDate' => trim((string)($rawTask['startDate'] ?? ($rawTask['start_date'] ?? ''))),
      'startTime' => trim((string)($rawTask['startTime'] ?? ($rawTask['start_time'] ?? ''))),
      'endDate' => trim((string)($rawTask['endDate'] ?? ($rawTask['end_date'] ?? ''))),
      'endTime' => trim((string)($rawTask['endTime'] ?? ($rawTask['end_time'] ?? ''))),
      'score' => (int)$scoreSettings['score'],
      'afterEndtimeScore' => (int)$scoreSettings['afterEndtimeScore'],
      'order' => max(1, (int)($rawTask['order'] ?? ((int)$index + 1)))
    ];
  }
  usort($tasks, static function (array $left, array $right): int {
    return ((int)($left['order'] ?? 0)) <=> ((int)($right['order'] ?? 0));
  });
  return array_values($tasks);
}

function inviteePasswordParseList(string $raw): array
{
  $parts = preg_split('/\s*(?:,|;)\s*/', trim($raw));
  if (!is_array($parts)) {
    return [];
  }
  $seen = [];
  $items = [];
  foreach ($parts as $part) {
    $token = trim((string)$part);
    if ($token === '' || isset($seen[$token])) {
      continue;
    }
    $seen[$token] = true;
    $items[] = $token;
  }
  return $items;
}

function inviteePasswordSerializeList(array $items): string
{
  $seen = [];
  $tokens = [];
  foreach ($items as $item) {
    $token = trim((string)$item);
    if ($token === '' || isset($seen[$token])) {
      continue;
    }
    $seen[$token] = true;
    $tokens[] = $token;
  }
  return implode(',', $tokens);
}

function inviteePasswordParseTaskScoreMap(string $raw): array
{
  $map = [];
  foreach (inviteePasswordParseList($raw) as $entry) {
    $separator = strpos($entry, ':');
    if ($separator === false) {
      continue;
    }
    $taskId = trim(substr($entry, 0, $separator));
    if ($taskId === '') {
      continue;
    }
    $map[$taskId] = inviteePasswordNormalizeScoreValue(substr($entry, $separator + 1));
  }
  return $map;
}

function inviteePasswordSerializeTaskScoreMap(array $map): string
{
  $tokens = [];
  foreach ($map as $taskId => $score) {
    $id = trim((string)$taskId);
    if ($id === '') {
      continue;
    }
    $tokens[] = $id . ':' . (string)inviteePasswordNormalizeScoreValue($score);
  }
  return implode(',', $tokens);
}

function inviteePasswordParseInfoTaskMap(string $raw): array
{
  $map = [];
  foreach (inviteePasswordParseList($raw) as $entry) {
    $separator = strpos($entry, '::');
    if ($separator === false) {
      continue;
    }
    $taskId = trim(substr($entry, 0, $separator));
    if ($taskId === '') {
      continue;
    }
    $map[$taskId] = inviteePasswordNormalizeScoreValue(substr($entry, $separator + 2));
  }
  return $map;
}

function inviteePasswordSerializeInfoTaskMap(array $map): string
{
  $tokens = [];
  foreach ($map as $taskId => $score) {
    $id = trim((string)$taskId);
    if ($id === '') {
      continue;
    }
    $tokens[] = $id . '::' . (string)inviteePasswordNormalizeScoreValue($score);
  }
  return implode(', ', $tokens);
}

function inviteePasswordParseTeamTaskMap(string $raw): array
{
  $map = [];
  foreach (inviteePasswordParseList($raw) as $entry) {
    $parts = explode('::', $entry);
    if (count($parts) < 2) {
      continue;
    }
    $taskId = trim((string)($parts[0] ?? ''));
    if ($taskId === '') {
      continue;
    }
    $map[$taskId] = [
      'teamName' => trim((string)($parts[1] ?? '')),
      'status' => trim((string)($parts[2] ?? '')),
      'score' => inviteePasswordNormalizeScoreValue($parts[3] ?? 0)
    ];
  }
  return $map;
}

function inviteePasswordSerializeTeamTaskMap(array $map): string
{
  $tokens = [];
  foreach ($map as $taskId => $entry) {
    $id = trim((string)$taskId);
    if ($id === '' || !is_array($entry)) {
      continue;
    }
    $tokens[] = $id . '::'
      . trim((string)($entry['teamName'] ?? '')) . '::'
      . trim((string)($entry['status'] ?? '')) . '::'
      . (string)inviteePasswordNormalizeScoreValue($entry['score'] ?? 0);
  }
  return implode(', ', $tokens);
}

function inviteePasswordParseDescribePhotoPicksMap(string $raw): array
{
  $map = [];
  foreach (inviteePasswordParseList($raw) as $entry) {
    $parts = explode('::', $entry, 3);
    if (count($parts) !== 3) {
      continue;
    }
    $taskId = trim((string)($parts[0] ?? ''));
    $photoId = trim((string)($parts[1] ?? ''));
    $fileName = basename(trim((string)($parts[2] ?? '')));
    if ($taskId === '' || $photoId === '' || $fileName === '') {
      continue;
    }
    if (!isset($map[$taskId]) || !is_array($map[$taskId])) {
      $map[$taskId] = [];
    }
    $map[$taskId][$photoId] = $fileName;
  }
  return $map;
}

function inviteePasswordSerializeDescribePhotoPicksMap(array $map): string
{
  $tokens = [];
  foreach ($map as $taskId => $photoMap) {
    $id = trim((string)$taskId);
    if ($id === '' || !is_array($photoMap)) {
      continue;
    }
    foreach ($photoMap as $photoId => $fileName) {
      $safePhotoId = trim((string)$photoId);
      $safeFileName = basename(trim((string)$fileName));
      if ($safePhotoId === '' || $safeFileName === '') {
        continue;
      }
      $tokens[] = $id . '::' . $safePhotoId . '::' . $safeFileName;
    }
  }
  return implode(', ', $tokens);
}

function inviteePasswordExtractAnswerQuestionCode(string $headerCell): string
{
  if (!preg_match('/^\s*([A-Za-z0-9_-]+)\s*(?:\||$)/', $headerCell, $matches)) {
    return '';
  }
  return strtoupper(trim((string)($matches[1] ?? '')));
}

function inviteePasswordReadQuestionLookup(string $questionsPath): array
{
  $payload = inviteePasswordReadJsonPayload($questionsPath);
  $lookup = [];
  foreach ($payload as $item) {
    if (!is_array($item)) {
      continue;
    }
    $code = strtoupper(trim((string)($item['code'] ?? '')));
    if ($code === '' || isset($lookup[$code])) {
      continue;
    }
    $lookup[$code] = $item;
  }
  return $lookup;
}

function inviteePasswordReadTaskQuestionLookup(string $tasksDir, string $taskDir): array
{
  $lookup = inviteePasswordReadQuestionLookup($taskDir . DIRECTORY_SEPARATOR . 'EGMQ list.json');
  if ($lookup) {
    return $lookup;
  }
  return inviteePasswordReadQuestionLookup(dirname($tasksDir) . DIRECTORY_SEPARATOR . 'EGMQ list.json');
}

function inviteePasswordReadTaskAnswers(string $tasksDir, array $task, string $workId): array
{
  $tagCode = trim((string)($task['tagCode'] ?? ''));
  $taskDir = inviteePasswordBuildTaskDirPath($tasksDir, $tagCode);
  if ($taskDir === '' || trim($workId) === '') {
    return ['count' => 0, 'correctCount' => 0, 'answers' => []];
  }
  $answersPath = $taskDir . DIRECTORY_SEPARATOR . 'Answers.csv';
  $rows = inviteePasswordReadCsvRows($answersPath);
  if (!$rows || !is_array($rows[0] ?? null)) {
    return ['count' => 0, 'correctCount' => 0, 'answers' => []];
  }
  $header = $rows[0];
  $workIdIndex = inviteePasswordFindHeaderIndexByNames($header, ['Work ID', 'work id', 'workid']);
  if ($workIdIndex < 0) {
    return ['count' => 0, 'correctCount' => 0, 'answers' => []];
  }
  $targetRow = [];
  for ($i = 1; $i < count($rows); $i += 1) {
    $row = is_array($rows[$i] ?? null) ? $rows[$i] : [];
    if (trim((string)($row[$workIdIndex] ?? '')) === trim($workId)) {
      $targetRow = $row;
      break;
    }
  }
  if (!$targetRow) {
    return ['count' => 0, 'correctCount' => 0, 'answers' => []];
  }

  $questionLookup = inviteePasswordReadTaskQuestionLookup($tasksDir, $taskDir);
  $answers = [];
  $correctCount = 0;
  foreach ($header as $index => $label) {
    if ((int)$index === $workIdIndex) {
      continue;
    }
    $answer = trim((string)($targetRow[$index] ?? ''));
    if ($answer === '') {
      continue;
    }
    $headerText = trim((string)$label);
    $code = inviteePasswordExtractAnswerQuestionCode($headerText);
    $questionText = $headerText;
    if ($code !== '') {
      $questionText = trim((string)preg_replace('/^\s*' . preg_quote($code, '/') . '\s*\|\s*/i', '', $headerText));
    }
    $isCorrect = null;
    if ($code !== '' && is_array($questionLookup[$code] ?? null)) {
      $question = $questionLookup[$code];
      $type = strtolower(trim((string)($question['type'] ?? 'mcq')));
      if ($type === 'percentage') {
        $isCorrect = preg_match('/^\d{1,3}$/', $answer) === 1;
      } else {
        $choices = is_array($question['answers'] ?? null) ? array_values($question['answers']) : [];
        $correctAnswer = trim((string)($choices[0] ?? ''));
        $isCorrect = $correctAnswer !== '' && $answer === $correctAnswer;
      }
      if ($isCorrect) {
        $correctCount += 1;
      }
    }
    $answers[] = [
      'code' => $code,
      'question' => $questionText !== '' ? $questionText : $headerText,
      'answer' => $answer,
      'correct' => $isCorrect
    ];
  }

  return [
    'count' => count($answers),
    'correctCount' => $correctCount,
    'answers' => $answers
  ];
}

function inviteePasswordRemoveTaskAnswersRow(string $tasksDir, string $tagCode, string $workId): bool
{
  $taskDir = inviteePasswordBuildTaskDirPath($tasksDir, $tagCode);
  if ($taskDir === '' || trim($workId) === '') {
    return true;
  }
  $answersPath = $taskDir . DIRECTORY_SEPARATOR . 'Answers.csv';
  $rows = inviteePasswordReadCsvRows($answersPath);
  if (!$rows || !is_array($rows[0] ?? null)) {
    return true;
  }
  $workIdIndex = inviteePasswordFindHeaderIndexByNames($rows[0], ['Work ID', 'work id', 'workid']);
  if ($workIdIndex < 0) {
    return true;
  }
  $nextRows = [];
  $changed = false;
  foreach ($rows as $index => $row) {
    $current = is_array($row) ? $row : [];
    if ((int)$index > 0 && trim((string)($current[$workIdIndex] ?? '')) === trim($workId)) {
      $changed = true;
      continue;
    }
    $nextRows[] = $current;
  }
  return !$changed || inviteePasswordWriteCsvRowsLocked($answersPath, $nextRows);
}

function inviteePasswordCountWords(string $text): int
{
  $trimmed = trim($text);
  if ($trimmed === '') {
    return 0;
  }
  $count = preg_match_all('/\S+/u', $trimmed, $matches);
  return is_int($count) && $count > 0 ? $count : 0;
}

function inviteePasswordDescribePhotoArticleStats(string $tasksDir, array $task, array $picksForTask): array
{
  $tagCode = trim((string)($task['tagCode'] ?? ''));
  $taskDir = inviteePasswordBuildTaskDirPath($tasksDir, $tagCode);
  if ($taskDir === '' || !$picksForTask) {
    return ['submissionCount' => 0, 'wordCount' => 0, 'photos' => []];
  }
  $articlesDir = $taskDir . DIRECTORY_SEPARATOR . 'photos' . DIRECTORY_SEPARATOR . 'articles';
  $metaById = [];
  $metaRows = inviteePasswordReadJsonPayload($taskDir . DIRECTORY_SEPARATOR . 'photos' . DIRECTORY_SEPARATOR . 'photos.json');
  foreach ($metaRows as $item) {
    if (!is_array($item)) {
      continue;
    }
    $photoId = trim((string)($item['id'] ?? ''));
    if ($photoId !== '') {
      $metaById[$photoId] = $item;
    }
  }
  $photos = [];
  $submissionCount = 0;
  $wordCountTotal = 0;
  foreach ($picksForTask as $photoId => $fileName) {
    $safeFileName = basename(trim((string)$fileName));
    if ($safeFileName === '') {
      continue;
    }
    $text = '';
    $filePath = $articlesDir . DIRECTORY_SEPARATOR . $safeFileName;
    if (egmDbIsFile($filePath)) {
      $content = egmDbFileGetContents($filePath);
      $text = is_string($content) ? $content : '';
    }
    $wordCount = inviteePasswordCountWords($text);
    if ($wordCount > 0) {
      $submissionCount += 1;
      $wordCountTotal += $wordCount;
    }
    $photoMeta = is_array($metaById[$photoId] ?? null) ? $metaById[$photoId] : [];
    $name = trim((string)($photoMeta['name'] ?? ''));
    if ($name === '') {
      $name = trim((string)pathinfo($safeFileName, PATHINFO_FILENAME));
    }
    $photos[] = [
      'photoId' => (string)$photoId,
      'photoName' => $name !== '' ? $name : (string)$photoId,
      'articleFile' => $safeFileName,
      'wordCount' => $wordCount
    ];
  }
  return [
    'submissionCount' => $submissionCount,
    'wordCount' => $wordCountTotal,
    'photos' => $photos
  ];
}

function inviteePasswordDeleteDescribePhotoArticles(string $tasksDir, string $tagCode, array $picksForTask): void
{
  $taskDir = inviteePasswordBuildTaskDirPath($tasksDir, $tagCode);
  if ($taskDir === '' || !$picksForTask) {
    return;
  }
  $articlesDir = $taskDir . DIRECTORY_SEPARATOR . 'photos' . DIRECTORY_SEPARATOR . 'articles';
  if (!is_dir($articlesDir)) {
    return;
  }
  $articlesReal = realpath($articlesDir);
  if (!is_string($articlesReal) || $articlesReal === '') {
    return;
  }
  foreach ($picksForTask as $fileName) {
    $safeFileName = basename(trim((string)$fileName));
    if ($safeFileName === '') {
      continue;
    }
    $targetPath = $articlesDir . DIRECTORY_SEPARATOR . $safeFileName;
    $targetReal = egmDbIsFile($targetPath) ? realpath($targetPath) : false;
    if (!is_string($targetReal) || strpos($targetReal, $articlesReal . DIRECTORY_SEPARATOR) !== 0) {
      continue;
    }
    @egmDbUnlink($targetReal);
  }
}

function inviteePasswordRemoveWorkIdFromTeamRuntime(string $tasksDir, string $tagCode, string $workId): bool
{
  $taskDir = inviteePasswordBuildTaskDirPath($tasksDir, $tagCode);
  if ($taskDir === '' || trim($workId) === '') {
    return true;
  }
  $runtimePath = $taskDir . DIRECTORY_SEPARATOR . 'team-runtime.json';
  $runtime = inviteePasswordReadJsonPayload($runtimePath);
  $teams = is_array($runtime['teams'] ?? null) ? $runtime['teams'] : [];
  if (!$teams) {
    return true;
  }
  $target = trim($workId);
  $changed = false;
  $nextTeams = [];
  foreach ($teams as $team) {
    if (!is_array($team)) {
      continue;
    }
    foreach (['members', 'invites', 'requests'] as $key) {
      $items = [];
      foreach ((array)($team[$key] ?? []) as $item) {
        $token = trim((string)$item);
        if ($token === '' || $token === $target) {
          if ($token === $target) {
            $changed = true;
          }
          continue;
        }
        if (!in_array($token, $items, true)) {
          $items[] = $token;
        }
      }
      $team[$key] = $items;
    }
    $members = is_array($team['members'] ?? null) ? $team['members'] : [];
    if (!$members) {
      $changed = true;
      continue;
    }
    if (trim((string)($team['leaderWorkId'] ?? '')) === $target || !in_array(trim((string)($team['leaderWorkId'] ?? '')), $members, true)) {
      $team['leaderWorkId'] = $members[0];
      $changed = true;
    }
    $nextTeams[] = $team;
  }
  if (!$changed) {
    return true;
  }
  $runtime['teams'] = array_values($nextTeams);
  return inviteePasswordWriteJsonPayload($runtimePath, $runtime);
}

function inviteePasswordUpdateInfoScoreStore(string $tasksDir, string $tagCode, string $workId): bool
{
  $taskDir = inviteePasswordBuildTaskDirPath($tasksDir, $tagCode);
  if ($taskDir === '' || trim($workId) === '') {
    return true;
  }
  $path = $taskDir . DIRECTORY_SEPARATOR . 'info-task-scores.json';
  $scores = inviteePasswordReadJsonPayload($path);
  if (!$scores || !array_key_exists($workId, $scores)) {
    return true;
  }
  unset($scores[$workId]);
  return inviteePasswordWriteJsonPayload($path, $scores);
}

function inviteePasswordSetInfoScoreStore(string $tasksDir, string $tagCode, string $workId, int $score): bool
{
  $taskDir = inviteePasswordBuildTaskDirPath($tasksDir, $tagCode);
  if ($taskDir === '' || trim($workId) === '') {
    return true;
  }
  $path = $taskDir . DIRECTORY_SEPARATOR . 'info-task-scores.json';
  $scores = inviteePasswordReadJsonPayload($path);
  $scores[trim($workId)] = max(0, $score);
  return inviteePasswordWriteJsonPayload($path, $scores);
}

function inviteePasswordTaskDateTimeTimestamp(array $task, string $dateKey, string $timeKey): ?int
{
  $date = trim((string)($task[$dateKey] ?? ''));
  if ($date === '') {
    return null;
  }
  $time = trim((string)($task[$timeKey] ?? ''));
  if ($time === '') {
    $time = '00:00:00';
  }
  $timestamp = strtotime(trim($date . ' ' . $time));
  return is_int($timestamp) ? $timestamp : null;
}

function inviteePasswordTaskCurrentStatus(array $task): string
{
  if (!inviteePasswordNormalizeBoolValue($task['active'] ?? true)) {
    return 'inactive';
  }
  if (!inviteePasswordNormalizeBoolValue($task['duration'] ?? false)) {
    return 'active';
  }
  $now = time();
  $startAt = inviteePasswordTaskDateTimeTimestamp($task, 'startDate', 'startTime');
  $endAt = inviteePasswordTaskDateTimeTimestamp($task, 'endDate', 'endTime');
  if ($startAt !== null && $now < $startAt) {
    return 'upcoming';
  }
  if ($endAt !== null && $now > $endAt) {
    return 'ended';
  }
  return 'active';
}

function inviteePasswordResolveConditionalQuizStats(array $task, int $score, array $answers): array
{
  $answerCount = max(0, (int)($answers['count'] ?? 0));
  $correctCount = max(0, (int)($answers['correctCount'] ?? 0));
  $activeScore = inviteePasswordNormalizeScoreValue($task['score'] ?? 0);
  $afterEndScore = inviteePasswordNormalizeScoreValue($task['afterEndtimeScore'] ?? 0);
  $currentStatus = inviteePasswordTaskCurrentStatus($task);

  $perCorrectScore = 0;
  $scoreWindow = '';
  $scoreWindowLabel = '';
  $inferred = false;

  if ($correctCount > 0) {
    $activeExpected = $activeScore * $correctCount;
    $afterExpected = $afterEndScore * $correctCount;
    if ($activeScore > 0 && $score === $activeExpected) {
      $perCorrectScore = $activeScore;
      $scoreWindow = 'golden';
      $scoreWindowLabel = 'Golden time';
    } elseif ($afterEndScore > 0 && $score === $afterExpected) {
      $perCorrectScore = $afterEndScore;
      $scoreWindow = 'after_endtime';
      $scoreWindowLabel = 'After golden time';
    } elseif ($score > 0 && $score % $correctCount === 0) {
      $perCorrectScore = max(0, intdiv($score, $correctCount));
      $inferred = true;
      if ($perCorrectScore === $activeScore && $activeScore > 0) {
        $scoreWindow = 'golden';
        $scoreWindowLabel = 'Golden time';
      } elseif ($perCorrectScore === $afterEndScore && $afterEndScore > 0) {
        $scoreWindow = 'after_endtime';
        $scoreWindowLabel = 'After golden time';
      } else {
        $scoreWindow = 'stored';
        $scoreWindowLabel = 'Stored score';
      }
    }
  }

  if ($perCorrectScore <= 0) {
    if ($currentStatus === 'ended') {
      $perCorrectScore = $afterEndScore;
      $scoreWindow = 'after_endtime';
      $scoreWindowLabel = 'After golden time';
    } else {
      $perCorrectScore = $activeScore;
      $scoreWindow = 'golden';
      $scoreWindowLabel = 'Golden time';
    }
  }

  $possibleScore = max(0, $answerCount * $perCorrectScore);
  $correctAnswerScore = max(0, $correctCount * $perCorrectScore);
  return [
    'answerCount' => $answerCount,
    'correctCount' => $correctCount,
    'perCorrectScore' => $perCorrectScore,
    'possibleScore' => $possibleScore,
    'correctAnswerScore' => $correctAnswerScore,
    'scoreWindow' => $scoreWindow,
    'scoreWindowLabel' => $scoreWindowLabel,
    'inferred' => $inferred
  ];
}

function inviteePasswordBuildTaskParticipationItem(
  array $task,
  string $workId,
  string $tasksDir,
  array $completedIds,
  array $taskScoreMap,
  array $infoMap,
  array $teamMap,
  array $describeMap,
  array $describePicksMap
): array {
  $taskId = trim((string)($task['id'] ?? ''));
  $taskType = inviteePasswordNormalizeTaskType((string)($task['taskType'] ?? 'quiz'));
  $score = 0;
  $possibleScore = inviteePasswordNormalizeScoreValue($task['score'] ?? 0);
  $conditionalStats = null;
  $details = [];
  $answers = ['count' => 0, 'correctCount' => 0, 'answers' => []];
  $completed = in_array($taskId, $completedIds, true);
  $started = false;

  if ($taskType === 'team_task') {
    $entry = is_array($teamMap[$taskId] ?? null) ? $teamMap[$taskId] : [];
    $score = inviteePasswordNormalizeScoreValue($entry['score'] ?? 0);
    $teamName = trim((string)($entry['teamName'] ?? ''));
    $status = trim((string)($entry['status'] ?? ''));
    if ($teamName !== '') {
      $details[] = 'Team: ' . $teamName;
    }
    if ($status !== '') {
      $details[] = 'Team status: ' . $status;
      $started = true;
    }
    $completed = $completed || $score > 0;
  } elseif ($taskType === 'info') {
    if (array_key_exists($taskId, $infoMap)) {
      $score = inviteePasswordNormalizeScoreValue($infoMap[$taskId] ?? 0);
      $completed = true;
      $started = true;
      $details[] = 'Admin score assigned';
    }
  } elseif ($taskType === 'describe_photo') {
    if (array_key_exists($taskId, $describeMap)) {
      $score = inviteePasswordNormalizeScoreValue($describeMap[$taskId] ?? 0);
      $completed = true;
      $started = true;
      $details[] = 'Admin score assigned';
    }
    $picks = is_array($describePicksMap[$taskId] ?? null) ? $describePicksMap[$taskId] : [];
    $photoStats = inviteePasswordDescribePhotoArticleStats($tasksDir, $task, $picks);
    if ((int)($photoStats['submissionCount'] ?? 0) > 0) {
      $started = true;
      $details[] = 'Submitted photos: ' . (string)(int)$photoStats['submissionCount'];
      $details[] = 'Words: ' . (string)(int)$photoStats['wordCount'];
    } elseif ($picks) {
      $started = true;
      $details[] = 'Photos assigned: ' . (string)count($picks);
    }
    $task['describePhotoStats'] = $photoStats;
  } else {
    if (array_key_exists($taskId, $taskScoreMap)) {
      $score = inviteePasswordNormalizeScoreValue($taskScoreMap[$taskId] ?? 0);
      $completed = true;
    }
    $answers = inviteePasswordReadTaskAnswers($tasksDir, $task, $workId);
    if ((int)($answers['count'] ?? 0) > 0) {
      $started = true;
      if ($taskType !== 'conditional_quiz') {
        $details[] = 'Answered questions: ' . (string)(int)$answers['count'];
      }
      if ($taskType !== 'conditional_quiz' && (int)($answers['correctCount'] ?? 0) > 0) {
        $details[] = 'Correct answers: ' . (string)(int)$answers['correctCount'];
      }
    }
    if ($taskType === 'conditional_quiz') {
      $conditionalStats = inviteePasswordResolveConditionalQuizStats($task, $score, $answers);
      $possibleScore = (int)($conditionalStats['possibleScore'] ?? 0);
      $answerCount = (int)($conditionalStats['answerCount'] ?? 0);
      $correctCount = (int)($conditionalStats['correctCount'] ?? 0);
      $perCorrectScore = (int)($conditionalStats['perCorrectScore'] ?? 0);
      $correctAnswerScore = (int)($conditionalStats['correctAnswerScore'] ?? 0);
      if ($answerCount > 0) {
        $details[] = 'Questions shown: ' . (string)$answerCount;
        $details[] = 'Correct answers: ' . (string)$correctCount . ' / ' . (string)$answerCount;
      }
      $scoreWindowLabel = trim((string)($conditionalStats['scoreWindowLabel'] ?? ''));
      if ($scoreWindowLabel !== '') {
        $details[] = 'Score window: ' . $scoreWindowLabel;
      }
      if ($perCorrectScore > 0) {
        $details[] = 'Score per correct answer: ' . (string)$perCorrectScore;
        $details[] = 'Correct answer score: ' . (string)$correctAnswerScore;
      }
    }
  }

  if ($score > 0) {
    $completed = true;
  }
  if ($completed) {
    $started = true;
  }
  $status = $completed ? 'completed' : ($started ? 'started' : 'not_started');
  $statusLabel = $completed ? 'Completed' : ($started ? 'Started' : 'Not started');

  return [
    'id' => $taskId,
    'title' => trim((string)($task['title'] ?? 'Untitled Task')),
    'tagCode' => trim((string)($task['tagCode'] ?? '')),
    'taskType' => $taskType,
    'typeLabel' => inviteePasswordTaskTypeLabel($taskType),
    'configuredScore' => inviteePasswordNormalizeScoreValue($task['score'] ?? 0),
    'afterEndtimeScore' => inviteePasswordNormalizeScoreValue($task['afterEndtimeScore'] ?? 0),
    'possibleScore' => $possibleScore,
    'scoreLabel' => (string)$score . ' / ' . (string)$possibleScore,
    'score' => $score,
    'completed' => $completed,
    'started' => $started,
    'status' => $status,
    'statusLabel' => $statusLabel,
    'details' => $details,
    'answers' => $answers['answers'] ?? [],
    'answerCount' => (int)($answers['count'] ?? 0),
    'correctAnswerCount' => (int)($answers['correctCount'] ?? 0),
    'conditionalQuizStats' => $conditionalStats,
    'describePhotoStats' => is_array($task['describePhotoStats'] ?? null) ? $task['describePhotoStats'] : null
  ];
}

function inviteePasswordCellValue(array $row, array $header, array $names): string
{
  $index = inviteePasswordFindHeaderIndexByNames($header, $names);
  return $index >= 0 ? trim((string)($row[$index] ?? '')) : '';
}

function inviteePasswordBuildParticipationPayload(
  array $rows,
  int $rowIndex,
  array $header,
  string $mappingPath,
  string $tasksPath,
  string $tasksDir
): array {
  $row = is_array($rows[$rowIndex] ?? null) ? $rows[$rowIndex] : [];
  $mapping = inviteePasswordReadMappedConfig($mappingPath);
  $workIdIndex = inviteePasswordResolveMappedColumnIndex($header, $mapping, ['workId', 'work_id', 'username'], ['Work ID', 'work id', 'workid', 'username']);
  $firstNameIndex = inviteePasswordResolveMappedColumnIndex($header, $mapping, ['firstName', 'first_name'], ['First Name', 'first name', 'firstname', 'name']);
  $lastNameIndex = inviteePasswordResolveMappedColumnIndex($header, $mapping, ['lastName', 'last_name'], ['Last Name', 'last name', 'lastname', 'family', 'surname']);
  $nationalIdIndex = inviteePasswordResolveMappedColumnIndex($header, $mapping, ['nationalId', 'national_id'], ['National ID', 'national id', 'nationalid']);
  $phoneIndex = inviteePasswordResolveMappedColumnIndex($header, $mapping, ['phoneNumber', 'phone_number', 'phone'], ['Phone Number', 'phone number', 'phone', 'mobile']);

  $workId = $workIdIndex >= 0 ? trim((string)($row[$workIdIndex] ?? '')) : '';
  $firstName = $firstNameIndex >= 0 ? trim((string)($row[$firstNameIndex] ?? '')) : '';
  $lastName = $lastNameIndex >= 0 ? trim((string)($row[$lastNameIndex] ?? '')) : '';
  $displayName = trim($firstName . ' ' . $lastName);
  if ($displayName === '') {
    $displayName = $workId;
  }

  $completedIds = inviteePasswordParseList(inviteePasswordCellValue($row, $header, ['task completed ids', 'task completed id', 'task completed']));
  $taskScoreMap = inviteePasswordParseTaskScoreMap(inviteePasswordCellValue($row, $header, ['task score map']));
  $infoMap = inviteePasswordParseInfoTaskMap(inviteePasswordCellValue($row, $header, ['info tasks']));
  $teamMap = inviteePasswordParseTeamTaskMap(inviteePasswordCellValue($row, $header, ['team task']));
  $describeMap = inviteePasswordParseInfoTaskMap(inviteePasswordCellValue($row, $header, ['describe photo task']));
  $describePicksMap = inviteePasswordParseDescribePhotoPicksMap(inviteePasswordCellValue($row, $header, ['describe photo picks']));

  $tasks = inviteePasswordReadTasks($tasksPath, $tasksDir);
  $knownTaskIds = [];
  $taskItems = [];
  foreach ($tasks as $task) {
    $taskId = trim((string)($task['id'] ?? ''));
    if ($taskId === '') {
      continue;
    }
    $knownTaskIds[$taskId] = true;
    $taskItems[] = inviteePasswordBuildTaskParticipationItem(
      $task,
      $workId,
      $tasksDir,
      $completedIds,
      $taskScoreMap,
      $infoMap,
      $teamMap,
      $describeMap,
      $describePicksMap
    );
  }

  $appendUnknownTask = static function (string $taskId, string $taskType, int $score = 0) use (&$knownTaskIds, &$taskItems, $workId, $tasksDir, $completedIds, $taskScoreMap, $infoMap, $teamMap, $describeMap, $describePicksMap): void {
    $id = trim($taskId);
    if ($id === '' || isset($knownTaskIds[$id])) {
      return;
    }
    $knownTaskIds[$id] = true;
    $taskItems[] = inviteePasswordBuildTaskParticipationItem(
      [
        'id' => $id,
        'title' => 'Unknown task (' . $id . ')',
        'tagCode' => '',
        'taskType' => $taskType,
        'score' => $score
      ],
      $workId,
      $tasksDir,
      $completedIds,
      $taskScoreMap,
      $infoMap,
      $teamMap,
      $describeMap,
      $describePicksMap
    );
  };
  foreach ($taskScoreMap as $taskId => $score) {
    $appendUnknownTask((string)$taskId, 'quiz', (int)$score);
  }
  foreach ($infoMap as $taskId => $score) {
    $appendUnknownTask((string)$taskId, 'info', (int)$score);
  }
  foreach ($teamMap as $taskId => $entry) {
    $appendUnknownTask((string)$taskId, 'team_task', is_array($entry) ? (int)($entry['score'] ?? 0) : 0);
  }
  foreach ($describeMap as $taskId => $score) {
    $appendUnknownTask((string)$taskId, 'describe_photo', (int)$score);
  }

  $completedCount = 0;
  $startedCount = 0;
  foreach ($taskItems as $item) {
    if (!empty($item['completed'])) {
      $completedCount += 1;
    }
    if (!empty($item['started'])) {
      $startedCount += 1;
    }
  }

  return [
    'invitee' => [
      'row' => $rowIndex + 1,
      'workId' => $workId,
      'firstName' => $firstName,
      'lastName' => $lastName,
      'displayName' => $displayName,
      'nationalId' => $nationalIdIndex >= 0 ? trim((string)($row[$nationalIdIndex] ?? '')) : '',
      'phoneNumber' => $phoneIndex >= 0 ? trim((string)($row[$phoneIndex] ?? '')) : ''
    ],
    'summary' => [
      'totalScore' => inviteePasswordNormalizeScoreValue(inviteePasswordCellValue($row, $header, ['score', 'total score'])),
      'completedTasks' => $completedCount,
      'startedTasks' => $startedCount,
      'taskCount' => count($taskItems),
      'loginCount' => inviteePasswordNormalizeScoreValue(inviteePasswordCellValue($row, $header, ['logins counts'])),
      'logins' => inviteePasswordCellValue($row, $header, ['logins']),
      'rollCount' => inviteePasswordNormalizeScoreValue(inviteePasswordCellValue($row, $header, ['count of rolls'])),
      'cardFlipsCount' => inviteePasswordNormalizeScoreValue(inviteePasswordCellValue($row, $header, ['card flips count'])),
      'prizeWon' => inviteePasswordCellValue($row, $header, ['prize won']),
      'prizeWonAt' => inviteePasswordCellValue($row, $header, ['prize won at']),
      'eachLevelWonPrize' => inviteePasswordCellValue($row, $header, ['each level won prize']),
      'totalPrizeWon' => inviteePasswordCellValue($row, $header, ['total prize won', 'مجموع جوایز برنده شده']),
      'outOfValueRewards' => inviteePasswordCellValue($row, $header, ['out of value rewards']),
      'legacyAnswered' => inviteePasswordCellValue($row, $header, ['answered']),
      'legacyAnswers' => inviteePasswordCellValue($row, $header, ['answers'])
    ],
    'tasks' => $taskItems
  ];
}

function inviteePasswordResetTaskProgressForRow(
  array &$rows,
  int $rowIndex,
  array $header,
  string $taskId,
  string $workId,
  array $tasks,
  string $tasksDir
): array {
  $normalizedTaskId = trim($taskId);
  if ($normalizedTaskId === '') {
    return ['ok' => false, 'message' => 'Invalid task id.'];
  }
  if ($rowIndex < 1 || !is_array($rows[$rowIndex] ?? null)) {
    return ['ok' => false, 'message' => 'Invitee row was not found.'];
  }
  $rowWidth = count($header);
  if (count($rows[$rowIndex]) < $rowWidth) {
    $rows[$rowIndex] = array_pad($rows[$rowIndex], $rowWidth, '');
  }

  $task = null;
  foreach ($tasks as $candidate) {
    if (is_array($candidate) && trim((string)($candidate['id'] ?? '')) === $normalizedTaskId) {
      $task = $candidate;
      break;
    }
  }
  $taskType = is_array($task) ? inviteePasswordNormalizeTaskType((string)($task['taskType'] ?? 'quiz')) : '';
  $tagCode = is_array($task) ? trim((string)($task['tagCode'] ?? '')) : '';

  $scoreIndex = inviteePasswordFindHeaderIndexByNames($header, ['score', 'total score']);
  $completedIndex = inviteePasswordFindHeaderIndexByNames($header, ['task completed ids', 'task completed id', 'task completed']);
  $taskScoreMapIndex = inviteePasswordFindHeaderIndexByNames($header, ['task score map']);
  $infoTasksIndex = inviteePasswordFindHeaderIndexByNames($header, ['info tasks']);
  $teamTaskIndex = inviteePasswordFindHeaderIndexByNames($header, ['team task']);
  $describeTaskIndex = inviteePasswordFindHeaderIndexByNames($header, ['describe photo task']);
  $describePicksIndex = inviteePasswordFindHeaderIndexByNames($header, ['describe photo picks']);

  $removedScore = 0;
  $changed = false;

  if ($completedIndex >= 0) {
    $completedIds = inviteePasswordParseList((string)($rows[$rowIndex][$completedIndex] ?? ''));
    $nextCompletedIds = array_values(array_filter($completedIds, static fn(string $id): bool => $id !== $normalizedTaskId));
    if (count($nextCompletedIds) !== count($completedIds)) {
      $rows[$rowIndex][$completedIndex] = inviteePasswordSerializeList($nextCompletedIds);
      $changed = true;
    }
  }

  if ($taskScoreMapIndex >= 0) {
    $taskScoreMap = inviteePasswordParseTaskScoreMap((string)($rows[$rowIndex][$taskScoreMapIndex] ?? ''));
    if (array_key_exists($normalizedTaskId, $taskScoreMap)) {
      $removedScore = max($removedScore, inviteePasswordNormalizeScoreValue($taskScoreMap[$normalizedTaskId] ?? 0));
      unset($taskScoreMap[$normalizedTaskId]);
      $rows[$rowIndex][$taskScoreMapIndex] = inviteePasswordSerializeTaskScoreMap($taskScoreMap);
      $changed = true;
    }
  }

  if ($infoTasksIndex >= 0) {
    $infoMap = inviteePasswordParseInfoTaskMap((string)($rows[$rowIndex][$infoTasksIndex] ?? ''));
    if (array_key_exists($normalizedTaskId, $infoMap)) {
      $removedScore = max($removedScore, inviteePasswordNormalizeScoreValue($infoMap[$normalizedTaskId] ?? 0));
      unset($infoMap[$normalizedTaskId]);
      $rows[$rowIndex][$infoTasksIndex] = inviteePasswordSerializeInfoTaskMap($infoMap);
      $changed = true;
    }
  }

  if ($teamTaskIndex >= 0) {
    $teamMap = inviteePasswordParseTeamTaskMap((string)($rows[$rowIndex][$teamTaskIndex] ?? ''));
    if (array_key_exists($normalizedTaskId, $teamMap)) {
      $entry = is_array($teamMap[$normalizedTaskId] ?? null) ? $teamMap[$normalizedTaskId] : [];
      $removedScore = max($removedScore, inviteePasswordNormalizeScoreValue($entry['score'] ?? 0));
      unset($teamMap[$normalizedTaskId]);
      $rows[$rowIndex][$teamTaskIndex] = inviteePasswordSerializeTeamTaskMap($teamMap);
      $taskType = $taskType !== '' ? $taskType : 'team_task';
      $changed = true;
    }
  }

  $picksForTask = [];
  if ($describePicksIndex >= 0) {
    $picksMap = inviteePasswordParseDescribePhotoPicksMap((string)($rows[$rowIndex][$describePicksIndex] ?? ''));
    if (is_array($picksMap[$normalizedTaskId] ?? null)) {
      $picksForTask = $picksMap[$normalizedTaskId];
      unset($picksMap[$normalizedTaskId]);
      $rows[$rowIndex][$describePicksIndex] = inviteePasswordSerializeDescribePhotoPicksMap($picksMap);
      $taskType = $taskType !== '' ? $taskType : 'describe_photo';
      $changed = true;
    }
  }

  if ($describeTaskIndex >= 0) {
    $describeMap = inviteePasswordParseInfoTaskMap((string)($rows[$rowIndex][$describeTaskIndex] ?? ''));
    if (array_key_exists($normalizedTaskId, $describeMap)) {
      $removedScore = max($removedScore, inviteePasswordNormalizeScoreValue($describeMap[$normalizedTaskId] ?? 0));
      unset($describeMap[$normalizedTaskId]);
      $rows[$rowIndex][$describeTaskIndex] = inviteePasswordSerializeInfoTaskMap($describeMap);
      $taskType = $taskType !== '' ? $taskType : 'describe_photo';
      $changed = true;
    }
  }

  if ($scoreIndex >= 0 && $removedScore > 0) {
    $currentScore = inviteePasswordNormalizeScoreValue($rows[$rowIndex][$scoreIndex] ?? 0);
    $rows[$rowIndex][$scoreIndex] = (string)max(0, $currentScore - $removedScore);
    $changed = true;
  }

  if ($tagCode !== '') {
    inviteePasswordRemoveTaskAnswersRow($tasksDir, $tagCode, $workId);
    if (in_array($taskType, ['info', 'team_task', 'describe_photo'], true)) {
      inviteePasswordUpdateInfoScoreStore($tasksDir, $tagCode, $workId);
    }
    if ($taskType === 'describe_photo' && $picksForTask) {
      inviteePasswordDeleteDescribePhotoArticles($tasksDir, $tagCode, $picksForTask);
    }
    if ($taskType === 'team_task') {
      inviteePasswordRemoveWorkIdFromTeamRuntime($tasksDir, $tagCode, $workId);
    }
  }

  return [
    'ok' => true,
    'changed' => $changed,
    'removedScore' => $removedScore,
    'message' => 'Task progress reset successfully.'
  ];
}

function inviteePasswordFindTaskById(array $tasks, string $taskId): ?array
{
  $normalizedTaskId = trim($taskId);
  if ($normalizedTaskId === '') {
    return null;
  }
  foreach ($tasks as $task) {
    if (!is_array($task)) {
      continue;
    }
    if (trim((string)($task['id'] ?? '')) === $normalizedTaskId) {
      return $task;
    }
  }
  return null;
}

function inviteePasswordTaskScoreForRow(array $row, array $header, string $taskId): int
{
  $normalizedTaskId = trim($taskId);
  if ($normalizedTaskId === '') {
    return 0;
  }
  $taskScoreMapIndex = inviteePasswordFindHeaderIndexByNames($header, ['task score map']);
  $infoTasksIndex = inviteePasswordFindHeaderIndexByNames($header, ['info tasks']);
  $teamTaskIndex = inviteePasswordFindHeaderIndexByNames($header, ['team task']);
  $describeTaskIndex = inviteePasswordFindHeaderIndexByNames($header, ['describe photo task']);
  $taskScoreMap = $taskScoreMapIndex >= 0
    ? inviteePasswordParseTaskScoreMap((string)($row[$taskScoreMapIndex] ?? ''))
    : [];
  $infoMap = $infoTasksIndex >= 0
    ? inviteePasswordParseInfoTaskMap((string)($row[$infoTasksIndex] ?? ''))
    : [];
  $teamMap = $teamTaskIndex >= 0
    ? inviteePasswordParseTeamTaskMap((string)($row[$teamTaskIndex] ?? ''))
    : [];
  $describeMap = $describeTaskIndex >= 0
    ? inviteePasswordParseInfoTaskMap((string)($row[$describeTaskIndex] ?? ''))
    : [];
  $score = 0;
  foreach ([
    $taskScoreMap[$normalizedTaskId] ?? null,
    $infoMap[$normalizedTaskId] ?? null,
    is_array($teamMap[$normalizedTaskId] ?? null) ? ($teamMap[$normalizedTaskId]['score'] ?? null) : null,
    $describeMap[$normalizedTaskId] ?? null
  ] as $candidate) {
    if ($candidate !== null) {
      $score = max($score, inviteePasswordNormalizeScoreValue($candidate));
    }
  }
  return $score;
}

function inviteePasswordReachedTaskOrder(array $row, array $header, array $tasks): int
{
  $completedIds = inviteePasswordParseList(inviteePasswordCellValue($row, $header, ['task completed ids', 'task completed id', 'task completed']));
  $taskScoreMap = inviteePasswordParseTaskScoreMap(inviteePasswordCellValue($row, $header, ['task score map']));
  $infoMap = inviteePasswordParseInfoTaskMap(inviteePasswordCellValue($row, $header, ['info tasks']));
  $teamMap = inviteePasswordParseTeamTaskMap(inviteePasswordCellValue($row, $header, ['team task']));
  $describeMap = inviteePasswordParseInfoTaskMap(inviteePasswordCellValue($row, $header, ['describe photo task']));
  $reachedIds = [];
  foreach ($completedIds as $taskId) {
    $reachedIds[trim((string)$taskId)] = true;
  }
  foreach ([$taskScoreMap, $infoMap, $describeMap] as $map) {
    foreach ($map as $taskId => $_score) {
      $reachedIds[trim((string)$taskId)] = true;
    }
  }
  foreach ($teamMap as $taskId => $entry) {
    if (is_array($entry) && inviteePasswordNormalizeScoreValue($entry['score'] ?? 0) > 0) {
      $reachedIds[trim((string)$taskId)] = true;
    }
  }

  $orderById = [];
  foreach ($tasks as $index => $task) {
    $taskId = trim((string)($task['id'] ?? ''));
    if ($taskId !== '') {
      $orderById[$taskId] = max(1, (int)($task['order'] ?? ($index + 1)));
    }
  }
  $reachedOrder = 0;
  foreach (array_keys($reachedIds) as $taskId) {
    if ($taskId === '') {
      continue;
    }
    if (isset($orderById[$taskId])) {
      $reachedOrder = max($reachedOrder, $orderById[$taskId]);
    } elseif (preg_match('/(\d+)(?!.*\d)/', $taskId, $matches)) {
      $reachedOrder = max($reachedOrder, (int)($matches[1] ?? 0));
    }
  }
  return $reachedOrder;
}

function inviteePasswordSaveTaskScoreForRow(
  array &$rows,
  int $rowIndex,
  array $header,
  string $taskId,
  int $newScore,
  string $workId,
  array $tasks,
  string $tasksDir
): array {
  $normalizedTaskId = trim($taskId);
  if ($normalizedTaskId === '') {
    return ['ok' => false, 'message' => 'Invalid task id.'];
  }
  if ($rowIndex < 1 || !is_array($rows[$rowIndex] ?? null)) {
    return ['ok' => false, 'message' => 'Invitee row was not found.'];
  }
  $rowWidth = count($header);
  if (count($rows[$rowIndex]) < $rowWidth) {
    $rows[$rowIndex] = array_pad($rows[$rowIndex], $rowWidth, '');
  }

  $task = inviteePasswordFindTaskById($tasks, $normalizedTaskId);
  $scoreIndex = inviteePasswordFindHeaderIndexByNames($header, ['score', 'total score']);
  $completedIndex = inviteePasswordFindHeaderIndexByNames($header, ['task completed ids', 'task completed id', 'task completed']);
  $taskScoreMapIndex = inviteePasswordFindHeaderIndexByNames($header, ['task score map']);
  $infoTasksIndex = inviteePasswordFindHeaderIndexByNames($header, ['info tasks']);
  $teamTaskIndex = inviteePasswordFindHeaderIndexByNames($header, ['team task']);
  $describeTaskIndex = inviteePasswordFindHeaderIndexByNames($header, ['describe photo task']);

  if ($scoreIndex < 0) {
    return ['ok' => false, 'message' => 'Total score column is not available.'];
  }

  $taskScoreMap = $taskScoreMapIndex >= 0
    ? inviteePasswordParseTaskScoreMap((string)($rows[$rowIndex][$taskScoreMapIndex] ?? ''))
    : [];
  $infoMap = $infoTasksIndex >= 0
    ? inviteePasswordParseInfoTaskMap((string)($rows[$rowIndex][$infoTasksIndex] ?? ''))
    : [];
  $teamMap = $teamTaskIndex >= 0
    ? inviteePasswordParseTeamTaskMap((string)($rows[$rowIndex][$teamTaskIndex] ?? ''))
    : [];
  $describeMap = $describeTaskIndex >= 0
    ? inviteePasswordParseInfoTaskMap((string)($rows[$rowIndex][$describeTaskIndex] ?? ''))
    : [];

  $taskType = is_array($task)
    ? inviteePasswordNormalizeTaskType((string)($task['taskType'] ?? 'quiz'))
    : '';
  if ($taskType === '') {
    if (array_key_exists($normalizedTaskId, $teamMap)) {
      $taskType = 'team_task';
    } elseif (array_key_exists($normalizedTaskId, $describeMap)) {
      $taskType = 'describe_photo';
    } elseif (array_key_exists($normalizedTaskId, $infoMap)) {
      $taskType = 'info';
    } else {
      $taskType = 'quiz';
    }
  }

  $previousScore = 0;
  foreach ([
    $taskScoreMap[$normalizedTaskId] ?? null,
    $infoMap[$normalizedTaskId] ?? null,
    is_array($teamMap[$normalizedTaskId] ?? null) ? ($teamMap[$normalizedTaskId]['score'] ?? null) : null,
    $describeMap[$normalizedTaskId] ?? null
  ] as $candidateScore) {
    if ($candidateScore !== null) {
      $previousScore = max($previousScore, inviteePasswordNormalizeScoreValue($candidateScore));
    }
  }

  unset($taskScoreMap[$normalizedTaskId], $infoMap[$normalizedTaskId], $describeMap[$normalizedTaskId]);
  $existingTeamEntry = is_array($teamMap[$normalizedTaskId] ?? null) ? $teamMap[$normalizedTaskId] : [];
  unset($teamMap[$normalizedTaskId]);

  if ($taskType === 'info') {
    if ($infoTasksIndex < 0) {
      return ['ok' => false, 'message' => 'Info task score column is not available.'];
    }
    $infoMap[$normalizedTaskId] = max(0, $newScore);
  } elseif ($taskType === 'team_task') {
    if ($teamTaskIndex < 0) {
      return ['ok' => false, 'message' => 'Team task score column is not available.'];
    }
    $teamName = trim((string)($existingTeamEntry['teamName'] ?? ''));
    $status = trim((string)($existingTeamEntry['status'] ?? ''));
    $teamMap[$normalizedTaskId] = [
      'teamName' => $teamName,
      'status' => $status !== '' ? $status : 'scored',
      'score' => max(0, $newScore)
    ];
  } elseif ($taskType === 'describe_photo') {
    if ($describeTaskIndex < 0) {
      return ['ok' => false, 'message' => 'Describe photo task score column is not available.'];
    }
    $describeMap[$normalizedTaskId] = max(0, $newScore);
  } else {
    if ($taskScoreMapIndex < 0) {
      return ['ok' => false, 'message' => 'Task score map column is not available.'];
    }
    $taskScoreMap[$normalizedTaskId] = max(0, $newScore);
  }

  if ($taskScoreMapIndex >= 0) {
    $rows[$rowIndex][$taskScoreMapIndex] = inviteePasswordSerializeTaskScoreMap($taskScoreMap);
  }
  if ($infoTasksIndex >= 0) {
    $rows[$rowIndex][$infoTasksIndex] = inviteePasswordSerializeInfoTaskMap($infoMap);
  }
  if ($teamTaskIndex >= 0) {
    $rows[$rowIndex][$teamTaskIndex] = inviteePasswordSerializeTeamTaskMap($teamMap);
  }
  if ($describeTaskIndex >= 0) {
    $rows[$rowIndex][$describeTaskIndex] = inviteePasswordSerializeInfoTaskMap($describeMap);
  }

  if ($completedIndex >= 0) {
    $completedIds = inviteePasswordParseList((string)($rows[$rowIndex][$completedIndex] ?? ''));
    if (!in_array($normalizedTaskId, $completedIds, true)) {
      $completedIds[] = $normalizedTaskId;
      $rows[$rowIndex][$completedIndex] = inviteePasswordSerializeList($completedIds);
    }
  }

  $currentTotalScore = inviteePasswordNormalizeScoreValue($rows[$rowIndex][$scoreIndex] ?? 0);
  $delta = max(0, $newScore) - $previousScore;
  $rows[$rowIndex][$scoreIndex] = (string)max(0, $currentTotalScore + $delta);

  $tagCode = is_array($task) ? trim((string)($task['tagCode'] ?? '')) : '';
  if ($tagCode !== '' && in_array($taskType, ['info', 'team_task', 'describe_photo'], true)) {
    if (!inviteePasswordSetInfoScoreStore($tasksDir, $tagCode, $workId, max(0, $newScore))) {
      return ['ok' => false, 'message' => 'Failed to save task score store.'];
    }
  }

  return [
    'ok' => true,
    'previousScore' => $previousScore,
    'score' => max(0, $newScore),
    'delta' => $delta,
    'message' => 'Task score updated successfully.'
  ];
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
  $all = is_array($_SESSION['egm_invite_password_unlock'] ?? null) ? $_SESSION['egm_invite_password_unlock'] : [];
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
  $all = is_array($_SESSION['egm_invite_password_unlock'] ?? null) ? $_SESSION['egm_invite_password_unlock'] : [];
  $all[$sessionKey] = $until;
  $_SESSION['egm_invite_password_unlock'] = $all;
  return $until;
}

$action = trim((string)($input['action'] ?? ''));
$sessionUserCode = trim((string)($egmInviteesPasswordSessionUser['code'] ?? ''));
if ($sessionUserCode === '') {
  echo json_encode(['status' => 'error', 'message' => 'Session user is not valid.']);
  exit;
}
$egmInviteesSensitiveAccess = egmInviteesSpecialAccessForPanelUser($egmInviteesPasswordSessionUser, __DIR__ . '/tasks/task-access.json');
$canRevealPassword = !empty($egmInviteesSensitiveAccess['revealPassword']);
$canResetInvitee = !empty($egmInviteesSensitiveAccess['resetInvitee']);
$canUseSensitiveAuth = $canRevealPassword || $canResetInvitee;
$isRevealAction = in_array($action, ['get_password', 'save_password'], true);
$isParticipationAction = in_array($action, ['get_participation', 'get_task_options', 'get_active_filter_preview'], true);
$isResetAction = in_array($action, ['reset_progress', 'reset_task_progress', 'save_task_score', 'bulk_save_task_score'], true);
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

$baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'EGM Event';
$mappedFile = $baseDir . DIRECTORY_SEPARATOR . 'Invitees mapped.csv';
$mapFile = $baseDir . DIRECTORY_SEPARATOR . 'EGM Mapped.json';
$tasksDir = __DIR__ . DIRECTORY_SEPARATOR . 'tasks';
$tasksFile = $tasksDir . DIRECTORY_SEPARATOR . 'tasks.js';

if ($action === 'verify_unlock') {
  $password = (string)($input['password'] ?? '');
  if (trim($password) === '') {
    echo json_encode(['status' => 'error', 'message' => 'Password is required.']);
    exit;
  }
  $config = loadConfig(__DIR__ . '/../../../api/config.php');
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

if (!$isParticipationAction && !inviteePasswordIsUnlocked($sessionUserCode)) {
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

if ($action === 'get_task_options') {
  $tasks = inviteePasswordReadTasks($tasksFile, $tasksDir);
  $options = [];
  foreach ($tasks as $task) {
    $taskId = trim((string)($task['id'] ?? ''));
    if ($taskId === '') {
      continue;
    }
    $options[] = [
      'id' => $taskId,
      'title' => trim((string)($task['title'] ?? 'Untitled Task')),
      'tagCode' => trim((string)($task['tagCode'] ?? '')),
      'taskType' => inviteePasswordNormalizeTaskType((string)($task['taskType'] ?? 'quiz')),
      'order' => max(1, (int)($task['order'] ?? (count($options) + 1)))
    ];
  }
  echo json_encode(['status' => 'ok', 'tasks' => $options], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'get_active_filter_preview') {
  $minimumOrder = max(0, (int)($input['minimum_order'] ?? ($input['minimumOrder'] ?? 1)));
  $conditionTaskId = trim((string)($input['condition_task_id'] ?? ($input['conditionTaskId'] ?? '')));
  $useNotScored = inviteePasswordNormalizeBoolValue($input['use_not_scored'] ?? ($input['useNotScored'] ?? false));
  $minimumLogins = max(0, (int)($input['minimum_logins'] ?? ($input['minimumLogins'] ?? 0)));
  $loginPercentage = (int)($input['login_percentage'] ?? ($input['loginPercentage'] ?? 0));
  $loginPercentage = $loginPercentage >= 1 && $loginPercentage <= 100 ? $loginPercentage : 0;
  $tasks = inviteePasswordReadTasks($tasksFile, $tasksDir);
  $reachedRows = [];
  $notScoredRows = [];
  $eligible = [];
  for ($index = 1; $index < count($rows); $index += 1) {
    $row = is_array($rows[$index] ?? null) ? $rows[$index] : [];
    $reachedOrder = inviteePasswordReachedTaskOrder($row, $header, $tasks);
    $matchesLevel = $minimumOrder === 0 ? $reachedOrder === 0 : $reachedOrder >= $minimumOrder;
    if (!$matchesLevel) {
      continue;
    }
    $rowNumber = $index + 1;
    $reachedRows[] = $rowNumber;
    if ($conditionTaskId !== '' && inviteePasswordTaskScoreForRow($row, $header, $conditionTaskId) <= 0) {
      $notScoredRows[] = $rowNumber;
    }
    $passesScoreCondition = !$useNotScored
      || ($conditionTaskId !== '' && inviteePasswordTaskScoreForRow($row, $header, $conditionTaskId) <= 0);
    $loginCount = inviteePasswordNormalizeScoreValue(inviteePasswordCellValue($row, $header, ['logins counts']));
    if ($passesScoreCondition && $loginCount >= $minimumLogins) {
      $eligible[] = [
        'row' => $rowNumber,
        'loginCount' => $loginCount
      ];
    }
  }
  usort($eligible, static function (array $left, array $right): int {
    $byLogins = ((int)($right['loginCount'] ?? 0)) <=> ((int)($left['loginCount'] ?? 0));
    return $byLogins !== 0 ? $byLogins : (((int)($left['row'] ?? 0)) <=> ((int)($right['row'] ?? 0)));
  });
  $minimumLoginsCount = count($eligible);
  if ($loginPercentage > 0 && $eligible) {
    $keepCount = max(1, (int)ceil(count($eligible) * ($loginPercentage / 100)));
    $eligible = array_slice($eligible, 0, $keepCount);
  }
  $filteredRows = array_map(static fn(array $item): int => (int)($item['row'] ?? 0), $eligible);
  echo json_encode([
    'status' => 'ok',
    'reachedRows' => $reachedRows,
    'notScoredRows' => $notScoredRows,
    'filteredRows' => $filteredRows,
    'reachedCount' => count($reachedRows),
    'notScoredCount' => count($notScoredRows),
    'minimumLoginsCount' => $minimumLoginsCount,
    'filteredCount' => count($filteredRows)
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'bulk_save_task_score') {
  $taskId = trim((string)($input['task_id'] ?? ($input['taskId'] ?? '')));
  $scoreRaw = trim((string)($input['score'] ?? ''));
  $condition = trim((string)($input['condition'] ?? 'none'));
  if (!in_array($condition, ['none', 'not_already_scored'], true)) {
    $condition = 'none';
  }
  $conditionTaskId = trim((string)($input['condition_task_id'] ?? ($input['conditionTaskId'] ?? '')));
  $requestedRows = is_array($input['rows'] ?? null) ? $input['rows'] : [];
  $rowNumbers = [];
  foreach ($requestedRows as $requestedRow) {
    $number = (int)$requestedRow;
    if ($number > 1 && $number <= count($rows)) {
      $rowNumbers[$number] = true;
    }
  }
  if ($taskId === '') {
    echo json_encode(['status' => 'error', 'message' => 'Select a mission.']);
    exit;
  }
  if ($scoreRaw === '' || !preg_match('/^\d+$/', $scoreRaw)) {
    echo json_encode(['status' => 'error', 'message' => 'Score must be a non-negative whole number.']);
    exit;
  }
  if (!$rowNumbers) {
    echo json_encode(['status' => 'error', 'message' => 'Select at least one invitee.']);
    exit;
  }
  if ($condition === 'not_already_scored' && $conditionTaskId === '') {
    echo json_encode(['status' => 'error', 'message' => 'Select the level or mission for the score condition.']);
    exit;
  }

  $mapping = inviteePasswordReadMappedConfig($mapFile);
  $workIdIndex = inviteePasswordResolveMappedColumnIndex(
    $header,
    $mapping,
    ['workId', 'work_id', 'username'],
    ['Work ID', 'work id', 'workid', 'username']
  );
  $tasks = inviteePasswordReadTasks($tasksFile, $tasksDir);
  $updatedCount = 0;
  $skippedCount = 0;
  foreach (array_keys($rowNumbers) as $number) {
    $index = $number - 1;
    if ($condition === 'not_already_scored'
      && inviteePasswordTaskScoreForRow((array)($rows[$index] ?? []), $header, $conditionTaskId) > 0) {
      $skippedCount += 1;
      continue;
    }
    $workId = $workIdIndex >= 0 ? trim((string)($rows[$index][$workIdIndex] ?? '')) : '';
    $result = inviteePasswordSaveTaskScoreForRow(
      $rows,
      $index,
      $header,
      $taskId,
      max(0, (int)$scoreRaw),
      $workId,
      $tasks,
      $tasksDir
    );
    if (!($result['ok'] ?? false)) {
      echo json_encode([
        'status' => 'error',
        'message' => 'Row ' . $number . ': ' . (string)($result['message'] ?? 'Failed to update mission score.')
      ], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $updatedCount += 1;
  }
  if ($updatedCount > 0 && !inviteePasswordWriteCsvRowsLocked($mappedFile, $rows)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to save mission scores.']);
    exit;
  }
  $message = 'Mission score updated for ' . $updatedCount . ' invitees.';
  if ($skippedCount > 0) {
    $message .= ' ' . $skippedCount . ' already-scored invitees skipped.';
  }
  echo json_encode([
    'status' => 'ok',
    'message' => $message,
    'updatedCount' => $updatedCount,
    'skippedCount' => $skippedCount
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

$rowNumber = (int)($input['row'] ?? 0);
$rowIndex = $rowNumber - 1;
if ($rowIndex < 1 || $rowIndex >= count($rows)) {
  echo json_encode(['status' => 'error', 'message' => 'Invitee row was not found.']);
  exit;
}

if ($isParticipationAction) {
  $participation = inviteePasswordBuildParticipationPayload(
    $rows,
    $rowIndex,
    $header,
    $mapFile,
    $tasksFile,
    $tasksDir
  );
  echo json_encode([
    'status' => 'ok',
    'participation' => $participation,
    'canResetTasks' => $canResetInvitee
  ], JSON_UNESCAPED_UNICODE);
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
  $passwordMapping = inviteePasswordReadMappedConfig($mapFile);
  $passwordWorkIdIndex = inviteePasswordResolveMappedColumnIndex(
    $header,
    $passwordMapping,
    ['workId', 'work_id', 'username'],
    ['Work ID', 'work id', 'workid', 'username']
  );
  $passwordWorkId = $passwordWorkIdIndex >= 0 ? trim((string)($rows[$rowIndex][$passwordWorkIdIndex] ?? '')) : '';
  if (!egmInstanceUpdateMissionUserPasswordUsingProjectConfig(dirname(__DIR__, 3), __DIR__, $passwordWorkId, $newPassword)) {
    error_log('Event Guest Manager could not immediately mirror an invitee password update to the database.');
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
    [['answers'], ''],
    [['score'], '0'],
    [['answered'], ''],
    [['task completed ids', 'task completed id', 'task completed'], ''],
    [['task score map'], ''],
    [['info tasks'], ''],
    [['describe photo task'], ''],
    [['team task'], '']
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
  echo json_encode([
    'status' => 'ok',
    'message' => 'Invitee task progress reset successfully. Prize history was preserved to prevent duplicate awards.'
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'reset_task_progress') {
  $taskId = trim((string)($input['task_id'] ?? ($input['taskId'] ?? '')));
  if ($taskId === '') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid task id.']);
    exit;
  }

  $mapping = inviteePasswordReadMappedConfig($mapFile);
  $workIdIndex = inviteePasswordResolveMappedColumnIndex(
    $header,
    $mapping,
    ['workId', 'work_id', 'username'],
    ['Work ID', 'work id', 'workid', 'username']
  );
  $workId = $workIdIndex >= 0 ? trim((string)($rows[$rowIndex][$workIdIndex] ?? '')) : '';
  $tasks = inviteePasswordReadTasks($tasksFile, $tasksDir);
  $resetResult = inviteePasswordResetTaskProgressForRow(
    $rows,
    $rowIndex,
    $header,
    $taskId,
    $workId,
    $tasks,
    $tasksDir
  );
  if (!($resetResult['ok'] ?? false)) {
    echo json_encode([
      'status' => 'error',
      'message' => (string)($resetResult['message'] ?? 'Failed to reset task progress.')
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }
  if (!inviteePasswordWriteCsvRowsLocked($mappedFile, $rows)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to reset task progress in mapped CSV.']);
    exit;
  }
  $participation = inviteePasswordBuildParticipationPayload(
    $rows,
    $rowIndex,
    is_array($rows[0] ?? null) ? $rows[0] : $header,
    $mapFile,
    $tasksFile,
    $tasksDir
  );
  echo json_encode([
    'status' => 'ok',
    'message' => (string)($resetResult['message'] ?? 'Task progress reset successfully.'),
    'removedScore' => (int)($resetResult['removedScore'] ?? 0),
    'participation' => $participation
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'save_task_score') {
  $taskId = trim((string)($input['task_id'] ?? ($input['taskId'] ?? '')));
  $scoreRaw = trim((string)($input['score'] ?? ''));
  if ($taskId === '') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid task id.']);
    exit;
  }
  if ($scoreRaw === '' || !preg_match('/^\d+$/', $scoreRaw)) {
    echo json_encode(['status' => 'error', 'message' => 'Score must be a non-negative whole number.']);
    exit;
  }

  $mapping = inviteePasswordReadMappedConfig($mapFile);
  $workIdIndex = inviteePasswordResolveMappedColumnIndex(
    $header,
    $mapping,
    ['workId', 'work_id', 'username'],
    ['Work ID', 'work id', 'workid', 'username']
  );
  $workId = $workIdIndex >= 0 ? trim((string)($rows[$rowIndex][$workIdIndex] ?? '')) : '';
  $tasks = inviteePasswordReadTasks($tasksFile, $tasksDir);
  $saveResult = inviteePasswordSaveTaskScoreForRow(
    $rows,
    $rowIndex,
    $header,
    $taskId,
    max(0, (int)$scoreRaw),
    $workId,
    $tasks,
    $tasksDir
  );
  if (!($saveResult['ok'] ?? false)) {
    echo json_encode([
      'status' => 'error',
      'message' => (string)($saveResult['message'] ?? 'Failed to update task score.')
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }
  if (!inviteePasswordWriteCsvRowsLocked($mappedFile, $rows)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to save task score in mapped CSV.']);
    exit;
  }
  $participation = inviteePasswordBuildParticipationPayload(
    $rows,
    $rowIndex,
    is_array($rows[0] ?? null) ? $rows[0] : $header,
    $mapFile,
    $tasksFile,
    $tasksDir
  );
  echo json_encode([
    'status' => 'ok',
    'message' => (string)($saveResult['message'] ?? 'Task score updated successfully.'),
    'previousScore' => (int)($saveResult['previousScore'] ?? 0),
    'score' => (int)($saveResult['score'] ?? 0),
    'delta' => (int)($saveResult['delta'] ?? 0),
    'participation' => $participation
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
