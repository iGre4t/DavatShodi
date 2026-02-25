<?php
session_start();
$cspNonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$cspNonce}'; style-src 'self' 'nonce-{$cspNonce}'; img-src 'self' data: https: http:; font-src 'self' data:; connect-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: same-origin");
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
if (empty($_SESSION['tc_csrf'])) {
  $_SESSION['tc_csrf'] = bin2hex(random_bytes(16));
}
if (isset($_GET['force_logout']) && (string)$_GET['force_logout'] === '1') {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', (bool)($params['secure'] ?? false), (bool)($params['httponly'] ?? true));
  }
  session_destroy();
  $redirectUrl = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?');
  if (!is_string($redirectUrl) || $redirectUrl === '') {
    $redirectUrl = basename(__FILE__);
  }
  header('Location: ' . $redirectUrl);
  exit;
}

$tcMaintenanceSettingsPath = __DIR__ . '/Setting.json';
$tcMaintenanceEnabled = false;
if (is_file($tcMaintenanceSettingsPath)) {
  $tcMaintenanceRaw = file_get_contents($tcMaintenanceSettingsPath);
  $tcMaintenanceDecoded = is_string($tcMaintenanceRaw) ? json_decode($tcMaintenanceRaw, true) : null;
  if (is_array($tcMaintenanceDecoded)) {
    $tcMaintenanceEnabled = (bool)($tcMaintenanceDecoded['maintenanceMode'] ?? false);
  }
}
$tcPanelSessionBypass = !empty($_SESSION['authenticated']) && is_array($_SESSION['user'] ?? null);
if ($tcMaintenanceEnabled && !$tcPanelSessionBypass) {
  $maintenanceUrl = 'TCM-maintenance.php';
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
      'status' => 'maintenance',
      'message' => 'سامانه در حال تعمیر است.',
      'redirect' => $maintenanceUrl
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }
  header('Location: ' . $maintenanceUrl);
  exit;
}

const SETTINGS_STORE_PATH = __DIR__ . '/../../data/store.json';
const DEFAULT_PANEL_SETTINGS = [
  'siteIcon' => ''
];
const TASKS_DIR_PATH = __DIR__ . '/tasks';
const TASKS_JS_STORE_PATH = TASKS_DIR_PATH . '/tasks.js';
const TASK_SCORE_SETTINGS_FILE = 'task-score.json';
const TASK_INFO_SETTINGS_FILE = 'info-task.json';
const TASK_TEAM_SETTINGS_FILE = 'team-settings.json';
const TASK_TEAM_CHALLENGES_FILE = 'team-challenges.json';
const TASK_TEAM_RUNTIME_FILE = 'team-runtime.json';
const TASK_DESCRIBE_PHOTO_DIR = 'photos';
const TASK_DESCRIBE_PHOTO_META_FILE = 'photos.json';
const TASK_DESCRIBE_PHOTO_ARTICLES_DIR = 'articles';

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
  if ($token === 'info' || $token === 'info-task' || $token === 'info task') {
    return 'info';
  }
  if ($token === 'team_task' || $token === 'team-task' || $token === 'team task') {
    return 'team_task';
  }
  if ($token === 'describe_photo' || $token === 'describe-photo' || $token === 'describe photo' || $token === 'describe-photo-task' || $token === 'describe photo task') {
    return 'describe_photo';
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

function readTaskInfoSettings(string $tasksDir, string $tagCode): array
{
  $defaults = [
    'title' => '',
    'text' => '',
    'guidePrefix' => '',
    'guideSuffix' => ''
  ];
  $normalizedTag = normalizeTaskTagCode($tagCode);
  if ($normalizedTag === '') {
    return $defaults;
  }
  $path = $tasksDir . DIRECTORY_SEPARATOR . $normalizedTag . DIRECTORY_SEPARATOR . TASK_INFO_SETTINGS_FILE;
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
    'title' => trim((string)($decoded['title'] ?? '')),
    'text' => trim((string)($decoded['text'] ?? '')),
    'guidePrefix' => trim((string)($decoded['guidePrefix'] ?? ($decoded['guide_prefix'] ?? ''))),
    'guideSuffix' => trim((string)($decoded['guideSuffix'] ?? ($decoded['guide_suffix'] ?? '')))
  ];
}

function readTaskTeamSettings(string $tasksDir, string $tagCode): array
{
  $defaults = [
    'teamMin' => 1,
    'teamMax' => 1,
    'teamAdditionalNote' => ''
  ];
  $normalizedTag = normalizeTaskTagCode($tagCode);
  if ($normalizedTag === '') {
    return $defaults;
  }
  $path = $tasksDir . DIRECTORY_SEPARATOR . $normalizedTag . DIRECTORY_SEPARATOR . TASK_TEAM_SETTINGS_FILE;
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
  $teamMin = normalizeTaskScoreValue($decoded['teamMin'] ?? ($decoded['team_min'] ?? 1));
  $teamMax = normalizeTaskScoreValue($decoded['teamMax'] ?? ($decoded['team_max'] ?? 1));
  $teamAdditionalNote = trim((string)($decoded['teamAdditionalNote'] ?? ($decoded['team_additional_note'] ?? '')));
  if ($teamMin < 1) {
    $teamMin = 1;
  }
  if ($teamMax < $teamMin) {
    $teamMax = $teamMin;
  }
  return [
    'teamMin' => $teamMin,
    'teamMax' => $teamMax,
    'teamAdditionalNote' => $teamAdditionalNote
  ];
}

function readTaskTeamChallenges(string $tasksDir, string $tagCode): array
{
  $normalizedTag = normalizeTaskTagCode($tagCode);
  if ($normalizedTag === '') {
    return [];
  }
  $path = $tasksDir . DIRECTORY_SEPARATOR . $normalizedTag . DIRECTORY_SEPARATOR . TASK_TEAM_CHALLENGES_FILE;
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

  $result = [];
  $seen = [];
  foreach ($decoded as $item) {
    if (!is_array($item)) {
      continue;
    }
    $challengeId = trim((string)($item['id'] ?? ''));
    if ($challengeId === '' || isset($seen[$challengeId])) {
      continue;
    }
    $seen[$challengeId] = true;
    $name = trim((string)($item['name'] ?? ''));
    if ($name === '') {
      $name = 'Challenge';
    }
    $guide = trim((string)($item['guide'] ?? ($item['challengeGuide'] ?? ($item['challenge_guide'] ?? ''))));
    $quantity = max(0, normalizeTaskScoreValue($item['quantity'] ?? 0));
    $last = max(0, normalizeTaskScoreValue($item['last'] ?? $quantity));
    if ($quantity === 0 && $last > 0) {
      $quantity = $last;
    }
    if ($last > $quantity) {
      $last = $quantity;
    }
    $result[] = [
      'id' => $challengeId,
      'name' => $name,
      'guide' => $guide,
      'quantity' => $quantity,
      'last' => $last,
      'createdAt' => trim((string)($item['createdAt'] ?? ($item['created_at'] ?? '')))
    ];
  }
  return $result;
}

