<?php
declare(strict_types=1);


require_once __DIR__ . '/egm-database-runtime.php';
require_once __DIR__ . '/../../../api/lib/tab-permissions.php';
require_once __DIR__ . '/egm-security.php';
require_once __DIR__ . '/invitees_csv_safety.php';
$tcqIsJsonRequest = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') && isset($_POST['tcq_action']);
$tcqSessionUser = requireTabPermissionFromSession('event-guest-manager', $tcqIsJsonRequest);
if (!userHasPermissionId($tcqSessionUser, 'event-guest-manager:manage-tasks')) {
  denyPanelAccess(403, 'You do not have permission to access this Event Guest Manager section.', $tcqIsJsonRequest);
}
$tcqCsrfToken = egmSecurityGetCsrfToken();

$tcqStorePath = __DIR__ . '/EGMQ list.json';
$tcqInviteesCsvPath = __DIR__ . '/EGM Event/Invitees mapped.csv';
$tcqAnswersCsvPath = __DIR__ . '/EGM Event/Answers.csv';
$tcqCodeStatePath = __DIR__ . '/EGMQ code state.json';
$tcqSettingsPath = __DIR__ . '/EGMQ settings.json';
$tcqTaskId = '';
$tcqTaskTagCode = '';
$tcqTaskTitle = '';
$tcqTaskType = 'quiz';
const EGMQ_DEFAULT_SETTINGS = [
  'answerTimeLimit' => true,
  'answerTimeLimitMs' => 14000,
  'randomOrder' => true,
  'questionsPerAttempt' => 0,
  'correctAnswersToScore' => 1
];

function tcqDefaultAnswerTimeLimitMs(bool $isConditionalQuizTask): int
{
  return $isConditionalQuizTask ? 30000 : (int)EGMQ_DEFAULT_SETTINGS['answerTimeLimitMs'];
}

function tcqNormalizeAnswerTimeLimitMs($value, int $fallback): int
{
  if (!is_scalar($value)) {
    return $fallback;
  }
  $parsed = (int)$value;
  if ($parsed < 1000) {
    return $fallback;
  }
  return min($parsed, 600000);
}

function tcqReadCsv(string $path): array
{
  if (egmInviteesCsvIsManagedPath($path)) {
    return egmInviteesCsvReadRowsForUpdate($path);
  }
  if (!egmDbIsFile($path)) {
    return [];
  }
  $rows = [];
  $handle = egmDbFopen($path, 'r');
  if ($handle === false) {
    return [];
  }
  if (!flock($handle, LOCK_SH)) {
    fclose($handle);
    return [];
  }
  while (($row = fgetcsv($handle)) !== false) {
    $rows[] = $row;
  }
  flock($handle, LOCK_UN);
  fclose($handle);
  return $rows;
}

