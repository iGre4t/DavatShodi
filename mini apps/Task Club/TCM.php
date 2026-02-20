<?php
session_start();
$cspNonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$cspNonce}'; style-src 'self' 'nonce-{$cspNonce}'; img-src 'self' data: https: http:; font-src 'self' data:; connect-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: same-origin");
if (empty($_SESSION['tc_csrf'])) {
  $_SESSION['tc_csrf'] = bin2hex(random_bytes(16));
}
const SETTINGS_STORE_PATH = __DIR__ . '/../../data/store.json';
const DEFAULT_PANEL_SETTINGS = [
  'siteIcon' => ''
];
const TASKS_DIR_PATH = __DIR__ . '/tasks';
const TASKS_JS_STORE_PATH = TASKS_DIR_PATH . '/tasks.js';
const TASK_SCORE_SETTINGS_FILE = 'task-score.json';

$prizeStorePath = __DIR__ . '/TC Prizes.json';
$prizeLevelsPath = __DIR__ . '/TC Prize Levels.json';
$questionsStorePath = __DIR__ . '/TCQ list.json';
$tcqSettingsPath = __DIR__ . '/TCQ settings.json';
$inviteesFilePath = __DIR__ . '/TC Event/Invitees mapped.csv';
$inviteesMapPath = __DIR__ . '/TC Event/TC Mapped.json';
$loginAttemptsPath = __DIR__ . '/TC Event/login_attempts.json';
const TCQ_DEFAULT_SETTINGS = [
  'answerTimeLimit' => true,
  'randomOrder' => true
];

function readPrizeStore(string $path): array
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