function saveTaskTeamChallenges(string $tasksDir, string $tagCode, array $challenges): bool
{
  $normalizedTag = normalizeTaskTagCode($tagCode);
  if ($normalizedTag === '') {
    return false;
  }
  $taskDir = $tasksDir . DIRECTORY_SEPARATOR . $normalizedTag;
  if (!is_dir($taskDir) && !(mkdir($taskDir, 0777, true) || is_dir($taskDir))) {
    return false;
  }
  $path = $taskDir . DIRECTORY_SEPARATOR . TASK_TEAM_CHALLENGES_FILE;
  $safe = [];
  $seen = [];
  foreach ($challenges as $item) {
    if (!is_array($item)) {
      continue;
    }
    $challengeId = trim((string)($item['id'] ?? ''));
    if ($challengeId === '' || isset($seen[$challengeId])) {
      continue;
    }
    $seen[$challengeId] = true;
    $name = trim((string)($item['name'] ?? ''));
    if ($name === '') {
      $name = 'Challenge';
    }
    $guide = trim((string)($item['guide'] ?? ''));
    $quantity = max(0, normalizeTaskScoreValue($item['quantity'] ?? 0));
    $last = max(0, normalizeTaskScoreValue($item['last'] ?? $quantity));
    if ($quantity === 0 && $last > 0) {
      $quantity = $last;
    }
    if ($last > $quantity) {
      $last = $quantity;
    }
    $safe[] = [
      'id' => $challengeId,
      'name' => $name,
      'guide' => $guide,
      'quantity' => $quantity,
      'last' => $last,
      'createdAt' => trim((string)($item['createdAt'] ?? ($item['created_at'] ?? date('Y-m-d H:i:s'))))
    ];
  }
  $json = json_encode($safe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($json === false) {
    return false;
  }
  return file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

function readTaskTeamRuntime(string $tasksDir, string $tagCode): array
{
  $normalizedTag = normalizeTaskTagCode($tagCode);
  if ($normalizedTag === '') {
    return ['teams' => []];
  }
  $path = $tasksDir . DIRECTORY_SEPARATOR . $normalizedTag . DIRECTORY_SEPARATOR . TASK_TEAM_RUNTIME_FILE;
  if (!is_file($path)) {
    return ['teams' => []];
  }
  $content = file_get_contents($path);
  if ($content === false) {
    return ['teams' => []];
  }
  $decoded = json_decode($content, true);
  if (!is_array($decoded)) {
    return ['teams' => []];
  }
  $teams = is_array($decoded['teams'] ?? null) ? $decoded['teams'] : [];
  $safeTeams = [];
  $seenIds = [];
  foreach ($teams as $team) {
    if (!is_array($team)) {
      continue;
    }
    $teamId = trim((string)($team['id'] ?? ''));
    $teamName = trim((string)($team['name'] ?? ''));
    $leaderWorkId = trim((string)($team['leaderWorkId'] ?? ($team['leader_work_id'] ?? '')));
    if ($teamId === '' || $teamName === '' || $leaderWorkId === '' || isset($seenIds[$teamId])) {
      continue;
    }
    $seenIds[$teamId] = true;
    $joinType = normalizeTeamJoinType((string)($team['joinType'] ?? ($team['join_type'] ?? 'private')));
    $members = [];
    foreach ((array)($team['members'] ?? []) as $memberWorkId) {
      $member = trim((string)$memberWorkId);
      if ($member !== '' && !in_array($member, $members, true)) {
        $members[] = $member;
      }
    }
    if (!in_array($leaderWorkId, $members, true)) {
      array_unshift($members, $leaderWorkId);
    }
    $invites = [];
    foreach ((array)($team['invites'] ?? []) as $inviteWorkId) {
      $invite = trim((string)$inviteWorkId);
      if ($invite !== '' && !in_array($invite, $invites, true) && !in_array($invite, $members, true)) {
        $invites[] = $invite;
      }
    }
    $requests = [];
    foreach ((array)($team['requests'] ?? []) as $requestWorkId) {
      $request = trim((string)$requestWorkId);
      if ($request !== '' && !in_array($request, $requests, true) && !in_array($request, $members, true)) {
        $requests[] = $request;
      }
    }
    $safeTeams[] = [
      'id' => $teamId,
      'name' => $teamName,
      'leaderWorkId' => $leaderWorkId,
      'joinType' => $joinType,
      'members' => array_values($members),
      'invites' => array_values($invites),
      'requests' => array_values($requests),
      'renameCount' => max(0, min(3, (int)($team['renameCount'] ?? ($team['rename_count'] ?? 0)))),
      'started' => (bool)($team['started'] ?? false),
      'startedAt' => trim((string)($team['startedAt'] ?? ($team['started_at'] ?? ''))),
      'challengeId' => trim((string)($team['challengeId'] ?? ($team['challenge_id'] ?? ''))),
      'challengeName' => trim((string)($team['challengeName'] ?? ($team['challenge_name'] ?? ''))),
      'challengeGuide' => trim((string)($team['challengeGuide'] ?? ($team['challenge_guide'] ?? ''))),
      'createdAt' => trim((string)($team['createdAt'] ?? ($team['created_at'] ?? '')))
    ];
  }
  return ['teams' => $safeTeams];
}

function saveTaskTeamRuntime(string $tasksDir, string $tagCode, array $runtime): bool
{
  $normalizedTag = normalizeTaskTagCode($tagCode);
  if ($normalizedTag === '') {
    return false;
  }
  $taskDir = $tasksDir . DIRECTORY_SEPARATOR . $normalizedTag;
  if (!is_dir($taskDir) && !(mkdir($taskDir, 0777, true) || is_dir($taskDir))) {
    return false;
  }
  $path = $taskDir . DIRECTORY_SEPARATOR . TASK_TEAM_RUNTIME_FILE;
  $teams = is_array($runtime['teams'] ?? null) ? $runtime['teams'] : [];
  $json = json_encode(['teams' => array_values($teams)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($json === false) {
    return false;
  }
  return file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

function normalizeTeamJoinType(string $value): string
{
  $token = strtolower(trim($value));
  if ($token === 'public_open' || $token === 'public-open' || $token === 'open' || $token === 'free') {
    return 'public_open';
  }
  if ($token === 'public_request' || $token === 'public-request' || $token === 'request') {
    return 'public_request';
  }
  return 'private';
}

function makeTeamId(): string
{
  try {
    return 'ttm_' . bin2hex(random_bytes(6));
  } catch (Throwable $e) {
    return 'ttm_' . str_replace('.', '', uniqid('', true));
  }
}

function findTaskTeamIndexById(array $teams, string $teamId): int
{
  $target = trim($teamId);
  if ($target === '') {
    return -1;
  }
  foreach ($teams as $index => $team) {
    if (!is_array($team)) {
      continue;
    }
    if (trim((string)($team['id'] ?? '')) === $target) {
      return (int)$index;
    }
  }
  return -1;
}

function findTaskTeamIndexByMember(array $teams, string $workId): int
{
  $target = trim($workId);
  if ($target === '') {
    return -1;
  }
  foreach ($teams as $index => $team) {
    if (!is_array($team)) {
      continue;
    }
    $members = is_array($team['members'] ?? null) ? $team['members'] : [];
    if (in_array($target, $members, true)) {
      return (int)$index;
    }
  }
  return -1;
}

function findTaskTeamIndexByInvite(array $teams, string $workId): int
{
  $target = trim($workId);
  if ($target === '') {
    return -1;
  }
  foreach ($teams as $index => $team) {
    if (!is_array($team)) {
      continue;
    }
    $invites = is_array($team['invites'] ?? null) ? $team['invites'] : [];
    if (in_array($target, $invites, true)) {
      return (int)$index;
    }
  }
  return -1;
}

function findTaskTeamIndexByRequest(array $teams, string $workId): int
{
  $target = trim($workId);
  if ($target === '') {
    return -1;
  }
  foreach ($teams as $index => $team) {
    if (!is_array($team)) {
      continue;
    }
    $requests = is_array($team['requests'] ?? null) ? $team['requests'] : [];
    if (in_array($target, $requests, true)) {
      return (int)$index;
    }
  }
  return -1;
}

function findInviteePhoneIndex(array $header, array $mapping): int
{
  $mappingKeys = ['phone', 'phoneNumber', 'phone_number', 'mobile', 'mobileNumber', 'mobile_number'];
  foreach ($mappingKeys as $key) {
    $mappedIndex = $mapping[$key] ?? null;
    if (is_numeric($mappedIndex) && (int)$mappedIndex >= 0) {
      return (int)$mappedIndex;
    }
  }
  return findFirstHeaderIndex($header, [
    'phone',
    'phone number',
    'mobile',
    'mobile number',
    'شماره موبایل',
    'شماره تلفن',
    'تلفن'
  ]);
}

function findInviteeLastNameIndex(array $header, array $mapping): int
{
  $mappingKeys = ['lastName', 'last_name', 'last name', 'family', 'surname'];
  foreach ($mappingKeys as $key) {
    $mappedIndex = $mapping[$key] ?? null;
    if (is_numeric($mappedIndex) && (int)$mappedIndex >= 0) {
      return (int)$mappedIndex;
    }
  }
  return findFirstHeaderIndex($header, [
    'last name',
    'family',
    'surname',
    'نام خانوادگی'
  ]);
}

function normalizePhoneLookupToken(string $value): string
{
  $normalizedDigits = normalizeUnicodeDigitsToAscii($value);
  $digitsOnly = preg_replace('/\D+/', '', $normalizedDigits);
  return is_string($digitsOnly) ? $digitsOnly : '';
}

function parseTeamTaskMap(string $raw): array
{
  $entries = preg_split('/\s*,\s*/', trim($raw));
  if (!is_array($entries)) {
    return [];
  }
  $result = [];
  foreach ($entries as $entry) {
    $token = trim((string)$entry);
    if ($token === '') {
      continue;
    }
    $parts = explode('::', $token);
    if (count($parts) < 4) {
      continue;
    }
    $taskId = trim((string)($parts[0] ?? ''));
    if ($taskId === '') {
      continue;
    }
    $teamName = trim((string)($parts[1] ?? ''));
    $status = trim((string)($parts[2] ?? ''));
    $score = normalizeTaskScoreValue($parts[3] ?? 0);
    $result[$taskId] = [
      'teamName' => $teamName,
      'status' => $status,
      'score' => $score
    ];
  }
  return $result;
}

function serializeTeamTaskMap(array $map): string
{
  $tokens = [];
  foreach ($map as $taskId => $entry) {
    $normalizedTaskId = trim((string)$taskId);
    if ($normalizedTaskId === '' || !is_array($entry)) {
      continue;
    }
    $teamName = trim((string)($entry['teamName'] ?? ''));
    $status = trim((string)($entry['status'] ?? ''));
    $score = normalizeTaskScoreValue($entry['score'] ?? 0);
    $tokens[] = $normalizedTaskId . '::' . $teamName . '::' . $status . '::' . (string)$score;
  }
  return implode(', ', $tokens);
}

function readTeamTaskScoreFromInviteeRow(array $columns, array $row, string $taskId): int
{
  $taskColumnIndex = (int)($columns['team task'] ?? -1);
  if ($taskColumnIndex < 0 || $taskId === '') {
    return 0;
  }
  $map = parseTeamTaskMap((string)($row[$taskColumnIndex] ?? ''));
  if (!isset($map[$taskId]) || !is_array($map[$taskId])) {
    return 0;
  }
  return normalizeTaskScoreValue($map[$taskId]['score'] ?? 0);
}

function updateTeamTaskInviteeEntry(array &$rows, array $columns, int $rowIndex, string $taskId, ?string $teamName, ?string $status, ?int $score = null): void
{
  $taskColumnIndex = (int)($columns['team task'] ?? -1);
  if ($taskColumnIndex < 0 || $taskId === '' || $rowIndex < 1 || !isset($rows[$rowIndex]) || !is_array($rows[$rowIndex])) {
    return;
  }
  $header = is_array($rows[0] ?? null) ? $rows[0] : [];
  $rowLength = count($header);
  if (count($rows[$rowIndex]) < $rowLength) {
    $rows[$rowIndex] = array_pad($rows[$rowIndex], $rowLength, '');
  }
  $map = parseTeamTaskMap((string)($rows[$rowIndex][$taskColumnIndex] ?? ''));
  if ($teamName === null || $status === null || trim($status) === '') {
    unset($map[$taskId]);
  } else {
    $existingScore = isset($map[$taskId]) && is_array($map[$taskId])
      ? normalizeTaskScoreValue($map[$taskId]['score'] ?? 0)
      : 0;
    $nextScore = is_int($score) ? max(0, $score) : $existingScore;
    $map[$taskId] = [
      'teamName' => trim($teamName),
      'status' => trim($status),
      'score' => $nextScore
    ];
  }
  $rows[$rowIndex][$taskColumnIndex] = serializeTeamTaskMap($map);
}

function getInviteeSummaryByWorkId(array $table, string $workId): ?array
{
  $rows = is_array($table['rows'] ?? null) ? $table['rows'] : [];
  $header = is_array($table['header'] ?? null) ? $table['header'] : [];
  $mapping = is_array($table['mapping'] ?? null) ? $table['mapping'] : [];
  $workIdIndex = (int)($table['workIdIndex'] ?? -1);
  if ($workIdIndex < 0) {
    return null;
  }
  $rowIndex = findInviteeRowIndex($rows, $workIdIndex, $workId);
  if ($rowIndex < 0 || !is_array($rows[$rowIndex] ?? null)) {
    return null;
  }
  $row = $rows[$rowIndex];
  $phoneIndex = findInviteePhoneIndex($header, $mapping);
  $lastNameIndex = findInviteeLastNameIndex($header, $mapping);
  $resolvedWorkId = trim((string)($row[$workIdIndex] ?? $workId));
  $fullName = resolveInviteeFullName($header, $mapping, $row, $resolvedWorkId);
  $firstName = resolveInviteeFirstName($header, $mapping, $row, $fullName);
  $lastName = $lastNameIndex >= 0 ? trim((string)($row[$lastNameIndex] ?? '')) : '';
  $phone = $phoneIndex >= 0 ? trim((string)($row[$phoneIndex] ?? '')) : '';
  return [
    'workId' => $resolvedWorkId,
    'fullName' => $fullName,
    'firstName' => $firstName,
    'lastName' => $lastName,
    'phone' => $phone
  ];
}

function findInviteeRowIndexByPhone(array $table, string $phone): int
{
  $rows = is_array($table['rows'] ?? null) ? $table['rows'] : [];
  $header = is_array($table['header'] ?? null) ? $table['header'] : [];
  $mapping = is_array($table['mapping'] ?? null) ? $table['mapping'] : [];
  $phoneIndex = findInviteePhoneIndex($header, $mapping);
  if ($phoneIndex < 0) {
    return -1;
  }
  $needle = normalizePhoneLookupToken($phone);
  if ($needle === '') {
    return -1;
  }
  for ($i = 1; $i < count($rows); $i += 1) {
    $row = $rows[$i] ?? [];
    $value = normalizePhoneLookupToken((string)($row[$phoneIndex] ?? ''));
    if ($value !== '' && $value === $needle) {
      return $i;
    }
  }
  return -1;
}

function resolveInviteeWorkIdByCredential(array $table, string $credential): string
{
  $rows = is_array($table['rows'] ?? null) ? $table['rows'] : [];
  $workIdIndex = (int)($table['workIdIndex'] ?? -1);
  if ($workIdIndex < 0) {
    return '';
  }
  $target = trim($credential);
  if ($target === '') {
    return '';
  }
  $rowIndex = findInviteeRowIndex($rows, $workIdIndex, $target);
  if ($rowIndex >= 0) {
    return trim((string)($rows[$rowIndex][$workIdIndex] ?? ''));
  }
  return '';
}

function buildTeamTaskGuideText(string $prefix, string $guide, string $suffix): string
{
  $parts = [];
  $prefixText = trim($prefix);
  $guideText = trim($guide);
  $suffixText = trim($suffix);
  if ($prefixText !== '') {
    $parts[] = $prefixText;
  }
  if ($guideText !== '') {
    $parts[] = $guideText;
  }
  if ($suffixText !== '') {
    $parts[] = $suffixText;
  }
  return trim(implode("\n\n", $parts));
}

function buildTeamTaskMemberPayload(array $table, array $team, string $taskId): array
{
  $members = [];
  $memberIds = is_array($team['members'] ?? null) ? $team['members'] : [];
  foreach ($memberIds as $memberWorkId) {
    $memberId = trim((string)$memberWorkId);
    if ($memberId === '') {
      continue;
    }
    $profile = getInviteeSummaryByWorkId($table, $memberId);
    $members[] = [
      'workId' => $memberId,
      'fullName' => (string)($profile['fullName'] ?? $memberId),
      'firstName' => (string)($profile['firstName'] ?? ''),
      'lastName' => (string)($profile['lastName'] ?? ''),
      'phone' => (string)($profile['phone'] ?? ''),
      'status' => $memberId === (string)($team['leaderWorkId'] ?? '') ? 'leader' : 'member',
      'score' => readTeamTaskScoreFromInviteeRow((array)($table['columns']['index'] ?? []), (array)($table['rows'][findInviteeRowIndex((array)$table['rows'], (int)($table['workIdIndex'] ?? -1), $memberId)] ?? []), $taskId)
    ];
  }

  $invites = [];
  foreach ((array)($team['invites'] ?? []) as $inviteWorkId) {
    $inviteId = trim((string)$inviteWorkId);
    if ($inviteId === '') {
      continue;
    }
    $profile = getInviteeSummaryByWorkId($table, $inviteId);
    $invites[] = [
      'workId' => $inviteId,
      'fullName' => (string)($profile['fullName'] ?? $inviteId),
      'firstName' => (string)($profile['firstName'] ?? ''),
      'lastName' => (string)($profile['lastName'] ?? ''),
      'phone' => (string)($profile['phone'] ?? ''),
      'status' => 'invited'
    ];
  }

  $requests = [];
  foreach ((array)($team['requests'] ?? []) as $requestWorkId) {
    $requestId = trim((string)$requestWorkId);
    if ($requestId === '') {
      continue;
    }
    $profile = getInviteeSummaryByWorkId($table, $requestId);
    $requests[] = [
      'workId' => $requestId,
      'fullName' => (string)($profile['fullName'] ?? $requestId),
      'firstName' => (string)($profile['firstName'] ?? ''),
      'lastName' => (string)($profile['lastName'] ?? ''),
      'phone' => (string)($profile['phone'] ?? ''),
      'status' => 'requested'
    ];
  }

  return [
    'members' => $members,
    'invites' => $invites,
    'requests' => $requests
  ];
}

function buildTeamTaskSummaryPayload(array $team, array $teamSettings, string $sessionWorkId): array
{
  $members = is_array($team['members'] ?? null) ? $team['members'] : [];
  $invites = is_array($team['invites'] ?? null) ? $team['invites'] : [];
  $requests = is_array($team['requests'] ?? null) ? $team['requests'] : [];
  $teamMax = max(1, (int)($teamSettings['teamMax'] ?? 1));
  $teamMin = max(1, (int)($teamSettings['teamMin'] ?? 1));
  return [
    'id' => (string)($team['id'] ?? ''),
    'name' => (string)($team['name'] ?? ''),
    'leaderWorkId' => (string)($team['leaderWorkId'] ?? ''),
    'joinType' => normalizeTeamJoinType((string)($team['joinType'] ?? 'private')),
    'memberCount' => count($members),
    'inviteCount' => count($invites),
    'requestCount' => count($requests),
    'minMembers' => $teamMin,
    'maxMembers' => $teamMax,
    'hasSlot' => count($members) < $teamMax,
    'started' => (bool)($team['started'] ?? false),
    'challengeId' => (string)($team['challengeId'] ?? ''),
    'challengeName' => (string)($team['challengeName'] ?? ''),
    'isLeader' => trim((string)($team['leaderWorkId'] ?? '')) === trim($sessionWorkId),
    'isMember' => in_array(trim($sessionWorkId), $members, true),
    'isInvited' => in_array(trim($sessionWorkId), $invites, true),
    'isRequested' => in_array(trim($sessionWorkId), $requests, true),
    'scoreSubmitted' => false
  ];
}

function buildTeamTaskContextForUser(array $task, string $sessionWorkId, string $inviteesPath, string $inviteesMapPath): array
{
  $taskId = trim((string)($task['id'] ?? ''));
  $tagCode = normalizeTaskTagCode((string)($task['tagCode'] ?? ''));
  if ($taskId === '' || $tagCode === '' || trim($sessionWorkId) === '') {
    return [
      'teamSettings' => ['teamMin' => 1, 'teamMax' => 1, 'teamAdditionalNote' => ''],
      'guidePrefix' => '',
      'guideSuffix' => '',
      'myTeam' => null,
      'invitedTeams' => [],
      'publicTeams' => [],
      'myStatus' => 'none'
    ];
  }
  $table = loadInviteesTable($inviteesPath, $inviteesMapPath);
  $teamSettings = readTaskTeamSettings(TASKS_DIR_PATH, $tagCode);
  $infoSettings = readTaskInfoSettings(TASKS_DIR_PATH, $tagCode);
  $runtime = readTaskTeamRuntime(TASKS_DIR_PATH, $tagCode);
  $challengeRecords = readTaskTeamChallenges(TASKS_DIR_PATH, $tagCode);
  $challengeMapById = [];
  foreach ($challengeRecords as $challengeItem) {
    if (!is_array($challengeItem)) {
      continue;
    }
    $challengeId = trim((string)($challengeItem['id'] ?? ''));
    if ($challengeId === '') {
      continue;
    }
    $challengeMapById[$challengeId] = [
      'name' => trim((string)($challengeItem['name'] ?? '')),
      'guide' => trim((string)($challengeItem['guide'] ?? ($challengeItem['challengeGuide'] ?? ($challengeItem['challenge_guide'] ?? ''))))
    ];
  }
  $teams = is_array($runtime['teams'] ?? null) ? $runtime['teams'] : [];
  $teamMin = max(1, (int)($teamSettings['teamMin'] ?? 1));
  $teamMax = max($teamMin, (int)($teamSettings['teamMax'] ?? 1));
  $teamSettings['teamMin'] = $teamMin;
  $teamSettings['teamMax'] = $teamMax;
  $sessionToken = trim($sessionWorkId);

  $myTeam = null;
  $invitedTeams = [];
  $publicTeams = [];
  $myStatus = 'none';

  foreach ($teams as $team) {
    if (!is_array($team)) {
      continue;
    }
    $summary = buildTeamTaskSummaryPayload($team, $teamSettings, $sessionToken);
    $membersBundle = buildTeamTaskMemberPayload($table, $team, $taskId);
    $summary['members'] = $membersBundle['members'] ?? [];
    $runtimeChallengeId = trim((string)($team['challengeId'] ?? ''));
    $runtimeChallengeName = trim((string)($team['challengeName'] ?? ''));
    $runtimeChallengeGuide = trim((string)($team['challengeGuide'] ?? ''));
    $freshChallenge = ($runtimeChallengeId !== '' && isset($challengeMapById[$runtimeChallengeId]) && is_array($challengeMapById[$runtimeChallengeId]))
      ? $challengeMapById[$runtimeChallengeId]
      : null;
    $resolvedChallengeName = ($freshChallenge && trim((string)($freshChallenge['name'] ?? '')) !== '')
      ? trim((string)$freshChallenge['name'])
      : $runtimeChallengeName;
    $resolvedChallengeGuide = $freshChallenge
      ? trim((string)($freshChallenge['guide'] ?? ''))
      : $runtimeChallengeGuide;
    $summary['challengeId'] = $runtimeChallengeId;
    $summary['challengeName'] = $resolvedChallengeName;
    $summary['challengeGuide'] = $resolvedChallengeGuide;
    $summary['scoreSubmitted'] = false;
    foreach ((array)($membersBundle['members'] ?? []) as $memberItem) {
      if (!is_array($memberItem)) {
        continue;
      }
      $memberScore = max(0, normalizeTaskScoreValue($memberItem['score'] ?? 0));
      if ($memberScore > 0) {
        $summary['scoreSubmitted'] = true;
        break;
      }
    }
    $summaryId = trim((string)($summary['id'] ?? ''));
    if ($summaryId === '') {
      continue;
    }
    if (!empty($summary['isMember'])) {
      $summary['members'] = $membersBundle['members'] ?? [];
      $summary['invites'] = $membersBundle['invites'] ?? [];
      $summary['requests'] = $membersBundle['requests'] ?? [];
      $summary['started'] = (bool)($team['started'] ?? false);
      $summary['challengeId'] = $runtimeChallengeId;
      $summary['challengeName'] = $resolvedChallengeName;
      $summary['challengeGuide'] = $resolvedChallengeGuide;
      $summary['startedAt'] = (string)($team['startedAt'] ?? '');
      $myTeam = $summary;
      $myStatus = !empty($summary['isLeader']) ? 'leader' : 'member';
      continue;
    }
    if (!empty($summary['isInvited'])) {
      $invitedTeams[] = $summary;
      if ($myStatus === 'none') {
        $myStatus = 'invited';
      }
    }
    if (!empty($summary['isRequested']) && $myStatus === 'none') {
      $myStatus = 'requested';
    }
    if ((string)($summary['joinType'] ?? '') !== 'private') {
      $publicTeams[] = $summary;
    }
  }

  return [
    'teamSettings' => $teamSettings,
    'guidePrefix' => (string)($infoSettings['guidePrefix'] ?? ''),
    'guideSuffix' => (string)($infoSettings['guideSuffix'] ?? ''),
    'myTeam' => $myTeam,
    'invitedTeams' => array_values($invitedTeams),
    'publicTeams' => array_values($publicTeams),
    'myStatus' => $myStatus
  ];
}

function resolveTeamTaskStatusForUser(array $teams, string $workId): array
{
  $target = trim($workId);
  if ($target === '') {
    return ['teamName' => '', 'status' => ''];
  }
  foreach ($teams as $team) {
    if (!is_array($team)) {
      continue;
    }
    $teamName = trim((string)($team['name'] ?? ''));
    $leaderWorkId = trim((string)($team['leaderWorkId'] ?? ''));
    $members = is_array($team['members'] ?? null) ? $team['members'] : [];
    if (in_array($target, $members, true)) {
      if ((bool)($team['started'] ?? false)) {
        return ['teamName' => $teamName, 'status' => 'started'];
      }
      if ($target === $leaderWorkId) {
        return ['teamName' => $teamName, 'status' => 'leader'];
      }
      return ['teamName' => $teamName, 'status' => 'member'];
    }
  }
  foreach ($teams as $team) {
    if (!is_array($team)) {
      continue;
    }
    $teamName = trim((string)($team['name'] ?? ''));
    $invites = is_array($team['invites'] ?? null) ? $team['invites'] : [];
    if (in_array($target, $invites, true)) {
      return ['teamName' => $teamName, 'status' => 'invited'];
    }
  }
  foreach ($teams as $team) {
    if (!is_array($team)) {
      continue;
    }
    $teamName = trim((string)($team['name'] ?? ''));
    $requests = is_array($team['requests'] ?? null) ? $team['requests'] : [];
    if (in_array($target, $requests, true)) {
      return ['teamName' => $teamName, 'status' => 'requested'];
    }
  }
  return ['teamName' => '', 'status' => ''];
}

function syncTeamTaskInviteeStatusByWorkId(array &$rows, array $columns, int $workIdIndex, string $taskId, array $teams, string $workId): void
{
  if ($workIdIndex < 0) {
    return;
  }
  $rowIndex = findInviteeRowIndex($rows, $workIdIndex, $workId);
  if ($rowIndex < 0) {
    return;
  }
  $resolved = resolveTeamTaskStatusForUser($teams, $workId);
  $status = trim((string)($resolved['status'] ?? ''));
  $teamName = trim((string)($resolved['teamName'] ?? ''));
  $teamTaskColumnIndex = (int)($columns['team task'] ?? -1);
  $infoTasksColumnIndex = (int)($columns['info tasks'] ?? -1);
  $preservedScore = 0;
  if ($teamTaskColumnIndex >= 0) {
    $currentMap = parseTeamTaskMap((string)($rows[$rowIndex][$teamTaskColumnIndex] ?? ''));
    if (isset($currentMap[$taskId]) && is_array($currentMap[$taskId])) {
      $preservedScore = normalizeTaskScoreValue($currentMap[$taskId]['score'] ?? 0);
    }
  }
  if ($preservedScore <= 0 && $infoTasksColumnIndex >= 0) {
    $legacyMap = parseInfoTasksScoreMap((string)($rows[$rowIndex][$infoTasksColumnIndex] ?? ''));
    if (array_key_exists($taskId, $legacyMap)) {
      $preservedScore = normalizeTaskScoreValue($legacyMap[$taskId] ?? 0);
    }
  }
  if ($status === '') {
    updateTeamTaskInviteeEntry($rows, $columns, $rowIndex, $taskId, null, null);
    return;
  }
  updateTeamTaskInviteeEntry($rows, $columns, $rowIndex, $taskId, $teamName, $status, $preservedScore);
}

function collectTeamTaskAffectedUsers(array $teams): array
{
  $seen = [];
  $result = [];
  foreach ($teams as $team) {
    if (!is_array($team)) {
      continue;
    }
    foreach (['members', 'invites', 'requests'] as $key) {
      $items = is_array($team[$key] ?? null) ? $team[$key] : [];
      foreach ($items as $workId) {
        $token = trim((string)$workId);
        if ($token === '' || isset($seen[$token])) {
          continue;
        }
        $seen[$token] = true;
        $result[] = $token;
      }
    }
  }
  return $result;
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
    'devPhase' => normalizeTaskBoolValue($task['devPhase'] ?? ($task['dev_phase'] ?? false)),
    'startDate' => normalizeTaskDateValue((string)($task['startDate'] ?? ($task['start_date'] ?? ''))),
    'startTime' => normalizeTaskTimeValue((string)($task['startTime'] ?? ($task['start_time'] ?? ''))),
    'endDate' => normalizeTaskDateValue((string)($task['endDate'] ?? ($task['end_date'] ?? ''))),
    'endTime' => normalizeTaskTimeValue((string)($task['endTime'] ?? ($task['end_time'] ?? ''))),
    'order' => $order,
    'createdAt' => trim((string)($task['createdAt'] ?? ''))
  ];
}

function isTaskInDevPhase(array $task): bool
{
  return normalizeTaskBoolValue($task['devPhase'] ?? ($task['dev_phase'] ?? false));
}

function canUserAccessTaskByRole(array $task, bool $isAdmin): bool
{
  if (!isTaskInDevPhase($task)) {
    return true;
  }
  return $isAdmin;
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
    $infoSettings = readTaskInfoSettings($tasksDir, $tagCode);
    $task['infoTitle'] = (string)($infoSettings['title'] ?? '');
    $task['infoText'] = (string)($infoSettings['text'] ?? '');
    $task['guidePrefix'] = (string)($infoSettings['guidePrefix'] ?? '');
    $task['guideSuffix'] = (string)($infoSettings['guideSuffix'] ?? '');
    if ((string)($task['taskType'] ?? '') === 'team_task') {
      $teamSettings = readTaskTeamSettings($tasksDir, $tagCode);
      $task['teamMin'] = (int)($teamSettings['teamMin'] ?? 1);
      $task['teamMax'] = (int)($teamSettings['teamMax'] ?? 1);
      $task['teamAdditionalNote'] = (string)($teamSettings['teamAdditionalNote'] ?? '');
      $task['teamChallenges'] = readTaskTeamChallenges($tasksDir, $tagCode);
    } else {
      $task['teamMin'] = 0;
      $task['teamMax'] = 0;
      $task['teamAdditionalNote'] = '';
      $task['teamChallenges'] = [];
    }
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

function parseInfoTasksScoreMap(string $raw): array
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
    $parts = explode('::', $token, 2);
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

function buildTaskDescribePhotoDirPath(string $tasksDir, string $tagCode): string
{
  $normalizedTag = normalizeTaskTagCode($tagCode);
  if ($normalizedTag === '') {
    return '';
  }
  return $tasksDir . DIRECTORY_SEPARATOR . $normalizedTag . DIRECTORY_SEPARATOR . TASK_DESCRIBE_PHOTO_DIR;
}

function buildTaskDescribePhotoMetaPath(string $tasksDir, string $tagCode): string
{
  $photoDir = buildTaskDescribePhotoDirPath($tasksDir, $tagCode);
  if ($photoDir === '') {
    return '';
  }
  return $photoDir . DIRECTORY_SEPARATOR . TASK_DESCRIBE_PHOTO_META_FILE;
}

function buildTaskDescribePhotoArticlesPath(string $tasksDir, string $tagCode): string
{
  $photoDir = buildTaskDescribePhotoDirPath($tasksDir, $tagCode);
  if ($photoDir === '') {
    return '';
  }
  return $photoDir . DIRECTORY_SEPARATOR . TASK_DESCRIBE_PHOTO_ARTICLES_DIR;
}

function normalizeDescribePhotoFileToken(string $value, string $fallback = 'file'): string
{
  $token = trim($value);
  if ($token === '') {
    $token = $fallback;
  }
  $normalized = preg_replace('/[^A-Za-z0-9._-]+/', '_', $token);
  if (!is_string($normalized) || $normalized === '') {
    $normalized = $fallback;
  }
  $normalized = trim($normalized, '_');
  if ($normalized === '') {
    $normalized = $fallback;
  }
  return $normalized;
}

function buildDescribePhotoArticleFileName(string $workId, string $photoId): string
{
  $safeWorkId = normalizeDescribePhotoFileToken($workId, 'user');
  $safePhotoId = normalizeDescribePhotoFileToken($photoId, 'photo');
  return $safeWorkId . '---' . $safePhotoId . '.txt';
}

function buildDescribePhotoImageUrl(string $tagCode, string $fileName): string
{
  $safeTagCode = normalizeTaskTagCode($tagCode);
  $safeFileName = basename(trim($fileName));
  if ($safeTagCode === '' || $safeFileName === '') {
    return '';
  }
  $segments = [
    'tasks',
    $safeTagCode,
    TASK_DESCRIBE_PHOTO_DIR,
    $safeFileName
  ];
  return implode('/', array_map(static fn(string $segment): string => rawurlencode($segment), $segments));
}

function readTaskDescribePhotoEntries(string $tasksDir, string $tagCode): array
{
  $metaPath = buildTaskDescribePhotoMetaPath($tasksDir, $tagCode);
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

  $entries = [];
  $seen = [];
  foreach ($decoded as $item) {
    if (!is_array($item)) {
      continue;
    }
    $id = trim((string)($item['id'] ?? ''));
    $name = trim((string)($item['name'] ?? ''));
    $fileName = basename(trim((string)($item['fileName'] ?? ($item['filename'] ?? ''))));
    if ($id === '' || $fileName === '' || isset($seen[$id])) {
      continue;
    }
    $seen[$id] = true;
    if ($name === '') {
      $name = trim((string)pathinfo($fileName, PATHINFO_FILENAME));
    }
    if ($name === '') {
      $name = 'تصویر';
    }
    $url = buildDescribePhotoImageUrl($tagCode, $fileName);
    if ($url === '') {
      continue;
    }
    $entries[] = [
      'id' => $id,
      'name' => $name,
      'fileName' => $fileName,
      'url' => $url
    ];
  }
  return $entries;
}

function parseDescribePhotoPicksMap(string $raw): array
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
    $parts = explode('::', $token, 3);
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

function serializeDescribePhotoPicksMap(array $map): string
{
  $tokens = [];
  foreach ($map as $taskId => $photoMap) {
    $normalizedTaskId = trim((string)$taskId);
    if ($normalizedTaskId === '' || !is_array($photoMap)) {
      continue;
    }
    foreach ($photoMap as $photoId => $fileName) {
      $normalizedPhotoId = trim((string)$photoId);
      $normalizedFileName = basename(trim((string)$fileName));
      if ($normalizedPhotoId === '' || $normalizedFileName === '') {
        continue;
      }
      $tokens[] = $normalizedTaskId . '::' . $normalizedPhotoId . '::' . $normalizedFileName;
    }
  }
  return implode(', ', $tokens);
}

function countWordsInText(string $text): int
{
  $trimmed = trim($text);
  if ($trimmed === '') {
    return 0;
  }
  $matched = preg_match_all('/\S+/u', $trimmed, $parts);
  if (!is_int($matched) || $matched <= 0) {
    return 0;
  }
  return $matched;
}

function resolveDescribePhotoPicksForUser(array $task, string $workId, string $inviteesPath, string $inviteesMapPath): array
{
  $taskId = trim((string)($task['id'] ?? ''));
  $tagCode = normalizeTaskTagCode((string)($task['tagCode'] ?? ''));
  $normalizedWorkId = trim($workId);
  if ($taskId === '' || $tagCode === '' || $normalizedWorkId === '') {
    return ['ok' => false, 'message' => 'شناسه ماموریت نامعتبر است.'];
  }

  $availablePhotos = readTaskDescribePhotoEntries(TASKS_DIR_PATH, $tagCode);
  if (count($availablePhotos) < 3) {
    return ['ok' => false, 'message' => 'برای این ماموریت حداقل ۳ تصویر لازم است.'];
  }

  $table = loadInviteesTable($inviteesPath, $inviteesMapPath);
  $rows = is_array($table['rows'] ?? null) ? $table['rows'] : [];
  $columns = is_array($table['columns']['index'] ?? null) ? $table['columns']['index'] : [];
  $workIdIndex = (int)($table['workIdIndex'] ?? -1);
  $rowIndex = findInviteeRowIndex($rows, $workIdIndex, $normalizedWorkId);
  if ($rowIndex < 0) {
    return ['ok' => false, 'message' => 'رکورد کاربر پیدا نشد.'];
  }

  $header = is_array($rows[0] ?? null) ? $rows[0] : [];
  $rowLength = count($header);
  if (!isset($rows[$rowIndex]) || !is_array($rows[$rowIndex])) {
    $rows[$rowIndex] = [];
  }
  if (count($rows[$rowIndex]) < $rowLength) {
    $rows[$rowIndex] = array_pad($rows[$rowIndex], $rowLength, '');
  }

  $picksColumnIndex = (int)($columns['describe photo picks'] ?? -1);
  if ($picksColumnIndex < 0) {
    return ['ok' => false, 'message' => 'ستون تصاویر ماموریت آماده نیست.'];
  }

  $rawPicks = trim((string)($rows[$rowIndex][$picksColumnIndex] ?? ''));
  $pickMap = parseDescribePhotoPicksMap($rawPicks);
  $taskPickMap = is_array($pickMap[$taskId] ?? null) ? $pickMap[$taskId] : [];

  $photoById = [];
  foreach ($availablePhotos as $photo) {
    if (!is_array($photo)) {
      continue;
    }
    $photoId = trim((string)($photo['id'] ?? ''));
    if ($photoId === '') {
      continue;
    }
    $photoById[$photoId] = $photo;
  }

  $desiredCount = min(3, count($photoById));
  $changed = false;
  $selectedMap = [];
  foreach ($taskPickMap as $photoId => $fileName) {
    $normalizedPhotoId = trim((string)$photoId);
    if ($normalizedPhotoId === '' || !isset($photoById[$normalizedPhotoId])) {
      $changed = true;
      continue;
    }
    $safeFileName = basename(trim((string)$fileName));
    if ($safeFileName === '') {
      $safeFileName = buildDescribePhotoArticleFileName($normalizedWorkId, $normalizedPhotoId);
      $changed = true;
    }
    $selectedMap[$normalizedPhotoId] = $safeFileName;
  }

  if (count($selectedMap) > $desiredCount) {
    $selectedMap = array_slice($selectedMap, 0, $desiredCount, true);
    $changed = true;
  }

  if (count($selectedMap) < $desiredCount) {
    $remainingPhotoIds = [];
    foreach (array_keys($photoById) as $photoId) {
      if (!isset($selectedMap[$photoId])) {
        $remainingPhotoIds[] = $photoId;
      }
    }
    shuffle($remainingPhotoIds);
    while (count($selectedMap) < $desiredCount && $remainingPhotoIds) {
      $nextPhotoId = array_shift($remainingPhotoIds);
      if (!is_string($nextPhotoId) || $nextPhotoId === '') {
        continue;
      }
      $selectedMap[$nextPhotoId] = buildDescribePhotoArticleFileName($normalizedWorkId, $nextPhotoId);
      $changed = true;
    }
  }

  $articlesDirPath = buildTaskDescribePhotoArticlesPath(TASKS_DIR_PATH, $tagCode);
  if ($articlesDirPath === '') {
    return ['ok' => false, 'message' => 'مسیر فایل‌های ماموریت نامعتبر است.'];
  }
  if (!is_dir($articlesDirPath) && !(mkdir($articlesDirPath, 0777, true) || is_dir($articlesDirPath))) {
    return ['ok' => false, 'message' => 'ساخت پوشه مقاله تصاویر ناموفق بود.'];
  }

  foreach ($selectedMap as $photoId => $fileName) {
    $safeFileName = basename(trim((string)$fileName));
    if ($safeFileName === '') {
      $safeFileName = buildDescribePhotoArticleFileName($normalizedWorkId, (string)$photoId);
      $selectedMap[$photoId] = $safeFileName;
      $changed = true;
    }
    $filePath = $articlesDirPath . DIRECTORY_SEPARATOR . $safeFileName;
    if (!is_file($filePath)) {
      if (file_put_contents($filePath, '', LOCK_EX) === false) {
        return ['ok' => false, 'message' => 'ساخت فایل متن تصویر ناموفق بود.'];
      }
      $changed = true;
    }
  }

  $pickMap[$taskId] = $selectedMap;
  if ($changed || (($table['columns']['added'] ?? false) && $rows)) {
    $rows[$rowIndex][$picksColumnIndex] = serializeDescribePhotoPicksMap($pickMap);
    if (!writeInviteesCsv($inviteesPath, $rows)) {
      return ['ok' => false, 'message' => 'ذخیره انتخاب تصاویر ناموفق بود.'];
    }
  }

  $selectedPhotos = [];
  foreach ($selectedMap as $photoId => $fileName) {
    if (!isset($photoById[$photoId]) || !is_array($photoById[$photoId])) {
      continue;
    }
    $photo = $photoById[$photoId];
    $selectedPhotos[] = [
      'id' => (string)$photoId,
      'name' => (string)($photo['name'] ?? ''),
      'url' => (string)($photo['url'] ?? ''),
      'articleFile' => basename(trim((string)$fileName))
    ];
  }

  return [
    'ok' => true,
    'taskId' => $taskId,
    'tagCode' => $tagCode,
    'photos' => $selectedPhotos
  ];
}

function readDescribePhotoUserArticle(array $task, string $workId, string $photoId, string $inviteesPath, string $inviteesMapPath): array
{
  $resolved = resolveDescribePhotoPicksForUser($task, $workId, $inviteesPath, $inviteesMapPath);
  if (!($resolved['ok'] ?? false)) {
    return $resolved;
  }
  $targetPhotoId = trim($photoId);
  if ($targetPhotoId === '') {
    return ['ok' => false, 'message' => 'تصویر انتخاب نشده است.'];
  }
  $photoItems = is_array($resolved['photos'] ?? null) ? $resolved['photos'] : [];
  $target = null;
  foreach ($photoItems as $item) {
    if (!is_array($item)) {
      continue;
    }
    if (trim((string)($item['id'] ?? '')) === $targetPhotoId) {
      $target = $item;
      break;
    }
  }
  if (!is_array($target)) {
    return ['ok' => false, 'message' => 'این تصویر برای کاربر انتخاب نشده است.'];
  }
  $tagCode = (string)($resolved['tagCode'] ?? '');
  $articlesDirPath = buildTaskDescribePhotoArticlesPath(TASKS_DIR_PATH, $tagCode);
  if ($articlesDirPath === '') {
    return ['ok' => false, 'message' => 'مسیر فایل متن تصویر نامعتبر است.'];
  }
  $fileName = basename(trim((string)($target['articleFile'] ?? '')));
  if ($fileName === '') {
    return ['ok' => false, 'message' => 'فایل متن تصویر نامعتبر است.'];
  }
  $filePath = $articlesDirPath . DIRECTORY_SEPARATOR . $fileName;
  if (!is_file($filePath)) {
    if (file_put_contents($filePath, '', LOCK_EX) === false) {
      return ['ok' => false, 'message' => 'ساخت فایل متن تصویر ناموفق بود.'];
    }
  }
  $content = file_get_contents($filePath);
  if (!is_string($content)) {
    $content = '';
  }
  return [
    'ok' => true,
    'photo' => $target,
    'text' => $content,
    'wordCount' => countWordsInText($content)
  ];
}

function saveDescribePhotoUserArticle(array $task, string $workId, string $photoId, string $text, string $inviteesPath, string $inviteesMapPath): array
{
  $normalizedText = str_replace(["\r\n", "\r"], "\n", $text);
  $wordCount = countWordsInText($normalizedText);
  if ($wordCount > 300) {
    return ['ok' => false, 'message' => 'حداکثر ۳۰۰ کلمه مجاز است.'];
  }

  $resolved = resolveDescribePhotoPicksForUser($task, $workId, $inviteesPath, $inviteesMapPath);
  if (!($resolved['ok'] ?? false)) {
    return $resolved;
  }

  $targetPhotoId = trim($photoId);
  if ($targetPhotoId === '') {
    return ['ok' => false, 'message' => 'تصویر انتخاب نشده است.'];
  }
  $photoItems = is_array($resolved['photos'] ?? null) ? $resolved['photos'] : [];
  $target = null;
  foreach ($photoItems as $item) {
    if (!is_array($item)) {
      continue;
    }
    if (trim((string)($item['id'] ?? '')) === $targetPhotoId) {
      $target = $item;
      break;
    }
  }
  if (!is_array($target)) {
    return ['ok' => false, 'message' => 'این تصویر برای کاربر انتخاب نشده است.'];
  }

  $tagCode = (string)($resolved['tagCode'] ?? '');
  $articlesDirPath = buildTaskDescribePhotoArticlesPath(TASKS_DIR_PATH, $tagCode);
  if ($articlesDirPath === '') {
    return ['ok' => false, 'message' => 'مسیر فایل متن تصویر نامعتبر است.'];
  }
  if (!is_dir($articlesDirPath) && !(mkdir($articlesDirPath, 0777, true) || is_dir($articlesDirPath))) {
    return ['ok' => false, 'message' => 'ساخت پوشه متن تصاویر ناموفق بود.'];
  }
  $fileName = basename(trim((string)($target['articleFile'] ?? '')));
  if ($fileName === '') {
    return ['ok' => false, 'message' => 'فایل متن تصویر نامعتبر است.'];
  }
  $filePath = $articlesDirPath . DIRECTORY_SEPARATOR . $fileName;
  if (file_put_contents($filePath, $normalizedText, LOCK_EX) === false) {
    return ['ok' => false, 'message' => 'ذخیره متن تصویر ناموفق بود.'];
  }
  return [
    'ok' => true,
    'photo' => $target,
    'wordCount' => $wordCount
  ];
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

function hasDescribePhotoSubmissionForUserTask(array $task, array $row, array $columns): bool
{
  $taskId = trim((string)($task['id'] ?? ''));
  $tagCode = normalizeTaskTagCode((string)($task['tagCode'] ?? ''));
  if ($taskId === '' || $tagCode === '') {
    return false;
  }

  $picksColumnIndex = (int)($columns['describe photo picks'] ?? -1);
  if ($picksColumnIndex < 0) {
    return false;
  }

  $pickMap = parseDescribePhotoPicksMap((string)($row[$picksColumnIndex] ?? ''));
  $taskPickMap = is_array($pickMap[$taskId] ?? null) ? $pickMap[$taskId] : [];
  if (!$taskPickMap) {
    return false;
  }

  $articlesDirPath = buildTaskDescribePhotoArticlesPath(TASKS_DIR_PATH, $tagCode);
  if ($articlesDirPath === '' || !is_dir($articlesDirPath)) {
    return false;
  }

  foreach ($taskPickMap as $fileName) {
    $safeFileName = basename(trim((string)$fileName));
    if ($safeFileName === '') {
      continue;
    }
    $articlePath = $articlesDirPath . DIRECTORY_SEPARATOR . $safeFileName;
    if (!is_file($articlePath)) {
      continue;
    }
    $content = file_get_contents($articlePath);
    if (!is_string($content)) {
      continue;
    }
    if (countWordsInText($content) > 0) {
      return true;
    }
  }

  return false;
}

function readTaskUserProgress(array $task, string $inviteesPath, string $inviteesMapPath, string $workId): array
{
  $defaults = [
    'score' => 0,
    'answered' => 0,
    'completed' => false,
    'describeSubmitted' => false,
    'teamStartedPending' => false
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
  $taskType = normalizeTaskTypeValue($task['taskType'] ?? 'quiz');
  $taskScoreColumn = $taskType === 'describe_photo'
    ? 'describe photo task'
    : ($taskType === 'team_task' ? 'team task' : 'info tasks');
  $infoTasksIndex = (int)($columns[$taskScoreColumn] ?? -1);
  $row = is_array($rows[$rowIndex] ?? null) ? $rows[$rowIndex] : [];
  $describeSubmitted = $taskType === 'describe_photo'
    ? hasDescribePhotoSubmissionForUserTask($task, $row, $columns)
    : false;
  $teamStartedPending = false;
  $completedIds = [];
  if ($taskCompletedIndex >= 0) {
    $completedIds = parseTaskCompletedIds((string)($row[$taskCompletedIndex] ?? ''));
  }
  $isCompleted = in_array($taskId, $completedIds, true);
  $taskScoreMap = [];
  if ($taskScoreMapIndex >= 0) {
    $taskScoreMap = parseTaskScoreMap((string)($row[$taskScoreMapIndex] ?? ''));
  }
  $taskScore = 0;
  if ($taskType === 'info' || $taskType === 'team_task' || $taskType === 'describe_photo') {
    if ($taskType === 'team_task') {
      $teamTaskMap = $infoTasksIndex >= 0
        ? parseTeamTaskMap((string)($row[$infoTasksIndex] ?? ''))
        : [];
      if (isset($teamTaskMap[$taskId]) && is_array($teamTaskMap[$taskId])) {
        $taskScore = max(0, normalizeTaskScoreValue($teamTaskMap[$taskId]['score'] ?? 0));
        $teamStatus = strtolower(trim((string)($teamTaskMap[$taskId]['status'] ?? '')));
        if ($taskScore <= 0 && $teamStatus === 'started') {
          $teamStartedPending = true;
        }
        if ($taskScore > 0) {
          $isCompleted = true;
        }
      }
      if ($taskScore <= 0) {
        $legacyInfoIndex = (int)($columns['info tasks'] ?? -1);
        if ($legacyInfoIndex >= 0) {
          $legacyMap = parseInfoTasksScoreMap((string)($row[$legacyInfoIndex] ?? ''));
          if (array_key_exists($taskId, $legacyMap)) {
            $taskScore = max(0, (int)($legacyMap[$taskId] ?? 0));
            if ($taskScore > 0) {
              $isCompleted = true;
            }
          }
        }
      }
    } else {
      $infoMap = $infoTasksIndex >= 0
        ? parseInfoTasksScoreMap((string)($row[$infoTasksIndex] ?? ''))
        : [];
      if (array_key_exists($taskId, $infoMap)) {
        $isCompleted = true;
        $taskScore = max(0, (int)($infoMap[$taskId] ?? 0));
      }
    }
  }
  if ($isCompleted) {
    if ($taskType === 'info' || $taskType === 'team_task' || $taskType === 'describe_photo') {
      $taskScore = max(0, $taskScore);
    } elseif (isset($taskScoreMap[$taskId])) {
      $taskScore = max(0, (int)$taskScoreMap[$taskId]);
    } else {
      $taskScore = max(0, (int)($task['score'] ?? 0));
    }
  }

  if ($isCompleted || $taskScore > 0) {
    $teamStartedPending = false;
  }

  return [
    'score' => $isCompleted ? $taskScore : 0,
    'answered' => $isCompleted ? 1 : 0,
    'completed' => $isCompleted,
    'describeSubmitted' => $describeSubmitted,
    'teamStartedPending' => $teamStartedPending
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

function buildTaskPayloadForView(
  array $tasks,
  string $inviteesPath,
  string $inviteesMapPath,
  string $workId,
  bool $isAdmin
): array
{
  $items = [];
  foreach ($tasks as $task) {
    if (!is_array($task)) {
      continue;
    }
    if (!canUserAccessTaskByRole($task, $isAdmin)) {
      continue;
    }
    $status = deriveTaskAvailabilityStatus($task);
    $taskType = normalizeTaskTypeValue($task['taskType'] ?? 'quiz');
    $isActive = $status === 'active';
    $isEndedQuiz = $taskType === 'quiz' && $status === 'ended';
    $progress = readTaskUserProgress($task, $inviteesPath, $inviteesMapPath, $workId);
    $completed = (bool)($progress['completed'] ?? false);
    $describeSubmitted = (bool)($progress['describeSubmitted'] ?? false);
    $teamStartedPending = (bool)($progress['teamStartedPending'] ?? false);
    $statusLabel = $completed
      ? 'تکمیل شده'
      : (((
          $taskType === 'describe_photo'
          && $status === 'active'
          && $describeSubmitted
        ) || (
          $taskType === 'team_task'
          && $status === 'active'
          && $teamStartedPending
        ))
        ? ($taskType === 'team_task' ? 'شروع شده' : 'تکمیل شده')
        : resolveTaskStatusLabel($status));
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
      'describeSubmitted' => $describeSubmitted,
      'teamStartedPending' => $teamStartedPending,
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

function normalizeUnicodeDigitsToAscii(string $value): string
{
  return strtr($value, [
    '۰' => '0',
    '۱' => '1',
    '۲' => '2',
    '۳' => '3',
    '۴' => '4',
    '۵' => '5',
    '۶' => '6',
    '۷' => '7',
    '۸' => '8',
    '۹' => '9',
    '٠' => '0',
    '١' => '1',
    '٢' => '2',
    '٣' => '3',
    '٤' => '4',
    '٥' => '5',
    '٦' => '6',
    '٧' => '7',
    '٨' => '8',
    '٩' => '9'
  ]);
}

function normalizeCredentialToken(string $value): string
{
  return trim(normalizeUnicodeDigitsToAscii($value));
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

function resolveInviteeFirstName(array $header, array $mapping, array $row, string $fallback = ''): string
{
  $mappingKeys = ['firstName', 'first_name', 'first name', 'name'];
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
    'نام',
    'first name',
    'name',
    'fullname',
    'full name'
  ]);
  if ($nameIndex >= 0) {
    $value = trim((string)($row[$nameIndex] ?? ''));
    if ($value !== '') {
      $parts = preg_split('/\s+/u', $value) ?: [];
      $first = trim((string)($parts[0] ?? ''));
      if ($first !== '') {
        return $first;
      }
      return $value;
    }
  }

  $fallbackText = trim($fallback);
  if ($fallbackText !== '') {
    $parts = preg_split('/\s+/u', $fallbackText) ?: [];
    $first = trim((string)($parts[0] ?? ''));
    if ($first !== '') {
      return $first;
    }
    return $fallbackText;
  }
  return '';
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
    $index[normalizeHeaderName($column)] = $colIndex;
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
    'info tasks',
    'Team Task',
    'describe photo task',
    'describe photo picks',
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
  $normalizedTarget = normalizeCredentialToken($workId);
  if ($normalizedTarget === '') {
    return -1;
  }
  for ($i = 1; $i < count($rows); $i += 1) {
    $row = $rows[$i] ?? [];
    $value = trim((string)($row[$workIdIndex] ?? ''));
    if ($value === '') {
      continue;
    }
    if ($value === $workId || normalizeCredentialToken($value) === $normalizedTarget) {
      return $i;
    }
  }
  return -1;
}

function resolveInviteeAdminColumnIndex(array $table): int
{
  $columns = is_array($table['columns']['index'] ?? null) ? $table['columns']['index'] : [];
  $header = is_array($table['header'] ?? null) ? $table['header'] : [];
  $candidates = ['admin', 'tc admin', 'is admin', 'ادمین'];
  foreach ($candidates as $candidate) {
    $colIndex = (int)($columns[$candidate] ?? -1);
    if ($colIndex >= 0) {
      return $colIndex;
    }
    $headerIndex = findHeaderIndex($header, $candidate);
    if ($headerIndex >= 0) {
      return $headerIndex;
    }
  }
  return -1;
}

function parseInviteeAdminCell($value): bool
{
  if (is_bool($value)) {
    return $value;
  }
  if (is_int($value) || is_float($value)) {
    return ((float)$value) > 0;
  }
  $token = strtolower(trim((string)$value));
  if ($token === '') {
    return false;
  }
  return in_array($token, ['1', 'true', 'yes', 'admin', 'ادمین'], true);
}

function isInviteeAdminFromTable(array $table, string $workId): bool
{
  $rows = is_array($table['rows'] ?? null) ? $table['rows'] : [];
  $workIdIndex = (int)($table['workIdIndex'] ?? -1);
  $rowIndex = findInviteeRowIndex($rows, $workIdIndex, $workId);
  if ($rowIndex < 0) {
    return false;
  }
  $adminIndex = resolveInviteeAdminColumnIndex($table);
  if ($adminIndex < 0) {
    return false;
  }
  $row = is_array($rows[$rowIndex] ?? null) ? $rows[$rowIndex] : [];
  return parseInviteeAdminCell($row[$adminIndex] ?? '');
}

function isInviteeAdmin(string $inviteesPath, string $inviteesMapPath, string $workId): bool
{
  $normalizedWorkId = trim($workId);
  if ($normalizedWorkId === '') {
    return false;
  }
  $table = loadInviteesTable($inviteesPath, $inviteesMapPath);
  return isInviteeAdminFromTable($table, $normalizedWorkId);
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
  $sessionCsrf = (string)($_SESSION['tc_csrf'] ?? '');
  if ($sessionCsrf === '') {
    $sessionCsrf = bin2hex(random_bytes(16));
    $_SESSION['tc_csrf'] = $sessionCsrf;
  }
  if ($csrfToken === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrfToken)) {
    echo json_encode([
      'status' => 'error',
      'code' => 'csrf_mismatch',
      'message' => 'نشست شما به‌روز نبود. لطفا دوباره تلاش کنید.',
      'csrf' => $sessionCsrf
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }


  if ($action === 'login') {
    $tcqSettings = loadWfqSettings($tcqSettingsPath);
    $maxAttempts = 5;
    $maxAttemptsPerIp = 30;
    $windowSeconds = 10 * 60;
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $username = normalizeCredentialToken((string)($payload['username'] ?? ''));
    $password = normalizeCredentialToken((string)($payload['password'] ?? ''));
    $attemptKey = $ip . '|' . $username;
    $ipAttemptKey = '__ip__' . $ip;
    $attempts = readLoginAttempts($loginAttemptsPath);
    $now = time();
    $collectRecentFails = static function ($rawFails) use ($now, $windowSeconds): array {
      if (!is_array($rawFails)) {
        return [];
      }
      return array_values(array_filter($rawFails, static function ($ts) use ($now, $windowSeconds) {
        return is_numeric($ts) && ($now - (int)$ts) <= $windowSeconds;
      }));
    };
    $entry = is_array($attempts[$attemptKey] ?? null) ? $attempts[$attemptKey] : ['fails' => []];
    $fails = $collectRecentFails($entry['fails'] ?? []);
    $ipEntry = is_array($attempts[$ipAttemptKey] ?? null) ? $attempts[$ipAttemptKey] : ['fails' => []];
    $ipFails = $collectRecentFails($ipEntry['fails'] ?? []);
    if (count($ipFails) >= $maxAttemptsPerIp) {
      echo json_encode(['status' => 'error', 'message' => 'Too many failed attempts from this IP. Please try again later.']);
      exit;
    }
    if (count($fails) >= $maxAttempts) {
      echo json_encode(['status' => 'error', 'message' => 'Too many failed attempts. Please try again later.']);
      exit;
    }
    $recordFail = function () use (&$attempts, $attemptKey, $ipAttemptKey, $now, &$fails, &$ipFails, $loginAttemptsPath) {
      $fails[] = $now;
      $ipFails[] = $now;
      $attempts[$attemptKey] = ['fails' => $fails];
      $attempts[$ipAttemptKey] = ['fails' => $ipFails];
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
    $rowPassword = normalizeCredentialToken((string)($rows[$rowIndex][$passwordIndex] ?? ''));
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
    $attemptsUpdated = false;
    if (isset($attempts[$attemptKey])) {
      unset($attempts[$attemptKey]);
      $attemptsUpdated = true;
    }
    if (isset($attempts[$ipAttemptKey])) {
      $attempts[$ipAttemptKey] = ['fails' => $ipFails];
      if (!$ipFails) {
        unset($attempts[$ipAttemptKey]);
      }
      $attemptsUpdated = true;
    }
    if ($attemptsUpdated) {
      writeLoginAttempts($loginAttemptsPath, $attempts);
    }
    $resolvedWorkId = trim((string)($rows[$rowIndex][$workIdIndex] ?? ''));
    if ($resolvedWorkId === '') {
      $resolvedWorkId = $username;
    }
    $_SESSION['tc_authed'] = true;
    $_SESSION['tc_work_id'] = $resolvedWorkId;
    $_SESSION['tc_invitees_mtime'] = is_file($inviteesFilePath) ? filemtime($inviteesFilePath) : null;
    $prizeIndex = $columns['prize won'] ?? -1;
    $prizeWonAtIndex = $columns['prize won at'] ?? -1;
    $angleIndex = $columns['wheel angle'] ?? -1;
    $questions = readQuestionStore($questionsStorePath);
    $questionCodes = array_values(array_map(static fn($item) => (string)($item['code'] ?? ''), $questions));
    $quizState = ensureUserQuestionProgress($rows, $rowIndex, $columns, $questionCodes, (bool)$tcqSettings['randomOrder']);
    $prizeWon = $prizeIndex >= 0 ? trim((string)($rows[$rowIndex][$prizeIndex] ?? '')) : '';
    $fullName = resolveInviteeFullName($table['header'] ?? [], $table['mapping'] ?? [], $rows[$rowIndex] ?? [], $resolvedWorkId);
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

  if ($action === 'settings_get') {
    $sessionWorkId = (string)($_SESSION['tc_work_id'] ?? '');
    if (!(($_SESSION['tc_authed'] ?? false) && $sessionWorkId !== '')) {
      echo json_encode(['status' => 'error', 'message' => 'ابتدا وارد شوید.']);
      exit;
    }
    $settings = loadJsonPayload(__DIR__ . '/Setting.json');
    $eventColors = is_array($settings['eventColors'] ?? null) ? $settings['eventColors'] : [];
    echo json_encode([
      'status' => 'ok',
      'data' => [
        'active' => (bool)($settings['active'] ?? false),
        'duration' => (bool)($settings['duration'] ?? false),
        'maintenanceMode' => (bool)($settings['maintenanceMode'] ?? false),
        'startDate' => trim((string)($settings['startDate'] ?? '')),
        'startTime' => trim((string)($settings['startTime'] ?? '')),
        'endDate' => trim((string)($settings['endDate'] ?? '')),
        'endTime' => trim((string)($settings['endTime'] ?? '')),
        'eventLogo' => trim((string)($settings['eventLogo'] ?? '')),
        'eventColors' => [
          'secondary' => trim((string)($eventColors['secondary'] ?? '')),
          'highlight' => trim((string)($eventColors['highlight'] ?? '')),
          'accentSoft' => trim((string)($eventColors['accentSoft'] ?? ''))
        ]
      ]
    ], JSON_UNESCAPED_UNICODE);
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
    $sessionIsAdmin = isInviteeAdmin($inviteesFilePath, $inviteesMapPath, $sessionWorkId);
    if (!canUserAccessTaskByRole($task, $sessionIsAdmin)) {
      echo json_encode(['status' => 'error', 'message' => 'این ماموریت در فاز توسعه است و فقط برای ادمین‌ها قابل مشاهده است.']);
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
    $describePhotos = [];
    $teamContext = null;
    if ($taskType === 'describe_photo' && $available) {
      $resolvedPicks = resolveDescribePhotoPicksForUser($task, $sessionWorkId, $inviteesFilePath, $inviteesMapPath);
      if (!($resolvedPicks['ok'] ?? false)) {
        echo json_encode(['status' => 'error', 'message' => (string)($resolvedPicks['message'] ?? 'دریافت تصاویر ماموریت ناموفق بود.')]);
        exit;
      }
      $describePhotos = is_array($resolvedPicks['photos'] ?? null) ? $resolvedPicks['photos'] : [];
    } elseif ($taskType === 'team_task') {
      $teamContext = buildTeamTaskContextForUser($task, $sessionWorkId, $inviteesFilePath, $inviteesMapPath);
    }
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
        'statusLabel' => resolveTaskStatusLabel($status),
        'infoTitle' => (string)($task['infoTitle'] ?? ''),
        'infoText' => (string)($task['infoText'] ?? ''),
        'guidePrefix' => (string)($task['guidePrefix'] ?? ''),
        'guideSuffix' => (string)($task['guideSuffix'] ?? ''),
        'teamMin' => (int)($task['teamMin'] ?? 0),
        'teamMax' => (int)($task['teamMax'] ?? 0),
        'teamAdditionalNote' => (string)($task['teamAdditionalNote'] ?? ''),
        'teamChallenges' => is_array($task['teamChallenges'] ?? null) ? $task['teamChallenges'] : [],
        'teamContext' => $teamContext,
        'describePhotos' => $describePhotos
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

  if ($action === 'describe_photo_load_article') {
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
    $photoId = trim((string)($payload['photoId'] ?? ''));
    if ($taskId === '' || $photoId === '') {
      echo json_encode(['status' => 'error', 'message' => 'اطلاعات تصویر کامل نیست.']);
      exit;
    }

    $tasks = loadTaskRecords(TASKS_JS_STORE_PATH, TASKS_DIR_PATH);
    $task = findTaskById($tasks, $taskId);
    if (!is_array($task)) {
      echo json_encode(['status' => 'error', 'message' => 'ماموریت پیدا نشد.']);
      exit;
    }
    $sessionIsAdmin = isInviteeAdmin($inviteesFilePath, $inviteesMapPath, $sessionWorkId);
    if (!canUserAccessTaskByRole($task, $sessionIsAdmin)) {
      echo json_encode(['status' => 'error', 'message' => 'این ماموریت در فاز توسعه است و فقط برای ادمین‌ها قابل مشاهده است.']);
      exit;
    }
    $taskType = normalizeTaskTypeValue($task['taskType'] ?? 'quiz');
    if ($taskType !== 'describe_photo') {
      echo json_encode(['status' => 'error', 'message' => 'این ماموریت از نوع توصیف تصویر نیست.']);
      exit;
    }
    $taskStatus = deriveTaskAvailabilityStatus($task);
    if ($taskStatus !== 'active') {
      echo json_encode(['status' => 'error', 'message' => 'این ماموریت در حال حاضر فعال نیست.']);
      exit;
    }

    $article = readDescribePhotoUserArticle($task, $sessionWorkId, $photoId, $inviteesFilePath, $inviteesMapPath);
    if (!($article['ok'] ?? false)) {
      echo json_encode(['status' => 'error', 'message' => (string)($article['message'] ?? 'بارگذاری متن تصویر ناموفق بود.')]);
      exit;
    }
    echo json_encode([
      'status' => 'ok',
      'data' => [
        'photo' => $article['photo'] ?? null,
        'text' => (string)($article['text'] ?? ''),
        'wordCount' => (int)($article['wordCount'] ?? 0),
        'maxWords' => 300
      ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'describe_photo_save_article') {
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
    $photoId = trim((string)($payload['photoId'] ?? ''));
    $text = (string)($payload['text'] ?? '');
    if ($taskId === '' || $photoId === '') {
      echo json_encode(['status' => 'error', 'message' => 'اطلاعات تصویر کامل نیست.']);
      exit;
    }

    $tasks = loadTaskRecords(TASKS_JS_STORE_PATH, TASKS_DIR_PATH);
    $task = findTaskById($tasks, $taskId);
    if (!is_array($task)) {
      echo json_encode(['status' => 'error', 'message' => 'ماموریت پیدا نشد.']);
      exit;
    }
    $sessionIsAdmin = isInviteeAdmin($inviteesFilePath, $inviteesMapPath, $sessionWorkId);
    if (!canUserAccessTaskByRole($task, $sessionIsAdmin)) {
      echo json_encode(['status' => 'error', 'message' => 'این ماموریت در فاز توسعه است و فقط برای ادمین‌ها قابل مشاهده است.']);
      exit;
    }
    $taskType = normalizeTaskTypeValue($task['taskType'] ?? 'quiz');
    if ($taskType !== 'describe_photo') {
      echo json_encode(['status' => 'error', 'message' => 'این ماموریت از نوع توصیف تصویر نیست.']);
      exit;
    }
    $taskStatus = deriveTaskAvailabilityStatus($task);
    if ($taskStatus !== 'active') {
      echo json_encode(['status' => 'error', 'message' => 'این ماموریت در حال حاضر فعال نیست.']);
      exit;
    }

    $saved = saveDescribePhotoUserArticle($task, $sessionWorkId, $photoId, $text, $inviteesFilePath, $inviteesMapPath);
    if (!($saved['ok'] ?? false)) {
      echo json_encode(['status' => 'error', 'message' => (string)($saved['message'] ?? 'ذخیره متن تصویر ناموفق بود.')]);
      exit;
    }
    echo json_encode([
      'status' => 'ok',
      'data' => [
        'photo' => $saved['photo'] ?? null,
        'wordCount' => (int)($saved['wordCount'] ?? 0),
        'maxWords' => 300
      ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'team_task_action') {
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
    $mode = strtolower(trim((string)($payload['mode'] ?? 'state')));
    if ($taskId === '') {
      echo json_encode(['status' => 'error', 'message' => 'شناسه ماموریت نامعتبر است.']);
      exit;
    }

    $tasks = loadTaskRecords(TASKS_JS_STORE_PATH, TASKS_DIR_PATH);
    $task = findTaskById($tasks, $taskId);
    if (!is_array($task)) {
      echo json_encode(['status' => 'error', 'message' => 'ماموریت پیدا نشد.']);
      exit;
    }
    $sessionIsAdmin = isInviteeAdmin($inviteesFilePath, $inviteesMapPath, $sessionWorkId);
    if (!canUserAccessTaskByRole($task, $sessionIsAdmin)) {
      echo json_encode(['status' => 'error', 'message' => 'این ماموریت در فاز توسعه است و فقط برای ادمین‌ها قابل مشاهده است.']);
      exit;
    }
    $taskType = normalizeTaskTypeValue($task['taskType'] ?? 'quiz');
    if ($taskType !== 'team_task') {
      echo json_encode(['status' => 'error', 'message' => 'این ماموریت از نوع تیمی نیست.']);
      exit;
    }
    $taskStatus = deriveTaskAvailabilityStatus($task);
    $mutationModes = [
      'create',
      'invite',
      'remove_member',
      'join',
      'review_request',
      'settings',
      'start'
    ];
    if (in_array($mode, $mutationModes, true) && $taskStatus !== 'active') {
      echo json_encode(['status' => 'error', 'message' => 'این ماموریت در حال حاضر فعال نیست.']);
      exit;
    }

    $tagCode = normalizeTaskTagCode((string)($task['tagCode'] ?? ''));
    if ($tagCode === '') {
      echo json_encode(['status' => 'error', 'message' => 'شناسه پوشه ماموریت نامعتبر است.']);
      exit;
    }

    $table = loadInviteesTable($inviteesFilePath, $inviteesMapPath);
    $rows = is_array($table['rows'] ?? null) ? $table['rows'] : [];
    $columns = is_array($table['columns']['index'] ?? null) ? $table['columns']['index'] : [];
    $workIdIndex = (int)($table['workIdIndex'] ?? -1);
    $sessionRowIndex = findInviteeRowIndex($rows, $workIdIndex, $sessionWorkId);
    if ($sessionRowIndex < 0) {
      echo json_encode(['status' => 'error', 'message' => 'رکورد کاربر پیدا نشد.']);
      exit;
    }

    $runtime = readTaskTeamRuntime(TASKS_DIR_PATH, $tagCode);
    $teams = is_array($runtime['teams'] ?? null) ? $runtime['teams'] : [];
    $teamSettings = readTaskTeamSettings(TASKS_DIR_PATH, $tagCode);
    $teamMin = max(1, (int)($teamSettings['teamMin'] ?? 1));
    $teamMax = max($teamMin, (int)($teamSettings['teamMax'] ?? 1));
    $teamSettings['teamMin'] = $teamMin;
    $teamSettings['teamMax'] = $teamMax;
    $infoSettings = readTaskInfoSettings(TASKS_DIR_PATH, $tagCode);

    $runtimeChanged = false;
    $rowsChanged = (bool)($table['columns']['added'] ?? false);
    foreach ($teams as $idx => $teamItem) {
      if (!is_array($teamItem)) {
        continue;
      }
      $started = (bool)($teamItem['started'] ?? false);
      $joinType = normalizeTeamJoinType((string)($teamItem['joinType'] ?? 'private'));
      if ($started && $joinType === 'public_open') {
        $teamItem['joinType'] = 'public_request';
        $teams[$idx] = $teamItem;
        $runtimeChanged = true;
      }
    }
    $syncInviteeStatus = function (string $workId) use (&$rows, $columns, $workIdIndex, $taskId, &$teams, &$rowsChanged): void {
      syncTeamTaskInviteeStatusByWorkId($rows, $columns, $workIdIndex, $taskId, $teams, $workId);
      $rowsChanged = true;
    };
    $saveChanges = function () use (&$runtimeChanged, &$rowsChanged, &$teams, &$rows, $tagCode, $inviteesFilePath): array {
      if ($runtimeChanged) {
        if (!saveTaskTeamRuntime(TASKS_DIR_PATH, $tagCode, ['teams' => $teams])) {
          return ['ok' => false, 'message' => 'ذخیره وضعیت تیم ناموفق بود.'];
        }
      }
      if ($rowsChanged) {
        if (!writeInviteesCsv($inviteesFilePath, $rows)) {
          return ['ok' => false, 'message' => 'ذخیره وضعیت کاربران ناموفق بود.'];
        }
      }
      return ['ok' => true];
    };
    $respondWithContext = function (array $extra = []) use ($task, $sessionWorkId, $inviteesFilePath, $inviteesMapPath): void {
      $context = buildTeamTaskContextForUser($task, $sessionWorkId, $inviteesFilePath, $inviteesMapPath);
      echo json_encode([
        'status' => 'ok',
        'data' => array_merge([
          'context' => $context
        ], $extra)
      ], JSON_UNESCAPED_UNICODE);
      exit;
    };

    if ($mode === 'state') {
      $saved = $saveChanges();
      if (!($saved['ok'] ?? false)) {
        echo json_encode(['status' => 'error', 'message' => $saved['message'] ?? 'ذخیره اطلاعات ناموفق بود.']);
        exit;
      }
      $respondWithContext();
    }

    if ($mode === 'create') {
      if (findTaskTeamIndexByMember($teams, $sessionWorkId) >= 0) {
        echo json_encode(['status' => 'error', 'message' => 'شما هم‌اکنون عضو یک تیم هستید.']);
        exit;
      }
      $teamName = trim((string)($payload['teamName'] ?? ''));
      if ($teamName === '') {
        echo json_encode(['status' => 'error', 'message' => 'نام تیم را وارد کنید.']);
        exit;
      }
      $joinType = normalizeTeamJoinType((string)($payload['joinType'] ?? 'private'));
      foreach ($teams as $idx => $otherTeam) {
        if (!is_array($otherTeam)) {
          continue;
        }
        $otherTeam['invites'] = array_values(array_filter((array)($otherTeam['invites'] ?? []), static fn($item) => trim((string)$item) !== trim($sessionWorkId)));
        $otherTeam['requests'] = array_values(array_filter((array)($otherTeam['requests'] ?? []), static fn($item) => trim((string)$item) !== trim($sessionWorkId)));
        $teams[$idx] = $otherTeam;
      }
      $teams[] = [
        'id' => makeTeamId(),
        'name' => $teamName,
        'leaderWorkId' => $sessionWorkId,
        'joinType' => $joinType,
        'members' => [$sessionWorkId],
        'invites' => [],
        'requests' => [],
        'renameCount' => 0,
        'started' => false,
        'startedAt' => '',
        'challengeId' => '',
        'challengeName' => '',
        'challengeGuide' => '',
        'createdAt' => date('Y-m-d H:i:s')
      ];
      $runtimeChanged = true;
      $syncInviteeStatus($sessionWorkId);
      $saved = $saveChanges();
      if (!($saved['ok'] ?? false)) {
        echo json_encode(['status' => 'error', 'message' => $saved['message'] ?? 'ذخیره اطلاعات ناموفق بود.']);
        exit;
      }
      $respondWithContext(['message' => 'تیم جدید ساخته شد.']);
    }

    if ($mode === 'lookup_user') {
      $myTeamIndex = findTaskTeamIndexByMember($teams, $sessionWorkId);
      if ($myTeamIndex < 0) {
        echo json_encode(['status' => 'error', 'message' => 'ابتدا یک تیم بسازید یا عضو تیم شوید.']);
        exit;
      }
      $myTeam = is_array($teams[$myTeamIndex] ?? null) ? $teams[$myTeamIndex] : [];
      $query = trim((string)($payload['query'] ?? ''));
      if ($query === '') {
        echo json_encode(['status' => 'error', 'message' => 'شماره پرسنلی (Work ID) را وارد کنید.']);
        exit;
      }
      $targetWorkId = resolveInviteeWorkIdByCredential($table, $query);
      if ($targetWorkId === '') {
        echo json_encode(['status' => 'error', 'message' => 'کاربری با این مشخصات پیدا نشد.']);
        exit;
      }
      if (trim($targetWorkId) === trim($sessionWorkId)) {
        echo json_encode(['status' => 'error', 'message' => 'دعوت از خودتان امکان‌پذیر نیست.']);
        exit;
      }
      $profile = getInviteeSummaryByWorkId($table, $targetWorkId);
      if (!is_array($profile)) {
        echo json_encode(['status' => 'error', 'message' => 'مشخصات کاربر قابل بازیابی نیست.']);
        exit;
      }
      $isMemberAny = findTaskTeamIndexByMember($teams, $targetWorkId) >= 0;
      $isInvitedInMyTeam = in_array($targetWorkId, (array)($myTeam['invites'] ?? []), true);
      $isRequestedInMyTeam = in_array($targetWorkId, (array)($myTeam['requests'] ?? []), true);
      echo json_encode([
        'status' => 'ok',
        'data' => [
          'invitee' => $profile,
          'isMemberAny' => $isMemberAny,
          'isInvitedInMyTeam' => $isInvitedInMyTeam,
          'isRequestedInMyTeam' => $isRequestedInMyTeam
        ]
      ], JSON_UNESCAPED_UNICODE);
      exit;
    }

    if ($mode === 'invite') {
      $myTeamIndex = findTaskTeamIndexByMember($teams, $sessionWorkId);
      if ($myTeamIndex < 0) {
        echo json_encode(['status' => 'error', 'message' => 'ابتدا یک تیم بسازید یا عضو تیم شوید.']);
        exit;
      }
      $myTeam = is_array($teams[$myTeamIndex] ?? null) ? $teams[$myTeamIndex] : [];
      $targetRaw = trim((string)($payload['targetWorkId'] ?? ($payload['query'] ?? '')));
      if ($targetRaw === '') {
        echo json_encode(['status' => 'error', 'message' => 'کاربر دعوت‌شونده مشخص نیست.']);
        exit;
      }
      $targetWorkId = resolveInviteeWorkIdByCredential($table, $targetRaw);
      if ($targetWorkId === '') {
        echo json_encode(['status' => 'error', 'message' => 'کاربری با این مشخصات پیدا نشد.']);
        exit;
      }
      if (trim($targetWorkId) === trim($sessionWorkId)) {
        echo json_encode(['status' => 'error', 'message' => 'دعوت از خودتان امکان‌پذیر نیست.']);
        exit;
      }
      if (findTaskTeamIndexByMember($teams, $targetWorkId) >= 0) {
        echo json_encode(['status' => 'error', 'message' => 'این کاربر هم‌اکنون عضو یک تیم است.']);
        exit;
      }
      $members = is_array($myTeam['members'] ?? null) ? $myTeam['members'] : [];
      $invites = is_array($myTeam['invites'] ?? null) ? $myTeam['invites'] : [];
      $requests = is_array($myTeam['requests'] ?? null) ? $myTeam['requests'] : [];
      if (in_array($targetWorkId, $invites, true)) {
        echo json_encode(['status' => 'error', 'message' => 'این کاربر قبلا دعوت شده است.']);
        exit;
      }
      if (count($members) + count($invites) >= $teamMax) {
        echo json_encode(['status' => 'error', 'message' => 'ظرفیت تیم برای دعوت تکمیل است.']);
        exit;
      }
      $requests = array_values(array_filter($requests, static fn($item) => trim((string)$item) !== trim($targetWorkId)));
      $invites[] = $targetWorkId;
      $myTeam['invites'] = array_values(array_unique(array_map('strval', $invites)));
      $myTeam['requests'] = array_values(array_unique(array_map('strval', $requests)));
      $teams[$myTeamIndex] = $myTeam;
      $runtimeChanged = true;
      $syncInviteeStatus($targetWorkId);
      $saved = $saveChanges();
      if (!($saved['ok'] ?? false)) {
        echo json_encode(['status' => 'error', 'message' => $saved['message'] ?? 'ذخیره اطلاعات ناموفق بود.']);
        exit;
      }
      $respondWithContext(['message' => 'دعوت‌نامه ارسال شد.']);
    }

    if ($mode === 'remove_member') {
      $targetWorkId = trim((string)($payload['targetWorkId'] ?? ''));
      if ($targetWorkId === '') {
        echo json_encode(['status' => 'error', 'message' => 'کاربر موردنظر مشخص نیست.']);
        exit;
      }
      $myTeamIndex = findTaskTeamIndexByMember($teams, $sessionWorkId);
      if ($myTeamIndex < 0) {
        echo json_encode(['status' => 'error', 'message' => 'شما عضو هیچ تیمی نیستید.']);
        exit;
      }
      $myTeam = is_array($teams[$myTeamIndex] ?? null) ? $teams[$myTeamIndex] : [];
      $isLeader = trim((string)($myTeam['leaderWorkId'] ?? '')) === trim($sessionWorkId);
      if ($isLeader) {
        if (trim($targetWorkId) === trim($sessionWorkId)) {
          echo json_encode(['status' => 'error', 'message' => 'برای حذف تیم از تنظیمات تیم استفاده کنید.']);
          exit;
        }
        $members = is_array($myTeam['members'] ?? null) ? $myTeam['members'] : [];
        $isTargetMember = in_array($targetWorkId, $members, true);
        if ($isTargetMember) {
          $nextMemberCount = max(0, count($members) - 1);
          if ($nextMemberCount < $teamMin) {
            echo json_encode(['status' => 'error', 'message' => 'با حذف این عضو، تعداد اعضا از حداقل مجاز کمتر می‌شود.']);
            exit;
          }
        }
        $removed = false;
        foreach (['members', 'invites', 'requests'] as $bucket) {
          $list = is_array($myTeam[$bucket] ?? null) ? $myTeam[$bucket] : [];
          $next = array_values(array_filter($list, static fn($item) => trim((string)$item) !== trim($targetWorkId)));
          if (count($next) !== count($list)) {
            $removed = true;
            $myTeam[$bucket] = $next;
          }
        }
        if (!$removed) {
          echo json_encode(['status' => 'error', 'message' => 'این کاربر در تیم شما یافت نشد.']);
          exit;
        }
        $teams[$myTeamIndex] = $myTeam;
        $runtimeChanged = true;
        $syncInviteeStatus($targetWorkId);
      } else {
        if ((bool)($myTeam['started'] ?? false)) {
          echo json_encode(['status' => 'error', 'message' => 'پس از شروع چالش، خروج عضو از تیم غیرفعال است و فقط سرگروه می‌تواند اعضا را مدیریت کند.']);
          exit;
        }
        if (trim($targetWorkId) !== trim($sessionWorkId)) {
          echo json_encode(['status' => 'error', 'message' => 'فقط خروج از تیم خودتان مجاز است.']);
          exit;
        }
        $members = is_array($myTeam['members'] ?? null) ? $myTeam['members'] : [];
        $nextMembers = array_values(array_filter($members, static fn($item) => trim((string)$item) !== trim($sessionWorkId)));
        if (count($nextMembers) === count($members)) {
          echo json_encode(['status' => 'error', 'message' => 'وضعیت عضویت شما پیدا نشد.']);
          exit;
        }
        $myTeam['members'] = $nextMembers;
        $teams[$myTeamIndex] = $myTeam;
        $runtimeChanged = true;
        $syncInviteeStatus($sessionWorkId);
      }
      $saved = $saveChanges();
      if (!($saved['ok'] ?? false)) {
        echo json_encode(['status' => 'error', 'message' => $saved['message'] ?? 'ذخیره اطلاعات ناموفق بود.']);
        exit;
      }
      $respondWithContext(['message' => 'تغییرات تیم ثبت شد.']);
    }

    if ($mode === 'find_by_leader') {
      $query = trim((string)($payload['query'] ?? ''));
      if ($query === '') {
        echo json_encode(['status' => 'error', 'message' => 'شماره پرسنلی (Work ID) سرگروه را وارد کنید.']);
        exit;
      }
      $leaderWorkId = resolveInviteeWorkIdByCredential($table, $query);
      if ($leaderWorkId === '') {
        echo json_encode(['status' => 'error', 'message' => 'سرگروهی با این مشخصات پیدا نشد.']);
        exit;
      }
      $teamIndex = -1;
      foreach ($teams as $idx => $team) {
        if (!is_array($team)) {
          continue;
        }
        if (trim((string)($team['leaderWorkId'] ?? '')) === trim($leaderWorkId)) {
          $teamIndex = (int)$idx;
          break;
        }
      }
      if ($teamIndex < 0) {
        echo json_encode(['status' => 'error', 'message' => 'تیمی برای این سرگروه پیدا نشد.']);
        exit;
      }
      $targetTeam = is_array($teams[$teamIndex] ?? null) ? $teams[$teamIndex] : [];
      $summary = buildTeamTaskSummaryPayload($targetTeam, $teamSettings, $sessionWorkId);
      $membersBundle = buildTeamTaskMemberPayload($table, $targetTeam, $taskId);
      $summary['members'] = $membersBundle['members'] ?? [];
      $summary['scoreSubmitted'] = false;
      foreach ((array)($membersBundle['members'] ?? []) as $memberItem) {
        if (!is_array($memberItem)) {
          continue;
        }
        $memberScore = max(0, normalizeTaskScoreValue($memberItem['score'] ?? 0));
        if ($memberScore > 0) {
          $summary['scoreSubmitted'] = true;
          break;
        }
      }
      echo json_encode([
        'status' => 'ok',
        'data' => [
          'team' => $summary
        ]
      ], JSON_UNESCAPED_UNICODE);
      exit;
    }

    if ($mode === 'join') {
      $teamId = trim((string)($payload['teamId'] ?? ''));
      if ($teamId === '') {
        echo json_encode(['status' => 'error', 'message' => 'شناسه تیم نامعتبر است.']);
        exit;
      }
      $memberTeamIndex = findTaskTeamIndexByMember($teams, $sessionWorkId);
      if ($memberTeamIndex >= 0) {
        $currentTeam = $teams[$memberTeamIndex] ?? [];
        if (trim((string)($currentTeam['id'] ?? '')) !== $teamId) {
          echo json_encode(['status' => 'error', 'message' => 'ابتدا از تیم فعلی خارج شوید.']);
          exit;
        }
        $respondWithContext(['message' => 'شما قبلا عضو این تیم شده‌اید.']);
      }
      $teamIndex = findTaskTeamIndexById($teams, $teamId);
      if ($teamIndex < 0) {
        echo json_encode(['status' => 'error', 'message' => 'تیم پیدا نشد.']);
        exit;
      }
      $team = is_array($teams[$teamIndex] ?? null) ? $teams[$teamIndex] : [];
      $members = is_array($team['members'] ?? null) ? $team['members'] : [];
      $invites = is_array($team['invites'] ?? null) ? $team['invites'] : [];
      $requests = is_array($team['requests'] ?? null) ? $team['requests'] : [];
      $joinType = normalizeTeamJoinType((string)($team['joinType'] ?? 'private'));
      if ((bool)($team['started'] ?? false) && $joinType === 'public_open') {
        $joinType = 'public_request';
        $team['joinType'] = 'public_request';
        $teams[$teamIndex] = $team;
        $runtimeChanged = true;
      }
      $joinedNow = false;
      $requestedNow = false;
      if (in_array($sessionWorkId, $invites, true)) {
        if (count($members) >= $teamMax) {
          echo json_encode(['status' => 'error', 'message' => 'ظرفیت تیم تکمیل است.']);
          exit;
        }
        $invites = array_values(array_filter($invites, static fn($item) => trim((string)$item) !== trim($sessionWorkId)));
        $requests = array_values(array_filter($requests, static fn($item) => trim((string)$item) !== trim($sessionWorkId)));
        $members[] = $sessionWorkId;
        $joinedNow = true;
      } elseif ($joinType === 'private') {
        echo json_encode(['status' => 'error', 'message' => 'این تیم خصوصی است و باید دعوت شوید.']);
        exit;
      } elseif ($joinType === 'public_request') {
        if (!in_array($sessionWorkId, $requests, true)) {
          $requests[] = $sessionWorkId;
          $requestedNow = true;
        }
      } else {
        if (count($members) >= $teamMax) {
          echo json_encode(['status' => 'error', 'message' => 'ظرفیت تیم تکمیل است.']);
          exit;
        }
        $members[] = $sessionWorkId;
        $joinedNow = true;
      }
      if ($joinedNow) {
        foreach ($teams as $idx => $otherTeam) {
          if (!is_array($otherTeam)) {
            continue;
          }
          $otherTeam['invites'] = array_values(array_filter((array)($otherTeam['invites'] ?? []), static fn($item) => trim((string)$item) !== trim($sessionWorkId)));
          $otherTeam['requests'] = array_values(array_filter((array)($otherTeam['requests'] ?? []), static fn($item) => trim((string)$item) !== trim($sessionWorkId)));
          if ((int)$idx !== $teamIndex) {
            $otherTeam['members'] = array_values(array_filter((array)($otherTeam['members'] ?? []), static fn($item) => trim((string)$item) !== trim($sessionWorkId)));
          }
          $teams[$idx] = $otherTeam;
        }
      }
      $team['members'] = array_values(array_unique(array_map('strval', $members)));
      $team['invites'] = array_values(array_unique(array_map('strval', $invites)));
      $team['requests'] = array_values(array_unique(array_map('strval', $requests)));
      $teams[$teamIndex] = $team;
      $runtimeChanged = true;
      $syncInviteeStatus($sessionWorkId);
      $saved = $saveChanges();
      if (!($saved['ok'] ?? false)) {
        echo json_encode(['status' => 'error', 'message' => $saved['message'] ?? 'ذخیره اطلاعات ناموفق بود.']);
        exit;
      }
      $respondWithContext([
        'message' => $joinedNow ? 'عضویت شما در تیم ثبت شد.' : ($requestedNow ? 'درخواست عضویت ارسال شد.' : 'وضعیت عضویت شما بدون تغییر است.'),
        'joined' => $joinedNow,
        'requested' => $requestedNow
      ]);
    }

    if ($mode === 'review_request') {
      $teamId = trim((string)($payload['teamId'] ?? ''));
      $targetWorkId = trim((string)($payload['targetWorkId'] ?? ''));
      $decision = strtolower(trim((string)($payload['decision'] ?? 'reject')));
      if ($teamId === '' || $targetWorkId === '') {
        echo json_encode(['status' => 'error', 'message' => 'درخواست بررسی ناقص است.']);
        exit;
      }
      $teamIndex = findTaskTeamIndexById($teams, $teamId);
      if ($teamIndex < 0) {
        echo json_encode(['status' => 'error', 'message' => 'تیم پیدا نشد.']);
        exit;
      }
      $team = is_array($teams[$teamIndex] ?? null) ? $teams[$teamIndex] : [];
      if (trim((string)($team['leaderWorkId'] ?? '')) !== trim($sessionWorkId)) {
        echo json_encode(['status' => 'error', 'message' => 'فقط سرگروه می‌تواند درخواست‌ها را مدیریت کند.']);
        exit;
      }
      $requests = is_array($team['requests'] ?? null) ? $team['requests'] : [];
      if (!in_array($targetWorkId, $requests, true)) {
        echo json_encode(['status' => 'error', 'message' => 'درخواست عضویتی برای این کاربر ثبت نشده است.']);
        exit;
      }
      $team['requests'] = array_values(array_filter($requests, static fn($item) => trim((string)$item) !== trim($targetWorkId)));
      if ($decision === 'accept') {
        $members = is_array($team['members'] ?? null) ? $team['members'] : [];
        $team['invites'] = array_values(array_filter((array)($team['invites'] ?? []), static fn($item) => trim((string)$item) !== trim($targetWorkId)));
        if (count($members) >= $teamMax) {
          echo json_encode(['status' => 'error', 'message' => 'ظرفیت تیم تکمیل است.']);
          exit;
        }
        if (!in_array($targetWorkId, $members, true)) {
          $members[] = $targetWorkId;
        }
        $team['members'] = array_values(array_unique(array_map('strval', $members)));
      }
      $teams[$teamIndex] = $team;
      $runtimeChanged = true;
      $syncInviteeStatus($targetWorkId);
      $saved = $saveChanges();
      if (!($saved['ok'] ?? false)) {
        echo json_encode(['status' => 'error', 'message' => $saved['message'] ?? 'ذخیره اطلاعات ناموفق بود.']);
        exit;
      }
      $respondWithContext(['message' => $decision === 'accept' ? 'درخواست عضویت تایید شد.' : 'درخواست عضویت رد شد.']);
    }

    if ($mode === 'settings') {
      $myTeamIndex = findTaskTeamIndexByMember($teams, $sessionWorkId);
      if ($myTeamIndex < 0) {
        echo json_encode(['status' => 'error', 'message' => 'شما عضو هیچ تیمی نیستید.']);
        exit;
      }
      $team = is_array($teams[$myTeamIndex] ?? null) ? $teams[$myTeamIndex] : [];
      $isLeader = trim((string)($team['leaderWorkId'] ?? '')) === trim($sessionWorkId);
      $teamStarted = (bool)($team['started'] ?? false);
      $asBool = static function ($value): bool {
        $token = strtolower(trim((string)$value));
        return in_array($token, ['1', 'true', 'on', 'yes'], true);
      };
      $deleteTeam = $asBool($payload['deleteTeam'] ?? false);
      if ($deleteTeam) {
        echo json_encode(['status' => 'error', 'message' => 'حذف تیم در این مرحله غیرفعال است.']);
        exit;
      }
      $leaveTeamRequested = $asBool($payload['leaveTeam'] ?? false);
      if ($isLeader && $leaveTeamRequested) {
        echo json_encode(['status' => 'error', 'message' => 'سرگروه امکان خروج از تیم را ندارد.']);
        exit;
      }
      if ($isLeader) {
        $changed = false;
        $nextName = trim((string)($payload['teamName'] ?? ''));
        if ($nextName !== '' && $nextName !== trim((string)($team['name'] ?? ''))) {
          $renameCount = max(0, min(3, (int)($team['renameCount'] ?? 0)));
          if ($renameCount >= 3) {
            echo json_encode(['status' => 'error', 'message' => 'حداکثر ۳ بار امکان تغییر نام تیم وجود دارد.']);
            exit;
          }
          $team['name'] = $nextName;
          $team['renameCount'] = $renameCount + 1;
          $changed = true;
        }
        if (array_key_exists('joinType', $payload)) {
          $nextJoinType = normalizeTeamJoinType((string)($payload['joinType'] ?? 'private'));
          if ($teamStarted && $nextJoinType === 'public_open') {
            $nextJoinType = 'public_request';
          }
          if ($nextJoinType !== normalizeTeamJoinType((string)($team['joinType'] ?? 'private'))) {
            $team['joinType'] = $nextJoinType;
            $changed = true;
          }
        }
        if (!$changed) {
          $respondWithContext(['message' => 'تغییری برای ذخیره وجود ندارد.']);
        }
        $teams[$myTeamIndex] = $team;
        $runtimeChanged = true;
        foreach (collectTeamTaskAffectedUsers([$team]) as $affectedWorkId) {
          $syncInviteeStatus((string)$affectedWorkId);
        }
        $saved = $saveChanges();
        if (!($saved['ok'] ?? false)) {
          echo json_encode(['status' => 'error', 'message' => $saved['message'] ?? 'ذخیره اطلاعات ناموفق بود.']);
          exit;
        }
        $respondWithContext(['message' => 'تنظیمات تیم ذخیره شد.']);
      }

      $leaveTeam = $asBool($payload['leaveTeam'] ?? true);
      if (!$leaveTeam) {
        echo json_encode(['status' => 'error', 'message' => 'گزینه معتبری انتخاب نشده است.']);
        exit;
      }
      if ($teamStarted) {
        echo json_encode(['status' => 'error', 'message' => 'پس از شروع چالش امکان خروج از تیم وجود ندارد.']);
        exit;
      }
      $members = is_array($team['members'] ?? null) ? $team['members'] : [];
      $nextMembers = array_values(array_filter($members, static fn($item) => trim((string)$item) !== trim($sessionWorkId)));
      if (count($nextMembers) === count($members)) {
        echo json_encode(['status' => 'error', 'message' => 'شما عضو تیم نیستید.']);
        exit;
      }
      $team['members'] = $nextMembers;
      $teams[$myTeamIndex] = $team;
      $runtimeChanged = true;
      $syncInviteeStatus($sessionWorkId);
      $saved = $saveChanges();
      if (!($saved['ok'] ?? false)) {
        echo json_encode(['status' => 'error', 'message' => $saved['message'] ?? 'ذخیره اطلاعات ناموفق بود.']);
        exit;
      }
      $respondWithContext(['message' => 'از تیم خارج شدید.']);
    }

    if ($mode === 'start') {
      $myTeamIndex = findTaskTeamIndexByMember($teams, $sessionWorkId);
      if ($myTeamIndex < 0) {
        echo json_encode(['status' => 'error', 'message' => 'شما عضو هیچ تیمی نیستید.']);
        exit;
      }
      $team = is_array($teams[$myTeamIndex] ?? null) ? $teams[$myTeamIndex] : [];
      if (trim((string)($team['leaderWorkId'] ?? '')) !== trim($sessionWorkId)) {
        echo json_encode(['status' => 'error', 'message' => 'فقط سرگروه می‌تواند چالش را شروع کند.']);
        exit;
      }
      $members = is_array($team['members'] ?? null) ? $team['members'] : [];
      if (count($members) < $teamMin) {
        echo json_encode(['status' => 'error', 'message' => 'تعداد اعضای تیم هنوز به حداقل نرسیده است.']);
        exit;
      }
      if (!(bool)($team['started'] ?? false)) {
        $challenges = readTaskTeamChallenges(TASKS_DIR_PATH, $tagCode);
        $availableIndexes = [];
        foreach ($challenges as $index => $challenge) {
          if (!is_array($challenge)) {
            continue;
          }
          $last = max(0, (int)($challenge['last'] ?? 0));
          if ($last > 0) {
            $availableIndexes[] = (int)$index;
          }
        }
        if (!$availableIndexes) {
          echo json_encode(['status' => 'error', 'message' => 'چالشی با ظرفیت باقی‌مانده برای این ماموریت تعریف نشده است.']);
          exit;
        }
        $selectedIndex = $availableIndexes[array_rand($availableIndexes)];
        $selectedChallenge = is_array($challenges[$selectedIndex] ?? null) ? $challenges[$selectedIndex] : [];
        $challengeLast = max(0, (int)($selectedChallenge['last'] ?? 0));
        $challenges[$selectedIndex]['last'] = max(0, $challengeLast - 1);
        if (!saveTaskTeamChallenges(TASKS_DIR_PATH, $tagCode, $challenges)) {
          echo json_encode(['status' => 'error', 'message' => 'به‌روزرسانی ظرفیت چالش ناموفق بود.']);
          exit;
        }
        $team['started'] = true;
        $team['startedAt'] = date('Y-m-d H:i:s');
        if (normalizeTeamJoinType((string)($team['joinType'] ?? 'private')) === 'public_open') {
          $team['joinType'] = 'public_request';
        }
        $team['challengeId'] = trim((string)($selectedChallenge['id'] ?? ''));
        $team['challengeName'] = trim((string)($selectedChallenge['name'] ?? ''));
        $team['challengeGuide'] = trim((string)($selectedChallenge['guide'] ?? ''));
        $teams[$myTeamIndex] = $team;
        $runtimeChanged = true;
      } elseif (normalizeTeamJoinType((string)($team['joinType'] ?? 'private')) === 'public_open') {
        $team['joinType'] = 'public_request';
        $teams[$myTeamIndex] = $team;
        $runtimeChanged = true;
      }
      foreach ((array)($team['members'] ?? []) as $memberWorkId) {
        $memberToken = trim((string)$memberWorkId);
        if ($memberToken === '') {
          continue;
        }
        $syncInviteeStatus($memberToken);
      }
      $saved = $saveChanges();
      if (!($saved['ok'] ?? false)) {
        echo json_encode(['status' => 'error', 'message' => $saved['message'] ?? 'ذخیره اطلاعات ناموفق بود.']);
        exit;
      }
      $guideText = buildTeamTaskGuideText(
        (string)($infoSettings['guidePrefix'] ?? ''),
        (string)($team['challengeGuide'] ?? ''),
        (string)($infoSettings['guideSuffix'] ?? '')
      );
      $respondWithContext([
        'message' => 'چالش تیمی شروع شد.',
        'guideText' => $guideText,
        'challengeName' => (string)($team['challengeName'] ?? '')
      ]);
    }

    echo json_encode(['status' => 'error', 'message' => 'عملیات تیمی نامعتبر است.']);
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
    $sessionIsAdmin = isInviteeAdmin($inviteesFilePath, $inviteesMapPath, $sessionWorkId);
    if (!canUserAccessTaskByRole($task, $sessionIsAdmin)) {
      echo json_encode(['status' => 'error', 'message' => 'این ماموریت در فاز توسعه است و فقط برای ادمین‌ها قابل مشاهده است.']);
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
$sessionFirstName = 'کاربر';
$sessionPrizeWon = '';
$sessionPrizeWonAt = null;
$sessionWheelAngle = null;
$sessionQuizOrder = [];
$sessionAnswered = 0;
$sessionIsAdmin = false;
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
    $sessionIsAdmin = isInviteeAdminFromTable($table, $sessionWorkId);
    $sessionFullName = resolveInviteeFullName($table['header'] ?? [], $table['mapping'] ?? [], $rows[$rowIndex] ?? [], $sessionWorkId);
    $resolvedFirstName = resolveInviteeFirstName($table['header'] ?? [], $table['mapping'] ?? [], $rows[$rowIndex] ?? [], $sessionFullName);
    if ($resolvedFirstName !== '') {
      $sessionFirstName = $resolvedFirstName;
    }
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
$taskItemsForView = buildTaskPayloadForView(
  $taskRecords,
  $inviteesFilePath,
  $inviteesMapPath,
  $sessionAuthed ? $sessionWorkId : '',
  $sessionIsAdmin
);
$sessionTaskTotalScore = ($sessionAuthed && $sessionWorkId !== '')
  ? computeUserTotalTaskScore($inviteesFilePath, $inviteesMapPath, $sessionWorkId)
  : 0;
if ($sessionFirstName === '') {
  $sessionFirstName = 'کاربر';
}
$tcqSettingsForPayload = loadWfqSettings($tcqSettingsPath);
$sessionPayload = [
  'authed' => $sessionAuthed,
  'workId' => $sessionWorkId,
  'isAdmin' => $sessionIsAdmin,
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
    <title>کمپین به‌نام‌خدا</title>
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
          radial-gradient(circle at top right, color-mix(in srgb, var(--tc-highlight) 20%, transparent), transparent 46%),
          radial-gradient(circle at bottom left, color-mix(in srgb, var(--tc-secondary) 18%, transparent), transparent 45%),
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
        text-decoration: none;
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
        color: #111111;
        letter-spacing: 0.04em;
      }

      .brand-greeting {
        display: inline-flex;
        align-items: center;
        gap: 2px;
      }

      .brand-name {
        color: var(--tc-secondary);
        font-weight: 700;
      }

      .brand-text {
        color: #111111;
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

      .task-item-meta.has-score-block {
        white-space: normal;
      }

      .task-item-meta .task-meta-main {
        display: block;
        white-space: pre-line;
        line-height: 1.55;
      }

      .task-item-meta .task-meta-score {
        display: block;
        margin-top: 6px;
        padding-top: 6px;
        border-top: 1px dashed rgba(111, 127, 159, 0.48);
        font-weight: 800;
        color: #1f4d91;
      }

      .task-item-meta.is-score-pending .task-meta-score {
        color: #6d5a2a;
        font-weight: 700;
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

      .task-item-btn.is-info-ended,
      .task-item-btn.is-info-ended:disabled {
        background: #fff1f3;
        border-color: #f4c2cb;
        color: #a52d3f;
        cursor: not-allowed;
      }

      .task-item-btn.is-info-ended .task-item-meta,
      .task-item-btn.is-info-ended:disabled .task-item-meta {
        color: #bf4a5d;
      }

      .task-item-btn.is-completed {
        background: #eef9f1;
        border-color: #c7e9d0;
        color: #2f5f3c;
      }

      .task-item-btn.is-completed .task-item-meta {
        color: #4d7a58;
      }

      .task-item-btn.is-describe-submitted {
        background: linear-gradient(145deg, #eef6ff, #e6f0ff);
        border-color: #a7c7ff;
        color: #1f4d91;
      }

      .task-item-btn.is-describe-submitted .task-item-meta {
        color: #2f5b9f;
        font-weight: 700;
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

      .task-item-btn.is-golden .task-item-meta .task-meta-score,
      .task-item-btn.is-golden-live .task-item-meta .task-meta-score {
        color: #9b5a00;
        border-top-color: rgba(155, 90, 0, 0.35);
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
        padding: 6px 14px 16px 2px;
        scrollbar-width: none;
      }

      .roadmap-list::-webkit-scrollbar {
        display: none;
      }

      .roadmap-item {
        position: relative;
        padding: 0 22px 16px 0;
        overflow: visible;
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
        animation: roadmapClaimPulse 1.9s cubic-bezier(0.33, 0, 0.2, 1) infinite;
        will-change: transform, box-shadow;
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

      @keyframes roadmapClaimPulse {
        0% {
          box-shadow: 0 11px 20px rgba(255, 174, 57, 0.2);
          transform: scale(1);
        }
        50% {
          box-shadow: 0 13px 23px rgba(255, 174, 57, 0.25);
          transform: scale(1.006);
        }
        100% {
          box-shadow: 0 11px 20px rgba(255, 174, 57, 0.2);
          transform: scale(1);
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
        min-height: 0;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        gap: 14px;
        padding: 16px 18px;
        position: relative;
        overflow-y: auto;
        overflow-x: hidden;
        -webkit-overflow-scrolling: touch;
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
        min-height: 0;
        display: flex;
        flex-direction: column;
        justify-content: flex-start;
        gap: 20px;
        padding: 22px 22px calc(24px + env(safe-area-inset-bottom, 0px));
        overflow-y: auto;
        overflow-x: hidden;
        -webkit-overflow-scrolling: touch;
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

      .info-task-area {
        flex: 1;
        width: 100%;
        min-height: 0;
        display: flex;
        flex-direction: column;
        gap: 12px;
        padding: 16px 16px calc(22px + env(safe-area-inset-bottom, 0px));
        overflow-y: auto;
        overflow-x: hidden;
        -webkit-overflow-scrolling: touch;
      }

      .info-task-head {
        display: grid;
        gap: 6px;
      }

      .tc-task-info-title {
        margin: 0;
        text-align: center;
        color: #1f3560;
        font-size: 1.02rem;
        font-weight: 700;
      }

      .info-task-content {
        flex: 1;
        overflow: auto;
        display: grid;
        gap: 10px;
        padding-inline: 2px;
      }

      .info-task-section {
        border: 1px solid #dbe7fb;
        border-radius: 14px;
        background: #f8fbff;
        padding: 12px;
      }

      .info-task-section h3 {
        margin: 0 0 6px;
        font-size: 0.95rem;
        color: #1f3560;
      }

      .info-task-section p {
        margin: 0;
        color: #4a5e86;
        line-height: 1.8;
        white-space: pre-wrap;
      }

      .info-task-section.info-task-rich p {
        margin: 0;
        white-space: normal;
      }

      .info-task-section.info-task-rich p + p {
        margin-top: 10px;
      }

      .info-task-section.info-task-rich ul,
      .info-task-section.info-task-rich ol {
        margin: 8px 0 0;
        padding-inline-start: 20px;
        color: #4a5e86;
      }

      .info-task-section.info-task-rich li + li {
        margin-top: 4px;
      }

      .info-task-section.info-task-rich h1,
      .info-task-section.info-task-rich h2,
      .info-task-section.info-task-rich h3,
      .info-task-section.info-task-rich h4,
      .info-task-section.info-task-rich h5,
      .info-task-section.info-task-rich h6 {
        margin: 0 0 8px;
        color: #1f3560;
      }

      .info-task-ack {
        width: 100%;
      }

      .describe-photo-step,
      .describe-photo-editor {
        flex: 1;
        min-height: 0;
        display: flex;
        flex-direction: column;
        gap: 10px;
        overflow-y: auto;
        overflow-x: hidden;
        padding-bottom: calc(8px + env(safe-area-inset-bottom, 0px));
      }

      .describe-photo-preview {
        border: 1px solid #dbe7fb;
        border-radius: 16px;
        background: #f8fbff;
        min-height: 190px;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
      }

      .describe-photo-image {
        width: 100%;
        height: 100%;
        max-height: 260px;
        object-fit: contain;
        display: block;
      }

      .describe-photo-image.is-hidden {
        display: none;
      }

      .describe-photo-editor-preview {
        min-height: 140px;
      }

      .describe-photo-editor-preview .describe-photo-image {
        max-height: 180px;
      }

      .describe-photo-name,
      .describe-photo-index {
        margin: 0;
        text-align: center;
        color: #435c86;
        font-size: 0.88rem;
      }

      .describe-photo-index {
        color: #5e7399;
      }

      .describe-photo-actions {
        margin-top: auto;
        display: grid;
        gap: 8px;
      }

      .describe-photo-btn {
        width: 100%;
      }

      .describe-photo-btn.secondary {
        background: #eef4ff;
        color: #2f4f88;
        border: 1px solid #c6d7fb;
      }

      .describe-photo-editor-title {
        margin: 0;
        text-align: center;
        color: #2a3f69;
        font-weight: 700;
      }

      .describe-photo-textarea {
        flex: 1;
        min-height: 180px;
        border: 1px solid #d8e2f4;
        border-radius: 14px;
        padding: 10px 12px;
        resize: vertical;
        font: inherit;
        background: #f8fbff;
        color: #263b62;
        line-height: 1.8;
      }

      .describe-photo-word-count {
        margin: 0;
        text-align: left;
        color: #5e7399;
        font-size: 0.82rem;
      }

      .describe-photo-word-count.is-error {
        color: #c03a43;
        font-weight: 700;
      }

      .describe-photo-save-btn {
        width: 100%;
      }

      .team-task-step {
        flex: 1;
        min-height: 0;
        display: flex;
        flex-direction: column;
        gap: 10px;
        overflow-y: auto;
        overflow-x: hidden;
        padding-bottom: calc(8px + env(safe-area-inset-bottom, 0px));
      }

      #tc-team-preview-step {
        overflow: hidden;
      }

      #tc-team-preview-members {
        flex: 1;
        min-height: 0;
        overflow-y: auto;
        overflow-x: hidden;
        -webkit-overflow-scrolling: touch;
        align-content: start;
        grid-auto-rows: max-content;
      }

      .team-preview-actions {
        margin-top: 0;
      }

      .team-find-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
      }

      .team-find-additional-text {
        margin: 0;
        padding: 8px 10px;
        border-radius: 10px;
        border: 1px solid #dbe6f8;
        background: #f5f8ff;
        color: #5f759d;
        font-size: 0.78rem;
        line-height: 1.8;
        white-space: normal;
      }

      .team-find-additional-text p,
      .team-find-additional-text h1,
      .team-find-additional-text h2,
      .team-find-additional-text h3,
      .team-find-additional-text h4,
      .team-find-additional-text h5,
      .team-find-additional-text h6 {
        margin: 0;
      }

      .team-find-additional-text p + p {
        margin-top: 6px;
      }

      .team-find-additional-text ul,
      .team-find-additional-text ol {
        margin: 8px 0 0;
        padding-inline-start: 18px;
      }

      #tc-team-rules-text {
        margin: 0;
        color: #4a5e86;
        line-height: 1.8;
      }

      #tc-team-rules-text .team-rules-note {
        margin-top: 8px;
        padding-top: 8px;
        border-top: 1px dashed #d5e2f8;
      }

      #tc-team-rules-text p,
      #tc-team-rules-text h1,
      #tc-team-rules-text h2,
      #tc-team-rules-text h3,
      #tc-team-rules-text h4,
      #tc-team-rules-text h5,
      #tc-team-rules-text h6 {
        margin: 0;
      }

      #tc-team-rules-text p + p {
        margin-top: 6px;
      }

      #tc-team-rules-text ul,
      #tc-team-rules-text ol {
        margin: 8px 0 0;
        padding-inline-start: 18px;
      }

      .team-task-actions {
        display: grid;
        gap: 8px;
        margin-top: auto;
        grid-template-columns: 1fr;
      }

      .team-task-actions--sticky {
        position: sticky;
        bottom: 0;
        z-index: 5;
        padding-top: 10px;
        padding-bottom: calc(8px + env(safe-area-inset-bottom, 0px));
        background: linear-gradient(180deg, rgba(248, 251, 255, 0) 0%, #f8fbff 32%, #f8fbff 100%);
      }

      .team-room-top-actions {
        display: grid;
        grid-template-columns: 3fr 1fr;
        gap: 8px;
      }

      .team-room-top-actions--single {
        grid-template-columns: 1fr;
      }

      .team-room-invite-btn {
        background: var(--tc-highlight);
        color: #ffffff;
      }

      .team-room-invite-btn:disabled {
        background: #d4dfef;
        color: #6c7b98;
      }

      .team-settings-icon-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 10px;
      }

      .team-settings-icon {
        display: inline-flex;
        width: 20px;
        height: 20px;
        color: #2f4f88;
      }

      .team-settings-icon svg {
        width: 100%;
        height: 100%;
        fill: currentColor;
      }

      .team-list-title {
        margin: 0;
        color: #355286;
        font-size: 0.85rem;
        font-weight: 700;
      }

      .team-list-group {
        display: grid;
        gap: 9px;
      }

      .team-groups-hint {
        margin: 2px 0 0;
        color: #5d7399;
        font-size: 0.8rem;
        font-weight: 700;
      }

      .team-list-group--invited .team-list-title {
        color: #1f3f77;
      }

      .team-list {
        display: grid;
        gap: 9px;
      }

      .team-list--invited .team-list-item-btn {
        border-color: #b7cdf9;
        background: #eef5ff;
        box-shadow: 0 14px 22px rgba(36, 74, 140, 0.13);
      }

      .team-list--public .team-list-item-btn {
        border-color: #d8e4f7;
        background: #fbfdff;
      }

      .team-list-item {
        border: 1px solid #d8e3f7;
        background: #f8fbff;
        border-radius: 12px;
        padding: 10px 12px;
        display: grid;
        gap: 4px;
        text-align: right;
      }

      .team-list-item-title {
        color: #213b6b;
        font-weight: 700;
        font-size: 0.9rem;
      }

      .team-list-item-meta {
        color: #5670a1;
        font-size: 0.8rem;
      }

      .team-list-item-btn {
        width: 100%;
        text-align: right;
        border: 1px solid #cad9f5;
        background: #ffffff;
        border-radius: 14px;
        padding: 12px;
        color: #2c4f87;
        font: inherit;
        display: grid;
        gap: 6px;
        position: relative;
      }

      .team-list-item-btn strong {
        display: block;
        color: #1c3a6e;
        font-size: 0.92rem;
      }

      .team-entity-title {
        display: inline-flex;
        align-items: center;
        gap: 8px;
      }

      .team-entity-icon {
        width: 20px;
        height: 20px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #c7d8f6;
        background: #eef4ff;
        color: #47618f;
        flex: 0 0 20px;
      }

      .team-entity-icon i {
        font-size: 0.56rem;
        display: block;
        line-height: 1;
        transform: scale(1);
        transform-origin: center;
        font-weight: 400;
        opacity: 0.92;
      }

      .team-entity-icon--member {
        background: #f2f7ff;
        color: #355788;
      }

      .team-entity-icon--invite {
        background: #fff8ea;
        color: #b86e1f;
        border-color: #efd5ae;
      }

      .team-entity-icon--request {
        background: #f7f0ff;
        color: #6f4a9a;
        border-color: #dccbf2;
      }

      .team-entity-icon--team-private {
        background: #fff1f2;
        color: #c23a44;
        border-color: #f0c6cb;
      }

      .team-entity-icon--team-public-request {
        background: #fff6e9;
        color: #b86e1f;
        border-color: #efd5ae;
      }

      .team-entity-icon--team-public-open {
        background: #edf9f2;
        color: #2e8d58;
        border-color: #cae8d7;
      }

      .team-entity-icon--team-full {
        background: #fff1f2;
        color: #c23a44;
        border-color: #f0c6cb;
      }

      .team-entity-icon--team-started {
        background: #edf4ff;
        color: #2f63ba;
        border-color: #c8daf8;
      }

      .team-entity-icon--team-completed {
        background: #edf9f2;
        color: #2e8d58;
        border-color: #cae8d7;
      }

      .team-list-item-leader {
        display: block;
        color: #4f6792;
        font-size: 0.78rem;
      }

      .team-list-item-hint {
        display: block;
        color: #7085ab;
        font-size: 0.72rem;
      }

      .team-list-item-meta-row {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
      }

      .team-list-item-chip {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 2px 8px;
        border-radius: 999px;
        border: 1px solid #c9d9f6;
        background: #ffffff;
        color: #385889;
        font-size: 0.74rem;
        font-weight: 700;
      }

      .team-list-item-chip--join {
        border-color: #cddcf7;
        background: #f2f7ff;
        color: #4b6290;
      }

      .team-list-separator {
        width: 100%;
        height: 1px;
        background: linear-gradient(90deg, rgba(140, 160, 196, 0.18) 0%, rgba(140, 160, 196, 0.6) 50%, rgba(140, 160, 196, 0.18) 100%);
        margin: 2px 0 4px;
      }

      .team-join-type-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
      }

      .team-join-type-badge i {
        font-size: 0.92rem;
        line-height: 1;
      }

      .team-join-type-badge--private {
        border-color: #f0c6cb;
        background: #fff1f2;
        color: #c23a44;
      }

      .team-join-type-badge--public-request {
        border-color: #efd5ae;
        background: #fff8ea;
        color: #b86e1f;
      }

      .team-join-type-badge--public-open {
        border-color: #cae8d7;
        background: #edf9f2;
        color: #2e8d58;
      }

      .team-join-type-badge--full {
        border-color: #efc7cb;
        background: #fff1f2;
        color: #c23a44;
      }

      .team-join-type-badge--started {
        border-color: #c8daf8;
        background: #edf4ff;
        color: #2f63ba;
      }

      .team-join-type-badge--completed {
        border-color: #cae8d7;
        background: #edf9f2;
        color: #2e8d58;
      }

      .team-list-item-invited-tag {
        position: absolute;
        top: -9px;
        inset-inline-end: 10px;
        background: var(--tc-highlight);
        color: #fff;
        border-radius: 999px;
        padding: 2px 8px;
        font-size: 0.68rem;
        font-weight: 700;
        box-shadow: 0 8px 16px rgba(37, 73, 136, 0.2);
      }

      .team-members-grid {
        display: grid;
        gap: 12px;
      }

      .team-member-group {
        display: grid;
        gap: 8px;
      }

      .team-member-group-title {
        margin: 0;
        color: #355286;
        font-size: 0.82rem;
        font-weight: 700;
      }

      .team-member-group--members .team-member-group-title {
        color: #1f3f77;
      }

      .team-member-group--invites .team-member-group-title {
        color: #8b5f15;
      }

      .team-member-group--requests .team-member-group-title {
        color: #6c4f90;
      }

      .team-member-chip {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        border: 1px solid #d9e4f8;
        border-radius: 12px;
        background: #f8fbff;
        padding: 9px 10px;
      }

      .team-member-chip--leader,
      .team-member-chip--member {
        border-color: #cdddf8;
        background: #f5f9ff;
      }

      .team-member-chip--invited {
        border-color: #f2d8a6;
        background: #fffaf0;
      }

      .team-member-chip--request {
        border-color: #ddccf3;
        background: #f9f5ff;
      }

      .team-member-text {
        display: grid;
        gap: 2px;
      }

      .team-member-name {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        color: #1f3560;
        font-size: 0.86rem;
        font-weight: 700;
      }

      .team-member-name-label {
        display: inline-block;
      }

      .team-member-status {
        color: #5d739a;
        font-size: 0.78rem;
        font-weight: 700;
      }

      .team-member-workid {
        color: #7085ab;
        font-size: 0.72rem;
      }

      .team-member-remove {
        border: 1px solid #d5e2f8;
        border-radius: 10px;
        background: #ffffff;
        color: #355487;
        font-size: 0.9rem;
        font-weight: 700;
        cursor: pointer;
        width: 34px;
        height: 34px;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
      }

      .team-member-remove i {
        font-size: 1.04rem;
        line-height: 1;
      }

      .team-member-remove:hover {
        background: #f3f8ff;
      }

      .team-member-remove--danger {
        border-color: #efc7cb;
        color: #c7363e;
        background: #fff4f5;
      }

      .team-member-remove--accept {
        border-color: #c7e7d3;
        color: #1f8a4b;
        background: #f2fff6;
      }

      .team-member-review-actions {
        display: inline-flex;
        align-items: center;
        gap: 6px;
      }

      .team-member-review-actions .team-member-remove--accept {
        border-color: #c7e7d3;
        color: #1f8a4b;
        background: #f2fff6;
      }

      .team-member-review-actions .team-member-remove[data-team-review-decision="reject"] {
        border-color: #efc7cb;
        color: #c7363e;
        background: #fff4f5;
      }

      .team-invite-dialog {
        width: min(460px, calc(100% - 26px));
      }

      .team-invite-dialog-content {
        display: grid;
        gap: 8px;
        width: 100%;
        align-items: stretch;
        justify-content: flex-start;
      }

      .team-invite-dialog-content .login-field,
      .team-invite-dialog-content .login-input,
      .team-invite-dialog-content .login-btn,
      .team-invite-dialog-content .tc-result-dialog-confirm,
      .team-invite-dialog-content .team-invite-result {
        width: 100%;
      }

      .team-invite-result {
        border: 1px solid #ccdbf8;
        border-radius: 10px;
        background: #fff;
        padding: 10px;
        display: grid;
        gap: 7px;
        box-sizing: border-box;
      }

      .team-invite-result-name {
        margin: 0;
        color: #1f3560;
        font-size: 0.86rem;
        display: flex;
        align-items: center;
        gap: 8px;
        width: 100%;
      }

      .team-invite-result-text {
        display: grid;
        gap: 2px;
      }

      .team-list-item-title {
        display: inline-flex;
        align-items: center;
        gap: 8px;
      }

      .team-field-hint {
        color: #627aa6;
        font-size: 0.74rem;
        line-height: 1.7;
      }

      .team-join-type-list {
        display: grid;
        gap: 8px;
      }

      .team-join-type-option {
        border: 1px solid #cad9f4;
        border-radius: 12px;
        background: #f8fbff;
        padding: 10px 12px;
        display: flex;
        align-items: flex-start;
        gap: 10px;
        text-align: right;
      }

      .team-join-type-option input[type="radio"] {
        margin-top: 3px;
        accent-color: var(--accent);
        inline-size: 16px;
        block-size: 16px;
      }

      .team-join-type-text {
        display: grid;
        gap: 4px;
        color: #2a4a82;
      }

      .team-join-type-text strong {
        font-size: 0.86rem;
      }

      .team-join-type-text small {
        font-size: 0.78rem;
        color: #5f759f;
        line-height: 1.65;
      }

      .team-join-type-option input[type="radio"]:checked + .team-join-type-text strong {
        color: #1a3c75;
      }

      .team-meta-badges {
        margin: 0;
        display: flex;
        justify-content: center;
        flex-wrap: wrap;
        gap: 6px;
      }

      .team-meta-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        border: 1px solid #cfddf6;
        background: #f4f8ff;
        color: #37588a;
        font-size: 0.76rem;
        font-weight: 700;
        padding: 4px 10px;
      }

      .team-meta-badge.team-join-type-badge--private {
        border-color: #f0c6cb;
        background: #fff1f2;
        color: #c23a44;
      }

      .team-meta-badge.team-join-type-badge--public-request {
        border-color: #efd5ae;
        background: #fff8ea;
        color: #b86e1f;
      }

      .team-meta-badge.team-join-type-badge--public-open {
        border-color: #cae8d7;
        background: #edf9f2;
        color: #2e8d58;
      }

      .team-meta-badge.team-join-type-badge--started {
        border-color: #c8daf8;
        background: #edf4ff;
        color: #2f63ba;
      }

      .team-meta-badge.team-join-type-badge--completed {
        border-color: #cae8d7;
        background: #edf9f2;
        color: #2e8d58;
      }

      .team-room-slot-badge {
        margin-inline: auto;
        width: fit-content;
        border: 1px solid #cfddf6;
        border-radius: 999px;
        background: #f4f8ff;
        color: #2d4f85;
        font-size: 0.8rem;
        font-weight: 700;
        padding: 5px 12px;
      }

      .team-room-slot-badge--need-members {
        border-color: #f0c6cb;
        background: #fff1f2;
        color: #c23a44;
      }

      .team-room-slot-badge--min-reached {
        border-color: #efd5ae;
        background: #fff8ea;
        color: #b86e1f;
      }

      .team-room-slot-badge--max-reached {
        border-color: #cae8d7;
        background: #edf9f2;
        color: #2e8d58;
      }

      .login-btn.team-danger-btn {
        width: 100%;
        background: #d5434d;
        color: #fff;
        border: 1px solid #b6363e;
      }

      .login-btn.team-danger-btn:hover {
        background: #bf333d;
        color: #fff;
      }

      .login-btn.team-danger-btn:disabled {
        background: #d5434d;
        color: #fff;
        opacity: 0.72;
      }

      .tc-confirm-dialog-actions {
        width: 100%;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-top: auto;
      }

      .tc-confirm-dialog-actions .tc-result-dialog-confirm {
        margin-top: 0;
      }

      .tc-confirm-dialog-cancel {
        background: #eef3fb;
        color: #2c4b7f;
        border: 1px solid #cfdbf1;
      }

      .tc-confirm-dialog-danger {
        background: #d5434d;
        color: #fff;
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

      .login-help-card {
        border: 1px solid #e3ecfa;
        border-radius: 14px;
        background: #f8fbff;
        padding: 10px 12px;
        display: grid;
        gap: 6px;
      }

      .login-help-title {
        margin: 0;
        font-size: 0.82rem;
        font-weight: 700;
        color: #2c446f;
      }

      .login-help-text {
        margin: 0;
        font-size: 0.78rem;
        line-height: 1.9;
        color: #5a6f95;
      }

      .login-help-id {
        color: var(--tc-secondary);
        font-weight: 700;
        direction: ltr;
        unicode-bidi: plaintext;
        display: inline-block;
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

      .tc-result-dialog .hint {
        font-size: 1rem;
        line-height: 1.7;
        color: #243a63;
      }

      #tc-info-dialog-message {
        white-space: pre-line;
        font-size: 1.04rem;
        font-weight: 600;
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

        .login-area {
          padding: 18px 16px calc(20px + env(safe-area-inset-bottom, 0px));
          gap: 14px;
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
            <span class="brand-greeting">
              <span class="brand-name"><?= htmlspecialchars($sessionFirstName, ENT_QUOTES, 'UTF-8') ?></span>
              <span class="brand-text">عزیز، خوش آمدید</span>
            </span>
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
            <?php else: ?>
              <a class="logout-btn" href="index.php">
                <span aria-hidden="true"></span>
                توضیحات
              </a>
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
            <h2 class="login-title">کمپین «به نام خدا»</h2>
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
          <section class="login-help-card" aria-label="راهنمای ورود و پشتیبانی">
            <p class="login-help-title">راهنمای ورود و پشتیبانی</p>
            <p class="login-help-text">
              نام کاربری و رمز عبور اختصاصی هر شخص از طریق پیامک ارسال شده است و امکان استفاده مشترک وجود ندارد.
            </p>
            <p class="login-help-text">
              اگر در ورود مشکل دارید، در روبیکا به آیدی <span class="login-help-id">@ero_admin</span> پیام دهید.
            </p>
          </section>
        </div>
      <?php else: ?>
        <div id="tc-timer-area" class="main-area">
          <?php if ($eventLogoUrl !== ''): ?>
            <img class="task-event-logo" src="<?= htmlspecialchars($eventLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="لوگوی رویداد" />
          <?php endif; ?>
          <h2 id="tc-tasks-title" class="tasks-title">چالش‌های کمپین «به نام خدا»</h2>
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
                  $isDescribeSubmitted = (bool)($taskItem['describeSubmitted'] ?? false);
                  $isTeamStartedPending = (bool)($taskItem['teamStartedPending'] ?? false);
                  $taskTypeToken = (string)($taskItem['taskType'] ?? 'quiz');
                  $taskStatusToken = (string)($taskItem['status'] ?? 'inactive');
                  $buttonClass = 'task-item-btn';
                  if (!$isAvailable) {
                    $buttonClass .= ' is-disabled';
                  }
                  if ($isCompleted) {
                    $buttonClass .= ' is-completed';
                  } elseif (
                    ($taskTypeToken === 'describe_photo' && $taskStatusToken === 'active' && $isDescribeSubmitted)
                    || ($taskTypeToken === 'team_task' && $taskStatusToken === 'active' && $isTeamStartedPending)
                  ) {
                    $buttonClass .= ' is-describe-submitted';
                  }
                  $disabledAttr = $isAvailable ? '' : 'disabled';
                ?>
                <button
                  class="<?= htmlspecialchars($buttonClass, ENT_QUOTES, 'UTF-8') ?>"
                  type="button"
                  data-task-id="<?= htmlspecialchars($taskId, ENT_QUOTES, 'UTF-8') ?>"
                  data-task-title="<?= htmlspecialchars($taskTitle, ENT_QUOTES, 'UTF-8') ?>"
                  data-task-type="<?= htmlspecialchars($taskTypeToken, ENT_QUOTES, 'UTF-8') ?>"
                  data-task-active="<?= !empty($taskItem['active']) ? '1' : '0' ?>"
                  data-task-duration="<?= !empty($taskItem['duration']) ? '1' : '0' ?>"
                  data-task-start-date="<?= htmlspecialchars((string)($taskItem['startDate'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                  data-task-start-time="<?= htmlspecialchars((string)($taskItem['startTime'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                  data-task-end-date="<?= htmlspecialchars((string)($taskItem['endDate'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                  data-task-end-time="<?= htmlspecialchars((string)($taskItem['endTime'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                  data-task-score="<?= (int)($taskItem['score'] ?? 0) ?>"
                  data-task-after-end-score="<?= (int)($taskItem['afterEndtimeScore'] ?? 0) ?>"
                  data-task-status="<?= htmlspecialchars($taskStatusToken, ENT_QUOTES, 'UTF-8') ?>"
                  data-task-completed="<?= $isCompleted ? '1' : '0' ?>"
                  data-task-describe-submitted="<?= $isDescribeSubmitted ? '1' : '0' ?>"
                  data-task-team-started-pending="<?= $isTeamStartedPending ? '1' : '0' ?>"
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
          <h2 id="tc-rewards-title" class="tasks-title">خوان‌های جوایز</h2>
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
        <div id="tc-task-info-area" class="info-task-area quiz-hidden">
          <div id="tc-task-info-head" class="info-task-head">
            <h3 id="tc-task-info-title" class="tc-task-info-title">اطلاعات ماموریت</h3>
          </div>
          <div id="tc-task-info-content" class="info-task-content"></div>
          <section id="tc-team-rules-step" class="team-task-step hidden">
            <div class="info-task-section team-task-rules-card">
              <h3>قوانین تیم</h3>
              <div id="tc-team-rules-text">برای این ماموریت تیمی ابتدا تیم خود را بسازید یا به یک تیم ملحق شوید.</div>
            </div>
            <div class="team-task-actions">
              <button id="tc-team-create-btn" class="login-btn describe-photo-btn" type="button">ساخت تیم</button>
            </div>
          </section>
          <section id="tc-team-create-name-step" class="team-task-step hidden">
            <label class="login-field">
              <span>نام تیم را انتخاب کنید</span>
              <input id="tc-team-create-name-input" class="login-input" type="text" maxlength="80" placeholder="نام تیم" />
            </label>
            <button id="tc-team-create-name-confirm" class="login-btn describe-photo-btn" type="button">تایید نام</button>
          </section>
          <section id="tc-team-create-type-step" class="team-task-step hidden">
            <p class="team-list-title">نوع تیم را انتخاب کنید</p>
            <div class="team-join-type-list">
              <label class="team-join-type-option">
                <input type="radio" name="tc-team-create-join-type" value="private" checked />
                <span class="team-join-type-text">
                  <strong>تیم خصوصی</strong>
                  <small>فقط افرادی که دعوت می‌کنید می‌توانند ملحق شوند</small>
                </span>
              </label>
              <label class="team-join-type-option">
                <input type="radio" name="tc-team-create-join-type" value="public_request" />
                <span class="team-join-type-text">
                  <strong>ورود فقط با درخواست</strong>
                  <small>در لیست تیم‌ها نمایش داده می‌شود و دیگران می‌توانند درخواست عضویت بفرستند</small>
                </span>
              </label>
              <label class="team-join-type-option">
                <input type="radio" name="tc-team-create-join-type" value="public_open" />
                <span class="team-join-type-text">
                  <strong>ورود آزاد</strong>
                  <small>هر کسی می‌تواند تیم را ببیند و مستقیم وارد شود</small>
                </span>
              </label>
            </div>
            <button id="tc-team-create-type-confirm" class="login-btn describe-photo-btn" type="button">ادامه و ساخت تیم</button>
          </section>
          <section id="tc-team-room-step" class="team-task-step hidden">
            <p id="tc-team-room-name" class="describe-photo-editor-title">-</p>
            <p id="tc-team-room-slot" class="describe-photo-index team-room-slot-badge">-</p>
            <div id="tc-team-room-top-actions" class="team-room-top-actions hidden">
              <button id="tc-team-open-invite-btn" class="login-btn describe-photo-btn team-room-invite-btn" type="button">دعوت دیگران</button>
              <button id="tc-team-settings-btn" class="login-btn describe-photo-btn secondary team-settings-icon-btn" type="button" aria-label="تنظیمات تیم">
                <span class="team-settings-icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 8.1a3.9 3.9 0 1 1 0 7.8 3.9 3.9 0 0 1 0-7.8Zm8.2 3.2-.9-.2a7.2 7.2 0 0 0-.5-1.2l.5-.8a1.4 1.4 0 0 0-.2-1.8l-1.1-1.1a1.4 1.4 0 0 0-1.8-.2l-.8.5c-.4-.2-.8-.4-1.2-.5l-.2-.9A1.4 1.4 0 0 0 12.6 4h-1.2a1.4 1.4 0 0 0-1.4 1.1l-.2.9c-.4.1-.8.3-1.2.5l-.8-.5a1.4 1.4 0 0 0-1.8.2L4.9 7.3a1.4 1.4 0 0 0-.2 1.8l.5.8c-.2.4-.4.8-.5 1.2l-.9.2A1.4 1.4 0 0 0 2.7 12v1.2c0 .7.5 1.2 1.1 1.4l.9.2c.1.4.3.8.5 1.2l-.5.8a1.4 1.4 0 0 0 .2 1.8l1.1 1.1c.5.5 1.2.5 1.8.2l.8-.5c.4.2.8.4 1.2.5l.2.9c.2.6.7 1.1 1.4 1.1h1.2c.7 0 1.2-.5 1.4-1.1l.2-.9c.4-.1.8-.3 1.2-.5l.8.5c.6.3 1.3.3 1.8-.2l1.1-1.1c.5-.5.5-1.2.2-1.8l-.5-.8c.2-.4.4-.8.5-1.2l.9-.2c.6-.2 1.1-.7 1.1-1.4V12c0-.7-.5-1.2-1.1-1.4Z"/>
                  </svg>
                </span>
              </button>
            </div>
            <div id="tc-team-room-members" class="team-members-grid"></div>
            <div class="team-task-actions team-task-actions--sticky">
              <button id="tc-team-start-btn" class="login-btn describe-photo-btn" type="button" disabled>شروع چالش</button>
            </div>
          </section>
          <section id="tc-team-find-step" class="team-task-step hidden">
            <div class="team-find-actions">
              <button id="tc-team-open-search-btn" class="login-btn describe-photo-btn secondary" type="button">جستجوی تیم</button>
              <button id="tc-team-create-from-find-btn" class="login-btn describe-photo-btn" type="button">ساخت تیم</button>
            </div>
            <div id="tc-team-find-additional-text" class="team-find-additional-text hidden"></div>
            <p id="tc-team-groups-hint" class="team-groups-hint">گروه تیم‌ها</p>
            <div id="tc-team-invited-group" class="team-list-group team-list-group--invited">
              <p class="team-list-title">دعوت‌شده‌ها</p>
              <div id="tc-team-invited-list" class="team-list team-list--invited"></div>
            </div>
            <div class="team-list-group team-list-group--public">
              <p class="team-list-title">تیم‌های عمومی</p>
              <div id="tc-team-public-list" class="team-list team-list--public"></div>
            </div>
          </section>
          <section id="tc-team-search-step" class="team-task-step hidden">
            <label class="login-field">
              <span>جستجوی سرگروه</span>
              <small class="team-field-hint">شماره پرسنلی سرگروه مدنظر خود را وارد کنید.</small>
              <input id="tc-team-search-leader-input" class="login-input" type="text" placeholder="شماره پرسنلی سرگروه" />
            </label>
            <button id="tc-team-search-leader-btn" class="login-btn describe-photo-btn" type="button">جستجوی تیم</button>
          </section>
          <section id="tc-team-preview-step" class="team-task-step hidden">
            <p id="tc-team-preview-name" class="describe-photo-editor-title">-</p>
            <p id="tc-team-preview-meta" class="team-meta-badges">-</p>
            <div id="tc-team-preview-members" class="team-list"></div>
            <div class="team-task-actions team-task-actions--sticky team-preview-actions">
              <button id="tc-team-preview-join-btn" class="login-btn describe-photo-btn" type="button">درخواست عضویت</button>
            </div>
          </section>
          <section id="tc-team-settings-step" class="team-task-step hidden">
            <label class="login-field">
              <span>نام تیم</span>
              <input id="tc-team-settings-name-input" class="login-input" type="text" maxlength="80" placeholder="نام جدید تیم" />
            </label>
            <div id="tc-team-settings-join-wrap" class="login-field">
              <span>نوع عضویت</span>
              <div class="team-join-type-list">
                <label class="team-join-type-option">
                  <input type="radio" name="tc-team-settings-join-type" value="private" checked />
                  <span class="team-join-type-text">
                    <strong>تیم خصوصی</strong>
                    <small>فقط افرادی که دعوت می‌کنید می‌توانند ملحق شوند</small>
                  </span>
                </label>
                <label class="team-join-type-option">
                  <input type="radio" name="tc-team-settings-join-type" value="public_request" />
                  <span class="team-join-type-text">
                    <strong>ورود فقط با درخواست</strong>
                    <small>نمایش عمومی همراه با تایید سرگروه</small>
                  </span>
                </label>
                <label class="team-join-type-option">
                  <input type="radio" name="tc-team-settings-join-type" value="public_open" />
                  <span class="team-join-type-text">
                    <strong>ورود آزاد</strong>
                    <small>همه کاربران می‌توانند مستقیم عضو شوند</small>
                  </span>
                </label>
              </div>
            </div>
            <button id="tc-team-settings-save-btn" class="login-btn describe-photo-btn" type="button">ذخیره تنظیمات</button>
            <button id="tc-team-settings-delete-btn" class="login-btn team-danger-btn" type="button">حذف تیم</button>
            <button id="tc-team-settings-leave-btn" class="login-btn team-danger-btn hidden" type="button">خروج از تیم</button>
          </section>
          <section id="tc-team-challenge-step" class="team-task-step hidden">
            <p id="tc-team-challenge-title" class="describe-photo-editor-title">راهنمای چالش تیمی</p>
            <div id="tc-team-challenge-content" class="info-task-content"></div>
            <button id="tc-team-challenge-back-btn" class="login-btn describe-photo-btn secondary" type="button">برگشت به تیم</button>
          </section>
          <section id="tc-describe-photo-step" class="describe-photo-step hidden">
            <div class="describe-photo-preview">
              <img id="tc-describe-photo-image" class="describe-photo-image" alt="تصویر ماموریت" />
            </div>
            <p id="tc-describe-photo-name" class="describe-photo-name">-</p>
            <p id="tc-describe-photo-index" class="describe-photo-index">1 / 3</p>
            <div class="describe-photo-actions">
              <button id="tc-describe-photo-change" class="login-btn describe-photo-btn secondary" type="button">تغییر عکس 1/3</button>
              <button id="tc-describe-photo-select" class="login-btn describe-photo-btn" type="button">انتخاب این تصویر</button>
            </div>
          </section>
          <section id="tc-describe-photo-editor-step" class="describe-photo-editor hidden">
            <p id="tc-describe-photo-editor-title" class="describe-photo-editor-title">-</p>
            <div class="describe-photo-preview describe-photo-editor-preview">
              <img id="tc-describe-photo-editor-image" class="describe-photo-image" alt="تصویر انتخاب شده" />
            </div>
            <p id="tc-describe-photo-editor-name" class="describe-photo-index">الهی‌نامه شما بر اساس تصویر:</p>
            <textarea
              id="tc-describe-photo-text"
              class="describe-photo-textarea"
              rows="10"
              maxlength="8000"
              placeholder="الهی‌نامه خود را بر اساس تصویر بنویسید (حداکثر 300 کلمه)"
            ></textarea>
            <p id="tc-describe-photo-word-count" class="describe-photo-word-count">0 / 300 کلمه</p>
            <button id="tc-describe-photo-save" class="login-btn describe-photo-save-btn" type="button">ذخیره</button>
          </section>
          <button id="tc-task-info-ack" class="login-btn info-task-ack" type="button">متوجه شدم</button>
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
      <div id="tc-team-invite-dialog" class="tc-result-dialog-overlay" aria-hidden="true">
        <section class="tc-result-dialog team-invite-dialog" role="dialog" aria-modal="true" aria-labelledby="tc-team-invite-dialog-title">
          <h3 id="tc-team-invite-dialog-title" class="tc-result-dialog-title">دعوت عضو جدید</h3>
          <div class="tc-result-dialog-content team-invite-dialog-content">
            <label class="login-field">
              <span>شماره پرسنلی (Work ID)</span>
              <input id="tc-team-invite-query-input" class="login-input" type="text" placeholder="مثال: 12345" />
            </label>
            <div id="tc-team-invite-result" class="team-invite-result hidden">
              <p id="tc-team-invite-result-name" class="team-invite-result-name">-</p>
            </div>
            <button id="tc-team-invite-action-btn" class="tc-result-dialog-confirm" type="button">جستجو</button>
          </div>
        </section>
      </div>
      <div id="tc-confirm-dialog" class="tc-result-dialog-overlay" aria-hidden="true">
        <section class="tc-result-dialog" role="dialog" aria-modal="true" aria-labelledby="tc-confirm-dialog-title">
          <h3 id="tc-confirm-dialog-title" class="tc-result-dialog-title">تایید عملیات</h3>
          <div class="tc-result-dialog-content">
            <p id="tc-confirm-dialog-message" class="hint hint-align-center">-</p>
          </div>
          <div class="tc-confirm-dialog-actions">
            <button id="tc-confirm-dialog-cancel" class="tc-result-dialog-confirm tc-confirm-dialog-cancel" type="button">انصراف</button>
            <button id="tc-confirm-dialog-confirm" class="tc-result-dialog-confirm tc-confirm-dialog-danger" type="button">تایید</button>
          </div>
        </section>
      </div>
    <?php endif; ?>

    <script nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>">
      (() => {
        const watchdog = setTimeout(() => {
          const body = document.body;
          if (!body || !body.classList.contains('page-loading')) return;
          try {
            const url = new URL(window.location.href);
            if (url.searchParams.get('force_logout') !== '1') {
              url.searchParams.set('force_logout', '1');
              window.location.replace(url.toString());
            }
          } catch {
            window.location.replace(`${window.location.pathname}?force_logout=1`);
          }
        }, 9000);
        window.__tcClearLoaderWatchdog = () => clearTimeout(watchdog);
      })();
    </script>

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
        if (typeof window.__tcClearLoaderWatchdog === 'function') {
          window.__tcClearLoaderWatchdog();
        }
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

      const sessionInfo = <?= json_encode($sessionPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
      let csrfToken = <?= json_encode($_SESSION['tc_csrf'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
      const isCsrfMismatchPayload = (payload) => (
        payload &&
        payload.code === 'csrf_mismatch' &&
        typeof payload.csrf === 'string' &&
        payload.csrf.trim() !== ''
      );
      const rewardCardLogoUrl = <?= json_encode($eventLogoUrl !== '' ? $eventLogoUrl : $fallbackSiteIconUrl, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
      const loginForm = document.getElementById('tc-login-form');
      const logoutBtn = document.getElementById('tc-logout');
      const createRuntimeLoader = (primaryText, secondaryText = '') => {
        const overlay = document.createElement('div');
        overlay.className = 'loader-overlay';
        overlay.dataset.runtimeLoader = '1';
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
        return overlay;
      };
      const TRANSITION_LOADER_DELAY_MS = 180;
      const TRANSITION_LOADER_MIN_VISIBLE_MS = 280;
      let transitionLoaderDepth = 0;
      let transitionLoaderEl = null;
      let transitionLoaderVisibleSince = 0;

      const waitForNextPaint = () => new Promise((resolve) => {
        requestAnimationFrame(() => requestAnimationFrame(resolve));
      });

      const setLoaderText = (overlay, primaryText, secondaryText = '') => {
        if (!(overlay instanceof HTMLElement)) return;
        const loaderTextEl = overlay.querySelector('.loader-text');
        const loaderSubtextEl = overlay.querySelector('.loader-subtext');
        if (loaderTextEl) loaderTextEl.textContent = String(primaryText || '').trim() || 'در حال پردازش';
        if (loaderSubtextEl) loaderSubtextEl.textContent = String(secondaryText || '').trim();
      };

      const ensureTransitionLoader = (primaryText, secondaryText = '') => {
        if (!(transitionLoaderEl instanceof HTMLElement) || !transitionLoaderEl.isConnected) {
          transitionLoaderEl = createRuntimeLoader(primaryText, secondaryText);
        } else {
          setLoaderText(transitionLoaderEl, primaryText, secondaryText);
          transitionLoaderEl.classList.remove('loader-hidden');
        }
        transitionLoaderVisibleSince = performance.now();
      };

      const removeTransitionLoader = () => {
        if (!(transitionLoaderEl instanceof HTMLElement)) return;
        const target = transitionLoaderEl;
        transitionLoaderEl = null;
        transitionLoaderVisibleSince = 0;
        target.remove();
      };

      const withTransitionLoader = async (work, {
        primaryText = 'در حال آماده‌سازی',
        secondaryText = 'لطفا چند لحظه صبر کنید',
        delayMs = TRANSITION_LOADER_DELAY_MS,
        minVisibleMs = TRANSITION_LOADER_MIN_VISIBLE_MS,
        waitForPaint = true
      } = {}) => {
        const task = typeof work === 'function' ? work : async () => undefined;
        transitionLoaderDepth += 1;
        let shown = false;
        const safeDelay = Math.max(0, Number(delayMs) || 0);
        const showTimer = setTimeout(() => {
          shown = true;
          ensureTransitionLoader(primaryText, secondaryText);
        }, safeDelay);

        try {
          const result = await task();
          if (waitForPaint) {
            await waitForNextPaint();
          }
          return result;
        } finally {
          clearTimeout(showTimer);
          transitionLoaderDepth = Math.max(0, transitionLoaderDepth - 1);

          if (!shown) {
            if (transitionLoaderDepth === 0) {
              removeTransitionLoader();
            }
            return;
          }

          const elapsed = Math.max(0, performance.now() - transitionLoaderVisibleSince);
          const safeMinVisible = Math.max(0, Number(minVisibleMs) || 0);
          if (elapsed < safeMinVisible) {
            await new Promise((resolve) => setTimeout(resolve, safeMinVisible - elapsed));
          }
          if (transitionLoaderDepth === 0) {
            removeTransitionLoader();
          }
        }
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
      const normalizeLoginDigits = (value) => String(value ?? '')
        .replace(/[۰-۹]/g, (char) => String(char.charCodeAt(0) - 1728))
        .replace(/[٠-٩]/g, (char) => String(char.charCodeAt(0) - 1584));
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
              const username = normalizeLoginDigits(String(userInput?.value ?? '')).trim();
              const password = normalizeLoginDigits(String(passInput?.value ?? '')).trim();
              let response = null;
              let payload = null;
              for (let attempt = 0; attempt < 2; attempt += 1) {
                response = await fetch(window.location.href, {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({ action: 'login', username, password, csrf: csrfToken })
                });
                try {
                  payload = await response.json();
                } catch {
                  payload = { status: 'error', message: 'پاسخ نامعتبر از سرور دریافت شد.' };
                }
                if (isCsrfMismatchPayload(payload) && attempt === 0) {
                  csrfToken = payload.csrf.trim();
                  continue;
                }
                break;
              }
              if (response.ok && payload?.status === 'ok') {
                window.location.reload();
                return;
              }
              if (loginMsg) {
                loginMsg.textContent = payload?.message || 'ورود ناموفق بود.';
              }
            } catch {
              if (loginMsg) {
                loginMsg.textContent = 'ورود ناموفق بود.';
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
        const confirmDialogEl = document.getElementById('tc-confirm-dialog');
        const confirmDialogTitleEl = document.getElementById('tc-confirm-dialog-title');
        const confirmDialogMessageEl = document.getElementById('tc-confirm-dialog-message');
        const confirmDialogCancelEl = document.getElementById('tc-confirm-dialog-cancel');
        const confirmDialogConfirmEl = document.getElementById('tc-confirm-dialog-confirm');
        const quizAreaEl = document.getElementById('tc-task-quiz-area');
        const taskInfoAreaEl = document.getElementById('tc-task-info-area');
        const taskInfoTitleEl = document.getElementById('tc-task-info-title');
        const taskInfoContentEl = document.getElementById('tc-task-info-content');
        const taskInfoAckBtnEl = document.getElementById('tc-task-info-ack');
        const describePhotoStepEl = document.getElementById('tc-describe-photo-step');
        const describePhotoImageEl = document.getElementById('tc-describe-photo-image');
        const describePhotoNameEl = document.getElementById('tc-describe-photo-name');
        const describePhotoIndexEl = document.getElementById('tc-describe-photo-index');
        const describePhotoChangeBtnEl = document.getElementById('tc-describe-photo-change');
        const describePhotoSelectBtnEl = document.getElementById('tc-describe-photo-select');
        const describePhotoEditorStepEl = document.getElementById('tc-describe-photo-editor-step');
        const describePhotoEditorTitleEl = document.getElementById('tc-describe-photo-editor-title');
        const describePhotoEditorImageEl = document.getElementById('tc-describe-photo-editor-image');
        const describePhotoEditorNameEl = document.getElementById('tc-describe-photo-editor-name');
        const describePhotoTextareaEl = document.getElementById('tc-describe-photo-text');
        const describePhotoWordCountEl = document.getElementById('tc-describe-photo-word-count');
        const describePhotoSaveBtnEl = document.getElementById('tc-describe-photo-save');
        const teamRulesStepEl = document.getElementById('tc-team-rules-step');
        const teamRulesTextEl = document.getElementById('tc-team-rules-text');
        const teamCreateBtnEl = document.getElementById('tc-team-create-btn');
        const teamCreateNameStepEl = document.getElementById('tc-team-create-name-step');
        const teamCreateNameInputEl = document.getElementById('tc-team-create-name-input');
        const teamCreateNameConfirmBtnEl = document.getElementById('tc-team-create-name-confirm');
        const teamCreateTypeStepEl = document.getElementById('tc-team-create-type-step');
        const teamCreateJoinTypeInputs = Array.from(document.querySelectorAll('input[name="tc-team-create-join-type"]'));
        const teamCreateTypeConfirmBtnEl = document.getElementById('tc-team-create-type-confirm');
        const teamRoomStepEl = document.getElementById('tc-team-room-step');
        const teamRoomNameEl = document.getElementById('tc-team-room-name');
        const teamRoomSlotEl = document.getElementById('tc-team-room-slot');
        const teamRoomTopActionsEl = document.getElementById('tc-team-room-top-actions');
        const teamRoomMembersEl = document.getElementById('tc-team-room-members');
        const teamOpenInviteBtnEl = document.getElementById('tc-team-open-invite-btn');
        const teamInviteQueryInputEl = document.getElementById('tc-team-invite-query-input');
        const teamInviteResultEl = document.getElementById('tc-team-invite-result');
        const teamInviteResultNameEl = document.getElementById('tc-team-invite-result-name');
        const teamInviteActionBtnEl = document.getElementById('tc-team-invite-action-btn');
        const teamInviteDialogEl = document.getElementById('tc-team-invite-dialog');
        const teamStartBtnEl = document.getElementById('tc-team-start-btn');
        const teamSettingsBtnEl = document.getElementById('tc-team-settings-btn');
        const teamFindStepEl = document.getElementById('tc-team-find-step');
        const teamFindAdditionalTextEl = document.getElementById('tc-team-find-additional-text');
        const teamOpenSearchBtnEl = document.getElementById('tc-team-open-search-btn');
        const teamCreateFromFindBtnEl = document.getElementById('tc-team-create-from-find-btn');
        const teamInvitedGroupEl = document.getElementById('tc-team-invited-group');
        const teamInvitedListEl = document.getElementById('tc-team-invited-list');
        const teamPublicListEl = document.getElementById('tc-team-public-list');
        const teamSearchStepEl = document.getElementById('tc-team-search-step');
        const teamSearchLeaderInputEl = document.getElementById('tc-team-search-leader-input');
        const teamSearchLeaderBtnEl = document.getElementById('tc-team-search-leader-btn');
        const teamPreviewStepEl = document.getElementById('tc-team-preview-step');
        const teamPreviewNameEl = document.getElementById('tc-team-preview-name');
        const teamPreviewMetaEl = document.getElementById('tc-team-preview-meta');
        const teamPreviewMembersEl = document.getElementById('tc-team-preview-members');
        const teamPreviewJoinBtnEl = document.getElementById('tc-team-preview-join-btn');
        const teamSettingsStepEl = document.getElementById('tc-team-settings-step');
        const teamSettingsNameInputEl = document.getElementById('tc-team-settings-name-input');
        const teamSettingsJoinWrapEl = document.getElementById('tc-team-settings-join-wrap');
        const teamSettingsJoinInputs = Array.from(document.querySelectorAll('input[name="tc-team-settings-join-type"]'));
        const teamSettingsSaveBtnEl = document.getElementById('tc-team-settings-save-btn');
        const teamSettingsDeleteBtnEl = document.getElementById('tc-team-settings-delete-btn');
        const teamSettingsLeaveBtnEl = document.getElementById('tc-team-settings-leave-btn');
        const teamChallengeStepEl = document.getElementById('tc-team-challenge-step');
        const teamChallengeTitleEl = document.getElementById('tc-team-challenge-title');
        const teamChallengeContentEl = document.getElementById('tc-team-challenge-content');
        const teamChallengeBackBtnEl = document.getElementById('tc-team-challenge-back-btn');
        const taskButtons = Array.from(document.querySelectorAll('.task-item-btn[data-task-id]'));
        const quizTitleEl = document.getElementById('tc-task-quiz-title');
        const quizCounterEl = document.getElementById('tc-task-quiz-counter');
        const quizQuestionEl = document.getElementById('tc-task-quiz-question');
        const quizAnswersEl = document.getElementById('tc-task-quiz-answers');
        const quizTimerFillEl = document.getElementById('tc-task-quiz-timer-fill');
        const taskInfoHeadEl = document.getElementById('tc-task-info-head');
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
        let currentTaskType = 'quiz';
        let currentQuestions = [];
        let currentQuestionIndex = 0;
        let infoTaskViewOpen = false;
        let infoTaskCurrentStep = 'info';
        let describePhotoChoices = [];
        let describePhotoCurrentIndex = 0;
        let describePhotoSelected = null;
        let describePhotoBusy = false;
        let teamTaskState = null;
        let teamTaskPreviewTeam = null;
        let teamTaskInviteCandidate = null;
        let teamInviteDialogActionMode = 'search';
        let teamInviteDialogHasHistoryState = false;
        let teamTaskPendingName = '';
        let teamTaskBusy = false;
        let answerTimeLimitEnabled = true;
        let globalEventStatus = 'inactive';
        let tcmInPageHistoryDepth = 0;
        let tcmHistoryReady = false;
        let tcmHistorySyncLocked = false;
        let tcmSuppressNextPopstate = false;

        const QUIZ_TIME_LIMIT_MS = 14000;
        const QUIZ_FEEDBACK_DELAY_MS = 1000;

        const parseHistoryDepth = (state) => {
          if (!state || typeof state !== 'object') return null;
          const raw = state.tcDepth;
          const depth = Number.parseInt(String(raw ?? ''), 10);
          if (!Number.isFinite(depth) || depth < 0) return null;
          return depth;
        };

        const initInPageHistoryState = () => {
          if (typeof window === 'undefined' || !window.history || typeof window.history.replaceState !== 'function') {
            return;
          }
          const currentState = (window.history.state && typeof window.history.state === 'object')
            ? window.history.state
            : {};
          const currentDepth = parseHistoryDepth(currentState);
          const depth = currentDepth === null ? 0 : currentDepth;
          tcmInPageHistoryDepth = depth;
          window.history.replaceState(
            { ...currentState, tcPage: 'TCM', tcDepth: depth },
            '',
            window.location.href
          );
          tcmHistoryReady = true;
        };

        const pushInPageHistoryState = () => {
          if (!tcmHistoryReady || tcmHistorySyncLocked) return;
          if (typeof window === 'undefined' || !window.history || typeof window.history.pushState !== 'function') return;
          const currentState = (window.history.state && typeof window.history.state === 'object')
            ? window.history.state
            : {};
          const nextDepth = Math.max(0, tcmInPageHistoryDepth) + 1;
          tcmInPageHistoryDepth = nextDepth;
          window.history.pushState(
            { ...currentState, tcPage: 'TCM', tcDepth: nextDepth },
            '',
            window.location.href
          );
        };

        const requestInPageBackByHistory = () => {
          if (typeof window === 'undefined' || !window.history || typeof window.history.back !== 'function') {
            return false;
          }
          if (teamInviteDialogHasHistoryState && isTeamInviteDialogOpen()) {
            window.history.back();
            return true;
          }
          if (tcmInPageHistoryDepth > 0) {
            window.history.back();
            return true;
          }
          return false;
        };

        const runWithoutHistorySync = (runner) => {
          tcmHistorySyncLocked = true;
          try {
            return runner();
          } finally {
            tcmHistorySyncLocked = false;
          }
        };

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
            const payload = await postJson({ action: 'settings_get' });
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
          if (rewardsViewOpen && !rewardsRoundBusy) {
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
            return (taskType === 'quiz' || taskType === 'info' || taskType === 'team_task' || taskType === 'describe_photo') ? 'مهلت طلایی' : 'فعال';
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

        const escapeTaskMetaHtml = (value) => String(value ?? '')
          .replaceAll('&', '&amp;')
          .replaceAll('<', '&lt;')
          .replaceAll('>', '&gt;')
          .replaceAll('"', '&quot;')
          .replaceAll("'", '&#39;');

        const isNonQuizPendingScore = (button, taskType) => {
          if (taskType === 'quiz') return false;
          const configuredScore = Math.max(0, Number.parseInt(button?.dataset?.taskScore || '0', 10) || 0);
          return configuredScore <= 0;
        };

        const resolveTaskScoreText = (button, taskType, scoreValue) => {
          if (isNonQuizPendingScore(button, taskType)) {
            return 'امتیاز شما پس از ارزیابی مشخص می‌شود';
          }
          return `امتیاز ماموریت: ${Math.max(0, Number.parseInt(scoreValue ?? 0, 10) || 0)}`;
        };

        const withScoreHint = (baseText, button, taskType, scoreValue) => {
          const text = String(baseText || '').trim();
          return `${text} | ${resolveTaskScoreText(button, taskType, scoreValue)}`;
        };

        const setMetaText = (metaEl, text, multiline = false) => {
          if (!metaEl) return;
          metaEl.classList.toggle('is-multiline', Boolean(multiline));
          metaEl.classList.remove('has-score-block', 'is-score-pending');
          metaEl.textContent = String(text || '');
        };

        const setMetaWithScoreBlock = (metaEl, topText, button, taskType, scoreValue) => {
          if (!metaEl) return;
          const top = String(topText || '').trim();
          const scoreText = resolveTaskScoreText(button, taskType, scoreValue);
          metaEl.classList.add('is-multiline', 'has-score-block');
          metaEl.classList.toggle('is-score-pending', isNonQuizPendingScore(button, taskType));
          metaEl.innerHTML = `<span class="task-meta-main">${escapeTaskMetaHtml(top).replace(/\n/g, '<br>')}</span><span class="task-meta-score">${escapeTaskMetaHtml(scoreText)}</span>`;
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

        const formatDescribeEditCountdown = (targetDate, targetTime) => {
          const target = getTehranTargetDate(targetDate, normalizeGoldenEndTime(targetTime));
          if (!target) {
            return '';
          }
          const diffSeconds = Math.max(0, Math.floor((target.getTime() - Date.now()) / 1000));
          return `تا پایان مهلت ویرایش\n${formatFaDuration(diffSeconds, { includeSeconds: true })}`;
        };

        const canOpenTaskByStatus = (status, taskType) => {
          if (status === 'active') return true;
          if (taskType === 'quiz' && status === 'ended') return true;
          return false;
        };

        const setTaskButtonState = (button, status, closestUpcomingTaskId = '', secondUpcomingTaskId = '') => {
          if (!(button instanceof HTMLButtonElement)) {
            return;
          }
          const metaEl = button.querySelector('.task-item-meta');
          const completed = String(button.dataset.taskCompleted || '') === '1';
          const taskScore = Number.parseInt(button.dataset.taskUserScore || '0', 10);
          const taskType = String(button.dataset.taskType || 'quiz').trim().toLowerCase() || 'quiz';
          const describeSubmitted = String(button.dataset.taskDescribeSubmitted || '') === '1';
          const teamStartedPending = String(button.dataset.taskTeamStartedPending || '') === '1';
          const infoEndedNoScore = (taskType === 'info' || taskType === 'team_task' || taskType === 'describe_photo') && status === 'ended' && !completed;
          const describeEditableDone = taskType === 'describe_photo' && status === 'active' && describeSubmitted && !completed;
          const teamEditableDone = taskType === 'team_task' && status === 'active' && teamStartedPending && !completed;
          const editableSubmittedDone = describeEditableDone || teamEditableDone;

          if (completed) {
            button.disabled = true;
            button.classList.remove('is-disabled');
            button.classList.add('is-completed');
            button.classList.remove('is-describe-submitted');
            button.classList.remove('is-golden');
            button.classList.remove('is-golden-live');
            button.classList.remove('is-info-ended');
            button.dataset.taskStatus = 'completed';
            if (metaEl) {
              const shownScore = Number.isFinite(taskScore) ? Math.max(0, taskScore) : 0;
              const completedLabel = 'تکمیل شده';
              setMetaText(metaEl, shownScore > 0 ? `${completedLabel} (امتیاز ${shownScore})` : completedLabel, false);
            }
            return;
          }

          const globallyBlocked = globalEventStatus === 'inactive';
          const available = !globallyBlocked && canOpenTaskByStatus(status, taskType);
          const isUpcoming = status === 'upcoming';
          const scoreNow = taskAvailableScoreNow(button, status, taskType);
          const isGoldenAppearance = status === 'active'
            && (taskType === 'quiz' || taskType === 'info' || taskType === 'team_task' || taskType === 'describe_photo')
            && !editableSubmittedDone;
          button.disabled = !available;
          button.classList.toggle('is-disabled', !available && !isUpcoming);
          button.classList.toggle('is-upcoming', isUpcoming);
          button.classList.remove('is-completed');
          button.classList.toggle('is-describe-submitted', editableSubmittedDone);
          button.classList.toggle('is-info-ended', infoEndedNoScore);
          button.classList.toggle('is-golden', isGoldenAppearance);
          button.classList.toggle('is-golden-live', false);
          button.dataset.taskStatus = status;
          if (metaEl) {
            if (status === 'upcoming') {
              const startDate = String(button.dataset.taskStartDate || '').trim();
              const startTime = String(button.dataset.taskStartTime || '').trim();
              const taskId = String(button.dataset.taskId || '').trim();
              if (taskId !== '' && taskId === closestUpcomingTaskId) {
                const countdown = formatTaskCountdown(startDate, startTime);
                const fallbackText = startDate
                  ? `شروع از: ${startDate} ${normalizeUpcomingStartTime(startTime)}`
                  : taskStatusLabel(status, false, taskType);
                setMetaText(metaEl, withScoreHint(countdown || fallbackText, button, taskType, scoreNow), false);
              } else if (taskId !== '' && taskId === secondUpcomingTaskId) {
                setMetaText(metaEl, withScoreHint('به‌زودی', button, taskType, scoreNow), false);
              } else {
                setMetaText(metaEl, resolveTaskScoreText(button, taskType, scoreNow), false);
              }
            } else if (editableSubmittedDone) {
              const endDate = String(button.dataset.taskEndDate || '').trim();
              const endTime = String(button.dataset.taskEndTime || '').trim();
              const editCountdown = formatDescribeEditCountdown(endDate, endTime);
              const doneLabel = taskType === 'team_task' ? 'شروع شده' : 'تکمیل شده';
              const editLabel = taskType === 'team_task' ? 'تا پایان مهلت تکمیل چالش' : 'تا پایان مهلت ویرایش';
              const normalizedEditCountdown = taskType === 'team_task'
                ? String(editCountdown || '').replace('تا پایان مهلت ویرایش', editLabel)
                : editCountdown;
              if (normalizedEditCountdown) {
                setMetaWithScoreBlock(metaEl, `${doneLabel}\n${normalizedEditCountdown}`, button, taskType, scoreNow);
              } else {
                setMetaWithScoreBlock(metaEl, `${doneLabel} | ${editLabel}`, button, taskType, scoreNow);
              }
            } else if (status === 'active' && (taskType === 'quiz' || taskType === 'info' || taskType === 'team_task' || taskType === 'describe_photo')) {
              const endDate = String(button.dataset.taskEndDate || '').trim();
              const endTime = String(button.dataset.taskEndTime || '').trim();
              const goldenCountdown = formatGoldenTimeCountdown(endDate, endTime);
              if (goldenCountdown) {
                setMetaWithScoreBlock(metaEl, goldenCountdown, button, taskType, scoreNow);
                button.classList.add('is-golden-live');
              } else {
                setMetaText(metaEl, withScoreHint(taskStatusLabel(status, false, taskType), button, taskType, scoreNow), false);
              }
            } else {
              setMetaText(metaEl, withScoreHint(taskStatusLabel(status, false, taskType), button, taskType, scoreNow), false);
            }
          }
        };

        const refreshTaskButtonsStatus = () => {
          const statusRows = taskButtons.map((button) => ({
            button,
            status: deriveTaskStatusFromButton(button)
          }));

          const upcomingItems = [];
          statusRows.forEach(({ button, status }, rowIndex) => {
            if (status !== 'upcoming') return;
            const taskId = String(button.dataset.taskId || '').trim();
            if (!taskId) return;
            const startDate = String(button.dataset.taskStartDate || '').trim();
            const startTime = normalizeUpcomingStartTime(button.dataset.taskStartTime);
            const target = getTehranTargetDate(startDate, startTime);
            const ts = target ? target.getTime() : Number.POSITIVE_INFINITY;
            upcomingItems.push({
              taskId,
              ts: Number.isFinite(ts) ? ts : Number.POSITIVE_INFINITY,
              rowIndex
            });
          });
          upcomingItems.sort((left, right) => {
            if (left.ts === right.ts) {
              return left.rowIndex - right.rowIndex;
            }
            return left.ts - right.ts;
          });
          const closestUpcomingTaskId = upcomingItems[0]?.taskId || '';
          const secondUpcomingTaskId = upcomingItems[1]?.taskId || '';

          statusRows.forEach(({ button, status }) => {
            setTaskButtonState(button, status, closestUpcomingTaskId, secondUpcomingTaskId);
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
          if (taskInfoAreaEl) {
            taskInfoAreaEl.classList.add('quiz-hidden');
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
          if (taskInfoAreaEl) {
            taskInfoAreaEl.classList.add('quiz-hidden');
          }
          if (taskInfoContentEl) {
            taskInfoContentEl.innerHTML = '';
          }
          if (describePhotoStepEl) {
            describePhotoStepEl.classList.add('hidden');
          }
          if (describePhotoEditorStepEl) {
            describePhotoEditorStepEl.classList.add('hidden');
          }
          if (taskInfoAckBtnEl) {
            taskInfoAckBtnEl.classList.remove('hidden');
            taskInfoAckBtnEl.textContent = 'متوجه شدم';
          }
          if (describePhotoImageEl) {
            describePhotoImageEl.removeAttribute('src');
            describePhotoImageEl.classList.add('is-hidden');
          }
          if (describePhotoNameEl) {
            describePhotoNameEl.textContent = '-';
          }
          if (describePhotoIndexEl) {
            describePhotoIndexEl.textContent = '1 / 3';
          }
          if (describePhotoEditorTitleEl) {
            describePhotoEditorTitleEl.textContent = '-';
          }
          if (describePhotoEditorImageEl) {
            describePhotoEditorImageEl.removeAttribute('src');
            describePhotoEditorImageEl.classList.add('is-hidden');
          }
          if (describePhotoEditorNameEl) {
            describePhotoEditorNameEl.textContent = 'الهی‌نامه شما بر اساس تصویر:';
          }
          if (describePhotoTextareaEl instanceof HTMLTextAreaElement) {
            describePhotoTextareaEl.value = '';
          }
          if (describePhotoWordCountEl) {
            describePhotoWordCountEl.textContent = '0 / 300 کلمه';
            describePhotoWordCountEl.classList.remove('is-error');
          }
          if (describePhotoSaveBtnEl instanceof HTMLButtonElement) {
            describePhotoSaveBtnEl.disabled = false;
          }
          if (teamRulesStepEl) teamRulesStepEl.classList.add('hidden');
          if (teamCreateNameStepEl) teamCreateNameStepEl.classList.add('hidden');
          if (teamCreateTypeStepEl) teamCreateTypeStepEl.classList.add('hidden');
          if (teamRoomStepEl) teamRoomStepEl.classList.add('hidden');
          if (teamFindStepEl) teamFindStepEl.classList.add('hidden');
          if (teamSearchStepEl) teamSearchStepEl.classList.add('hidden');
          if (teamPreviewStepEl) teamPreviewStepEl.classList.add('hidden');
          if (teamSettingsStepEl) teamSettingsStepEl.classList.add('hidden');
          if (teamChallengeStepEl) teamChallengeStepEl.classList.add('hidden');
          if (teamRulesTextEl) teamRulesTextEl.textContent = '';
          if (teamCreateNameInputEl instanceof HTMLInputElement) teamCreateNameInputEl.value = '';
          if (teamRoomNameEl) teamRoomNameEl.textContent = '-';
          if (teamRoomSlotEl) teamRoomSlotEl.textContent = '-';
          if (teamRoomTopActionsEl) {
            teamRoomTopActionsEl.classList.add('hidden');
            teamRoomTopActionsEl.classList.remove('team-room-top-actions--single');
          }
          if (teamRoomMembersEl) teamRoomMembersEl.innerHTML = '';
          if (teamInvitedGroupEl) teamInvitedGroupEl.classList.remove('hidden');
          if (teamInvitedListEl) teamInvitedListEl.innerHTML = '';
          if (teamPublicListEl) teamPublicListEl.innerHTML = '';
          if (teamFindAdditionalTextEl) {
            teamFindAdditionalTextEl.textContent = '';
            teamFindAdditionalTextEl.classList.add('hidden');
          }
          if (teamPreviewNameEl) teamPreviewNameEl.textContent = '-';
          if (teamPreviewMetaEl) teamPreviewMetaEl.textContent = '-';
          if (teamPreviewMembersEl) teamPreviewMembersEl.innerHTML = '';
          if (teamChallengeTitleEl) teamChallengeTitleEl.textContent = 'راهنمای چالش تیمی';
          if (teamChallengeContentEl) teamChallengeContentEl.innerHTML = '';
          if (teamInviteQueryInputEl instanceof HTMLInputElement) teamInviteQueryInputEl.value = '';
          if (teamInviteResultEl) teamInviteResultEl.classList.add('hidden');
          if (teamInviteResultNameEl) teamInviteResultNameEl.innerHTML = '<span>-</span>';
          if (teamInviteDialogEl instanceof HTMLElement) {
            teamInviteDialogEl.classList.remove('open');
            teamInviteDialogEl.setAttribute('aria-hidden', 'true');
          }
          teamInviteDialogHasHistoryState = false;
          teamInviteDialogActionMode = 'search';
          if (teamInviteActionBtnEl instanceof HTMLButtonElement) {
            teamInviteActionBtnEl.textContent = 'جستجو';
          }
          if (teamOpenInviteBtnEl instanceof HTMLButtonElement) {
            teamOpenInviteBtnEl.disabled = true;
            teamOpenInviteBtnEl.classList.remove('hidden');
          }
          if (teamSettingsBtnEl instanceof HTMLButtonElement) {
            teamSettingsBtnEl.disabled = true;
          }
          if (teamSearchLeaderInputEl instanceof HTMLInputElement) teamSearchLeaderInputEl.value = '';
          if (teamSettingsNameInputEl instanceof HTMLInputElement) teamSettingsNameInputEl.value = '';
          teamSettingsJoinInputs.forEach((input) => {
            if (!(input instanceof HTMLInputElement)) return;
            input.checked = input.value === 'private';
            input.disabled = false;
          });
          teamCreateJoinTypeInputs.forEach((input) => {
            if (!(input instanceof HTMLInputElement)) return;
            input.checked = input.value === 'private';
          });
          if (teamSettingsJoinWrapEl) teamSettingsJoinWrapEl.classList.remove('hidden');
          if (teamSettingsSaveBtnEl instanceof HTMLButtonElement) teamSettingsSaveBtnEl.classList.remove('hidden');
          if (teamSettingsDeleteBtnEl instanceof HTMLButtonElement) teamSettingsDeleteBtnEl.classList.remove('hidden');
          if (teamSettingsLeaveBtnEl instanceof HTMLButtonElement) teamSettingsLeaveBtnEl.classList.add('hidden');
          teamTaskState = null;
          teamTaskPreviewTeam = null;
          teamTaskInviteCandidate = null;
          teamTaskPendingName = '';
          teamTaskBusy = false;
          infoTaskViewOpen = false;
          infoTaskCurrentStep = 'info';
          quizLocked = false;
          describePhotoBusy = false;
          currentTaskId = '';
          currentTaskTitle = '';
          currentTaskType = 'quiz';
          currentQuestions = [];
          currentQuestionIndex = 0;
          describePhotoChoices = [];
          describePhotoCurrentIndex = 0;
          describePhotoSelected = null;
          if (rewardsViewOpen || rewardsCardsViewOpen) {
            setTopbarMode('rewards');
          } else {
            setTopbarMode('tasks');
          }
        };

        const escapeHtml = (value) => String(value ?? '')
          .replaceAll('&', '&amp;')
          .replaceAll('<', '&lt;')
          .replaceAll('>', '&gt;')
          .replaceAll('"', '&quot;')
          .replaceAll("'", '&#39;');

        const parseInfoTaskSections = (text) => {
          const source = String(text || '').replace(/\r/g, '');
          const lines = source.split('\n');
          const sections = [];
          let current = null;
          lines.forEach((rawLine) => {
            const line = String(rawLine || '');
            const match = line.match(/^\s*A(\d+)\s*[:\-]?\s*(.*)$/i);
            if (match) {
              if (current) sections.push(current);
              const titleText = String(match[2] || '').trim();
              current = {
                title: titleText !== '' ? titleText : `Section A${match[1]}`,
                lines: []
              };
              return;
            }
            if (!current) {
              if (line.trim() === '') return;
              current = { title: '', lines: [] };
            }
            current.lines.push(line);
          });
          if (current) sections.push(current);
          return sections.filter((sec) => String(sec.title || '').trim() !== '' || (Array.isArray(sec.lines) && sec.lines.join('').trim() !== ''));
        };

        const hasInfoHtmlTag = (text) => /<\s*\/?\s*[a-zA-Z][^>]*>/.test(String(text || ''));

        const INFO_HTML_ALLOWED_TAGS = new Set([
          'P', 'BR', 'B', 'STRONG', 'I', 'EM', 'U', 'S',
          'H1', 'H2', 'H3', 'H4', 'H5', 'H6',
          'UL', 'OL', 'LI', 'SPAN', 'DIV', 'BLOCKQUOTE', 'A'
        ]);

        const sanitizeInfoTaskHtml = (htmlText) => {
          const template = document.createElement('template');
          template.innerHTML = String(htmlText || '');

          const sanitizeNode = (node) => {
            if (node.nodeType === Node.TEXT_NODE) {
              const normalizedText = String(node.textContent || '').replace(/\r\n?/g, '\n');
              if (normalizedText.indexOf('\n') === -1) {
                return document.createTextNode(normalizedText);
              }
              const fragment = document.createDocumentFragment();
              const parts = normalizedText.split('\n');
              parts.forEach((part, index) => {
                if (part !== '') {
                  fragment.appendChild(document.createTextNode(part));
                }
                if (index < parts.length - 1) {
                  fragment.appendChild(document.createElement('br'));
                }
              });
              return fragment;
            }
            if (node.nodeType !== Node.ELEMENT_NODE) {
              return null;
            }
            const element = node;
            const tagName = String(element.tagName || '').toUpperCase();
            if (!INFO_HTML_ALLOWED_TAGS.has(tagName)) {
              const fragment = document.createDocumentFragment();
              Array.from(element.childNodes).forEach((child) => {
                const safeChild = sanitizeNode(child);
                if (safeChild) fragment.appendChild(safeChild);
              });
              return fragment;
            }

            const safeElement = document.createElement(tagName.toLowerCase());
            if (tagName === 'A') {
              const rawHref = String(element.getAttribute('href') || '').trim();
              const loweredHref = rawHref.toLowerCase();
              const isUnsafe = loweredHref.startsWith('javascript:') || loweredHref.startsWith('data:') || loweredHref.startsWith('vbscript:');
              if (rawHref !== '' && !isUnsafe) {
                safeElement.setAttribute('href', rawHref);
                safeElement.setAttribute('target', '_blank');
                safeElement.setAttribute('rel', 'noopener noreferrer');
              }
            }

            Array.from(element.childNodes).forEach((child) => {
              const safeChild = sanitizeNode(child);
              if (safeChild) safeElement.appendChild(safeChild);
            });
            return safeElement;
          };

          const container = document.createElement('div');
          Array.from(template.content.childNodes).forEach((child) => {
            const safeChild = sanitizeNode(child);
            if (safeChild) container.appendChild(safeChild);
          });
          return container.innerHTML.trim();
        };

        const buildInfoTaskContentHtml = (text) => {
          const source = String(text || '').replace(/\r/g, '').trim();
          if (source === '') {
            return '<section class="info-task-section"><p>محتوایی برای این ماموریت ثبت نشده است.</p></section>';
          }

          if (hasInfoHtmlTag(source)) {
            const safeHtml = sanitizeInfoTaskHtml(source);
            if (safeHtml === '') {
              return '<section class="info-task-section"><p>محتوایی برای این ماموریت ثبت نشده است.</p></section>';
            }
            return `<section class="info-task-section info-task-rich">${safeHtml}</section>`;
          }

          const hasLegacySections = /^\s*A\d+\s*[:\-]?/im.test(source);
          if (hasLegacySections) {
            const sections = parseInfoTaskSections(source);
            if (!sections.length) {
              return '<section class="info-task-section"><p>محتوایی برای این ماموریت ثبت نشده است.</p></section>';
            }
            return sections.map((section) => {
              const title = escapeHtml(section.title || '');
              const body = escapeHtml((section.lines || []).join('\n').trim());
              return `<section class="info-task-section">${title ? `<h3>${title}</h3>` : ''}<p>${body || '-'}</p></section>`;
            }).join('');
          }

          const paragraphs = source
            .split(/\n{2,}/)
            .map((part) => String(part || '').trim())
            .filter((part) => part !== '');
          if (!paragraphs.length) {
            return '<section class="info-task-section"><p>محتوایی برای این ماموریت ثبت نشده است.</p></section>';
          }
          const content = paragraphs
            .map((part) => `<p>${escapeHtml(part).replace(/\n/g, '<br>')}</p>`)
            .join('');
          return `<section class="info-task-section info-task-rich">${content}</section>`;
        };

        const buildInlineInfoRichHtml = (text) => {
          const source = String(text || '').replace(/\r/g, '').trim();
          if (source === '') return '';
          if (hasInfoHtmlTag(source)) {
            return sanitizeInfoTaskHtml(source);
          }
          return escapeHtml(source).replace(/\n/g, '<br>');
        };

        const countWords = (text) => {
          const source = String(text || '').trim();
          if (source === '') return 0;
          const matched = source.match(/\S+/gu);
          return Array.isArray(matched) ? matched.length : 0;
        };

        const normalizeDescribePhotoChoices = (list) => {
          if (!Array.isArray(list)) return [];
          return list
            .map((item) => ({
              id: String(item?.id || '').trim(),
              name: String(item?.name || '').trim(),
              url: String(item?.url || '').trim(),
              articleFile: String(item?.articleFile || '').trim()
            }))
            .filter((item) => item.id !== '' && item.url !== '');
        };

        const getCurrentDescribePhotoChoice = () => {
          if (!describePhotoChoices.length) return null;
          if (describePhotoCurrentIndex < 0 || describePhotoCurrentIndex >= describePhotoChoices.length) {
            describePhotoCurrentIndex = 0;
          }
          return describePhotoChoices[describePhotoCurrentIndex] || null;
        };

        const normalizeDescribePhotoUrl = (value) => {
          const raw = String(value || '').trim();
          if (raw === '') return '';
          if (/^mini%20apps\/Task%20Club\//i.test(raw)) {
            return raw.replace(/^mini%20apps\/Task%20Club\//i, '');
          }
          if (/^mini apps\/Task Club\//i.test(raw)) {
            return raw.replace(/^mini apps\/Task Club\//i, '');
          }
          return raw;
        };

        const setDescribePhotoImage = (element, value, altText = '') => {
          if (!(element instanceof HTMLImageElement)) return;
          const src = normalizeDescribePhotoUrl(value);
          if (src === '') {
            element.removeAttribute('src');
            element.classList.add('is-hidden');
            return;
          }
          element.classList.remove('is-hidden');
          element.alt = String(altText || element.alt || '').trim() || 'تصویر ماموریت';
          element.src = src;
        };

        const bindDescribePhotoImageState = (element) => {
          if (!(element instanceof HTMLImageElement)) return;
          element.addEventListener('load', () => {
            element.classList.remove('is-hidden');
          });
          element.addEventListener('error', () => {
            element.classList.add('is-hidden');
          });
        };
        bindDescribePhotoImageState(describePhotoImageEl);
        bindDescribePhotoImageState(describePhotoEditorImageEl);

        const setInfoTaskStep = (step, options = {}) => {
          const next = String(step || 'info').trim().toLowerCase();
          const previous = String(infoTaskCurrentStep || '').trim().toLowerCase();
          const shouldPushHistory = options?.pushHistory !== false;
          if (shouldPushHistory && infoTaskViewOpen && previous !== '' && previous !== next) {
            pushInPageHistoryState();
          }
          const isInfoStep = next === 'info';
          const isPhotoStep = next === 'photo';
          const isEditorStep = next === 'editor';
          const isTeamRulesStep = next === 'team_rules';
          const isTeamCreateNameStep = next === 'team_create_name';
          const isTeamCreateTypeStep = next === 'team_create_type';
          const isTeamRoomStep = next === 'team_room';
          const isTeamFindStep = next === 'team_find';
          const isTeamSearchStep = next === 'team_search';
          const isTeamPreviewStep = next === 'team_preview';
          const isTeamSettingsStep = next === 'team_settings';
          const isTeamChallengeStep = next === 'team_challenge';
          infoTaskCurrentStep = next;
          if (taskInfoHeadEl) {
            taskInfoHeadEl.classList.toggle('hidden', !isInfoStep);
          }
          if (taskInfoContentEl) {
            taskInfoContentEl.classList.toggle('hidden', !isInfoStep);
          }
          if (describePhotoStepEl) {
            describePhotoStepEl.classList.toggle('hidden', !(currentTaskType === 'describe_photo' && isPhotoStep));
          }
          if (describePhotoEditorStepEl) {
            describePhotoEditorStepEl.classList.toggle('hidden', !(currentTaskType === 'describe_photo' && isEditorStep));
          }
          if (teamRulesStepEl) teamRulesStepEl.classList.toggle('hidden', !(currentTaskType === 'team_task' && isTeamRulesStep));
          if (teamCreateNameStepEl) teamCreateNameStepEl.classList.toggle('hidden', !(currentTaskType === 'team_task' && isTeamCreateNameStep));
          if (teamCreateTypeStepEl) teamCreateTypeStepEl.classList.toggle('hidden', !(currentTaskType === 'team_task' && isTeamCreateTypeStep));
          if (teamRoomStepEl) teamRoomStepEl.classList.toggle('hidden', !(currentTaskType === 'team_task' && isTeamRoomStep));
          if (teamFindStepEl) teamFindStepEl.classList.toggle('hidden', !(currentTaskType === 'team_task' && isTeamFindStep));
          if (teamSearchStepEl) teamSearchStepEl.classList.toggle('hidden', !(currentTaskType === 'team_task' && isTeamSearchStep));
          if (teamPreviewStepEl) teamPreviewStepEl.classList.toggle('hidden', !(currentTaskType === 'team_task' && isTeamPreviewStep));
          if (teamSettingsStepEl) teamSettingsStepEl.classList.toggle('hidden', !(currentTaskType === 'team_task' && isTeamSettingsStep));
          if (teamChallengeStepEl) teamChallengeStepEl.classList.toggle('hidden', !(currentTaskType === 'team_task' && isTeamChallengeStep));
          if (currentTaskType !== 'team_task' || !isTeamRoomStep) {
            closeTeamInviteDialog();
          }
          if (taskInfoAckBtnEl) {
            if (currentTaskType === 'describe_photo') {
              taskInfoAckBtnEl.classList.toggle('hidden', !isInfoStep);
              taskInfoAckBtnEl.textContent = 'ادامه';
            } else if (currentTaskType === 'team_task') {
              taskInfoAckBtnEl.classList.toggle('hidden', !isInfoStep);
              taskInfoAckBtnEl.textContent = 'ادامه';
            } else {
              taskInfoAckBtnEl.classList.toggle('hidden', !isInfoStep);
              taskInfoAckBtnEl.textContent = 'متوجه شدم';
            }
          }
        };

        const renderDescribePhotoChoice = () => {
          const current = getCurrentDescribePhotoChoice();
          const total = describePhotoChoices.length;
          if (!(current && total > 0)) {
            setDescribePhotoImage(describePhotoImageEl, '');
            if (describePhotoNameEl) describePhotoNameEl.textContent = 'برای این ماموریت تصویری ثبت نشده است.';
            if (describePhotoIndexEl) describePhotoIndexEl.textContent = '0 / 0';
            if (describePhotoChangeBtnEl instanceof HTMLButtonElement) describePhotoChangeBtnEl.disabled = true;
            if (describePhotoSelectBtnEl instanceof HTMLButtonElement) describePhotoSelectBtnEl.disabled = true;
            return;
          }
          setDescribePhotoImage(describePhotoImageEl, current.url, current.name || 'تصویر ماموریت');
          if (describePhotoNameEl) {
            describePhotoNameEl.textContent = current.name || 'تصویر ماموریت';
          }
          if (describePhotoIndexEl) {
            describePhotoIndexEl.textContent = `${describePhotoCurrentIndex + 1} / ${total}`;
          }
          if (describePhotoChangeBtnEl instanceof HTMLButtonElement) {
            describePhotoChangeBtnEl.textContent = `تغییر عکس ${describePhotoCurrentIndex + 1}/${total}`;
            describePhotoChangeBtnEl.disabled = total <= 1;
          }
          if (describePhotoSelectBtnEl instanceof HTMLButtonElement) {
            describePhotoSelectBtnEl.disabled = false;
          }
        };

        const updateDescribePhotoWordCount = () => {
          const text = describePhotoTextareaEl instanceof HTMLTextAreaElement
            ? String(describePhotoTextareaEl.value || '')
            : '';
          const words = countWords(text);
          const isValid = words <= 300;
          if (describePhotoWordCountEl) {
            describePhotoWordCountEl.textContent = `${words} / 300 کلمه`;
            describePhotoWordCountEl.classList.toggle('is-error', !isValid);
          }
          if (describePhotoSaveBtnEl instanceof HTMLButtonElement) {
            describePhotoSaveBtnEl.disabled = !isValid || describePhotoBusy;
          }
          return { words, isValid };
        };

        const openDescribePhotoEditor = async () => {
          const current = getCurrentDescribePhotoChoice();
          if (!current || !currentTaskId || describePhotoBusy) return;
          describePhotoBusy = true;
          if (describePhotoSelectBtnEl instanceof HTMLButtonElement) {
            describePhotoSelectBtnEl.disabled = true;
          }
          try {
            const payload = await postJson({
              action: 'describe_photo_load_article',
              taskId: currentTaskId,
              photoId: current.id
            });
            const data = payload?.data || {};
            describePhotoSelected = {
              ...current,
              url: String(data?.photo?.url || current.url || '').trim(),
              articleFile: String(data?.photo?.articleFile || current.articleFile || '').trim()
            };
            const title = describePhotoSelected.name || 'تصویر ماموریت';
            if (describePhotoEditorTitleEl) {
              describePhotoEditorTitleEl.textContent = title;
            }
            setDescribePhotoImage(describePhotoEditorImageEl, describePhotoSelected.url, title);
            if (describePhotoEditorNameEl) {
              describePhotoEditorNameEl.textContent = 'الهی‌نامه شما بر اساس تصویر:';
            }
            if (describePhotoTextareaEl instanceof HTMLTextAreaElement) {
              describePhotoTextareaEl.value = String(data?.text || '');
            }
            updateDescribePhotoWordCount();
            setInfoTaskStep('editor');
          } catch (error) {
            await openInfoDialog(error?.message || 'بارگذاری متن تصویر ناموفق بود.', 'خطا');
          } finally {
            describePhotoBusy = false;
            if (describePhotoSelectBtnEl instanceof HTMLButtonElement) {
              describePhotoSelectBtnEl.disabled = false;
            }
            updateDescribePhotoWordCount();
          }
        };

        const saveDescribePhotoEditor = async () => {
          if (!describePhotoSelected || !currentTaskId || !(describePhotoTextareaEl instanceof HTMLTextAreaElement) || describePhotoBusy) return;
          const validation = updateDescribePhotoWordCount();
          if (!validation.isValid) {
            await openInfoDialog('حداکثر ۳۰۰ کلمه مجاز است.', 'خطا');
            return;
          }
          describePhotoBusy = true;
          if (describePhotoSaveBtnEl instanceof HTMLButtonElement) {
            describePhotoSaveBtnEl.disabled = true;
          }
          try {
            await postJson({
              action: 'describe_photo_save_article',
              taskId: currentTaskId,
              photoId: describePhotoSelected.id,
              text: String(describePhotoTextareaEl.value || '')
            });
            const targetButton = taskButtons.find(
              (button) => String(button?.dataset?.taskId || '').trim() === String(currentTaskId || '').trim()
            );
            if (targetButton) {
              targetButton.dataset.taskDescribeSubmitted = '1';
              setTaskButtonState(targetButton, deriveTaskStatusFromButton(targetButton));
            }
            closeQuizOverlay();
          } catch (error) {
            await openInfoDialog(error?.message || 'ذخیره توضیح تصویر ناموفق بود.', 'خطا');
          } finally {
            describePhotoBusy = false;
            updateDescribePhotoWordCount();
          }
        };

        const normalizeTeamJoinTypeClient = (value) => {
          const token = String(value || '').trim().toLowerCase();
          if (token === 'public_open' || token === 'public-open' || token === 'open') return 'public_open';
          if (token === 'public_request' || token === 'public-request' || token === 'request') return 'public_request';
          return 'private';
        };

        const getTeamJoinTypeMeta = (joinType) => {
          const token = normalizeTeamJoinTypeClient(joinType);
          if (token === 'public_open') {
            return { token, label: 'ورود آزاد', iconClass: 'ri-earth-line' };
          }
          if (token === 'public_request') {
            return { token, label: 'ورود فقط با درخواست', iconClass: 'ri-user-follow-line' };
          }
          return { token: 'private', label: 'خصوصی', iconClass: 'ri-lock-2-line' };
        };

        const renderTeamJoinTypeBadge = (joinType, baseClasses = 'team-list-item-chip team-list-item-chip--join') => {
          const meta = getTeamJoinTypeMeta(joinType);
          const tokenClass = `team-join-type-badge--${meta.token.replace(/_/g, '-')}`;
          return `<span class="${escapeTaskMetaHtml(`${baseClasses} team-join-type-badge ${tokenClass}`)}"><i class="${escapeTaskMetaHtml(meta.iconClass)}" aria-hidden="true"></i><span>${escapeTaskMetaHtml(meta.label)}</span></span>`;
        };

        const renderTeamAccessBadge = (team, baseClasses = 'team-list-item-chip team-list-item-chip--join') => {
          const memberCount = Math.max(0, Number.parseInt(team?.memberCount ?? 0, 10) || 0);
          const maxMembers = Math.max(1, Number.parseInt(team?.maxMembers ?? 1, 10) || 1);
          const started = Boolean(team?.started);
          const scoreSubmitted = Boolean(team?.scoreSubmitted);
          const isFull = memberCount >= maxMembers;

          if (started) {
            if (scoreSubmitted) {
              return `<span class="${escapeTaskMetaHtml(`${baseClasses} team-join-type-badge team-join-type-badge--completed`)}"><i class="ri-checkbox-circle-line" aria-hidden="true"></i><span>چالش را با موفقیت تمام کردند</span></span>`;
            }
            return `<span class="${escapeTaskMetaHtml(`${baseClasses} team-join-type-badge team-join-type-badge--started`)}"><i class="ri-flag-2-line" aria-hidden="true"></i><span>چالش را شروع کردند</span></span>`;
          }

          if (isFull) {
            return `<span class="${escapeTaskMetaHtml(`${baseClasses} team-join-type-badge team-join-type-badge--full`)}"><i class="ri-user-forbid-line" aria-hidden="true"></i><span>ظرفیت تیم تکمیل</span></span>`;
          }

          return renderTeamJoinTypeBadge(team?.joinType, baseClasses);
        };

        const teamTaskPost = async (mode, body = {}) => {
          return postJson({
            action: 'team_task_action',
            taskId: currentTaskId,
            mode,
            ...body
          });
        };

        const resolveInviteeDisplayName = (entry, fallback = 'کاربر') => {
          const workId = String(entry?.workId || '').trim();
          const fullName = String(entry?.fullName || '').trim();
          const firstName = String(entry?.firstName || '').trim();
          const lastName = String(entry?.lastName || '').trim();
          const composed = `${firstName} ${lastName}`.trim();
          if (composed !== '') return composed;
          if (fullName !== '' && fullName !== workId) return fullName;
          const fallbackText = String(fallback || '').trim();
          return fallbackText !== '' ? fallbackText : 'کاربر';
        };

        const resolveInviteeDisplayMeta = (entry, fallback = 'کاربر') => {
          const workId = String(entry?.workId || '').trim();
          const name = resolveInviteeDisplayName(entry, fallback);
          return { name, workId };
        };

        const splitNameTokens = (value) => {
          const normalized = String(value || '').replace(/\s+/g, ' ').trim();
          if (normalized === '') return [];
          return normalized.split(' ').filter((part) => part !== '');
        };

        const maskLastNameToInitial = (entry, fallbackName = '') => {
          const firstName = String(entry?.firstName || '').replace(/\s+/g, ' ').trim();
          const lastName = String(entry?.lastName || '').replace(/\s+/g, ' ').trim();

          if (firstName !== '' && lastName !== '') {
            const lastInitials = splitNameTokens(lastName)
              .map((part) => String(part || '').charAt(0))
              .filter((part) => part !== '')
              .join(' . ');
            if (lastInitials !== '') {
              return `${firstName} . ${lastInitials}`;
            }
            return firstName;
          }

          const sourceName = String(fallbackName || '').replace(/\s+/g, ' ').trim();
          const parts = splitNameTokens(sourceName);
          if (parts.length < 2) {
            return sourceName;
          }
          const firstPart = parts.slice(0, -1).join(' ').trim();
          const lastInitial = String(parts[parts.length - 1] || '').trim().charAt(0);
          if (firstPart === '' || lastInitial === '') {
            return sourceName;
          }
          return `${firstPart} . ${lastInitial}`;
        };

        const renderEntityIcon = (kind, options = {}) => {
          const type = String(kind || '').trim().toLowerCase();
          let iconClass = 'ri-user-3-line';
          let variantClass = 'member';

          if (type === 'team') {
            const started = Boolean(options?.started);
            const scoreSubmitted = Boolean(options?.scoreSubmitted);
            const memberCount = Math.max(0, Number.parseInt(options?.memberCount ?? 0, 10) || 0);
            const maxMembers = Math.max(1, Number.parseInt(options?.maxMembers ?? 1, 10) || 1);
            const isFull = memberCount >= maxMembers;
            if (started) {
              if (scoreSubmitted) {
                variantClass = 'team-completed';
                iconClass = 'ri-checkbox-circle-line';
              } else {
                variantClass = 'team-started';
                iconClass = 'ri-flag-2-line';
              }
            } else if (isFull) {
              variantClass = 'team-full';
              iconClass = 'ri-user-unfollow-line';
            } else {
              const meta = getTeamJoinTypeMeta(options?.joinType);
              variantClass = `team-${meta.token.replace(/_/g, '-')}`;
              iconClass = meta.iconClass;
            }
          } else if (type === 'invite' || type === 'invited') {
            variantClass = 'invite';
            iconClass = 'ri-mail-line';
          } else if (type === 'request' || type === 'requested') {
            variantClass = 'request';
            iconClass = 'ri-user-add-line';
          } else {
            variantClass = 'member';
            iconClass = 'ri-user-3-line';
          }

          return `<span class="team-entity-icon team-entity-icon--${escapeTaskMetaHtml(variantClass)}" aria-hidden="true"><i class="${escapeTaskMetaHtml(iconClass)}"></i></span>`;
        };

        const renderTeamCardList = (container, teams, emptyText = 'موردی یافت نشد.', variant = 'public') => {
          if (!(container instanceof HTMLElement)) return;
          const list = Array.isArray(teams) ? teams : [];
          if (!list.length) {
            container.innerHTML = `<div class="team-list-item"><div class="team-list-item-meta">${escapeTaskMetaHtml(emptyText)}</div></div>`;
            return;
          }
          const buildTeamCard = (team) => {
            const teamId = escapeTaskMetaHtml(String(team?.id || ''));
            const teamName = escapeTaskMetaHtml(String(team?.name || 'تیم'));
            const memberCount = Math.max(0, Number.parseInt(team?.memberCount ?? 0, 10) || 0);
            const maxMembers = Math.max(1, Number.parseInt(team?.maxMembers ?? 1, 10) || 1);
            const joinTypeBadge = renderTeamAccessBadge(team);
            const invitedTag = variant === 'invited'
              ? '<span class="team-list-item-invited-tag">دعوت برای شما</span>'
              : '';
            return `<button class="team-list-item-btn" type="button" data-team-open-id="${teamId}">
              ${invitedTag}
              <span class="team-entity-title">${renderEntityIcon('team', { joinType: team?.joinType, memberCount, maxMembers, started: Boolean(team?.started), scoreSubmitted: Boolean(team?.scoreSubmitted) })}<strong>${teamName}</strong></span>
              <span class="team-list-item-meta-row">
                <span class="team-list-item-chip">${memberCount}/${maxMembers} نفر</span>
                ${joinTypeBadge}
              </span>
            </button>`;
          };

          const openTeams = [];
          const lockedTeams = [];
          list.forEach((team) => {
            const memberCount = Math.max(0, Number.parseInt(team?.memberCount ?? 0, 10) || 0);
            const maxMembers = Math.max(1, Number.parseInt(team?.maxMembers ?? 1, 10) || 1);
            const started = Boolean(team?.started);
            const isFull = memberCount >= maxMembers;
            if (started || isFull) {
              lockedTeams.push(team);
            } else {
              openTeams.push(team);
            }
          });

          const chunks = [];
          if (openTeams.length > 0) {
            chunks.push(openTeams.map((team) => buildTeamCard(team)).join(''));
          }
          if (openTeams.length > 0 && lockedTeams.length > 0) {
            chunks.push('<div class="team-list-separator" role="separator" aria-hidden="true"></div>');
          }
          if (lockedTeams.length > 0) {
            chunks.push(lockedTeams.map((team) => buildTeamCard(team)).join(''));
          }
          container.innerHTML = chunks.join('');
        };

        const isTeamInviteDialogOpen = () => (
          teamInviteDialogEl instanceof HTMLElement
          && teamInviteDialogEl.classList.contains('open')
        );

        const setTeamInviteDialogActionMode = (mode = 'search') => {
          const next = String(mode || '').trim().toLowerCase() === 'invite' ? 'invite' : 'search';
          teamInviteDialogActionMode = next;
          if (teamInviteActionBtnEl instanceof HTMLButtonElement) {
            teamInviteActionBtnEl.textContent = next === 'invite' ? 'دعوت' : 'جستجو';
          }
        };

        const resetTeamInviteLookup = (options = {}) => {
          const clearInput = options?.clearInput !== false;
          teamTaskInviteCandidate = null;
          if (clearInput && teamInviteQueryInputEl instanceof HTMLInputElement) {
            teamInviteQueryInputEl.value = '';
          }
          if (teamInviteResultEl) {
            teamInviteResultEl.classList.add('hidden');
          }
          if (teamInviteResultNameEl) {
            teamInviteResultNameEl.innerHTML = '<span>-</span>';
          }
          setTeamInviteDialogActionMode('search');
        };

        const closeTeamInviteDialog = (options = {}) => {
          const fromPopState = Boolean(options?.fromPopState);
          const wasOpen = isTeamInviteDialogOpen();
          if (!(teamInviteDialogEl instanceof HTMLElement) || (!wasOpen && !teamInviteDialogHasHistoryState)) return;
          teamInviteDialogEl.classList.remove('open');
          teamInviteDialogEl.setAttribute('aria-hidden', 'true');
          if (fromPopState) {
            teamInviteDialogHasHistoryState = false;
            return;
          }
          if (teamInviteDialogHasHistoryState && typeof window !== 'undefined' && window.history && typeof window.history.back === 'function') {
            teamInviteDialogHasHistoryState = false;
            tcmSuppressNextPopstate = true;
            window.history.back();
          }
        };

        const openTeamInviteDialog = () => {
          const myTeam = teamTaskState?.myTeam || null;
          if (!myTeam) return;
          if (isTeamInviteDialogOpen()) return;
          resetTeamInviteLookup();
          if (teamInviteDialogEl instanceof HTMLElement) {
            teamInviteDialogEl.classList.add('open');
            teamInviteDialogEl.setAttribute('aria-hidden', 'false');
          }
          if (!teamInviteDialogHasHistoryState && typeof window !== 'undefined' && window.history && typeof window.history.pushState === 'function') {
            const baseState = (window.history.state && typeof window.history.state === 'object')
              ? window.history.state
              : {};
            window.history.pushState({ ...baseState, tcOverlay: 'teamInviteDialog' }, '', window.location.href);
            teamInviteDialogHasHistoryState = true;
          }
          window.setTimeout(() => {
            if (teamInviteQueryInputEl instanceof HTMLInputElement) {
              teamInviteQueryInputEl.focus();
            }
          }, 60);
        };

        const renderTeamMemberGroup = (title, items, groupClassName) => {
          if (!Array.isArray(items) || !items.length) return '';
          return `<section class="team-member-group team-member-group--${escapeTaskMetaHtml(groupClassName)}">
            <p class="team-member-group-title">${escapeTaskMetaHtml(title)}</p>
            ${items.join('')}
          </section>`;
        };

        const renderTeamRoomMembers = (myTeam) => {
          if (!(teamRoomMembersEl instanceof HTMLElement)) return;
          const team = myTeam && typeof myTeam === 'object' ? myTeam : null;
          if (!team) {
            teamRoomMembersEl.innerHTML = '';
            return;
          }
          const isLeader = Boolean(team?.isLeader);
          const members = Array.isArray(team?.members) ? team.members : [];
          const invites = Array.isArray(team?.invites) ? team.invites : [];
          const requests = Array.isArray(team?.requests) ? team.requests : [];
          const minMembers = Math.max(1, Number.parseInt(team?.minMembers ?? teamTaskState?.teamSettings?.teamMin ?? 1, 10) || 1);
          const currentMembersCount = members.length;

          const memberItems = members.map((item) => {
            const meta = resolveInviteeDisplayMeta(item, 'کاربر');
            const workId = String(meta.workId || '').trim();
            const name = meta.name;
            const isLeadMember = String(item?.status || '').trim() === 'leader';
            const statusText = isLeadMember ? 'سرگروه' : 'عضو تیم';
            const canRemove = isLeader && !isLeadMember && workId !== '' && (currentMembersCount - 1) >= minMembers;
            return `<div class="team-member-chip ${isLeadMember ? 'team-member-chip--leader' : 'team-member-chip--member'}">
              <div class="team-member-text">
                <div class="team-member-name">${renderEntityIcon('member')}<span class="team-member-name-label">${escapeTaskMetaHtml(name)}</span></div>
                <div class="team-member-status">${escapeTaskMetaHtml(statusText)}</div>
              </div>
              ${canRemove ? `<button class="team-member-remove team-member-remove--danger" type="button" data-team-remove-work-id="${escapeTaskMetaHtml(workId)}" data-team-remove-kind="member" aria-label="حذف عضو"><i class="ri-user-unfollow-line" aria-hidden="true"></i></button>` : ''}
            </div>`;
          });

          const inviteItems = invites.map((item) => {
            const meta = resolveInviteeDisplayMeta(item, 'کاربر');
            const workId = String(meta.workId || '').trim();
            const name = meta.name;
            const canCancel = isLeader && workId !== '';
            return `<div class="team-member-chip team-member-chip--invited">
              <div class="team-member-text">
                <div class="team-member-name">${renderEntityIcon('invite')}<span class="team-member-name-label">${escapeTaskMetaHtml(name)}</span></div>
                <div class="team-member-status">دعوت در انتظار تایید</div>
              </div>
              ${canCancel ? `<button class="team-member-remove team-member-remove--danger" type="button" data-team-remove-work-id="${escapeTaskMetaHtml(workId)}" data-team-remove-kind="invite" aria-label="لغو دعوت"><i class="ri-mail-close-line" aria-hidden="true"></i></button>` : ''}
            </div>`;
          });

          const requestItems = requests.map((item) => {
            const meta = resolveInviteeDisplayMeta(item, 'کاربر');
            const workId = String(meta.workId || '').trim();
            const name = meta.name;
            const actionButtons = (isLeader && workId !== '')
              ? `<div class="team-member-review-actions">
                  <button class="team-member-remove team-member-remove--accept" type="button" data-team-review-work-id="${escapeTaskMetaHtml(workId)}" data-team-review-decision="accept" aria-label="تایید درخواست"><i class="ri-check-line" aria-hidden="true"></i></button>
                  <button class="team-member-remove team-member-remove--danger" type="button" data-team-review-work-id="${escapeTaskMetaHtml(workId)}" data-team-review-decision="reject" aria-label="رد درخواست"><i class="ri-close-line" aria-hidden="true"></i></button>
                </div>`
              : '';
            return `<div class="team-member-chip team-member-chip--request">
              <div class="team-member-text">
                <div class="team-member-name">${renderEntityIcon('request')}<span class="team-member-name-label">${escapeTaskMetaHtml(name)}</span></div>
                <div class="team-member-status">درخواست عضویت</div>
              </div>
              ${actionButtons}
            </div>`;
          });

          const sections = [
            renderTeamMemberGroup(`اعضای تیم (${memberItems.length})`, memberItems, 'members'),
            renderTeamMemberGroup(`دعوت‌های ارسال شده (${inviteItems.length})`, inviteItems, 'invites'),
            renderTeamMemberGroup(`درخواست‌های عضویت (${requestItems.length})`, requestItems, 'requests')
          ].filter((sectionHtml) => sectionHtml !== '');

          teamRoomMembersEl.innerHTML = sections.length
            ? sections.join('')
            : '<div class="team-list-item"><div class="team-list-item-meta">هنوز عضوی ثبت نشده است.</div></div>';
        };

        const renderTeamRulesText = (context) => {
          if (!(teamRulesTextEl instanceof HTMLElement)) return;
          const settings = context?.teamSettings || {};
          const minMembers = Math.max(1, Number.parseInt(settings?.teamMin ?? 1, 10) || 1);
          const maxMembers = Math.max(minMembers, Number.parseInt(settings?.teamMax ?? minMembers, 10) || minMembers);
          const additionalNote = String(settings?.teamAdditionalNote || '').trim();
          const baseHtml = [
            `<div>حداقل اعضای تیم: ${escapeTaskMetaHtml(minMembers)} نفر</div>`,
            `<div>حداکثر اعضای تیم: ${escapeTaskMetaHtml(maxMembers)} نفر</div>`
          ].join('');
          const additionalHtml = buildInlineInfoRichHtml(additionalNote);
          if (additionalHtml !== '') {
            teamRulesTextEl.innerHTML = `${baseHtml}<div class="team-rules-note">${additionalHtml}</div>`;
            return;
          }
          teamRulesTextEl.innerHTML = baseHtml;
        };

        const renderTeamTaskState = (context) => {
          teamTaskState = context && typeof context === 'object' ? context : null;
          renderTeamRulesText(teamTaskState);
          const additionalText = String(teamTaskState?.teamSettings?.teamAdditionalNote || '').trim();
          if (teamFindAdditionalTextEl) {
            if (additionalText !== '') {
              teamFindAdditionalTextEl.innerHTML = buildInlineInfoRichHtml(additionalText);
              teamFindAdditionalTextEl.classList.remove('hidden');
            } else {
              teamFindAdditionalTextEl.innerHTML = '';
              teamFindAdditionalTextEl.classList.add('hidden');
            }
          }
          const invitedTeams = Array.isArray(teamTaskState?.invitedTeams) ? teamTaskState.invitedTeams : [];
          const publicTeams = Array.isArray(teamTaskState?.publicTeams) ? teamTaskState.publicTeams : [];
          if (teamInvitedGroupEl) {
            teamInvitedGroupEl.classList.toggle('hidden', invitedTeams.length === 0);
          }
          if (invitedTeams.length > 0) {
            renderTeamCardList(teamInvitedListEl, invitedTeams, 'دعوتی فعالی ندارید.', 'invited');
          } else if (teamInvitedListEl) {
            teamInvitedListEl.innerHTML = '';
          }
          renderTeamCardList(teamPublicListEl, publicTeams, 'تیم عمومی فعالی وجود ندارد.', 'public');
          const myTeam = teamTaskState?.myTeam || null;
          const memberCount = Math.max(0, Number.parseInt(myTeam?.memberCount ?? 0, 10) || 0);
          const minMembers = Math.max(1, Number.parseInt(myTeam?.minMembers ?? teamTaskState?.teamSettings?.teamMin ?? 1, 10) || 1);
          const maxMembers = Math.max(1, Number.parseInt(myTeam?.maxMembers ?? teamTaskState?.teamSettings?.teamMax ?? 1, 10) || 1);
          if (myTeam && teamRoomNameEl) {
            teamRoomNameEl.textContent = String(myTeam?.name || 'تیم من');
          }
          if (teamRoomSlotEl) {
            teamRoomSlotEl.textContent = `اعضا: ${memberCount} / ${maxMembers}`;
            teamRoomSlotEl.classList.remove('team-room-slot-badge--need-members', 'team-room-slot-badge--min-reached', 'team-room-slot-badge--max-reached');
            if (!myTeam || memberCount < minMembers) {
              teamRoomSlotEl.classList.add('team-room-slot-badge--need-members');
            } else if (memberCount >= maxMembers) {
              teamRoomSlotEl.classList.add('team-room-slot-badge--max-reached');
            } else {
              teamRoomSlotEl.classList.add('team-room-slot-badge--min-reached');
            }
          }
          renderTeamRoomMembers(myTeam);
          const isLeader = Boolean(myTeam?.isLeader);
          if (teamRoomTopActionsEl) {
            teamRoomTopActionsEl.classList.toggle('hidden', !myTeam);
            teamRoomTopActionsEl.classList.remove('team-room-top-actions--single');
          }
          if (teamOpenInviteBtnEl instanceof HTMLButtonElement) {
            teamOpenInviteBtnEl.classList.toggle('hidden', !myTeam);
            teamOpenInviteBtnEl.disabled = !myTeam;
          }
          if (!myTeam) {
            closeTeamInviteDialog();
          }
          resetTeamInviteLookup();
          if (teamStartBtnEl instanceof HTMLButtonElement) {
            const started = Boolean(myTeam?.started);
            const neededMembers = Math.max(0, minMembers - memberCount);
            if (!myTeam) {
              teamStartBtnEl.disabled = true;
              teamStartBtnEl.textContent = 'شروع چالش';
            } else if (started) {
              teamStartBtnEl.disabled = false;
              teamStartBtnEl.textContent = 'مشاهده چالش';
            } else if (neededMembers > 0) {
              teamStartBtnEl.disabled = true;
              teamStartBtnEl.textContent = `${neededMembers} عضو دیگر برای شروع چالش`;
            } else if (!isLeader) {
              teamStartBtnEl.disabled = true;
              teamStartBtnEl.textContent = 'در انتظار شروع توسط سرگروه';
            } else {
              teamStartBtnEl.disabled = false;
              teamStartBtnEl.textContent = 'شروع چالش';
            }
          }
          if (teamSettingsBtnEl instanceof HTMLButtonElement) {
            teamSettingsBtnEl.disabled = !myTeam;
          }
        };

        const openTeamPreview = (team) => {
          teamTaskPreviewTeam = team && typeof team === 'object' ? team : null;
          if (!teamTaskPreviewTeam) return;
          if (teamPreviewNameEl) {
            teamPreviewNameEl.textContent = String(teamTaskPreviewTeam?.name || 'تیم');
          }
          if (teamPreviewMetaEl) {
            const memberCount = Math.max(0, Number.parseInt(teamTaskPreviewTeam?.memberCount ?? 0, 10) || 0);
            const maxMembers = Math.max(1, Number.parseInt(teamTaskPreviewTeam?.maxMembers ?? 1, 10) || 1);
            const joinTypeBadge = renderTeamAccessBadge(teamTaskPreviewTeam, 'team-meta-badge team-meta-badge--join');
            teamPreviewMetaEl.innerHTML = [
              `<span class="team-meta-badge">${escapeTaskMetaHtml(`${memberCount}/${maxMembers} نفر`)}</span>`,
              joinTypeBadge
            ].filter((part) => part !== '').join('');
          }
          if (teamPreviewMembersEl) {
            const members = Array.isArray(teamTaskPreviewTeam?.members) ? teamTaskPreviewTeam.members : [];
            if (!members.length) {
              teamPreviewMembersEl.innerHTML = '<div class="team-list-item"><div class="team-list-item-meta">عضوی ثبت نشده است.</div></div>';
            } else {
              teamPreviewMembersEl.innerHTML = members.map((item) => {
                const meta = resolveInviteeDisplayMeta(item, 'کاربر');
                const maskedName = maskLastNameToInitial(item, meta.name);
                const roleText = String(item?.status || '').trim() === 'leader' ? 'سرگروه' : 'عضو';
                const roleHint = `<div class="team-member-status">نقش: ${escapeTaskMetaHtml(roleText)}</div>`;
                return `<div class="team-list-item"><div class="team-list-item-title">${renderEntityIcon('member')}<span>${escapeTaskMetaHtml(maskedName)}</span></div>${roleHint}</div>`;
              }).join('');
            }
          }
          if (teamPreviewJoinBtnEl instanceof HTMLButtonElement) {
            const memberCount = Math.max(0, Number.parseInt(teamTaskPreviewTeam?.memberCount ?? 0, 10) || 0);
            const maxMembers = Math.max(1, Number.parseInt(teamTaskPreviewTeam?.maxMembers ?? 1, 10) || 1);
            const isFullTeam = memberCount >= maxMembers;
            const isInvited = Boolean(teamTaskPreviewTeam?.isInvited);
            const isRequested = Boolean(teamTaskPreviewTeam?.isRequested);
            teamPreviewJoinBtnEl.classList.toggle('team-danger-btn', isFullTeam);
            if (isFullTeam) {
              teamPreviewJoinBtnEl.textContent = 'ظرفیت تکمیل است';
              teamPreviewJoinBtnEl.disabled = true;
            } else if (isInvited) {
              teamPreviewJoinBtnEl.textContent = 'عضویت';
              teamPreviewJoinBtnEl.disabled = false;
            } else if (isRequested) {
              teamPreviewJoinBtnEl.textContent = 'درخواست عضویت ثبت شده';
              teamPreviewJoinBtnEl.disabled = true;
            } else if (normalizeTeamJoinTypeClient(teamTaskPreviewTeam?.joinType) === 'public_request') {
              teamPreviewJoinBtnEl.textContent = 'درخواست عضویت';
              teamPreviewJoinBtnEl.disabled = false;
            } else {
              teamPreviewJoinBtnEl.textContent = 'عضویت';
              teamPreviewJoinBtnEl.disabled = false;
            }
          }
          setInfoTaskStep('team_preview');
        };

        const openTeamSettingsView = () => {
          const myTeam = teamTaskState?.myTeam || null;
          if (!myTeam) return;
          closeTeamInviteDialog();
          const isLeader = Boolean(myTeam?.isLeader);
          const started = Boolean(myTeam?.started);
          if (teamSettingsNameInputEl instanceof HTMLInputElement) {
            teamSettingsNameInputEl.value = String(myTeam?.name || '').trim();
            teamSettingsNameInputEl.disabled = !isLeader;
          }
          if (teamSettingsJoinWrapEl) {
            teamSettingsJoinWrapEl.classList.toggle('hidden', !isLeader);
          }
          const selectedJoinType = normalizeTeamJoinTypeClient(myTeam?.joinType);
          teamSettingsJoinInputs.forEach((input) => {
            if (!(input instanceof HTMLInputElement)) return;
            input.checked = input.value === selectedJoinType;
            input.disabled = !isLeader;
          });
          if (teamSettingsSaveBtnEl instanceof HTMLButtonElement) {
            teamSettingsSaveBtnEl.classList.toggle('hidden', !isLeader);
          }
          if (teamSettingsDeleteBtnEl instanceof HTMLButtonElement) {
            teamSettingsDeleteBtnEl.classList.add('hidden');
          }
          if (teamSettingsLeaveBtnEl instanceof HTMLButtonElement) {
            teamSettingsLeaveBtnEl.classList.toggle('hidden', isLeader || started);
          }
          setInfoTaskStep('team_settings');
        };

        const openTeamChallengeView = () => {
          const myTeam = teamTaskState?.myTeam || null;
          if (!myTeam || !myTeam.started) {
            return;
          }
          const challengeName = String(myTeam?.challengeName || '').trim();
          const guideText = [
            String(teamTaskState?.guidePrefix || '').trim(),
            String(myTeam?.challengeGuide || '').trim(),
            String(teamTaskState?.guideSuffix || '').trim()
          ].filter((part) => part !== '').join('\n\n');
          if (teamChallengeTitleEl) {
            teamChallengeTitleEl.textContent = challengeName !== '' ? challengeName : 'راهنمای چالش تیمی';
          }
          if (teamChallengeContentEl) {
            teamChallengeContentEl.innerHTML = buildInfoTaskContentHtml(guideText);
          }
          setInfoTaskStep('team_challenge');
        };

        const refreshTeamTaskState = async (targetStep = '') => {
          if (!currentTaskId) return;
          const payload = await teamTaskPost('state');
          const context = payload?.data?.context || null;
          renderTeamTaskState(context);
          const targetButton = taskButtons.find(
            (button) => String(button?.dataset?.taskId || '').trim() === String(currentTaskId || '').trim()
          );
          if (targetButton instanceof HTMLButtonElement) {
            const completed = String(targetButton.dataset.taskCompleted || '') === '1';
            const startedPending = !completed && Boolean(context?.myTeam?.started);
            targetButton.dataset.taskTeamStartedPending = startedPending ? '1' : '0';
            setTaskButtonState(targetButton, deriveTaskStatusFromButton(targetButton));
          }
          if (targetStep) {
            setInfoTaskStep(targetStep);
            return;
          }
          if (context?.myTeam) {
            setInfoTaskStep('team_room');
            return;
          }
          setInfoTaskStep('team_find');
        };

        const openInfoTaskView = (taskTitle, infoTitle, infoText, options = {}) => {
          pushInPageHistoryState();
          currentTaskType = String(options?.taskType || 'info').trim().toLowerCase() || 'info';
          describePhotoChoices = normalizeDescribePhotoChoices(options?.describePhotos || []);
          describePhotoCurrentIndex = 0;
          describePhotoSelected = null;
          describePhotoBusy = false;
          teamTaskState = (options?.teamContext && typeof options.teamContext === 'object') ? options.teamContext : null;
          teamTaskPreviewTeam = null;
          teamTaskInviteCandidate = null;
          teamTaskPendingName = '';
          if (timerAreaEl) timerAreaEl.classList.add('quiz-hidden');
          if (bottomCtaEl) bottomCtaEl.classList.add('quiz-hidden');
          if (quizAreaEl) quizAreaEl.classList.add('quiz-hidden');
          if (taskInfoAreaEl) taskInfoAreaEl.classList.remove('quiz-hidden');
          setTopbarMode('task');
          if (taskInfoTitleEl) {
            taskInfoTitleEl.textContent = String(infoTitle || taskTitle || 'اطلاعات ماموریت').trim() || 'اطلاعات ماموریت';
          }
          if (taskInfoContentEl) {
            taskInfoContentEl.innerHTML = buildInfoTaskContentHtml(infoText);
          }
          if (currentTaskType === 'describe_photo') {
            renderDescribePhotoChoice();
          } else if (currentTaskType === 'team_task') {
            renderTeamTaskState(teamTaskState);
            const hasTeam = Boolean(teamTaskState?.myTeam);
            setInfoTaskStep(hasTeam ? 'team_room' : 'info', { pushHistory: false });
            infoTaskViewOpen = true;
            return;
          }
          setInfoTaskStep('info', { pushHistory: false });
          infoTaskViewOpen = true;
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

        const postJson = async (body, retriedOnCsrf = false) => {
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
          if (isCsrfMismatchPayload(payload) && !retriedOnCsrf) {
            csrfToken = payload.csrf.trim();
            return postJson(body, true);
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
          const isBackMode = mode === 'rewards' || mode === 'task';
          if (logoutBtn) logoutBtn.classList.toggle('hidden', isBackMode);
          if (topbarBackBtnEl) topbarBackBtnEl.classList.toggle('hidden', !isBackMode);
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
              message: `${formatRewardNumber(needed)} امتیاز دیگر نیاز است تا بتوانید یک کارت دیگر انتخاب کنید`,
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
          const eventStatus = String(rewardsState?.eventStatus || globalEventStatus || 'inactive');
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
              stateText = 'برای دریافت جایزه کلیک کنید';
              rowClasses.push('can-flip');
            } else if (level?.reached && !level?.won && isOutOfValue) {
              stateClass = 'won';
              stateText = 'این جایزه به شما تعلق گرفته';
              rowClasses.push('won');
            } else if (level?.reached && !level?.won && String(level?.type || '') === 'value_sum' && eventStatus === 'upcoming') {
              stateClass = 'reached';
              stateText = 'لطفا تا شروع زمان دریافت جوایز صبر کنید';
            } else if (level?.reached && isOutOfValue) {
              stateClass = 'won';
              stateText = 'این جایزه به شما تعلق گرفته';
              rowClasses.push('won');
            } else if (level?.reached) {
              stateClass = 'reached';
              stateText = 'رسیده‌اید';
            }
            const safeStateClass = ['locked', 'won', 'can-flip', 'reached'].includes(stateClass)
              ? stateClass
              : 'locked';
            const pointsNeedText = (level?.won || (isOutOfValue && reached))
              ? `امتیاز جمع‌آوری‌شده برای این سطح: ${formatRewardNumber(target)}`
              : reached
                ? 'امتیاز مورد نظر کسب شد'
                : `امتیاز موردنیاز: ${formatRewardNumber(left)}`;
            const safeLevelId = escapeHtml(levelId);
            const safeLevelName = escapeHtml(levelName);
            const safePointsNeedText = escapeHtml(pointsNeedText);
            const safeStateText = escapeHtml(stateText);
            return `<div class="${rowClasses.join(' ')}">
              <span class="roadmap-node" aria-hidden="true"></span>
              <button class="roadmap-level-btn" type="button" data-level-id="${safeLevelId}" ${isClickable ? '' : 'disabled'}>
              <div class="roadmap-content">
                <div class="roadmap-level-name">${safeLevelName}</div>
                <div class="roadmap-left">${safePointsNeedText}</div>
                <div class="roadmap-state ${safeStateClass}">${safeStateText}</div>
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
              setRewardTimeBox('زمان باقی‌مانده تا دریافت جوایز', countdown || 'به‌زودی شروع می‌شود');
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

        const refreshRewardState = async ({ force = false } = {}) => {
          if (rewardsRoundBusy && !force) {
            return;
          }
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
          removeTransitionLoader();
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

        const openConfirmDialog = (
          message,
          title = 'تایید عملیات',
          confirmText = 'تایید',
          cancelText = 'انصراف'
        ) => new Promise((resolve) => {
          removeTransitionLoader();
          if (
            !(confirmDialogEl instanceof HTMLElement) ||
            !(confirmDialogTitleEl instanceof HTMLElement) ||
            !(confirmDialogMessageEl instanceof HTMLElement) ||
            !(confirmDialogCancelEl instanceof HTMLButtonElement) ||
            !(confirmDialogConfirmEl instanceof HTMLButtonElement)
          ) {
            resolve(false);
            return;
          }
          confirmDialogTitleEl.textContent = String(title || 'تایید عملیات').trim() || 'تایید عملیات';
          confirmDialogMessageEl.textContent = String(message || '').trim() || '—';
          confirmDialogCancelEl.textContent = String(cancelText || 'انصراف').trim() || 'انصراف';
          confirmDialogConfirmEl.textContent = String(confirmText || 'تایید').trim() || 'تایید';
          confirmDialogEl.classList.add('open');
          confirmDialogEl.setAttribute('aria-hidden', 'false');

          const close = (result) => {
            confirmDialogEl.classList.remove('open');
            confirmDialogEl.setAttribute('aria-hidden', 'true');
            confirmDialogCancelEl.removeEventListener('click', onCancel);
            confirmDialogConfirmEl.removeEventListener('click', onConfirm);
            confirmDialogEl.removeEventListener('click', onOverlay);
            resolve(Boolean(result));
          };

          const onCancel = () => close(false);
          const onConfirm = () => close(true);
          const onOverlay = (event) => {
            if (event.target === confirmDialogEl) {
              close(false);
            }
          };

          confirmDialogCancelEl.addEventListener('click', onCancel);
          confirmDialogConfirmEl.addEventListener('click', onConfirm);
          confirmDialogEl.addEventListener('click', onOverlay);
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
          // Keep reveal smooth for any remaining-card count; avoid long pauses when only a few are left.
          const staggerMs = 700;
          try {
            for (let i = 0; i < otherButtons.length; i += 1) {
              otherButtons[i].classList.add('is-revealed');
              await new Promise((resolve) => setTimeout(resolve, staggerMs));
            }
          } catch {}
          // Ensure selected card reveal is painted before opening win dialog.
          await new Promise((resolve) => requestAnimationFrame(resolve));
          pickedButton.classList.add('is-revealed');
          await new Promise((resolve) => setTimeout(resolve, 300));
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
            await refreshRewardState({ force: true });
          } catch (error) {
            await openInfoDialog(error?.message || 'باز کردن کارت ناموفق بود.');
          } finally {
            rewardsRoundBusy = false;
            updateLockedCardButtonsState();
          }
        };

        const openRewardCardsSlide = async (levelId, options = {}) => {
          const shouldPushHistory = options?.pushHistory !== false;
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
            await openInfoDialog(`زمان دریافت جوایز فرا نرسیده، لطفا صبر کنید.\n${countdown || 'به‌زودی'}`);
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
          if (shouldPushHistory) {
            pushInPageHistoryState();
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

        const openRewardsView = async (options = {}) => {
          const shouldPushHistory = options?.pushHistory !== false;
          if (shouldPushHistory && !rewardsViewOpen) {
            pushInPageHistoryState();
          }
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
          }, QUIZ_FEEDBACK_DELAY_MS);
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
            }, QUIZ_FEEDBACK_DELAY_MS);
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
          }, QUIZ_FEEDBACK_DELAY_MS);
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
          }, QUIZ_FEEDBACK_DELAY_MS);
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
            const describeSubmitted = Boolean(progress?.describeSubmitted);
            button.dataset.taskDescribeSubmitted = describeSubmitted ? '1' : '0';
            const teamStartedPending = Boolean(progress?.teamStartedPending);
            button.dataset.taskTeamStartedPending = teamStartedPending ? '1' : '0';
            setTaskButtonState(button, deriveTaskStatusFromButton(button));
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

            const fetchedTaskType = String(payload?.task?.taskType || button?.dataset?.taskType || 'quiz').trim().toLowerCase();
            if (fetchedTaskType === 'info' || fetchedTaskType === 'team_task' || fetchedTaskType === 'describe_photo') {
              currentTaskId = taskId;
              currentTaskTitle = String(payload?.task?.title ?? button?.dataset?.taskTitle ?? 'ماموریت اطلاعاتی').trim();
              const describePhotos = Array.isArray(payload?.task?.describePhotos) ? payload.task.describePhotos : [];
              const teamContext = payload?.task?.teamContext && typeof payload.task.teamContext === 'object'
                ? payload.task.teamContext
                : null;
              openInfoTaskView(
                currentTaskTitle,
                String(payload?.task?.infoTitle ?? '').trim(),
                String(payload?.task?.infoText ?? '').trim(),
                {
                  taskType: fetchedTaskType,
                  describePhotos,
                  teamContext
                }
              );
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
            pushInPageHistoryState();
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
        if (taskInfoAckBtnEl) {
          taskInfoAckBtnEl.addEventListener('click', async () => {
            if (currentTaskType === 'describe_photo') {
              if (!describePhotoChoices.length) {
                await openInfoDialog('برای این ماموریت تصویری ثبت نشده است.', 'ماموریت تصویر');
                closeQuizOverlay();
                return;
              }
              await withTransitionLoader(async () => {
                renderDescribePhotoChoice();
                setInfoTaskStep('photo');
              }, {
                primaryText: 'در حال آماده‌سازی مرحله بعد',
                secondaryText: 'در حال بارگذاری تصاویر ماموریت',
                delayMs: 120
              });
              return;
            }
            if (currentTaskType === 'team_task') {
              try {
                await withTransitionLoader(async () => {
                  await refreshTeamTaskState();
                }, {
                  primaryText: 'در حال آماده‌سازی تیم',
                  secondaryText: 'در حال دریافت وضعیت تیم‌ها',
                  delayMs: 120
                });
              } catch (error) {
                await openInfoDialog(error?.message || 'دریافت وضعیت تیم‌ها ناموفق بود.');
              }
              return;
            }
            closeQuizOverlay();
          });
        }

        if (describePhotoChangeBtnEl) {
          describePhotoChangeBtnEl.addEventListener('click', () => {
            if (!describePhotoChoices.length) return;
            describePhotoCurrentIndex = (describePhotoCurrentIndex + 1) % describePhotoChoices.length;
            renderDescribePhotoChoice();
          });
        }

        if (describePhotoSelectBtnEl) {
          describePhotoSelectBtnEl.addEventListener('click', () => {
            void withTransitionLoader(
              () => openDescribePhotoEditor(),
              {
                primaryText: 'در حال آماده‌سازی ویرایش تصویر',
                secondaryText: 'در حال دریافت محتوای ذخیره‌شده',
                delayMs: 120
              }
            );
          });
        }

        if (describePhotoTextareaEl) {
          describePhotoTextareaEl.addEventListener('input', () => {
            updateDescribePhotoWordCount();
          });
        }

        if (describePhotoSaveBtnEl) {
          describePhotoSaveBtnEl.addEventListener('click', () => {
            void withTransitionLoader(
              () => saveDescribePhotoEditor(),
              {
                primaryText: 'در حال ذخیره پاسخ',
                secondaryText: 'لطفا چند لحظه صبر کنید',
                delayMs: 120
              }
            );
          });
        }

        const findTeamFromCurrentContext = (teamId) => {
          const target = String(teamId || '').trim();
          if (target === '') return null;
          const myTeam = teamTaskState?.myTeam && String(teamTaskState.myTeam.id || '').trim() === target
            ? teamTaskState.myTeam
            : null;
          if (myTeam) return myTeam;
          const invited = (Array.isArray(teamTaskState?.invitedTeams) ? teamTaskState.invitedTeams : [])
            .find((item) => String(item?.id || '').trim() === target);
          if (invited) return invited;
          const publicTeam = (Array.isArray(teamTaskState?.publicTeams) ? teamTaskState.publicTeams : [])
            .find((item) => String(item?.id || '').trim() === target);
          return publicTeam || null;
        };

        if (teamCreateBtnEl instanceof HTMLButtonElement) {
          teamCreateBtnEl.addEventListener('click', () => {
            setInfoTaskStep('team_create_name');
          });
        }

        if (teamCreateFromFindBtnEl instanceof HTMLButtonElement) {
          teamCreateFromFindBtnEl.addEventListener('click', () => {
            setInfoTaskStep('team_rules');
          });
        }

        if (teamCreateNameConfirmBtnEl instanceof HTMLButtonElement) {
          teamCreateNameConfirmBtnEl.addEventListener('click', async () => {
            const teamName = String(teamCreateNameInputEl?.value || '').trim();
            if (teamName === '') {
              await openInfoDialog('نام تیم را وارد کنید.');
              return;
            }
            teamTaskPendingName = teamName;
            setInfoTaskStep('team_create_type');
          });
        }

        if (teamCreateTypeConfirmBtnEl instanceof HTMLButtonElement) {
          teamCreateTypeConfirmBtnEl.addEventListener('click', () => {
            const selectedInput = teamCreateJoinTypeInputs.find((input) => (
              input instanceof HTMLInputElement && input.checked
            ));
            const joinType = normalizeTeamJoinTypeClient(selectedInput?.value || 'private');
            const teamName = String(teamTaskPendingName || '').trim();
            if (teamName === '') {
              setInfoTaskStep('team_create_name');
              return;
            }
            void withTransitionLoader(
              async () => {
                await teamTaskPost('create', { teamName, joinType });
                teamTaskPendingName = '';
                await refreshTeamTaskState('team_room');
              },
              {
                primaryText: 'در حال ساخت تیم',
                secondaryText: 'لطفا چند لحظه صبر کنید',
                delayMs: 120
              }
            ).catch(async (error) => {
              await openInfoDialog(error?.message || 'ساخت تیم ناموفق بود.');
            });
          });
        }

        if (teamOpenSearchBtnEl instanceof HTMLButtonElement) {
          teamOpenSearchBtnEl.addEventListener('click', () => {
            setInfoTaskStep('team_search');
          });
        }

        if (teamSearchLeaderBtnEl instanceof HTMLButtonElement) {
          teamSearchLeaderBtnEl.addEventListener('click', () => {
            const query = String(teamSearchLeaderInputEl?.value || '').trim();
            if (query === '') {
              void openInfoDialog('شماره پرسنلی (Work ID) سرگروه را وارد کنید.');
              return;
            }
            void withTransitionLoader(
              async () => {
                const payload = await teamTaskPost('find_by_leader', { query });
                const team = payload?.data?.team || null;
                if (!team) {
                  throw new Error('تیمی پیدا نشد.');
                }
                openTeamPreview(team);
              },
              {
                primaryText: 'در حال جستجوی تیم',
                secondaryText: 'لطفا صبر کنید',
                delayMs: 120
              }
            ).catch(async (error) => {
              await openInfoDialog(error?.message || 'جستجوی تیم ناموفق بود.');
            });
          });
        }

        const onTeamListClick = (event) => {
          const target = event.target;
          if (!(target instanceof Element)) return;
          const button = target.closest('[data-team-open-id]');
          if (!(button instanceof HTMLButtonElement)) return;
          const teamId = String(button.dataset.teamOpenId || '').trim();
          if (teamId === '') return;
          const team = findTeamFromCurrentContext(teamId);
          if (!team) return;
          openTeamPreview(team);
        };
        if (teamInvitedListEl instanceof HTMLElement) {
          teamInvitedListEl.addEventListener('click', onTeamListClick);
        }
        if (teamPublicListEl instanceof HTMLElement) {
          teamPublicListEl.addEventListener('click', onTeamListClick);
        }

        if (teamPreviewJoinBtnEl instanceof HTMLButtonElement) {
          teamPreviewJoinBtnEl.addEventListener('click', () => {
            const teamId = String(teamTaskPreviewTeam?.id || '').trim();
            if (teamId === '') return;
            void withTransitionLoader(
              async () => {
                const payload = await teamTaskPost('join', { teamId });
                const joined = Boolean(payload?.data?.joined);
                await refreshTeamTaskState(joined ? 'team_room' : 'team_find');
              },
              {
                primaryText: 'در حال ثبت عضویت',
                secondaryText: 'لطفا چند لحظه صبر کنید',
                delayMs: 120
              }
            ).catch(async (error) => {
              await openInfoDialog(error?.message || 'ثبت عضویت ناموفق بود.');
            });
          });
        }

        if (teamOpenInviteBtnEl instanceof HTMLButtonElement) {
          teamOpenInviteBtnEl.addEventListener('click', () => {
            openTeamInviteDialog();
          });
        }

        if (teamInviteDialogEl instanceof HTMLElement) {
          teamInviteDialogEl.addEventListener('click', (event) => {
            if (event.target === teamInviteDialogEl) {
              closeTeamInviteDialog();
            }
          });
        }

        const runTeamInviteLookup = () => {
          const query = String(teamInviteQueryInputEl?.value || '').trim();
          if (query === '') {
            void openInfoDialog('شماره پرسنلی (Work ID) کاربر را وارد کنید.');
            return;
          }
          void withTransitionLoader(
            async () => {
              const payload = await teamTaskPost('lookup_user', { query });
              const invitee = payload?.data?.invitee || null;
              if (!invitee) {
                throw new Error('کاربری پیدا نشد.');
              }
              if (payload?.data?.isMemberAny) {
                throw new Error('این کاربر هم‌اکنون عضو یک تیم است.');
              }
              teamTaskInviteCandidate = invitee;
              if (teamInviteResultNameEl) {
                const inviteeMeta = resolveInviteeDisplayMeta(invitee, 'کاربر');
                const workIdHint = inviteeMeta.workId !== '' ? `<small class="team-list-item-hint">شناسه: ${escapeTaskMetaHtml(inviteeMeta.workId)}</small>` : '';
                teamInviteResultNameEl.innerHTML = `${renderEntityIcon('member')}<span class="team-invite-result-text"><span>${escapeTaskMetaHtml(inviteeMeta.name)}</span>${workIdHint}</span>`;
              }
              if (teamInviteResultEl) {
                teamInviteResultEl.classList.remove('hidden');
              }
              setTeamInviteDialogActionMode('invite');
            },
            {
              primaryText: 'در حال جستجوی کاربر',
              secondaryText: 'لطفا صبر کنید',
              delayMs: 120
            }
          ).catch(async (error) => {
            resetTeamInviteLookup({ clearInput: false });
            await openInfoDialog(error?.message || 'جستجوی کاربر ناموفق بود.');
          });
        };

        const runTeamInviteSubmit = () => {
          const targetWorkId = String(teamTaskInviteCandidate?.workId || '').trim();
          if (targetWorkId === '') {
            void openInfoDialog('ابتدا کاربر را جستجو کنید.');
            return;
          }
          void withTransitionLoader(
            async () => {
              await teamTaskPost('invite', { targetWorkId });
              await refreshTeamTaskState('team_room');
              closeTeamInviteDialog();
            },
            {
              primaryText: 'در حال ارسال دعوت',
              secondaryText: 'لطفا چند لحظه صبر کنید',
              delayMs: 120
            }
          ).catch(async (error) => {
            await openInfoDialog(error?.message || 'ارسال دعوت ناموفق بود.');
          });
        };

        if (teamInviteActionBtnEl instanceof HTMLButtonElement) {
          teamInviteActionBtnEl.addEventListener('click', () => {
            if (teamInviteDialogActionMode === 'invite') {
              runTeamInviteSubmit();
              return;
            }
            runTeamInviteLookup();
          });
        }

        if (teamInviteQueryInputEl instanceof HTMLInputElement) {
          teamInviteQueryInputEl.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
              event.preventDefault();
              if (teamInviteActionBtnEl instanceof HTMLButtonElement) {
                teamInviteActionBtnEl.click();
              }
            }
          });
          teamInviteQueryInputEl.addEventListener('input', () => {
            if (teamInviteDialogActionMode === 'invite') {
              resetTeamInviteLookup({ clearInput: false });
            }
          });
        }

        const performInternalBackAction = () => {
          if (rewardsCardsViewOpen) {
            closeRewardCardsSlide();
            return true;
          }
          if (rewardsViewOpen) {
            closeRewardsView();
            return true;
          }
          if (infoTaskViewOpen) {
            if (currentTaskType === 'describe_photo') {
              if (infoTaskCurrentStep === 'editor') {
                setInfoTaskStep('photo');
                return true;
              }
              if (infoTaskCurrentStep === 'photo') {
                setInfoTaskStep('info');
                return true;
              }
            } else if (currentTaskType === 'team_task') {
              if (infoTaskCurrentStep === 'team_room' && teamInviteDialogEl instanceof HTMLElement && teamInviteDialogEl.classList.contains('open')) {
                closeTeamInviteDialog();
                return true;
              }
              if (infoTaskCurrentStep === 'team_create_name') {
                setInfoTaskStep('team_rules');
                return true;
              }
              if (infoTaskCurrentStep === 'team_create_type') {
                setInfoTaskStep('team_create_name');
                return true;
              }
              if (infoTaskCurrentStep === 'team_rules') {
                setInfoTaskStep('team_find');
                return true;
              }
              if (infoTaskCurrentStep === 'team_find') {
                setInfoTaskStep('info');
                return true;
              }
              if (infoTaskCurrentStep === 'team_search' || infoTaskCurrentStep === 'team_preview') {
                setInfoTaskStep('team_find');
                return true;
              }
              if (infoTaskCurrentStep === 'team_settings' || infoTaskCurrentStep === 'team_challenge') {
                setInfoTaskStep('team_room');
                return true;
              }
              if (infoTaskCurrentStep === 'team_room') {
                closeQuizOverlay();
                return true;
              }
            }
            closeQuizOverlay();
            return true;
          }
          return false;
        };

        window.addEventListener('popstate', (event) => {
          const previousDepth = tcmInPageHistoryDepth;
          const nextDepth = parseHistoryDepth(event?.state);
          if (nextDepth !== null) {
            tcmInPageHistoryDepth = nextDepth;
          }
          if (tcmSuppressNextPopstate) {
            tcmSuppressNextPopstate = false;
            return;
          }
          if (isTeamInviteDialogOpen()) {
            closeTeamInviteDialog({ fromPopState: true });
            return;
          }
          const goingBack = nextDepth === null ? true : nextDepth < previousDepth;
          if (!goingBack) return;
          runWithoutHistorySync(() => {
            performInternalBackAction();
          });
        });

        if (teamRoomMembersEl instanceof HTMLElement) {
          teamRoomMembersEl.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof Element)) return;
            const removeBtn = target.closest('[data-team-remove-work-id]');
            if (removeBtn instanceof HTMLButtonElement) {
              const targetWorkId = String(removeBtn.dataset.teamRemoveWorkId || '').trim();
              if (targetWorkId === '') return;
              void withTransitionLoader(
                async () => {
                  await teamTaskPost('remove_member', { targetWorkId });
                  await refreshTeamTaskState('team_room');
                },
                {
                  primaryText: 'در حال ثبت تغییرات تیم',
                  secondaryText: 'لطفا صبر کنید',
                  delayMs: 120
                }
              ).catch(async (error) => {
                await openInfoDialog(error?.message || 'ثبت تغییرات ناموفق بود.');
              });
              return;
            }
            const reviewBtn = target.closest('[data-team-review-work-id]');
            if (reviewBtn instanceof HTMLButtonElement) {
              const targetWorkId = String(reviewBtn.dataset.teamReviewWorkId || '').trim();
              const decision = String(reviewBtn.dataset.teamReviewDecision || 'reject').trim().toLowerCase();
              const teamId = String(teamTaskState?.myTeam?.id || '').trim();
              if (teamId === '' || targetWorkId === '') return;
              void withTransitionLoader(
                async () => {
                  await teamTaskPost('review_request', { teamId, targetWorkId, decision });
                  await refreshTeamTaskState('team_room');
                },
                {
                  primaryText: 'در حال بررسی درخواست',
                  secondaryText: 'لطفا صبر کنید',
                  delayMs: 120
                }
              ).catch(async (error) => {
                await openInfoDialog(error?.message || 'ثبت نتیجه درخواست ناموفق بود.');
              });
            }
          });
        }

        if (teamStartBtnEl instanceof HTMLButtonElement) {
          teamStartBtnEl.addEventListener('click', () => {
            const myTeam = teamTaskState?.myTeam || null;
            if (!myTeam) return;
            if (myTeam?.started) {
              openTeamChallengeView();
              return;
            }
            void withTransitionLoader(
              async () => {
                await teamTaskPost('start');
                await refreshTeamTaskState('team_room');
                openTeamChallengeView();
              },
              {
                primaryText: 'در حال شروع چالش تیمی',
                secondaryText: 'لطفا چند لحظه صبر کنید',
                delayMs: 120
              }
            ).catch(async (error) => {
              await openInfoDialog(error?.message || 'شروع چالش ناموفق بود.');
            });
          });
        }

        if (teamSettingsBtnEl instanceof HTMLButtonElement) {
          teamSettingsBtnEl.addEventListener('click', () => {
            openTeamSettingsView();
          });
        }

        if (teamSettingsSaveBtnEl instanceof HTMLButtonElement) {
          teamSettingsSaveBtnEl.addEventListener('click', () => {
            const teamName = String(teamSettingsNameInputEl?.value || '').trim();
            const selectedJoinInput = teamSettingsJoinInputs.find((input) => (
              input instanceof HTMLInputElement && input.checked
            ));
            const joinType = normalizeTeamJoinTypeClient(selectedJoinInput?.value || 'private');
            void withTransitionLoader(
              async () => {
                await teamTaskPost('settings', { teamName, joinType });
                await refreshTeamTaskState('team_room');
              },
              {
                primaryText: 'در حال ذخیره تنظیمات تیم',
                secondaryText: 'لطفا صبر کنید',
                delayMs: 120
              }
            ).catch(async (error) => {
              await openInfoDialog(error?.message || 'ذخیره تنظیمات تیم ناموفق بود.');
            });
          });
        }

        if (teamSettingsDeleteBtnEl instanceof HTMLButtonElement) {
          teamSettingsDeleteBtnEl.addEventListener('click', () => {
            void (async () => {
              const shouldDelete = await openConfirmDialog(
                'آیا از حذف کامل تیم مطمئن هستید؟ این عملیات قابل بازگشت نیست.',
                'حذف تیم',
                'حذف تیم',
                'انصراف'
              );
              if (!shouldDelete) {
                return;
              }
              await withTransitionLoader(
                async () => {
                  await teamTaskPost('settings', { deleteTeam: true });
                  await refreshTeamTaskState('team_find');
                },
                {
                  primaryText: 'در حال حذف تیم',
                  secondaryText: 'لطفا صبر کنید',
                  delayMs: 120
                }
              ).catch(async (error) => {
                await openInfoDialog(error?.message || 'حذف تیم ناموفق بود.');
              });
            })();
          });
        }

        if (teamSettingsLeaveBtnEl instanceof HTMLButtonElement) {
          teamSettingsLeaveBtnEl.addEventListener('click', () => {
            void withTransitionLoader(
              async () => {
                await teamTaskPost('settings', { leaveTeam: true });
                await refreshTeamTaskState('team_find');
              },
              {
                primaryText: 'در حال خروج از تیم',
                secondaryText: 'لطفا صبر کنید',
                delayMs: 120
              }
            ).catch(async (error) => {
              await openInfoDialog(error?.message || 'خروج از تیم ناموفق بود.');
            });
          });
        }

        if (teamChallengeBackBtnEl instanceof HTMLButtonElement) {
          teamChallengeBackBtnEl.addEventListener('click', () => {
            setInfoTaskStep('team_room');
          });
        }

        if (openRewardsBtnEl) {
          openRewardsBtnEl.addEventListener('click', () => {
            void withTransitionLoader(
              () => openRewardsView(),
              {
                primaryText: 'در حال آماده‌سازی بخش جوایز',
                secondaryText: 'در حال بارگذاری اطلاعات جوایز',
                delayMs: 120
              }
            );
          });
        }

        if (topbarBackBtnEl) {
          topbarBackBtnEl.addEventListener('click', () => {
            if (requestInPageBackByHistory()) {
              return;
            }
            runWithoutHistorySync(() => {
              performInternalBackAction();
            });
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
            void withTransitionLoader(
              () => openRewardCardsSlide(levelId),
              {
                primaryText: 'در حال آماده‌سازی کارت‌ها',
                secondaryText: 'لطفا چند لحظه صبر کنید',
                delayMs: 120
              }
            );
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
            void withTransitionLoader(
              () => startTaskQuiz(button),
              {
                primaryText: 'در حال آماده‌سازی ماموریت',
                secondaryText: 'در حال بارگذاری محتوای ماموریت',
                delayMs: 120
              }
            );
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

        initInPageHistoryState();
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







