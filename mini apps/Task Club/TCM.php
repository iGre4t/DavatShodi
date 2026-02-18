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

$prizeStorePath = __DIR__ . '/TC Prizes.json';
$questionsStorePath = __DIR__ . '/TCQ list.json';
$tcqSettingsPath = __DIR__ . '/TCQ settings.json';
$inviteesFilePath = __DIR__ . '/TC Event/Invitees mapped.csv';
$answersSheetPath = __DIR__ . '/TC Event/Answers.csv';
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
    'نام و نام خانوادگی',
    'نام‌و‌نام خانوادگی',
    'نام کامل',
    'نام'
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
    'Answered'
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
      echo json_encode(['status' => 'error', 'message' => 'تعداد تلاش ناموفق زیاد است. کمی بعد دوباره امتحان کنید.']);
      exit;
    }
    $recordFail = function () use (&$attempts, $attemptKey, $now, &$fails, $loginAttemptsPath) {
      $fails[] = $now;
      $attempts[$attemptKey] = ['fails' => $fails];
      writeLoginAttempts($loginAttemptsPath, $attempts);
    };
    if ($username === '' || $password === '') {
      $recordFail();
      echo json_encode(['status' => 'error', 'message' => 'اطلاعات ورود کامل نیست.']);
      exit;
    }
    $table = loadInviteesTable($inviteesFilePath, $inviteesMapPath);
    $rows = $table['rows'];
    if (!$rows) {
      $recordFail();
      echo json_encode(['status' => 'error', 'message' => 'لیست کاربران موجود نیست.']);
      exit;
    }
    $workIdIndex = $table['workIdIndex'];
    if ($workIdIndex < 0) {
      $recordFail();
      echo json_encode(['status' => 'error', 'message' => 'ستون نام کاربری مشخص نشده است.']);
      exit;
    }
    $columns = $table['columns']['index'] ?? [];
    $passwordIndex = $columns['password'] ?? findHeaderIndex($table['header'], 'password');
    if ($passwordIndex < 0) {
      $recordFail();
      echo json_encode(['status' => 'error', 'message' => 'ستون گذرواژه موجود نیست.']);
      exit;
    }
    $rowIndex = findInviteeRowIndex($rows, $workIdIndex, $username);
    if ($rowIndex < 0) {
      $recordFail();
      echo json_encode(['status' => 'error', 'message' => 'نام کاربری یا گذرواژه اشتباه است.']);
      exit;
    }
    $rowPassword = trim((string)($rows[$rowIndex][$passwordIndex] ?? ''));
    if ($rowPassword === '' || $rowPassword !== $password) {
      $recordFail();
      echo json_encode(['status' => 'error', 'message' => 'نام کاربری یا گذرواژه اشتباه است.']);
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
      echo json_encode(['status' => 'error', 'message' => 'ذخیره اطلاعات ورود انجام نشد.']);
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


  if ($action === 'log_roll') {
    $sessionWorkId = (string)($_SESSION['tc_work_id'] ?? '');
    if (!(($_SESSION['tc_authed'] ?? false) && $sessionWorkId !== '')) {
      echo json_encode(['status' => 'error', 'message' => 'ورود انجام نشده است.']);
      exit;
    }
    $table = loadInviteesTable($inviteesFilePath, $inviteesMapPath);
    $rows = $table['rows'];
    $workIdIndex = $table['workIdIndex'];
    $columns = $table['columns']['index'] ?? [];
    $rollIndex = $columns['count of rolls'] ?? -1;
    $prizeIndex = $columns['prize won'] ?? -1;
    $rowIndex = findInviteeRowIndex($rows, $workIdIndex, $sessionWorkId);
    if ($rowIndex < 0 || $rollIndex < 0) {
      echo json_encode(['status' => 'error', 'message' => 'ردیف کاربر پیدا نشد.']);
      exit;
    }
    if ($prizeIndex >= 0) {
      $already = trim((string)($rows[$rowIndex][$prizeIndex] ?? ''));
      if ($already !== '') {
        echo json_encode(['status' => 'error', 'message' => 'جایزه قبلاً ثبت شده است.']);
        exit;
      }
    }
    $rolls = (int)($rows[$rowIndex][$rollIndex] ?? 0);
    $rows[$rowIndex][$rollIndex] = (string)($rolls + 1);
    if (($table['columns']['added'] ?? false) && $rows) {
      writeInviteesCsv($inviteesFilePath, $rows);
    } else if (!writeInviteesCsv($inviteesFilePath, $rows)) {
      echo json_encode(['status' => 'error', 'message' => 'ذخیره تعداد چرخش انجام نشد.']);
      exit;
    }
    echo json_encode(['status' => 'ok']);
    exit;
  }

  if ($action === 'log_answer') {
    $sessionWorkId = (string)($_SESSION['tc_work_id'] ?? '');
    if (!(($_SESSION['tc_authed'] ?? false) && $sessionWorkId !== '')) {
      echo json_encode(['status' => 'error', 'message' => 'ÙˆØ±ÙˆØ¯ Ø§Ù†Ø¬Ø§Ù… Ù†Ø´Ø¯Ù‡ Ø§Ø³Øª.']);
      exit;
    }
    $questionCode = strtoupper(trim((string)($payload['questionCode'] ?? '')));
    $question = trim((string)($payload['question'] ?? ''));
    $answer = trim((string)($payload['answer'] ?? ''));
    if ($questionCode === '' || $question === '') {
      echo json_encode(['status' => 'error', 'message' => 'Ø³ÙˆØ§Ù„ Ø§Ø±Ø³Ø§Ù„ Ù†Ø´Ø¯Ù‡ Ø§Ø³Øª.']);
      exit;
    }
    if (!logAnswerValue($answersSheetPath, $questionsStorePath, $sessionWorkId, $questionCode, $question, $answer)) {
      echo json_encode(['status' => 'error', 'message' => 'Ø°Ø®ÛŒØ±Ù‡ Ù¾Ø§Ø³Ø® Ø§Ù†Ø¬Ø§Ù… Ù†Ø´Ø¯.']);
      exit;
    }
    echo json_encode(['status' => 'ok']);
    exit;
  }

  if ($action === 'update_answered') {
    $sessionWorkId = (string)($_SESSION['tc_work_id'] ?? '');
    if (!(($_SESSION['tc_authed'] ?? false) && $sessionWorkId !== '')) {
      echo json_encode(['status' => 'error', 'message' => 'ورود انجام نشده است.']);
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
      echo json_encode(['status' => 'error', 'message' => 'ردیف کاربر پیدا نشد.']);
      exit;
    }
    $questionCount = count(readQuestionStore($questionsStorePath));
    $answered = clampAnsweredCount($answeredRaw, $questionCount);
    $rows[$rowIndex][$answeredIndex] = (string)$answered;
    if (($table['columns']['added'] ?? false) && $rows) {
      writeInviteesCsv($inviteesFilePath, $rows);
    } else if (!writeInviteesCsv($inviteesFilePath, $rows)) {
      echo json_encode(['status' => 'error', 'message' => 'ذخیره وضعیت پاسخ انجام نشد.']);
      exit;
    }
    echo json_encode(['status' => 'ok']);
    exit;
  }

  if ($action === 'log_prize') {
    $sessionWorkId = (string)($_SESSION['tc_work_id'] ?? '');
    if (!(($_SESSION['tc_authed'] ?? false) && $sessionWorkId !== '')) {
      echo json_encode(['status' => 'error', 'message' => 'ورود انجام نشده است.']);
      exit;
    }
    $prizeName = trim((string)($payload['prize'] ?? ''));
    if ($prizeName === '') {
      echo json_encode(['status' => 'error', 'message' => 'جایزه مشخص نیست.']);
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
      echo json_encode(['status' => 'error', 'message' => 'ردیف کاربر پیدا نشد.']);
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
      echo json_encode(['status' => 'error', 'message' => 'ذخیره جایزه انجام نشد.']);
      exit;
    }
    echo json_encode(['status' => 'ok']);
    exit;
  }

  if ($action === 'decrement_prize') {
    $name = trim((string)($payload['name'] ?? ''));
    if ($name === '') {
      echo json_encode(['status' => 'error', 'message' => 'نام جایزه ارسال نشده است.']);
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
      if ($itemName !== '' && $itemName === $name && !$isFake) {
        $last = max(0, $last - 1);
      }
      if ($itemName !== '') {
        $updated[] = [
          'name' => $itemName,
          'onWheelName' => $onWheelName !== '' ? $onWheelName : $itemName,
          'quantity' => $quantity > 0 ? $quantity : 0,
          'last' => $last > 0 ? $last : 0,
          'isFake' => $isFake
        ];
      }
    }
    if (!writePrizeStore($prizeStorePath, $updated)) {
      echo json_encode(['status' => 'error', 'message' => 'ذخیره جایزه‌ها انجام نشد.']);
      exit;
    }
    echo json_encode(['status' => 'ok', 'data' => $updated]);
    exit;
  }

  echo json_encode(['status' => 'error', 'message' => 'درخواست پشتیبانی نمی‌شود.']);
  exit;
}