function tcqWriteCsv(string $path, array $rows): bool
{
  if (egmInviteesCsvIsManagedPath($path)) {
    return egmInviteesCsvCommitRows($path, $rows);
  }
  $dir = dirname($path);
  if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
  }
  $handle = egmDbFopen($path, 'c+');
  if ($handle === false) {
    return false;
  }
  if (!flock($handle, LOCK_EX)) {
    fclose($handle);
    return false;
  }
  if (!ftruncate($handle, 0) || rewind($handle) === false) {
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

function tcqNormalizeHeader(string $value): string
{
  $value = trim(mb_strtolower($value, 'UTF-8'));
  $value = preg_replace('/\s+/', ' ', $value);
  return $value ?? '';
}

function tcqFindHeaderIndex(array $header, string $name): int
{
  $needle = tcqNormalizeHeader($name);
  foreach ($header as $index => $value) {
    if (tcqNormalizeHeader((string)$value) === $needle) {
      return (int)$index;
    }
  }
  return -1;
}

function tcqEnsureInviteesColumns(string $path): bool
{
  $rows = tcqReadCsv($path);
  if (!$rows || !isset($rows[0]) || !is_array($rows[0])) {
    return false;
  }
  $header = $rows[0];
  $required = ['count of rolls', 'invitees', 'prize won', 'answers', 'score', 'Answered'];
  $changed = false;
  foreach ($required as $columnName) {
    $idx = tcqFindHeaderIndex($header, $columnName);
    if ($idx >= 0) {
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
    egmInviteesCsvEndTransaction($path);
    return true;
  }
  $rows[0] = $header;
  return tcqWriteCsv($path, $rows);
}

function tcqNormalizeScoreValue($value): int
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

function tcqNormalizeItem(array $item, bool $includeQuestionScores = true): array
{
  $type = trim(mb_strtolower((string)($item['type'] ?? 'mcq'), 'UTF-8'));
  if ($type !== 'percentage') {
    $type = 'mcq';
  }
  $answers = is_array($item['answers'] ?? null) ? array_values($item['answers']) : [];
  while (count($answers) < 4) {
    $answers[] = '';
  }
  $answers = array_slice($answers, 0, 4);
  if ($type === 'percentage') {
    $answers = ['', '', '', ''];
  }
  return [
    'id' => trim((string)($item['id'] ?? '')) ?: ('q_' . bin2hex(random_bytes(6))),
    'code' => trim((string)($item['code'] ?? '')),
    'type' => $type,
    'question' => trim((string)($item['question'] ?? '')),
    'answers' => array_map(static fn($v) => trim((string)$v), $answers),
    'activeDurationScore' => $includeQuestionScores ? tcqNormalizeScoreValue($item['activeDurationScore'] ?? ($item['active_duration_score'] ?? ($item['score'] ?? 0))) : 0,
    'goldenTimeEndedScore' => $includeQuestionScores ? tcqNormalizeScoreValue($item['goldenTimeEndedScore'] ?? ($item['golden_time_ended_score'] ?? ($item['afterEndtimeScore'] ?? ($item['after_endtime_score'] ?? 0)))) : 0,
    'createdAt' => trim((string)($item['createdAt'] ?? '')) ?: date('Y-m-d H:i:s')
  ];
}

function tcqLoadStore(string $path, bool $includeQuestionScores = true): array
{
  if (!egmDbIsFile($path)) {
    return [];
  }
  $content = egmDbFileGetContents($path);
  if ($content === false) {
    return [];
  }
  $decoded = json_decode($content, true);
  if (!is_array($decoded)) {
    return [];
  }
  $items = [];
  foreach ($decoded as $row) {
    if (is_array($row)) {
      $items[] = tcqNormalizeItem($row, $includeQuestionScores);
    }
  }
  return $items;
}

function tcqSaveStore(string $path, array $rows): bool
{
  $dir = dirname($path);
  if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
  }
  $json = json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($json === false) {
    return false;
  }
  return egmDbFilePutContents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

function tcqLoadSettings(string $path, int $defaultAnswerTimeLimitMs = 14000): array
{
  $settings = EGMQ_DEFAULT_SETTINGS;
  $settings['answerTimeLimitMs'] = $defaultAnswerTimeLimitMs;
  if (!egmDbIsFile($path)) {
    return $settings;
  }
  $content = egmDbFileGetContents($path);
  if ($content === false) {
    return $settings;
  }
  $decoded = json_decode($content, true);
  if (!is_array($decoded)) {
    return $settings;
  }
  $storedCorrectAnswersToScore = max(0, (int)($decoded['correctAnswersToScore'] ?? $settings['correctAnswersToScore']));
  $settings['answerTimeLimit'] = (bool)($decoded['answerTimeLimit'] ?? $settings['answerTimeLimit']);
  $settings['answerTimeLimitMs'] = tcqNormalizeAnswerTimeLimitMs($decoded['answerTimeLimitMs'] ?? ($decoded['answer_time_limit_ms'] ?? $settings['answerTimeLimitMs']), $defaultAnswerTimeLimitMs);
  $settings['randomOrder'] = (bool)($decoded['randomOrder'] ?? $settings['randomOrder']);
  $settings['questionsPerAttempt'] = max(0, (int)($decoded['questionsPerAttempt'] ?? $settings['questionsPerAttempt']));
  $settings['correctAnswersToScore'] = $storedCorrectAnswersToScore > 0
    ? $storedCorrectAnswersToScore
    : (int)$settings['correctAnswersToScore'];
  return $settings;
}

function tcqSaveSettings(string $path, array $settings, bool $includeCorrectAnswersToScore = true): bool
{
  $dir = dirname($path);
  if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
  }
  $payload = [
    'answerTimeLimit' => (bool)($settings['answerTimeLimit'] ?? true),
    'answerTimeLimitMs' => tcqNormalizeAnswerTimeLimitMs($settings['answerTimeLimitMs'] ?? 14000, 14000),
    'randomOrder' => (bool)($settings['randomOrder'] ?? true),
    'questionsPerAttempt' => max(0, (int)($settings['questionsPerAttempt'] ?? 0))
  ];
  if ($includeCorrectAnswersToScore) {
    $payload['correctAnswersToScore'] = max(1, (int)($settings['correctAnswersToScore'] ?? 1));
  }
  $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($json === false) {
    return false;
  }
  return egmDbFilePutContents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

function tcqSettingsForTaskType(array $settings, bool $includeCorrectAnswersToScore = true): array
{
  $payload = [
    'answerTimeLimit' => (bool)($settings['answerTimeLimit'] ?? true),
    'answerTimeLimitMs' => tcqNormalizeAnswerTimeLimitMs($settings['answerTimeLimitMs'] ?? 14000, 14000),
    'randomOrder' => (bool)($settings['randomOrder'] ?? true),
    'questionsPerAttempt' => max(0, (int)($settings['questionsPerAttempt'] ?? 0))
  ];
  if ($includeCorrectAnswersToScore) {
    $payload['correctAnswersToScore'] = max(1, (int)($settings['correctAnswersToScore'] ?? 1));
  }
  return $payload;
}

function tcqLoadCodeState(string $path): array
{
  if (!egmDbIsFile($path)) {
    return ['nextNumber' => 1];
  }
  $content = egmDbFileGetContents($path);
  if ($content === false) {
    return ['nextNumber' => 1];
  }
  $decoded = json_decode($content, true);
  $next = is_array($decoded) ? (int)($decoded['nextNumber'] ?? 1) : 1;
  if ($next < 1) {
    $next = 1;
  }
  return ['nextNumber' => $next];
}

function tcqSaveCodeState(string $path, array $state): bool
{
  $dir = dirname($path);
  if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
  }
  $next = (int)($state['nextNumber'] ?? 1);
  if ($next < 1) {
    $next = 1;
  }
  $json = json_encode(['nextNumber' => $next], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($json === false) {
    return false;
  }
  return egmDbFilePutContents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

function tcqNormalizeTaskTagCode(string $value): string
{
  $upper = strtoupper(trim($value));
  $clean = preg_replace('/[^A-Z0-9_-]+/', '', $upper);
  return is_string($clean) ? $clean : '';
}

function tcqNormalizeTaskType(string $value): string
{
  $token = trim(strtolower($value));
  if (in_array($token, ['conditional_quiz', 'conditional-quiz', 'conditional quiz', 'conditional-quiz-task', 'conditional quiz task'], true)) {
    return 'conditional_quiz';
  }
  return 'quiz';
}

function tcqReadTasksStore(string $path): array
{
  if (!egmDbIsFile($path)) {
    return [];
  }
  $content = egmDbFileGetContents($path);
  if ($content === false) {
    return [];
  }
  $jsonPayload = '';
  if (preg_match('/window\.EGM_TASKS\s*=\s*(\[[\s\S]*\])\s*;?\s*$/', $content, $m)) {
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

function tcqFindTaskById(string $taskId, string $tasksStorePath): ?array
{
  $needle = trim($taskId);
  if ($needle === '') {
    return null;
  }
  $tasks = tcqReadTasksStore($tasksStorePath);
  foreach ($tasks as $task) {
    if (!is_array($task)) {
      continue;
    }
    $id = trim((string)($task['id'] ?? ''));
    if ($id === '' || $id !== $needle) {
      continue;
    }
    $tagCode = tcqNormalizeTaskTagCode((string)($task['tagCode'] ?? ($task['tag_code'] ?? '')));
    if ($tagCode === '') {
      return null;
    }
    return [
      'id' => $id,
      'tagCode' => $tagCode,
      'title' => trim((string)($task['title'] ?? '')),
      'taskType' => tcqNormalizeTaskType((string)($task['taskType'] ?? ($task['task_type'] ?? 'quiz')))
    ];
  }
  return null;
}

function tcqExtractCodeNumber(string $code): int
{
  if (!preg_match('/^Q(\d+)$/', $code, $m)) {
    return -1;
  }
  return (int)$m[1];
}

function tcqFormatCode(int $number): string
{
  return 'Q' . str_pad((string)$number, 5, '0', STR_PAD_LEFT);
}

function tcqReserveOrCreateCode(string $candidateCode, array &$usedCodes, array &$state): string
{
  $candidate = strtoupper(trim($candidateCode));
  if ($candidate !== '' && !isset($usedCodes[$candidate])) {
    $num = tcqExtractCodeNumber($candidate);
    if ($num > 0) {
      $usedCodes[$candidate] = true;
      $next = (int)($state['nextNumber'] ?? 1);
      if ($num >= $next) {
        $state['nextNumber'] = $num + 1;
      }
      return $candidate;
    }
  }

  $next = max(1, (int)($state['nextNumber'] ?? 1));
  while (true) {
    $code = tcqFormatCode($next);
    if (!isset($usedCodes[$code])) {
      $usedCodes[$code] = true;
      $state['nextNumber'] = $next + 1;
      return $code;
    }
    $next += 1;
  }
}

function tcqBuildItemByIdMap(array $items, bool $includeQuestionScores = true): array
{
  $map = [];
  foreach ($items as $item) {
    if (!is_array($item)) {
      continue;
    }
    $id = trim((string)($item['id'] ?? ''));
    if ($id === '') {
      continue;
    }
    $map[$id] = tcqNormalizeItem($item, $includeQuestionScores);
  }
  return $map;
}

function tcqBuildAnswersHeader(array $items): array
{
  $header = ['Work ID'];
  foreach ($items as $item) {
    if (!is_array($item)) {
      continue;
    }
    $code = strtoupper(trim((string)($item['code'] ?? '')));
    $question = trim((string)($item['question'] ?? ''));
    if ($code === '' || $question === '') {
      continue;
    }
    $header[] = "{$code} | {$question}";
  }
  return $header;
}

function tcqExtractCodeFromAnswerHeader(string $headerCell): string
{
  if (!preg_match('/^\s*([A-Za-z0-9_-]+)\s*(?:\||$)/', $headerCell, $m)) {
    return '';
  }
  return strtoupper(trim((string)$m[1]));
}

function tcqSyncAnswersSheet(string $answersPath, array $oldItems, array $newItems, bool $includeQuestionScores = true): bool
{
  $rows = tcqReadCsv($answersPath);
  $header = isset($rows[0]) && is_array($rows[0]) ? $rows[0] : ['Work ID'];
  $workIdIndex = tcqFindHeaderIndex($header, 'Work ID');
  if ($workIdIndex < 0) {
    $header = array_merge(['Work ID'], array_values($header));
    $workIdIndex = 0;
  }

  $oldHeaderLookup = [];
  $oldHeaderByCode = [];
  foreach ($header as $idx => $name) {
    $cell = trim((string)$name);
    $key = $cell;
    if ($key !== '' && !array_key_exists($key, $oldHeaderLookup)) {
      $oldHeaderLookup[$key] = (int)$idx;
    }
    $code = tcqExtractCodeFromAnswerHeader($cell);
    if ($code !== '' && !array_key_exists($code, $oldHeaderByCode)) {
      $oldHeaderByCode[$code] = (int)$idx;
    }
  }

  $oldById = tcqBuildItemByIdMap($oldItems, $includeQuestionScores);
  $newHeader = tcqBuildAnswersHeader($newItems);
  $columnSources = [];
  for ($i = 1; $i < count($newHeader); $i += 1) {
    $newHeaderCell = $newHeader[$i];
    $sourceIndex = -1;
    $newItem = $newItems[$i - 1] ?? null;
    if (is_array($newItem)) {
      $id = trim((string)($newItem['id'] ?? ''));
      $newCode = strtoupper(trim((string)($newItem['code'] ?? '')));
      if ($newCode !== '' && array_key_exists($newCode, $oldHeaderByCode)) {
        $sourceIndex = (int)$oldHeaderByCode[$newCode];
      }
      if ($id !== '' && isset($oldById[$id])) {
        $oldCode = strtoupper(trim((string)($oldById[$id]['code'] ?? '')));
        if ($sourceIndex < 0 && $oldCode !== '' && array_key_exists($oldCode, $oldHeaderByCode)) {
          $sourceIndex = (int)$oldHeaderByCode[$oldCode];
        }
        $oldHeaderCell = '';
        if ($oldCode !== '') {
          $oldQuestion = trim((string)($oldById[$id]['question'] ?? ''));
          if ($oldQuestion !== '') {
            $oldHeaderCell = "{$oldCode} | {$oldQuestion}";
          }
        }
        if ($sourceIndex < 0 && $oldHeaderCell !== '' && array_key_exists($oldHeaderCell, $oldHeaderLookup)) {
          $sourceIndex = (int)$oldHeaderLookup[$oldHeaderCell];
        }
        $oldQuestionOnly = trim((string)($oldById[$id]['question'] ?? ''));
        if ($sourceIndex < 0 && $oldQuestionOnly !== '' && array_key_exists($oldQuestionOnly, $oldHeaderLookup)) {
          $sourceIndex = (int)$oldHeaderLookup[$oldQuestionOnly];
        }
      }
    }
    if ($sourceIndex < 0 && array_key_exists($newHeaderCell, $oldHeaderLookup)) {
      $sourceIndex = (int)$oldHeaderLookup[$newHeaderCell];
    }
    $columnSources[] = $sourceIndex;
  }

  $syncedRows = [$newHeader];
  for ($i = 1; $i < count($rows); $i += 1) {
    $row = is_array($rows[$i]) ? $rows[$i] : [];
    $workId = trim((string)($row[$workIdIndex] ?? ''));
    if ($workId === '') {
      continue;
    }
    $nextRow = [$workId];
    foreach ($columnSources as $sourceIndex) {
      $nextRow[] = $sourceIndex >= 0 ? (string)($row[$sourceIndex] ?? '') : '';
    }
    $syncedRows[] = $nextRow;
  }

  return tcqWriteCsv($answersPath, $syncedRows);
}

if (!defined('EGMQ_INCLUDE_ONLY')) {
  define('EGMQ_INCLUDE_ONLY', false);
}

$tcqRequestedTaskId = trim((string)($_POST['task_id'] ?? ($_GET['task_id'] ?? '')));
$tcqTaskLookupFailed = false;
if ($tcqRequestedTaskId !== '') {
  $task = tcqFindTaskById($tcqRequestedTaskId, __DIR__ . '/tasks/tasks.js');
  if (is_array($task)) {
    $tcqTaskId = (string)($task['id'] ?? '');
    $tcqTaskTagCode = tcqNormalizeTaskTagCode((string)($task['tagCode'] ?? ''));
    $tcqTaskTitle = trim((string)($task['title'] ?? ''));
    $tcqTaskType = tcqNormalizeTaskType((string)($task['taskType'] ?? 'quiz'));
    if ($tcqTaskId !== '' && $tcqTaskTagCode !== '') {
      $tcqTaskDir = __DIR__ . '/tasks/' . $tcqTaskTagCode;
      $tcqStorePath = $tcqTaskDir . '/EGMQ list.json';
      $tcqInviteesCsvPath = $tcqTaskDir . '/Invitees mapped.csv';
      $tcqAnswersCsvPath = $tcqTaskDir . '/Answers.csv';
      $tcqCodeStatePath = $tcqTaskDir . '/EGMQ code state.json';
      $tcqSettingsPath = $tcqTaskDir . '/EGMQ settings.json';
    } else {
      $tcqTaskLookupFailed = true;
    }
  } else {
    $tcqTaskLookupFailed = true;
  }
}

$tcqIsConditionalQuizTask = $tcqTaskType === 'conditional_quiz';

if ($tcqTaskLookupFailed) {
  if (!EGMQ_INCLUDE_ONLY && $tcqIsJsonRequest) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Task not found.'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  if (!EGMQ_INCLUDE_ONLY) {
    echo '<div class="card"><p class="muted">Task not found.</p></div>';
    return;
  }
}

if (!EGMQ_INCLUDE_ONLY && (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['tcq_action']))) {
  $csrfToken = egmSecurityReadCsrfFromRequest($_POST, 'csrf');
  if (!egmSecurityIsValidCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  header('Content-Type: application/json; charset=utf-8');
  $action = trim((string)($_POST['tcq_action'] ?? ''));

  if ($action === 'list') {
    if (!tcqEnsureInviteesColumns($tcqInviteesCsvPath)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to prepare invitees CSV columns.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $items = tcqLoadStore($tcqStorePath, !$tcqIsConditionalQuizTask);
    $codeState = tcqLoadCodeState($tcqCodeStatePath);
    $usedCodes = [];
    $repaired = [];
    foreach ($items as $item) {
      $item['code'] = tcqReserveOrCreateCode((string)($item['code'] ?? ''), $usedCodes, $codeState);
      $repaired[] = $item;
    }
    if (count($repaired) === count($items)) {
      $items = $repaired;
      if (!tcqSaveStore($tcqStorePath, $items) || !tcqSaveCodeState($tcqCodeStatePath, $codeState)) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to persist repaired question codes.'], JSON_UNESCAPED_UNICODE);
        exit;
      }
    }
    if (!tcqSyncAnswersSheet($tcqAnswersCsvPath, $items, $items, !$tcqIsConditionalQuizTask)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to sync Answers.csv with questions.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $settings = tcqSettingsForTaskType(tcqLoadSettings($tcqSettingsPath, tcqDefaultAnswerTimeLimitMs($tcqIsConditionalQuizTask)), !$tcqIsConditionalQuizTask);
    echo json_encode(['status' => 'ok', 'items' => $items, 'settings' => $settings], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'save_settings') {
    $answerTimeLimitRaw = trim((string)($_POST['answer_time_limit'] ?? '1'));
    $answerTimeLimitMsRaw = trim((string)($_POST['answer_time_limit_ms'] ?? (string)tcqDefaultAnswerTimeLimitMs($tcqIsConditionalQuizTask)));
    $randomOrderRaw = trim((string)($_POST['random_order'] ?? '1'));
    $questionsPerAttemptRaw = trim((string)($_POST['questions_per_attempt'] ?? '0'));
    $correctAnswersToScoreRaw = trim((string)($_POST['correct_answers_to_score'] ?? '1'));
    if (!preg_match('/^\d+$/', $answerTimeLimitMsRaw) || !preg_match('/^\d+$/', $questionsPerAttemptRaw) || (!$tcqIsConditionalQuizTask && !preg_match('/^\d+$/', $correctAnswersToScoreRaw))) {
      echo json_encode(['status' => 'error', 'message' => 'Time and question count settings must be numeric.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $answerTimeLimitMs = tcqNormalizeAnswerTimeLimitMs($answerTimeLimitMsRaw, tcqDefaultAnswerTimeLimitMs($tcqIsConditionalQuizTask));
    $questionsPerAttempt = max(0, (int)$questionsPerAttemptRaw);
    $correctAnswersToScore = $tcqIsConditionalQuizTask ? 0 : max(1, (int)$correctAnswersToScoreRaw);
    if (!$tcqIsConditionalQuizTask && $questionsPerAttempt > 0 && $correctAnswersToScore > $questionsPerAttempt) {
      echo json_encode(['status' => 'error', 'message' => 'Required correct answers cannot be greater than questions per attempt.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $settings = [
      'answerTimeLimit' => in_array($answerTimeLimitRaw, ['1', 'true', 'on'], true),
      'answerTimeLimitMs' => $answerTimeLimitMs,
      'randomOrder' => in_array($randomOrderRaw, ['1', 'true', 'on'], true),
      'questionsPerAttempt' => $questionsPerAttempt,
      'correctAnswersToScore' => $correctAnswersToScore
    ];
    if (!tcqSaveSettings($tcqSettingsPath, $settings, !$tcqIsConditionalQuizTask)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to save general settings.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    echo json_encode([
      'status' => 'ok',
      'message' => 'General settings saved.',
      'settings' => tcqSettingsForTaskType(tcqLoadSettings($tcqSettingsPath, tcqDefaultAnswerTimeLimitMs($tcqIsConditionalQuizTask)), !$tcqIsConditionalQuizTask)
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'save_all') {
    if (!tcqEnsureInviteesColumns($tcqInviteesCsvPath)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to prepare invitees CSV columns.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $raw = (string)($_POST['items'] ?? '[]');
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
      echo json_encode(['status' => 'error', 'message' => 'Invalid payload.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $oldItems = tcqLoadStore($tcqStorePath, !$tcqIsConditionalQuizTask);
    $codeState = tcqLoadCodeState($tcqCodeStatePath);
    $oldCodeState = $codeState;
    $usedCodes = [];
    $items = [];
    foreach ($decoded as $index => $row) {
      if (!is_array($row)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid row data.'], JSON_UNESCAPED_UNICODE);
        exit;
      }
      $item = tcqNormalizeItem($row, !$tcqIsConditionalQuizTask);
      if ($item['question'] === '') {
        $num = $index + 1;
        echo json_encode(['status' => 'error', 'message' => "Question in row {$num} is required."], JSON_UNESCAPED_UNICODE);
        exit;
      }
      if (($item['type'] ?? 'mcq') === 'mcq') {
        foreach ($item['answers'] as $ans) {
          if ($ans === '') {
            $num = $index + 1;
            echo json_encode(['status' => 'error', 'message' => "All 4 answers in row {$num} are required for MCQ."], JSON_UNESCAPED_UNICODE);
            exit;
          }
        }
      }
      $item['code'] = tcqReserveOrCreateCode((string)($item['code'] ?? ''), $usedCodes, $codeState);
      $items[] = $item;
    }

    if (!tcqSaveStore($tcqStorePath, $items)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to save questions.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if (!tcqSaveCodeState($tcqCodeStatePath, $codeState)) {
      $rolledBack = tcqSaveStore($tcqStorePath, $oldItems);
      echo json_encode(['status' => 'error', 'message' => 'Failed to save question code state.'], JSON_UNESCAPED_UNICODE);
      if (!$rolledBack) {
        error_log('Event Guest Manager failed to roll back question storage after a code-state write failure.');
      }
      exit;
    }
    if (!tcqSyncAnswersSheet($tcqAnswersCsvPath, $oldItems, $items, !$tcqIsConditionalQuizTask)) {
      $storeRolledBack = tcqSaveStore($tcqStorePath, $oldItems);
      $codeStateRolledBack = tcqSaveCodeState($tcqCodeStatePath, $oldCodeState);
      if (!$storeRolledBack || !$codeStateRolledBack) {
        error_log('Event Guest Manager failed to fully roll back question storage after an Answers.csv sync failure.');
      }
      echo json_encode(['status' => 'error', 'message' => 'Failed to sync Answers.csv with saved questions.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    echo json_encode(['status' => 'ok', 'message' => 'All changes saved.', 'items' => $items], JSON_UNESCAPED_UNICODE);
    exit;
  }

  echo json_encode(['status' => 'error', 'message' => 'Unsupported action.'], JSON_UNESCAPED_UNICODE);
  exit;
}

if (EGMQ_INCLUDE_ONLY) {
  return;
}

$tcqEmbeddedInPanel = defined('EGMQ_EMBEDDED_IN_PANEL') && EGMQ_EMBEDDED_IN_PANEL === true;
$tcqStandaloneCoreCssHref = '../../../style/styles.css';
$tcqStandalonePanelCssHref = 'egm-panel.css';
$tcqStandaloneCoreCssVersion = @egmDbFilemtime(__DIR__ . '/../../../style/styles.css');
$tcqStandalonePanelCssVersion = @egmDbFilemtime(__DIR__ . '/egm-panel.css');
if (is_int($tcqStandaloneCoreCssVersion) && $tcqStandaloneCoreCssVersion > 0) {
  $tcqStandaloneCoreCssHref .= '?v=' . rawurlencode((string)$tcqStandaloneCoreCssVersion);
}
if (is_int($tcqStandalonePanelCssVersion) && $tcqStandalonePanelCssVersion > 0) {
  $tcqStandalonePanelCssHref .= '?v=' . rawurlencode((string)$tcqStandalonePanelCssVersion);
}
?>

<?php if (!$tcqEmbeddedInPanel): ?>
<link rel="stylesheet" href="<?= htmlspecialchars($tcqStandaloneCoreCssHref, ENT_QUOTES, 'UTF-8') ?>" />
<link rel="stylesheet" href="<?= htmlspecialchars($tcqStandalonePanelCssHref, ENT_QUOTES, 'UTF-8') ?>" />
<style>
  html, body { margin: 0; padding: 0; }
  .tcq-standalone-shell { padding: 14px; }
</style>
<?php endif; ?>

<div class="egm-shell tcq-standalone-shell">

<div class="card">
  <div class="section-header">
    <h3>General Setting</h3>
  </div>
  <div class="tcq-settings-grid">
    <label class="tcq-settings-row">
      <span>Answer Time Limit</span>
      <input id="tcq-setting-answer-time-limit" type="checkbox" checked />
    </label>
    <label class="tcq-settings-row">
      <span>Answer Time Limit (ms)</span>
      <input id="tcq-setting-answer-time-limit-ms" type="number" min="1000" max="600000" step="100" value="<?= $tcqIsConditionalQuizTask ? '30000' : '14000' ?>" />
    </label>
    <label class="tcq-settings-row">
      <span>Random Order</span>
      <input id="tcq-setting-random-order" type="checkbox" checked />
    </label>
    <label class="tcq-settings-row">
      <span>Questions Per Attempt (0 = All)</span>
      <input id="tcq-setting-questions-per-attempt" type="number" min="0" step="1" value="0" />
    </label>
    <?php if (!$tcqIsConditionalQuizTask): ?>
    <label class="tcq-settings-row">
      <span>Correct Answers For Score</span>
      <input id="tcq-setting-correct-answers-to-score" type="number" min="1" step="1" value="1" />
    </label>
    <?php endif; ?>
  </div>
  <div class="tcq-settings-actions">
    <button id="tcq-save-settings" type="button" class="btn primary">Save General Settings</button>
  </div>
  <p id="tcq-settings-status" class="muted small tcq-settings-status" aria-live="polite"></p>
</div>

<div class="card">
  <div class="section-header">
    <h3>Question</h3>
  </div>

  <form id="tcq-form" class="form" style="gap:12px;">
    <label class="field standard-width">
      <span>Question Type</span>
      <div class="tcq-type-group">
        <label class="tcq-type-option">
          <input type="radio" name="questionType" value="mcq" checked />
          <span>MCQ (Multi Choice Question)</span>
        </label>
        <label class="tcq-type-option">
          <input type="radio" name="questionType" value="percentage" />
          <span>Percentage Question</span>
        </label>
      </div>
      <p class="tcq-type-hint">Percentage Question is a poll (0 to 100) and does not need multiple answers.</p>
    </label>
    <label class="field standard-width">
      <span>Question</span>
      <input id="tcq-question-input" name="question" type="text" autocomplete="off" required />
    </label>
    <?php if (!$tcqIsConditionalQuizTask): ?>
    <div class="form grid tcq-score-grid">
      <label class="field standard-width">
        <span>Active Duration (Golden Time) Score</span>
        <input id="tcq-active-duration-score-input" name="activeDurationScore" type="number" min="0" step="1" value="0" />
      </label>
      <label class="field standard-width">
        <span>Golden Time Ended Score</span>
        <input id="tcq-golden-time-ended-score-input" name="goldenTimeEndedScore" type="number" min="0" step="1" value="0" />
      </label>
    </div>
    <p class="muted small">These per-question scores are used when Proportional Score Mode is enabled.</p>
    <?php endif; ?>
    <div id="tcq-answer-grid" class="form grid tcq-answer-grid">
      <label class="field standard-width">
        <span>Answer 1 (Correct)</span>
        <input id="tcq-answer-1" name="answer1" type="text" autocomplete="off" required />
      </label>
      <label class="field standard-width">
        <span>Answer 2</span>
        <input id="tcq-answer-2" name="answer2" type="text" autocomplete="off" required />
      </label>
      <label class="field standard-width">
        <span>Answer 3</span>
        <input id="tcq-answer-3" name="answer3" type="text" autocomplete="off" required />
      </label>
      <label class="field standard-width">
        <span>Answer 4</span>
        <input id="tcq-answer-4" name="answer4" type="text" autocomplete="off" required />
      </label>
    </div>
    <div class="field full">
      <button type="submit" class="btn primary standard-primary-button">Add</button>
    </div>
    <p id="tcq-status" class="muted small" aria-live="polite"></p>
  </form>

  <div class="section-header" style="margin-top:12px;">
    <h3>Questions list</h3>
  </div>
  <div class="table-wrapper">
    <table class="tcq-list-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Question & Answers</th>
          <th>Actions</th>
          <th class="tcq-drag-cell">Sort</th>
        </tr>
      </thead>
      <tbody id="tcq-list-body">
        <tr><td colspan="4" class="muted">Loading questions...</td></tr>
      </tbody>
    </table>
  </div>

  <div class="tcq-save-wrap">
    <button id="tcq-save-all" type="button" class="btn primary standard-primary-button">Save</button>
  </div>
</div>

<script>
(() => {
  <?php
  $tcqEndpointBase = $tcqEmbeddedInPanel ? 'mini%20apps/EGMs/EGM/EGMQ.php' : 'EGMQ.php';
  ?>
  const endpoint = <?= json_encode(
    $tcqEndpointBase . ($tcqTaskId !== '' ? ('?task_id=' . rawurlencode($tcqTaskId)) : ''),
    JSON_UNESCAPED_UNICODE
  ); ?>;
  const csrfToken = <?= json_encode($tcqCsrfToken, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  const isConditionalQuizTask = <?= $tcqIsConditionalQuizTask ? 'true' : 'false'; ?>;
  const form = document.getElementById('tcq-form');
  const input = document.getElementById('tcq-question-input');
  const body = document.getElementById('tcq-list-body');
  const statusEl = document.getElementById('tcq-status');
  const saveAllBtn = document.getElementById('tcq-save-all');
  const answerTimeLimitToggle = document.getElementById('tcq-setting-answer-time-limit');
  const answerTimeLimitMsInput = document.getElementById('tcq-setting-answer-time-limit-ms');
  const randomOrderToggle = document.getElementById('tcq-setting-random-order');
  const questionsPerAttemptInput = document.getElementById('tcq-setting-questions-per-attempt');
  const correctAnswersToScoreInput = document.getElementById('tcq-setting-correct-answers-to-score');
  const saveSettingsBtn = document.getElementById('tcq-save-settings');
  const settingsStatusEl = document.getElementById('tcq-settings-status');
  const formAnswerGrid = document.getElementById('tcq-answer-grid');
  const formActiveDurationScoreInput = document.getElementById('tcq-active-duration-score-input');
  const formGoldenTimeEndedScoreInput = document.getElementById('tcq-golden-time-ended-score-input');
  if (
    !form
    || !input
    || !body
    || !statusEl
    || !saveAllBtn
    || !formAnswerGrid
    || (!isConditionalQuizTask && !(formActiveDurationScoreInput instanceof HTMLInputElement))
    || (!isConditionalQuizTask && !(formGoldenTimeEndedScoreInput instanceof HTMLInputElement))
  ) return;
  const formTypeInputs = form.querySelectorAll('input[name="questionType"]');
  const formAnswerInputs = form.querySelectorAll('input[name="answer1"], input[name="answer2"], input[name="answer3"], input[name="answer4"]');

  let items = [];
  let draggedRowId = '';
  const defaultAnswerTimeLimitMs = isConditionalQuizTask ? 30000 : 14000;

  const esc = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

  const makeId = () => `q_${Math.random().toString(36).slice(2, 10)}`;
  const normalizeType = (value) => String(value ?? '').toLowerCase() === 'percentage' ? 'percentage' : 'mcq';
  const normalizeScoreValue = (value) => {
    const parsed = Number.parseInt(String(value ?? '').trim(), 10);
    if (!Number.isFinite(parsed) || parsed < 0) {
      return 0;
    }
    return parsed;
  };

  const normalizeAnswerTimeLimitMs = (value) => {
    const parsed = Number.parseInt(String(value ?? '').trim(), 10);
    if (!Number.isFinite(parsed) || parsed < 1000) {
      return defaultAnswerTimeLimitMs;
    }
    return Math.min(parsed, 600000);
  };

  const syncAnswerTimeLimitFieldState = () => {
    if (!(answerTimeLimitToggle instanceof HTMLInputElement) || !(answerTimeLimitMsInput instanceof HTMLInputElement)) return;
    const enabled = answerTimeLimitToggle.checked;
    answerTimeLimitMsInput.disabled = !enabled;
    answerTimeLimitMsInput.setAttribute('aria-disabled', enabled ? 'false' : 'true');
  };

  const normalizeAnswers = (list) => {
    const answers = Array.isArray(list) ? list.slice(0, 4) : [];
    while (answers.length < 4) answers.push('');
    return answers.map((v) => String(v ?? ''));
  };

  const normalizeItemRecord = (item = {}) => ({
    id: String(item.id || makeId()),
    code: String(item.code || ''),
    type: normalizeType(item.type),
    question: String(item.question || ''),
    answers: normalizeAnswers(item.answers),
    activeDurationScore: isConditionalQuizTask ? 0 : normalizeScoreValue(item.activeDurationScore ?? item.active_duration_score ?? item.score ?? 0),
    goldenTimeEndedScore: isConditionalQuizTask ? 0 : normalizeScoreValue(item.goldenTimeEndedScore ?? item.golden_time_ended_score ?? item.afterEndtimeScore ?? item.after_endtime_score ?? 0),
    createdAt: String(item.createdAt || '')
  });

  const getSelectedType = () => {
    const selected = form.querySelector('input[name="questionType"]:checked');
    return normalizeType(selected ? selected.value : 'mcq');
  };

  const syncAddFormTypeState = () => {
    const type = getSelectedType();
    const isMcq = type === 'mcq';
    formAnswerGrid.classList.toggle('tcq-hidden', !isMcq);
    formAnswerInputs.forEach((field) => {
      field.required = isMcq;
      if (!isMcq) {
        field.value = '';
      }
    });
  };

  const setStatus = (message, isError = false) => {
    statusEl.textContent = message || '';
    statusEl.style.color = isError ? '#d1434a' : '';
  };

  const setSettingsStatus = (message, isError = false) => {
    if (!settingsStatusEl) return;
    settingsStatusEl.textContent = message || '';
    settingsStatusEl.style.color = isError ? '#d1434a' : '';
  };

  const applySettingsToForm = (settings) => {
    if (
      !(answerTimeLimitToggle instanceof HTMLInputElement)
      || !(answerTimeLimitMsInput instanceof HTMLInputElement)
      || !(randomOrderToggle instanceof HTMLInputElement)
      || !(questionsPerAttemptInput instanceof HTMLInputElement)
      || (!isConditionalQuizTask && !(correctAnswersToScoreInput instanceof HTMLInputElement))
    ) {
      return;
    }
    answerTimeLimitToggle.checked = Boolean(settings?.answerTimeLimit ?? true);
    answerTimeLimitMsInput.value = String(normalizeAnswerTimeLimitMs(settings?.answerTimeLimitMs ?? settings?.answer_time_limit_ms));
    randomOrderToggle.checked = Boolean(settings?.randomOrder ?? true);
    questionsPerAttemptInput.value = String(Math.max(0, Number.parseInt(String(settings?.questionsPerAttempt ?? 0), 10) || 0));
    if (correctAnswersToScoreInput instanceof HTMLInputElement) {
      correctAnswersToScoreInput.value = String(Math.max(1, Number.parseInt(String(settings?.correctAnswersToScore ?? 1), 10) || 0));
    }
    syncAnswerTimeLimitFieldState();
  };

  const validateAll = () => {
    for (let i = 0; i < items.length; i += 1) {
      const row = items[i];
      if (!String(row.question || '').trim()) {
        return `Question in row ${i + 1} is required.`;
      }
      const type = normalizeType(row.type);
      if (type === 'mcq') {
        const answers = normalizeAnswers(row.answers);
        if (answers.some((ans) => !String(ans).trim())) {
          return `All 4 answers in row ${i + 1} are required for MCQ.`;
        }
      }
    }
    return '';
  };

  const render = () => {
    if (!items.length) {
      body.innerHTML = '<tr><td colspan="4" class="muted">No questions added yet.</td></tr>';
      return;
    }

    body.innerHTML = items.map((item, index) => {
      const type = normalizeType(item.type);
      const isMcq = type === 'mcq';
      const answers = normalizeAnswers(item.answers);
      return `<tr data-row-id="${esc(item.id)}" draggable="true">
        <td>${index + 1}</td>
        <td>
          <div class="tcq-row-grid">
            <div class="muted small">Code: ${esc(item.code || 'Auto')}</div>
            <div class="tcq-type-group">
              <label class="tcq-type-option">
                <input type="radio" name="row-type-${esc(item.id)}" data-field="type" value="mcq" ${isMcq ? 'checked' : ''} />
                <span>MCQ</span>
              </label>
              <label class="tcq-type-option">
                <input type="radio" name="row-type-${esc(item.id)}" data-field="type" value="percentage" ${!isMcq ? 'checked' : ''} />
                <span>Percentage Question</span>
              </label>
            </div>
            <input class="tcq-field" type="text" data-field="question" value="${esc(item.question)}" />
            ${isConditionalQuizTask ? '' : `<div class="form grid tcq-score-grid">
              <label class="field standard-width">
                <span>Active Duration (Golden Time) Score</span>
                <input class="tcq-field" type="number" min="0" step="1" data-field="activeDurationScore" value="${esc(String(normalizeScoreValue(item.activeDurationScore)))}" />
              </label>
              <label class="field standard-width">
                <span>Golden Time Ended Score</span>
                <input class="tcq-field" type="number" min="0" step="1" data-field="goldenTimeEndedScore" value="${esc(String(normalizeScoreValue(item.goldenTimeEndedScore)))}" />
              </label>
            </div>`}
            <div class="tcq-answer-grid ${isMcq ? '' : 'tcq-hidden'}">
              <input class="tcq-field" type="text" data-field="answer0" value="${esc(answers[0])}" ${isMcq ? '' : 'disabled'} />
              <input class="tcq-field" type="text" data-field="answer1" value="${esc(answers[1])}" ${isMcq ? '' : 'disabled'} />
              <input class="tcq-field" type="text" data-field="answer2" value="${esc(answers[2])}" ${isMcq ? '' : 'disabled'} />
              <input class="tcq-field" type="text" data-field="answer3" value="${esc(answers[3])}" ${isMcq ? '' : 'disabled'} />
            </div>
          </div>
        </td>
        <td>
          <div class="tcq-list-actions">
            <button type="button" class="btn ghost" data-delete-id="${esc(item.id)}">Delete</button>
          </div>
        </td>
        <td class="tcq-drag-cell">
          <button type="button" class="tcq-drag-handle" data-drag-handle="1" title="Drag to reorder" aria-label="Drag to reorder">&#9776;</button>
        </td>
      </tr>`;
    }).join('');
  };

  const reorderById = (dragId, targetId, placeAfter) => {
    const fromIndex = items.findIndex((item) => item.id === dragId);
    const toIndex = items.findIndex((item) => item.id === targetId);
    if (fromIndex < 0 || toIndex < 0 || fromIndex === toIndex) return false;
    const [moved] = items.splice(fromIndex, 1);
    let insertIndex = toIndex;
    if (fromIndex < toIndex) {
      insertIndex = placeAfter ? toIndex : toIndex - 1;
    } else {
      insertIndex = placeAfter ? toIndex + 1 : toIndex;
    }
    insertIndex = Math.max(0, Math.min(items.length, insertIndex));
    items.splice(insertIndex, 0, moved);
    return true;
  };

  const clearDragVisuals = () => {
    Array.from(body.querySelectorAll('.tcq-row-drop-target')).forEach((node) => {
      node.classList.remove('tcq-row-drop-target');
    });
    Array.from(body.querySelectorAll('.tcq-row-dragging')).forEach((node) => {
      node.classList.remove('tcq-row-dragging');
    });
  };

  const postAction = async (action, payload = {}) => {
    const formData = new FormData();
    formData.append('tcq_action', action);
    if (csrfToken) {
      formData.append('csrf', csrfToken);
    }
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

  const syncFromServer = async () => {
    const data = await postAction('list');
    items = Array.isArray(data.items) ? data.items.map((item) => normalizeItemRecord(item)) : [];
    applySettingsToForm(data.settings || {});
    render();
  };

  let isPersisting = false;
  const persistAllChanges = async (successMessage = 'All changes saved.') => {
    if (isPersisting) return false;
    const validationError = validateAll();
    if (validationError) {
      setStatus(validationError, true);
      return false;
    }
    isPersisting = true;
    saveAllBtn.disabled = true;
    try {
      const data = await postAction('save_all', { items: JSON.stringify(items) });
      items = Array.isArray(data.items) ? data.items.map((item) => normalizeItemRecord(item)) : items;
      render();
      setStatus(successMessage || data.message || 'All changes saved.');
      return true;
    } catch (error) {
      setStatus(error?.message || 'Failed to save changes.', true);
      return false;
    } finally {
      isPersisting = false;
      saveAllBtn.disabled = false;
    }
  };

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const fd = new FormData(form);
    const type = normalizeType(fd.get('questionType'));
    const next = {
      id: makeId(),
      code: '',
      type,
      question: String(fd.get('question') ?? '').trim(),
      answers: type === 'mcq' ? [
        String(fd.get('answer1') ?? '').trim(),
        String(fd.get('answer2') ?? '').trim(),
        String(fd.get('answer3') ?? '').trim(),
        String(fd.get('answer4') ?? '').trim()
      ] : ['', '', '', ''],
      activeDurationScore: isConditionalQuizTask ? 0 : normalizeScoreValue(fd.get('activeDurationScore') ?? 0),
      goldenTimeEndedScore: isConditionalQuizTask ? 0 : normalizeScoreValue(fd.get('goldenTimeEndedScore') ?? 0),
      createdAt: new Date().toISOString().slice(0, 19).replace('T', ' ')
    };
    if (!next.question) {
      setStatus('Question is required.', true);
      return;
    }
    if (next.type === 'mcq' && next.answers.some((ans) => !ans)) {
      setStatus('All 4 answers are required for MCQ.', true);
      return;
    }
    items.push(next);
    render();
    void persistAllChanges('Question saved.');
    form.reset();
    const defaultType = form.querySelector('input[name="questionType"][value="mcq"]');
    if (defaultType instanceof HTMLInputElement) {
      defaultType.checked = true;
    }
    syncAddFormTypeState();
    input.focus();
  });

  body.addEventListener('input', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) return;
    const row = target.closest('tr[data-row-id]');
    if (!row) return;
    const id = row.getAttribute('data-row-id') || '';
    const idx = items.findIndex((item) => item.id === id);
    if (idx < 0) return;
    const field = target.dataset.field || '';
    if (field === 'question') {
      items[idx].question = target.value;
      return;
    }
    if (field === 'activeDurationScore' || field === 'goldenTimeEndedScore') {
      items[idx][field] = normalizeScoreValue(target.value);
      return;
    }
    if (field.startsWith('answer')) {
      const pos = Number.parseInt(field.replace('answer', ''), 10);
      if (Number.isInteger(pos) && pos >= 0 && pos < 4) {
        const answers = normalizeAnswers(items[idx].answers);
        answers[pos] = target.value;
        items[idx].answers = answers;
      }
    }
  });

  body.addEventListener('change', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) return;
    if (target.dataset.field !== 'type') return;
    const row = target.closest('tr[data-row-id]');
    if (!row) return;
    const id = row.getAttribute('data-row-id') || '';
    const idx = items.findIndex((item) => item.id === id);
    if (idx < 0) return;
    items[idx].type = normalizeType(target.value);
    if (items[idx].type === 'percentage') {
      items[idx].answers = ['', '', '', ''];
    } else {
      items[idx].answers = normalizeAnswers(items[idx].answers);
    }
    render();
    void persistAllChanges('Question type updated and saved.');
  });

  body.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const deleteBtn = target.closest('[data-delete-id]');
    if (!deleteBtn) return;
    const id = deleteBtn.getAttribute('data-delete-id') || '';
    items = items.filter((item) => item.id !== id);
    render();
    void persistAllChanges('Question removed and saved.');
  });

  body.addEventListener('dragstart', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const handle = target.closest('[data-drag-handle]');
    if (!handle) {
      event.preventDefault();
      return;
    }
    const row = handle.closest('tr[data-row-id]');
    if (!(row instanceof HTMLTableRowElement)) {
      event.preventDefault();
      return;
    }
    draggedRowId = row.getAttribute('data-row-id') || '';
    if (!draggedRowId) {
      event.preventDefault();
      return;
    }
    row.classList.add('tcq-row-dragging');
    if (event.dataTransfer) {
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', draggedRowId);
    }
  });

  body.addEventListener('dragover', (event) => {
    if (!draggedRowId) return;
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const row = target.closest('tr[data-row-id]');
    if (!(row instanceof HTMLTableRowElement)) return;
    const targetId = row.getAttribute('data-row-id') || '';
    if (!targetId || targetId === draggedRowId) return;
    event.preventDefault();
    clearDragVisuals();
    row.classList.add('tcq-row-drop-target');
  });

  body.addEventListener('drop', (event) => {
    if (!draggedRowId) return;
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const row = target.closest('tr[data-row-id]');
    if (!(row instanceof HTMLTableRowElement)) return;
    const targetId = row.getAttribute('data-row-id') || '';
    if (!targetId || targetId === draggedRowId) return;
    event.preventDefault();
    const rect = row.getBoundingClientRect();
    const placeAfter = event.clientY > (rect.top + rect.height / 2);
    if (reorderById(draggedRowId, targetId, placeAfter)) {
      render();
      void persistAllChanges('Question order saved.');
    }
    draggedRowId = '';
    clearDragVisuals();
  });

  body.addEventListener('dragend', () => {
    draggedRowId = '';
    clearDragVisuals();
  });

  saveAllBtn.addEventListener('click', async () => {
    await persistAllChanges('All changes saved.');
  });

  formTypeInputs.forEach((radio) => {
    radio.addEventListener('change', syncAddFormTypeState);
  });
  if (answerTimeLimitToggle instanceof HTMLInputElement) {
    answerTimeLimitToggle.addEventListener('change', syncAnswerTimeLimitFieldState);
  }
  syncAddFormTypeState();
  syncAnswerTimeLimitFieldState();

  if (saveSettingsBtn instanceof HTMLButtonElement) {
    saveSettingsBtn.addEventListener('click', async () => {
      if (
        !(answerTimeLimitToggle instanceof HTMLInputElement)
        || !(answerTimeLimitMsInput instanceof HTMLInputElement)
        || !(randomOrderToggle instanceof HTMLInputElement)
        || !(questionsPerAttemptInput instanceof HTMLInputElement)
        || (!isConditionalQuizTask && !(correctAnswersToScoreInput instanceof HTMLInputElement))
      ) {
        return;
      }
      const answerTimeLimitMs = normalizeAnswerTimeLimitMs(answerTimeLimitMsInput.value);
      answerTimeLimitMsInput.value = String(answerTimeLimitMs);
      const questionsPerAttempt = Math.max(0, Number.parseInt(questionsPerAttemptInput.value || '0', 10) || 0);
      const correctAnswersToScore = correctAnswersToScoreInput instanceof HTMLInputElement
        ? Math.max(1, Number.parseInt(correctAnswersToScoreInput.value || '1', 10) || 0)
        : 0;
      if (!isConditionalQuizTask && questionsPerAttempt > 0 && correctAnswersToScore > questionsPerAttempt) {
        setSettingsStatus('Required correct answers cannot be greater than questions per attempt.', true);
        return;
      }
      saveSettingsBtn.disabled = true;
      try {
        const data = await postAction('save_settings', {
          answer_time_limit: answerTimeLimitToggle.checked ? '1' : '0',
          answer_time_limit_ms: String(answerTimeLimitMs),
          random_order: randomOrderToggle.checked ? '1' : '0',
          questions_per_attempt: String(questionsPerAttempt),
          correct_answers_to_score: String(correctAnswersToScore)
        });
        applySettingsToForm(data.settings || {});
        setSettingsStatus(data.message || 'General settings saved.');
      } catch (error) {
        setSettingsStatus(error?.message || 'Failed to save general settings.', true);
      } finally {
        saveSettingsBtn.disabled = false;
      }
    });
  }

  syncFromServer()
    .then(() => setStatus(''))
    .catch((error) => {
      items = [];
      render();
      setStatus(error?.message || 'Failed to load questions.', true);
    });
})();
</script>

</div>