function writePrizeStore(string $path, array $payload): bool
{
  $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($json === false) {
    return false;
  }
  return file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

function normalizePrizeLevelScoreValue($value): int
{
  if (!is_scalar($value)) {
    return 0;
  }
  $parsed = (int)$value;
  return $parsed > 0 ? $parsed : 0;
}

function readPrizeLevels(string $path): array
{
  if (!is_file($path)) {
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
  $levels = [];
  foreach ($decoded as $item) {
    if (!is_array($item)) {
      continue;
    }
    $score = normalizePrizeLevelScoreValue($item['score'] ?? 0);
    if ($score <= 0) {
      continue;
    }
    $levels[] = $score;
  }
  sort($levels, SORT_NUMERIC);
  return array_values($levels);
}

function resolveAllowedRollCountByScore(int $score, array $levels): ?int
{
  if (!$levels) {
    return null;
  }
  $normalizedScore = max(0, $score);
  $allowed = 0;
  foreach ($levels as $threshold) {
    $value = normalizePrizeLevelScoreValue($threshold);
    if ($value > 0 && $normalizedScore >= $value) {
      $allowed += 1;
    }
  }
  return $allowed;
}

function normalizePrizeLevelTypeValue($value): string
{
  $token = strtolower(trim((string)$value));
  if ($token === 'out_of_value') {
    return 'out_of_value';
  }
  return 'value_sum';
}

function readPrizeLevelRecords(string $path): array
{
  if (!is_file($path)) {
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
  $records = [];
  foreach ($decoded as $item) {
    if (!is_array($item)) {
      continue;
    }
    $score = normalizePrizeLevelScoreValue($item['score'] ?? 0);
    if ($score <= 0) {
      continue;
    }
    $id = trim((string)($item['id'] ?? ''));
    if ($id === '') {
      $id = uniqid('lvl_', true);
    }
    $name = trim((string)($item['name'] ?? ''));
    if ($name === '') {
      $name = 'Level ' . $score;
    }
    $type = normalizePrizeLevelTypeValue($item['type'] ?? 'value_sum');
    $records[] = [
      'id' => $id,
      'name' => $name,
      'type' => $type,
      'score' => $score
    ];
  }
  usort($records, static function ($a, $b) {
    return (int)($a['score'] ?? 0) <=> (int)($b['score'] ?? 0);
  });
  return array_values($records);
}

function parseCommaSeparatedList(string $raw): array
{
  $trimmed = trim($raw);
  if ($trimmed === '') {
    return [];
  }
  $parts = preg_split('/\s*,\s*/', $trimmed);
  if (!is_array($parts)) {
    return [];
  }
  $result = [];
  foreach ($parts as $part) {
    $token = trim((string)$part);
    if ($token !== '') {
      $result[] = $token;
    }
  }
  return $result;
}

function serializeCommaSeparatedList(array $items): string
{
  $clean = [];
  foreach ($items as $item) {
    $token = trim((string)$item);
    if ($token !== '') {
      $clean[] = $token;
    }
  }
  return implode(', ', $clean);
}

function syncOutOfValueRewardsForUser(array &$rows, int $rowIndex, array $columns, array $levels, int $userScore): bool
{
  $outOfValueIndex = (int)($columns['Out of Value Rewards'] ?? -1);
  if ($outOfValueIndex < 0 || !isset($rows[$rowIndex]) || !is_array($rows[$rowIndex])) {
    return false;
  }
  $existing = parseCommaSeparatedList((string)($rows[$rowIndex][$outOfValueIndex] ?? ''));
  $seen = [];
  foreach ($existing as $name) {
    $seen[mb_strtolower(trim((string)$name), 'UTF-8')] = true;
  }

  $changed = false;
  foreach ($levels as $level) {
    if (!is_array($level)) {
      continue;
    }
    $type = (string)($level['type'] ?? 'value_sum');
    if ($type !== 'out_of_value') {
      continue;
    }
    $requiredScore = max(0, (int)($level['score'] ?? 0));
    if ($requiredScore <= 0 || $userScore < $requiredScore) {
      continue;
    }
    $name = trim((string)($level['name'] ?? ''));
    if ($name === '') {
      $name = 'Level ' . $requiredScore;
    }
    $key = mb_strtolower($name, 'UTF-8');
    if (isset($seen[$key])) {
      continue;
    }
    $seen[$key] = true;
    $existing[] = $name;
    $changed = true;
  }

  if ($changed) {
    $rows[$rowIndex][$outOfValueIndex] = serializeCommaSeparatedList($existing);
  }
  return $changed;
}

function normalizeFloatValue($value): float
{
  if (is_int($value) || is_float($value)) {
    return (float)$value;
  }
  if (!is_scalar($value)) {
    return 0.0;
  }
  $normalized = preg_replace('/[,\s]+/', '', (string)$value);
  if (!is_string($normalized) || $normalized === '' || !is_numeric($normalized)) {
    return 0.0;
  }
  return (float)$normalized;
}

function readQuestionStore(string $path): array
{
  if (!is_file($path)) {
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
  $items = [];
  $usedCodes = [];
  $legacyCounter = 1;
  foreach ($decoded as $row) {
    if (!is_array($row)) {
      continue;
    }
    $code = trim((string)($row['code'] ?? ''));
    if ($code === '') {
      $code = 'LEGACY' . $legacyCounter;
      $legacyCounter += 1;
    }
    if (isset($usedCodes[$code])) {
      continue;
    }
    $type = trim(mb_strtolower((string)($row['type'] ?? 'mcq'), 'UTF-8'));
    if ($type !== 'percentage') {
      $type = 'mcq';
    }
    $question = trim((string)($row['question'] ?? ''));
    $answers = is_array($row['answers'] ?? null) ? array_values($row['answers']) : [];
    if ($question === '') {
      continue;
    }
    if ($type === 'mcq') {
      if (count($answers) < 4) {
        continue;
      }
      $answers = array_map(static fn($value) => trim((string)$value), array_slice($answers, 0, 4));
      if (count(array_filter($answers, static fn($value) => $value !== '')) < 4) {
        continue;
      }
    } else {
      $answers = [];
    }
    $usedCodes[$code] = true;
    $items[] = [
      'code' => $code,
      'type' => $type,
      'question' => $question,
      // Answer at index 0 is the correct answer.
      'answers' => $answers
    ];
  }
  return $items;
}

function formatAnswersQuestionHeader(string $code, string $question): string
{
  $code = strtoupper(trim($code));
  $question = trim($question);
  if ($code === '') {
    return $question;
  }
  return $question !== '' ? "{$code} | {$question}" : $code;
}

function extractCodeFromAnswersHeader(string $headerCell): string
{
  if (!preg_match('/^\s*([A-Za-z0-9_-]+)\s*(?:\||$)/', $headerCell, $m)) {
    return '';
  }
  return strtoupper(trim((string)$m[1]));
}

function loadWfqSettings(string $path): array
{
  $settings = TCQ_DEFAULT_SETTINGS;
  if (!is_file($path)) {
    return $settings;
  }
  $content = file_get_contents($path);
  if ($content === false) {
    return $settings;
  }
  $decoded = json_decode($content, true);
  if (!is_array($decoded)) {
    return $settings;
  }
  $settings['answerTimeLimit'] = (bool)($decoded['answerTimeLimit'] ?? $settings['answerTimeLimit']);
  $settings['randomOrder'] = (bool)($decoded['randomOrder'] ?? $settings['randomOrder']);
  return $settings;
}

function readQuestionColumnsFromStore(string $path): array
{
  if (!is_file($path)) {
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
  $columns = [];
  $usedCodes = [];
  $legacyCounter = 1;
  foreach ($decoded as $row) {
    if (!is_array($row)) {
      continue;
    }
    $code = strtoupper(trim((string)($row['code'] ?? '')));
    if ($code === '') {
      $code = 'LEGACY' . $legacyCounter;
      $legacyCounter += 1;
    }
    if (isset($usedCodes[$code])) {
      continue;
    }
    $question = trim((string)($row['question'] ?? ''));
    if ($code === '' || $question === '') {
      continue;
    }
    $usedCodes[$code] = true;
    $columns[] = [
      'code' => $code,
      'question' => $question,
      'header' => formatAnswersQuestionHeader($code, $question)
    ];
  }
  return $columns;
}

function syncAnswersSheet(string $path, array $questionColumns): bool
{
  $rows = readInviteesCsv($path);
  $oldHeader = (isset($rows[0]) && is_array($rows[0])) ? $rows[0] : ['Work ID'];
  $workIdIndex = findHeaderIndex($oldHeader, 'Work ID');
  if ($workIdIndex < 0) {
    $oldHeader = array_merge(['Work ID'], array_values($oldHeader));
    $workIdIndex = 0;
  }

  $oldHeaderLookup = [];
  $oldHeaderByCode = [];
  foreach ($oldHeader as $idx => $name) {
    $cell = trim((string)$name);
    if ($cell !== '' && !array_key_exists($cell, $oldHeaderLookup)) {
      $oldHeaderLookup[$cell] = (int)$idx;
    }
    $code = extractCodeFromAnswersHeader($cell);
    if ($code !== '' && !array_key_exists($code, $oldHeaderByCode)) {
      $oldHeaderByCode[$code] = (int)$idx;
    }
  }

  $newHeader = ['Work ID'];
  foreach ($questionColumns as $column) {
    if (!is_array($column)) {
      continue;
    }
    $code = strtoupper(trim((string)($column['code'] ?? '')));
    $question = trim((string)($column['question'] ?? ''));
    if ($code === '' || $question === '') {
      continue;
    }
    $newHeader[] = formatAnswersQuestionHeader($code, $question);
  }

  $columnSources = [];
  for ($i = 1; $i < count($newHeader); $i += 1) {
    $headerCell = $newHeader[$i];
    $code = extractCodeFromAnswersHeader($headerCell);
    if ($code !== '' && array_key_exists($code, $oldHeaderByCode)) {
      $columnSources[] = (int)$oldHeaderByCode[$code];
      continue;
    }
    $columnSources[] = array_key_exists($headerCell, $oldHeaderLookup)
      ? (int)$oldHeaderLookup[$headerCell]
      : -1;
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

  return writeInviteesCsv($path, $syncedRows);
}

function logAnswerValue(string $answersPath, string $questionsPath, string $workId, string $questionCode, string $question, string $answer): bool
{
  $workId = trim($workId);
  $questionCode = strtoupper(trim($questionCode));
  $question = trim($question);
  if ($workId === '' || $questionCode === '' || $question === '') {
    return false;
  }

  $questionColumns = readQuestionColumnsFromStore($questionsPath);
  $questionExists = false;
  foreach ($questionColumns as $column) {
    if (strtoupper(trim((string)($column['code'] ?? ''))) === $questionCode) {
      $questionExists = true;
      break;
    }
  }
  if (!$questionExists) {
    $questionColumns[] = [
      'code' => $questionCode,
      'question' => $question,
      'header' => formatAnswersQuestionHeader($questionCode, $question)
    ];
  }
  if (!syncAnswersSheet($answersPath, $questionColumns)) {
    return false;
  }

  $rows = readInviteesCsv($answersPath);
  if (!$rows || !isset($rows[0]) || !is_array($rows[0])) {
    return false;
  }
  $header = $rows[0];
  $workIdIndex = findHeaderIndex($header, 'Work ID');
  if ($workIdIndex < 0) {
    return false;
  }
  $questionIndex = -1;
  foreach ($header as $idx => $name) {
    $code = extractCodeFromAnswersHeader((string)$name);
    if ($code !== '' && $code === $questionCode) {
      $questionIndex = (int)$idx;
      break;
    }
  }
  if ($questionIndex < 0) {
    return false;
  }

  $rowIndex = -1;
  for ($i = 1; $i < count($rows); $i += 1) {
    $value = trim((string)($rows[$i][$workIdIndex] ?? ''));
    if ($value === $workId) {
      $rowIndex = $i;
      break;
    }
  }
  if ($rowIndex < 0) {
    $rows[] = array_fill(0, count($header), '');
    $rowIndex = count($rows) - 1;
    $rows[$rowIndex][$workIdIndex] = $workId;
  }

  $needed = max(count($header), $questionIndex + 1);
  if (count($rows[$rowIndex]) < $needed) {
    $rows[$rowIndex] = array_pad($rows[$rowIndex], $needed, '');
  }
  $rows[$rowIndex][$questionIndex] = trim($answer);
  return writeInviteesCsv($answersPath, $rows);
}

function loadJsonPayload(string $path): array
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

function loadPanelSettings(): array
{
  $payload = loadJsonPayload(SETTINGS_STORE_PATH);
  $settings = is_array($payload['settings'] ?? null) ? $payload['settings'] : [];
  return array_merge(DEFAULT_PANEL_SETTINGS, $settings);
}

function formatSiteIconUrlForHtml(string $value): string
{
  $trimmed = trim($value);
  if ($trimmed === '') {
    return '';
  }
  if (preg_match('/^(?:data:|https?:\/\/|\/\/)/i', $trimmed)) {
    return $trimmed;
  }
  if (strncmp($trimmed, '/', 1) === 0 || strncmp($trimmed, './', 2) === 0 || strncmp($trimmed, '../', 3) === 0) {
    return $trimmed;
  }
  return "../../{$trimmed}";
}

function normalizeHexColorForTheme($value, string $fallback): string
{
  $color = strtoupper(trim((string)$value));
  if (preg_match('/^#[0-9A-F]{6}$/', $color)) {
    return $color;
  }
  return strtoupper($fallback);
}

function normalizeTaskTitle(string $value): string
{
  $normalized = preg_replace('/\s+/u', ' ', trim($value));
  if (!is_string($normalized)) {
    return '';
  }
  return trim($normalized);
}

function appendTaskTitle(array &$items, array &$seen, string $title): void
{
  $label = normalizeTaskTitle($title);
  if ($label === '') {
    return;
  }
  $key = mb_strtolower($label, 'UTF-8');
  if (isset($seen[$key])) {
    return;
  }
  $seen[$key] = true;
  $items[] = $label;
}

function readTaskTitlesFromJsonNode($node, array &$items, array &$seen): void
{
  if (is_string($node)) {
    appendTaskTitle($items, $seen, $node);
    return;
  }
  if (!is_array($node)) {
    return;
  }

  $isList = array_keys($node) === range(0, count($node) - 1);
  if ($isList) {
    foreach ($node as $child) {
      readTaskTitlesFromJsonNode($child, $items, $seen);
    }
    return;
  }

  foreach (['title', 'name', 'task', 'label', 'text'] as $field) {
    if (isset($node[$field]) && is_string($node[$field])) {
      appendTaskTitle($items, $seen, $node[$field]);
      break;
    }
  }
  foreach (['tasks', 'items', 'list', 'data'] as $field) {
    if (array_key_exists($field, $node)) {
      readTaskTitlesFromJsonNode($node[$field], $items, $seen);
    }
  }
}

function readTaskTitlesFromJsonFiles(string $tasksDir): array
{
  if (!is_dir($tasksDir)) {
    return [];
  }

  $paths = glob($tasksDir . DIRECTORY_SEPARATOR . '*.json');
  if (!is_array($paths) || !$paths) {
    return [];
  }
  sort($paths, SORT_NATURAL | SORT_FLAG_CASE);

  $items = [];
  $seen = [];
  foreach ($paths as $path) {
    if (!is_file($path)) {
      continue;
    }
    $content = file_get_contents($path);
    if ($content === false || trim($content) === '') {
      continue;
    }
    $decoded = json_decode($content, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
      continue;
    }
    readTaskTitlesFromJsonNode($decoded, $items, $seen);
  }
  return $items;
}

function readTaskTitlesFromJsStore(string $storePath): array
{
  if (!is_file($storePath)) {
    return [];
  }
  $content = file_get_contents($storePath);
  if ($content === false || trim($content) === '') {
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
  if (!is_array($decoded)) {
    return [];
  }

  $rows = array_values(array_filter($decoded, static fn($task): bool => is_array($task)));
  usort($rows, static function (array $left, array $right): int {
    return (int)($left['order'] ?? 0) <=> (int)($right['order'] ?? 0);
  });

  $items = [];
  $seen = [];
  foreach ($rows as $row) {
    $title = (string)($row['title'] ?? $row['name'] ?? '');
    appendTaskTitle($items, $seen, $title);
  }
  return $items;
}

function loadTaskTitles(string $tasksDir, string $storePath): array
{
  $fromJson = readTaskTitlesFromJsonFiles($tasksDir);
  if ($fromJson) {
    return $fromJson;
  }
  return readTaskTitlesFromJsStore($storePath);
}

function normalizeTaskTagCode(string $value): string
{
  $upper = strtoupper(trim($value));
  $clean = preg_replace('/[^A-Z0-9_-]+/', '', $upper);
  return is_string($clean) ? $clean : '';
}

function normalizeTaskBoolValue($value): bool
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

function normalizeTaskDateValue(string $value): string
{
  $trimmed = trim($value);
  if ($trimmed === '') {
    return '';
  }
  return preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) ? $trimmed : '';
}

function normalizeTaskTimeValue(string $value): string
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

function normalizeTaskScoreValue($value): int
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

function normalizeTaskTypeValue($value): string
{
  $token = strtolower(trim((string)$value));
  if ($token === 'quiz' || $token === 'quiz-task' || $token === 'quiz task') {
    return 'quiz';
  }
  return 'quiz';
}

function readTasksStoreItems(string $storePath): array
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

function readTaskScoreSettings(string $tasksDir, string $tagCode): array
{
  $defaults = [
    'score' => 0,
    'afterEndtimeScore' => 0
  ];
  $normalizedTag = normalizeTaskTagCode($tagCode);
  if ($normalizedTag === '') {
    return $defaults;
  }
  $path = $tasksDir . DIRECTORY_SEPARATOR . $normalizedTag . DIRECTORY_SEPARATOR . TASK_SCORE_SETTINGS_FILE;
  if (!is_file($path)) {
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
    'score' => normalizeTaskScoreValue($decoded['score'] ?? 0),
    'afterEndtimeScore' => normalizeTaskScoreValue($decoded['afterEndtimeScore'] ?? ($decoded['after_endtime_score'] ?? 0))
  ];
}

function normalizeTaskRecord(array $task, int $fallbackOrder): array
{
  $id = trim((string)($task['id'] ?? ''));
  $title = trim((string)($task['title'] ?? ($task['name'] ?? '')));
  $tagCode = normalizeTaskTagCode((string)($task['tagCode'] ?? ($task['tag_code'] ?? '')));
  $order = (int)($task['order'] ?? $fallbackOrder);
  if ($order < 1) {
    $order = $fallbackOrder;
  }
  return [
    'id' => $id,
    'title' => $title,
    'tagCode' => $tagCode,
    'taskType' => normalizeTaskTypeValue($task['taskType'] ?? ($task['task_type'] ?? 'quiz')),
    'active' => normalizeTaskBoolValue($task['active'] ?? false),
    'duration' => normalizeTaskBoolValue($task['duration'] ?? false),
    'startDate' => normalizeTaskDateValue((string)($task['startDate'] ?? ($task['start_date'] ?? ''))),
    'startTime' => normalizeTaskTimeValue((string)($task['startTime'] ?? ($task['start_time'] ?? ''))),
    'endDate' => normalizeTaskDateValue((string)($task['endDate'] ?? ($task['end_date'] ?? ''))),
    'endTime' => normalizeTaskTimeValue((string)($task['endTime'] ?? ($task['end_time'] ?? ''))),
    'order' => $order,
    'createdAt' => trim((string)($task['createdAt'] ?? ''))
  ];
}

function loadTaskRecords(string $storePath, string $tasksDir): array
{
  $rawItems = readTasksStoreItems($storePath);
  $normalized = [];
  foreach ($rawItems as $index => $item) {
    if (!is_array($item)) {
      continue;
    }
    $normalized[] = normalizeTaskRecord($item, $index + 1);
  }
  usort($normalized, static function (array $left, array $right): int {
    $orderDiff = ((int)($left['order'] ?? 0)) <=> ((int)($right['order'] ?? 0));
    if ($orderDiff !== 0) {
      return $orderDiff;
    }
    return strcmp((string)($left['createdAt'] ?? ''), (string)($right['createdAt'] ?? ''));
  });

  $result = [];
  $seenIds = [];
  $seenTagCodes = [];
  $nextOrder = 1;
  foreach ($normalized as $task) {
    $id = trim((string)($task['id'] ?? ''));
    $title = trim((string)($task['title'] ?? ''));
    $tagCode = normalizeTaskTagCode((string)($task['tagCode'] ?? ''));
    if ($id === '' || $title === '' || $tagCode === '') {
      continue;
    }
    $idKey = strtolower($id);
    $tagKey = strtolower($tagCode);
    if (isset($seenIds[$idKey]) || isset($seenTagCodes[$tagKey])) {
      continue;
    }
    $scoreSettings = readTaskScoreSettings($tasksDir, $tagCode);
    $task['id'] = $id;
    $task['title'] = $title;
    $task['tagCode'] = $tagCode;
    $task['order'] = $nextOrder;
    $task['score'] = (int)$scoreSettings['score'];
    $task['afterEndtimeScore'] = (int)$scoreSettings['afterEndtimeScore'];
    $result[] = $task;
    $seenIds[$idKey] = true;
    $seenTagCodes[$tagKey] = true;
    $nextOrder += 1;
  }
  return $result;
}

function getTehranDateTimeParts(): array
{
  try {
    $dt = new DateTime('now', new DateTimeZone('Asia/Tehran'));
  } catch (Throwable $e) {
    $dt = new DateTime('now');
  }
  return [
    'date' => $dt->format('Y-m-d'),
    'time' => $dt->format('H:i:s')
  ];
}

function parseTaskTimeToSeconds(string $value): ?int
{
  $trimmed = trim($value);
  if ($trimmed === '') {
    return null;
  }
  if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $trimmed, $m)) {
    return null;
  }
  $hours = (int)($m[1] ?? 0);
  $minutes = (int)($m[2] ?? 0);
  $seconds = isset($m[3]) ? (int)$m[3] : 0;
  return $hours * 3600 + $minutes * 60 + $seconds;
}

function compareTaskDates(string $left, string $right): ?int
{
  $l = normalizeTaskDateValue($left);
  $r = normalizeTaskDateValue($right);
  if ($l === '' || $r === '') {
    return null;
  }
  if ($l === $r) {
    return 0;
  }
  return $l > $r ? 1 : -1;
}

function parseEventTimeToSeconds(string $value): ?int
{
  return parseTaskTimeToSeconds($value);
}

function compareEventDates(string $left, string $right): ?int
{
  return compareTaskDates($left, $right);
}

function deriveGlobalEventStatus(array $settings): string
{
  $active = normalizeTaskBoolValue($settings['active'] ?? false);
  $duration = normalizeTaskBoolValue($settings['duration'] ?? false);
  if (!$duration) {
    return $active ? 'active' : 'inactive';
  }

  $startDate = normalizeTaskDateValue((string)($settings['startDate'] ?? ''));
  $endDate = normalizeTaskDateValue((string)($settings['endDate'] ?? ''));
  $startTime = normalizeTaskTimeValue((string)($settings['startTime'] ?? ''));
  $endTime = normalizeTaskTimeValue((string)($settings['endTime'] ?? ''));
  $nowParts = getTehranDateTimeParts();
  $today = (string)($nowParts['date'] ?? '');
  if ($startDate === '' || $today === '') {
    return 'inactive';
  }

  $startRelation = compareEventDates($startDate, $today);
  $endRelation = compareEventDates($endDate, $today);
  if ($startRelation === 1) {
    return 'upcoming';
  }
  if ($endRelation !== null && $endRelation === -1) {
    return 'ended';
  }

  if ($startRelation === 0 || $endRelation === 0) {
    $nowSeconds = parseEventTimeToSeconds((string)($nowParts['time'] ?? '')) ?? 0;
    $startSeconds = parseEventTimeToSeconds($startTime);
    $endSeconds = parseEventTimeToSeconds($endTime);
    if ($endSeconds !== null && $nowSeconds >= $endSeconds) {
      return 'ended';
    }
    if ($startSeconds !== null && $nowSeconds >= $startSeconds) {
      return 'active';
    }
    if ($startSeconds !== null && $nowSeconds < $startSeconds) {
      return 'upcoming';
    }
  }

  return 'active';
}

function loadGlobalEventStatus(): string
{
  $settings = loadJsonPayload(__DIR__ . '/Setting.json');
  return deriveGlobalEventStatus($settings);
}

function deriveTaskAvailabilityStatus(array $task): string
{
  $active = normalizeTaskBoolValue($task['active'] ?? false);
  $duration = normalizeTaskBoolValue($task['duration'] ?? false);
  if (!$duration) {
    return $active ? 'active' : 'inactive';
  }

  $startDate = normalizeTaskDateValue((string)($task['startDate'] ?? ''));
  $endDate = normalizeTaskDateValue((string)($task['endDate'] ?? ''));
  $startTime = normalizeTaskTimeValue((string)($task['startTime'] ?? ''));
  $endTime = normalizeTaskTimeValue((string)($task['endTime'] ?? ''));
  $nowParts = getTehranDateTimeParts();
  $today = (string)($nowParts['date'] ?? '');
  if ($startDate === '' || $today === '') {
    return 'inactive';
  }

  $startRelation = compareTaskDates($startDate, $today);
  $endRelation = compareTaskDates($endDate, $today);
  if ($startRelation === 1) {
    return 'upcoming';
  }
  if ($endRelation !== null && $endRelation === -1) {
    return 'ended';
  }

  if ($startRelation === 0 || $endRelation === 0) {
    $nowSeconds = parseTaskTimeToSeconds((string)($nowParts['time'] ?? '')) ?? 0;
    $startSeconds = parseTaskTimeToSeconds($startTime);
    $endSeconds = parseTaskTimeToSeconds($endTime);
    if ($endSeconds !== null && $nowSeconds >= $endSeconds) {
      return 'ended';
    }
    if ($startSeconds !== null && $nowSeconds >= $startSeconds) {
      return 'active';
    }
    if ($startSeconds !== null && $nowSeconds < $startSeconds) {
      return 'upcoming';
    }
  }

  return 'active';
}

function resolveTaskStatusLabel(string $status): string
{
  if ($status === 'active') {
    return 'فعال';
  }
  if ($status === 'upcoming') {
    return 'به‌زودی';
  }
  if ($status === 'ended') {
    return 'پایان‌یافته';
  }
  return 'غیرفعال';
}

function findTaskById(array $tasks, string $taskId): ?array
{
  $needle = trim($taskId);
  if ($needle === '') {
    return null;
  }
  foreach ($tasks as $task) {
    if (!is_array($task)) {
      continue;
    }
    if (trim((string)($task['id'] ?? '')) === $needle) {
      return $task;
    }
  }
  return null;
}

function parseTaskCompletedIds(string $raw): array
{
  $parts = preg_split('/\s*,\s*/', trim($raw));
  if (!is_array($parts)) {
    return [];
  }
  $seen = [];
  $ids = [];
  foreach ($parts as $part) {
    $id = trim((string)$part);
    if ($id === '' || isset($seen[$id])) {
      continue;
    }
    $seen[$id] = true;
    $ids[] = $id;
  }
  return $ids;
}

function parseTaskScoreMap(string $raw): array
{
  $entries = preg_split('/\s*,\s*/', trim($raw));
  if (!is_array($entries)) {
    return [];
  }
  $map = [];
  foreach ($entries as $entry) {
    $token = trim((string)$entry);
    if ($token === '') {
      continue;
    }
    $parts = explode(':', $token, 2);
    if (count($parts) !== 2) {
      continue;
    }
    $taskId = trim((string)($parts[0] ?? ''));
    $score = normalizeTaskScoreValue($parts[1] ?? 0);
    if ($taskId === '') {
      continue;
    }
    $map[$taskId] = $score;
  }
  return $map;
}

function serializeTaskScoreMap(array $map): string
{
  $items = [];
  foreach ($map as $taskId => $score) {
    $normalizedId = trim((string)$taskId);
    if ($normalizedId === '') {
      continue;
    }
    $normalizedScore = normalizeTaskScoreValue($score);
    $items[] = $normalizedId . ':' . (string)$normalizedScore;
  }
  return implode(',', $items);
}

function serializeTaskCompletedIds(array $ids): string
{
  $seen = [];
  $normalized = [];
  foreach ($ids as $id) {
    $token = trim((string)$id);
    if ($token === '' || isset($seen[$token])) {
      continue;
    }
    $seen[$token] = true;
    $normalized[] = $token;
  }
  return implode(',', $normalized);
}

function readTaskUserProgress(array $task, string $inviteesPath, string $inviteesMapPath, string $workId): array
{
  $defaults = [
    'score' => 0,
    'answered' => 0,
    'completed' => false
  ];
  $normalizedWorkId = trim($workId);
  $taskId = trim((string)($task['id'] ?? ''));
  if ($normalizedWorkId === '' || $taskId === '') {
    return $defaults;
  }

  $table = loadInviteesTable($inviteesPath, $inviteesMapPath);
  $rows = is_array($table['rows'] ?? null) ? $table['rows'] : [];
  $columns = is_array($table['columns']['index'] ?? null) ? $table['columns']['index'] : [];
  $workIdIndex = (int)($table['workIdIndex'] ?? -1);
  $rowIndex = findInviteeRowIndex($rows, $workIdIndex, $normalizedWorkId);
  if ($rowIndex < 0) {
    return $defaults;
  }

  $taskCompletedIndex = (int)($columns['task completed ids'] ?? -1);
  $taskScoreMapIndex = (int)($columns['task score map'] ?? -1);
  $completedIds = [];
  if ($taskCompletedIndex >= 0) {
    $completedIds = parseTaskCompletedIds((string)($rows[$rowIndex][$taskCompletedIndex] ?? ''));
  }
  $isCompleted = in_array($taskId, $completedIds, true);
  $taskScoreMap = [];
  if ($taskScoreMapIndex >= 0) {
    $taskScoreMap = parseTaskScoreMap((string)($rows[$rowIndex][$taskScoreMapIndex] ?? ''));
  }
  $taskScore = 0;
  if ($isCompleted) {
    if (isset($taskScoreMap[$taskId])) {
      $taskScore = max(0, (int)$taskScoreMap[$taskId]);
    } else {
      $taskScore = max(0, (int)($task['score'] ?? 0));
    }
  }

  return [
    'score' => $isCompleted ? $taskScore : 0,
    'answered' => $isCompleted ? 1 : 0,
    'completed' => $isCompleted
  ];
}

function computeUserTotalTaskScore(string $inviteesPath, string $inviteesMapPath, string $workId): int
{
  $normalizedWorkId = trim($workId);
  if ($normalizedWorkId === '') {
    return 0;
  }

  $table = loadInviteesTable($inviteesPath, $inviteesMapPath);
  $rows = is_array($table['rows'] ?? null) ? $table['rows'] : [];
  $columns = is_array($table['columns']['index'] ?? null) ? $table['columns']['index'] : [];
  $workIdIndex = (int)($table['workIdIndex'] ?? -1);
  $rowIndex = findInviteeRowIndex($rows, $workIdIndex, $normalizedWorkId);
  if ($rowIndex < 0) {
    return 0;
  }

  $scoreIndex = (int)($columns['score'] ?? -1);
  if ($scoreIndex < 0) {
    return 0;
  }
  return max(0, (int)($rows[$rowIndex][$scoreIndex] ?? 0));
}

function buildTaskPayloadForView(array $tasks, string $inviteesPath, string $inviteesMapPath, string $workId): array
{
  $items = [];
  foreach ($tasks as $task) {
    if (!is_array($task)) {
      continue;
    }
    $status = deriveTaskAvailabilityStatus($task);
    $taskType = normalizeTaskTypeValue($task['taskType'] ?? 'quiz');
    $isActive = $status === 'active';
    $isEndedQuiz = $taskType === 'quiz' && $status === 'ended';
    $progress = readTaskUserProgress($task, $inviteesPath, $inviteesMapPath, $workId);
    $completed = (bool)($progress['completed'] ?? false);
    $statusLabel = $completed ? 'تکمیل شده' : resolveTaskStatusLabel($status);
    $items[] = [
      'id' => (string)($task['id'] ?? ''),
      'title' => (string)($task['title'] ?? ''),
      'tagCode' => (string)($task['tagCode'] ?? ''),
      'taskType' => $taskType,
      'active' => (bool)($task['active'] ?? false),
      'duration' => (bool)($task['duration'] ?? false),
      'startDate' => (string)($task['startDate'] ?? ''),
      'startTime' => (string)($task['startTime'] ?? ''),
      'endDate' => (string)($task['endDate'] ?? ''),
      'endTime' => (string)($task['endTime'] ?? ''),
      'score' => (int)($task['score'] ?? 0),
      'afterEndtimeScore' => (int)($task['afterEndtimeScore'] ?? 0),
      'status' => $status,
      'statusLabel' => $statusLabel,
      'completed' => $completed,
      'available' => ($isActive || $isEndedQuiz) && !$completed,
      'userScore' => (int)($progress['score'] ?? 0)
    ];
  }
  return $items;
}

function readInviteesCsv(string $path): array
{
  if (!is_file($path)) {
    return [];
  }
  $rows = [];
  if (($handle = fopen($path, 'r')) !== false) {
    while (($data = fgetcsv($handle)) !== false) {
      $rows[] = $data;
    }
    fclose($handle);
  }
  return $rows;
}

function writeInviteesCsv(string $path, array $rows): bool
{
  $dir = dirname($path);
  if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
  }
  $handle = fopen($path, 'c+');
  if ($handle == false) {
    return false;
  }
  if (!flock($handle, LOCK_EX)) {
    fclose($handle);
    return false;
  }
  ftruncate($handle, 0);
  rewind($handle);
  foreach ($rows as $row) {
    fputcsv($handle, $row);
  }
  fflush($handle);
  flock($handle, LOCK_UN);
  fclose($handle);
  return true;
}

function readInviteesMapping(string $path): array
{
  if (!is_file($path)) {
    return [];
  }
  $data = json_decode(file_get_contents($path), true);
  return is_array($data) ? $data : [];
}

function readLoginAttempts(string $path): array
{
  if (!is_file($path)) {
    return [];
  }
  $data = json_decode(file_get_contents($path), true);
  return is_array($data) ? $data : [];
}

function writeLoginAttempts(string $path, array $payload): bool
{
  $dir = dirname($path);
  if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
  }
  $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($json === false) {
    return false;
  }
  return file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

function normalizeHeaderName(string $value): string
{
  $value = trim(mb_strtolower($value, 'UTF-8'));
  $value = preg_replace('/\s+/', ' ', $value);
  return $value ?? '';
}

function findHeaderIndex(array $header, string $needle): int
{
  $needle = normalizeHeaderName($needle);
  foreach ($header as $index => $value) {
    if (normalizeHeaderName((string)$value) === $needle) {
      return (int)$index;
    }
  }
  return -1;
}

function findFirstHeaderIndex(array $header, array $needles): int
{
  foreach ($needles as $needle) {
    $index = findHeaderIndex($header, (string)$needle);
    if ($index >= 0) {
      return $index;
    }
  }
  return -1;
}

function resolveInviteeFullName(array $header, array $mapping, array $row, string $fallbackWorkId): string
{
  $mappingKeys = ['fullName', 'full_name', 'full name', 'name', 'displayName', 'display_name', 'display name'];
  foreach ($mappingKeys as $key) {
    $mappedIndex = $mapping[$key] ?? null;
    if (is_numeric($mappedIndex) && (int)$mappedIndex >= 0) {
      $index = (int)$mappedIndex;
      $value = trim((string)($row[$index] ?? ''));
      if ($value !== '') {
        return $value;
      }
    }
  }
  $nameIndex = findFirstHeaderIndex($header, [
    'full name',
    'fullname',
    'display name',
    'name',
    'first name',
    'last name',
    'first name last name',
    'full name',
    'fullname',
    'name',
    'first name'
  ]);
  if ($nameIndex >= 0) {
    $value = trim((string)($row[$nameIndex] ?? ''));
    if ($value !== '') {
      return $value;
    }
  }
  return $fallbackWorkId;
}

function parseEpochValue($raw): ?int
{
  if (is_int($raw) || is_float($raw) || (is_string($raw) && preg_match('/^\d+$/', trim($raw)))) {
    $ts = (int)$raw;
    if ($ts > 0) {
      return $ts;
    }
  }
  $text = trim((string)$raw);
  if ($text === '') {
    return null;
  }
  $parsed = strtotime($text);
  if ($parsed === false || $parsed <= 0) {
    return null;
  }
  return $parsed;
}

function ensureInviteeColumns(array &$rows, array $columns): array
{
  if (!$rows) {
    return ['index' => [], 'added' => false];
  }
  $header = $rows[0];
  $added = false;
  $index = [];
  foreach ($columns as $column) {
    $colIndex = findHeaderIndex($header, $column);
    if ($colIndex < 0) {
      $header[] = $column;
      $colIndex = count($header) - 1;
      $added = true;
      for ($i = 1; $i < count($rows); $i += 1) {
        if (!is_array($rows[$i])) {
          $rows[$i] = [];
        }
        $rows[$i][$colIndex] = '';
      }
    }
    $index[$column] = $colIndex;
  }
  $rows[0] = $header;
  return ['index' => $index, 'added' => $added];
}

function loadInviteesTable(string $filePath, string $mapPath): array
{
  $rows = readInviteesCsv($filePath);
  if (!$rows || !isset($rows[0]) || !is_array($rows[0])) {
    return [
      'rows' => [],
      'mapping' => [],
      'columns' => [],
      'header' => [],
      'workIdIndex' => -1
    ];
  }
  $mapping = readInviteesMapping($mapPath);
  $columns = ensureInviteeColumns($rows, [
    'password',
    'logins counts',
    'logins',
    'count of rolls',
    'prize won',
    'prize won at',
    'wheel angle',
    'invitees',
    'answers',
    'score',
    'Answered',
    'task completed ids',
    'task score map',
    'Card Flips Count',
    'Each Level Won Prize',
    'Total Prize Won',
    'Reward Level Won IDs',
    'Out of Value Rewards'
  ]);
  $header = $rows[0];
  $workIdIndex = (int)($mapping['workId'] ?? -1);
  if ($workIdIndex < 0 || $workIdIndex >= count($header)) {
    $workIdIndex = findHeaderIndex($header, 'work id');
  }
  return [
    'rows' => $rows,
    'mapping' => $mapping,
    'columns' => $columns,
    'header' => $header,
    'workIdIndex' => $workIdIndex
  ];
}

function findInviteeRowIndex(array $rows, int $workIdIndex, string $workId): int
{
  if ($workIdIndex < 0) {
    return -1;
  }
  for ($i = 1; $i < count($rows); $i += 1) {
    $row = $rows[$i] ?? [];
    $value = trim((string)($row[$workIdIndex] ?? ''));
    if ($value !== '' && $value === $workId) {
      return $i;
    }
  }
  return -1;
}

function parseQuestionOrder(string $value, array $questionCodes): array
{
  $questionCount = count($questionCodes);
  if ($questionCount <= 0) {
    return [];
  }
  $codeSet = [];
  foreach ($questionCodes as $code) {
    $codeSet[(string)$code] = true;
  }
  $pairs = preg_split('/\s*,\s*/', trim($value));
  if (!is_array($pairs) || !$pairs) {
    return [];
  }
  $sequence = [];
  foreach ($pairs as $pair) {
    if (!preg_match('/^\s*(\d+)\s*::\s*([^\s,]+)\s*$/', (string)$pair, $m)) {
      return [];
    }
    $order = (int)$m[1];
    $sourceToken = trim((string)$m[2]);
    if ($order < 1 || $order > $questionCount) {
      return [];
    }
    $sourceCode = '';
    if (ctype_digit($sourceToken)) {
      // Backward compatibility with old "order::sourceIndex" format.
      $sourceIndex = (int)$sourceToken;
      if ($sourceIndex < 1 || $sourceIndex > $questionCount) {
        return [];
      }
      $sourceCode = (string)$questionCodes[$sourceIndex - 1];
    } else {
      $sourceCode = $sourceToken;
    }
    if ($sourceCode === '' || !isset($codeSet[$sourceCode])) {
      return [];
    }
    $sequence[$order] = $sourceCode;
  }
  if (count($sequence) !== $questionCount) {
    return [];
  }
  ksort($sequence, SORT_NUMERIC);
  $sourceCodes = array_values($sequence);
  if (count(array_unique($sourceCodes)) !== $questionCount) {
    return [];
  }
  return $sourceCodes;
}

function serializeQuestionOrder(array $sourceOrder): string
{
  $parts = [];
  foreach (array_values($sourceOrder) as $index => $source) {
    $parts[] = ($index + 1) . '::' . trim((string)$source);
  }
  return implode(',', $parts);
}

function buildRandomQuestionOrder(array $questionCodes): array
{
  if (!$questionCodes) {
    return [];
  }
  $source = array_values(array_map(static fn($v) => (string)$v, $questionCodes));
  shuffle($source);
  return $source;
}

function clampAnsweredCount($raw, int $questionCount): int
{
  $value = is_numeric($raw) ? (int)$raw : 0;
  if ($value < 0) {
    return 0;
  }
  if ($value > $questionCount) {
    return $questionCount;
  }
  return $value;
}

function ensureUserQuestionProgress(array &$rows, int $rowIndex, array $columns, array $questionCodes, bool $randomOrderEnabled): array
{
  $questionCount = count($questionCodes);
  $inviteesIndex = $columns['invitees'] ?? -1;
  $answeredIndex = $columns['Answered'] ?? -1;
  if ($rowIndex < 1 || $inviteesIndex < 0 || $answeredIndex < 0) {
    return ['order' => [], 'answered' => 0, 'changed' => false];
  }
  $changed = false;
  if ($questionCount <= 0) {
    $currentInvitees = trim((string)($rows[$rowIndex][$inviteesIndex] ?? ''));
    $currentAnswered = trim((string)($rows[$rowIndex][$answeredIndex] ?? ''));
    if ($currentInvitees !== '') {
      $rows[$rowIndex][$inviteesIndex] = '';
      $changed = true;
    }
    if ($currentAnswered !== '' && $currentAnswered !== '0') {
      $rows[$rowIndex][$answeredIndex] = '0';
      $changed = true;
    }
    return ['order' => [], 'answered' => 0, 'changed' => $changed];
  }

  $storedOrder = trim((string)($rows[$rowIndex][$inviteesIndex] ?? ''));
  if (!$randomOrderEnabled) {
    $order = array_values(array_map(static fn($v) => (string)$v, $questionCodes));
    $serialized = serializeQuestionOrder($order);
    if ($storedOrder !== $serialized) {
      $rows[$rowIndex][$inviteesIndex] = $serialized;
      $changed = true;
    }
    $answered = clampAnsweredCount($rows[$rowIndex][$answeredIndex] ?? 0, $questionCount);
    if ((string)($rows[$rowIndex][$answeredIndex] ?? '') !== (string)$answered) {
      $rows[$rowIndex][$answeredIndex] = (string)$answered;
      $changed = true;
    }
    return ['order' => $order, 'answered' => $answered, 'changed' => $changed];
  }

  $order = parseQuestionOrder($storedOrder, $questionCodes);
  if (!$order) {
    $order = buildRandomQuestionOrder($questionCodes);
    $rows[$rowIndex][$inviteesIndex] = serializeQuestionOrder($order);
    $rows[$rowIndex][$answeredIndex] = '0';
    $changed = true;
    return ['order' => $order, 'answered' => 0, 'changed' => $changed];
  }
  $normalizedStoredOrder = serializeQuestionOrder($order);
  if ($storedOrder !== $normalizedStoredOrder) {
    $rows[$rowIndex][$inviteesIndex] = $normalizedStoredOrder;
    $changed = true;
  }

  $answered = clampAnsweredCount($rows[$rowIndex][$answeredIndex] ?? 0, $questionCount);
  if ((string)($rows[$rowIndex][$answeredIndex] ?? '') !== (string)$answered) {
    $rows[$rowIndex][$answeredIndex] = (string)$answered;
    $changed = true;
  }
  return ['order' => $order, 'answered' => $answered, 'changed' => $changed];
}


if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  header('Content-Type: application/json; charset=UTF-8');
  $rawInput = file_get_contents('php://input');
  $payload = json_decode($rawInput ?: '', true);
  $action = is_array($payload) ? (string)($payload['action'] ?? '') : '';
  $csrfToken = is_array($payload) ? (string)($payload['csrf'] ?? '') : '';
  if ($csrfToken === '' || !hash_equals((string)($_SESSION['tc_csrf'] ?? ''), $csrfToken)) {
    echo json_encode(['status' => 'error', 'message' => 'درخواست نامعتبر است.']);
    exit;
  }


  if ($action === 'login') {
    $tcqSettings = loadWfqSettings($tcqSettingsPath);
    $maxAttempts = 5;
    $windowSeconds = 10 * 60;
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $username = trim((string)($payload['username'] ?? ''));
    $password = trim((string)($payload['password'] ?? ''));
    $attemptKey = $ip . '|' . $username;
    $attempts = readLoginAttempts($loginAttemptsPath);
    $now = time();
    $entry = is_array($attempts[$attemptKey] ?? null) ? $attempts[$attemptKey] : ['fails' => []];
    $fails = array_values(array_filter($entry['fails'] ?? [], function ($ts) use ($now, $windowSeconds) {
      return is_numeric($ts) && ($now - (int)$ts) <= $windowSeconds;
    }));
    if (count($fails) >= $maxAttempts) {
      echo json_encode(['status' => 'error', 'message' => 'Too many failed attempts. Please try again later.']);
      exit;
    }
    $recordFail = function () use (&$attempts, $attemptKey, $now, &$fails, $loginAttemptsPath) {
      $fails[] = $now;
      $attempts[$attemptKey] = ['fails' => $fails];
      writeLoginAttempts($loginAttemptsPath, $attempts);
    };
    if ($username === '' || $password === '') {
      $recordFail();
      echo json_encode(['status' => 'error', 'message' => 'Please enter both username and password.']);
      exit;
    }
    $table = loadInviteesTable($inviteesFilePath, $inviteesMapPath);
    $rows = $table['rows'];
    if (!$rows) {
      $recordFail();
      echo json_encode(['status' => 'error', 'message' => 'کاربری یافت نشد.']);
      exit;
    }
    $workIdIndex = $table['workIdIndex'];
    if ($workIdIndex < 0) {
      $recordFail();
      echo json_encode(['status' => 'error', 'message' => 'نام کاربری column is missing.']);
      exit;
    }
    $columns = $table['columns']['index'] ?? [];
    $passwordIndex = $columns['password'] ?? findHeaderIndex($table['header'], 'password');
    if ($passwordIndex < 0) {
      $recordFail();
      echo json_encode(['status' => 'error', 'message' => 'رمز عبور column is missing.']);
      exit;
    }
    $rowIndex = findInviteeRowIndex($rows, $workIdIndex, $username);
    if ($rowIndex < 0) {
      $recordFail();
      echo json_encode(['status' => 'error', 'message' => 'Invalid username or password.']);
      exit;
    }
    $rowPassword = trim((string)($rows[$rowIndex][$passwordIndex] ?? ''));
    if ($rowPassword === '' || $rowPassword !== $password) {
      $recordFail();
      echo json_encode(['status' => 'error', 'message' => 'Invalid username or password.']);
      exit;
    }
    $loginCountIndex = $columns['logins counts'] ?? -1;
    $loginListIndex = $columns['logins'] ?? -1;
    if ($loginCountIndex >= 0) {
      $count = (int)($rows[$rowIndex][$loginCountIndex] ?? 0);
      $rows[$rowIndex][$loginCountIndex] = (string)($count + 1);
    }
    if ($loginListIndex >= 0) {
      $stamp = date('Y-m-d H:i:s');
      $existing = trim((string)($rows[$rowIndex][$loginListIndex] ?? ''));
      $rows[$rowIndex][$loginListIndex] = $existing !== '' ? ($existing . ', ' . $stamp) : $stamp;
    }
    if (($table['columns']['added'] ?? false) && $rows) {
      writeInviteesCsv($inviteesFilePath, $rows);
    } else if (!writeInviteesCsv($inviteesFilePath, $rows)) {
      $recordFail();
      echo json_encode(['status' => 'error', 'message' => 'ذخیره اطلاعات ورود ناموفق بود.']);
      exit;
    }
    if (isset($attempts[$attemptKey])) {
      unset($attempts[$attemptKey]);
      writeLoginAttempts($loginAttemptsPath, $attempts);
    }
    $_SESSION['tc_authed'] = true;
    $_SESSION['tc_work_id'] = $username;
    $_SESSION['tc_invitees_mtime'] = is_file($inviteesFilePath) ? filemtime($inviteesFilePath) : null;
    $prizeIndex = $columns['prize won'] ?? -1;
    $prizeWonAtIndex = $columns['prize won at'] ?? -1;
    $angleIndex = $columns['wheel angle'] ?? -1;
    $questions = readQuestionStore($questionsStorePath);
    $questionCodes = array_values(array_map(static fn($item) => (string)($item['code'] ?? ''), $questions));
    $quizState = ensureUserQuestionProgress($rows, $rowIndex, $columns, $questionCodes, (bool)$tcqSettings['randomOrder']);
    $prizeWon = $prizeIndex >= 0 ? trim((string)($rows[$rowIndex][$prizeIndex] ?? '')) : '';
    $fullName = resolveInviteeFullName($table['header'] ?? [], $table['mapping'] ?? [], $rows[$rowIndex] ?? [], $username);
    $prizeWonAt = null;
    if ($prizeWonAtIndex >= 0) {
      $prizeWonAt = parseEpochValue($rows[$rowIndex][$prizeWonAtIndex] ?? null);
    }
    $wheelAngle = null;
    if ($angleIndex >= 0) {
      $angleValue = trim((string)($rows[$rowIndex][$angleIndex] ?? ''));
      if ($angleValue !== '' && is_numeric($angleValue)) {
        $wheelAngle = (float)$angleValue;
      }
    }
    echo json_encode([
      'status' => 'ok',
      'fullName' => $fullName,
      'prizeWon' => $prizeWon,
      'prizeWonAt' => $prizeWonAt,
      'wheelAngle' => $wheelAngle,
      'quizOrder' => $quizState['order'],
      'answered' => $quizState['answered']
    ]);
    exit;
  }

  if ($action === 'logout') {
    session_unset();
    session_destroy();
    echo json_encode(['status' => 'ok']);
    exit;
  }

  if ($action === 'task_fetch') {
    $sessionWorkId = (string)($_SESSION['tc_work_id'] ?? '');
    if (!(($_SESSION['tc_authed'] ?? false) && $sessionWorkId !== '')) {
      echo json_encode(['status' => 'error', 'message' => 'ابتدا وارد شوید.']);
      exit;
    }
    $eventStatus = loadGlobalEventStatus();
    if ($eventStatus === 'inactive') {
      echo json_encode(['status' => 'error', 'message' => 'فعلا رویداد فعالی وجود ندارد.']);
      exit;
    }

    $taskId = trim((string)($payload['taskId'] ?? ''));
    if ($taskId === '') {
      echo json_encode(['status' => 'error', 'message' => 'Please select a task.']);
      exit;
    }

    $tasks = loadTaskRecords(TASKS_JS_STORE_PATH, TASKS_DIR_PATH);
    $task = findTaskById($tasks, $taskId);
    if (!is_array($task)) {
      echo json_encode(['status' => 'error', 'message' => 'ماموریت پیدا نشد.']);
      exit;
    }

    $status = deriveTaskAvailabilityStatus($task);
    $taskType = normalizeTaskTypeValue($task['taskType'] ?? 'quiz');
    $available = $status === 'active' || ($taskType === 'quiz' && $status === 'ended');
    $tagCode = normalizeTaskTagCode((string)($task['tagCode'] ?? ''));
    $taskDir = TASKS_DIR_PATH . DIRECTORY_SEPARATOR . $tagCode;
    $questionPath = $taskDir . DIRECTORY_SEPARATOR . 'TCQ list.json';
    $settingsPath = $taskDir . DIRECTORY_SEPARATOR . 'TCQ settings.json';
    $questions = readQuestionStore($questionPath);
    if (!$questions) {
      // Backward compatibility: use shared questions if task-specific file is empty.
      $questions = readQuestionStore($questionsStorePath);
    }
    $settings = loadWfqSettings($settingsPath);
    $progress = readTaskUserProgress($task, $inviteesFilePath, $inviteesMapPath, $sessionWorkId);
    echo json_encode([
      'status' => 'ok',
      'task' => [
        'id' => (string)($task['id'] ?? ''),
        'title' => (string)($task['title'] ?? ''),
        'tagCode' => $tagCode,
        'taskType' => $taskType,
        'score' => (int)($task['score'] ?? 0),
        'afterEndtimeScore' => (int)($task['afterEndtimeScore'] ?? 0),
        'status' => $status,
        'available' => $available,
        'statusLabel' => resolveTaskStatusLabel($status)
      ],
      'questions' => $questions,
      'settings' => $settings,
      'progress' => $progress
    ]);
    exit;
  }

  if ($action === 'task_log_answer') {
    $sessionWorkId = (string)($_SESSION['tc_work_id'] ?? '');
    if (!(($_SESSION['tc_authed'] ?? false) && $sessionWorkId !== '')) {
      echo json_encode(['status' => 'error', 'message' => 'ابتدا وارد شوید.']);
      exit;
    }
    $eventStatus = loadGlobalEventStatus();
    if ($eventStatus === 'inactive') {
      echo json_encode(['status' => 'error', 'message' => 'فعلا رویداد فعالی وجود ندارد.']);
      exit;
    }
    echo json_encode(['status' => 'ok']);
    exit;
  }

  if ($action === 'task_complete') {
    $sessionWorkId = (string)($_SESSION['tc_work_id'] ?? '');
    if (!(($_SESSION['tc_authed'] ?? false) && $sessionWorkId !== '')) {
      echo json_encode(['status' => 'error', 'message' => 'ابتدا وارد شوید.']);
      exit;
    }
    $eventStatus = loadGlobalEventStatus();
    if ($eventStatus === 'inactive') {
      echo json_encode(['status' => 'error', 'message' => 'فعلا رویداد فعالی وجود ندارد.']);
      exit;
    }

    $taskId = trim((string)($payload['taskId'] ?? ''));
    if ($taskId === '') {
      echo json_encode(['status' => 'error', 'message' => 'Please select a task.']);
      exit;
    }

    $tasks = loadTaskRecords(TASKS_JS_STORE_PATH, TASKS_DIR_PATH);
    $task = findTaskById($tasks, $taskId);
    if (!is_array($task)) {
      echo json_encode(['status' => 'error', 'message' => 'ماموریت پیدا نشد.']);
      exit;
    }

    $status = deriveTaskAvailabilityStatus($task);
    $taskType = normalizeTaskTypeValue($task['taskType'] ?? 'quiz');
    $canComplete = $status === 'active' || ($taskType === 'quiz' && $status === 'ended');
    if (!$canComplete) {
      echo json_encode(['status' => 'error', 'message' => 'This task is not available right now.']);
      exit;
    }

    $table = loadInviteesTable($inviteesFilePath, $inviteesMapPath);
    $rows = is_array($table['rows'] ?? null) ? $table['rows'] : [];
    $columns = is_array($table['columns']['index'] ?? null) ? $table['columns']['index'] : [];
    $workIdIndex = (int)($table['workIdIndex'] ?? -1);
    $rowIndex = findInviteeRowIndex($rows, $workIdIndex, $sessionWorkId);
    if ($rowIndex < 0) {
      echo json_encode(['status' => 'error', 'message' => 'رکورد کاربر پیدا نشد.']);
      exit;
    }

    $header = is_array($rows[0] ?? null) ? $rows[0] : [];
    $rowLength = count($header);
    if (!isset($rows[$rowIndex]) || !is_array($rows[$rowIndex])) {
      $rows[$rowIndex] = [];
    }
    if (count($rows[$rowIndex]) < $rowLength) {
      $rows[$rowIndex] = array_pad($rows[$rowIndex], $rowLength, '');
    }

    $scoreIndex = (int)($columns['score'] ?? -1);
    $taskCompletedIndex = (int)($columns['task completed ids'] ?? -1);
    $taskScoreMapIndex = (int)($columns['task score map'] ?? -1);
    if ($scoreIndex < 0 || $taskCompletedIndex < 0 || $taskScoreMapIndex < 0) {
      echo json_encode(['status' => 'error', 'message' => 'امتیاز columns are not ready.']);
      exit;
    }

    $currentTotalScore = max(0, (int)($rows[$rowIndex][$scoreIndex] ?? 0));
    $completedTaskIds = parseTaskCompletedIds((string)($rows[$rowIndex][$taskCompletedIndex] ?? ''));
    $activeScore = max(0, (int)($task['score'] ?? 0));
    $afterEndScore = max(0, (int)($task['afterEndtimeScore'] ?? 0));
    $awardedScore = ($taskType === 'quiz' && $status === 'ended') ? $afterEndScore : $activeScore;
    $taskScoreMap = parseTaskScoreMap((string)($rows[$rowIndex][$taskScoreMapIndex] ?? ''));

    if (in_array($taskId, $completedTaskIds, true)) {
      echo json_encode([
        'status' => 'ok',
        'alreadyCompleted' => true,
        'awardedScore' => 0,
        'userTaskScore' => $awardedScore,
        'totalScore' => $currentTotalScore
      ]);
      exit;
    }

    $completedTaskIds[] = $taskId;
    $taskScoreMap[$taskId] = $awardedScore;
    $newTotalScore = $currentTotalScore + $awardedScore;
    $rows[$rowIndex][$scoreIndex] = (string)$newTotalScore;
    $rows[$rowIndex][$taskCompletedIndex] = serializeTaskCompletedIds($completedTaskIds);
    $rows[$rowIndex][$taskScoreMapIndex] = serializeTaskScoreMap($taskScoreMap);
    $outOfValueLevels = readPrizeLevelRecords($prizeLevelsPath);
    syncOutOfValueRewardsForUser($rows, $rowIndex, $columns, $outOfValueLevels, $newTotalScore);

    if (!writeInviteesCsv($inviteesFilePath, $rows)) {
      echo json_encode(['status' => 'error', 'message' => 'ذخیره امتیاز ناموفق بود.']);
      exit;
    }

    echo json_encode([
      'status' => 'ok',
      'alreadyCompleted' => false,
      'awardedScore' => $awardedScore,
      'userTaskScore' => $awardedScore,
      'scoreMode' => ($taskType === 'quiz' && $status === 'ended') ? 'after_endtime' : 'active',
      'totalScore' => $newTotalScore
    ]);
    exit;
  }

  if ($action === 'reward_state') {
    $sessionWorkId = (string)($_SESSION['tc_work_id'] ?? '');
    if (!(($_SESSION['tc_authed'] ?? false) && $sessionWorkId !== '')) {
      echo json_encode(['status' => 'error', 'message' => 'ابتدا وارد شوید.']);
      exit;
    }
    $eventStatus = loadGlobalEventStatus();
    $table = loadInviteesTable($inviteesFilePath, $inviteesMapPath);
    $rows = $table['rows'];
    $workIdIndex = (int)($table['workIdIndex'] ?? -1);
    $columns = is_array($table['columns']['index'] ?? null) ? $table['columns']['index'] : [];
    $rowIndex = findInviteeRowIndex($rows, $workIdIndex, $sessionWorkId);
    if ($rowIndex < 0) {
      echo json_encode(['status' => 'error', 'message' => 'رکورد کاربر پیدا نشد.']);
      exit;
    }
    $scoreIndex = (int)($columns['score'] ?? -1);
    $flipCountIndex = (int)($columns['Card Flips Count'] ?? -1);
    $wonPrizeIndex = (int)($columns['Each Level Won Prize'] ?? -1);
    $totalPrizeWonIndex = (int)($columns['Total Prize Won'] ?? ($columns['مجموع جوایز برنده شده'] ?? -1));
    $wonLevelIdsIndex = (int)($columns['Reward Level Won IDs'] ?? -1);

    $userScore = $scoreIndex >= 0 ? max(0, (int)($rows[$rowIndex][$scoreIndex] ?? 0)) : 0;
    $cardFlipsCount = $flipCountIndex >= 0 ? max(0, (int)($rows[$rowIndex][$flipCountIndex] ?? 0)) : 0;
    $eachLevelWonPrizeRaw = $wonPrizeIndex >= 0 ? trim((string)($rows[$rowIndex][$wonPrizeIndex] ?? '')) : '';
    $totalPrizeWon = $totalPrizeWonIndex >= 0 ? max(0, normalizeFloatValue($rows[$rowIndex][$totalPrizeWonIndex] ?? 0)) : 0.0;
    $wonLevelIdsRaw = $wonLevelIdsIndex >= 0 ? trim((string)($rows[$rowIndex][$wonLevelIdsIndex] ?? '')) : '';
    $wonLevelIds = parseCommaSeparatedList($wonLevelIdsRaw);
    $wonSet = [];
    foreach ($wonLevelIds as $token) {
      $wonSet[$token] = true;
    }
    $wonPrizeEntries = parseCommaSeparatedList($eachLevelWonPrizeRaw);
    $wonPrizeNamesOrdered = [];
    foreach ($wonPrizeEntries as $entry) {
      $part = trim((string)$entry);
      if ($part === '') {
        continue;
      }
      $separatorIndex = strpos($part, ':');
      if ($separatorIndex === false || $separatorIndex <= 0) {
        continue;
      }
      $prizeName = trim((string)substr($part, $separatorIndex + 1));
      if ($prizeName !== '') {
        $wonPrizeNamesOrdered[] = $prizeName;
      }
    }
    $wonPrizeByLevelId = [];
    foreach ($wonLevelIds as $idx => $levelIdToken) {
      $levelIdToken = trim((string)$levelIdToken);
      if ($levelIdToken === '') {
        continue;
      }
      $wonPrizeByLevelId[$levelIdToken] = (string)($wonPrizeNamesOrdered[$idx] ?? '');
    }

    $levels = readPrizeLevelRecords($prizeLevelsPath);
    $outOfValueChanged = syncOutOfValueRewardsForUser($rows, $rowIndex, $columns, $levels, $userScore);
    if ((($table['columns']['added'] ?? false) || $outOfValueChanged) && $rows) {
      writeInviteesCsv($inviteesFilePath, $rows);
    }
    $levelPayload = [];
    foreach ($levels as $level) {
      $levelId = (string)($level['id'] ?? '');
      $type = (string)($level['type'] ?? 'value_sum');
      $reached = $userScore >= (int)($level['score'] ?? 0);
      $won = $levelId !== '' && isset($wonSet[$levelId]);
      $canFlip = $eventStatus === 'active' && $reached && !$won && $type === 'value_sum';
      $levelPayload[] = [
        'id' => $levelId,
        'name' => (string)($level['name'] ?? ''),
        'type' => $type,
        'score' => (int)($level['score'] ?? 0),
        'reached' => $reached,
        'won' => $won,
        'wonPrize' => (string)($wonPrizeByLevelId[$levelId] ?? ''),
        'canFlip' => $canFlip
      ];
    }

    $prizes = readPrizeStore($prizeStorePath);
    $availablePrizeNames = [];
    foreach ($prizes as $item) {
      if (!is_array($item)) {
        continue;
      }
      if ((bool)($item['isFake'] ?? false)) {
        continue;
      }
      $name = trim((string)($item['name'] ?? ''));
      $displayName = trim((string)($item['onWheelName'] ?? $name));
      $last = max(0, (int)($item['last'] ?? 0));
      if (($displayName === '' && $name === '') || $last <= 0) {
        continue;
      }
      $availablePrizeNames[] = $displayName !== '' ? $displayName : $name;
    }

    echo json_encode([
      'status' => 'ok',
      'data' => [
        'score' => $userScore,
        'cardFlipsCount' => $cardFlipsCount,
        'eachLevelWonPrize' => $eachLevelWonPrizeRaw,
        'totalPrizeWon' => $totalPrizeWon,
        'eventStatus' => $eventStatus,
        'levels' => $levelPayload,
        'availablePrizeNames' => $availablePrizeNames
      ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'reward_flip') {
    $sessionWorkId = (string)($_SESSION['tc_work_id'] ?? '');
    if (!(($_SESSION['tc_authed'] ?? false) && $sessionWorkId !== '')) {
      echo json_encode(['status' => 'error', 'message' => 'ابتدا وارد شوید.']);
      exit;
    }
    $eventStatus = loadGlobalEventStatus();
    if ($eventStatus !== 'active') {
      if ($eventStatus === 'inactive') {
        echo json_encode(['status' => 'error', 'message' => 'فعلا رویداد فعالی وجود ندارد.']);
      } elseif ($eventStatus === 'upcoming') {
        echo json_encode(['status' => 'error', 'message' => 'کارت‌ها فقط در زمان فعال بودن رویداد باز می‌شوند.']);
      } else {
        echo json_encode(['status' => 'error', 'message' => 'رویداد به پایان رسیده است.']);
      }
      exit;
    }
    $targetLevelId = trim((string)($payload['levelId'] ?? ''));
    if ($targetLevelId === '') {
      echo json_encode(['status' => 'error', 'message' => 'سطح جایزه انتخاب نشده است.']);
      exit;
    }

    $table = loadInviteesTable($inviteesFilePath, $inviteesMapPath);
    $rows = $table['rows'];
    $workIdIndex = (int)($table['workIdIndex'] ?? -1);
    $columns = is_array($table['columns']['index'] ?? null) ? $table['columns']['index'] : [];
    $rowIndex = findInviteeRowIndex($rows, $workIdIndex, $sessionWorkId);
    if ($rowIndex < 0) {
      echo json_encode(['status' => 'error', 'message' => 'رکورد کاربر پیدا نشد.']);
      exit;
    }

    $scoreIndex = (int)($columns['score'] ?? -1);
    $flipCountIndex = (int)($columns['Card Flips Count'] ?? -1);
    $wonPrizeIndex = (int)($columns['Each Level Won Prize'] ?? -1);
    $totalPrizeWonIndex = (int)($columns['Total Prize Won'] ?? ($columns['مجموع جوایز برنده شده'] ?? -1));
    $wonLevelIdsIndex = (int)($columns['Reward Level Won IDs'] ?? -1);
    if ($flipCountIndex < 0 || $wonPrizeIndex < 0 || $totalPrizeWonIndex < 0 || $wonLevelIdsIndex < 0) {
      echo json_encode(['status' => 'error', 'message' => 'ستون‌های جایزه آماده نیستند.']);
      exit;
    }

    $userScore = $scoreIndex >= 0 ? max(0, (int)($rows[$rowIndex][$scoreIndex] ?? 0)) : 0;
    $wonLevelIds = parseCommaSeparatedList((string)($rows[$rowIndex][$wonLevelIdsIndex] ?? ''));
    $wonSet = [];
    foreach ($wonLevelIds as $token) {
      $wonSet[$token] = true;
    }

    $levels = readPrizeLevelRecords($prizeLevelsPath);
    $targetLevel = null;
    foreach ($levels as $level) {
      if ((string)($level['id'] ?? '') === $targetLevelId) {
        $targetLevel = $level;
        break;
      }
    }
    if (!is_array($targetLevel)) {
      echo json_encode(['status' => 'error', 'message' => 'سطح جایزه پیدا نشد.']);
      exit;
    }
    if ((string)($targetLevel['type'] ?? 'value_sum') !== 'value_sum') {
      echo json_encode(['status' => 'error', 'message' => 'این سطح فقط امتیازی است و کارت ندارد.']);
      exit;
    }
    if ($userScore < (int)($targetLevel['score'] ?? 0)) {
      echo json_encode(['status' => 'error', 'message' => 'امتیاز شما برای این سطح کافی نیست.']);
      exit;
    }
    if (isset($wonSet[$targetLevelId])) {
      echo json_encode(['status' => 'error', 'message' => 'جایزه این سطح قبلا دریافت شده است.']);
      exit;
    }

    $prizes = readPrizeStore($prizeStorePath);
    $candidateIndexes = [];
    foreach ($prizes as $index => $item) {
      if (!is_array($item)) {
        continue;
      }
      if ((bool)($item['isFake'] ?? false)) {
        continue;
      }
      $name = trim((string)($item['name'] ?? ''));
      $displayName = trim((string)($item['onWheelName'] ?? $name));
      $last = max(0, (int)($item['last'] ?? 0));
      if (($displayName !== '' || $name !== '') && $last > 0) {
        $candidateIndexes[] = (int)$index;
      }
    }
    if (!$candidateIndexes) {
      echo json_encode(['status' => 'error', 'message' => 'در حال حاضر جایزه‌ای موجود نیست.']);
      exit;
    }

    $selectedStoreIndex = $candidateIndexes[random_int(0, count($candidateIndexes) - 1)];
    $selectedPrize = $prizes[$selectedStoreIndex];
    $selectedPrizeNameRaw = trim((string)($selectedPrize['name'] ?? ''));
    $selectedPrizeOnWheelName = trim((string)($selectedPrize['onWheelName'] ?? $selectedPrizeNameRaw));
    $selectedPrizeName = $selectedPrizeOnWheelName !== '' ? $selectedPrizeOnWheelName : $selectedPrizeNameRaw;
    $selectedPrizeValue = max(0, normalizeFloatValue($selectedPrize['value'] ?? 0));
    $selectedLast = max(0, (int)($selectedPrize['last'] ?? 0));
    $prizes[$selectedStoreIndex]['last'] = max(0, $selectedLast - 1);
    if (!writePrizeStore($prizeStorePath, $prizes)) {
      echo json_encode(['status' => 'error', 'message' => 'رزرو جایزه ناموفق بود.']);
      exit;
    }

    $currentFlipCount = max(0, (int)($rows[$rowIndex][$flipCountIndex] ?? 0));
    $currentTotalPrizeWon = max(0, normalizeFloatValue($rows[$rowIndex][$totalPrizeWonIndex] ?? 0));
    $wonPrizeEntries = parseCommaSeparatedList((string)($rows[$rowIndex][$wonPrizeIndex] ?? ''));

    $rows[$rowIndex][$flipCountIndex] = (string)($currentFlipCount + 1);
    $wonPrizeEntries[] = trim((string)($targetLevel['name'] ?? 'Level')) . ': ' . $selectedPrizeName;
    $rows[$rowIndex][$wonPrizeIndex] = serializeCommaSeparatedList($wonPrizeEntries);
    $rows[$rowIndex][$totalPrizeWonIndex] = (string)($currentTotalPrizeWon + $selectedPrizeValue);
    $wonLevelIds[] = $targetLevelId;
    $rows[$rowIndex][$wonLevelIdsIndex] = serializeCommaSeparatedList($wonLevelIds);

    if (!writeInviteesCsv($inviteesFilePath, $rows)) {
      // Best-effort rollback if CSV write fails after prize decrement.
      $prizesRollback = readPrizeStore($prizeStorePath);
      if (isset($prizesRollback[$selectedStoreIndex]) && is_array($prizesRollback[$selectedStoreIndex])) {
        $rollbackLast = max(0, (int)($prizesRollback[$selectedStoreIndex]['last'] ?? 0));
        $prizesRollback[$selectedStoreIndex]['last'] = $rollbackLast + 1;
        writePrizeStore($prizeStorePath, $prizesRollback);
      }
      echo json_encode(['status' => 'error', 'message' => 'ذخیره نتیجه جایزه ناموفق بود.']);
      exit;
    }

    $updatedWonSet = [];
    foreach ($wonLevelIds as $token) {
      $updatedWonSet[$token] = true;
    }
    $levelPayload = [];
    $wonPrizeByLevelId = [];
    foreach ($wonLevelIds as $idx => $levelIdToken) {
      $levelIdToken = trim((string)$levelIdToken);
      if ($levelIdToken === '') {
        continue;
      }
      $rawEntry = (string)($wonPrizeEntries[$idx] ?? '');
      $rawEntry = trim($rawEntry);
      $prizeName = '';
      if ($rawEntry !== '') {
        $separatorIndex = strpos($rawEntry, ':');
        if ($separatorIndex !== false && $separatorIndex > 0) {
          $prizeName = trim((string)substr($rawEntry, $separatorIndex + 1));
        }
      }
      $wonPrizeByLevelId[$levelIdToken] = $prizeName;
    }
    foreach ($levels as $level) {
      $levelId = (string)($level['id'] ?? '');
      $type = (string)($level['type'] ?? 'value_sum');
      $reached = $userScore >= (int)($level['score'] ?? 0);
      $won = $levelId !== '' && isset($updatedWonSet[$levelId]);
      $canFlip = $eventStatus === 'active' && $reached && !$won && $type === 'value_sum';
      $levelPayload[] = [
        'id' => $levelId,
        'name' => (string)($level['name'] ?? ''),
        'type' => $type,
        'score' => (int)($level['score'] ?? 0),
        'reached' => $reached,
        'won' => $won,
        'wonPrize' => (string)($wonPrizeByLevelId[$levelId] ?? ''),
        'canFlip' => $canFlip
      ];
    }

    echo json_encode([
      'status' => 'ok',
      'data' => [
        'levelId' => $targetLevelId,
        'levelName' => (string)($targetLevel['name'] ?? ''),
        'prizeName' => $selectedPrizeName,
        'prizeValue' => $selectedPrizeValue,
        'cardFlipsCount' => $currentFlipCount + 1,
        'totalPrizeWon' => $currentTotalPrizeWon + $selectedPrizeValue,
        'eachLevelWonPrize' => $rows[$rowIndex][$wonPrizeIndex],
        'levels' => $levelPayload
      ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }


  if ($action === 'log_roll') {
    $sessionWorkId = (string)($_SESSION['tc_work_id'] ?? '');
    if (!(($_SESSION['tc_authed'] ?? false) && $sessionWorkId !== '')) {
      echo json_encode(['status' => 'error', 'message' => 'ورود required.']);
      exit;
    }
    $table = loadInviteesTable($inviteesFilePath, $inviteesMapPath);
    $rows = $table['rows'];
    $workIdIndex = $table['workIdIndex'];
    $columns = $table['columns']['index'] ?? [];
    $rollIndex = $columns['count of rolls'] ?? -1;
    $prizeIndex = $columns['prize won'] ?? -1;
    $scoreIndex = $columns['score'] ?? -1;
    $rowIndex = findInviteeRowIndex($rows, $workIdIndex, $sessionWorkId);
    if ($rowIndex < 0 || $rollIndex < 0) {
      echo json_encode(['status' => 'error', 'message' => 'User row not found.']);
      exit;
    }
    if ($prizeIndex >= 0) {
      $already = trim((string)($rows[$rowIndex][$prizeIndex] ?? ''));
      if ($already !== '') {
        echo json_encode(['status' => 'error', 'message' => 'جایزه قبلا ثبت شده است.']);
        exit;
      }
    }
    $rolls = max(0, (int)($rows[$rowIndex][$rollIndex] ?? 0));
    $userScore = $scoreIndex >= 0 ? max(0, (int)($rows[$rowIndex][$scoreIndex] ?? 0)) : 0;
    $prizeLevels = readPrizeLevels($prizeLevelsPath);
    $allowedRolls = resolveAllowedRollCountByScore($userScore, $prizeLevels);
    if ($allowedRolls !== null) {
      if ($allowedRolls <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'امتیاز شما is not enough for a prize attempt yet.']);
        exit;
      }
      if ($rolls >= $allowedRolls) {
        echo json_encode(['status' => 'error', 'message' => 'با امتیاز فعلی شما فرصتی برای جایزه باقی نمانده است.']);
        exit;
      }
    }
    $rows[$rowIndex][$rollIndex] = (string)($rolls + 1);
    if (($table['columns']['added'] ?? false) && $rows) {
      writeInviteesCsv($inviteesFilePath, $rows);
    } else if (!writeInviteesCsv($inviteesFilePath, $rows)) {
      echo json_encode(['status' => 'error', 'message' => 'ذخیره تعداد چرخش ناموفق بود.']);
      exit;
    }
    echo json_encode(['status' => 'ok']);
    exit;
  }

  if ($action === 'log_answer') {
    $sessionWorkId = (string)($_SESSION['tc_work_id'] ?? '');
    if (!(($_SESSION['tc_authed'] ?? false) && $sessionWorkId !== '')) {
      echo json_encode(['status' => 'error', 'message' => 'ورود required.']);
      exit;
    }
    echo json_encode(['status' => 'ok']);
    exit;
  }

  if ($action === 'update_answered') {
    $sessionWorkId = (string)($_SESSION['tc_work_id'] ?? '');
    if (!(($_SESSION['tc_authed'] ?? false) && $sessionWorkId !== '')) {
      echo json_encode(['status' => 'error', 'message' => 'ورود required.']);
      exit;
    }
    $answeredRaw = $payload['answered'] ?? 0;
    $table = loadInviteesTable($inviteesFilePath, $inviteesMapPath);
    $rows = $table['rows'];
    $workIdIndex = $table['workIdIndex'];
    $columns = $table['columns']['index'] ?? [];
    $answeredIndex = $columns['Answered'] ?? -1;
    $rowIndex = findInviteeRowIndex($rows, $workIdIndex, $sessionWorkId);
    if ($rowIndex < 0 || $answeredIndex < 0) {
      echo json_encode(['status' => 'error', 'message' => 'User row not found.']);
      exit;
    }
    $questionCount = count(readQuestionStore($questionsStorePath));
    $answered = clampAnsweredCount($answeredRaw, $questionCount);
    $rows[$rowIndex][$answeredIndex] = (string)$answered;
    if (($table['columns']['added'] ?? false) && $rows) {
      writeInviteesCsv($inviteesFilePath, $rows);
    } else if (!writeInviteesCsv($inviteesFilePath, $rows)) {
      echo json_encode(['status' => 'error', 'message' => 'ذخیره وضعیت پاسخ ناموفق بود.']);
      exit;
    }
    echo json_encode(['status' => 'ok']);
    exit;
  }

  if ($action === 'log_prize') {
    $sessionWorkId = (string)($_SESSION['tc_work_id'] ?? '');
    if (!(($_SESSION['tc_authed'] ?? false) && $sessionWorkId !== '')) {
      echo json_encode(['status' => 'error', 'message' => 'ورود required.']);
      exit;
    }
    $prizeName = trim((string)($payload['prize'] ?? ''));
    if ($prizeName === '') {
      echo json_encode(['status' => 'error', 'message' => 'جایزه مشخص نشده است.']);
      exit;
    }
    $angleValue = $payload['wheelAngle'] ?? null;
    $wheelAngle = is_numeric($angleValue) ? (float)$angleValue : null;
    $table = loadInviteesTable($inviteesFilePath, $inviteesMapPath);
    $rows = $table['rows'];
    $workIdIndex = $table['workIdIndex'];
    $columns = $table['columns']['index'] ?? [];
    $prizeIndex = $columns['prize won'] ?? -1;
    $prizeWonAtIndex = $columns['prize won at'] ?? -1;
    $angleIndex = $columns['wheel angle'] ?? -1;
    $rowIndex = findInviteeRowIndex($rows, $workIdIndex, $sessionWorkId);
    if ($rowIndex < 0 || $prizeIndex < 0) {
      echo json_encode(['status' => 'error', 'message' => 'User row not found.']);
      exit;
    }
    $rows[$rowIndex][$prizeIndex] = $prizeName;
    if ($prizeWonAtIndex >= 0) {
      $rows[$rowIndex][$prizeWonAtIndex] = (string)time();
    }
    if ($angleIndex >= 0 && $wheelAngle !== null) {
      $rows[$rowIndex][$angleIndex] = (string)$wheelAngle;
    }
    if (($table['columns']['added'] ?? false) && $rows) {
      writeInviteesCsv($inviteesFilePath, $rows);
    } else if (!writeInviteesCsv($inviteesFilePath, $rows)) {
      echo json_encode(['status' => 'error', 'message' => 'ذخیره جایزه ناموفق بود.']);
      exit;
    }
    echo json_encode(['status' => 'ok']);
    exit;
  }

  if ($action === 'decrement_prize') {
    $name = trim((string)($payload['name'] ?? ''));
    if ($name === '') {
      echo json_encode(['status' => 'error', 'message' => 'نام جایزه وارد نشده است.']);
      exit;
    }
    $prizes = readPrizeStore($prizeStorePath);
    $updated = [];
    foreach ($prizes as $item) {
      if (!is_array($item)) {
        continue;
      }
      $itemName = trim((string)($item['name'] ?? ''));
      $onWheelName = trim((string)($item['onWheelName'] ?? $itemName));
      $isFake = (bool)($item['isFake'] ?? false);
      $quantity = (int)($item['quantity'] ?? 0);
      $last = (int)($item['last'] ?? $quantity);
      $value = is_numeric($item['value'] ?? null) ? (float)$item['value'] : 0.0;
      if ($value < 0) {
        $value = 0.0;
      }
      if ($itemName !== '' && $itemName === $name && !$isFake) {
        $last = max(0, $last - 1);
      }
      if ($itemName !== '') {
        $updated[] = [
          'name' => $itemName,
          'onWheelName' => $onWheelName !== '' ? $onWheelName : $itemName,
          'quantity' => $quantity > 0 ? $quantity : 0,
          'last' => $last > 0 ? $last : 0,
          'value' => $value,
          'isFake' => $isFake
        ];
      }
    }
    if (!writePrizeStore($prizeStorePath, $updated)) {
      echo json_encode(['status' => 'error', 'message' => 'ذخیره جوایز ناموفق بود.']);
      exit;
    }
    echo json_encode(['status' => 'ok', 'data' => $updated]);
    exit;
  }

  echo json_encode(['status' => 'error', 'message' => 'Unsupported request.']);
  exit;
}

$initialPrizes = readPrizeStore($prizeStorePath);
$initialQuestions = readQuestionStore($questionsStorePath);
$taskRecords = loadTaskRecords(TASKS_JS_STORE_PATH, TASKS_DIR_PATH);
$wheelSettings = loadJsonPayload(__DIR__ . '/Setting.json');
$panelSettings = loadPanelSettings();
$eventLogoRaw = (string)($wheelSettings['eventLogo'] ?? '');
$eventLogoUrl = formatSiteIconUrlForHtml($eventLogoRaw);
$fallbackSiteIconUrl = formatSiteIconUrlForHtml((string)($panelSettings['siteIcon'] ?? ''));
$faviconUrl = $eventLogoUrl !== '' ? $eventLogoUrl : $fallbackSiteIconUrl;
$eventColors = is_array($wheelSettings['eventColors'] ?? null) ? $wheelSettings['eventColors'] : [];
$eventSecondary = normalizeHexColorForTheme($eventColors['secondary'] ?? '', '#2F8FFF');
$eventHighlight = normalizeHexColorForTheme($eventColors['highlight'] ?? '', '#20C997');
$eventAccentSoft = normalizeHexColorForTheme($eventColors['accentSoft'] ?? '', '#FFB347');
function sanitizeHintHtml(string $html): string
{
  $allowed = '<br><b><strong><em><a><div><span><p>';
  $clean = strip_tags($html, $allowed);
  $clean = preg_replace('/\s+on\w+="[^"]*"/i', '', $clean);
  $clean = preg_replace("/\s+on\w+='[^']*'/i", '', $clean);
  $clean = preg_replace_callback('/\sclass="([^"]*)"/i', function ($matches) {
    $classes = preg_split('/\s+/', trim($matches[1]));
    $allowedClasses = array_filter($classes, function ($class) {
      return preg_match('/^ql-align-(right|center|left|justify)$/', $class);
    });
    if (!$allowedClasses) {
      return '';
    }
    return ' class="' . implode(' ', $allowedClasses) . '"';
  }, $clean);
  $clean = preg_replace_callback('/<a\s+[^>]*href=(["\'])(.*?)\1[^>]*>/i', function ($matches) {
    $href = trim($matches[2]);
    if (!preg_match('#^(https?:|mailto:|tel:|/|#)#i', $href)) {
      $href = '#';
    }
    $tag = $matches[0];
    $tag = preg_replace('/\s+href=(["\']).*?\1/i', ' href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"', $tag);
    if (!preg_match('/\s+rel=/i', $tag)) {
      $tag = rtrim($tag, '>') . ' rel="noopener">';
    }
    if (!preg_match('/\s+target=/i', $tag)) {
      $tag = rtrim($tag, '>') . ' target="_blank">';
    }
    return $tag;
  }, $clean);
  $clean = trim($clean);
  if ($clean === '') {
    return '';
  }
  // Normalize Quill paragraphs into a single paragraph with <br> separators.
  $clean = preg_replace('/<p>\s*<\/p>/i', '', $clean);
  $clean = preg_replace('/<\/p>\s*<p[^>]*>/i', '<br>', $clean);
  $clean = preg_replace('/<p[^>]*>/i', '', $clean);
  $clean = str_replace('</p>', '', $clean);
  $clean = preg_replace('/(<br>\s*){2,}/i', '<br>', $clean);
  return trim($clean);
}

$rawHintHtml = (string)($wheelSettings['hintHtml'] ?? '');
$hintTextFallback = trim((string)($wheelSettings['hint'] ?? ''));
if ($rawHintHtml === '' && $hintTextFallback !== '') {
  $rawHintHtml = htmlspecialchars($hintTextFallback, ENT_QUOTES, 'UTF-8');
}
if ($rawHintHtml === '') {
  $rawHintHtml = htmlspecialchars('شانس خودت را امتحان کن و جایزه ببر', ENT_QUOTES, 'UTF-8');
}
$hintHtml = sanitizeHintHtml($rawHintHtml);
$hintAlign = trim((string)($wheelSettings['hintAlign'] ?? 'right'));
$hintAlign = in_array($hintAlign, ['right', 'center', 'left'], true) ? $hintAlign : 'right';

$inviteesMtime = is_file($inviteesFilePath) ? filemtime($inviteesFilePath) : null;
$sessionAuthed = isset($_SESSION['tc_authed']) && $_SESSION['tc_authed'] === true;
if ($sessionAuthed && ($inviteesMtime === null || ($inviteesMtime !== ($_SESSION['tc_invitees_mtime'] ?? null)))) {
  session_unset();
  $sessionAuthed = false;
}
$sessionWorkId = $sessionAuthed ? trim((string)($_SESSION['tc_work_id'] ?? '')) : '';
$sessionFullName = $sessionWorkId;
$sessionPrizeWon = '';
$sessionPrizeWonAt = null;
$sessionWheelAngle = null;
$sessionQuizOrder = [];
$sessionAnswered = 0;
if ($sessionAuthed && $sessionWorkId !== '' && $inviteesMtime !== null) {
  $tcqSettings = loadWfqSettings($tcqSettingsPath);
  $table = loadInviteesTable($inviteesFilePath, $inviteesMapPath);
  $rows = $table['rows'];
  $workIdIndex = $table['workIdIndex'];
  $columns = $table['columns']['index'] ?? [];
  $prizeIndex = $columns['prize won'] ?? -1;
  $prizeWonAtIndex = $columns['prize won at'] ?? -1;
  $angleIndex = $columns['wheel angle'] ?? -1;
  $questions = readQuestionStore($questionsStorePath);
  $questionCodes = array_values(array_map(static fn($item) => (string)($item['code'] ?? ''), $questions));
  if (($table['columns']['added'] ?? false) && $rows) {
    writeInviteesCsv($inviteesFilePath, $rows);
  }
  $rowIndex = findInviteeRowIndex($rows, $workIdIndex, $sessionWorkId);
  if ($rowIndex < 0) {
    session_unset();
    $sessionAuthed = false;
    $sessionWorkId = '';
  } else {
    $sessionFullName = resolveInviteeFullName($table['header'] ?? [], $table['mapping'] ?? [], $rows[$rowIndex] ?? [], $sessionWorkId);
    $quizState = ensureUserQuestionProgress($rows, $rowIndex, $columns, $questionCodes, (bool)$tcqSettings['randomOrder']);
    $sessionQuizOrder = $quizState['order'];
    $sessionAnswered = $quizState['answered'];
    if ($quizState['changed']) {
      writeInviteesCsv($inviteesFilePath, $rows);
    }
    if ($prizeIndex >= 0) {
      $sessionPrizeWon = trim((string)($rows[$rowIndex][$prizeIndex] ?? ''));
    }
    if ($prizeWonAtIndex >= 0) {
      $sessionPrizeWonAt = parseEpochValue($rows[$rowIndex][$prizeWonAtIndex] ?? null);
    }
    if ($angleIndex >= 0) {
      $angleValue = trim((string)($rows[$rowIndex][$angleIndex] ?? ''));
      if ($angleValue !== '' && is_numeric($angleValue)) {
        $sessionWheelAngle = (float)$angleValue;
      }
    }
  }
}
$taskItemsForView = buildTaskPayloadForView($taskRecords, $inviteesFilePath, $inviteesMapPath, $sessionAuthed ? $sessionWorkId : '');
$sessionTaskTotalScore = ($sessionAuthed && $sessionWorkId !== '')
  ? computeUserTotalTaskScore($inviteesFilePath, $inviteesMapPath, $sessionWorkId)
  : 0;
$tcqSettingsForPayload = loadWfqSettings($tcqSettingsPath);
$sessionPayload = [
  'authed' => $sessionAuthed,
  'workId' => $sessionWorkId,
  'fullName' => $sessionFullName,
  'prizeWon' => $sessionPrizeWon,
  'prizeWonAt' => $sessionPrizeWonAt,
  'wheelAngle' => $sessionWheelAngle,
  'quizOrder' => $sessionQuizOrder,
  'answered' => $sessionAnswered,
  'taskTotalScore' => $sessionTaskTotalScore,
  'answerTimeLimit' => (bool)($tcqSettingsForPayload['answerTimeLimit'] ?? true),
  'randomOrder' => (bool)($tcqSettingsForPayload['randomOrder'] ?? true)
];
?>
<!doctype html>
<html lang="fa" dir="rtl">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>کمپین به نام‌خدا</title>
    <link rel="icon" href="<?= htmlspecialchars($faviconUrl ?: 'data:,', ENT_QUOTES, 'UTF-8') ?>" />
    <link rel="stylesheet" href="../../style/remixicon.css" />
    <style nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>">
      :root {
        --bg: #f4f7fb;
        --phone: #ffffff;
        --ink: #1f2a44;
        --muted: #7f8baa;
        --line: #e8edf6;
        --accent: #2f8fff;
        --accent-ink: #ffffff;
        --soft-pink: #eef5ff;
        --soft-blue: #eef5ff;
        --tc-secondary: <?= htmlspecialchars($eventSecondary, ENT_QUOTES, 'UTF-8') ?>;
        --tc-highlight: <?= htmlspecialchars($eventHighlight, ENT_QUOTES, 'UTF-8') ?>;
        --tc-accent-soft: <?= htmlspecialchars($eventAccentSoft, ENT_QUOTES, 'UTF-8') ?>;
        font-family: 'Peyda Fa Num', 'Segoe UI', Tahoma, Arial, sans-serif;
        color-scheme: light;
      }


      @font-face {
        font-family: 'Peyda Fa Num';
        src:
          url('../../style/fonts/PeydaWebFaNum-Regular.woff2') format('woff2'),
          url('/style/fonts/PeydaWebFaNum-Regular.woff2') format('woff2');
        font-weight: 400;
        font-style: normal;
        font-display: swap;
      }

      @font-face {
        font-family: 'Peyda Fa Num';
        src:
          url('../../style/fonts/PeydaWebFaNum-Bold.woff2') format('woff2'),
          url('/style/fonts/PeydaWebFaNum-Bold.woff2') format('woff2');
        font-weight: 700;
        font-style: normal;
        font-display: swap;
      }

      * {
        box-sizing: border-box;
      }

      body {
        margin: 0;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        background:
          linear-gradient(160deg, rgba(206, 227, 255, 0.5), rgba(244, 247, 251, 0) 40%),
          linear-gradient(330deg, rgba(215, 230, 255, 0.5), rgba(244, 247, 251, 0) 42%),
          var(--bg);
        color: var(--ink);
        padding: 18px;
        direction: rtl;
        text-align: right;
      }

      @supports (height: 100dvh) {
        body {
          min-height: 100dvh;
        }
      }

      .page-loading body {
        overflow: hidden;
      }

      .page-loading .app {
        opacity: 0;
        pointer-events: none;
      }

      .loader-overlay {
        position: fixed;
        inset: 0;
        background:
          radial-gradient(circle at top, rgba(223, 236, 255, 0.9), rgba(244, 247, 251, 0.92) 50%, rgba(255, 255, 255, 0.95));
        display: grid;
        place-items: center;
        z-index: 9999;
        transition: opacity 0.35s ease;
      }

      .loader-card {
        width: min(280px, 80vw);
        padding: 10px 8px;
        text-align: center;
        display: grid;
        gap: 12px;
        background: transparent;
        border: none;
        box-shadow: none;
      }

      .loader-icon-wrap {
        width: 96px;
        height: 96px;
        margin: 0 auto;
        position: relative;
        display: grid;
        place-items: center;
      }

      .loader-icon-svg {
        width: 72px;
        height: 48px;
        display: block;
      }

      .loader-icon-fill {
        fill: rgba(47, 143, 255, 0.16);
      }

      .loader-icon-path {
        fill: none;
        stroke: #2f8fff;
        stroke-width: 22;
        stroke-linecap: round;
        stroke-linejoin: round;
        stroke-dasharray: 950 1250;
        stroke-dashoffset: 0;
        animation: tc-icon-stroke 2.4s linear infinite;
      }

      .loader-text {
        margin: 0;
        font-size: 0.9rem;
        color: #516089;
        font-weight: 600;
      }

      .loader-subtext {
        margin: 0;
        font-size: 0.78rem;
        color: #8a97b2;
      }

      .loader-hidden {
        opacity: 0;
        pointer-events: none;
      }

      @keyframes tc-icon-stroke {
        0% {
          stroke-dashoffset: 0;
          opacity: 0.85;
        }
        50% {
          opacity: 1;
        }
        100% {
          stroke-dashoffset: -2200;
          opacity: 0.85;
        }
      }

      .app {
        width: min(460px, 100%);
        position: relative;
        overflow: hidden;
      }

      .phone {
        width: 100%;
        min-height: min(860px, calc(100vh - 36px));
        height: calc(100vh - 36px);
        background: var(--phone);
        border: 1px solid var(--line);
        border-radius: 28px;
        box-shadow:
          0 26px 50px rgba(29, 55, 96, 0.14),
          inset 0 1px 0 #fff;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        position: relative;
      }

      .topbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 16px 10px;
        gap: 12px;
      }

      .topbar-actions {
        display: flex;
        align-items: center;
        gap: 10px;
      }

      .logout-btn {
        border: none;
        background: transparent;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-family: inherit;
        font-size: 0.82rem;
        color: #6b7a99;
        cursor: pointer;
        padding: 4px 6px;
      }

      .logout-btn span {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 8px;
        height: 8px;
        border-radius: 999px;
        background: #c7d2e5;
      }

      .logout-btn:hover {
        color: #2f5aa6;
      }

      .brand {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin: 0;
        font-size: 0.96rem;
        color: var(--tc-secondary);
        letter-spacing: 0.04em;
      }

      .brand-icon {
        width: auto;
        height: 24px;
        max-width: 72px;
        border: 0;
        border-radius: 0;
        box-shadow: none;
        object-fit: contain;
        background: transparent;
      }

      .main-area {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 14px;
        padding: 12px 18px 10px;
      }

      #tc-timer-area {
        flex: 1;
        min-height: 0;
        justify-content: flex-start;
        gap: 12px;
        padding-top: 20px;
        padding-bottom: 8px;
      }

      .tasks-title {
        margin: 4px 0 2px;
        color: var(--tc-highlight);
        font-size: 1.08rem;
        font-weight: 700;
        letter-spacing: 0.02em;
      }

      .task-event-logo {
        width: auto;
        height: 92px;
        max-width: min(240px, 80vw);
        object-fit: contain;
        display: block;
        margin: 0 auto 4px;
      }

      .user-score-chip {
        width: min(360px, calc(100vw - 56px));
        position: relative;
        isolation: isolate;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 10px 14px;
        border-radius: 14px;
        border: 1px solid #d2ddf0;
        background: #f6faff;
        box-shadow: 0 16px 28px rgba(44, 86, 146, 0.12), inset 0 1px 0 rgba(255, 255, 255, 0.9);
        color: #24406d;
        font-size: 0.86rem;
        font-weight: 700;
        overflow: hidden;
        white-space: nowrap;
      }

      .user-score-chip::before {
        content: '';
        position: absolute;
        top: -30%;
        left: -120%;
        width: 70%;
        height: 160%;
        background: linear-gradient(110deg, rgba(255, 255, 255, 0), rgba(255, 255, 255, 0.82), rgba(255, 255, 255, 0));
        transform: rotate(14deg);
        animation: tcGlassShine 3.2s ease-in-out infinite;
        pointer-events: none;
        z-index: 0;
      }

      .user-score-chip span,
      .user-score-chip strong {
        position: relative;
        z-index: 1;
      }

      .user-score-chip strong {
        color: #15325c;
        font-size: 1.04rem;
      }

      .tasks-list {
        width: min(360px, calc(100vw - 56px));
        flex: 1;
        min-height: 0;
        display: flex;
        flex-direction: column;
        gap: 10px;
        overflow-y: auto;
        padding-inline-end: 0;
        padding-bottom: 132px;
        scrollbar-width: none;
        -ms-overflow-style: none;
      }

      .tasks-list::-webkit-scrollbar {
        width: 0;
        height: 0;
      }

      .task-item-btn {
        width: 100%;
        border: 1px solid #d9e6fa;
        border-radius: 14px;
        background: #f7fbff;
        color: #284069;
        font-family: inherit;
        font-size: 0.96rem;
        font-weight: 700;
        padding: 12px 14px;
        text-align: right;
        cursor: pointer;
        transition: background-color 0.18s ease, border-color 0.18s ease, transform 0.18s ease;
      }

      .task-item-title {
        display: block;
      }

      .task-item-meta {
        display: block;
        margin-top: 3px;
        font-size: 0.78rem;
        font-weight: 400;
        color: #6f7f9f;
      }

      .task-item-meta.is-multiline {
        white-space: pre-line;
        line-height: 1.55;
      }

      .task-item-btn:hover {
        background: #edf5ff;
        border-color: #bfd7ff;
        transform: translateY(-1px);
      }

      .task-item-btn:active {
        transform: translateY(0);
      }

      .task-item-btn.is-disabled,
      .task-item-btn:disabled {
        background: #f2f4f8;
        border-color: #d7dde8;
        color: #8b97ac;
        cursor: not-allowed;
        transform: none;
      }

      .task-item-btn.is-disabled .task-item-meta,
      .task-item-btn:disabled .task-item-meta {
        color: #9aa6bb;
      }

      .task-item-btn.is-completed {
        background: #eef9f1;
        border-color: #c7e9d0;
        color: #2f5f3c;
      }

      .task-item-btn.is-completed .task-item-meta {
        color: #4d7a58;
      }

      .task-item-btn.is-upcoming,
      .task-item-btn.is-upcoming:disabled {
        background: #f2f4f8;
        border-color: #d7dde8;
        color: #8b97ac;
        cursor: not-allowed;
        opacity: 1;
      }

      .task-item-btn.is-upcoming .task-item-meta,
      .task-item-btn.is-upcoming:disabled .task-item-meta {
        color: #9aa6bb;
        font-weight: 400;
      }

      .task-item-btn.is-upcoming .task-item-title,
      .task-item-btn.is-upcoming:disabled .task-item-title {
        font-weight: 500;
      }

      .task-item-btn.is-golden {
        border-color: #ffb247;
        background: linear-gradient(145deg, #fff8ec, #fff2d8);
        box-shadow: 0 8px 20px rgba(255, 170, 64, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.85);
      }

      .task-item-btn.is-golden .task-item-meta {
        color: #9b5a00;
        font-weight: 700;
      }

      .task-item-btn.is-golden-live {
        border-color: #ff9b1c;
        background: #fff2d6;
        box-shadow: 0 12px 24px rgba(255, 157, 35, 0.24), inset 0 1px 0 rgba(255, 255, 255, 0.9);
      }

      .task-item-btn.is-golden-live .task-item-meta {
        color: #9a4a00;
        font-size: 0.84rem;
        font-weight: 800;
        text-shadow: 0 1px 0 rgba(255, 255, 255, 0.5);
      }

      .tasks-empty {
        margin: 8px 0 0;
        font-size: 0.88rem;
        color: #7b8aa8;
        text-align: center;
      }

      .tc-bottom-cta {
        position: absolute;
        left: 0;
        right: 0;
        bottom: 0;
        padding: 10px 18px 14px;
        background: linear-gradient(180deg, rgba(246, 250, 255, 0.18) 0%, rgba(246, 250, 255, 0.8) 28%, rgba(246, 250, 255, 0.94) 100%);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
      }

      .tc-bottom-cta.quiz-hidden {
        display: none;
      }

      .tc-bottom-cta-btn {
        width: min(360px, calc(100vw - 56px));
        margin: 0 auto;
        display: block;
        font-family: 'Peyda Fa Num', 'Segoe UI', Tahoma, Arial, sans-serif;
        text-align: center;
        text-decoration: none;
        border-radius: 14px;
        border: 1px solid var(--tc-secondary);
        background: var(--tc-secondary);
        color: #ffffff;
        font-size: 0.96rem;
        font-weight: 700;
        padding: 12px 14px;
        box-shadow: 0 14px 26px rgba(37, 86, 146, 0.28), inset 0 1px 0 rgba(255, 255, 255, 0.35);
        transition: background-color 0.18s ease, transform 0.18s ease;
      }

      .tc-bottom-cta-btn:hover {
        background: var(--tc-secondary);
        filter: brightness(1.06);
        transform: translateY(-1px);
      }

      .tc-bottom-cta-btn:active {
        transform: translateY(0);
      }

      @keyframes tcCtaPulse {
        0% {
          transform: translateY(0) scale(1);
          box-shadow: 0 14px 26px rgba(37, 86, 146, 0.28), inset 0 1px 0 rgba(255, 255, 255, 0.35);
        }
        50% {
          transform: translateY(-2px) scale(1.02);
          box-shadow: 0 20px 34px rgba(37, 86, 146, 0.38), inset 0 1px 0 rgba(255, 255, 255, 0.4);
        }
        100% {
          transform: translateY(0) scale(1);
          box-shadow: 0 14px 26px rgba(37, 86, 146, 0.28), inset 0 1px 0 rgba(255, 255, 255, 0.35);
        }
      }

      .tc-bottom-cta-btn.is-attention {
        animation: tcCtaPulse 1.35s ease-in-out infinite;
      }

      .rewards-view {
        width: 100%;
        align-items: center;
        justify-content: flex-start;
        gap: 10px;
      }

      #tc-reward-cards-view {
        flex: 1;
        min-height: 0;
        justify-content: center;
        gap: 14px;
      }

      #tc-reward-cards-view .result-label {
        display: block;
        margin-bottom: 1px;
        text-align: center;
        font-size: 0.66rem;
        font-weight: 500;
        letter-spacing: 0.01em;
        opacity: 0.72;
      }

      #tc-rewards-view {
        flex: 1;
        min-height: 0;
        justify-content: flex-start;
        gap: 12px;
        padding-top: 20px;
        padding-bottom: 12px;
      }

      .rewards-head {
        width: min(360px, calc(100vw - 56px));
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
      }

      .rewards-title {
        margin: 0;
        color: #2a3f68;
        font-size: 0.96rem;
        font-weight: 700;
      }

      .rewards-time-box,
      #tc-reward-cards-box {
        width: min(360px, calc(100vw - 56px));
      }

      .rewards-total-bar {
        width: min(360px, calc(100vw - 56px));
        margin-top: 8px;
        margin-bottom: 22px;
        border: 1px solid #d9e7fb;
        border-radius: 14px;
        background: #f6faff;
        box-shadow: 0 10px 20px rgba(44, 86, 146, 0.1), inset 0 1px 0 rgba(255, 255, 255, 0.9);
        padding: 10px 14px;
      }

      .rewards-total-bar.is-sticky {
        position: sticky;
        bottom: 10px;
        z-index: 6;
        margin-top: 0;
        margin-bottom: 10px;
      }

      .rewards-roadmap-main {
        width: min(360px, calc(100vw - 56px));
        flex: 1;
        min-height: 0;
        padding: 6px 8px 0 2px;
        margin-bottom: 0;
      }

      #tc-reward-time-label {
        display: block;
        text-align: center;
        font-size: 0.66rem;
        font-weight: 500;
        letter-spacing: 0.01em;
        opacity: 0.72;
      }

      .roadmap-list {
        display: grid;
        gap: 0;
        max-height: 100%;
        min-height: 0;
        overflow-y: auto;
        overflow-x: visible;
        padding: 0 14px 16px 2px;
        scrollbar-width: none;
      }

      .roadmap-list::-webkit-scrollbar {
        display: none;
      }

      .roadmap-item {
        position: relative;
        padding: 0 22px 16px 0;
      }

      .roadmap-item::before {
        content: '';
        position: absolute;
        right: 0;
        top: 0;
        bottom: 0;
        width: 4px;
        border-radius: 999px;
        background: #d9e6fb;
      }

      .roadmap-item:last-child {
        padding-bottom: 0;
      }

      .roadmap-item:last-child::before {
        background: transparent;
      }

      .roadmap-item.reached {
      }

      .roadmap-item.reached::before {
        background: #78a9ef;
        animation: roadmapLineFlow 1.5s ease-in-out infinite;
      }

      .roadmap-item.won {
      }

      .roadmap-item.won::before {
        background: #30a25d;
        animation: none;
      }

      .roadmap-node {
        position: absolute;
        right: 0;
        transform: translateX(50%);
        top: 2px;
        width: 18px;
        height: 18px;
        border-radius: 50%;
        border: 3px solid #7aa6e9;
        background: #fff;
        animation: roadmapNodeIdle 2.1s ease-in-out infinite;
      }

      .roadmap-item.reached .roadmap-node {
        background: #74a9f1;
        border-color: #74a9f1;
      }

      .roadmap-item.can-flip .roadmap-node {
        background: #ffcc52;
        border-color: #f1a500;
        animation: roadmapNodePulse 1.2s ease-in-out infinite;
      }

      .roadmap-item.won .roadmap-node {
        background: #30a25d;
        border-color: #30a25d;
      }

      .roadmap-content {
        border: 0;
        border-radius: 12px;
        background: transparent;
        backdrop-filter: none;
        padding: 8px 10px 8px 0;
        font-size: 0.78rem;
        margin-right: 14px;
      }

      .roadmap-item.can-flip .roadmap-content {
        border: 1px solid #ffbd3a;
        background: linear-gradient(145deg, #fff9e8, #ffefc2);
        box-shadow: 0 14px 24px rgba(255, 174, 57, 0.22);
        padding: 10px 12px;
      }

      .roadmap-item.won .roadmap-content {
        border: 0;
        background: transparent;
      }

      .roadmap-level-btn {
        all: unset;
        display: block;
        width: 100%;
        cursor: pointer;
      }

      .roadmap-level-btn[disabled] {
        cursor: not-allowed;
        opacity: 0.8;
      }

      .roadmap-left {
        color: var(--muted);
        font-size: 0.74rem;
      }

      .roadmap-level-name {
        font-weight: 800;
        font-size: 0.84rem;
      }

      .roadmap-state {
        margin-top: 4px;
        font-size: 0.73rem;
        font-weight: 700;
      }

      .roadmap-state.can-flip { color: #b36a00; }
      .roadmap-state.won { color: #227346; }
      .roadmap-state.locked { color: #9aa8c4; }
      .roadmap-state.reached { color: #cc7a00; }

      @keyframes roadmapNodePulse {
        0% {
          box-shadow: 0 0 0 0 rgba(241, 165, 0, 0.34);
        }
        70% {
          box-shadow: 0 0 0 10px rgba(241, 165, 0, 0);
        }
        100% {
          box-shadow: 0 0 0 0 rgba(241, 165, 0, 0);
        }
      }

      @keyframes roadmapLineFlow {
        0% {
          background: #78a9ef;
        }
        50% {
          background: #9dc0f6;
        }
        100% {
          background: #78a9ef;
        }
      }

      @keyframes roadmapNodeIdle {
        0% {
          transform: translateX(50%) scale(1);
        }
        50% {
          transform: translateX(50%) scale(1.05);
        }
        100% {
          transform: translateX(50%) scale(1);
        }
      }

      #tc-reward-status-line {
        display: none;
      }

      #tc-reward-cards-box {
        border-radius: 18px;
        border: 1px solid #d9e7fb;
        background: #f6faff;
        box-shadow: 0 16px 30px rgba(44, 86, 146, 0.12), inset 0 1px 0 rgba(255, 255, 255, 0.84);
        padding: 12px;
      }

      #tc-reward-cards-box.is-disabled {
        opacity: 0.58;
        filter: grayscale(0.4);
      }

      .cards-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 10px;
      }

      .flip-card {
        perspective: 700px;
        border: 0;
        background: transparent;
        padding: 0;
        cursor: pointer;
        transition: transform 180ms ease;
        font-family: 'Peyda Fa Num', 'Segoe UI', Tahoma, Arial, sans-serif;
      }

      .flip-card:disabled {
        cursor: not-allowed;
      }

      .flip-card:hover:not(:disabled) {
        transform: translateY(-2px);
      }

      .flip-card-inner {
        position: relative;
        width: 100%;
        padding-top: 125%;
        transform-style: preserve-3d;
        transform: rotateY(0deg);
        transition: transform 620ms ease;
      }

      .flip-card.is-revealed .flip-card-inner {
        transform: rotateY(180deg);
      }

      .flip-card.is-picked .flip-face {
        border-color: var(--tc-highlight);
        box-shadow: 0 0 0 2px rgba(255, 211, 84, 0.26), 0 10px 20px rgba(33, 65, 109, 0.2);
      }

      .flip-card.is-picked .flip-back {
        background: var(--tc-highlight);
        color: #ffffff;
      }

      .flip-card.is-locked {
        cursor: not-allowed;
      }

      .flip-face {
        position: absolute;
        inset: 0;
        border-radius: 12px;
        border: 1px solid #c7d8f5;
        backface-visibility: hidden;
        display: grid;
        place-items: center;
        text-align: center;
        padding: 8px;
        font-family: 'Peyda Fa Num', 'Segoe UI', Tahoma, Arial, sans-serif;
      }

      .flip-front {
        background: #eef5ff;
        color: #2b4370;
        font-size: 0.75rem;
        font-weight: 700;
        gap: 6px;
      }

      .flip-front-logo {
        width: 44px;
        height: 44px;
        object-fit: contain;
        filter: drop-shadow(0 2px 6px rgba(0, 0, 0, 0.14));
      }

      .flip-front-label {
        line-height: 1.45;
      }

      .flip-back {
        transform: rotateY(180deg);
        background: #ffffff;
        color: #1b1f2a;
        font-size: 0.75rem;
        font-weight: 700;
        line-height: 1.45;
        gap: 4px;
      }

      .flip-back small {
        font-size: 0.67rem;
        opacity: 0.88;
        font-family: 'Peyda Fa Num', 'Segoe UI', Tahoma, Arial, sans-serif;
      }

      @keyframes tcGlassShine {
        0% {
          left: -120%;
        }
        55% {
          left: -120%;
        }
        100% {
          left: 130%;
        }
      }

      #tc-task-quiz-area {
        justify-content: flex-start;
        padding-top: 14px;
      }

      .tc-task-quiz-head {
        width: min(360px, calc(100% - 8px));
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin: 0 0 2px;
      }

      .tc-task-quiz-title {
        margin: 0;
        color: #29416b;
        font-size: 1rem;
        font-weight: 700;
      }

      #tc-task-quiz-area .quiz-counter {
        position: static;
        align-self: flex-start;
        margin: 0;
      }

      .repeat-area {
        flex: 1;
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px 16px 28px;
      }

      .repeat-card {
        width: min(360px, calc(100vw - 64px));
        border-radius: 18px;
        border: 1px solid #dbe7fb;
        background: linear-gradient(155deg, #f7fbff, #edf4ff 55%, #f8fbff);
        box-shadow: 0 18px 34px rgba(42, 93, 162, 0.12), inset 0 1px 0 rgba(255, 255, 255, 0.86);
        padding: 18px 16px;
        text-align: right;
        color: #2a3f68;
        line-height: 1.9;
      }

      .repeat-card p {
        margin: 0;
      }

      .repeat-card p + p {
        margin-top: 8px;
      }

      .quiz-area {
        flex: 1;
        width: 100%;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        gap: 14px;
        padding: 16px 18px;
        position: relative;
      }

      .quiz-area::before {
        content: '';
        position: absolute;
        inset: 0;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='%23ffffff' d='M20 7h-2.2A3 3 0 0 0 18 6a3 3 0 0 0-5.2-2.1L12 4.8l-.8-.9A3 3 0 0 0 6 6c0 .35.06.69.18 1H4a1 1 0 0 0-1 1v3h1v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8h1V8a1 1 0 0 0-1-1ZM15 4.2A1.5 1.5 0 0 1 17.5 6c0 .55-.3 1.03-.74 1.29L13.6 9H12.5l1.8-3a1.5 1.5 0 0 1 .7-.8ZM6.5 6A1.5 1.5 0 0 1 9 4.2c.3.17.55.45.7.8l1.8 3H10.4L7.24 7.29A1.5 1.5 0 0 1 6.5 6ZM5 9h6v2H5V9Zm1 10v-8h5v8H6Zm7 0v-8h5v8h-5Zm6-8h-6V9h6v2Z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: center 28%;
        background-size: min(180px, 48%);
        opacity: 0.08;
        pointer-events: none;
        z-index: 0;
      }

      .quiz-area > * {
        position: relative;
        z-index: 1;
      }

      .quiz-hidden {
        display: none !important;
      }

      .quiz-counter {
        position: absolute;
        top: 10px;
        left: 14px;
        font-size: 0.95rem;
        font-weight: 800;
        letter-spacing: 0.01em;
        color: #1f3f77;
        background: linear-gradient(180deg, #ffffff 0%, #f3f8ff 100%);
        border: 1px solid #cfe0f8;
        border-radius: 999px;
        padding: 6px 14px;
        box-shadow: 0 8px 18px rgba(47, 143, 255, 0.16);
      }

      .quiz-question-box {
        width: min(360px, calc(100% - 8px));
        min-height: 130px;
        border: 1px solid #dce7f8;
        border-radius: 16px;
        background: #f8fbff;
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        color: #2d3f66;
        font-size: 1rem;
        font-weight: 700;
        line-height: 1.5;
        padding: 14px 16px;
      }

      .quiz-answers-grid {
        width: min(360px, calc(100% - 8px));
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
      }
      .quiz-answers-grid--single {
        width: min(360px, calc(100% - 8px));
        grid-template-columns: 1fr;
        padding-bottom: 98px;
      }

      .quiz-timer-track {
        position: absolute;
        left: 18px;
        right: 18px;
        bottom: 10px;
        height: 6px;
        border-radius: 999px;
        background: #dfe8f7;
        overflow: hidden;
      }

      .quiz-area--percentage .quiz-timer-track {
        bottom: 74px;
      }

      .quiz-timer-fill {
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, #2f8fff, #7ab9ff);
        border-radius: inherit;
        transition: width 0.1s linear;
        margin-left: auto;
      }

      .quiz-timer-fill.is-danger {
        background: linear-gradient(90deg, #ef4444, #f87171);
        animation: quiz-timer-alert 0.22s ease-in-out infinite alternate;
      }

      @keyframes quiz-timer-alert {
        0% { opacity: 1; }
        100% { opacity: 0.45; }
      }

      .quiz-answer-btn {
        border: 1px solid #d8e4f7;
        border-radius: 12px;
        background: #ffffff;
        color: #33466f;
        font-family: inherit;
        font-size: 0.9rem;
        font-weight: 600;
        min-height: 54px;
        padding: 10px 12px;
        cursor: pointer;
        transition: transform 0.16s ease, background 0.2s ease, color 0.2s ease, border-color 0.2s ease;
      }

      .quiz-answer-btn:hover {
        transform: translateY(-1px);
      }

      .quiz-answer-btn:disabled {
        cursor: default;
      }

      .quiz-answer-btn.is-wrong {
        background: #ffe4e7;
        border-color: #fda4af;
        color: #b4232f;
      }

      .quiz-answer-btn.is-correct {
        background: #2f8fff;
        border-color: #2f8fff;
        color: #ffffff;
        animation: quiz-correct-pop 0.42s cubic-bezier(0.22, 1, 0.36, 1);
      }

      .quiz-answer-btn.is-correct-reveal {
        background: #2f8fff;
        border-color: #2f8fff;
        color: #ffffff;
        animation:
          quiz-correct-pop 0.42s cubic-bezier(0.22, 1, 0.36, 1),
          quiz-correct-shake 0.55s ease;
      }

      @keyframes quiz-correct-shake {
        0% { transform: translateX(0); }
        20% { transform: translateX(-5px); }
        40% { transform: translateX(5px); }
        60% { transform: translateX(-4px); }
        80% { transform: translateX(4px); }
        100% { transform: translateX(0); }
      }

      @keyframes quiz-correct-pop {
        0% { transform: scale(0.96); }
        55% { transform: scale(1.06); }
        100% { transform: scale(1); }
      }

      .quiz-percentage-wrap {
        width: 100%;
        border: 1px solid #d6e3f7;
        border-radius: 16px;
        background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        box-shadow: 0 8px 22px rgba(47, 143, 255, 0.12);
        padding: 16px 16px 14px;
        display: grid;
        gap: 12px;
      }

      .quiz-percentage-value {
        font-size: 1.06rem;
        font-weight: 800;
        color: #1f4f8f;
      }

      .quiz-percentage-slider {
        width: 100%;
        appearance: none;
        height: 26px;
        background: transparent;
        --range-progress: 50%;
        direction: ltr;
      }
      .quiz-percentage-slider:focus {
        outline: none;
      }
      .quiz-percentage-slider::-webkit-slider-runnable-track {
        height: 10px;
        border-radius: 999px;
        background: linear-gradient(
          90deg,
          #2f8fff 0%,
          #66b2ff var(--range-progress),
          #e5e7eb var(--range-progress),
          #e5e7eb 100%
        );
      }
      .quiz-percentage-slider::-webkit-slider-thumb {
        appearance: none;
        width: 22px;
        height: 22px;
        border-radius: 50%;
        background: #fff;
        border: 3px solid #2f8fff;
        box-shadow: 0 3px 10px rgba(47, 143, 255, 0.35);
        margin-top: -6px;
      }
      .quiz-percentage-slider::-moz-range-track {
        height: 10px;
        border-radius: 999px;
        background: linear-gradient(
          90deg,
          #2f8fff 0%,
          #66b2ff var(--range-progress),
          #e5e7eb var(--range-progress),
          #e5e7eb 100%
        );
      }
      .quiz-percentage-slider::-moz-range-thumb {
        width: 22px;
        height: 22px;
        border-radius: 50%;
        background: #fff;
        border: 3px solid #2f8fff;
        box-shadow: 0 3px 10px rgba(47, 143, 255, 0.35);
      }

      .quiz-percentage-submit {
        width: 100%;
        min-height: 52px;
      }
      .quiz-percentage-submit-bottom {
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
        width: min(360px, calc(100% - 36px));
        bottom: 10px;
        background: #2f8fff;
        border-color: #2f8fff;
        color: #fff;
        font-weight: 700;
        box-shadow: 0 10px 22px rgba(47, 143, 255, 0.35);
      }
      .quiz-percentage-submit-bottom:hover {
        transform: translateX(-50%);
        background: #1f76e6;
        border-color: #1f76e6;
      }

      .quiz-percentage-submit-bottom.is-correct {
        animation: quiz-correct-pop-fixed 0.42s cubic-bezier(0.22, 1, 0.36, 1);
      }

      @keyframes quiz-correct-pop-fixed {
        0% { transform: translateX(-50%) scale(0.96); }
        55% { transform: translateX(-50%) scale(1.06); }
        100% { transform: translateX(-50%) scale(1); }
      }

      .login-area {
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: center;
        gap: 20px;
        padding: 26px 22px;
      }

      .login-hero {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 10px;
        text-align: center;
      }

      .login-icon {
        width: 92px;
        height: 92px;
        object-fit: contain;
        display: block;
      }

      .login-title {
        margin: 0;
        font-size: 1.08rem;
        color: var(--tc-highlight);
      }

      .login-form {
        display: flex;
        flex-direction: column;
        gap: 12px;
      }

      .login-field {
        display: flex;
        flex-direction: column;
        gap: 6px;
        font-size: 0.82rem;
        color: #516089;
      }

      .login-input {
        border: 1px solid #e1e8f4;
        border-radius: 12px;
        padding: 10px 12px;
        font-family: inherit;
        font-size: 0.95rem;
        background: #f8fbff;
        color: var(--ink);
        outline: none;
        transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
      }

      .login-input:focus {
        border-color: #9bbcff;
        box-shadow: 0 0 0 3px rgba(47, 143, 255, 0.12);
        background: #ffffff;
      }

      .login-btn {
        margin-top: 4px;
        border: none;
        border-radius: 14px;
        padding: 12px;
        font-family: 'Peyda Fa Num', 'Segoe UI', Tahoma, Arial, sans-serif;
        font-weight: 700;
        font-size: 0.95rem;
        background: var(--accent);
        color: var(--accent-ink);
        cursor: pointer;
        transition: background 0.2s ease, color 0.2s ease;
      }

      .login-btn:disabled {
        background: #c7d2e5;
        color: #6b7a99;
        cursor: not-allowed;
      }

      .login-hint {
        margin: 4px 0 0;
        min-height: 1.2em;
        font-size: 0.78rem;
        color: #d1434a;
        text-align: center;
      }

      .no-auth .wheel-shell {
        display: none;
      }

      .no-auth .main-area {
        display: none;
      }


      .hero {
        display: grid;
        place-items: center;
        width: 100%;
        order: 1;
      }

      .question {
        width: 82px;
        height: 82px;
        border-radius: 50%;
        display: grid;
        place-items: center;
        background: #f3f7ff;
        border: 1px solid #e4ebf7;
        overflow: hidden;
      }

      .hero-icon {
        width: 82px;
        height: 82px;
        object-fit: contain;
        display: block;
      }

      .question span {
        font-size: 2rem;
        font-weight: 700;
        color: #8da0c4;
      }

      .hint {
        margin: 10px 0 0;
        font-size: 0.85rem;
        color: var(--muted);
        padding: 0 16px;
        line-height: 1.35;
      }

      .hint p {
        margin: 0;
      }

      .hint-align-right {
        text-align: right;
      }

      .hint-align-center {
        text-align: center;
      }

      .hint-align-left {
        text-align: left;
      }

      .ql-align-center {
        text-align: center;
        padding: 0 30px;
      }

      .ql-align-right {
        text-align: right;
      }

      .ql-align-left {
        text-align: left;
      }

      .ql-align-justify {
        text-align: justify;
      }

      .tc-result-dialog-overlay {
        position: fixed;
        inset: 0;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 18px;
        background: rgba(18, 31, 56, 0.48);
        z-index: 9998;
      }

      .tc-result-dialog-overlay.open {
        display: flex;
      }

      .tc-result-dialog {
        width: min(460px, 100%);
        min-height: min(520px, calc(100vh - 36px));
        max-height: calc(100vh - 36px);
        background: #ffffff;
        border: 1px solid #e8edf6;
        border-radius: 28px;
        box-shadow: 0 26px 50px rgba(29, 55, 96, 0.24);
        position: relative;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 16px;
        padding: 24px 18px;
      }

      .tc-result-dialog-title {
        margin: 0;
        font-size: 1.18rem;
        color: #2a3c63;
        position: relative;
        z-index: 2;
      }

      .tc-result-dialog-content {
        flex: 1;
        width: 100%;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 12px;
        position: relative;
        z-index: 2;
      }

      .tc-result-gift {
        font-size: 7.2rem;
        color: var(--tc-highlight);
        line-height: 1;
        text-shadow: 0 8px 18px rgba(0, 0, 0, 0.18);
        animation: result-gift-shake 1.1s ease-in-out infinite;
        transform-origin: 50% 72%;
      }

      @keyframes result-gift-shake {
        0% { transform: rotate(0deg) translateX(0); }
        15% { transform: rotate(-10deg) translateX(-1px); }
        30% { transform: rotate(9deg) translateX(1px); }
        45% { transform: rotate(-8deg) translateX(-1px); }
        60% { transform: rotate(7deg) translateX(1px); }
        75% { transform: rotate(-5deg) translateX(0); }
        100% { transform: rotate(0deg) translateX(0); }
      }

      .tc-result-dialog-confirm {
        width: 100%;
        margin-top: auto;
        border: none;
        border-radius: 14px;
        padding: 12px;
        font-family: inherit;
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--accent-ink);
        background: var(--accent);
        cursor: pointer;
        transition: opacity 0.2s ease, transform 0.2s ease;
        position: relative;
        z-index: 2;
      }

      .tc-result-dialog-confirm:hover {
        transform: translateY(-1px);
      }

      .tc-result-dialog-confirm:disabled {
        opacity: 0.7;
        cursor: not-allowed;
        transform: none;
      }

      .result {
        margin: 0 auto;
        width: min(300px, calc(100% - 32px));
        border: 1px solid #e8edf6;
        border-radius: 14px;
        background: #f9fbff;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 2px;
        padding: 8px 10px;
        height: 3.2em;
        position: relative;
        overflow: hidden;
        transition: background 2s ease, border-color 2s ease, color 2s ease;
        z-index: 2;
      }

      .result::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(120deg, rgba(47, 143, 255, 0) 0%, rgba(47, 143, 255, 0.65) 45%, rgba(47, 143, 255, 0) 75%);
        transform: translateX(-130%);
        opacity: 0;
        pointer-events: none;
      }

      .result.result-shine::after {
        animation: result-shine 1.5s ease-in-out infinite;
      }

      .result.result-shake {
        animation: result-shake 1.2s ease-in-out infinite;
      }

      @keyframes result-shake {
        0% { transform: translateX(0); }
        20% { transform: translateX(-4px); }
        40% { transform: translateX(4px); }
        60% { transform: translateX(-3px); }
        80% { transform: translateX(3px); }
        100% { transform: translateX(0); }
      }

      .result.result-fake {
        background: #ffe7ea;
        border-color: #f2a0aa;
      }

      .result.result-fake .result-value {
        color: #c0262d;
      }

      .confetti-layer {
        position: absolute;
        inset: 0;
        pointer-events: none;
        overflow: hidden;
        z-index: 50;
      }

      .tc-result-dialog .confetti-layer {
        z-index: 1;
      }

      .confetti-piece {
        position: absolute;
        width: 8px;
        height: 16px;
        opacity: 0;
        animation: confetti-fall 2.8s ease-out forwards;
        --drift: 0px;
      }

      @keyframes confetti-fall {
        0% {
          transform: translate3d(0, -20px, 0) rotate(0deg);
          opacity: 0;
        }
        10% {
          opacity: 1;
        }
        100% {
          transform: translate3d(var(--drift), 280px, 0) rotate(240deg);
          opacity: 0;
        }
      }

      @keyframes result-shine {
        0% {
          transform: translateX(-150%);
          opacity: 0;
        }
        40% {
          opacity: 0.75;
        }
        100% {
          transform: translateX(150%);
          opacity: 0;
        }
      }

      .result-label {
        display: none;
      }


      .result-value {
        margin: 0;
        font-size: 1.2rem;
        font-weight: 700;
        color: #29365b;
        min-height: 1.3em;
        direction: rtl;
        unicode-bidi: plaintext;
        text-align: center;
        align-self: stretch;
      }

      #tc-result {
        display: flex;
        align-items: center;
        justify-content: center;
      }

      .result-value.drop-in {
        animation: result-drop 0.55s cubic-bezier(0.22, 1, 0.36, 1);
      }

      @keyframes result-drop {
        0% {
          transform: translateY(-16px) scale(0.96);
          opacity: 0;
        }
        70% {
          transform: translateY(2px) scale(1.02);
          opacity: 1;
        }
        100% {
          transform: translateY(0) scale(1);
          opacity: 1;
        }
      }

      .wheel-shell {
        position: relative;
        width: min(340px, calc(100vw - 84px));
        height: min(340px, calc(100vw - 84px));
        display: grid;
        place-items: center;
        overflow: visible;
        order: 3;
        margin: 16px 0 18px;
      }

      .wheel-status {
        position: relative;
        align-self: center;
        background: #ffffff;
        border: 1px solid #e5ecf7;
        border-radius: 999px;
        padding: 6px 12px;
        font-size: 0.78rem;
        color: #5b6a88;
        box-shadow: 0 10px 24px rgba(30, 62, 108, 0.08);
        white-space: nowrap;
        margin: 6px 0 0;
      }

      .wheel-status.hidden {
        display: none;
      }

      .hidden {
        display: none !important;
      }

      .wheel-status.top-left {
        position: static;
        margin: 0;
        align-self: center;
        margin-inline-start: auto;
      }

      .wheel-shell::before {
        content: '';
        position: absolute;
        width: calc(100% + 36px);
        height: calc(100% + 36px);
        border-radius: 50%;
        background:
          radial-gradient(circle at 35% 28%, rgba(255, 255, 255, 0.9), rgba(173, 219, 244, 0.42) 45%, rgba(0, 149, 218, 0.18) 100%);
        border: 1px solid rgba(158, 208, 240, 0.86);
        left: 50%;
        top: 50%;
        transform: translate(-50%, -50%);
        box-shadow:
          0 22px 34px rgba(35, 84, 154, 0.2),
          inset 0 2px 10px rgba(255, 255, 255, 0.65);
      }
      .wheel-shell::after {
        content: '';
        position: absolute;
        width: 36%;
        height: 150%;
        top: -25%;
        left: -18%;
        background: linear-gradient(90deg, rgba(255, 255, 255, 0) 0%, rgba(255, 255, 255, 0.62) 46%, rgba(255, 255, 255, 0) 100%);
        transform: translateX(-220%) rotate(18deg);
        opacity: 0;
        pointer-events: none;
        z-index: 2;
        animation: wheel-shine 2.1s ease-in-out infinite;
      }
      .wheel-shell.is-spinning::after {
        opacity: 0;
        animation: none;
      }
      @keyframes wheel-shine {
        0% {
          transform: translateX(-220%) rotate(18deg);
          opacity: 0;
        }
        35% {
          opacity: 0.82;
        }
        100% {
          transform: translateX(320%) rotate(18deg);
          opacity: 0;
        }
      }

      canvas {
        width: 100%;
        height: auto;
        background: radial-gradient(circle at 30% 20%, #f5fbff, #d9edf9 62%, #b7def2 100%);
        border-radius: 50%;
        border: 2px solid #b8daed;
        box-shadow:
          0 20px 40px rgba(21, 66, 129, 0.24),
          inset 0 0 0 1px rgba(255, 255, 255, 0.9);
        position: absolute;
        left: 50%;
        top: 50%;
        transform: translate(-50%, -50%);
        z-index: 1;
      }

      .wheel-slice-overlay {
        display: none;
        position: absolute;
        top: 36px;
        left: 50%;
        width: 76px;
        height: 86px;
        transform: translateX(-50%);
        clip-path: polygon(30% 0%, 70% 0%, 50% 100%);
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.54) 0%, rgba(255, 255, 255, 0.2) 55%, rgba(255, 255, 255, 0.04) 100%);
        border: 1px solid rgba(255, 255, 255, 0.45);
        border-radius: 60px 60px 0 0;
        box-shadow:
          inset 0 1px 1px rgba(255, 255, 255, 0.5),
          inset 0 -6px 12px rgba(255, 255, 255, 0.05),
          0 4px 10px rgba(14, 32, 63, 0.16);
        pointer-events: none;
        z-index: 2;
        backdrop-filter: blur(1.4px) saturate(1.12);
      }

      .wheel-slice-overlay::after {
        content: '';
        position: absolute;
        inset: 6px 8px 10px;
        border-radius: 999px;
        background: linear-gradient(90deg, rgba(255, 255, 255, 0) 22%, rgba(255, 255, 255, 0.78) 48%, rgba(255, 255, 255, 0) 76%);
        transform: translateX(-130%);
        opacity: 0;
        animation: slice-overlay-shine 1.45s ease-in-out infinite;
      }

      @keyframes slice-overlay-shine {
        0% {
          transform: translateX(-140%);
          opacity: 0;
        }
        38% {
          opacity: 0.75;
        }
        100% {
          transform: translateX(145%);
          opacity: 0;
        }
      }

      .pointer {
        position: absolute;
        top: -18px;
        left: 50%;
        width: 24px;
        height: 24px;
        transform: translateX(-50%);
        border-radius: 50%;
        background: radial-gradient(circle at 35% 28%, #f1f3f7, #6c7688 65%, #3b4250 100%);
        border: 2px solid #4a5362;
        z-index: 4;
        box-shadow:
          0 0 0 8px rgba(61, 70, 84, 0.14),
          0 0 24px rgba(61, 70, 84, 0.42),
          0 8px 14px rgba(33, 39, 50, 0.34);
      }
      .pointer::after {
        content: '';
        position: absolute;
        left: 50%;
        top: calc(100% - 1px);
        transform: translateX(-50%);
        width: 0;
        height: 0;
        border-left: 11px solid transparent;
        border-right: 11px solid transparent;
        border-top: 22px solid #3b4250;
        filter: drop-shadow(0 4px 10px rgba(46, 53, 66, 0.46));
      }

      .center-spin {
        position: absolute;
        left: 50%;
        top: 50%;
        transform: translate(-50%, -50%);
        border: none;
        border-radius: 999px;
        width: 84px;
        height: 84px;
        font-size: 0.9rem;
        font-weight: 700;
        font-family: inherit;
        color: var(--accent-ink);
        background: #ff4f00;
        box-shadow:
          0 14px 24px rgba(255, 79, 0, 0.34),
          inset 0 1px 0 rgba(255, 255, 255, 0.46);
        cursor: pointer;
        z-index: 4;
        transition: transform 0.2s ease, opacity 0.2s ease, background 2s ease, color 2s ease;
      }

      .center-spin:hover:not(:disabled) {
        transform: translate(-50%, -52%);
      }

      .center-spin:disabled {
        opacity: 1;
        cursor: not-allowed;
        background: #c7d2e5;
        color: #6b7a99;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.6);
      }

      .center-spin.is-spinning:disabled {
        background: #ff4f00;
        color: #ffffff;
        box-shadow:
          0 14px 24px rgba(255, 79, 0, 0.34),
          inset 0 1px 0 rgba(255, 255, 255, 0.46);
      }

      .wheel-count {
        position: static;
        z-index: 2;
        order: 2;
        margin: 2px auto 0;
        text-align: center;
        font-size: 0.72rem;
        font-weight: 600;
        color: #6f7c98;
        background: rgba(255, 255, 255, 0.82);
        border: 1px solid #e5ecf7;
        border-radius: 999px;
        padding: 4px 8px;
        direction: rtl;
        unicode-bidi: plaintext;
      }

      @media (max-width: 440px) {
        body {
          padding: 10px;
        }

        .phone {
          border-radius: 22px;
          min-height: calc(100vh - 20px);
        }

        .wheel-shell {
          width: min(296px, calc(100vw - 52px));
          height: min(296px, calc(100vw - 52px));
          margin: 18px 0 20px;
        }

        .main-area {
          padding: 6px 16px 2px;
        }

        .tc-bottom-cta {
          padding-inline: 16px;
          padding-bottom: 12px;
        }

        .tc-bottom-cta-btn {
          width: min(296px, calc(100vw - 52px));
        }

        .center-spin {
          width: 74px;
          height: 74px;
          font-size: 0.82rem;
        }
      }

      @supports (height: 100dvh) {
        .phone {
          min-height: min(860px, calc(100dvh - 36px));
          height: calc(100dvh - 36px);
        }
      }
    </style>
  </head>
  <body class="page-loading <?= $sessionPayload['authed'] ? 'authed' : 'no-auth' ?>">
    <div id="tc-loader" class="loader-overlay" role="status" aria-live="polite">
      <div class="loader-card">
        <div class="loader-icon-wrap" aria-hidden="true">
          <svg class="loader-icon-svg" viewBox="0 0 1173 773" aria-hidden="true" focusable="false">
            <path class="loader-icon-fill" d="M1173 407.266V773C791.7 589.486 381.3 521.402 0 573.591V16.8977C319.721 -26.5479 659.341 13.9796 985.446 136.213C1099.03 178.364 1173 286.979 1173 406.947V407.266Z" />
            <path class="loader-icon-path" d="M1173 407.266V773C791.7 589.486 381.3 521.402 0 573.591V16.8977C319.721 -26.5479 659.341 13.9796 985.446 136.213C1099.03 178.364 1173 286.979 1173 406.947V407.266Z" />
          </svg>
        </div>
        <p class="loader-text">در حال آماده سازی</p>
        <p class="loader-subtext">لطفا چند لحظه صبر کنید</p>
      </div>
    </div>
    <main class="app">
      <section class="phone">
    <div class="topbar">
          <p class="brand">
            <?php if ($fallbackSiteIconUrl !== ''): ?>
              <img class="brand-icon" src="<?= htmlspecialchars($fallbackSiteIconUrl, ENT_QUOTES, 'UTF-8') ?>" alt="آیکن سایت" />
            <?php endif; ?>
            <span>کمپین باشگاه تعاملی</span>
          </p>
          <div class="topbar-actions">
            <?php if ($sessionPayload['authed']): ?>
              <button id="tc-topbar-back" class="logout-btn hidden" type="button">
                <span aria-hidden="true"></span>
                برگشت
              </button>
              <button id="tc-logout" class="logout-btn" type="button">
                <span aria-hidden="true"></span>
                خروج
              </button>
            <?php endif; ?>
          </div>
        </div>

        <?php if (!$sessionPayload['authed']): ?>
        <div class="login-area">
          <div class="login-hero">
            <?php if ($faviconUrl !== ''): ?>
              <img class="login-icon" src="<?= htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8') ?>" alt="آیکن سایت" />
            <?php else: ?>
              <div class="question">
                <span>?</span>
              </div>
            <?php endif; ?>
            <h2 class="login-title">باشگاه تعاملی</h2>
          </div>
          <form id="tc-login-form" class="login-form" autocomplete="on">
            <label class="login-field">
              <span>نام کاربری</span>
              <input id="tc-login-user" class="login-input" type="text" autocomplete="username" required />
            </label>
            <label class="login-field">
              <span>رمز عبور</span>
              <input id="tc-login-pass" class="login-input" type="password" autocomplete="current-password" required />
            </label>
            <button type="submit" class="login-btn">ورود</button>
            <p id="tc-login-msg" class="login-hint" aria-live="polite"></p>
          </form>
        </div>
      <?php else: ?>
        <div id="tc-timer-area" class="main-area">
          <?php if ($eventLogoUrl !== ''): ?>
            <img class="task-event-logo" src="<?= htmlspecialchars($eventLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="لوگوی رویداد" />
          <?php endif; ?>
          <h2 id="tc-tasks-title" class="tasks-title">امتیاز جمع کن، جایزه ببر!</h2>
          <div class="user-score-chip">
            <span>امتیاز شما</span>
            <strong id="tc-user-score"><?= (int)($sessionPayload['taskTotalScore'] ?? 0) ?></strong>
          </div>
          <p id="tc-event-notice" class="tasks-empty hidden" aria-live="polite"></p>
          <div id="tc-tasks-list" class="tasks-list" aria-label="فهرست ماموریت‌ها">
            <?php if ($taskItemsForView): ?>
              <?php foreach ($taskItemsForView as $taskItem): ?>
                <?php
                  $taskId = (string)($taskItem['id'] ?? '');
                  $taskTitle = (string)($taskItem['title'] ?? '');
                  $isAvailable = (bool)($taskItem['available'] ?? false);
                  $isCompleted = (bool)($taskItem['completed'] ?? false);
                  $buttonClass = 'task-item-btn';
                  if (!$isAvailable) {
                    $buttonClass .= ' is-disabled';
                  }
                  if ($isCompleted) {
                    $buttonClass .= ' is-completed';
                  }
                  $disabledAttr = $isAvailable ? '' : 'disabled';
                ?>
                <button
                  class="<?= htmlspecialchars($buttonClass, ENT_QUOTES, 'UTF-8') ?>"
                  type="button"
                  data-task-id="<?= htmlspecialchars($taskId, ENT_QUOTES, 'UTF-8') ?>"
                  data-task-title="<?= htmlspecialchars($taskTitle, ENT_QUOTES, 'UTF-8') ?>"
                  data-task-type="<?= htmlspecialchars((string)($taskItem['taskType'] ?? 'quiz'), ENT_QUOTES, 'UTF-8') ?>"
                  data-task-active="<?= !empty($taskItem['active']) ? '1' : '0' ?>"
                  data-task-duration="<?= !empty($taskItem['duration']) ? '1' : '0' ?>"
                  data-task-start-date="<?= htmlspecialchars((string)($taskItem['startDate'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                  data-task-start-time="<?= htmlspecialchars((string)($taskItem['startTime'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                  data-task-end-date="<?= htmlspecialchars((string)($taskItem['endDate'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                  data-task-end-time="<?= htmlspecialchars((string)($taskItem['endTime'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                  data-task-score="<?= (int)($taskItem['score'] ?? 0) ?>"
                  data-task-after-end-score="<?= (int)($taskItem['afterEndtimeScore'] ?? 0) ?>"
                  data-task-status="<?= htmlspecialchars((string)($taskItem['status'] ?? 'inactive'), ENT_QUOTES, 'UTF-8') ?>"
                  data-task-completed="<?= $isCompleted ? '1' : '0' ?>"
                  data-task-user-score="<?= (int)($taskItem['userScore'] ?? 0) ?>"
                  <?= $disabledAttr ?>
                >
                  <span class="task-item-title"><?= htmlspecialchars($taskTitle, ENT_QUOTES, 'UTF-8') ?></span>
                  <span class="task-item-meta"><?= htmlspecialchars((string)($taskItem['statusLabel'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                </button>
              <?php endforeach; ?>
            <?php else: ?>
              <p class="tasks-empty">ماموریتی برای نمایش وجود ندارد.</p>
            <?php endif; ?>
          </div>
        </div>
        <div id="tc-bottom-cta" class="tc-bottom-cta">
          <button id="tc-open-rewards-btn" class="tc-bottom-cta-btn" type="button">دریافت جایزه</button>
        </div>
        <div id="tc-rewards-view" class="main-area rewards-view hidden" aria-hidden="true">
          <?php if ($eventLogoUrl !== ''): ?>
            <img class="task-event-logo" src="<?= htmlspecialchars($eventLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="لوگوی رویداد" />
          <?php endif; ?>
          <h2 id="tc-rewards-title" class="tasks-title">امتیاز جمع کن، جایزه ببر!</h2>
          <div class="user-score-chip">
            <span>امتیاز شما</span>
            <strong id="tc-reward-user-score-chip-value"><?= (int)($sessionPayload['taskTotalScore'] ?? 0) ?></strong>
          </div>
          <section class="rewards-roadmap-main">
            <div id="tc-reward-roadmap" class="roadmap-list"></div>
          </section>
          <div class="result rewards-total-bar">
            <span id="tc-reward-time-label" class="result-label">مجموع جوایز برنده شده</span>
            <p id="tc-reward-time-value" class="result-value">—</p>
          </div>
          <p id="tc-reward-status-line" class="tasks-empty" aria-live="polite"></p>
        </div>
        <div id="tc-reward-cards-view" class="main-area rewards-view hidden" aria-hidden="true">
          <p id="tc-reward-cards-hint" class="tasks-empty"></p>
          <section id="tc-reward-cards-box" class="cards-box">
            <div id="tc-reward-cards" class="cards-grid"></div>
          </section>
          <div class="result rewards-time-box">
            <span class="result-label">مجموع جوایز برنده شده</span>
            <p id="tc-reward-cards-total-value" class="result-value">۰ تومان</p>
          </div>
        </div>
        <div id="tc-task-quiz-area" class="quiz-area quiz-hidden">
          <div class="tc-task-quiz-head">
            <h3 id="tc-task-quiz-title" class="tc-task-quiz-title">ماموریت کوییز</h3>
          </div>
          <div id="tc-task-quiz-counter" class="quiz-counter">1 / 1</div>
          <div id="tc-task-quiz-question" class="quiz-question-box">-</div>
          <div id="tc-task-quiz-answers" class="quiz-answers-grid"></div>
          <div class="quiz-timer-track"><div id="tc-task-quiz-timer-fill" class="quiz-timer-fill"></div></div>
        </div>
      <?php endif; ?>
      </section>
    </main>
    <?php if ($sessionPayload['authed']): ?>
      <div id="tc-task-result-dialog" class="tc-result-dialog-overlay" aria-hidden="true">
        <section class="tc-result-dialog" role="dialog" aria-modal="true" aria-labelledby="tc-task-result-title">
          <h3 id="tc-task-result-title" class="tc-result-dialog-title">نتیجه ماموریت</h3>
          <div class="tc-result-dialog-content">
            <div class="result">
              <span class="result-label">امتیاز</span>
              <p id="tc-task-result-value" class="result-value">0</p>
            </div>
            <p id="tc-task-result-message" class="hint hint-align-center">-</p>
          </div>
          <button id="tc-task-result-confirm" class="tc-result-dialog-confirm" type="button">تایید</button>
        </section>
      </div>
      <div id="tc-reward-win-dialog" class="tc-result-dialog-overlay" aria-hidden="true">
        <section class="tc-result-dialog" role="dialog" aria-modal="true" aria-labelledby="tc-reward-win-title">
          <div id="tc-reward-win-confetti" class="confetti-layer" aria-hidden="true"></div>
          <h3 id="tc-reward-win-title" class="tc-result-dialog-title">شما برنده شدید</h3>
          <div class="tc-result-dialog-content">
            <span class="tc-result-gift" aria-hidden="true">🎁</span>
            <div class="result">
              <span class="result-label">جایزه شما</span>
              <p id="tc-reward-win-value" class="result-value">-</p>
            </div>
            <p id="tc-reward-win-message" class="hint hint-align-center">تبریک! جایزه شما ثبت شد.</p>
          </div>
          <button id="tc-reward-win-confirm" class="tc-result-dialog-confirm" type="button">عالیه</button>
        </section>
      </div>
      <div id="tc-info-dialog" class="tc-result-dialog-overlay" aria-hidden="true">
        <section class="tc-result-dialog" role="dialog" aria-modal="true" aria-labelledby="tc-info-dialog-title">
          <h3 id="tc-info-dialog-title" class="tc-result-dialog-title">پیام</h3>
          <div class="tc-result-dialog-content">
            <p id="tc-info-dialog-message" class="hint hint-align-center">-</p>
          </div>
          <button id="tc-info-dialog-confirm" class="tc-result-dialog-confirm" type="button">متوجه شدم</button>
        </section>
      </div>
    <?php endif; ?>

    <script nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>">
      const loaderEl = document.getElementById('tc-loader');
      const bodyEl = document.body;
      const loaderStart = performance.now();
      const minLoaderDuration = 1300;

      const waitForFonts = async () => {
        if (!document.fonts) {
          return;
        }
        try {
          await Promise.all([
            document.fonts.load('400 16px "Peyda Fa Num"'),
            document.fonts.load('700 16px "Peyda Fa Num"'),
            document.fonts.ready
          ]);
        } catch {}
      };

      const revealPage = () => {
        bodyEl.classList.remove('page-loading');
        if (loaderEl) {
          loaderEl.classList.add('loader-hidden');
          setTimeout(() => loaderEl.remove(), 450);
        }
      };

      const bootReady = async () => {
        try {
          await Promise.all([
            waitForFonts(),
            new Promise(resolve => window.addEventListener('load', resolve, { once: true }))
          ]);
        } catch {}
        const elapsed = performance.now() - loaderStart;
        if (elapsed < minLoaderDuration) {
          await new Promise(resolve => setTimeout(resolve, minLoaderDuration - elapsed));
        }
        revealPage();
      };

      bootReady();

      const sessionInfo = <?= json_encode($sessionPayload, JSON_UNESCAPED_UNICODE); ?>;
      const csrfToken = <?= json_encode($_SESSION['tc_csrf'], JSON_UNESCAPED_UNICODE); ?>;
      const rewardCardLogoUrl = <?= json_encode($eventLogoUrl !== '' ? $eventLogoUrl : $fallbackSiteIconUrl, JSON_UNESCAPED_UNICODE); ?>;
      const loginForm = document.getElementById('tc-login-form');
      const logoutBtn = document.getElementById('tc-logout');
      const createRuntimeLoader = (primaryText, secondaryText = '') => {
        const overlay = document.createElement('div');
        overlay.className = 'loader-overlay';
        overlay.setAttribute('role', 'status');
        overlay.setAttribute('aria-live', 'polite');
        overlay.innerHTML = [
          '<div class="loader-card">',
          '<div class="loader-icon-wrap" aria-hidden="true">',
          '<svg class="loader-icon-svg" viewBox="0 0 1173 773" aria-hidden="true" focusable="false">',
          '<path class="loader-icon-fill" d="M1173 407.266V773C791.7 589.486 381.3 521.402 0 573.591V16.8977C319.721 -26.5479 659.341 13.9796 985.446 136.213C1099.03 178.364 1173 286.979 1173 406.947V407.266Z"></path>',
          '<path class="loader-icon-path" d="M1173 407.266V773C791.7 589.486 381.3 521.402 0 573.591V16.8977C319.721 -26.5479 659.341 13.9796 985.446 136.213C1099.03 178.364 1173 286.979 1173 406.947V407.266Z"></path>',
          '</svg>',
          '</div>',
          '<p class="loader-text"></p>',
          '<p class="loader-subtext"></p>',
          '</div>'
        ].join('');
        overlay.querySelector('.loader-text').textContent = String(primaryText || '').trim() || 'در حال پردازش';
        overlay.querySelector('.loader-subtext').textContent = String(secondaryText || '').trim();
        document.body.appendChild(overlay);
      };
      const performLogout = async ({ loaderText = '', loaderSubtext = '' } = {}) => {
        if (loaderText) {
          createRuntimeLoader(loaderText, loaderSubtext);
        }
        try {
          await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'logout', csrf: csrfToken })
          });
        } catch {}
        window.location.reload();
      };
      if (!sessionInfo?.authed) {
        const loginBtn = document.querySelector('.login-btn');
        const loginMsg = document.getElementById('tc-login-msg');
        const userInput = document.getElementById('tc-login-user');
        const passInput = document.getElementById('tc-login-pass');
        if (loginForm) {
          loginForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (loginBtn) loginBtn.disabled = true;
            if (loginMsg) loginMsg.textContent = '';
            try {
              const username = String(userInput?.value ?? '').trim();
              const password = String(passInput?.value ?? '').trim();
              const response = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'login', username, password, csrf: csrfToken })
              });
              const payload = await response.json();
              if (response.ok && payload?.status === 'ok') {
                window.location.reload();
                return;
              }
              if (loginMsg) {
                loginMsg.textContent = payload?.message || 'ورود failed.';
              }
            } catch {
              if (loginMsg) {
                loginMsg.textContent = 'ورود failed.';
              }
            } finally {
              if (loginBtn) loginBtn.disabled = false;
            }
          });
        }
      }

      if (sessionInfo?.authed) {
        if (logoutBtn) {
          logoutBtn.addEventListener('click', async () => {
            await performLogout();
          });
        }

        const timeCounterLabelEl = document.getElementById('tc-time-counter-label');
        const timeCounterEl = document.getElementById('tc-time-counter');
        const statusEl = document.getElementById('tc-status');
        const userScoreEl = document.getElementById('tc-user-score');
        const timerAreaEl = document.getElementById('tc-timer-area');
        const tasksTitleEl = document.getElementById('tc-tasks-title');
        const tasksListEl = document.getElementById('tc-tasks-list');
        const eventNoticeEl = document.getElementById('tc-event-notice');
        const bottomCtaEl = document.getElementById('tc-bottom-cta');
        const bottomCtaBtnEl = bottomCtaEl ? bottomCtaEl.querySelector('.tc-bottom-cta-btn') : null;
        const rewardsViewEl = document.getElementById('tc-rewards-view');
        const rewardCardsViewEl = document.getElementById('tc-reward-cards-view');
        const openRewardsBtnEl = document.getElementById('tc-open-rewards-btn');
        const topbarBackBtnEl = document.getElementById('tc-topbar-back');
        const rewardTimeLabelEl = document.getElementById('tc-reward-time-label');
        const rewardTimeValueEl = document.getElementById('tc-reward-time-value');
        const rewardsTotalBarEl = rewardsViewEl ? rewardsViewEl.querySelector('.rewards-total-bar') : null;
        const rewardScoreChipEl = document.getElementById('tc-reward-user-score-chip-value');
        const rewardRoadmapEl = document.getElementById('tc-reward-roadmap');
        const rewardCardsBoxEl = document.getElementById('tc-reward-cards-box');
        const rewardCardsEl = document.getElementById('tc-reward-cards');
        const rewardCardsHintEl = document.getElementById('tc-reward-cards-hint');
        const rewardStatusLineEl = document.getElementById('tc-reward-status-line');
        const rewardCardsTotalValueEl = document.getElementById('tc-reward-cards-total-value');
        const rewardWinDialogEl = document.getElementById('tc-reward-win-dialog');
        const rewardWinConfettiEl = document.getElementById('tc-reward-win-confetti');
        const rewardWinValueEl = document.getElementById('tc-reward-win-value');
        const rewardWinMessageEl = document.getElementById('tc-reward-win-message');
        const rewardWinConfirmEl = document.getElementById('tc-reward-win-confirm');
        const infoDialogEl = document.getElementById('tc-info-dialog');
        const infoDialogTitleEl = document.getElementById('tc-info-dialog-title');
        const infoDialogMessageEl = document.getElementById('tc-info-dialog-message');
        const infoDialogConfirmEl = document.getElementById('tc-info-dialog-confirm');
        const quizAreaEl = document.getElementById('tc-task-quiz-area');
        const taskButtons = Array.from(document.querySelectorAll('.task-item-btn[data-task-id]'));
        const quizTitleEl = document.getElementById('tc-task-quiz-title');
        const quizCounterEl = document.getElementById('tc-task-quiz-counter');
        const quizQuestionEl = document.getElementById('tc-task-quiz-question');
        const quizAnswersEl = document.getElementById('tc-task-quiz-answers');
        const quizTimerFillEl = document.getElementById('tc-task-quiz-timer-fill');
        const resultDialogEl = document.getElementById('tc-task-result-dialog');
        const resultValueEl = document.getElementById('tc-task-result-value');
        const resultMessageEl = document.getElementById('tc-task-result-message');
        const resultConfirmBtn = document.getElementById('tc-task-result-confirm');

        let statusTickTimer = null;
        let taskStatusTimer = null;
        let taskCountdownTickTimer = null;
        let quizTimerHandle = null;
        let quizLocked = false;
        let rewardsViewOpen = false;
        let rewardsCardsViewOpen = false;
        let rewardsRoundBusy = false;
        let rewardsState = null;
        let rewardEventTickTimer = null;
        let selectedRewardLevelId = '';
        let rewardCardsDeck = [];
        const rewardCardsLockedIndexes = new Set();
        const rewardCardsLockedPrizes = new Map();
        let currentTaskId = '';
        let currentTaskTitle = '';
        let currentQuestions = [];
        let currentQuestionIndex = 0;
        let answerTimeLimitEnabled = true;
        let globalEventStatus = 'inactive';

        const QUIZ_TIME_LIMIT_MS = 14000;

        const setTimeCounter = (label, value) => {
          if (timeCounterLabelEl) {
            timeCounterLabelEl.textContent = label;
          }
          if (timeCounterEl) {
            timeCounterEl.textContent = value;
          }
        };

        const showStatus = (status, text) => {
          if (!statusEl) {
            return;
          }
          statusEl.classList.remove('hidden');
          statusEl.textContent = text;
          statusEl.dataset.state = status;
        };

        const loadWheelSettings = async () => {
          try {
            const response = await fetch('tc_store.php?action=get_settings', { cache: 'no-store' });
            const payload = await response.json();
            if (payload?.status === 'ok' && payload.data && typeof payload.data === 'object') {
              return payload.data;
            }
          } catch {}
          return {};
        };

        const TEHRAN_OFFSET_MINUTES = 210;

        const getTehranDateTimeParts = (date = new Date()) => {
          const utcMs = date.getTime() + date.getTimezoneOffset() * 60000;
          const tehran = new Date(utcMs + TEHRAN_OFFSET_MINUTES * 60000);
          const year = String(tehran.getFullYear());
          const month = String(tehran.getMonth() + 1).padStart(2, '0');
          const day = String(tehran.getDate()).padStart(2, '0');
          const hour = String(tehran.getHours()).padStart(2, '0');
          const minute = String(tehran.getMinutes()).padStart(2, '0');
          const second = String(tehran.getSeconds()).padStart(2, '0');
          return { date: `${year}-${month}-${day}`, time: `${hour}:${minute}:${second}` };
        };

        const getTehranTargetDate = (dateStr, timeStr) => {
          const dateParts = String(dateStr || '').split('-').map((n) => Number(n));
          const timeParts = String(timeStr || '').split(':').map((n) => Number(n));
          if (dateParts.length !== 3 || timeParts.length < 2) {
            return null;
          }
          const [year, month, day] = dateParts;
          const [hour, minute, second = 0] = timeParts;
          if (![year, month, day, hour, minute, second].every((n) => Number.isFinite(n))) {
            return null;
          }
          const utcMs = Date.UTC(year, month - 1, day, hour, minute, second) - TEHRAN_OFFSET_MINUTES * 60000;
          return new Date(utcMs);
        };

        const parseTimeToSeconds = (value) => {
          if (!value) return null;
          const normalized = String(value).trim();
          const parts = normalized.split(':').map((part) => Number(part));
          if (parts.length < 2 || parts.length > 3 || parts.some((n) => !Number.isFinite(n))) {
            return null;
          }
          const [hours, minutes, seconds = 0] = parts;
          return hours * 3600 + minutes * 60 + seconds;
        };

        const compareDates = (a = '', b = '') => {
          const left = (a || '').trim();
          const right = (b || '').trim();
          if (!left || !right) return null;
          if (left === right) return 0;
          return left > right ? 1 : -1;
        };

        const getCurrentTehranSeconds = () => {
          const parts = getTehranDateTimeParts();
          return parseTimeToSeconds(parts.time) ?? 0;
        };

        const describeStatus = (settings) => {
          const active = Boolean(settings?.active);
          const duration = Boolean(settings?.duration);
          if (!duration) {
            return active ? 'active' : 'inactive';
          }
          const startDate = String(settings?.startDate ?? '').trim();
          const endDate = String(settings?.endDate ?? '').trim();
          const startTime = String(settings?.startTime ?? '').trim();
          const endTime = String(settings?.endTime ?? '').trim();
          const today = getTehranDateTimeParts();
          const startRelation = compareDates(startDate, today.date);
          const endRelation = compareDates(endDate, today.date);
          if (!startDate || !today.date) return 'inactive';
          if (startRelation === 1) return 'upcoming';
          if (endRelation !== null && endRelation === -1) return 'ended';
          const nowSeconds = getCurrentTehranSeconds();
          if (startRelation === 0 || endRelation === 0) {
            const startSeconds = parseTimeToSeconds(startTime);
            const endSeconds = parseTimeToSeconds(endTime);
            if (endSeconds !== null && nowSeconds >= endSeconds) return 'ended';
            if (startSeconds !== null && nowSeconds >= startSeconds) return 'active';
            if (startSeconds !== null && nowSeconds < startSeconds) return 'upcoming';
          }
          return 'active';
        };

        const clearStatusTimer = () => {
          if (statusTickTimer) {
            clearInterval(statusTickTimer);
            statusTickTimer = null;
          }
        };

        const setFallbackCounter = (status) => {
          if (status === 'upcoming') {
            setTimeCounter('تا شروع رویداد', 'در انتظار شروع');
            return;
          }
          if (status === 'active') {
            setTimeCounter('تا پایان رویداد', '-');
            return;
          }
          if (status === 'inactive') {
            setTimeCounter('وضعیت', 'غیرفعال');
            return;
          }
          setTimeCounter('وضعیت', 'پایان‌یافته');
        };

        const updateTimerByStatus = (status, settings) => {
          clearStatusTimer();
          if (status === 'inactive') {
            showStatus(status, 'غیرفعال');
            setTimeCounter('وضعیت', 'غیرفعال');
            return;
          }
          if (status === 'ended') {
            showStatus(status, 'پایان‌یافته');
            setTimeCounter('وضعیت', 'پایان‌یافته');
            return;
          }

          if (status === 'upcoming') {
            showStatus(status, 'در انتظار شروع');
          } else {
            showStatus(status, 'فعال');
          }

          const durationOn = Boolean(settings?.duration);
          if (!durationOn) {
            setFallbackCounter(status);
            return;
          }

          const startDate = String(settings?.startDate ?? '').trim();
          const endDate = String(settings?.endDate ?? '').trim();
          const startTime = String(settings?.startTime ?? '').trim();
          const endTime = String(settings?.endTime ?? '').trim();
          const targetDate = status === 'upcoming' ? startDate : endDate;
          const targetTime = status === 'upcoming' ? startTime : endTime;
          const label = status === 'upcoming' ? 'تا شروع رویداد' : 'تا پایان رویداد';

          const updateCountdown = () => {
            if (!targetDate || !targetTime) {
              setFallbackCounter(status);
              return;
            }
            const target = getTehranTargetDate(targetDate, targetTime);
            if (!target) {
              setFallbackCounter(status);
              return;
            }
            let diff = Math.max(0, Math.floor((target.getTime() - Date.now()) / 1000));
            const hours = Math.floor(diff / 3600);
            diff -= hours * 3600;
            const minutes = Math.floor(diff / 60);
            const seconds = diff - minutes * 60;
            const timer = `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
            setTimeCounter(label, timer);
          };

          updateCountdown();
          statusTickTimer = setInterval(updateCountdown, 1000);
        };

        const refreshStatus = async () => {
          const settings = await loadWheelSettings();
          const status = describeStatus(settings);
          globalEventStatus = status;
          updateTimerByStatus(status, settings);
          applyEventGate(status);
          refreshTaskButtonsStatus();
          if (rewardsViewOpen) {
            await refreshRewardState();
          }
        };

        const readTaskBool = (value) => {
          const token = String(value ?? '').trim().toLowerCase();
          return token === '1' || token === 'true' || token === 'on' || token === 'yes';
        };

        const taskStatusLabel = (status, completed = false, taskType = 'quiz') => {
          if (completed) return 'تکمیل شده';
          if (status === 'active') {
            return taskType === 'quiz' ? 'مهلت طلایی' : 'فعال';
          }
          if (status === 'upcoming') return 'به‌زودی';
          if (status === 'ended') {
            return taskType === 'quiz'
              ? 'مهلت طلایی تمام شده؛ پاسخ دهید و امتیاز کمتر بگیرید'
              : 'پایان‌یافته';
          }
          return 'غیرفعال';
        };

        const taskAvailableScoreNow = (button, status, taskType) => {
          const activeScore = Math.max(0, Number.parseInt(button?.dataset?.taskScore || '0', 10) || 0);
          const afterEndScore = Math.max(0, Number.parseInt(button?.dataset?.taskAfterEndScore || '0', 10) || 0);
          if (taskType === 'quiz' && status === 'ended') {
            return afterEndScore;
          }
          return activeScore;
        };

        const withScoreHint = (baseText, scoreValue) => {
          const text = String(baseText || '').trim();
          return `${text} | امتیاز ماموریت: ${Math.max(0, Number.parseInt(scoreValue ?? 0, 10) || 0)}`;
        };

        const deriveTaskStatusFromButton = (button) => {
          const active = readTaskBool(button.dataset.taskActive);
          const duration = readTaskBool(button.dataset.taskDuration);
          if (!duration) {
            return active ? 'active' : 'inactive';
          }

          const startDate = String(button.dataset.taskStartDate || '').trim();
          const startTime = String(button.dataset.taskStartTime || '').trim();
          const endDate = String(button.dataset.taskEndDate || '').trim();
          const endTime = String(button.dataset.taskEndTime || '').trim();
          const today = getTehranDateTimeParts();
          const startRelation = compareDates(startDate, today.date);
          const endRelation = compareDates(endDate, today.date);
          if (!startDate || !today.date) return 'inactive';
          if (startRelation === 1) return 'upcoming';
          if (endRelation !== null && endRelation === -1) return 'ended';

          const nowSeconds = getCurrentTehranSeconds();
          if (startRelation === 0 || endRelation === 0) {
            const startSeconds = parseTimeToSeconds(startTime);
            const endSeconds = parseTimeToSeconds(endTime);
            if (endSeconds !== null && nowSeconds >= endSeconds) return 'ended';
            if (startSeconds !== null && nowSeconds >= startSeconds) return 'active';
            if (startSeconds !== null && nowSeconds < startSeconds) return 'upcoming';
          }
          return 'active';
        };

        const normalizeUpcomingStartTime = (value) => {
          const normalized = String(value || '').trim();
          return normalized !== '' ? normalized : '00:00';
        };

        const normalizeGoldenEndTime = (value) => {
          const normalized = String(value || '').trim();
          return normalized !== '' ? normalized : '23:59';
        };

        const formatTaskCountdown = (targetDate, targetTime) => {
          const target = getTehranTargetDate(targetDate, normalizeUpcomingStartTime(targetTime));
          if (!target) {
            return '';
          }
          const diffSeconds = Math.max(0, Math.floor((target.getTime() - Date.now()) / 1000));
          const human = formatFaDuration(diffSeconds, { includeSeconds: false });
          return `${human} تا شروع ماموریت بعدی`;
        };

        const formatFaDuration = (secondsValue, { includeSeconds = false } = {}) => {
          const total = Math.max(0, Math.floor(Number(secondsValue) || 0));
          const days = Math.floor(total / 86400);
          const hours = Math.floor((total % 86400) / 3600);
          const minutes = Math.floor((total % 3600) / 60);
          const seconds = total % 60;
          const parts = [];
          if (days > 0) parts.push(`${days} روز`);
          if (hours > 0) parts.push(`${hours} ساعت`);
          if (minutes > 0) parts.push(`${minutes} دقیقه`);
          if (includeSeconds && seconds > 0) parts.push(`${seconds} ثانیه`);
          if (!parts.length) {
            return includeSeconds ? '0 ثانیه' : '0 دقیقه';
          }
          return parts.join(' و ');
        };

        const formatEventCountdown = (targetDate, targetTime) => {
          const target = getTehranTargetDate(targetDate, targetTime);
          if (!target) return '';
          const diffSeconds = Math.max(0, Math.floor((target.getTime() - Date.now()) / 1000));
          return formatFaDuration(diffSeconds, { includeSeconds: true });
        };

        const formatGoldenTimeCountdown = (targetDate, targetTime) => {
          const target = getTehranTargetDate(targetDate, normalizeGoldenEndTime(targetTime));
          if (!target) {
            return '';
          }
          const diffSeconds = Math.max(0, Math.floor((target.getTime() - Date.now()) / 1000));
          return `تا پایان مهلت طلایی پاسخ به سوال\n${formatFaDuration(diffSeconds, { includeSeconds: true })}`;
        };

        const canOpenTaskByStatus = (status, taskType) => {
          if (status === 'active') return true;
          if (taskType === 'quiz' && status === 'ended') return true;
          return false;
        };

        const setTaskButtonState = (button, status, closestUpcomingTaskId = '') => {
          if (!(button instanceof HTMLButtonElement)) {
            return;
          }
          const metaEl = button.querySelector('.task-item-meta');
          const completed = String(button.dataset.taskCompleted || '') === '1';
          const taskScore = Number.parseInt(button.dataset.taskUserScore || '0', 10);
          const taskType = String(button.dataset.taskType || 'quiz').trim().toLowerCase() || 'quiz';

          if (completed) {
            button.disabled = true;
            button.classList.remove('is-disabled');
            button.classList.add('is-completed');
            button.classList.remove('is-golden');
            button.classList.remove('is-golden-live');
            button.dataset.taskStatus = 'completed';
            if (metaEl) {
              metaEl.classList.remove('is-multiline');
              const shownScore = Number.isFinite(taskScore) ? Math.max(0, taskScore) : 0;
              metaEl.textContent = shownScore > 0 ? `تکمیل شده (امتیاز ${shownScore})` : 'تکمیل شده';
            }
            return;
          }

          const globallyBlocked = globalEventStatus === 'inactive';
          const available = !globallyBlocked && canOpenTaskByStatus(status, taskType);
          const isUpcoming = status === 'upcoming';
          const scoreNow = taskAvailableScoreNow(button, status, taskType);
          button.disabled = !available;
          button.classList.toggle('is-disabled', !available && !isUpcoming);
          button.classList.toggle('is-upcoming', isUpcoming);
          button.classList.remove('is-completed');
          button.classList.toggle('is-golden', status === 'active' && taskType === 'quiz');
          button.classList.toggle('is-golden-live', false);
          button.dataset.taskStatus = status;
          if (metaEl) {
            metaEl.classList.remove('is-multiline');
            if (status === 'upcoming') {
              const startDate = String(button.dataset.taskStartDate || '').trim();
              const startTime = String(button.dataset.taskStartTime || '').trim();
              const taskId = String(button.dataset.taskId || '').trim();
              if (taskId !== '' && taskId === closestUpcomingTaskId) {
                const countdown = formatTaskCountdown(startDate, startTime);
                const fallbackText = startDate
                  ? `شروع از: ${startDate} ${normalizeUpcomingStartTime(startTime)}`
                  : taskStatusLabel(status, false, taskType);
                metaEl.textContent = withScoreHint(countdown || fallbackText, scoreNow);
              } else {
                metaEl.textContent = withScoreHint(taskStatusLabel(status, false, taskType), scoreNow);
              }
            } else if (status === 'active' && taskType === 'quiz') {
              const endDate = String(button.dataset.taskEndDate || '').trim();
              const endTime = String(button.dataset.taskEndTime || '').trim();
              const goldenCountdown = formatGoldenTimeCountdown(endDate, endTime);
              if (goldenCountdown) {
                metaEl.textContent = `${goldenCountdown}\nامتیاز ماموریت: ${scoreNow}`;
                metaEl.classList.add('is-multiline');
                button.classList.add('is-golden-live');
              } else {
                metaEl.textContent = withScoreHint(taskStatusLabel(status, false, taskType), scoreNow);
              }
            } else {
              metaEl.textContent = withScoreHint(taskStatusLabel(status, false, taskType), scoreNow);
            }
          }
        };

        const refreshTaskButtonsStatus = () => {
          const statusRows = taskButtons.map((button) => ({
            button,
            status: deriveTaskStatusFromButton(button)
          }));

          let closestUpcomingTaskId = '';
          let closestUpcomingTs = Number.POSITIVE_INFINITY;
          let firstUpcomingTaskId = '';
          statusRows.forEach(({ button, status }) => {
            if (status !== 'upcoming') return;
            const taskId = String(button.dataset.taskId || '').trim();
            if (firstUpcomingTaskId === '' && taskId !== '') {
              firstUpcomingTaskId = taskId;
            }
            const startDate = String(button.dataset.taskStartDate || '').trim();
            const startTime = normalizeUpcomingStartTime(button.dataset.taskStartTime);
            const target = getTehranTargetDate(startDate, startTime);
            if (!target || !taskId) return;
            const ts = target.getTime();
            if (Number.isFinite(ts) && ts < closestUpcomingTs) {
              closestUpcomingTs = ts;
              closestUpcomingTaskId = taskId;
            }
          });
          if (closestUpcomingTaskId === '' && firstUpcomingTaskId !== '') {
            closestUpcomingTaskId = firstUpcomingTaskId;
          }

          statusRows.forEach(({ button, status }) => {
            setTaskButtonState(button, status, closestUpcomingTaskId);
          });
        };

        const updateBottomCtaAttention = (status) => {
          if (!(bottomCtaBtnEl instanceof HTMLElement)) return;
          const shouldAnimate = status === 'active';
          bottomCtaBtnEl.classList.toggle('is-attention', shouldAnimate);
        };

        const applyEventGate = (status) => {
          if (rewardsViewOpen || rewardsCardsViewOpen) {
            if (timerAreaEl) timerAreaEl.classList.add('hidden');
            if (bottomCtaEl) bottomCtaEl.classList.add('hidden');
            if (rewardsViewEl && rewardsViewOpen && !rewardsCardsViewOpen) rewardsViewEl.classList.remove('hidden');
            if (rewardCardsViewEl && rewardsCardsViewOpen) rewardCardsViewEl.classList.remove('hidden');
          }
          if (eventNoticeEl) {
            eventNoticeEl.classList.add('hidden');
            eventNoticeEl.textContent = '';
          }
          if (!rewardsViewOpen && !rewardsCardsViewOpen) {
            if (tasksListEl) tasksListEl.classList.remove('hidden');
            if (tasksTitleEl) tasksTitleEl.classList.remove('hidden');
            if (bottomCtaEl) bottomCtaEl.classList.remove('hidden');
          }
          updateBottomCtaAttention(status);
          if (status === 'inactive') {
            closeQuizOverlay();
            if (tasksListEl) {
              tasksListEl.classList.add('hidden');
            }
            if (tasksTitleEl) {
              tasksTitleEl.classList.add('hidden');
            }
            if (bottomCtaEl) {
              bottomCtaEl.classList.add('hidden');
            }
            updateBottomCtaAttention('inactive');
            if (eventNoticeEl) {
              eventNoticeEl.textContent = 'متاسفیم، فعلا رویدادی در حال اجرا نیست.';
              eventNoticeEl.classList.remove('hidden');
            }
            return;
          }
          if (status === 'ended') {
            if (eventNoticeEl) {
              eventNoticeEl.textContent = '';
              eventNoticeEl.classList.add('hidden');
            }
          }
        };

        const clearQuizTimer = () => {
          if (quizTimerHandle) {
            clearInterval(quizTimerHandle);
            quizTimerHandle = null;
          }
        };

        const setQuizTimerProgress = (remainingMs) => {
          if (!quizTimerFillEl) return;
          if (!answerTimeLimitEnabled) {
            quizTimerFillEl.style.width = '100%';
            quizTimerFillEl.classList.remove('is-danger');
            return;
          }
          const clamped = Math.max(0, Math.min(QUIZ_TIME_LIMIT_MS, remainingMs));
          const ratio = clamped / QUIZ_TIME_LIMIT_MS;
          quizTimerFillEl.style.width = `${Math.round(ratio * 1000) / 10}%`;
          quizTimerFillEl.classList.toggle('is-danger', clamped <= 5000);
        };

        const openQuizOverlay = () => {
          if (timerAreaEl) {
            timerAreaEl.classList.add('quiz-hidden');
          }
          if (bottomCtaEl) {
            bottomCtaEl.classList.add('quiz-hidden');
          }
          if (quizAreaEl) {
            quizAreaEl.classList.remove('quiz-hidden');
          }
        };

        const closeQuizOverlay = () => {
          clearQuizTimer();
          if (quizAreaEl) {
            quizAreaEl.classList.add('quiz-hidden');
            quizAreaEl.classList.remove('quiz-area--percentage');
            Array.from(quizAreaEl.querySelectorAll('.quiz-percentage-submit-bottom[data-dynamic="1"]')).forEach((node) => node.remove());
          }
          if (timerAreaEl) {
            timerAreaEl.classList.remove('quiz-hidden');
          }
          if (bottomCtaEl) {
            bottomCtaEl.classList.remove('quiz-hidden');
          }
          if (quizAnswersEl) {
            quizAnswersEl.classList.remove('quiz-answers-grid--single');
            quizAnswersEl.innerHTML = '';
          }
          quizLocked = false;
          currentTaskId = '';
          currentTaskTitle = '';
          currentQuestions = [];
          currentQuestionIndex = 0;
        };

        const openTaskResultDialog = (scoreValue, messageText) => {
          if (resultValueEl) {
            resultValueEl.textContent = String(Math.max(0, Number.parseInt(scoreValue ?? 0, 10) || 0));
          }
          if (resultMessageEl) {
            resultMessageEl.textContent = String(messageText || '').trim() || 'عالی! نتیجه ماموریت ذخیره شد.';
          }
          if (resultDialogEl) {
            resultDialogEl.classList.add('open');
            resultDialogEl.setAttribute('aria-hidden', 'false');
          }
        };

        const closeTaskResultDialog = () => {
          if (resultDialogEl) {
            resultDialogEl.classList.remove('open');
            resultDialogEl.setAttribute('aria-hidden', 'true');
          }
        };

        const postJson = async (body) => {
          const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ...body, csrf: csrfToken })
          });
          let payload = null;
          try {
            payload = await response.json();
          } catch {
            payload = { status: 'error', message: 'پاسخ نامعتبر از سرور دریافت شد.' };
          }
          if (!response.ok || payload?.status !== 'ok') {
            throw new Error(payload?.message || 'درخواست ناموفق بود.');
          }
          return payload;
        };

        const formatRewardNumber = (value) => {
          const n = Number(value || 0);
          if (!Number.isFinite(n)) return '0';
          return new Intl.NumberFormat('fa-IR', { maximumFractionDigits: 2 }).format(n);
        };

        const formatToman = (value) => `${formatRewardNumber(value)} تومان`;

        const shuffleArray = (list) => {
          const arr = Array.isArray(list) ? list.slice() : [];
          for (let i = arr.length - 1; i > 0; i -= 1) {
            const j = Math.floor(Math.random() * (i + 1));
            const tmp = arr[i];
            arr[i] = arr[j];
            arr[j] = tmp;
          }
          return arr;
        };

        const escapeHtml = (value) => String(value ?? '')
          .replaceAll('&', '&amp;')
          .replaceAll('<', '&lt;')
          .replaceAll('>', '&gt;')
          .replaceAll('"', '&quot;')
          .replaceAll("'", '&#39;');

        const rewardCardLockStorageKey = `tc_reward_locked_cards_${String(sessionInfo?.workId || 'guest')}`;
        const rewardCardLockPrizeStorageKey = `tc_reward_locked_card_prizes_${String(sessionInfo?.workId || 'guest')}`;

        const saveLockedRewardCards = () => {
          try {
            const indexes = Array.from(rewardCardsLockedIndexes).filter((index) => Number.isInteger(index) && index >= 0 && index < 9);
            localStorage.setItem(rewardCardLockStorageKey, JSON.stringify(indexes));
            const prizeEntries = [];
            rewardCardsLockedPrizes.forEach((prizeName, index) => {
              if (!Number.isInteger(index) || index < 0 || index >= 9) return;
              prizeEntries.push({ index, prizeName: String(prizeName || '').trim() });
            });
            localStorage.setItem(rewardCardLockPrizeStorageKey, JSON.stringify(prizeEntries));
          } catch {}
        };

        const loadLockedRewardCards = () => {
          rewardCardsLockedIndexes.clear();
          rewardCardsLockedPrizes.clear();
          try {
            const raw = localStorage.getItem(rewardCardLockStorageKey);
            if (!raw) return;
            const indexes = JSON.parse(raw);
            if (!Array.isArray(indexes)) return;
            indexes.forEach((item) => {
              const parsed = Number(item);
              if (Number.isInteger(parsed) && parsed >= 0 && parsed < 9) {
                rewardCardsLockedIndexes.add(parsed);
              }
            });
          } catch {}
          try {
            const raw = localStorage.getItem(rewardCardLockPrizeStorageKey);
            if (!raw) return;
            const entries = JSON.parse(raw);
            if (!Array.isArray(entries)) return;
            entries.forEach((item) => {
              if (!item || typeof item !== 'object') return;
              const parsedIndex = Number(item.index);
              const prizeName = String(item.prizeName || '').trim();
              if (Number.isInteger(parsedIndex) && parsedIndex >= 0 && parsedIndex < 9 && prizeName !== '') {
                rewardCardsLockedPrizes.set(parsedIndex, prizeName);
              }
            });
          } catch {}
        };

        const setRewardStatusLine = (message, isError = false) => {
          if (!(rewardStatusLineEl instanceof HTMLElement)) return;
          rewardStatusLineEl.textContent = String(message || '').trim();
          rewardStatusLineEl.style.color = isError ? '#c0262d' : '';
        };

        const setTopbarMode = (mode) => {
          const isRewardsMode = mode === 'rewards';
          if (logoutBtn) logoutBtn.classList.toggle('hidden', isRewardsMode);
          if (topbarBackBtnEl) topbarBackBtnEl.classList.toggle('hidden', !isRewardsMode);
        };

        const setRewardTimeBox = (label, value) => {
          if (rewardTimeLabelEl) rewardTimeLabelEl.textContent = label;
          if (rewardTimeValueEl) rewardTimeValueEl.textContent = value;
        };

        const updateRewardsTotalBarPlacement = () => {
          if (!(rewardRoadmapEl instanceof HTMLElement) || !(rewardsTotalBarEl instanceof HTMLElement)) return;
          const isScrollable = rewardRoadmapEl.scrollHeight > (rewardRoadmapEl.clientHeight + 2);
          rewardsTotalBarEl.classList.toggle('is-sticky', isScrollable);
        };

        const buildRewardCards = () => {
          const names = Array.isArray(rewardsState?.availablePrizeNames) ? rewardsState.availablePrizeNames : [];
          const safeNames = names.length ? names : ['دوباره تلاش کن'];
          const cards = [];
          for (let i = 0; i < 9; i += 1) {
            cards.push({
              id: `tc_reward_card_${Date.now()}_${i}_${Math.random().toString(36).slice(2, 7)}`,
              label: String(safeNames[Math.floor(Math.random() * safeNames.length)] || 'جایزه ویژه')
            });
          }
          return shuffleArray(cards);
        };

        const getCardsHintState = () => {
          const levels = Array.isArray(rewardsState?.levels) ? rewardsState.levels : [];
          const valueSumLevels = levels.filter((level) => String(level?.type || '') === 'value_sum');
          if (!valueSumLevels.length) {
            return { message: 'کارتی برای انتخاب باقی نمانده!', disableUnchosen: true };
          }

          const userScore = Number(rewardsState?.score ?? 0);
          const wonCount = valueSumLevels.filter((level) => Boolean(level?.won)).length;
          const remainingCount = Math.max(0, valueSumLevels.length - wonCount);
          if (remainingCount <= 0) {
            return { message: 'کارتی برای انتخاب باقی نمانده!', disableUnchosen: true };
          }

          const flippableCount = valueSumLevels.filter((level) => Boolean(level?.canFlip) && !level?.won).length;
          if (flippableCount > 0) {
            return {
              message: `${formatRewardNumber(flippableCount)} شانس برای انتخاب کارت جدید`,
              disableUnchosen: false
            };
          }

          const nextLocked = valueSumLevels
            .filter((level) => !level?.won)
            .sort((a, b) => Number(a?.score || 0) - Number(b?.score || 0))[0] || null;
          if (nextLocked) {
            const needed = Math.max(0, Number(nextLocked?.score || 0) - userScore);
            return {
              message: `${formatRewardNumber(needed)} امتیاز دیگر برای انتخاب کارت`,
              disableUnchosen: true
            };
          }

          return { message: 'کارتی برای انتخاب باقی نمانده!', disableUnchosen: true };
        };

        const updateLockedCardButtonsState = () => {
          if (!(rewardCardsEl instanceof HTMLElement)) return;
          const hintState = getCardsHintState();
          if (rewardCardsHintEl instanceof HTMLElement) {
            rewardCardsHintEl.textContent = String(hintState.message || '').trim();
            rewardCardsHintEl.classList.toggle('hidden', !rewardCardsHintEl.textContent);
          }
          if (rewardCardsBoxEl instanceof HTMLElement) {
            rewardCardsBoxEl.classList.toggle('is-disabled', Boolean(hintState.disableUnchosen));
          }
          const buttons = Array.from(rewardCardsEl.querySelectorAll('.flip-card'));
          buttons.forEach((button) => {
            if (!(button instanceof HTMLButtonElement)) return;
            const index = Number(button.dataset.cardIndex);
            const isLocked = rewardCardsLockedIndexes.has(index);
            const blockedForNoChance = Boolean(hintState.disableUnchosen) && !isLocked;
            button.disabled = rewardsRoundBusy || isLocked || blockedForNoChance;
            button.classList.toggle('is-locked', isLocked);
            button.classList.toggle('is-revealed', isLocked);
            button.classList.toggle('is-picked', isLocked);
            const backLabelEl = button.querySelector('[data-back-label]');
            if (backLabelEl instanceof HTMLElement && isLocked) {
              const wonPrizeName = String(rewardCardsLockedPrizes.get(index) || '').trim();
              backLabelEl.innerHTML = `<span>${escapeHtml(wonPrizeName || 'جایزه برنده شده')}</span>`;
            }
          });
        };

        const getCurrentFlippableLevel = () => {
          const levels = Array.isArray(rewardsState?.levels) ? rewardsState.levels : [];
          const list = levels
            .filter((lvl) => lvl && lvl.canFlip && !lvl.won && String(lvl.type || '') === 'value_sum')
            .sort((a, b) => Number(a.score || 0) - Number(b.score || 0));
          return list[0] || null;
        };

        const renderRewardSummary = () => {
          if (!rewardsState) return;
          if (rewardScoreChipEl) rewardScoreChipEl.textContent = formatRewardNumber(rewardsState.score || 0);
          setRewardTimeBox('مجموع جوایز برنده شده', formatToman(rewardsState.totalPrizeWon || 0));
          if (rewardCardsTotalValueEl) {
            rewardCardsTotalValueEl.textContent = formatToman(rewardsState.totalPrizeWon || 0);
          }
        };

        const getWonPrizeNames = () => {
          const raw = String(rewardsState?.eachLevelWonPrize || '').trim();
          if (!raw) return [];
          const names = [];
          raw.split(',').forEach((chunk) => {
            const part = String(chunk || '').trim();
            if (!part) return;
            const separatorIndex = part.indexOf(':');
            if (separatorIndex <= 0) return;
            const prizeName = part.slice(separatorIndex + 1).trim();
            if (prizeName) names.push(prizeName);
          });
          return names;
        };

        const renderRewardRoadmap = () => {
          if (!(rewardRoadmapEl instanceof HTMLElement)) return;
          const levels = Array.isArray(rewardsState?.levels) ? rewardsState.levels : [];
          const score = Number(rewardsState?.score ?? 0);
          if (!levels.length) {
            rewardRoadmapEl.innerHTML = '<div class="roadmap-item"><span class="roadmap-node"></span><div class="roadmap-content">هنوز سطح جایزه‌ای تعریف نشده است.</div></div>';
            return;
          }
          rewardRoadmapEl.innerHTML = levels.map((level) => {
            const target = Number(level?.score ?? 0);
            const reached = score >= target;
            const left = Math.max(0, target - score);
            const isOutOfValue = String(level?.type || '') === 'out_of_value';
            const rowClasses = ['roadmap-item'];
            if (reached) rowClasses.push('reached');
            let stateClass = 'locked';
            let stateText = 'قفل';
            const levelId = String(level?.id || '');
            const levelName = String(level?.name || 'سطح جایزه');
            const wonPrize = String(level?.wonPrize || '').trim();
            const isClickable = Boolean(level?.reached) && String(level?.type || '') === 'value_sum';
            if (level?.won) {
              stateClass = 'won';
              stateText = wonPrize ? `برنده ${wonPrize}` : 'برنده شدی';
              rowClasses.push('won');
            } else if (level?.canFlip) {
              stateClass = 'can-flip';
              stateText = 'برای انتخاب کارت لمس کن';
              rowClasses.push('can-flip');
            } else if (level?.reached && isOutOfValue) {
              stateClass = 'won';
              stateText = 'رسیده‌اید';
              rowClasses.push('won');
            } else if (level?.reached) {
              stateClass = 'reached';
              stateText = 'رسیده‌اید';
            }
            const pointsNeedText = (level?.won || (isOutOfValue && reached))
              ? `امتیاز جمع‌آوری‌شده برای این سطح: ${formatRewardNumber(target)}`
              : reached
                ? 'امتیاز موردنیاز: ۰'
                : `امتیاز موردنیاز: ${formatRewardNumber(left)}`;
            return `<div class="${rowClasses.join(' ')}">
              <span class="roadmap-node" aria-hidden="true"></span>
              <button class="roadmap-level-btn" type="button" data-level-id="${levelId}" ${isClickable ? '' : 'disabled'}>
              <div class="roadmap-content">
                <div class="roadmap-level-name">${levelName}</div>
                <div class="roadmap-left">${pointsNeedText}</div>
                <div class="roadmap-state ${stateClass}">${stateText}</div>
              </div>
              </button>
            </div>`;
          }).join('');
        };

        const renderRewardCards = () => {
          if (!(rewardCardsEl instanceof HTMLElement)) return;
          if (!Array.isArray(rewardCardsDeck) || rewardCardsDeck.length !== 9) {
            rewardCardsDeck = buildRewardCards();
          }
          rewardCardsEl.innerHTML = rewardCardsDeck.map((card, index) => `
            <button class="flip-card" type="button" data-card-index="${index}">
              <div class="flip-card-inner">
                <div class="flip-face flip-front">
                  ${rewardCardLogoUrl ? `<img class="flip-front-logo" src="${escapeHtml(rewardCardLogoUrl)}" alt="لوگوی رویداد" />` : ''}
                  <span class="flip-front-label">برای انتخاب لمس کن</span>
                </div>
                <div class="flip-face flip-back" data-back-label><span>${escapeHtml(String(card.label || 'جایزه ویژه'))}</span></div>
              </div>
            </button>
          `).join('');
          updateLockedCardButtonsState();
        };

        const clearRewardEventTick = () => {
          if (rewardEventTickTimer) {
            clearInterval(rewardEventTickTimer);
            rewardEventTickTimer = null;
          }
        };

        const applyRewardEventState = async (eventStatus) => {
          clearRewardEventTick();
          const settings = await loadWheelSettings();
          const startDate = String(settings?.startDate ?? '').trim();
          const startTime = String(settings?.startTime ?? '').trim();
          const endDate = String(settings?.endDate ?? '').trim();
          const endTime = String(settings?.endTime ?? '').trim();

          const updateEventStateText = () => {
            if (eventStatus === 'upcoming') {
              const countdown = formatEventCountdown(startDate, startTime);
              setRewardTimeBox('زمان باقی‌مانده تا برد جوایز', countdown || 'به‌زودی شروع می‌شود');
              return;
            }

            if (eventStatus === 'active') {
              setRewardTimeBox('مجموع جوایز برنده شده', formatToman(rewardsState?.totalPrizeWon || 0));
              return;
            }

            if (eventStatus === 'ended') {
              const finalScore = formatRewardNumber(rewardsState?.score || 0);
              const finalWon = formatToman(rewardsState?.totalPrizeWon || 0);
              setRewardTimeBox('رویداد به اتمام رسیده', `امتیاز: ${finalScore} | مجموع جوایز: ${finalWon}`);
              return;
            }

            setRewardTimeBox('مجموع جوایز برنده شده', formatToman(rewardsState?.totalPrizeWon || 0));
          };

          updateEventStateText();
          if (eventStatus === 'upcoming' || eventStatus === 'active') {
            rewardEventTickTimer = setInterval(updateEventStateText, 1000);
          }
        };

        const refreshRewardState = async () => {
          try {
            const payload = await postJson({ action: 'reward_state' });
            rewardsState = payload?.data || null;
            const serverFlips = Math.max(0, Number(rewardsState?.cardFlipsCount || 0));
            if (rewardCardsLockedIndexes.size > serverFlips) {
              rewardCardsLockedIndexes.clear();
              rewardCardsLockedPrizes.clear();
              saveLockedRewardCards();
            }
            if (rewardCardsLockedIndexes.size && rewardCardsLockedPrizes.size < rewardCardsLockedIndexes.size) {
              const wonPrizeNames = getWonPrizeNames();
              const sortedLockedIndexes = Array.from(rewardCardsLockedIndexes).sort((a, b) => a - b);
              sortedLockedIndexes.forEach((index, idx) => {
                if (!rewardCardsLockedPrizes.has(index) && wonPrizeNames[idx]) {
                  rewardCardsLockedPrizes.set(index, wonPrizeNames[idx]);
                }
              });
              saveLockedRewardCards();
            }
            renderRewardSummary();
            renderRewardRoadmap();
            renderRewardCards();
            updateRewardsTotalBarPlacement();
            await applyRewardEventState(String(rewardsState?.eventStatus || globalEventStatus || 'inactive'));
            const level = getCurrentFlippableLevel();
            if (!level) {
              setRewardStatusLine('برای باز شدن کارت‌های بیشتر امتیاز جمع کنید.');
            } else {
              setRewardStatusLine(`اکنون می‌توانید جایزه سطح "${String(level.name || 'بدون نام')}" را دریافت کنید.`);
            }
          } catch (error) {
            setRewardStatusLine(error?.message || 'بارگذاری وضعیت جوایز ناموفق بود.', true);
          }
        };

        const runRewardWinConfetti = () => {
          if (!(rewardWinConfettiEl instanceof HTMLElement)) return;
          const colors = ['var(--tc-secondary)', 'var(--tc-highlight)'];
          const bounds = rewardWinConfettiEl.getBoundingClientRect();
          const width = Math.max(260, bounds.width || 360);
          const height = Math.max(220, bounds.height || 420);
          const count = Math.max(82, Math.min(148, Math.round(width / 4.3)));
          rewardWinConfettiEl.innerHTML = '';
          for (let i = 0; i < count; i += 1) {
            const piece = document.createElement('span');
            piece.className = 'confetti-piece';
            const fromLeft = Math.random() < 0.5;
            const sideOffset = Math.random() * 40 + 2;
            piece.style[fromLeft ? 'left' : 'right'] = `${sideOffset}px`;
            piece.style.top = `${Math.random() * (height * 0.8)}px`;
            piece.style.background = colors[i % colors.length];
            piece.style.animationDelay = `${Math.random() * 0.22}s`;
            const drift = fromLeft ? (Math.random() * 120 + 40) : -(Math.random() * 120 + 40);
            piece.style.setProperty('--drift', `${drift}px`);
            piece.style.transform = `translate3d(0, 0, 0) rotate(${Math.random() * 180}deg)`;
            rewardWinConfettiEl.appendChild(piece);
          }
          setTimeout(() => {
            rewardWinConfettiEl.innerHTML = '';
          }, 3400);
        };

        const openInfoDialog = (message, title = 'پیام') => new Promise((resolve) => {
          if (!(infoDialogEl instanceof HTMLElement) || !(infoDialogTitleEl instanceof HTMLElement) || !(infoDialogMessageEl instanceof HTMLElement) || !(infoDialogConfirmEl instanceof HTMLElement)) {
            resolve();
            return;
          }
          infoDialogTitleEl.textContent = String(title || 'پیام').trim() || 'پیام';
          infoDialogMessageEl.textContent = String(message || '').trim() || '—';
          infoDialogEl.classList.add('open');
          infoDialogEl.setAttribute('aria-hidden', 'false');

          const close = () => {
            infoDialogEl.classList.remove('open');
            infoDialogEl.setAttribute('aria-hidden', 'true');
            infoDialogConfirmEl.removeEventListener('click', onConfirm);
            infoDialogEl.removeEventListener('click', onOverlay);
            resolve();
          };
          const onConfirm = () => close();
          const onOverlay = (event) => {
            if (event.target === infoDialogEl) close();
          };
          infoDialogConfirmEl.addEventListener('click', onConfirm);
          infoDialogEl.addEventListener('click', onOverlay);
        });

        const openRewardWinDialog = (prizeName) => new Promise((resolve) => {
          if (!(rewardWinDialogEl instanceof HTMLElement) || !(rewardWinMessageEl instanceof HTMLElement) || !(rewardWinConfirmEl instanceof HTMLElement) || !(rewardWinValueEl instanceof HTMLElement)) {
            resolve();
            return;
          }
          const safePrize = String(prizeName || 'یک جایزه').trim() || 'یک جایزه';
          rewardWinValueEl.textContent = safePrize;
          rewardWinMessageEl.textContent = 'تبریک! جایزه شما ثبت شد.';
          runRewardWinConfetti();
          rewardWinDialogEl.classList.add('open');
          rewardWinDialogEl.setAttribute('aria-hidden', 'false');

          const close = () => {
            rewardWinDialogEl.classList.remove('open');
            rewardWinDialogEl.setAttribute('aria-hidden', 'true');
            rewardWinConfirmEl.removeEventListener('click', onConfirm);
            rewardWinDialogEl.removeEventListener('click', onOverlay);
            resolve();
          };

          const onConfirm = () => close();
          const onOverlay = (event) => {
            if (event.target === rewardWinDialogEl) close();
          };

          rewardWinConfirmEl.addEventListener('click', onConfirm);
          rewardWinDialogEl.addEventListener('click', onOverlay);
        });

        const animateRewardCardsRound = async (pickedButton, prizeName = '') => {
          const allButtons = Array.from((rewardCardsEl?.querySelectorAll('.flip-card')) || []).filter((button) => button instanceof HTMLButtonElement);
          if (!(pickedButton instanceof HTMLButtonElement) || !allButtons.length) return;
          const pickedIndex = Number(pickedButton.dataset.cardIndex);
          const names = Array.isArray(rewardsState?.availablePrizeNames) ? rewardsState.availablePrizeNames : [];
          const safeNames = names.length ? names : ['جایزه ویژه'];
          const candidateNames = safeNames.filter((name) => String(name || '').trim().toLowerCase() !== String(prizeName || '').trim().toLowerCase());

          allButtons.forEach((button) => {
            const buttonIndex = Number(button.dataset.cardIndex);
            const backEl = button.querySelector('[data-back-label]');
            if (!(backEl instanceof HTMLElement)) return;
            if (buttonIndex === pickedIndex) {
              backEl.innerHTML = `<span>${escapeHtml(prizeName || 'جایزه ویژه')}</span>`;
            } else {
              const preview = candidateNames.length
                ? candidateNames[Math.floor(Math.random() * candidateNames.length)]
                : safeNames[Math.floor(Math.random() * safeNames.length)];
              backEl.innerHTML = `<span>${escapeHtml(String(preview || 'جایزه ویژه'))}</span>`;
            }
          });

          pickedButton.classList.add('is-picked');
          const otherButtons = shuffleArray(
            allButtons.filter((button) => button !== pickedButton && !rewardCardsLockedIndexes.has(Number(button.dataset.cardIndex)))
          );
          const totalOthersRevealMs = 6000;
          const staggerMs = otherButtons.length ? Math.max(420, Math.round(totalOthersRevealMs / otherButtons.length)) : 0;
          for (let i = 0; i < otherButtons.length; i += 1) {
            otherButtons[i].classList.add('is-revealed');
            await new Promise((resolve) => setTimeout(resolve, staggerMs));
          }
          pickedButton.classList.add('is-revealed');
          await new Promise((resolve) => setTimeout(resolve, 2500));
        };

        const performRewardFlip = async (cardButton) => {
          if (rewardsRoundBusy) return;
          if (!(cardButton instanceof HTMLButtonElement)) return;
          const cardIndex = Number(cardButton.dataset.cardIndex);
          if (rewardCardsLockedIndexes.has(cardIndex)) {
            await openInfoDialog('این کارت قبلا انتخاب شده است. کارت دیگری را انتخاب کنید.');
            return;
          }
          const eventStatus = String(rewardsState?.eventStatus || globalEventStatus || 'inactive');
          if (eventStatus !== 'active') {
            await openInfoDialog('کارت‌ها فقط هنگام فعال بودن رویداد قابل انتخاب هستند.');
            return;
          }
          const level = getCurrentFlippableLevel();
          if (!level || !level.id || !level.canFlip) {
            await openInfoDialog('این سطح هنوز آماده نیست. امتیاز بیشتری جمع کنید.');
            return;
          }
          rewardsRoundBusy = true;
          updateLockedCardButtonsState();
          try {
            const payload = await postJson({ action: 'reward_flip', levelId: level.id });
            const data = payload?.data || {};
            const prizeName = String(data.prizeName || 'جایزه ویژه').trim();
            await animateRewardCardsRound(cardButton, prizeName);
            rewardCardsLockedIndexes.add(cardIndex);
            rewardCardsLockedPrizes.set(cardIndex, prizeName);
            saveLockedRewardCards();
            await openRewardWinDialog(prizeName);
            const allButtons = Array.from((rewardCardsEl?.querySelectorAll('.flip-card')) || []);
            allButtons.forEach((button) => {
              if (!(button instanceof HTMLButtonElement)) return;
              const index = Number(button.dataset.cardIndex);
              if (index !== cardIndex && !rewardCardsLockedIndexes.has(index)) {
                button.classList.remove('is-revealed');
                button.classList.remove('is-picked');
              }
            });
            rewardCardsDeck = buildRewardCards();
            renderRewardCards();
            await refreshRewardState();
          } catch (error) {
            await openInfoDialog(error?.message || 'باز کردن کارت ناموفق بود.');
          } finally {
            rewardsRoundBusy = false;
            updateLockedCardButtonsState();
          }
        };

        const openRewardCardsSlide = async (levelId) => {
          if (!(rewardCardsViewEl instanceof HTMLElement)) return;
          const eventStatus = String(rewardsState?.eventStatus || globalEventStatus || 'inactive');
          const levels = Array.isArray(rewardsState?.levels) ? rewardsState.levels : [];
          const level = levels.find((lvl) => String(lvl?.id || '') === String(levelId || '')) || null;
          if (!level || String(level.type || '') !== 'value_sum') {
            await openInfoDialog('این سطح کارت ندارد و فقط امتیازی است.');
            return;
          }
          if (eventStatus === 'upcoming') {
            const settings = await loadWheelSettings();
            const startDate = String(settings?.startDate ?? '').trim();
            const startTime = String(settings?.startTime ?? '').trim();
            const countdown = formatEventCountdown(startDate, startTime);
            await openInfoDialog(`اول امتیاز جمع کنید، سپس در ${countdown || 'بازه شروع رویداد'} می‌توانید جایزه ببرید.`);
            return;
          }
          if (eventStatus !== 'active') {
            await openInfoDialog('کارت‌ها فقط در حالت فعال رویداد باز می‌شوند.');
            return;
          }
          if (!level.reached) {
            await openInfoDialog('هنوز به این سطح نرسیده‌اید.');
            return;
          }
          selectedRewardLevelId = String(level.id || '');
          rewardsCardsViewOpen = true;
          if (rewardsViewEl) rewardsViewEl.classList.add('hidden');
          rewardCardsViewEl.classList.remove('hidden');
          rewardCardsViewEl.setAttribute('aria-hidden', 'false');
          updateLockedCardButtonsState();
          setTopbarMode('rewards');
        };

        const closeRewardCardsSlide = () => {
          rewardsCardsViewOpen = false;
          selectedRewardLevelId = '';
          if (rewardCardsViewEl) {
            rewardCardsViewEl.classList.add('hidden');
            rewardCardsViewEl.setAttribute('aria-hidden', 'true');
          }
          if (rewardsViewEl) rewardsViewEl.classList.remove('hidden');
        };

        const openRewardsView = async () => {
          rewardsViewOpen = true;
          rewardsCardsViewOpen = false;
          if (timerAreaEl) timerAreaEl.classList.add('hidden');
          if (bottomCtaEl) bottomCtaEl.classList.add('hidden');
          if (quizAreaEl) quizAreaEl.classList.add('hidden');
          if (rewardsViewEl) {
            rewardsViewEl.classList.remove('hidden');
            rewardsViewEl.setAttribute('aria-hidden', 'false');
          }
          if (rewardCardsViewEl) {
            rewardCardsViewEl.classList.add('hidden');
            rewardCardsViewEl.setAttribute('aria-hidden', 'true');
          }
          setTopbarMode('rewards');
          await refreshRewardState();
        };

        const closeRewardsView = () => {
          rewardsViewOpen = false;
          rewardsCardsViewOpen = false;
          clearRewardEventTick();
          if (rewardsViewEl) {
            rewardsViewEl.classList.add('hidden');
            rewardsViewEl.setAttribute('aria-hidden', 'true');
          }
          if (rewardCardsViewEl) {
            rewardCardsViewEl.classList.add('hidden');
            rewardCardsViewEl.setAttribute('aria-hidden', 'true');
          }
          if (timerAreaEl) timerAreaEl.classList.remove('hidden');
          if (bottomCtaEl) bottomCtaEl.classList.remove('hidden');
          setTopbarMode('tasks');
        };

        const sendTaskAnswer = async (item, answerText) => {
          if (!currentTaskId) return;
          const questionCode = String(item?.code ?? '').trim();
          const questionText = String(item?.question ?? '').trim();
          if (!questionCode || !questionText) return;
          try {
            await postJson({
              action: 'task_log_answer',
              taskId: currentTaskId,
              questionCode,
              question: questionText,
              answer: String(answerText ?? '').trim()
            });
          } catch {}
        };

        const completeCurrentTask = async (item, answerText = '') => {
          const completedTaskId = currentTaskId;
          let payload;
          try {
            payload = await postJson({
              action: 'task_complete',
              taskId: completedTaskId,
              questionCode: String(item?.code ?? '').trim(),
              question: String(item?.question ?? '').trim(),
              answer: String(answerText ?? '').trim()
            });
          } catch (error) {
            closeQuizOverlay();
            openTaskResultDialog(0, error?.message || 'ذخیره امتیاز این ماموریت ناموفق بود.');
            return;
          }

          const totalScore = Number.parseInt(payload?.totalScore ?? 0, 10);
          if (userScoreEl && Number.isFinite(totalScore)) {
            userScoreEl.textContent = String(Math.max(0, totalScore));
          }

          const targetButton = taskButtons.find((button) => String(button.dataset.taskId || '') === completedTaskId);
          if (targetButton) {
            const userTaskScore = Number.parseInt(payload?.userTaskScore ?? payload?.awardedScore ?? 0, 10);
            targetButton.dataset.taskCompleted = '1';
            targetButton.dataset.taskUserScore = String(Number.isFinite(userTaskScore) ? Math.max(0, userTaskScore) : 0);
            setTaskButtonState(targetButton, 'completed');
          }

          closeQuizOverlay();
          if (payload?.alreadyCompleted) {
            openTaskResultDialog(payload?.userTaskScore ?? 0, 'این ماموریت قبلا انجام شده و امتیاز گرفته است.');
            return;
          }
          openTaskResultDialog(payload?.awardedScore ?? 0, 'عالی! ماموریت کامل شد و امتیاز ثبت شد.');
        };

        const continueQuiz = () => {
          clearQuizTimer();
          currentQuestionIndex += 1;
          quizLocked = false;
          if (currentQuestionIndex >= currentQuestions.length) {
            closeQuizOverlay();
            openTaskResultDialog(0, 'این مرحله بدون پاسخ صحیح تمام شد. دوباره تلاش کنید.');
            return;
          }
          renderQuizQuestion();
        };

        const handleQuizTimeout = async () => {
          if (quizLocked) return;
          quizLocked = true;
          const item = currentQuestions[currentQuestionIndex] || null;
          await sendTaskAnswer(item, '');
          setTimeout(() => {
            continueQuiz();
          }, 500);
        };

        const startQuizTimer = () => {
          clearQuizTimer();
          if (!answerTimeLimitEnabled) {
            setQuizTimerProgress(QUIZ_TIME_LIMIT_MS);
            return;
          }
          setQuizTimerProgress(QUIZ_TIME_LIMIT_MS);
          const startedAt = Date.now();
          quizTimerHandle = setInterval(() => {
            const elapsed = Date.now() - startedAt;
            const remaining = QUIZ_TIME_LIMIT_MS - elapsed;
            setQuizTimerProgress(remaining);
            if (remaining <= 0) {
              clearQuizTimer();
              void handleQuizTimeout();
            }
          }, 100);
        };

        const handleChoiceAnswer = async (button, item, isCorrect, answerText) => {
          if (quizLocked) return;
          quizLocked = true;
          clearQuizTimer();
          Array.from(quizAnswersEl?.querySelectorAll('button') || []).forEach((node) => {
            if (node instanceof HTMLButtonElement) {
              node.disabled = true;
            }
          });

          await sendTaskAnswer(item, answerText);

          if (isCorrect) {
            if (button instanceof HTMLButtonElement) {
              button.classList.add('is-correct');
            }
            setTimeout(() => {
              void completeCurrentTask(item, answerText);
            }, 420);
            return;
          }

          if (button instanceof HTMLButtonElement) {
            button.classList.add('is-wrong');
          }
          const correctButton = quizAnswersEl
            ? quizAnswersEl.querySelector('.quiz-answer-btn[data-correct="1"]')
            : null;
          if (correctButton instanceof HTMLButtonElement) {
            correctButton.classList.add('is-correct-reveal');
          }
          setTimeout(() => {
            continueQuiz();
          }, 700);
        };

        const handlePercentageAnswer = async (submitButton, slider, item) => {
          if (quizLocked) return;
          if (!(slider instanceof HTMLInputElement)) return;
          quizLocked = true;
          clearQuizTimer();
          const value = Math.max(0, Math.min(100, Number.parseInt(slider.value || '0', 10)));
          await sendTaskAnswer(item, String(value));
          if (submitButton instanceof HTMLButtonElement) {
            submitButton.classList.add('is-correct');
            submitButton.disabled = true;
          }
          setTimeout(() => {
            void completeCurrentTask(item, String(value));
          }, 420);
        };

        const renderQuizQuestion = () => {
          if (!quizCounterEl || !quizQuestionEl || !quizAnswersEl) {
            return;
          }

          if (quizTitleEl) {
            quizTitleEl.textContent = currentTaskTitle || 'ماموریت کوییز';
          }

          const total = currentQuestions.length;
          const item = currentQuestions[currentQuestionIndex] || null;
          if (!item) {
            closeQuizOverlay();
            return;
          }

          quizCounterEl.textContent = `${currentQuestionIndex + 1} / ${total}`;
          quizQuestionEl.textContent = String(item.question || '').trim() || '-';
          quizAnswersEl.innerHTML = '';
          quizAnswersEl.classList.toggle('quiz-answers-grid--single', item.type === 'percentage');
          if (quizAreaEl) {
            Array.from(quizAreaEl.querySelectorAll('.quiz-percentage-submit-bottom[data-dynamic="1"]')).forEach((node) => node.remove());
            quizAreaEl.classList.toggle('quiz-area--percentage', item.type === 'percentage');
          }

          if (item.type === 'percentage') {
            const wrap = document.createElement('div');
            wrap.className = 'quiz-percentage-wrap';
            const valueLabel = document.createElement('div');
            valueLabel.className = 'quiz-percentage-value';
            valueLabel.textContent = '50%';
            const slider = document.createElement('input');
            slider.type = 'range';
            slider.min = '0';
            slider.max = '100';
            slider.step = '1';
            slider.value = '50';
            slider.className = 'quiz-percentage-slider';
            slider.style.setProperty('--range-progress', '50%');
            slider.addEventListener('input', () => {
              const val = Math.max(0, Math.min(100, Number.parseInt(slider.value || '0', 10)));
              valueLabel.textContent = `${val}%`;
              slider.style.setProperty('--range-progress', `${val}%`);
            });
            const submit = document.createElement('button');
            submit.type = 'button';
            submit.className = 'quiz-answer-btn quiz-percentage-submit quiz-percentage-submit-bottom';
            submit.dataset.dynamic = '1';
            submit.textContent = 'ثبت پاسخ';
            submit.addEventListener('click', () => {
              void handlePercentageAnswer(submit, slider, item);
            });
            wrap.appendChild(valueLabel);
            wrap.appendChild(slider);
            quizAnswersEl.appendChild(wrap);
            if (quizAreaEl) {
              quizAreaEl.appendChild(submit);
            } else {
              quizAnswersEl.appendChild(submit);
            }
            startQuizTimer();
            return;
          }

          const answers = Array.isArray(item.answers)
            ? item.answers.slice(0, 4).map((answer, index) => ({ text: String(answer ?? '').trim(), isCorrect: index === 0 }))
            : [];
          const validAnswers = answers.filter((answer) => answer.text !== '');
          for (let i = validAnswers.length - 1; i > 0; i -= 1) {
            const j = Math.floor(Math.random() * (i + 1));
            const tmp = validAnswers[i];
            validAnswers[i] = validAnswers[j];
            validAnswers[j] = tmp;
          }

          validAnswers.forEach((answerItem) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'quiz-answer-btn';
            button.textContent = answerItem.text;
            button.dataset.correct = answerItem.isCorrect ? '1' : '0';
            button.addEventListener('click', () => {
              void handleChoiceAnswer(button, item, answerItem.isCorrect, answerItem.text);
            });
            quizAnswersEl.appendChild(button);
          });

          startQuizTimer();
        };

        const normalizeQuestions = (list) => {
          if (!Array.isArray(list)) return [];
          return list
            .map((item) => ({
              code: String(item?.code ?? '').trim(),
              type: String(item?.type ?? 'mcq').toLowerCase() === 'percentage' ? 'percentage' : 'mcq',
              question: String(item?.question ?? '').trim(),
              answers: Array.isArray(item?.answers) ? item.answers.slice(0, 4).map((ans) => String(ans ?? '').trim()) : []
            }))
            .filter((item) => {
              if (!item.code || !item.question) return false;
              if (item.type === 'percentage') return true;
              return item.answers.length >= 4 && item.answers.slice(0, 4).every((ans) => ans !== '');
            });
        };

        const startTaskQuiz = async (button) => {
          const taskId = String(button?.dataset?.taskId || '').trim();
          if (!taskId) return;
          if (globalEventStatus === 'inactive') {
            openTaskResultDialog(0, 'فعلا رویداد فعالی وجود ندارد.');
            return;
          }

          try {
            const payload = await postJson({ action: 'task_fetch', taskId });
            const progress = payload?.progress || {};
            if (progress?.completed) {
              button.dataset.taskCompleted = '1';
              button.dataset.taskUserScore = String(Number.parseInt(progress?.score ?? 0, 10) || 0);
              setTaskButtonState(button, 'completed');
              openTaskResultDialog(progress?.score ?? 0, 'این ماموریت قبلا انجام شده و امتیاز گرفته است.');
              return;
            }

            if (!payload?.task?.available) {
              setTaskButtonState(button, String(payload?.task?.status || 'inactive'));
              openTaskResultDialog(0, 'این ماموریت در حال حاضر فعال نیست.');
              return;
            }

            const normalizedQuestions = normalizeQuestions(payload?.questions || []);
            if (!normalizedQuestions.length) {
              openTaskResultDialog(0, 'برای این ماموریت سوالی تنظیم نشده است.');
              return;
            }

            const randomOrder = Boolean(payload?.settings?.randomOrder ?? true);
            const nextQuestions = normalizedQuestions.slice();
            if (randomOrder) {
              for (let i = nextQuestions.length - 1; i > 0; i -= 1) {
                const j = Math.floor(Math.random() * (i + 1));
                const tmp = nextQuestions[i];
                nextQuestions[i] = nextQuestions[j];
                nextQuestions[j] = tmp;
              }
            }

            answerTimeLimitEnabled = Boolean(payload?.settings?.answerTimeLimit ?? true);
            currentTaskId = taskId;
            currentTaskTitle = String(payload?.task?.title ?? button?.dataset?.taskTitle ?? 'ماموریت کوییز').trim();
            currentQuestions = nextQuestions;
            currentQuestionIndex = 0;
            quizLocked = false;
            closeTaskResultDialog();
            openQuizOverlay();
            renderQuizQuestion();
          } catch (error) {
            openTaskResultDialog(0, error?.message || 'دریافت سوالات ماموریت ناموفق بود.');
          }
        };

        if (resultDialogEl) {
          resultDialogEl.addEventListener('click', (event) => {
            if (event.target === resultDialogEl) {
              closeTaskResultDialog();
            }
          });
        }
        if (resultConfirmBtn) {
          resultConfirmBtn.addEventListener('click', () => {
            closeTaskResultDialog();
          });
        }

        if (openRewardsBtnEl) {
          openRewardsBtnEl.addEventListener('click', () => {
            void openRewardsView();
          });
        }

        if (topbarBackBtnEl) {
          topbarBackBtnEl.addEventListener('click', () => {
            if (rewardsCardsViewOpen) {
              closeRewardCardsSlide();
              return;
            }
            if (rewardsViewOpen) {
              closeRewardsView();
            }
          });
        }

        if (rewardRoadmapEl) {
          rewardRoadmapEl.addEventListener('scroll', () => {
            updateRewardsTotalBarPlacement();
          });
          rewardRoadmapEl.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof Element)) return;
            const button = target.closest('.roadmap-level-btn');
            if (!(button instanceof HTMLButtonElement) || button.disabled) return;
            const levelId = String(button.dataset.levelId || '').trim();
            if (!levelId) return;
            void openRewardCardsSlide(levelId);
          });
        }

        if (rewardCardsEl) {
          rewardCardsEl.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof Element)) return;
            const button = target.closest('.flip-card');
            if (!(button instanceof HTMLButtonElement)) return;
            void performRewardFlip(button);
          });
        }

        taskButtons.forEach((button) => {
          button.addEventListener('click', () => {
            if (button.disabled) {
              return;
            }
            void startTaskQuiz(button);
          });
        });

        window.addEventListener('storage', (event) => {
          if (event.key === 'tcSettingsUpdated') {
            refreshStatus();
          }
        });

        window.addEventListener('resize', () => {
          updateRewardsTotalBarPlacement();
        });

        const scheduleHourlyStatusCheck = () => {
          const now = new Date();
          const nextHour = new Date(now);
          nextHour.setMinutes(0, 0, 0);
          nextHour.setHours(now.getHours() + 1);
          const delay = nextHour.getTime() - now.getTime();
          setTimeout(() => {
            refreshStatus();
            setInterval(refreshStatus, 60 * 60 * 1000);
          }, delay);
        };

        setTopbarMode('tasks');
        loadLockedRewardCards();
        refreshStatus();
        refreshTaskButtonsStatus();
        scheduleHourlyStatusCheck();
        taskStatusTimer = setInterval(refreshStatus, 30 * 1000);
        taskCountdownTickTimer = setInterval(refreshTaskButtonsStatus, 1000);
      }
    </script>
  </body>
</html>