$initialPrizes = readPrizeStore($prizeStorePath);
$initialQuestions = readQuestionStore($questionsStorePath);
syncAnswersSheet($answersSheetPath, readQuestionColumnsFromStore($questionsStorePath));
$wheelSettings = loadJsonPayload(__DIR__ . '/Setting.json');
$panelSettings = loadPanelSettings();
$faviconUrl = formatSiteIconUrlForHtml((string)($panelSettings['siteIcon'] ?? ''));
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
  $rawHintHtml = htmlspecialchars('شانس خودت رو امتحان کن و جایزه ببر', ENT_QUOTES, 'UTF-8');
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
  'answerTimeLimit' => (bool)($tcqSettingsForPayload['answerTimeLimit'] ?? true),
  'randomOrder' => (bool)($tcqSettingsForPayload['randomOrder'] ?? true)
];
?>
<!doctype html>
<html lang="fa" dir="rtl">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>چرخ شانس شگفتانه</title>
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
        margin: 0;
        font-size: 0.96rem;
        color: #506081;
        letter-spacing: 0.12em;
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
        color: #33466f;
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
        color: #ff4f00;
        line-height: 1;
        text-shadow: 0 8px 18px rgba(255, 79, 0, 0.28);
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

      .time-counter-result {
        width: min(340px, calc(100vw - 84px));
        min-height: 4.2em;
        height: auto;
        padding: 10px 12px;
        order: 4;
      }

      .time-counter-result .result-label {
        display: block;
        font-size: 0.72rem;
        color: #6b7a99;
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

        .time-counter-result {
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
        <p class="loader-subtext">لطفاً چند لحظه صبر کنید</p>
      </div>
    </div>
    <main class="app">
      <section class="phone">
    <div class="topbar">
          <p class="brand">چرخ شانس شگفتانه</p>
          <div class="topbar-actions">
            <?php if ($sessionPayload['authed']): ?>
              <button id="tc-logout" class="logout-btn" type="button">
                <span aria-hidden="true"></span>
                خروج
              </button>
            <?php endif; ?>
            <div id="tc-status" class="wheel-status hidden"></div>
          </div>
        </div>

        <?php if (!$sessionPayload['authed']): ?>
        <div class="login-area">
          <div class="login-hero">
            <?php if ($faviconUrl !== ''): ?>
              <img class="login-icon" src="<?= htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8') ?>" alt="آیکون سایت" />
            <?php else: ?>
              <div class="question">
                <span>?</span>
              </div>
            <?php endif; ?>
            <h2 class="login-title">چرخونه شگفتانه</h2>
          </div>
          <form id="tc-login-form" class="login-form" autocomplete="on">
            <label class="login-field">
              <span>نام کاربری</span>
              <input id="tc-login-user" class="login-input" type="text" autocomplete="username" required />
            </label>
            <label class="login-field">
              <span>گذرواژه</span>
              <input id="tc-login-pass" class="login-input" type="password" autocomplete="current-password" required />
            </label>
            <button type="submit" class="login-btn">ورود</button>
            <p id="tc-login-msg" class="login-hint" aria-live="polite"></p>
          </form>
        </div>
      <?php else: ?>
        <div id="tc-quiz-area" class="quiz-area">
          <div id="tc-quiz-counter" class="quiz-counter">۱ از ۱</div>
          <div id="tc-quiz-question" class="quiz-question-box">—</div>
          <div id="tc-quiz-answers" class="quiz-answers-grid"></div>
          <div class="quiz-timer-track"><div id="tc-quiz-timer-fill" class="quiz-timer-fill"></div></div>
        </div>
        <div id="tc-repeat-area" class="repeat-area quiz-hidden">
          <div class="repeat-card">
            <p id="tc-repeat-name">—</p>
            <p id="tc-repeat-msg">—</p>
            <p>با تشکر از همکاری و صبوری شما</p>
          </div>
        </div>
        <div id="tc-play-area" class="main-area quiz-hidden">
          <div class="hero">
            <?php if ($faviconUrl !== ''): ?>
              <img class="hero-icon" src="<?= htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8') ?>" alt="آیکون سایت" />
            <?php else: ?>
              <div class="question">
                <span>?</span>
              </div>
            <?php endif; ?>
            <p class="hint hint-align-<?= htmlspecialchars($hintAlign, ENT_QUOTES, 'UTF-8') ?>"><?= $hintHtml ?></p>
          </div>
          <div id="tc-count" class="wheel-count">تعداد آیتم‌ها: —</div>
          <div class="wheel-shell">
            <div class="pointer" aria-hidden="true"></div>
            <div class="wheel-slice-overlay" aria-hidden="true"></div>
            <canvas id="tc-wheel" width="420" height="420" aria-label="چرخ جایزه"></canvas>
            <button id="tc-spin" class="center-spin" type="button">بچرخون</button>
          </div>
          <div class="result time-counter-result">
            <span id="tc-time-counter-label" class="result-label">تا اتمام شگفتانه</span>
            <p id="tc-time-counter" class="result-value">—</p>
          </div>
        </div>
      <?php endif; ?>
      </section>
    </main>
    <div id="tc-result-dialog" class="tc-result-dialog-overlay" aria-hidden="true">
      <section class="tc-result-dialog" role="dialog" aria-modal="true" aria-labelledby="tc-result-dialog-title">
        <div id="tc-confetti" class="confetti-layer" aria-hidden="true"></div>
        <h3 id="tc-result-dialog-title" class="tc-result-dialog-title">نتیجه چرخ شما</h3>
        <div class="tc-result-dialog-content">
          <i class="ri-gift-fill tc-result-gift" aria-hidden="true"></i>
          <div class="result">
            <span class="result-label">نتیجه</span>
            <p id="tc-result" class="result-value">—</p>
          </div>
          <p class="hint hint-align-center">مبارک باشه! جایزه شما به زودی توسط سازمان به حساب شما واریز می‌شود.</p>
        </div>
        <button id="tc-result-confirm" class="tc-result-dialog-confirm" type="button">تایید</button>
      </section>
    </div>

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
                loginMsg.textContent = payload?.message || 'خطا در ورود.';
              }
            } catch {
              if (loginMsg) {
                loginMsg.textContent = 'خطا در ورود.';
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
      const initialPrizes = Array.isArray(<?= json_encode($initialPrizes, JSON_UNESCAPED_UNICODE); ?>)
        ? <?= json_encode($initialPrizes, JSON_UNESCAPED_UNICODE); ?>
        : [];
      const initialQuestions = Array.isArray(<?= json_encode($initialQuestions, JSON_UNESCAPED_UNICODE); ?>)
        ? <?= json_encode($initialQuestions, JSON_UNESCAPED_UNICODE); ?>
        : [];

      const canvas = document.getElementById('tc-wheel');
      const ctx = canvas.getContext('2d');
      const wheelShellEl = document.querySelector('.wheel-shell');
      const spinBtn = document.getElementById('tc-spin');
      const resultEl = document.getElementById('tc-result');
      const resultBox = document.querySelector('.tc-result-dialog .result');
      const timeCounterLabelEl = document.getElementById('tc-time-counter-label');
      const timeCounterEl = document.getElementById('tc-time-counter');
      const hintEl = document.querySelector('.hint');
      const resultDialogEl = document.getElementById('tc-result-dialog');
      const resultDialogConfirmBtn = document.getElementById('tc-result-confirm');
      const confettiLayer = document.getElementById('tc-confetti');
      const quizAreaEl = document.getElementById('tc-quiz-area');
      const repeatAreaEl = document.getElementById('tc-repeat-area');
      const repeatNameEl = document.getElementById('tc-repeat-name');
      const repeatMsgEl = document.getElementById('tc-repeat-msg');
      const playAreaEl = document.getElementById('tc-play-area');
      const quizCounterEl = document.getElementById('tc-quiz-counter');
      const quizQuestionEl = document.getElementById('tc-quiz-question');
      const quizAnswersEl = document.getElementById('tc-quiz-answers');
      const quizTimerFillEl = document.getElementById('tc-quiz-timer-fill');
      const quizTimerTrackEl = quizTimerFillEl ? quizTimerFillEl.closest('.quiz-timer-track') : null;
      let fakeLoopTimer = null;
      let wheelActive = true;
      const countEl = document.getElementById('tc-count');
      const statusEl = document.getElementById('tc-status');
      let wheelStatus = 'active';
      let statusTickTimer = null;
      let latestSettings = {};
      const defaultHintText = hintEl ? hintEl.textContent : '';
      const activeHintText = 'گردونه رو بچرخون و شانست رو امتحان کن!';
      const allowRepeatRolls = false;
      const userFullName = String(sessionInfo?.fullName ?? sessionInfo?.workId ?? '').trim();
      const savedPrizeWonAt = Number.parseInt(sessionInfo?.prizeWonAt ?? 0, 10);
      let userPrizeName = String(sessionInfo?.prizeWon ?? '').trim();
      let userHasPrize = !allowRepeatRolls && userPrizeName !== '';
      const savedWheelAngle = Number.isFinite(Number(sessionInfo?.wheelAngle)) ? Number(sessionInfo.wheelAngle) : null;
      const toFaDigits = (value) => String(value ?? '').replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[Number(d)]);
      const getElapsedLabel = (epochSeconds) => {
        if (!Number.isFinite(epochSeconds) || epochSeconds <= 0) {
          return 'خیلی وقت پیش';
        }
        const diffMinutes = Math.max(0, (Date.now() / 1000 - epochSeconds) / 60);
        if (diffMinutes < 10) return 'لحظاتی پیش';
        if (diffMinutes < 60) return 'دقایقی پیش';
        if (diffMinutes < 180) return 'ساعاتی پیش';
        if (diffMinutes < 1440) return 'چندساعت پیش';
        if (diffMinutes < 2880) return 'یک روز پیش';
        if (diffMinutes < 4320) return 'دو روز پیش';
        if (diffMinutes < 10080) return 'کمتر از یک هفته پیش';
        if (diffMinutes < 43200) return 'کمتر از یکماه پیش';
        return 'خیلی وقت پیش';
      };
      const questionPool = Array.isArray(initialQuestions)
        ? initialQuestions
          .map((item) => ({
            code: String(item?.code ?? '').trim(),
            type: String(item?.type ?? 'mcq').toLowerCase() === 'percentage' ? 'percentage' : 'mcq',
            question: String(item?.question ?? '').trim(),
            answers: Array.isArray(item?.answers) ? item.answers.slice(0, 4).map((ans) => String(ans ?? '').trim()) : []
          }))
          .filter((item) => item.code !== '' && item.question !== '' && (
            (item.type === 'mcq' && item.answers.length === 4 && item.answers.every((ans) => ans !== '')) ||
            item.type === 'percentage'
          ))
        : [];
      const questionByCode = new Map(questionPool.map((item) => [item.code, item]));
      const quizOrderFromSession = Array.isArray(sessionInfo?.quizOrder)
        ? sessionInfo.quizOrder.map((value) => String(value ?? '').trim()).filter((value) => value !== '')
        : [];
      const orderedQuizQuestions = (quizOrderFromSession.length === questionPool.length && questionPool.length)
        ? quizOrderFromSession
          .map((code) => questionByCode.get(code) || null)
          .filter((item) => item && item.question && (
            (item.type === 'mcq' && Array.isArray(item.answers) && item.answers.length === 4) ||
            item.type === 'percentage'
          ))
        : [];
      const quizQuestions = orderedQuizQuestions.length === questionPool.length ? orderedQuizQuestions : questionPool;
      const answeredFromSession = Number.parseInt(sessionInfo?.answered ?? 0, 10);
      const initialAnsweredCount = Number.isFinite(answeredFromSession) ? Math.max(0, answeredFromSession) : 0;
      let quizIndex = Math.min(initialAnsweredCount, quizQuestions.length);
      let quizLocked = false;
      let quizCompleted = quizQuestions.length === 0 || quizIndex >= quizQuestions.length;
      let quizTimerHandle = null;
      const QUIZ_TIME_LIMIT_MS = 14000;
      const answerTimeLimitEnabled = Boolean(sessionInfo?.answerTimeLimit ?? true);
      if (!answerTimeLimitEnabled && quizTimerTrackEl instanceof HTMLElement) {
        quizTimerTrackEl.classList.add('quiz-hidden');
      }

      const TWO_PI = Math.PI * 2;
      const MIN_VISIBLE_SEGMENTS = 10;
      const MAX_VISIBLE_SEGMENTS = 18;
      const SEGMENT_COLORS = [
        '#1d8ce1', '#177fcf'
      ];
      const DISABLED_SEGMENT_COLORS = [
        '#cdd7e8', '#dce3ef'
      ];
      const REMIX_SLICE_ICONS = [
        '\uedba', // ri-gift-fill
        '\uf22e', // ri-trophy-fill
        '\uf186', // ri-star-fill
        '\uea89', // ri-award-fill
        '\uebb1', // ri-coin-fill
        '\uef27'  // ri-medal-fill
      ];
      const buildSliceIconLayout = (sliceCount) => {
        const total = Math.max(1, Number.parseInt(sliceCount ?? 0, 10));
        const layout = [];
        for (let i = 0; i < total; i += 1) {
          const pool = REMIX_SLICE_ICONS.slice();
          for (let j = pool.length - 1; j > 0; j -= 1) {
            const k = Math.floor(Math.random() * (j + 1));
            const tmp = pool[j];
            pool[j] = pool[k];
            pool[k] = tmp;
          }
          layout.push([pool[0]]);
        }
        return layout;
      };

      let sourcePrizes = [];
      let wheelSegments = [];
      let sliceIconLayout = [];
      let currentAngle = savedWheelAngle ?? 0;
      let spinning = false;
      let wheelSize = 420;
      let wheelInitialized = false;
      let bulbPhase = 0;
      let bulbTimer = null;
      const currentAccent = '#2f8fff';
      const showPlayArea = () => {
        if (quizAreaEl) {
          quizAreaEl.classList.add('quiz-hidden');
        }
        if (repeatAreaEl) {
          repeatAreaEl.classList.add('quiz-hidden');
        }
        if (playAreaEl) {
          playAreaEl.classList.remove('quiz-hidden');
        }
      };
      const showQuizArea = () => {
        if (repeatAreaEl) {
          repeatAreaEl.classList.add('quiz-hidden');
        }
        if (playAreaEl) {
          playAreaEl.classList.add('quiz-hidden');
        }
        if (quizAreaEl) {
          quizAreaEl.classList.remove('quiz-hidden');
        }
      };
      const showRepeatArea = () => {
        if (quizAreaEl) {
          quizAreaEl.classList.add('quiz-hidden');
        }
        if (playAreaEl) {
          playAreaEl.classList.add('quiz-hidden');
        }
        if (repeatAreaEl) {
          repeatAreaEl.classList.remove('quiz-hidden');
        }
        if (repeatMsgEl) {
          const elapsedText = getElapsedLabel(savedPrizeWonAt);
          const fullName = userFullName || String(sessionInfo?.workId ?? '').trim();
          if (repeatNameEl) {
            repeatNameEl.textContent = `${fullName} عزیز`;
          }
          repeatMsgEl.textContent = `شما ${elapsedText} با موفقیت وارد این صفحه شدید و چالش را انجام دادید. جایزه شما توسط سیستم ثبت شده و به زودی از طرف سازمان به حساب شما واریز می‌شود.`;
        }
      };
      const markQuizButtonsDisabled = () => {
        if (!quizAnswersEl) return;
        Array.from(quizAnswersEl.querySelectorAll('button')).forEach((node) => {
          node.disabled = true;
        });
        Array.from(quizAnswersEl.querySelectorAll('input')).forEach((node) => {
          node.disabled = true;
        });
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
      const persistAnsweredProgress = async (answeredCount) => {
        try {
          await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'update_answered', answered: answeredCount, csrf: csrfToken })
          });
        } catch {}
      };
      const persistQuizAnswer = async (questionCode, questionText, answerText) => {
        const code = String(questionCode ?? '').trim();
        const question = String(questionText ?? '').trim();
        if (!code || !question) return;
        try {
          await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              action: 'log_answer',
              questionCode: code,
              question,
              answer: String(answerText ?? '').trim(),
              csrf: csrfToken
            })
          });
        } catch {}
      };
      const handleQuizTimeout = async () => {
        if (quizLocked) return;
        quizLocked = true;
        markQuizButtonsDisabled();
        const currentItem = quizQuestions[quizIndex] || null;
        if (currentItem && currentItem.question) {
          void persistQuizAnswer(currentItem.code, currentItem.question, '');
        }
        const answeredCount = Math.min(quizQuestions.length, quizIndex + 1);
        void persistAnsweredProgress(answeredCount);
        const correctButton = quizAnswersEl
          ? quizAnswersEl.querySelector('.quiz-answer-btn[data-correct="1"]')
          : null;
        if (correctButton instanceof HTMLButtonElement) {
          correctButton.classList.add('is-correct-reveal');
        }
        setTimeout(() => {
          void continueQuiz();
        }, 900);
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
      const continueQuiz = async () => {
        clearQuizTimer();
        quizIndex += 1;
        quizLocked = false;
        if (quizIndex >= quizQuestions.length) {
          quizCompleted = true;
          showPlayArea();
          if (!wheelInitialized) {
            await initWheelView();
          } else {
            configureCanvas();
            drawWheel(wheelSegments, currentAngle);
          }
          return;
        }
        renderQuizQuestion();
      };
      const handleQuizAnswer = async (button, isCorrect, answerText) => {
        if (quizLocked) return;
        quizLocked = true;
        clearQuizTimer();
        markQuizButtonsDisabled();
        const currentItem = quizQuestions[quizIndex] || null;
        if (currentItem && currentItem.question) {
          void persistQuizAnswer(currentItem.code, currentItem.question, answerText);
        }
        const answeredCount = Math.min(quizQuestions.length, quizIndex + 1);
        void persistAnsweredProgress(answeredCount);
        if (isCorrect) {
          button.classList.add('is-correct');
          setTimeout(() => {
            void continueQuiz();
          }, 700);
          return;
        }
        button.classList.add('is-wrong');
        const correctButton = quizAnswersEl
          ? quizAnswersEl.querySelector('.quiz-answer-btn[data-correct="1"]')
          : null;
        if (correctButton instanceof HTMLButtonElement) {
          correctButton.classList.add('is-correct-reveal');
        }
        setTimeout(() => {
          void continueQuiz();
        }, 900);
      };
      const handlePercentageAnswer = async (submitButton, rangeInput) => {
        if (quizLocked) return;
        if (!(rangeInput instanceof HTMLInputElement)) return;
        quizLocked = true;
        clearQuizTimer();
        markQuizButtonsDisabled();
        const currentItem = quizQuestions[quizIndex] || null;
        const value = Math.max(0, Math.min(100, Number.parseInt(rangeInput.value || '0', 10)));
        if (currentItem && currentItem.question) {
          void persistQuizAnswer(currentItem.code, currentItem.question, String(value));
        }
        const answeredCount = Math.min(quizQuestions.length, quizIndex + 1);
        void persistAnsweredProgress(answeredCount);
        if (submitButton instanceof HTMLButtonElement) {
          submitButton.classList.add('is-correct');
        }
        setTimeout(() => {
          void continueQuiz();
        }, 700);
      };
      const renderQuizQuestion = () => {
        if (!quizQuestionEl || !quizAnswersEl || !quizCounterEl) {
          return;
        }
        const total = quizQuestions.length;
        if (!total) {
          quizCompleted = true;
          return;
        }
        const item = quizQuestions[quizIndex];
        if (!item) {
          quizCompleted = true;
          return;
        }
        quizCounterEl.textContent = `${toFaDigits(quizIndex + 1)} از ${toFaDigits(total)}`;
        quizQuestionEl.textContent = item.question;
        quizAnswersEl.innerHTML = '';
        if (quizAreaEl) {
          Array.from(quizAreaEl.querySelectorAll('.quiz-percentage-submit-bottom[data-dynamic="1"]')).forEach((node) => node.remove());
        }
        quizAnswersEl.classList.toggle('quiz-answers-grid--single', item.type === 'percentage');
        if (quizAreaEl) {
          quizAreaEl.classList.toggle('quiz-area--percentage', item.type === 'percentage');
        }
        if (item.type === 'percentage') {
          const wrap = document.createElement('div');
          wrap.className = 'quiz-percentage-wrap';
          const valueLabel = document.createElement('div');
          valueLabel.className = 'quiz-percentage-value';
          valueLabel.textContent = `${toFaDigits(50)}%`;
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
            valueLabel.textContent = `${toFaDigits(val)}%`;
            slider.style.setProperty('--range-progress', `${val}%`);
          });
          const submit = document.createElement('button');
          submit.type = 'button';
          submit.className = 'quiz-answer-btn quiz-percentage-submit quiz-percentage-submit-bottom';
          submit.dataset.dynamic = '1';
          submit.textContent = '\u0628\u0639\u062f\u06cc';
          submit.addEventListener('click', () => {
            void handlePercentageAnswer(submit, slider);
          });
          wrap.appendChild(valueLabel);
          wrap.appendChild(slider);
          quizAnswersEl.appendChild(wrap);
          if (quizAreaEl) {
            quizAreaEl.appendChild(submit);
          }
          startQuizTimer();
          return;
        }
        const shuffledAnswers = item.answers
          .map((answer, index) => ({ text: answer, isCorrect: index === 0 }));
        for (let i = shuffledAnswers.length - 1; i > 0; i -= 1) {
          const j = Math.floor(Math.random() * (i + 1));
          const temp = shuffledAnswers[i];
          shuffledAnswers[i] = shuffledAnswers[j];
          shuffledAnswers[j] = temp;
        }
        shuffledAnswers.forEach((answerItem) => {
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'quiz-answer-btn';
          button.textContent = answerItem.text;
          button.dataset.correct = answerItem.isCorrect ? '1' : '0';
          button.addEventListener('click', () => {
            void handleQuizAnswer(button, answerItem.isCorrect, answerItem.text);
          });
          quizAnswersEl.appendChild(button);
        });
        startQuizTimer();
      };
      const openResultDialog = () => {
        if (!resultDialogEl) return;
        resultDialogEl.classList.add('open');
        resultDialogEl.setAttribute('aria-hidden', 'false');
      };
      const closeResultDialog = () => {
        if (!resultDialogEl) return;
        resultDialogEl.classList.remove('open');
        resultDialogEl.setAttribute('aria-hidden', 'true');
      };
      const setTimeCounter = (label, value) => {
        if (timeCounterLabelEl) {
          timeCounterLabelEl.textContent = label;
        }
        if (timeCounterEl) {
          timeCounterEl.textContent = value;
        }
      };
      if (resultDialogEl) {
        resultDialogEl.addEventListener('click', (event) => {
          if (event.target === resultDialogEl) {
            closeResultDialog();
          }
        });
      }
      if (resultDialogConfirmBtn) {
        resultDialogConfirmBtn.addEventListener('click', async () => {
          resultDialogConfirmBtn.disabled = true;
          await performLogout();
        });
      }

      const loadPrizeStore = async () => {
        try {
          const response = await fetch('TC%20Prizes.json', { cache: 'no-store' });
          const payload = await response.json();
          return Array.isArray(payload) ? payload : [];
        } catch {
          return [];
        }
      };

      const normalizeSourcePrizes = (list) => {
        if (!Array.isArray(list)) {
          return [{ name: 'بدون جایزه', wheelLabel: 'بدون جایزه', weight: 1, canDecrement: false }];
        }

        const normalized = list
          .map((item) => {
            const name = String(item?.name ?? '').trim();
            const onWheelName = String(item?.onWheelName ?? name).trim();
            const quantityValue = Number.parseInt(item?.quantity ?? 0, 10);
            const quantity = Number.isFinite(quantityValue) && quantityValue > 0 ? quantityValue : 0;
            const isFake = Boolean(item?.isFake);
            const hasLast = item && Object.prototype.hasOwnProperty.call(item, 'last');
            const lastValue = Number.parseInt(item?.last ?? quantity, 10);
            const remaining = hasLast
              ? (Number.isFinite(lastValue) ? Math.max(0, lastValue) : 0)
              : quantity;
            return {
              name,
              wheelLabel: onWheelName || name,
              weight: remaining,
              canDecrement: name !== '' && remaining > 0 && !isFake,
              isFake,
              displayWeight: remaining > 0 ? remaining : 1
            };
          })
          .filter((prize) => prize.name !== '');

        return normalized.length
          ? normalized
          : [{ name: 'بدون جایزه', wheelLabel: 'بدون جایزه', weight: 1, canDecrement: false }];
      };

      const buildDisplaySegments = (prizes) => {
        if (!prizes.length) {
          return Array.from({ length: MIN_VISIBLE_SEGMENTS }, () => ({
            label: 'بدون جایزه',
            source: 'بدون جایزه',
            canDecrement: false
          }));
        }

        const maxWeight = Math.max(...prizes.map((prize) => prize.displayWeight), 1);
        const counters = prizes.map((prize) => {
          const relative = prize.displayWeight / maxWeight;
          return {
            name: prize.name,
            wheelLabel: prize.wheelLabel || prize.name,
            weight: prize.weight,
            canDecrement: prize.canDecrement,
            isFake: prize.isFake,
            repeats: Math.max(2, Math.min(6, Math.round(relative * 4) + 1))
          };
        });

        let totalRepeats = counters.reduce((sum, item) => sum + item.repeats, 0);
        let fillIndex = 0;
        while (totalRepeats < MIN_VISIBLE_SEGMENTS) {
          counters[fillIndex % counters.length].repeats += 1;
          totalRepeats += 1;
          fillIndex += 1;
        }

        const minPerItem = (counters.length * 2 <= MAX_VISIBLE_SEGMENTS) ? 2 : 1;
        while (totalRepeats > MAX_VISIBLE_SEGMENTS) {
          const target = counters
            .filter((item) => item.repeats > minPerItem)
            .sort((a, b) => b.repeats - a.repeats)[0];
          if (!target) {
            break;
          }
          target.repeats -= 1;
          totalRepeats -= 1;
        }

        const sequence = [];
        const queue = counters.map((item) => ({
          name: item.name,
          wheelLabel: item.wheelLabel,
          canDecrement: item.canDecrement,
          isFake: item.isFake,
          remaining: item.repeats
        }));

        let lastName = '';
        let lastWasFake = false;
        while (true) {
          const candidates = queue
            .filter((item) => item.remaining > 0)
            .sort((a, b) => b.remaining - a.remaining);
          if (!candidates.length) {
            break;
          }
          let picked = candidates.find((item) => item.name !== lastName && (!lastWasFake || !item.isFake));
          if (!picked) {
            picked = candidates.find((item) => item.name !== lastName) || candidates[0];
          }
          picked.remaining -= 1;
          sequence.push({
            label: picked.wheelLabel,
            source: picked.name,
            canDecrement: picked.canDecrement,
            isFake: picked.isFake
          });
          lastName = picked.name;
          lastWasFake = picked.isFake;
        }

        if (sequence.length > 2 && sequence[0].source === sequence[sequence.length - 1].source) {
          const edgeName = sequence[0].source;
          let swapIndex = -1;
          for (let i = 1; i < sequence.length - 1; i += 1) {
            if (sequence[i].source !== edgeName) {
              swapIndex = i;
              break;
            }
          }
          if (swapIndex !== -1) {
            const last = sequence.length - 1;
            const temp = sequence[last];
            sequence[last] = sequence[swapIndex];
            sequence[swapIndex] = temp;
          }
        }

        if (sequence.length > 2 && sequence[0].isFake && sequence[sequence.length - 1].isFake) {
          let swapIndex = -1;
          for (let i = 1; i < sequence.length - 1; i += 1) {
            if (!sequence[i].isFake) {
              swapIndex = i;
              break;
            }
          }
          if (swapIndex !== -1) {
            const last = sequence.length - 1;
            const temp = sequence[last];
            sequence[last] = sequence[swapIndex];
            sequence[swapIndex] = temp;
          }
        }

        return sequence;
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

      const updateStatusBanner = () => {
        if (statusEl) {
          statusEl.classList.add('hidden');
          statusEl.classList.remove('top-left');
        }
        if (userPrizeName !== '') {
          setTimeCounter('جایزه برنده شده', userPrizeName);
          return;
        }
        if (statusTickTimer) {
          clearInterval(statusTickTimer);
          statusTickTimer = null;
        }
        const status = wheelStatus;
        if (status === 'ended') {
          setTimeCounter('متاسفیم', 'شگفتانه تمام شده');
          if (hintEl) {
            hintEl.textContent = 'زمان شگفتانه به پایان رسیده و دیگر امکان شرکت وجود ندارد.';
            hintEl.style.textAlign = 'center';
          }
          return;
        }
        if (status === 'inactive') {
          setTimeCounter('متاسفیم', 'شگفتانه غیر فعال است');
          if (hintEl) {
            hintEl.textContent = 'در حال حاضر شگفتانه‌ای فعال نیست. لطفاً بعداً دوباره سر بزنید.';
            hintEl.style.textAlign = 'center';
          }
          return;
        }
        const durationOn = Boolean(latestSettings?.duration);
        if (!durationOn) {
          setTimeCounter('تا اتمام شگفتانه', '—');
          if (hintEl) {
            hintEl.textContent = activeHintText;
            hintEl.style.textAlign = 'center';
          }
          return;
        }
        const startDate = String(latestSettings?.startDate ?? '').trim();
        const endDate = String(latestSettings?.endDate ?? '').trim();
        const startTime = String(latestSettings?.startTime ?? '').trim();
        const endTime = String(latestSettings?.endTime ?? '').trim();
        const targetLabel = status === 'upcoming' ? 'تا شروع شگفتانه' : 'تا اتمام شگفتانه';
        const targetDate = status === 'upcoming' ? startDate : endDate;
        const targetTime = status === 'upcoming' ? startTime : endTime;
        const counterLabel = status === 'upcoming' ? 'تا شروع شگفتانه' : 'تا اتمام شگفتانه';
        const updateCountdown = () => {
          if (!targetDate || !targetTime) {
            setTimeCounter(counterLabel, targetLabel);
            return;
          }
          const target = getTehranTargetDate(targetDate, targetTime);
          if (!target) {
            setTimeCounter(counterLabel, targetLabel);
            return;
          }
          let diff = Math.max(0, Math.floor((target.getTime() - Date.now()) / 1000));
          const hours = Math.floor(diff / 3600);
          diff -= hours * 3600;
          const minutes = Math.floor(diff / 60);
          const seconds = diff - minutes * 60;
          const timer = `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
          setTimeCounter(counterLabel, timer);
        };
        if (status === 'upcoming') {
          if (hintEl) {
            hintEl.textContent = 'چرخ شانس هنوز فعال نشده است';
            hintEl.style.textAlign = '';
          }
        } else {
          if (hintEl) {
            hintEl.textContent = activeHintText;
            hintEl.style.textAlign = 'center';
          }
        }
        updateCountdown();
        statusTickTimer = setInterval(updateCountdown, 1000);
      };

      const applyWheelStatus = (status) => {
        wheelStatus = status;
        const canSpin = status === 'active' && !userHasPrize;
        wheelActive = canSpin;
        if (spinBtn) {
          spinBtn.disabled = !canSpin;
        }
        drawWheel(wheelSegments, currentAngle);
      };

      const refreshWheelStatus = async () => {
        const settings = await loadWheelSettings();
        latestSettings = settings;
        const status = describeStatus(settings);
        applyWheelStatus(status);
        updateStatusBanner();
      };

      window.addEventListener('storage', (event) => {
        if (event.key === 'tcSettingsUpdated') {
          refreshWheelStatus();
        }
      });

      const configureCanvas = () => {
        const rect = canvas.getBoundingClientRect();
        const cssSize = Math.max(320, Math.round(rect.width || 420));
        const dpr = window.devicePixelRatio || 1;
        canvas.width = Math.round(cssSize * dpr);
        canvas.height = Math.round(cssSize * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        wheelSize = cssSize;
      };

      const drawWheel = (segments, angle = 0) => {
        const size = wheelSize;
        const center = size / 2;
        const radius = center - 16;
        const count = Math.max(segments.length, 1);
        const slice = TWO_PI / count;

        ctx.clearRect(0, 0, size, size);
        ctx.save();
        ctx.translate(center, center);
        ctx.rotate(angle);

        const palette = (wheelStatus === 'inactive') ? DISABLED_SEGMENT_COLORS : SEGMENT_COLORS;
        for (let i = 0; i < count; i += 1) {
          const start = i * slice;
          const end = start + slice;
          ctx.beginPath();
          ctx.moveTo(0, 0);
          ctx.arc(0, 0, radius, start, end);
          ctx.closePath();
          ctx.fillStyle = palette[i % palette.length];
          ctx.fill();
        }

        // Decorative remix icons per slice.
        const iconSize = Math.max(17, Math.min(26, radius * 0.12));
        ctx.font = `${iconSize}px remixicon`;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        for (let i = 0; i < count; i += 1) {
          const icons = Array.isArray(sliceIconLayout[i]) ? sliceIconLayout[i] : [];
          const mid = (i * slice) + (slice / 2);
          const glyph = String(icons[0] ?? '');
          if (!glyph) continue;
          const r = radius * 0.62;
          const x = Math.cos(mid) * r;
          const y = Math.sin(mid) * r;
          ctx.fillStyle = wheelStatus === 'inactive'
            ? 'rgba(233, 240, 250, 0.62)'
            : 'rgba(255, 255, 255, 0.3)';
          ctx.fillText(glyph, x, y);
        }

        if (wheelStatus !== 'inactive' && count > 1) {
          const cycleMs = Math.max(1500, count * 240);
          const progress = ((Date.now() % cycleMs) / cycleMs) * count;
          const activeIndex = Math.floor(progress) % count;
          const nextIndex = (activeIndex + 1) % count;
          const blend = progress - Math.floor(progress);

          const paintSliceGlow = (idx, alpha) => {
            const start = idx * slice;
            const end = start + slice;
            ctx.beginPath();
            ctx.moveTo(0, 0);
            ctx.arc(0, 0, radius * 0.985, start, end);
            ctx.closePath();
            const glow = ctx.createLinearGradient(0, -radius, 0, radius * 0.28);
            glow.addColorStop(0, `rgba(255, 255, 255, ${alpha})`);
            glow.addColorStop(0.55, `rgba(255, 255, 255, ${alpha * 0.35})`);
            glow.addColorStop(1, 'rgba(255, 255, 255, 0)');
            ctx.fillStyle = glow;
            ctx.fill();
          };

          paintSliceGlow(activeIndex, 0.28 * (1 - blend) + 0.06);
          paintSliceGlow(nextIndex, 0.28 * blend + 0.06);
        }

        if (wheelStatus !== 'inactive') {
          ctx.beginPath();
          ctx.arc(0, 0, radius * 0.98, -Math.PI * 0.88, -Math.PI * 0.12);
          ctx.lineWidth = Math.max(5, radius * 0.07);
          ctx.strokeStyle = 'rgba(255, 255, 255, 0.23)';
          ctx.stroke();
        }

        ctx.restore();

        if (wheelStatus !== 'inactive') {
          const pointerCenter = -Math.PI / 2;
          const pointerHalf = Math.min(0.42, Math.max(0.09, slice * 0.5));
          const pointerRadius = radius * 0.985;

          ctx.save();
          ctx.translate(center, center);
          ctx.beginPath();
          ctx.moveTo(0, 0);
          ctx.arc(0, 0, pointerRadius, pointerCenter - pointerHalf, pointerCenter + pointerHalf);
          ctx.closePath();
          const pointerGlow = ctx.createLinearGradient(0, -pointerRadius, 0, pointerRadius * 0.2);
          pointerGlow.addColorStop(0, 'rgba(255, 255, 255, 0.36)');
          pointerGlow.addColorStop(0.52, 'rgba(255, 255, 255, 0.14)');
          pointerGlow.addColorStop(1, 'rgba(255, 255, 255, 0.02)');
          ctx.fillStyle = pointerGlow;
          ctx.fill();

          // Thick frame around the pointer-area highlight wedge.
          ctx.beginPath();
          ctx.moveTo(0, 0);
          ctx.arc(0, 0, pointerRadius, pointerCenter - pointerHalf, pointerCenter + pointerHalf);
          ctx.closePath();
          ctx.lineJoin = 'round';
          ctx.lineCap = 'round';
          ctx.lineWidth = 5.8;
          ctx.strokeStyle = 'rgba(255, 255, 255, 0.92)';
          ctx.stroke();

          // Soft inner edge for a glass-like framed effect.
          ctx.beginPath();
          ctx.moveTo(0, 0);
          ctx.arc(0, 0, pointerRadius * 0.985, pointerCenter - (pointerHalf * 0.96), pointerCenter + (pointerHalf * 0.96));
          ctx.closePath();
          ctx.lineWidth = 2.2;
          ctx.strokeStyle = 'rgba(207, 233, 255, 0.62)';
          ctx.stroke();

          // Thick white outer cap connected to the bulb margin.
          const capRadius = radius + 7.2;
          ctx.beginPath();
          ctx.arc(0, 0, capRadius, pointerCenter - (pointerHalf * 1.02), pointerCenter + (pointerHalf * 1.02));
          ctx.lineWidth = 8.4;
          ctx.strokeStyle = 'rgba(255, 255, 255, 0.96)';
          ctx.lineCap = 'round';
          ctx.stroke();
          ctx.restore();
        }
        ctx.beginPath();
        ctx.arc(center, center, radius, 0, TWO_PI);
        ctx.lineWidth = 4.2;
        ctx.strokeStyle = wheelStatus === 'inactive' ? '#d7dfeb' : '#eaf3ff';
        ctx.stroke();

        ctx.beginPath();
        ctx.arc(center, center, radius + 10, 0, TWO_PI);
        ctx.lineWidth = 7;
        ctx.strokeStyle = wheelStatus === 'inactive'
          ? 'rgba(198, 208, 222, 0.9)'
          : 'rgba(232, 243, 255, 0.95)';
        ctx.stroke();

        const bulbCount = Math.max(12, Math.round(count * 1.2));
        const bulbRadius = Math.max(6.8, radius * 0.04);
        const bulbOrbit = center - bulbRadius - 2;
        for (let i = 0; i < bulbCount; i += 1) {
          const a = (i / bulbCount) * TWO_PI;
          const x = center + Math.cos(a) * bulbOrbit;
          const y = center + Math.sin(a) * bulbOrbit;
          const isOn = ((i + bulbPhase) % 2) === 0;
          const isBlueBulb = (i % 2) === 0;
          const outer = wheelStatus === 'inactive'
            ? (isOn ? '#d2dae7' : '#edf2f8')
            : (isBlueBulb
              ? (isOn ? '#4fb4ff' : '#dfeefd')
              : (isOn ? '#ff8a4f' : '#ffe8dc'));
          const inner = wheelStatus === 'inactive'
            ? '#f4f7fb'
            : '#ffffff';
          const edge = wheelStatus === 'inactive'
            ? '#c9d2e2'
            : (isBlueBulb ? '#198fe6' : '#ff4f00');
          const bulbGrad = ctx.createRadialGradient(
            x - bulbRadius * 0.35,
            y - bulbRadius * 0.4,
            0.2,
            x,
            y,
            bulbRadius
          );
          bulbGrad.addColorStop(0, inner);
          bulbGrad.addColorStop(0.62, outer);
          bulbGrad.addColorStop(1, edge);
          ctx.beginPath();
          ctx.arc(x, y, bulbRadius, 0, TWO_PI);
          ctx.fillStyle = bulbGrad;
          ctx.fill();
          ctx.lineWidth = 1;
          ctx.strokeStyle = wheelStatus === 'inactive' ? '#bec8d9' : (isBlueBulb ? '#acd9ff' : '#ffc8ad');
          ctx.stroke();
          if (wheelStatus !== 'inactive' && isOn) {
            ctx.shadowColor = isBlueBulb ? '#6eb8ff' : '#ff8c53';
            ctx.shadowBlur = 12;
            ctx.fill();
            ctx.shadowBlur = 0;
          }
        }

        ctx.beginPath();
        ctx.arc(center, center, 26, 0, TWO_PI);
        const centerGrad = ctx.createRadialGradient(center - 7, center - 9, 1, center, center, 26);
        centerGrad.addColorStop(0, '#ffffff');
        centerGrad.addColorStop(0.55, '#58a8ff');
        centerGrad.addColorStop(1, '#1e73d7');
        ctx.fillStyle = centerGrad;
        ctx.fill();
        ctx.lineWidth = 3.5;
        ctx.strokeStyle = '#f5faff';
        ctx.stroke();
      };

      const startBulbAnimation = () => {
        if (bulbTimer) return;
        bulbTimer = setInterval(() => {
          bulbPhase = (bulbPhase + 1) % 1000;
          if (!spinning) {
            drawWheel(wheelSegments, currentAngle);
          }
        }, 280);
      };

      const weightedPrizePick = (prizes) => {
        const total = prizes.reduce((sum, prize) => sum + prize.weight, 0);
        if (total <= 0) {
          return prizes[0] ?? { name: 'بدون جایزه', canDecrement: false };
        }
        let roll = Math.random() * total;
        for (let i = 0; i < prizes.length; i += 1) {
          roll -= prizes[i].weight;
          if (roll <= 0) {
            return prizes[i];
          }
        }
        return prizes[prizes.length - 1];
      };

      const pickDisplayIndexForPrize = (segments, prizeName) => {
        const matches = [];
        segments.forEach((segment, index) => {
          if (segment.source === prizeName) {
            matches.push(index);
          }
        });
        if (!matches.length) {
          return 0;
        }
        return matches[Math.floor(Math.random() * matches.length)];
      };

      const initWheel = (list) => {
        sourcePrizes = normalizeSourcePrizes(list);
        wheelSegments = buildDisplaySegments(sourcePrizes);
        sliceIconLayout = buildSliceIconLayout(wheelSegments.length);
        countEl.textContent = `تعداد آیتم‌ها: ${toFaDigits(wheelSegments.length)}`;
        drawWheel(wheelSegments, currentAngle);
      };

      const bootstrap = async () => {
        if (initialPrizes.length) {
          initWheel(initialPrizes);
          return;
        }
        const loaded = await loadPrizeStore();
        initWheel(loaded);
      };

      const initWheelView = async () => {
        if (wheelInitialized) {
          return;
        }
        await waitForFonts();
        configureCanvas();
        await bootstrap();
        await refreshWheelStatus();
        if (userHasPrize) {
          if (resultEl) {
          resultEl.textContent = userPrizeName || '—';
          }
          if (spinBtn) {
            spinBtn.disabled = true;
          }
        } else if (resultEl && userPrizeName !== '') {
          resultEl.textContent = userPrizeName;
        }
        if (savedWheelAngle !== null) {
          currentAngle = savedWheelAngle;
          drawWheel(wheelSegments, currentAngle);
        }


        window.addEventListener('resize', () => {
          configureCanvas();
          drawWheel(wheelSegments, currentAngle);
        });
        startBulbAnimation();
        wheelInitialized = true;
      };

      const initApp = async () => {
        if (userHasPrize) {
          showRepeatArea();
          return;
        }
        if (!quizCompleted && quizQuestions.length) {
          showQuizArea();
          renderQuizQuestion();
          return;
        }
        showPlayArea();
        await initWheelView();
      };

      initApp();

      spinBtn.addEventListener('click', async () => {
        if (!wheelActive) {
          return;
        }
        if (spinning || !wheelSegments.length || !sourcePrizes.length) {
          return;
        }
        if (userHasPrize) {
          if (resultEl) {
          resultEl.textContent = userPrizeName || '—';
          }
          return;
        }
        try {
          const rollResponse = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'log_roll', csrf: csrfToken })
          });
          const rollPayload = await rollResponse.json();
          if (!rollResponse.ok || rollPayload?.status !== 'ok') {
            if (resultEl) {
              resultEl.textContent = userPrizeName || rollPayload?.message || 'خطا در ثبت چرخش.';
            }
            return;
          }
        } catch {
          if (resultEl) {
            resultEl.textContent = 'خطایی رخ داد.';
          }
          return;
        }
        spinning = true;
        if (wheelShellEl instanceof HTMLElement) {
          wheelShellEl.classList.add('is-spinning');
        }
        if (spinBtn) {
          spinBtn.classList.add('is-spinning');
        }
        spinBtn.disabled = true;
          resultEl.textContent = '—';
        if (resultBox) {
          resultBox.classList.remove('result-shine');
          resultBox.classList.remove('result-shake');
          resultBox.classList.remove('result-fake');
        }
        if (fakeLoopTimer) {
          clearInterval(fakeLoopTimer);
          fakeLoopTimer = null;
        }

        const fakeItems = sourcePrizes.filter((item) => item.isFake);
        const realItems = sourcePrizes.filter((item) => !item.isFake && item.weight > 0);
        let winnerPrize;
        if (fakeItems.length && realItems.length) {
          const pickFake = Math.random() < 0.5;
          winnerPrize = pickFake
            ? fakeItems[Math.floor(Math.random() * fakeItems.length)]
            : weightedPrizePick(realItems);
        } else if (fakeItems.length) {
          winnerPrize = fakeItems[Math.floor(Math.random() * fakeItems.length)];
        } else {
          winnerPrize = weightedPrizePick(realItems.length ? realItems : sourcePrizes);
        }
        const winnerIndex = pickDisplayIndexForPrize(wheelSegments, winnerPrize.name);
        const slice = TWO_PI / wheelSegments.length;
        const winnerCenter = (winnerIndex * slice) + (slice / 2);
        const desiredAngle = (-Math.PI / 2) - winnerCenter;
        const normalizedCurrent = ((currentAngle % TWO_PI) + TWO_PI) % TWO_PI;
        const delta = ((desiredAngle - normalizedCurrent) % TWO_PI + TWO_PI) % TWO_PI;
        const spinTurns = 7 + Math.floor(Math.random() * 3);
        const startAngle = currentAngle;
        const targetAngle = currentAngle + (spinTurns * TWO_PI) + delta;
        const startTime = performance.now();
        const duration = 7000;
        const easeOutCubic = (t) => 1 - Math.pow(1 - t, 3);

        const animate = async (now) => {
          const elapsed = now - startTime;
          const progress = Math.min(1, elapsed / duration);
          const eased = easeOutCubic(progress);
          currentAngle = startAngle + ((targetAngle - startAngle) * eased);
          drawWheel(wheelSegments, currentAngle);
          if (progress < 1) {
            requestAnimationFrame(animate);
            return;
          }

          currentAngle = ((targetAngle % TWO_PI) + TWO_PI) % TWO_PI;
          drawWheel(wheelSegments, currentAngle);
          resultEl.textContent = winnerPrize?.name ?? 'بدون جایزه';
          resultEl.classList.remove('drop-in');
          void resultEl.offsetWidth;
          if (resultBox) {
            resultBox.classList.remove('result-shine');
            resultBox.classList.remove('result-shake');
          }
          const isFake = Boolean(winnerPrize?.isFake);
          if (!isFake && winnerPrize?.name) {
            userPrizeName = winnerPrize.name;
            userHasPrize = !allowRepeatRolls;
            setTimeCounter('جایزه برنده شده', userPrizeName);
            if (!allowRepeatRolls && spinBtn) {
              spinBtn.disabled = true;
            }
            try {
              await fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'log_prize', prize: winnerPrize.name, wheelAngle: currentAngle, csrf: csrfToken })
              });
            } catch {}
          }
          if (resultBox && isFake) {
            void resultBox.offsetWidth;
            resultBox.classList.add('result-shake');
            resultBox.classList.add('result-fake');
            const fakeText = winnerPrize?.name ?? 'بدون جایزه';
            const retryText = 'دوباره امتحان کن!';
            let toggle = false;
            fakeLoopTimer = setInterval(() => {
              toggle = !toggle;
              resultEl.textContent = toggle ? retryText : fakeText;
            }, 1000);
          }
          if (resultBox && !isFake) {
            resultBox.classList.add('result-shine');
            resultEl.classList.add('drop-in');
            openResultDialog();
          }
          if (confettiLayer && !isFake) {
            const colors = ['#0095da', '#ff4f00'];
            const dialogBounds = confettiLayer.getBoundingClientRect();
            const width = Math.max(260, dialogBounds.width || 360);
            const height = Math.max(220, dialogBounds.height || 420);
            const count = Math.max(88, Math.min(150, Math.round(width / 4.2)));
            confettiLayer.innerHTML = '';
            for (let i = 0; i < count; i += 1) {
              const piece = document.createElement('span');
              piece.className = 'confetti-piece';
              const fromLeft = Math.random() < 0.5;
              const sideOffset = Math.random() * 40 + 2;
              piece.style[fromLeft ? 'left' : 'right'] = `${sideOffset}px`;
              piece.style.top = `${Math.random() * (height * 0.8)}px`;
              piece.style.background = colors[i % colors.length];
              piece.style.animationDelay = `${Math.random() * 0.25}s`;
              const drift = fromLeft ? (Math.random() * 120 + 40) : -(Math.random() * 120 + 40);
              piece.style.setProperty('--drift', `${drift}px`);
              piece.style.transform = `translate3d(0, 0, 0) rotate(${Math.random() * 180}deg)`;
              confettiLayer.appendChild(piece);
            }
            setTimeout(() => {
              confettiLayer.innerHTML = '';
            }, 3400);
          }
          spinning = false;
          if (wheelShellEl instanceof HTMLElement) {
            wheelShellEl.classList.remove('is-spinning');
          }
          if (spinBtn) {
            spinBtn.classList.remove('is-spinning');
          }
          if (spinBtn) {
            spinBtn.disabled = !wheelActive || userHasPrize;
          }

          if (!winnerPrize?.canDecrement) {
            return;
          }

          try {
            const response = await fetch('TCM.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ action: 'decrement_prize', name: winnerPrize.name, csrf: csrfToken })
            });
            const payload = await response.json();
            if (response.ok && payload?.status === 'ok' && Array.isArray(payload.data)) {
              initWheel(payload.data);
            }
          } catch {}
        };

        requestAnimationFrame(animate);
      });

      const scheduleHourlyStatusCheck = () => {
        const now = new Date();
        const nextHour = new Date(now);
        nextHour.setMinutes(0, 0, 0);
        nextHour.setHours(now.getHours() + 1);
        const delay = nextHour.getTime() - now.getTime();
        setTimeout(() => {
          refreshWheelStatus();
          setInterval(refreshWheelStatus, 60 * 60 * 1000);
        }, delay);
      };

      scheduleHourlyStatusCheck();
      }
    </script>
  </body>
</html>


