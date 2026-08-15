<?php
declare(strict_types=1);

require_once __DIR__ . '/invitees_csv_safety.php';
require_once __DIR__ . '/prize_inventory_store.php';

$tcMonitoringAction = strtolower(trim((string)($_GET['action'] ?? '')));
$tcMonitoringIsJsonRequest = $tcMonitoringAction === 'stats';
$tcMonitoringJsonCompleted = false;

function tcMonitoringEarlyJsonFlags(): int
{
  $flags = JSON_UNESCAPED_UNICODE;
  if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
  }
  if (defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) {
    $flags |= JSON_PARTIAL_OUTPUT_ON_ERROR;
  }
  return $flags;
}

function tcMonitoringEarlyJsonResponse(array $payload): string
{
  $json = json_encode($payload, tcMonitoringEarlyJsonFlags());
  return is_string($json) && $json !== '' ? $json : '{"status":"error","message":"Monitoring JSON encode failed."}';
}

if ($tcMonitoringIsJsonRequest || $tcMonitoringAction === 'export') {
  @ini_set('display_errors', '0');
}

if ($tcMonitoringIsJsonRequest) {
  ob_start();
  register_shutdown_function(static function () use (&$tcMonitoringJsonCompleted): void {
    if ($tcMonitoringJsonCompleted) {
      return;
    }

    $buffer = '';
    if (ob_get_level() > 0) {
      $buffer = (string)ob_get_clean();
    }
    $trimmedBuffer = trim($buffer);
    $error = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    $isFatal = is_array($error) && in_array((int)($error['type'] ?? 0), $fatalTypes, true);

    if (!$isFatal && $trimmedBuffer === '') {
      return;
    }

    if (!headers_sent()) {
      header('Content-Type: application/json; charset=utf-8');
    }

    if ($trimmedBuffer !== '') {
      $decodedBuffer = json_decode($trimmedBuffer, true);
      if (is_array($decodedBuffer)) {
        echo $trimmedBuffer;
        return;
      }
    }

    if ($isFatal) {
      http_response_code(500);
      error_log('Task Club monitoring fatal error: ' . (string)($error['message'] ?? 'unknown error'));
      echo tcMonitoringEarlyJsonResponse([
        'status' => 'error',
        'code' => 'monitoring_fatal',
        'message' => 'خطای داخلی در مانیتورینگ رخ داد. جزئیات خطا در همین بخش نمایش داده شد.',
        'diagnostics' => [
          'type' => 'fatal',
          'message' => (string)($error['message'] ?? 'unknown error'),
          'file' => (string)($error['file'] ?? ''),
          'line' => (int)($error['line'] ?? 0)
        ]
      ]);
      return;
    }

    http_response_code(200);
    echo tcMonitoringEarlyJsonResponse([
      'status' => 'error',
      'code' => 'monitoring_pre_json_output',
      'message' => 'پاسخ مانیتورینگ قبل از تولید JSON متوقف شد. نشست یا دسترسی مانیتورینگ را بررسی کنید.',
      'diagnostics' => [
        'type' => 'pre_json_output',
        'message' => trim(strip_tags($trimmedBuffer)),
        'file' => '',
        'line' => 0
      ]
    ]);
  });
}

require_once __DIR__ . '/../../api/lib/tab-permissions.php';

$tcMonitoringSessionUser = requireTabPermissionFromSession('task-club', $tcMonitoringIsJsonRequest);
if (!userHasPermissionId($tcMonitoringSessionUser, 'task-club:monitoring')) {
  denyPanelAccess(403, 'شما دسترسی لازم برای مشاهده این بخش باشگاه تعاملی را ندارید.', $tcMonitoringIsJsonRequest);
}

function tcMonitoringReadJson(string $path, $fallback)
{
  if (!is_file($path)) {
    return $fallback;
  }
  $content = file_get_contents($path);
  if (!is_string($content) || $content === '') {
    return $fallback;
  }
  $decoded = json_decode($content, true);
  return $decoded === null ? $fallback : $decoded;
}

function tcMonitoringEmptyStats(array $warnings = []): array
{
  return [
    'summary' => [
      'totalUsers' => 0,
      'loggedInUsers' => 0,
      'neverLoggedInUsers' => 0,
      'usersWithCompletion' => 0,
      'usersEligibleAnyLevel' => 0,
      'maximumPrizeCardsOpenable' => 0,
      'usersCompletedAllStarted' => 0,
      'highActiveCompletedAllStarted' => 0,
      'highActivityThreshold' => 0,
      'avgScore' => 0,
      'topScore' => 0,
      'minPossibleScore' => 0,
      'maxPossibleScore' => 0,
      'usersWithMaxPossibleScore' => 0,
      'scoreStageUserCount' => 0,
      'completionDistributionUserCount' => 0,
      'taskCount' => 0,
      'startedTaskCount' => 0,
      'levelCount' => 0,
      'prizeCapacity' => 0,
      'prizeRemaining' => 0,
      'prizeGiven' => 0,
      'prizeValueRemaining' => 0,
      'prizeValueGiven' => 0,
      'prizeValueAssignedToInvitees' => 0,
      'prizeValueBudget' => 0,
      'prizeValueReconciliationDifference' => 0,
      'prizeValueHasProblem' => false,
      'favoriteTask' => null
    ],
    'scoreStages' => [],
    'completionDistribution' => [],
    'eventInfo' => [
      'startAt' => '',
      'endAt' => '',
      'durationMinutes' => 0
    ],
    'participationStats' => [
      'totalUsers' => 0,
      'loggedInUsers' => 0,
      'usersWithTaskParticipation' => 0,
      'usersWithCompletion' => 0,
      'usersCompletedAllStarted' => 0,
      'usersWithMaxPossibleScore' => 0,
      'usersEligibleAnyLevel' => 0,
      'loggedInWithoutCompletion' => 0,
      'neverLoggedInUsers' => 0
    ],
    'challengeStats' => [
      'available' => false,
      'note' => 'اطلاعات چالش‌ها فعلا در دسترس نیست.',
      'startedChallengeTeams' => 0,
      'challengeParticipants' => 0,
      'avgChallengesPerUser' => 0,
      'avgChallengesPerParticipant' => 0
    ],
    'timeEngagementStats' => [
      'available' => false,
      'note' => 'گزارش فعالیت برای محاسبه این بخش در دسترس نیست.',
      'usersLoginAndAnswerOnMissionStartDay' => 0,
      'usersLoginAndAnswerOnMissionStartDayRate' => 0,
      'userDaysWithMultiMissionSamePeriod' => 0,
      'logEventsRead' => 0
    ],
    'workIdGroupStats' => [],
    'workIdGroups' => [],
    'selectedWorkIdGroups' => [],
    'taskStats' => [],
    'levelStats' => [],
    'mostActiveUsers' => [],
    'prizeStats' => ['rows' => []],
    'dataSources' => [],
    'warnings' => array_values(array_unique(array_filter(array_map('strval', $warnings)))),
    'generatedAt' => gmdate('c')
  ];
}

function tcMonitoringSanitizeForJson($value)
{
  if (is_string($value)) {
    if (function_exists('mb_check_encoding') && function_exists('mb_convert_encoding')) {
      return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
    if (function_exists('iconv')) {
      $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
      return is_string($converted) ? $converted : $value;
    }
    return $value;
  }
  if (is_array($value)) {
    $clean = [];
    foreach ($value as $key => $item) {
      $cleanKey = is_string($key) ? tcMonitoringSanitizeForJson($key) : $key;
      if (is_int($cleanKey) || is_string($cleanKey)) {
        $clean[$cleanKey] = tcMonitoringSanitizeForJson($item);
      }
    }
    return $clean;
  }
  if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
    return $value;
  }
  return (string)$value;
}

function tcMonitoringJsonResponse(array $payload): string
{
  $flags = JSON_UNESCAPED_UNICODE;
  if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
  }
  if (defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) {
    $flags |= JSON_PARTIAL_OUTPUT_ON_ERROR;
  }
  $json = json_encode($payload, $flags);
  if (is_string($json) && $json !== '') {
    return $json;
  }
  $sanitizedJson = json_encode(tcMonitoringSanitizeForJson($payload), $flags);
  if (is_string($sanitizedJson) && $sanitizedJson !== '') {
    return $sanitizedJson;
  }
  $fallback = [
    'status' => 'partial',
    'message' => 'بخشی از داده‌های مانیتورینگ قابل نمایش نبود.',
    'data' => tcMonitoringEmptyStats(['بخشی از داده‌های مانیتورینگ قابل تبدیل به JSON نبود.'])
  ];
  $json = json_encode($fallback, $flags);
  return is_string($json) && $json !== '' ? $json : '{"status":"error","message":"Monitoring JSON encode failed."}';
}

function tcMonitoringReadCsvRows(string $path): array
{
  if (tcInviteesCsvIsManagedPath($path)) {
    return tcInviteesCsvReadRowsSnapshot($path);
  }
  if (!is_file($path)) {
    return [];
  }
  $rows = [];
  $handle = fopen($path, 'r');
  if ($handle === false) {
    return [];
  }
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

function tcMonitoringNormalizeHeader(string $value): string
{
  $withoutBom = str_replace("\xEF\xBB\xBF", '', $value);
  $normalized = strtolower(trim($withoutBom));
  $normalized = preg_replace('/\s+/u', ' ', $normalized);
  return is_string($normalized) ? $normalized : '';
}

function tcMonitoringFindHeaderIndex(array $header, array $names): int
{
  $needles = [];
  foreach ($names as $name) {
    $needle = tcMonitoringNormalizeHeader((string)$name);
    if ($needle !== '') {
      $needles[$needle] = true;
    }
  }
  if (!$needles) {
    return -1;
  }
  foreach ($header as $index => $cell) {
    $normalized = tcMonitoringNormalizeHeader((string)$cell);
    if ($normalized !== '' && isset($needles[$normalized])) {
      return (int)$index;
    }
  }
  return -1;
}

function tcMonitoringResolveMappedIndex(array $header, array $mapping, array $mappingKeys, array $fallbackNames): int
{
  foreach ($mappingKeys as $mappingKey) {
    $mappedIndex = $mapping[(string)$mappingKey] ?? null;
    if (!is_numeric($mappedIndex)) {
      continue;
    }
    $index = (int)$mappedIndex;
    if ($index >= 0 && $index < count($header)) {
      return $index;
    }
  }
  return tcMonitoringFindHeaderIndex($header, $fallbackNames);
}

function tcMonitoringCell(array $row, int $index): string
{
  if ($index < 0) {
    return '';
  }
  return trim((string)($row[$index] ?? ''));
}

function tcMonitoringParseNumber($value): float
{
  if (!is_scalar($value)) {
    return 0.0;
  }
  $token = trim((string)$value);
  if ($token === '') {
    return 0.0;
  }
  $token = str_replace([',', '٬', ' '], '', $token);
  if (!is_numeric($token)) {
    return 0.0;
  }
  $parsed = (float)$token;
  if (!is_finite($parsed) || $parsed < 0) {
    return 0.0;
  }
  return $parsed;
}

function tcMonitoringParseInt($value): int
{
  return (int)floor(tcMonitoringParseNumber($value));
}

function tcMonitoringSplitTokens(string $value): array
{
  $parts = preg_split('/\s*(?:,|،|;)\s*/u', trim($value));
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

function tcMonitoringParseTaskScoreMap(string $value): array
{
  $items = tcMonitoringSplitTokens($value);
  $map = [];
  foreach ($items as $item) {
    $separatorPos = strpos($item, '::');
    $separatorLength = 2;
    if ($separatorPos === false) {
      $separatorPos = strpos($item, ':');
      $separatorLength = 1;
    }
    if ($separatorPos === false) {
      continue;
    }
    $taskId = trim(substr($item, 0, $separatorPos));
    if ($taskId === '') {
      continue;
    }
    $scoreRaw = trim(substr($item, $separatorPos + $separatorLength));
    $map[$taskId] = tcMonitoringParseNumber($scoreRaw);
  }
  return $map;
}

function tcMonitoringParseTeamTaskMap(string $value): array
{
  $items = tcMonitoringSplitTokens($value);
  $map = [];
  foreach ($items as $item) {
    $parts = explode('::', $item);
    if (count($parts) < 4) {
      continue;
    }
    $taskId = trim((string)($parts[0] ?? ''));
    if ($taskId === '') {
      continue;
    }
    $map[$taskId] = [
      'teamName' => trim((string)($parts[1] ?? '')),
      'status' => trim((string)($parts[2] ?? '')),
      'score' => tcMonitoringParseNumber($parts[3] ?? 0)
    ];
  }
  return $map;
}

function tcMonitoringWorkIdGroup(string $workId): string
{
  $trimmed = trim($workId);
  if ($trimmed === '') {
    return 'نامشخص';
  }
  if (preg_match('/^\s*(\d)/u', $trimmed, $matches)) {
    return (string)($matches[1] ?? 'نامشخص');
  }
  return function_exists('mb_substr') ? mb_substr($trimmed, 0, 1, 'UTF-8') : substr($trimmed, 0, 1);
}

function tcMonitoringNormalizeWorkIdGroupKey(string $group): string
{
  $normalized = trim(strtr($group, [
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
  ]));
  return $normalized !== '' ? $normalized : 'نامشخص';
}

function tcMonitoringRequestedWorkIdGroups(array $availableGroups): array
{
  if (!array_key_exists('groups', $_GET)) {
    return $availableGroups;
  }
  $rawValue = $_GET['groups'];
  $tokens = [];
  if (is_array($rawValue)) {
    foreach ($rawValue as $item) {
      $token = trim(rawurldecode((string)$item));
      if ($token !== '') {
        $tokens[] = $token;
      }
    }
  } else {
    $raw = trim((string)$rawValue);
    if ($raw === '') {
      return $availableGroups;
    }
    foreach (explode(',', $raw) as $item) {
      $token = trim(rawurldecode((string)$item));
      if ($token !== '') {
        $tokens[] = $token;
      }
    }
  }
  if (in_array('__none', $tokens, true)) {
    return [];
  }
  $availableByKey = [];
  foreach ($availableGroups as $group) {
    $availableByKey[tcMonitoringNormalizeWorkIdGroupKey((string)$group)] = (string)$group;
  }
  $selected = [];
  foreach ($tokens as $group) {
    $key = tcMonitoringNormalizeWorkIdGroupKey((string)$group);
    if ($key !== '' && isset($availableByKey[$key])) {
      $selected[$availableByKey[$key]] = true;
    }
  }
  return array_keys($selected);
}

function tcMonitoringHasExplicitWorkIdGroupFilter(): bool
{
  return array_key_exists('groups', $_GET);
}

function tcMonitoringUserWorkIdGroupKey(array $user): string
{
  return tcMonitoringNormalizeWorkIdGroupKey((string)($user['workIdGroup'] ?? tcMonitoringWorkIdGroup((string)($user['workId'] ?? ''))));
}

function tcMonitoringParseTimestamp(string $value): ?DateTimeImmutable
{
  $trimmed = trim($value);
  if ($trimmed === '') {
    return null;
  }
  try {
    return new DateTimeImmutable($trimmed);
  } catch (Throwable $err) {
    return null;
  }
}

function tcMonitoringParseDescribePhotoPicksMap(string $value): array
{
  $items = tcMonitoringSplitTokens($value);
  $map = [];
  foreach ($items as $item) {
    $parts = explode('::', $item, 3);
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

function tcMonitoringCountWords(string $text): int
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

function tcMonitoringHasDescribePhotoSubmissionForTask(array $user, string $taskId, string $tagCode, string $tasksDir): bool
{
  $normalizedTaskId = trim($taskId);
  if ($normalizedTaskId === '') {
    return false;
  }
  $picksMap = is_array($user['describePhotoPicksMap'] ?? null) ? $user['describePhotoPicksMap'] : [];
  $taskPicks = is_array($picksMap[$normalizedTaskId] ?? null) ? $picksMap[$normalizedTaskId] : [];
  if (!$taskPicks) {
    return false;
  }

  $normalizedTagCode = strtoupper(trim($tagCode));
  $normalizedTagCode = preg_replace('/[^A-Z0-9_-]+/', '', $normalizedTagCode);
  if (!is_string($normalizedTagCode) || $normalizedTagCode === '') {
    return false;
  }

  $articlesDir = $tasksDir
    . DIRECTORY_SEPARATOR
    . $normalizedTagCode
    . DIRECTORY_SEPARATOR
    . 'photos'
    . DIRECTORY_SEPARATOR
    . 'articles';
  if (!is_dir($articlesDir)) {
    return false;
  }

  static $submissionCache = [];
  foreach ($taskPicks as $fileNameRaw) {
    $fileName = basename(trim((string)$fileNameRaw));
    if ($fileName === '') {
      continue;
    }
    $filePath = $articlesDir . DIRECTORY_SEPARATOR . $fileName;
    if (!is_file($filePath)) {
      continue;
    }
    if (!array_key_exists($filePath, $submissionCache)) {
      $content = file_get_contents($filePath);
      if (!is_string($content)) {
        $content = '';
      }
      $submissionCache[$filePath] = tcMonitoringCountWords($content) > 0;
    }
    if ($submissionCache[$filePath]) {
      return true;
    }
  }
  return false;
}

function tcMonitoringNormalizeTaskType(string $value): string
{
  $token = strtolower(trim($value));
  if (in_array($token, ['quiz', 'quiz-task', 'quiz task'], true)) {
    return 'quiz';
  }
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

function tcMonitoringNormalizeBool($value): bool
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

function tcMonitoringNormalizeDate(string $value): string
{
  $trimmed = trim($value);
  return preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) ? $trimmed : '';
}

function tcMonitoringNormalizeTime(string $value): string
{
  $trimmed = trim($value);
  if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $trimmed, $matches)) {
    return (string)($matches[1] ?? '00') . ':' . (string)($matches[2] ?? '00');
  }
  return '';
}

function tcMonitoringTaskTimeToSeconds(string $value): ?int
{
  $trimmed = trim($value);
  if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $trimmed, $matches)) {
    return null;
  }
  return ((int)$matches[1] * 3600) + ((int)$matches[2] * 60) + (isset($matches[3]) ? (int)$matches[3] : 0);
}

function tcMonitoringNowTehran(): array
{
  try {
    $dt = new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
  } catch (Throwable $err) {
    $dt = new DateTimeImmutable('now');
  }
  return ['date' => $dt->format('Y-m-d'), 'time' => $dt->format('H:i:s')];
}

function tcMonitoringDeriveTaskStatus(array $task): string
{
  $active = tcMonitoringNormalizeBool($task['active'] ?? false);
  $duration = tcMonitoringNormalizeBool($task['duration'] ?? false);
  if (!$duration) {
    return $active ? 'active' : 'inactive';
  }

  $startDate = tcMonitoringNormalizeDate((string)($task['startDate'] ?? ''));
  $endDate = tcMonitoringNormalizeDate((string)($task['endDate'] ?? ''));
  $startTime = tcMonitoringNormalizeTime((string)($task['startTime'] ?? ''));
  $endTime = tcMonitoringNormalizeTime((string)($task['endTime'] ?? ''));
  $now = tcMonitoringNowTehran();
  $today = (string)($now['date'] ?? '');
  if ($startDate === '' || $today === '') {
    return 'inactive';
  }
  if ($startDate > $today) {
    return 'upcoming';
  }
  if ($endDate !== '' && $endDate < $today) {
    return 'ended';
  }
  if ($startDate === $today) {
    $startSeconds = tcMonitoringTaskTimeToSeconds($startTime);
    $nowSeconds = tcMonitoringTaskTimeToSeconds((string)($now['time'] ?? '')) ?? 0;
    if ($startSeconds !== null && $nowSeconds < $startSeconds) {
      return 'upcoming';
    }
  }
  if ($endDate !== '' && $endDate === $today) {
    $endSeconds = tcMonitoringTaskTimeToSeconds($endTime);
    $nowSeconds = tcMonitoringTaskTimeToSeconds((string)($now['time'] ?? '')) ?? 0;
    if ($endSeconds !== null && $nowSeconds >= $endSeconds) {
      return 'ended';
    }
  }
  return 'active';
}

function tcMonitoringTaskHasStarted(array $task): bool
{
  $status = (string)($task['status'] ?? tcMonitoringDeriveTaskStatus($task));
  return in_array($status, ['active', 'ended'], true);
}

function tcMonitoringTaskHasGoldenTime(array $task): bool
{
  $taskType = tcMonitoringNormalizeTaskType((string)($task['taskType'] ?? 'quiz'));
  if ($taskType === 'conditional_quiz' && array_key_exists('hasGoldenTime', $task)) {
    return tcMonitoringNormalizeBool($task['hasGoldenTime']);
  }
  return $taskType === 'quiz' || $taskType === 'conditional_quiz';
}

function tcMonitoringTaskGoldenTimeApplies(array $task): bool
{
  $taskType = tcMonitoringNormalizeTaskType((string)($task['taskType'] ?? 'quiz'));
  if ($taskType !== 'quiz' && $taskType !== 'conditional_quiz') {
    return false;
  }
  if (!tcMonitoringTaskHasGoldenTime($task)) {
    return false;
  }
  if (!tcMonitoringNormalizeBool($task['duration'] ?? false)) {
    return false;
  }
  return tcMonitoringNormalizeDate((string)($task['endDate'] ?? '')) !== ''
    && tcMonitoringNormalizeTime((string)($task['endTime'] ?? '')) !== '';
}

function tcMonitoringTaskGoldenEndAt(array $task): ?DateTimeImmutable
{
  if (!tcMonitoringTaskGoldenTimeApplies($task)) {
    return null;
  }
  $endDate = tcMonitoringNormalizeDate((string)($task['endDate'] ?? ''));
  $endTime = tcMonitoringNormalizeTime((string)($task['endTime'] ?? ''));
  try {
    return new DateTimeImmutable($endDate . ' ' . $endTime . ':00', new DateTimeZone('Asia/Tehran'));
  } catch (Throwable $err) {
    return null;
  }
}

function tcMonitoringReadQuestionCount(string $tasksDir, string $tagCode): int
{
  $normalizedTagCode = strtoupper(trim($tagCode));
  $normalizedTagCode = preg_replace('/[^A-Z0-9_-]+/', '', $normalizedTagCode);
  if (!is_string($normalizedTagCode) || $normalizedTagCode === '') {
    return 0;
  }
  $path = $tasksDir . DIRECTORY_SEPARATOR . $normalizedTagCode . DIRECTORY_SEPARATOR . 'TCQ list.json';
  $decoded = tcMonitoringReadJson($path, []);
  return is_array($decoded) ? count($decoded) : 0;
}

function tcMonitoringReadQuestionsPerAttempt(string $tasksDir, string $tagCode): int
{
  $normalizedTagCode = strtoupper(trim($tagCode));
  $normalizedTagCode = preg_replace('/[^A-Z0-9_-]+/', '', $normalizedTagCode);
  if (!is_string($normalizedTagCode) || $normalizedTagCode === '') {
    return 0;
  }
  $path = $tasksDir . DIRECTORY_SEPARATOR . $normalizedTagCode . DIRECTORY_SEPARATOR . 'TCQ settings.json';
  $decoded = tcMonitoringReadJson($path, []);
  if (!is_array($decoded)) {
    return 0;
  }
  return max(0, tcMonitoringParseInt($decoded['questionsPerAttempt'] ?? ($decoded['questions_per_attempt'] ?? 0)));
}

function tcMonitoringReadTaskScoreSettings(string $tasksDir, string $tagCode): array
{
  $defaults = ['score' => 0, 'afterEndtimeScore' => 0, 'hasGoldenTime' => true];
  $normalizedTagCode = strtoupper(trim($tagCode));
  $normalizedTagCode = preg_replace('/[^A-Z0-9_-]+/', '', $normalizedTagCode);
  if (!is_string($normalizedTagCode) || $normalizedTagCode === '') {
    return $defaults;
  }
  $path = $tasksDir . DIRECTORY_SEPARATOR . $normalizedTagCode . DIRECTORY_SEPARATOR . 'task-score.json';
  $decoded = tcMonitoringReadJson($path, []);
  if (!is_array($decoded)) {
    return $defaults;
  }
  return [
    'score' => max(0, tcMonitoringParseInt($decoded['score'] ?? 0)),
    'afterEndtimeScore' => max(0, tcMonitoringParseInt($decoded['afterEndtimeScore'] ?? ($decoded['after_endtime_score'] ?? 0))),
    'hasGoldenTime' => array_key_exists('hasGoldenTime', $decoded) || array_key_exists('has_golden_time', $decoded)
      ? tcMonitoringNormalizeBool($decoded['hasGoldenTime'] ?? ($decoded['has_golden_time'] ?? true))
      : true
  ];
}

function tcMonitoringMinPositiveScoreValue(array $values): int
{
  $min = 0;
  foreach ($values as $value) {
    $score = max(0, tcMonitoringParseInt($value));
    if ($score <= 0) {
      continue;
    }
    if ($min === 0 || $score < $min) {
      $min = $score;
    }
  }
  return $min;
}

function tcMonitoringReadTasks(string $storePath, string $tasksDir): array
{
  if (!is_file($storePath)) {
    return [];
  }
  $content = file_get_contents($storePath);
  if (!is_string($content) || trim($content) === '') {
    return [];
  }
  $jsonText = $content;
  if (preg_match('/window\.TC_TASKS\s*=\s*(.*?);\s*$/s', $content, $matches)) {
    $jsonText = trim((string)($matches[1] ?? ''));
  }
  $decoded = json_decode($jsonText, true);
  if (!is_array($decoded)) {
    return [];
  }

  $tasks = [];
  $seen = [];
  foreach ($decoded as $item) {
    if (!is_array($item)) {
      continue;
    }
    $id = trim((string)($item['id'] ?? ''));
    $title = trim((string)($item['title'] ?? ''));
    $tagCode = strtoupper(trim((string)($item['tagCode'] ?? ($item['tag_code'] ?? ''))));
    $tagCode = preg_replace('/[^A-Z0-9_-]+/', '', $tagCode);
    if (!is_string($tagCode)) {
      $tagCode = '';
    }
    if ($id === '' || $title === '' || $tagCode === '') {
      continue;
    }
    if (isset($seen[$id])) {
      continue;
    }
    $seen[$id] = true;
    $taskType = tcMonitoringNormalizeTaskType((string)($item['taskType'] ?? ($item['task_type'] ?? 'quiz')));
    $scoreSettings = tcMonitoringReadTaskScoreSettings($tasksDir, $tagCode);
    $baseScore = max(0, tcMonitoringParseInt($item['score'] ?? $scoreSettings['score'] ?? 0));
    $afterEndtimeScore = max(0, tcMonitoringParseInt($item['afterEndtimeScore'] ?? ($item['after_endtime_score'] ?? ($scoreSettings['afterEndtimeScore'] ?? 0))));
    $questionCount = tcMonitoringReadQuestionCount($tasksDir, $tagCode);
    $questionsPerAttempt = tcMonitoringReadQuestionsPerAttempt($tasksDir, $tagCode);
    $selectedQuestionCount = $questionsPerAttempt > 0 ? min($questionsPerAttempt, $questionCount) : $questionCount;
    $minPositiveUnitScore = tcMonitoringMinPositiveScoreValue([$baseScore, $afterEndtimeScore]);
    $maxScore = $taskType === 'conditional_quiz'
      ? max($baseScore, $afterEndtimeScore) * max(0, $selectedQuestionCount)
      : max($baseScore, $afterEndtimeScore);
    $minScore = $taskType === 'conditional_quiz'
      ? $minPositiveUnitScore
      : $minPositiveUnitScore;
    $taskRecord = [
      'id' => $id,
      'title' => $title,
      'tagCode' => $tagCode,
      'taskType' => $taskType,
      'score' => $baseScore,
      'afterEndtimeScore' => $afterEndtimeScore,
      'hasGoldenTime' => (bool)($scoreSettings['hasGoldenTime'] ?? true),
      'minScore' => $minScore,
      'maxScore' => $maxScore,
      'active' => tcMonitoringNormalizeBool($item['active'] ?? false),
      'duration' => tcMonitoringNormalizeBool($item['duration'] ?? false),
      'startDate' => tcMonitoringNormalizeDate((string)($item['startDate'] ?? ($item['start_date'] ?? ''))),
      'startTime' => tcMonitoringNormalizeTime((string)($item['startTime'] ?? ($item['start_time'] ?? ''))),
      'endDate' => tcMonitoringNormalizeDate((string)($item['endDate'] ?? ($item['end_date'] ?? ''))),
      'endTime' => tcMonitoringNormalizeTime((string)($item['endTime'] ?? ($item['end_time'] ?? ''))),
      'order' => max(1, tcMonitoringParseInt($item['order'] ?? 0))
    ];
    $taskRecord['status'] = tcMonitoringDeriveTaskStatus($taskRecord);
    $tasks[] = $taskRecord;
  }

  usort($tasks, static function (array $a, array $b): int {
    return (int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0);
  });
  return $tasks;
}

function tcMonitoringBuildEventInfo(array $tasks): array
{
  $starts = [];
  $ends = [];
  $timezone = new DateTimeZone('Asia/Tehran');
  foreach ($tasks as $task) {
    if (!is_array($task)) {
      continue;
    }
    $startDate = tcMonitoringNormalizeDate((string)($task['startDate'] ?? ''));
    $startTime = tcMonitoringNormalizeTime((string)($task['startTime'] ?? ''));
    $endDate = tcMonitoringNormalizeDate((string)($task['endDate'] ?? ''));
    $endTime = tcMonitoringNormalizeTime((string)($task['endTime'] ?? ''));
    $start = null;
    $end = null;
    try {
      if ($startDate !== '') {
        $start = new DateTimeImmutable($startDate . ' ' . ($startTime !== '' ? $startTime : '00:00') . ':00', $timezone);
        $starts[] = $start;
      }
      if ($endDate !== '') {
        $end = new DateTimeImmutable($endDate . ' ' . ($endTime !== '' ? $endTime : '23:59') . ':00', $timezone);
        $ends[] = $end;
      } elseif ($start) {
        $ends[] = $start;
      }
    } catch (Throwable $err) {
      continue;
    }
  }
  usort($starts, static fn(DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);
  usort($ends, static fn(DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);
  $start = $starts[0] ?? null;
  $end = $ends ? $ends[count($ends) - 1] : null;
  $durationMinutes = ($start && $end && $end >= $start)
    ? max(0, (int)floor(($end->getTimestamp() - $start->getTimestamp()) / 60))
    : 0;
  return [
    'startAt' => $start ? $start->format(DateTimeInterface::ATOM) : '',
    'endAt' => $end ? $end->format(DateTimeInterface::ATOM) : '',
    'durationMinutes' => $durationMinutes
  ];
}

function tcMonitoringReadPrizeLevels(string $path): array
{
  $decoded = tcMonitoringReadJson($path, []);
  if (!is_array($decoded)) {
    return [];
  }
  $levels = [];
  foreach ($decoded as $item) {
    if (!is_array($item)) {
      continue;
    }
    $score = max(1, tcMonitoringParseInt($item['score'] ?? ($item['levelScore'] ?? 0)));
    $name = trim((string)($item['name'] ?? ($item['levelName'] ?? ('سطح ' . $score))));
    if ($name === '') {
      $name = 'سطح ' . $score;
    }
    $typeToken = strtolower(trim((string)($item['type'] ?? ($item['levelType'] ?? 'value_sum'))));
    $type = in_array($typeToken, ['out_of_value', 'pot'], true) ? $typeToken : 'value_sum';
    $levels[] = [
      'id' => trim((string)($item['id'] ?? '')),
      'name' => $name,
      'score' => $score,
      'type' => $type
    ];
  }
  usort($levels, static function (array $a, array $b): int {
    return (int)($a['score'] ?? 0) <=> (int)($b['score'] ?? 0);
  });
  return $levels;
}

function tcMonitoringReadPrizes(string $path): array
{
  $decoded = tcPrizeInventoryReadSnapshot($path);
  if (!is_array($decoded)) {
    return [];
  }
  $prizes = [];
  foreach ($decoded as $item) {
    if (!is_array($item)) {
      continue;
    }
    $name = trim((string)($item['name'] ?? ''));
    if ($name === '') {
      continue;
    }
    $quantity = max(0, tcMonitoringParseInt($item['quantity'] ?? 0));
    $last = max(0, tcMonitoringParseInt($item['last'] ?? $quantity));
    if ($quantity === 0 && $last > 0) {
      $quantity = $last;
    }
    if ($last > $quantity) {
      $last = $quantity;
    }
    $value = tcMonitoringParseNumber($item['value'] ?? 0);
    $isFake = (bool)($item['isFake'] ?? false);
    $prizes[] = [
      'name' => $name,
      'onWheelName' => trim((string)($item['onWheelName'] ?? $name)),
      'quantity' => $quantity,
      'last' => $last,
      'value' => $value,
      'isFake' => $isFake
    ];
  }
  return $prizes;
}

function tcMonitoringBuildInviteesData(string $inviteesPath, string $mapPath): array
{
  $rows = tcMonitoringReadCsvRows($inviteesPath);
  if (!$rows || !isset($rows[0]) || !is_array($rows[0])) {
    return ['users' => [], 'rowsCount' => 0];
  }

  $header = $rows[0];
  $mapping = tcMonitoringReadJson($mapPath, []);
  if (!is_array($mapping)) {
    $mapping = [];
  }

  $workIdIndex = tcMonitoringResolveMappedIndex(
    $header,
    $mapping,
    ['workId', 'username'],
    ['Work ID', 'work id', 'workid', 'username', 'user name', 'national id', 'کد پرسنلی', 'کد ملی']
  );
  if ($workIdIndex < 0) {
    return ['users' => [], 'rowsCount' => max(0, count($rows) - 1)];
  }

  $firstNameIndex = tcMonitoringResolveMappedIndex(
    $header,
    $mapping,
    ['firstName', 'first_name'],
    ['First Name', 'first name', 'firstname', 'name', 'نام']
  );
  $lastNameIndex = tcMonitoringResolveMappedIndex(
    $header,
    $mapping,
    ['lastName', 'last_name'],
    ['Last Name', 'last name', 'lastname', 'family', 'surname', 'نام خانوادگی']
  );
  $fullNameIndex = tcMonitoringResolveMappedIndex(
    $header,
    $mapping,
    ['fullName', 'fullname', 'name', 'full_name'],
    ['Full Name', 'full name', 'name', 'نام و نام خانوادگی']
  );

  $scoreIndex = tcMonitoringFindHeaderIndex($header, ['score']);
  $loginCountIndex = tcMonitoringFindHeaderIndex($header, ['logins counts', 'logins count', 'login count']);
  $loginsIndex = tcMonitoringFindHeaderIndex($header, ['logins', 'login logs']);
  $taskCompletedIndex = tcMonitoringFindHeaderIndex($header, ['task completed ids', 'task completed id']);
  $taskScoreMapIndex = tcMonitoringFindHeaderIndex($header, ['task score map']);
  $infoTasksIndex = tcMonitoringFindHeaderIndex($header, ['info tasks']);
  $teamTasksIndex = tcMonitoringFindHeaderIndex($header, ['team task']);
  $describeTasksIndex = tcMonitoringFindHeaderIndex($header, ['describe photo task']);
  $describePicksIndex = tcMonitoringFindHeaderIndex($header, ['describe photo picks']);
  $outOfValueRewardsIndex = tcMonitoringFindHeaderIndex($header, ['out of value rewards']);
  $totalPrizeWonIndex = tcMonitoringFindHeaderIndex($header, ['total prize won', 'مجموع جوایز برنده شده']);

  $users = [];
  for ($i = 1; $i < count($rows); $i += 1) {
    $row = is_array($rows[$i] ?? null) ? $rows[$i] : [];
    $workId = tcMonitoringCell($row, $workIdIndex);
    if ($workId === '') {
      continue;
    }

    $firstName = tcMonitoringCell($row, $firstNameIndex);
    $lastName = tcMonitoringCell($row, $lastNameIndex);
    if (($firstName === '' || $lastName === '') && $fullNameIndex >= 0) {
      $fullName = tcMonitoringCell($row, $fullNameIndex);
      if ($fullName !== '') {
        $parts = preg_split('/\s+/u', $fullName, -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($parts) && $parts) {
          if ($firstName === '') {
            $firstName = (string)($parts[0] ?? '');
          }
          if ($lastName === '' && count($parts) > 1) {
            $lastName = trim((string)implode(' ', array_slice($parts, 1)));
          }
        }
      }
    }

    $displayName = trim($firstName . ' ' . $lastName);
    if ($displayName === '') {
      $displayName = $workId;
    }

    $score = max(0, tcMonitoringParseInt(tcMonitoringCell($row, $scoreIndex)));
    $loginCount = max(0, tcMonitoringParseInt(tcMonitoringCell($row, $loginCountIndex)));
    $loginStamps = tcMonitoringSplitTokens(tcMonitoringCell($row, $loginsIndex));
    if ($loginCount === 0 && $loginStamps) {
      $loginCount = count($loginStamps);
    }

    $completedTaskIds = tcMonitoringSplitTokens(tcMonitoringCell($row, $taskCompletedIndex));
    $taskScoreMap = tcMonitoringParseTaskScoreMap(tcMonitoringCell($row, $taskScoreMapIndex));
    $infoTasksMap = tcMonitoringParseTaskScoreMap(tcMonitoringCell($row, $infoTasksIndex));
    $teamTasksMap = tcMonitoringParseTeamTaskMap(tcMonitoringCell($row, $teamTasksIndex));
    $describeTasksMap = tcMonitoringParseTaskScoreMap(tcMonitoringCell($row, $describeTasksIndex));
    $describePhotoPicksMap = tcMonitoringParseDescribePhotoPicksMap(tcMonitoringCell($row, $describePicksIndex));

    $allCompletedLookup = [];
    foreach ($completedTaskIds as $taskId) {
      $allCompletedLookup[$taskId] = true;
    }
    foreach (array_keys($taskScoreMap) as $taskId) {
      $allCompletedLookup[(string)$taskId] = true;
    }
    foreach (array_keys($infoTasksMap) as $taskId) {
      $allCompletedLookup[(string)$taskId] = true;
    }
    foreach ($teamTasksMap as $taskId => $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $teamStatus = strtolower(trim((string)($entry['status'] ?? '')));
      $teamScore = tcMonitoringParseNumber($entry['score'] ?? 0);
      if ($teamScore > 0 || $teamStatus === 'started') {
        $allCompletedLookup[(string)$taskId] = true;
      }
    }
    foreach (array_keys($describeTasksMap) as $taskId) {
      $allCompletedLookup[(string)$taskId] = true;
    }

    $outOfValueRewards = tcMonitoringSplitTokens(tcMonitoringCell($row, $outOfValueRewardsIndex));
    $completedTaskCount = count($allCompletedLookup);
    $activityScore = ($completedTaskCount * 3) + $loginCount;

    $users[] = [
      'workId' => $workId,
      'name' => $displayName,
      'firstName' => $firstName,
      'lastName' => $lastName,
      'score' => $score,
      'loginCount' => $loginCount,
      'loginStamps' => $loginStamps,
      'hasLoggedIn' => ($loginCount > 0) || (count($loginStamps) > 0),
      'workIdGroup' => tcMonitoringWorkIdGroup($workId),
      'completedTaskIds' => $completedTaskIds,
      'taskScoreMap' => $taskScoreMap,
      'infoTasksMap' => $infoTasksMap,
      'teamTasksMap' => $teamTasksMap,
      'describeTasksMap' => $describeTasksMap,
      'describePhotoPicksMap' => $describePhotoPicksMap,
      'completedTaskCount' => $completedTaskCount,
      'outOfValueRewardCount' => count($outOfValueRewards),
      'totalPrizeWon' => max(0.0, tcMonitoringParseNumber(tcMonitoringCell($row, $totalPrizeWonIndex))),
      'activityScore' => $activityScore
    ];
  }

  return ['users' => $users, 'rowsCount' => max(0, count($rows) - 1)];
}

function tcMonitoringUserTaskCompletion(array $user, array $task, string $tasksDir, array $completionPhaseLookup = []): array
{
  $taskId = (string)($task['id'] ?? '');
  $taskType = (string)($task['taskType'] ?? 'quiz');
  if ($taskId === '') {
    return ['completed' => false, 'score' => 0.0, 'phase' => 'unknown'];
  }
  $workId = trim((string)($user['workId'] ?? ''));
  $loggedPhase = $workId !== '' ? (string)($completionPhaseLookup[$workId][$taskId] ?? '') : '';

  $completedLookup = [];
  foreach ((array)($user['completedTaskIds'] ?? []) as $completedTaskId) {
    $completedLookup[(string)$completedTaskId] = true;
  }
  $taskScoreMap = is_array($user['taskScoreMap'] ?? null) ? $user['taskScoreMap'] : [];
  $infoTasksMap = is_array($user['infoTasksMap'] ?? null) ? $user['infoTasksMap'] : [];
  $teamTasksMap = is_array($user['teamTasksMap'] ?? null) ? $user['teamTasksMap'] : [];
  $describeTasksMap = is_array($user['describeTasksMap'] ?? null) ? $user['describeTasksMap'] : [];

  if ($taskType === 'info') {
    if (array_key_exists($taskId, $infoTasksMap)) {
      return ['completed' => true, 'score' => (float)$infoTasksMap[$taskId], 'phase' => 'no_golden'];
    }
    return ['completed' => false, 'score' => 0.0, 'phase' => 'unknown'];
  }

  if ($taskType === 'team_task') {
    if (isset($teamTasksMap[$taskId]) && is_array($teamTasksMap[$taskId])) {
      $entry = $teamTasksMap[$taskId];
      $score = tcMonitoringParseNumber($entry['score'] ?? 0);
      $status = strtolower(trim((string)($entry['status'] ?? '')));
      return [
        'completed' => $score > 0 || $status === 'started',
        'score' => $score,
        'phase' => 'no_golden'
      ];
    }
    if (array_key_exists($taskId, $infoTasksMap)) {
      return ['completed' => true, 'score' => (float)$infoTasksMap[$taskId], 'phase' => 'no_golden'];
    }
    return ['completed' => false, 'score' => 0.0, 'phase' => 'unknown'];
  }

  if ($taskType === 'describe_photo') {
    $hasDescribeSubmission = tcMonitoringHasDescribePhotoSubmissionForTask(
      $user,
      $taskId,
      (string)($task['tagCode'] ?? ''),
      $tasksDir
    );
    if ($hasDescribeSubmission || array_key_exists($taskId, $describeTasksMap)) {
      return ['completed' => true, 'score' => (float)($describeTasksMap[$taskId] ?? 0), 'phase' => 'no_golden'];
    }
    return ['completed' => false, 'score' => 0.0, 'phase' => 'unknown'];
  }

  if (isset($completedLookup[$taskId]) || array_key_exists($taskId, $taskScoreMap)) {
    $score = (float)($taskScoreMap[$taskId] ?? 0);
    $phase = $loggedPhase !== '' ? $loggedPhase : 'active';
    if ($phase === 'active' && !tcMonitoringTaskGoldenTimeApplies($task)) {
      $phase = 'no_golden';
    }
    if ($phase === 'active' && $taskType !== 'conditional_quiz' && (float)$score > 0 && (int)$score === (int)($task['afterEndtimeScore'] ?? -1) && (int)($task['afterEndtimeScore'] ?? 0) !== (int)($task['score'] ?? 0)) {
      $phase = 'after_golden';
    }
    return ['completed' => true, 'score' => $score, 'phase' => $phase];
  }

  return ['completed' => false, 'score' => 0.0, 'phase' => 'unknown'];
}

function tcMonitoringBuildCompletionPhaseLookup(string $logsDir, array $tasks): array
{
  if (!is_dir($logsDir)) {
    return [];
  }
  $taskById = [];
  foreach ($tasks as $task) {
    if (!is_array($task)) {
      continue;
    }
    $taskId = trim((string)($task['id'] ?? ''));
    if ($taskId !== '') {
      $taskById[$taskId] = $task;
    }
  }
  if (!$taskById) {
    return [];
  }

  $lookup = [];
  $paths = glob($logsDir . DIRECTORY_SEPARATOR . '*.log');
  if (!is_array($paths) || !$paths) {
    return [];
  }
  sort($paths, SORT_NATURAL | SORT_FLAG_CASE);
  foreach ($paths as $path) {
    if (!is_file($path)) {
      continue;
    }
    $handle = fopen($path, 'r');
    if ($handle === false) {
      continue;
    }
    while (($line = fgets($handle)) !== false) {
      $event = json_decode(trim($line), true);
      if (!is_array($event)) {
        continue;
      }
      $userId = trim((string)($event['user_id'] ?? ''));
      if ($userId === '') {
        continue;
      }
      $metadata = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];
      $requestAction = trim((string)($metadata['request_action'] ?? ''));
      if ($requestAction !== 'quiz_complete_finished') {
        continue;
      }
      $taskId = trim((string)($metadata['task_id'] ?? ($metadata['taskId'] ?? ($event['entity_id'] ?? ''))));
      if ($taskId === '' || !isset($taskById[$taskId])) {
        continue;
      }
      if (isset($lookup[$userId][$taskId])) {
        continue;
      }
      $timestamp = tcMonitoringParseTimestamp((string)($event['timestamp'] ?? ''));
      if ($timestamp === null) {
        continue;
      }
      $task = $taskById[$taskId];
      if (!tcMonitoringTaskGoldenTimeApplies($task)) {
        $phase = 'no_golden';
      } else {
        $goldenEndAt = tcMonitoringTaskGoldenEndAt($task);
        $phase = $goldenEndAt !== null && $timestamp->setTimezone(new DateTimeZone('Asia/Tehran')) > $goldenEndAt
          ? 'after_golden'
          : 'active';
      }
      if (!isset($lookup[$userId])) {
        $lookup[$userId] = [];
      }
      $lookup[$userId][$taskId] = $phase;
    }
    fclose($handle);
  }
  return $lookup;
}

function tcMonitoringUserHasTaskParticipation(array $user, array $taskInteractionWorkIds = []): bool
{
  $workId = trim((string)($user['workId'] ?? ''));
  if ($workId !== '' && isset($taskInteractionWorkIds[$workId])) {
    return true;
  }
  if (max(0, (int)($user['completedTaskCount'] ?? 0)) > 0) {
    return true;
  }
  foreach (['completedTaskIds', 'taskScoreMap', 'infoTasksMap', 'teamTasksMap', 'describeTasksMap', 'describePhotoPicksMap'] as $key) {
    $value = $user[$key] ?? [];
    if (is_array($value) && count($value) > 0) {
      return true;
    }
  }
  return false;
}

function tcMonitoringReadTaskInteractionWorkIds(string $logsDir): array
{
  if (!is_dir($logsDir)) {
    return [];
  }
  $paths = glob($logsDir . DIRECTORY_SEPARATOR . '*.log');
  if (!is_array($paths) || !$paths) {
    return [];
  }
  $workIds = [];
  foreach ($paths as $path) {
    if (!is_file($path)) {
      continue;
    }
    $handle = fopen($path, 'r');
    if ($handle === false) {
      continue;
    }
    while (($line = fgets($handle)) !== false) {
      $event = json_decode(trim($line), true);
      if (!is_array($event)) {
        continue;
      }
      $userId = trim((string)($event['user_id'] ?? ''));
      if ($userId === '') {
        continue;
      }
      $action = trim((string)($event['action'] ?? ''));
      $metadata = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];
      $requestAction = trim((string)($metadata['request_action'] ?? ($metadata['requestAction'] ?? '')));
      $clientEventType = trim((string)($metadata['client_event_type'] ?? ($metadata['clientEventType'] ?? '')));
      $taskId = trim((string)($metadata['task_id'] ?? ($metadata['taskId'] ?? ($event['entity_id'] ?? ''))));
      $isTaskInteraction = false;
      if ($action === 'taskclub.task.action') {
        $isTaskInteraction = true;
      } elseif ($clientEventType === 'task_action' && $taskId !== '') {
        $isTaskInteraction = true;
      } elseif ($requestAction !== '' && preg_match('/^(?:task_|team_task_|describe_photo_)/', $requestAction) === 1) {
        $isTaskInteraction = true;
      }
      if ($isTaskInteraction) {
        $workIds[$userId] = true;
      }
    }
    fclose($handle);
  }
  return $workIds;
}

function tcMonitoringScoreStages(array $users, int $minScore, int $maxScore, array $taskInteractionWorkIds = []): array
{
  $minScore = max(0, $minScore);
  $maxScore = max(0, $maxScore);
  if ($maxScore > 0 && $minScore > $maxScore) {
    $minScore = $maxScore;
  }
  $rangeStart = $minScore > 0 ? $minScore : 1;
  $range = $maxScore >= $rangeStart ? ($maxScore - $rangeStart + 1) : 0;
  $stageSize = $range > 0 ? $range / 6 : 0;
  $stages = [];
  $stages[] = [
    'stage' => 0,
    'label' => 'Participated with 0 score',
    'minScore' => 0,
    'maxScore' => 0,
    'users' => 0,
    'percentage' => 0.0,
    'avgScore' => 0.0,
    'heat' => 0.0
  ];
  for ($i = 0; $i < 6; $i += 1) {
    $min = $range > 0 ? (int)floor($rangeStart + ($stageSize * $i)) : 0;
    $max = $range > 0 ? (int)floor($rangeStart + ($stageSize * ($i + 1)) - 1) : 0;
    if ($i === 5) {
      $max = $maxScore;
    }
    if ($range > 0 && $max < $min) {
      $max = $min;
    }
    $stages[] = [
      'stage' => $i + 1,
      'minScore' => $min,
      'maxScore' => $max,
      'users' => 0,
      'percentage' => 0.0,
      'avgScore' => 0.0,
      'heat' => 0.0
    ];
  }

  $sums = array_fill(0, 7, 0.0);
  $includedUsers = 0;
  foreach ($users as $user) {
    if (!tcMonitoringUserHasTaskParticipation($user, $taskInteractionWorkIds)) {
      continue;
    }
    $score = max(0, (int)($user['score'] ?? 0));
    if ($score === 0) {
      $index = 0;
    } elseif ($range <= 0 || $score < $rangeStart) {
      continue;
    } else {
      $index = min(6, max(1, 1 + (int)floor(($score - $rangeStart) / $stageSize)));
      if ($score >= $maxScore) {
        $index = 6;
      }
    }
    $includedUsers += 1;
    $stages[$index]['users'] = (int)$stages[$index]['users'] + 1;
    $sums[$index] += $score;
  }

  foreach ($stages as $index => $stage) {
    $count = (int)($stage['users'] ?? 0);
    $avg = $count > 0 ? $sums[$index] / $count : 0.0;
    $stages[$index]['percentage'] = $includedUsers > 0 ? round(($count * 100) / $includedUsers, 2) : 0.0;
    $stages[$index]['avgScore'] = round($avg, 2);
    $stages[$index]['heat'] = $maxScore > 0 ? round(min(1, $avg / $maxScore), 3) : 0.0;
  }
  return $stages;
}

function tcMonitoringFallbackUserCompletedTask(array $user, array $task): bool
{
  $taskId = (string)($task['id'] ?? '');
  if ($taskId === '') {
    return false;
  }
  $taskType = (string)($task['taskType'] ?? 'quiz');
  $completedLookup = [];
  foreach ((array)($user['completedTaskIds'] ?? []) as $completedTaskId) {
    $completedLookup[(string)$completedTaskId] = true;
  }
  $taskScoreMap = is_array($user['taskScoreMap'] ?? null) ? $user['taskScoreMap'] : [];
  $infoTasksMap = is_array($user['infoTasksMap'] ?? null) ? $user['infoTasksMap'] : [];
  $teamTasksMap = is_array($user['teamTasksMap'] ?? null) ? $user['teamTasksMap'] : [];
  $describeTasksMap = is_array($user['describeTasksMap'] ?? null) ? $user['describeTasksMap'] : [];
  if ($taskType === 'info') {
    return array_key_exists($taskId, $infoTasksMap);
  }
  if ($taskType === 'team_task') {
    if (isset($teamTasksMap[$taskId]) && is_array($teamTasksMap[$taskId])) {
      $entry = $teamTasksMap[$taskId];
      return tcMonitoringParseNumber($entry['score'] ?? 0) > 0 || strtolower(trim((string)($entry['status'] ?? ''))) === 'started';
    }
    return array_key_exists($taskId, $infoTasksMap);
  }
  if ($taskType === 'describe_photo') {
    return array_key_exists($taskId, $describeTasksMap);
  }
  return isset($completedLookup[$taskId]) || array_key_exists($taskId, $taskScoreMap);
}

function tcMonitoringBuildFallbackStats(string $baseDir, array $warnings = []): array
{
  $tasksPath = $baseDir . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . 'tasks.js';
  $tasksDir = $baseDir . DIRECTORY_SEPARATOR . 'tasks';
  $inviteesPath = $baseDir . DIRECTORY_SEPARATOR . 'TC Event' . DIRECTORY_SEPARATOR . 'Invitees mapped.csv';
  $inviteesMapPath = $baseDir . DIRECTORY_SEPARATOR . 'TC Event' . DIRECTORY_SEPARATOR . 'TC Mapped.json';
  $prizesPath = $baseDir . DIRECTORY_SEPARATOR . 'TC Prizes.json';
  $levelsPath = $baseDir . DIRECTORY_SEPARATOR . 'TC Prize Levels.json';

  $tasks = tcMonitoringReadTasks($tasksPath, $tasksDir);
  $eventInfo = tcMonitoringBuildEventInfo($tasks);
  $inviteesPayload = tcMonitoringBuildInviteesData($inviteesPath, $inviteesMapPath);
  $users = is_array($inviteesPayload['users'] ?? null) ? $inviteesPayload['users'] : [];
  $allUsers = $users;
  $allWorkIdGroups = array_values(array_unique(array_map(static fn(array $user): string => (string)($user['workIdGroup'] ?? 'نامشخص'), $allUsers)));
  usort($allWorkIdGroups, static fn(string $a, string $b): int => strnatcasecmp($a, $b));
  $selectedWorkIdGroups = tcMonitoringRequestedWorkIdGroups($allWorkIdGroups);
  $selectedGroupSet = array_fill_keys(array_map(static fn(string $group): string => tcMonitoringNormalizeWorkIdGroupKey($group), $selectedWorkIdGroups), true);
  $hasExplicitGroupFilter = tcMonitoringHasExplicitWorkIdGroupFilter();
  if ($selectedGroupSet) {
    $users = array_values(array_filter($allUsers, static fn(array $user): bool => isset($selectedGroupSet[tcMonitoringUserWorkIdGroupKey($user)])));
  } elseif ($hasExplicitGroupFilter) {
    $users = [];
  }
  $selectedWorkIdSet = [];
  foreach ($users as $user) {
    $workId = trim((string)($user['workId'] ?? ''));
    if ($workId !== '') {
      $selectedWorkIdSet[$workId] = true;
    }
  }
  $rowsCount = max(0, (int)($inviteesPayload['rowsCount'] ?? 0));
  $prizes = tcMonitoringReadPrizes($prizesPath);
  $levels = tcMonitoringReadPrizeLevels($levelsPath);
  $totalUsers = count($users);

  $loggedInUsers = 0;
  $usersWithCompletion = 0;
  $loggedInWithoutCompletion = 0;
  $sumScores = 0;
  $topScore = 0;
  foreach ($users as $user) {
    if (!is_array($user)) {
      continue;
    }
    if (!empty($user['hasLoggedIn'])) {
      $loggedInUsers += 1;
    }
    if (max(0, (int)($user['completedTaskCount'] ?? 0)) > 0) {
      $usersWithCompletion += 1;
    }
    if (!empty($user['hasLoggedIn']) && max(0, (int)($user['completedTaskCount'] ?? 0)) === 0) {
      $loggedInWithoutCompletion += 1;
    }
    $score = max(0, (int)($user['score'] ?? 0));
    $sumScores += $score;
    $topScore = max($topScore, $score);
  }

  $taskStats = [];
  $favoriteTask = null;
  foreach ($tasks as $task) {
    if (!is_array($task)) {
      continue;
    }
    $completedUsers = 0;
    foreach ($users as $user) {
      if (is_array($user) && tcMonitoringFallbackUserCompletedTask($user, $task)) {
        $completedUsers += 1;
      }
    }
    $completionRate = $totalUsers > 0 ? round(($completedUsers * 100) / $totalUsers, 2) : 0.0;
    $row = [
      'id' => (string)($task['id'] ?? ''),
      'title' => (string)($task['title'] ?? ''),
      'tagCode' => (string)($task['tagCode'] ?? ''),
      'taskType' => (string)($task['taskType'] ?? 'quiz'),
      'baseScore' => max(0, (int)($task['score'] ?? 0)),
      'afterEndtimeScore' => max(0, (int)($task['afterEndtimeScore'] ?? 0)),
      'maxScore' => max(0, (int)($task['maxScore'] ?? 0)),
      'hasGoldenTime' => (bool)($task['hasGoldenTime'] ?? true),
      'duration' => (bool)($task['duration'] ?? false),
      'startDate' => (string)($task['startDate'] ?? ''),
      'startTime' => (string)($task['startTime'] ?? ''),
      'endDate' => (string)($task['endDate'] ?? ''),
      'endTime' => (string)($task['endTime'] ?? ''),
      'status' => (string)($task['status'] ?? 'inactive'),
      'completedUsers' => $completedUsers,
      'goldenTimeUsers' => 0,
      'afterGoldenTimeUsers' => 0,
      'completionRate' => $completionRate,
      'awardedScoreAvg' => 0,
      'awardedScoreTotal' => 0
    ];
    $taskStats[] = $row;
    if ($favoriteTask === null || $completedUsers > (int)($favoriteTask['completedUsers'] ?? 0)) {
      $favoriteTask = [
        'id' => (string)($row['id'] ?? ''),
        'title' => (string)($row['title'] ?? ''),
        'completedUsers' => $completedUsers,
        'completionRate' => $completionRate
      ];
    }
  }

  $levelStats = [];
  $usersEligibleAnyLevel = 0;
  $minLevelScore = null;
  foreach ($levels as $level) {
    if (!is_array($level)) {
      continue;
    }
    $targetScore = max(0, (int)($level['score'] ?? 0));
    if ($targetScore <= 0) {
      continue;
    }
    $minLevelScore = $minLevelScore === null ? $targetScore : min($minLevelScore, $targetScore);
    $eligibleUsers = 0;
    foreach ($users as $user) {
      if (is_array($user) && max(0, (int)($user['score'] ?? 0)) >= $targetScore) {
        $eligibleUsers += 1;
      }
    }
    $levelStats[] = [
      'id' => (string)($level['id'] ?? ''),
      'name' => (string)($level['name'] ?? ('سطح ' . $targetScore)),
      'type' => (string)($level['type'] ?? 'value_sum'),
      'score' => $targetScore,
      'eligibleUsers' => $eligibleUsers,
      'eligibleRate' => $totalUsers > 0 ? round(($eligibleUsers * 100) / $totalUsers, 2) : 0.0
    ];
  }
  if ($minLevelScore !== null) {
    foreach ($users as $user) {
      if (is_array($user) && max(0, (int)($user['score'] ?? 0)) >= $minLevelScore) {
        $usersEligibleAnyLevel += 1;
      }
    }
  }
  $maximumPrizeCardsOpenable = array_sum(array_map(
    static fn(array $level): int => max(0, (int)($level['eligibleUsers'] ?? 0)),
    $levelStats
  ));
  $usersWithTaskParticipation = count(array_filter(
    $users,
    static fn(array $user): bool => tcMonitoringUserHasTaskParticipation($user)
  ));

  $prizeCapacity = 0;
  $prizeRemaining = 0;
  $prizeGiven = 0;
  $prizeValueRemaining = 0.0;
  $prizeValueGiven = 0.0;
  $prizeValueBudget = 0.0;
  foreach ($prizes as $prize) {
    if (!is_array($prize)) {
      continue;
    }
    $quantity = max(0, (int)($prize['quantity'] ?? 0));
    $last = max(0, min($quantity, (int)($prize['last'] ?? 0)));
    $value = max(0.0, (float)($prize['value'] ?? 0));
    $prizeCapacity += $quantity;
    $prizeRemaining += $last;
    $prizeGiven += max(0, $quantity - $last);
    if (empty($prize['isFake'])) {
      $prizeValueBudget += $quantity * $value;
      $prizeValueRemaining += $last * $value;
      $prizeValueGiven += max(0, $quantity - $last) * $value;
    }
  }
  $prizeValueAssignedToInvitees = array_sum(array_map(
    static fn(array $user): float => max(0.0, (float)($user['totalPrizeWon'] ?? 0)),
    $allUsers
  ));
  $prizeValueReconciliationDifference = $prizeValueAssignedToInvitees + $prizeValueRemaining - $prizeValueBudget;

  $mostActiveUsers = array_values(array_filter($users, 'is_array'));
  usort($mostActiveUsers, static function ($a, $b): int {
    return ((int)($b['activityScore'] ?? 0)) <=> ((int)($a['activityScore'] ?? 0));
  });
  $mostActiveUsers = array_slice(array_map(static function (array $user, int $index): array {
    return [
      'rank' => $index + 1,
      'name' => (string)($user['name'] ?? ''),
      'workId' => (string)($user['workId'] ?? ''),
      'score' => max(0, (int)($user['score'] ?? 0)),
      'loginCount' => max(0, (int)($user['loginCount'] ?? 0)),
      'completedTaskCount' => max(0, (int)($user['completedTaskCount'] ?? 0)),
      'activityScore' => max(0, (int)($user['activityScore'] ?? 0))
    ];
  }, $mostActiveUsers, array_keys($mostActiveUsers)), 0, 10);

  $fallbackGroupMap = [];
  foreach ($allUsers as $user) {
    if (!is_array($user)) {
      continue;
    }
    $group = (string)($user['workIdGroup'] ?? 'نامشخص');
    if (!isset($fallbackGroupMap[$group])) {
      $fallbackGroupMap[$group] = [
        'group' => $group,
        'users' => 0,
        'participants' => 0,
        'completionTotal' => 0,
        'scoreTotal' => 0
      ];
    }
    $fallbackGroupMap[$group]['users'] = (int)$fallbackGroupMap[$group]['users'] + 1;
    $completedCount = max(0, (int)($user['completedTaskCount'] ?? 0));
    if (!empty($user['hasLoggedIn']) || $completedCount > 0) {
      $fallbackGroupMap[$group]['participants'] = (int)$fallbackGroupMap[$group]['participants'] + 1;
    }
    $fallbackGroupMap[$group]['completionTotal'] = (int)$fallbackGroupMap[$group]['completionTotal'] + $completedCount;
    $fallbackGroupMap[$group]['scoreTotal'] = (float)$fallbackGroupMap[$group]['scoreTotal'] + max(0, (int)($user['score'] ?? 0));
  }
  $fallbackWorkIdGroupStats = [];
  foreach ($fallbackGroupMap as $group) {
    $groupUsers = max(0, (int)($group['users'] ?? 0));
    $participants = max(0, (int)($group['participants'] ?? 0));
    $fallbackWorkIdGroupStats[] = [
      'group' => (string)($group['group'] ?? 'نامشخص'),
      'users' => $groupUsers,
      'participants' => $participants,
      'participantRate' => $groupUsers > 0 ? round(($participants * 100) / $groupUsers, 2) : 0.0,
      'avgCompletedStartedMissions' => $groupUsers > 0 ? round(((int)($group['completionTotal'] ?? 0)) / $groupUsers, 2) : 0.0,
      'avgScore' => $groupUsers > 0 ? round(((float)($group['scoreTotal'] ?? 0)) / $groupUsers, 2) : 0.0,
      'missionRates' => []
    ];
  }
  usort($fallbackWorkIdGroupStats, static fn(array $a, array $b): int => strnatcasecmp((string)($a['group'] ?? ''), (string)($b['group'] ?? '')));

  $warnings[] = 'بخشی از محاسبات جدید مانیتورینگ ناموفق بود؛ داده‌های پایه نمایش داده می‌شود.';
  return array_merge(tcMonitoringEmptyStats($warnings), [
    'summary' => [
      'totalUsers' => $totalUsers,
      'loggedInUsers' => $loggedInUsers,
      'neverLoggedInUsers' => max(0, $totalUsers - $loggedInUsers),
      'usersWithCompletion' => $usersWithCompletion,
      'usersEligibleAnyLevel' => $usersEligibleAnyLevel,
      'maximumPrizeCardsOpenable' => $maximumPrizeCardsOpenable,
      'usersCompletedAllStarted' => 0,
      'highActiveCompletedAllStarted' => 0,
      'highActivityThreshold' => 0,
      'avgScore' => $totalUsers > 0 ? round($sumScores / $totalUsers, 2) : 0,
      'topScore' => $topScore,
      'minPossibleScore' => 0,
      'maxPossibleScore' => 0,
      'usersWithMaxPossibleScore' => 0,
      'scoreStageUserCount' => 0,
      'completionDistributionUserCount' => 0,
      'taskCount' => count($tasks),
      'startedTaskCount' => 0,
      'levelCount' => count($levelStats),
      'prizeCapacity' => $prizeCapacity,
      'prizeRemaining' => $prizeRemaining,
      'prizeGiven' => $prizeGiven,
      'prizeValueRemaining' => round($prizeValueRemaining, 2),
      'prizeValueGiven' => round($prizeValueGiven, 2),
      'prizeValueAssignedToInvitees' => round($prizeValueAssignedToInvitees, 2),
      'prizeValueBudget' => round($prizeValueBudget, 2),
      'prizeValueReconciliationDifference' => round($prizeValueReconciliationDifference, 2),
      'prizeValueHasProblem' => abs($prizeValueReconciliationDifference) > 0.005,
      'favoriteTask' => $favoriteTask
    ],
    'taskStats' => $taskStats,
    'levelStats' => $levelStats,
    'eventInfo' => $eventInfo,
    'participationStats' => [
      'totalUsers' => $totalUsers,
      'loggedInUsers' => $loggedInUsers,
      'usersWithTaskParticipation' => $usersWithTaskParticipation,
      'usersWithCompletion' => $usersWithCompletion,
      'usersCompletedAllStarted' => 0,
      'usersWithMaxPossibleScore' => 0,
      'usersEligibleAnyLevel' => $usersEligibleAnyLevel,
      'loggedInWithoutCompletion' => $loggedInWithoutCompletion,
      'neverLoggedInUsers' => max(0, $totalUsers - $loggedInUsers)
    ],
    'mostActiveUsers' => $mostActiveUsers,
    'workIdGroupStats' => $fallbackWorkIdGroupStats,
    'workIdGroups' => $allWorkIdGroups,
    'selectedWorkIdGroups' => $selectedWorkIdGroups,
    'prizeStats' => ['rows' => $prizes],
    'dataSources' => [
      'inviteesCsvFound' => is_file($inviteesPath),
      'inviteesRows' => $rowsCount,
      'tasksStoreFound' => is_file($tasksPath),
      'prizesFileFound' => is_file($prizesPath),
      'levelsFileFound' => is_file($levelsPath)
    ],
    'warnings' => array_values(array_unique(array_filter($warnings))),
    'generatedAt' => gmdate('c')
  ]);
}

function tcMonitoringBuildTimeEngagementStats(string $logsDir, array $taskWindows, int $totalUsers): array
{
  $empty = [
    'available' => false,
    'note' => 'این بخش برای رویدادهای قدیمی ممکن است در دسترس نباشد، چون گزارش فعالیت قبلی ثبت نشده است.',
    'usersLoginAndAnswerOnMissionStartDay' => 0,
    'usersLoginAndAnswerOnMissionStartDayRate' => 0.0,
    'userDaysWithMultiMissionSamePeriod' => 0,
    'logEventsRead' => 0
  ];
  if (!is_dir($logsDir)) {
    return $empty;
  }
  $paths = glob($logsDir . DIRECTORY_SEPARATOR . '*.log');
  if (!is_array($paths) || !$paths) {
    return $empty;
  }
  sort($paths, SORT_NATURAL | SORT_FLAG_CASE);

  $logEventsRead = 0;
  $loginsByUserDate = [];
  foreach ($paths as $path) {
    if (!is_file($path)) {
      continue;
    }
    $handle = fopen($path, 'r');
    if ($handle === false) {
      continue;
    }
    while (($line = fgets($handle)) !== false) {
      $decoded = json_decode(trim($line), true);
      if (!is_array($decoded)) {
        continue;
      }
      $logEventsRead += 1;
      if (trim((string)($decoded['action'] ?? '')) !== 'taskclub.user.login') {
        continue;
      }
      $userId = trim((string)($decoded['user_id'] ?? ''));
      $timestamp = tcMonitoringParseTimestamp((string)($decoded['timestamp'] ?? ''));
      if ($userId === '' || $timestamp === null) {
        continue;
      }
      try {
        $tehranTimestamp = $timestamp->setTimezone(new DateTimeZone('Asia/Tehran'));
      } catch (Throwable $err) {
        $tehranTimestamp = $timestamp;
      }
      $dateKey = $tehranTimestamp->format('Y-m-d');
      $seconds = ((int)$tehranTimestamp->format('H') * 3600) + ((int)$tehranTimestamp->format('i') * 60) + (int)$tehranTimestamp->format('s');
      if (!isset($loginsByUserDate[$userId])) {
        $loginsByUserDate[$userId] = [];
      }
      if (!isset($loginsByUserDate[$userId][$dateKey])) {
        $loginsByUserDate[$userId][$dateKey] = [];
      }
      $loginsByUserDate[$userId][$dateKey][] = $seconds;
    }
    fclose($handle);
  }

  if ($logEventsRead <= 0) {
    return $empty;
  }

  $usersLoginAndAnswerOnMissionStartDay = [];
  $answeredStartedDayTasksByUserDate = [];
  foreach ($paths as $path) {
    if (!is_file($path)) {
      continue;
    }
    $handle = fopen($path, 'r');
    if ($handle === false) {
      continue;
    }
    while (($line = fgets($handle)) !== false) {
      $event = json_decode(trim($line), true);
      if (!is_array($event)) {
        continue;
      }
      $userId = trim((string)($event['user_id'] ?? ''));
      $timestamp = tcMonitoringParseTimestamp((string)($event['timestamp'] ?? ''));
      if ($userId === '' || $timestamp === null) {
        continue;
      }
      $action = trim((string)($event['action'] ?? ''));
      $metadata = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];
      $requestAction = trim((string)($metadata['request_action'] ?? ''));
      if (!(($action === 'taskclub.task.action' && $requestAction === 'task_log_answer') || $requestAction === 'task_complete')) {
        continue;
      }
      $taskId = trim((string)($metadata['task_id'] ?? ($metadata['taskId'] ?? ($event['entity_id'] ?? ''))));
      if ($taskId === '' || !isset($taskWindows[$taskId])) {
        continue;
      }
      try {
        $tehranTimestamp = $timestamp->setTimezone(new DateTimeZone('Asia/Tehran'));
      } catch (Throwable $err) {
        $tehranTimestamp = $timestamp;
      }
      $dateKey = $tehranTimestamp->format('Y-m-d');
      if ((string)$taskWindows[$taskId]['date'] !== (string)$dateKey) {
        continue;
      }
      $answerSeconds = ((int)$tehranTimestamp->format('H') * 3600) + ((int)$tehranTimestamp->format('i') * 60) + (int)$tehranTimestamp->format('s');
      $startSeconds = (int)$taskWindows[$taskId]['startSeconds'];
      $endSeconds = (int)$taskWindows[$taskId]['endSeconds'];
      if ($answerSeconds < $startSeconds || $answerSeconds > $endSeconds) {
        continue;
      }
      $loginSecondsList = is_array($loginsByUserDate[$userId][$dateKey] ?? null) ? $loginsByUserDate[$userId][$dateKey] : [];
      $hasLoginInWindow = false;
      foreach ($loginSecondsList as $loginSeconds) {
        $loginSeconds = (int)$loginSeconds;
        if ($loginSeconds >= $startSeconds && $loginSeconds <= $endSeconds) {
          $hasLoginInWindow = true;
          break;
        }
      }
      if (!$hasLoginInWindow) {
        continue;
      }
      $usersLoginAndAnswerOnMissionStartDay[$userId] = true;
      $userDateKey = $userId . '|' . $dateKey;
      if (!isset($answeredStartedDayTasksByUserDate[$userDateKey])) {
        $answeredStartedDayTasksByUserDate[$userDateKey] = [];
      }
      $answeredStartedDayTasksByUserDate[$userDateKey][$taskId] = true;
    }
    fclose($handle);
  }

  $usersMultiMissionSameDayPeriod = 0;
  foreach ($answeredStartedDayTasksByUserDate as $taskMap) {
    if (count($taskMap) >= 2) {
      $usersMultiMissionSameDayPeriod += 1;
    }
  }

  return [
    'available' => true,
    'note' => '',
    'usersLoginAndAnswerOnMissionStartDay' => count($usersLoginAndAnswerOnMissionStartDay),
    'usersLoginAndAnswerOnMissionStartDayRate' => $totalUsers > 0 ? round((count($usersLoginAndAnswerOnMissionStartDay) * 100) / $totalUsers, 2) : 0.0,
    'userDaysWithMultiMissionSamePeriod' => $usersMultiMissionSameDayPeriod,
    'logEventsRead' => $logEventsRead
  ];
}

function tcMonitoringBuildStats(string $baseDir): array
{
  $tasksPath = $baseDir . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . 'tasks.js';
  $tasksDir = $baseDir . DIRECTORY_SEPARATOR . 'tasks';
  $inviteesPath = $baseDir . DIRECTORY_SEPARATOR . 'TC Event' . DIRECTORY_SEPARATOR . 'Invitees mapped.csv';
  $inviteesMapPath = $baseDir . DIRECTORY_SEPARATOR . 'TC Event' . DIRECTORY_SEPARATOR . 'TC Mapped.json';
  $prizesPath = $baseDir . DIRECTORY_SEPARATOR . 'TC Prizes.json';
  $levelsPath = $baseDir . DIRECTORY_SEPARATOR . 'TC Prize Levels.json';
  $logsDir = $baseDir . DIRECTORY_SEPARATOR . 'useractivitylogs' . DIRECTORY_SEPARATOR . 'logs';
  $warnings = [];

  $tasks = tcMonitoringReadTasks($tasksPath, $tasksDir);
  $eventInfo = tcMonitoringBuildEventInfo($tasks);
  $inviteesPayload = tcMonitoringBuildInviteesData($inviteesPath, $inviteesMapPath);
  $users = is_array($inviteesPayload['users'] ?? null) ? $inviteesPayload['users'] : [];
  $allUsers = $users;
  $allWorkIdGroups = array_values(array_unique(array_map(static fn(array $user): string => (string)($user['workIdGroup'] ?? tcMonitoringWorkIdGroup((string)($user['workId'] ?? ''))), $allUsers)));
  $allWorkIdGroups = array_values(array_filter($allWorkIdGroups, static fn(string $group): bool => trim($group) !== ''));
  usort($allWorkIdGroups, static fn(string $a, string $b): int => strnatcasecmp($a, $b));
  $selectedWorkIdGroups = tcMonitoringRequestedWorkIdGroups($allWorkIdGroups);
  $selectedGroupSet = array_fill_keys(array_map(static fn(string $group): string => tcMonitoringNormalizeWorkIdGroupKey($group), $selectedWorkIdGroups), true);
  $hasExplicitGroupFilter = tcMonitoringHasExplicitWorkIdGroupFilter();
  if ($selectedGroupSet) {
    $users = array_values(array_filter($allUsers, static fn(array $user): bool => isset($selectedGroupSet[tcMonitoringUserWorkIdGroupKey($user)])));
  } elseif ($hasExplicitGroupFilter) {
    $users = [];
  }
  $selectedWorkIdSet = [];
  foreach ($users as $user) {
    $workId = trim((string)($user['workId'] ?? ''));
    if ($workId !== '') {
      $selectedWorkIdSet[$workId] = true;
    }
  }
  $rowsCount = max(0, (int)($inviteesPayload['rowsCount'] ?? 0));
  $prizes = tcMonitoringReadPrizes($prizesPath);
  $levels = tcMonitoringReadPrizeLevels($levelsPath);
  $activityLogsAvailable = is_dir($logsDir);
  if (!$activityLogsAvailable) {
    $warnings[] = 'برای این رویداد گزارش فعالیت کافی وجود ندارد؛ آمار ورود و زمان پاسخ ممکن است برای رویدادهای قدیمی در دسترس نباشد.';
  }

  $totalUsers = count($users);
  $loggedInUsers = 0;
  $usersWithCompletion = 0;
  $loggedInWithoutCompletion = 0;
  $sumScores = 0;
  $topScore = 0;
  foreach ($users as $user) {
    if (($user['hasLoggedIn'] ?? false) === true) {
      $loggedInUsers += 1;
    }
    $completedTaskCount = (int)($user['completedTaskCount'] ?? 0);
    if ($completedTaskCount > 0) {
      $usersWithCompletion += 1;
    }
    if (($user['hasLoggedIn'] ?? false) === true && $completedTaskCount <= 0) {
      $loggedInWithoutCompletion += 1;
    }
    $score = max(0, (int)($user['score'] ?? 0));
    $sumScores += $score;
    if ($score > $topScore) {
      $topScore = $score;
    }
  }
  $averageScore = $totalUsers > 0 ? round($sumScores / $totalUsers, 2) : 0.0;
  $startedTasks = array_values(array_filter($tasks, static fn(array $task): bool => tcMonitoringTaskHasStarted($task)));
  $startedTaskCount = count($startedTasks);
  $startedTaskIdSet = [];
  foreach ($startedTasks as $task) {
    $startedTaskId = (string)($task['id'] ?? '');
    if ($startedTaskId !== '') {
      $startedTaskIdSet[$startedTaskId] = true;
    }
  }
  $maxPossibleScore = 0;
  $minPossibleScore = 0;
  foreach ($startedTasks as $task) {
    $maxPossibleScore += max(0, (int)($task['maxScore'] ?? max((int)($task['score'] ?? 0), (int)($task['afterEndtimeScore'] ?? 0))));
    $taskMinScore = max(0, (int)($task['minScore'] ?? tcMonitoringMinPositiveScoreValue([
      $task['score'] ?? 0,
      $task['afterEndtimeScore'] ?? 0,
      $task['maxScore'] ?? 0
    ])));
    if ($taskMinScore > 0 && ($minPossibleScore === 0 || $taskMinScore < $minPossibleScore)) {
      $minPossibleScore = $taskMinScore;
    }
  }
  $usersWithMaxPossibleScore = 0;
  if ($maxPossibleScore > 0) {
    foreach ($users as $user) {
      if (is_array($user) && max(0, (int)($user['score'] ?? 0)) >= $maxPossibleScore) {
        $usersWithMaxPossibleScore += 1;
      }
    }
  }
  $taskInteractionWorkIds = tcMonitoringReadTaskInteractionWorkIds($logsDir);
  $usersWithTaskParticipation = count(array_filter(
    $users,
    static fn(array $user): bool => tcMonitoringUserHasTaskParticipation($user, $taskInteractionWorkIds)
  ));
  try {
    $completionPhaseLookup = tcMonitoringBuildCompletionPhaseLookup($logsDir, $tasks);
  } catch (Throwable $err) {
    $completionPhaseLookup = [];
    $warnings[] = 'گزارش فعالیت قابل خواندن نبود؛ تفکیک مهلت طلایی ممکن است ناقص باشد.';
  }
  $scoreStages = tcMonitoringScoreStages($users, $minPossibleScore, $maxPossibleScore, $taskInteractionWorkIds);
  $scoreStageUserCount = array_sum(array_map(static fn(array $stage): int => max(0, (int)($stage['users'] ?? 0)), $scoreStages));

  $taskStatsMap = [];
  $taskScoreSums = [];
  $taskScoreCounts = [];
  foreach ($tasks as $task) {
    $taskId = (string)($task['id'] ?? '');
    if ($taskId === '') {
      continue;
    }
    $taskStatsMap[$taskId] = [
      'id' => $taskId,
      'title' => (string)($task['title'] ?? ''),
      'tagCode' => (string)($task['tagCode'] ?? ''),
      'taskType' => (string)($task['taskType'] ?? 'quiz'),
      'baseScore' => max(0, (int)($task['score'] ?? 0)),
      'afterEndtimeScore' => max(0, (int)($task['afterEndtimeScore'] ?? 0)),
      'maxScore' => max(0, (int)($task['maxScore'] ?? 0)),
      'status' => (string)($task['status'] ?? 'inactive'),
      'completedUsers' => 0,
      'goldenTimeUsers' => 0,
      'afterGoldenTimeUsers' => 0,
      'completionRate' => 0.0,
      'awardedScoreAvg' => 0.0,
      'awardedScoreTotal' => 0.0
    ];
    $taskScoreSums[$taskId] = 0.0;
    $taskScoreCounts[$taskId] = 0;
  }

  $completionDistribution = [];
  $usersCompletedAllStarted = 0;
  $highActiveCompletedAllStarted = 0;
  $completionDistributionUserCount = 0;
  $highActivityThreshold = 0;
  $activityScores = array_map(static fn(array $user): int => max(0, (int)($user['activityScore'] ?? 0)), $users);
  sort($activityScores, SORT_NUMERIC);
  if ($activityScores) {
    $thresholdIndex = max(0, (int)floor((count($activityScores) - 1) * 0.75));
    $highActivityThreshold = (int)$activityScores[$thresholdIndex];
  }

  foreach ($users as $user) {
    $completedStartedTaskIds = [];

    foreach ($taskStatsMap as $taskId => &$taskStat) {
      $taskForCompletion = $taskStat;
      $completion = tcMonitoringUserTaskCompletion($user, $taskForCompletion, $tasksDir, $completionPhaseLookup);
      $completed = !empty($completion['completed']);
      $awardedScore = $completed ? (float)($completion['score'] ?? 0) : null;
      $isStartedTask = isset($startedTaskIdSet[(string)$taskId]);

      if ($completed) {
        $taskStat['completedUsers'] = (int)$taskStat['completedUsers'] + 1;
        if ($isStartedTask) {
          $completedStartedTaskIds[(string)$taskId] = true;
        }
        if ((string)($completion['phase'] ?? '') === 'after_golden') {
          $taskStat['afterGoldenTimeUsers'] = (int)$taskStat['afterGoldenTimeUsers'] + 1;
        } elseif ((string)($completion['phase'] ?? '') === 'active') {
          $taskStat['goldenTimeUsers'] = (int)$taskStat['goldenTimeUsers'] + 1;
        }
      }
      if ($awardedScore !== null) {
        $taskScoreSums[$taskId] += max(0.0, $awardedScore);
        $taskScoreCounts[$taskId] += 1;
      }
    }
    unset($taskStat);
    $completedStartedCount = count($completedStartedTaskIds);
    if ($completedStartedCount > 0) {
      $completionDistributionUserCount += 1;
    }
    $requiredTaskIds = [];
    foreach ($startedTasks as $index => $startedTask) {
      $startedTaskId = (string)($startedTask['id'] ?? '');
      if ($startedTaskId === '') {
        continue;
      }
      $requiredTaskIds[] = $startedTaskId;
      $completedRequired = true;
      foreach ($requiredTaskIds as $requiredTaskId) {
        if (!isset($completedStartedTaskIds[$requiredTaskId])) {
          $completedRequired = false;
          break;
        }
      }
      if (!$completedRequired) {
        continue;
      }
      if (!isset($completionDistribution[$index])) {
        $title = trim((string)($startedTask['title'] ?? ''));
        $completionDistribution[$index] = [
          'id' => $startedTaskId,
          'title' => $title !== '' ? $title : $startedTaskId,
          'completedCount' => count($requiredTaskIds),
          'missingCount' => max(0, $startedTaskCount - count($requiredTaskIds)),
          'missingTaskTitles' => [],
          'users' => 0,
          'percentage' => 0.0
        ];
      }
      $completionDistribution[$index]['users'] = (int)$completionDistribution[$index]['users'] + 1;
    }
    if ($startedTaskCount > 0 && $completedStartedCount >= $startedTaskCount) {
      $usersCompletedAllStarted += 1;
      if (max(0, (int)($user['activityScore'] ?? 0)) >= $highActivityThreshold) {
        $highActiveCompletedAllStarted += 1;
      }
    }
  }

  foreach ($startedTasks as $index => $startedTask) {
    $startedTaskId = (string)($startedTask['id'] ?? '');
    if ($startedTaskId === '' || isset($completionDistribution[$index])) {
      continue;
    }
    $title = trim((string)($startedTask['title'] ?? ''));
    $completedCount = $index + 1;
    $completionDistribution[$index] = [
      'id' => $startedTaskId,
      'title' => $title !== '' ? $title : $startedTaskId,
      'completedCount' => $completedCount,
      'missingCount' => max(0, $startedTaskCount - $completedCount),
      'missingTaskTitles' => [],
      'users' => 0,
      'percentage' => 0.0
    ];
  }

  foreach ($completionDistribution as $index => $row) {
    $completionDistribution[$index]['percentage'] = $totalUsers > 0 ? round(((int)($row['users'] ?? 0) * 100) / $totalUsers, 2) : 0.0;
  }
  ksort($completionDistribution, SORT_NUMERIC);
  $completionDistribution = array_values(array_filter($completionDistribution, static fn(array $row): bool => (int)($row['completedCount'] ?? 0) > 0));

  $taskStats = [];
  $favoriteTask = null;
  foreach ($taskStatsMap as $taskId => $taskStat) {
    $completedUsers = (int)($taskStat['completedUsers'] ?? 0);
    $completionRate = $totalUsers > 0 ? round(($completedUsers * 100) / $totalUsers, 2) : 0.0;
    $awardedTotal = (float)($taskScoreSums[$taskId] ?? 0.0);
    $awardedCount = (int)($taskScoreCounts[$taskId] ?? 0);
    $awardedAvg = $awardedCount > 0 ? round($awardedTotal / $awardedCount, 2) : 0.0;

    $taskStat['completionRate'] = $completionRate;
    $taskStat['awardedScoreTotal'] = round($awardedTotal, 2);
    $taskStat['awardedScoreAvg'] = $awardedAvg;
    $taskStats[] = $taskStat;

    if ($favoriteTask === null || $completedUsers > (int)($favoriteTask['completedUsers'] ?? 0)) {
      $favoriteTask = [
        'id' => (string)($taskStat['id'] ?? ''),
        'title' => (string)($taskStat['title'] ?? ''),
        'completedUsers' => $completedUsers,
        'completionRate' => $completionRate
      ];
    }
  }

  usort($taskStats, static function (array $a, array $b): int {
    $byCompleted = ((int)($b['completedUsers'] ?? 0)) <=> ((int)($a['completedUsers'] ?? 0));
    if ($byCompleted !== 0) {
      return $byCompleted;
    }
    return strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
  });

  $groupMap = [];
  foreach ($allUsers as $user) {
    $group = (string)($user['workIdGroup'] ?? 'نامشخص');
    if (!isset($groupMap[$group])) {
      $groupMap[$group] = [
        'group' => $group,
        'users' => 0,
        'participants' => 0,
        'completionTotal' => 0,
        'scoreTotal' => 0,
        'missionCompletions' => []
      ];
    }
    $groupMap[$group]['users'] = (int)$groupMap[$group]['users'] + 1;
    $userCompletedStarted = 0;
    foreach ($startedTasks as $task) {
      $completion = tcMonitoringUserTaskCompletion($user, $task, $tasksDir);
      if (!empty($completion['completed'])) {
        $userCompletedStarted += 1;
        $taskId = (string)($task['id'] ?? '');
        if ($taskId !== '') {
          if (!isset($groupMap[$group]['missionCompletions'][$taskId])) {
            $groupMap[$group]['missionCompletions'][$taskId] = 0;
          }
          $groupMap[$group]['missionCompletions'][$taskId] += 1;
        }
      }
    }
    if (!empty($user['hasLoggedIn']) || $userCompletedStarted > 0) {
      $groupMap[$group]['participants'] = (int)$groupMap[$group]['participants'] + 1;
    }
    $groupMap[$group]['completionTotal'] = (int)$groupMap[$group]['completionTotal'] + $userCompletedStarted;
    $groupMap[$group]['scoreTotal'] = (float)$groupMap[$group]['scoreTotal'] + max(0, (int)($user['score'] ?? 0));
  }

  $workIdGroupStats = [];
  foreach ($groupMap as $group) {
    $groupUsers = max(0, (int)($group['users'] ?? 0));
    $participants = max(0, (int)($group['participants'] ?? 0));
    $missionRates = [];
    foreach ($startedTasks as $task) {
      $taskId = (string)($task['id'] ?? '');
      if ($taskId === '') {
        continue;
      }
      $completed = max(0, (int)($group['missionCompletions'][$taskId] ?? 0));
      $missionRates[] = [
        'id' => $taskId,
        'title' => (string)($task['title'] ?? ''),
        'completedUsers' => $completed,
        'completionRate' => $groupUsers > 0 ? round(($completed * 100) / $groupUsers, 2) : 0.0
      ];
    }
    $workIdGroupStats[] = [
      'group' => (string)($group['group'] ?? 'نامشخص'),
      'users' => $groupUsers,
      'participants' => $participants,
      'participantRate' => $groupUsers > 0 ? round(($participants * 100) / $groupUsers, 2) : 0.0,
      'avgCompletedStartedMissions' => $groupUsers > 0 ? round(((int)($group['completionTotal'] ?? 0)) / $groupUsers, 2) : 0.0,
      'avgScore' => $groupUsers > 0 ? round(((float)($group['scoreTotal'] ?? 0)) / $groupUsers, 2) : 0.0,
      'missionRates' => $missionRates
    ];
  }
  usort($workIdGroupStats, static fn(array $a, array $b): int => strnatcasecmp((string)($a['group'] ?? ''), (string)($b['group'] ?? '')));

  try {
    $teamRuntimePaths = glob($tasksDir . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'team-runtime.json');
  } catch (Throwable $err) {
    $teamRuntimePaths = [];
    $warnings[] = 'اطلاعات چالش‌های تیمی قابل خواندن نبود.';
  }
  if (!is_array($teamRuntimePaths)) {
    $teamRuntimePaths = [];
  }
  $teamRuntimeAvailable = count($teamRuntimePaths) > 0;
  if (!$teamRuntimeAvailable) {
    $warnings[] = 'برای این رویداد اطلاعات چالش تیمی ثبت نشده یا در دسترس نیست.';
  }
  $startedChallengeTeams = 0;
  $challengeParticipants = [];
  foreach ($teamRuntimePaths as $runtimePath) {
    $runtime = tcMonitoringReadJson((string)$runtimePath, []);
    $teamsRuntime = is_array($runtime['teams'] ?? null) ? $runtime['teams'] : [];
    foreach ($teamsRuntime as $team) {
      if (!is_array($team) || empty($team['started'])) {
        continue;
      }
      $startedChallengeTeams += 1;
      foreach ((array)($team['members'] ?? []) as $memberWorkId) {
        $member = trim((string)$memberWorkId);
        if ($member === '') {
          continue;
        }
        if ($hasExplicitGroupFilter && !isset($selectedWorkIdSet[$member])) {
          continue;
        }
        if (!isset($challengeParticipants[$member])) {
          $challengeParticipants[$member] = 0;
        }
        $challengeParticipants[$member] += 1;
      }
    }
  }
  $avgChallengesPerUser = $totalUsers > 0 ? round(array_sum($challengeParticipants) / $totalUsers, 2) : 0.0;
  $avgChallengesPerParticipant = count($challengeParticipants) > 0 ? round(array_sum($challengeParticipants) / count($challengeParticipants), 2) : 0.0;

  $taskWindows = [];
  foreach ($startedTasks as $task) {
    $taskId = (string)($task['id'] ?? '');
    $startDate = (string)($task['startDate'] ?? '');
    if ($taskId === '' || $startDate === '') {
      continue;
    }
    $taskWindows[$taskId] = [
      'date' => $startDate,
      'startSeconds' => tcMonitoringTaskTimeToSeconds((string)($task['startTime'] ?? '')) ?? 0,
      'endSeconds' => tcMonitoringTaskTimeToSeconds((string)($task['endTime'] ?? '')) ?? 86399
    ];
  }
  try {
    $timeEngagementStats = tcMonitoringBuildTimeEngagementStats($logsDir, $taskWindows, $totalUsers);
  } catch (Throwable $err) {
    $timeEngagementStats = tcMonitoringBuildTimeEngagementStats('', [], $totalUsers);
    $warnings[] = 'گزارش فعالیت قابل خواندن نبود؛ آمار ورود و زمان پاسخ ممکن است ناقص باشد.';
  }
  if (empty($timeEngagementStats['available'])) {
    $warnings[] = 'برای این رویداد گزارش فعالیت کافی وجود ندارد؛ آمار ورود و زمان پاسخ ممکن است برای رویدادهای قدیمی در دسترس نباشد.';
  }

  $levelStats = [];
  $usersEligibleAnyLevel = 0;
  $minLevelScore = null;
  foreach ($levels as $level) {
    $targetScore = max(0, (int)($level['score'] ?? 0));
    if ($targetScore <= 0) {
      continue;
    }
    if ($minLevelScore === null || $targetScore < $minLevelScore) {
      $minLevelScore = $targetScore;
    }
    $eligibleUsers = 0;
    foreach ($users as $user) {
      $score = max(0, (int)($user['score'] ?? 0));
      if ($score >= $targetScore) {
        $eligibleUsers += 1;
      }
    }
    $levelStats[] = [
      'id' => (string)($level['id'] ?? ''),
      'name' => (string)($level['name'] ?? ('سطح ' . $targetScore)),
      'type' => (string)($level['type'] ?? 'value_sum'),
      'score' => $targetScore,
      'eligibleUsers' => $eligibleUsers,
      'eligibleRate' => $totalUsers > 0 ? round(($eligibleUsers * 100) / $totalUsers, 2) : 0.0
    ];
  }

  if ($minLevelScore !== null) {
    foreach ($users as $user) {
      $score = max(0, (int)($user['score'] ?? 0));
      if ($score >= $minLevelScore) {
        $usersEligibleAnyLevel += 1;
      }
    }
  }
  $maximumPrizeCardsOpenable = array_sum(array_map(
    static fn(array $level): int => max(0, (int)($level['eligibleUsers'] ?? 0)),
    $levelStats
  ));

  $prizeCapacity = 0;
  $prizeRemaining = 0;
  $prizeGiven = 0;
  $prizeValueRemaining = 0.0;
  $prizeValueGiven = 0.0;
  $prizeValueBudget = 0.0;
  foreach ($prizes as $prize) {
    $quantity = max(0, (int)($prize['quantity'] ?? 0));
    $last = max(0, min($quantity, (int)($prize['last'] ?? 0)));
    $value = max(0.0, (float)($prize['value'] ?? 0.0));
    $given = max(0, $quantity - $last);
    $prizeCapacity += $quantity;
    $prizeRemaining += $last;
    $prizeGiven += $given;
    if (empty($prize['isFake'])) {
      $prizeValueBudget += ($quantity * $value);
      $prizeValueRemaining += ($last * $value);
      $prizeValueGiven += ($given * $value);
    }
  }
  $prizeValueAssignedToInvitees = array_sum(array_map(
    static fn(array $user): float => max(0.0, (float)($user['totalPrizeWon'] ?? 0)),
    $allUsers
  ));
  $prizeValueReconciliationDifference = $prizeValueAssignedToInvitees + $prizeValueRemaining - $prizeValueBudget;

  $mostActiveUsers = $users;
  usort($mostActiveUsers, static function (array $a, array $b): int {
    $byActivity = ((int)($b['activityScore'] ?? 0)) <=> ((int)($a['activityScore'] ?? 0));
    if ($byActivity !== 0) {
      return $byActivity;
    }
    $byCompleted = ((int)($b['completedTaskCount'] ?? 0)) <=> ((int)($a['completedTaskCount'] ?? 0));
    if ($byCompleted !== 0) {
      return $byCompleted;
    }
    $byLogins = ((int)($b['loginCount'] ?? 0)) <=> ((int)($a['loginCount'] ?? 0));
    if ($byLogins !== 0) {
      return $byLogins;
    }
    return ((int)($b['score'] ?? 0)) <=> ((int)($a['score'] ?? 0));
  });

  $mostActiveUsers = array_slice(array_map(static function (array $user, int $index): array {
    return [
      'rank' => $index + 1,
      'name' => (string)($user['name'] ?? ''),
      'workId' => (string)($user['workId'] ?? ''),
      'score' => max(0, (int)($user['score'] ?? 0)),
      'loginCount' => max(0, (int)($user['loginCount'] ?? 0)),
      'completedTaskCount' => max(0, (int)($user['completedTaskCount'] ?? 0)),
      'activityScore' => max(0, (int)($user['activityScore'] ?? 0))
    ];
  }, $mostActiveUsers, array_keys($mostActiveUsers)), 0, 10);

  return [
    'summary' => [
      'totalUsers' => $totalUsers,
      'loggedInUsers' => $loggedInUsers,
      'neverLoggedInUsers' => max(0, $totalUsers - $loggedInUsers),
      'usersWithCompletion' => $usersWithCompletion,
      'usersEligibleAnyLevel' => $usersEligibleAnyLevel,
      'maximumPrizeCardsOpenable' => $maximumPrizeCardsOpenable,
      'usersCompletedAllStarted' => $usersCompletedAllStarted,
      'highActiveCompletedAllStarted' => $highActiveCompletedAllStarted,
      'highActivityThreshold' => $highActivityThreshold,
      'avgScore' => $averageScore,
      'topScore' => $topScore,
      'minPossibleScore' => $minPossibleScore,
      'maxPossibleScore' => $maxPossibleScore,
      'usersWithMaxPossibleScore' => $usersWithMaxPossibleScore,
      'scoreStageUserCount' => $scoreStageUserCount,
      'completionDistributionUserCount' => $completionDistributionUserCount,
      'taskCount' => count($tasks),
      'startedTaskCount' => $startedTaskCount,
      'levelCount' => count($levelStats),
      'prizeCapacity' => $prizeCapacity,
      'prizeRemaining' => $prizeRemaining,
      'prizeGiven' => $prizeGiven,
      'prizeValueRemaining' => round($prizeValueRemaining, 2),
      'prizeValueGiven' => round($prizeValueGiven, 2),
      'prizeValueAssignedToInvitees' => round($prizeValueAssignedToInvitees, 2),
      'prizeValueBudget' => round($prizeValueBudget, 2),
      'prizeValueReconciliationDifference' => round($prizeValueReconciliationDifference, 2),
      'prizeValueHasProblem' => abs($prizeValueReconciliationDifference) > 0.005,
      'favoriteTask' => $favoriteTask
    ],
    'scoreStages' => $scoreStages,
    'completionDistribution' => $completionDistribution,
    'challengeStats' => [
      'available' => $teamRuntimeAvailable,
      'note' => $teamRuntimeAvailable
        ? ''
        : 'اطلاعات چالش تیمی برای این رویداد ثبت نشده یا قابل محاسبه نیست.',
      'startedChallengeTeams' => $startedChallengeTeams,
      'challengeParticipants' => count($challengeParticipants),
      'avgChallengesPerUser' => $avgChallengesPerUser,
      'avgChallengesPerParticipant' => $avgChallengesPerParticipant
    ],
    'timeEngagementStats' => $timeEngagementStats,
    'eventInfo' => $eventInfo,
    'participationStats' => [
      'totalUsers' => $totalUsers,
      'loggedInUsers' => $loggedInUsers,
      'usersWithTaskParticipation' => $usersWithTaskParticipation,
      'usersWithCompletion' => $usersWithCompletion,
      'usersCompletedAllStarted' => $usersCompletedAllStarted,
      'usersWithMaxPossibleScore' => $usersWithMaxPossibleScore,
      'usersEligibleAnyLevel' => $usersEligibleAnyLevel,
      'loggedInWithoutCompletion' => $loggedInWithoutCompletion,
      'neverLoggedInUsers' => max(0, $totalUsers - $loggedInUsers)
    ],
    'workIdGroupStats' => $workIdGroupStats,
    'workIdGroups' => $allWorkIdGroups,
    'selectedWorkIdGroups' => $selectedWorkIdGroups,
    'taskStats' => $taskStats,
    'levelStats' => $levelStats,
    'mostActiveUsers' => $mostActiveUsers,
    'prizeStats' => [
      'rows' => $prizes
    ],
    'dataSources' => [
      'inviteesCsvFound' => is_file($inviteesPath),
      'inviteesRows' => $rowsCount,
      'tasksStoreFound' => is_file($tasksPath),
      'prizesFileFound' => is_file($prizesPath),
      'levelsFileFound' => is_file($levelsPath)
    ],
    'warnings' => array_values(array_unique(array_filter($warnings))),
    'generatedAt' => gmdate('c')
  ];
}

function tcMonitoringAttachInviteeGroupsToStats(array $data, string $baseDir): array
{
  if (!empty($data['workIdGroups']) && is_array($data['workIdGroups'])) {
    return $data;
  }
  $inviteesPath = $baseDir . DIRECTORY_SEPARATOR . 'TC Event' . DIRECTORY_SEPARATOR . 'Invitees mapped.csv';
  $inviteesMapPath = $baseDir . DIRECTORY_SEPARATOR . 'TC Event' . DIRECTORY_SEPARATOR . 'TC Mapped.json';
  try {
    $inviteesPayload = tcMonitoringBuildInviteesData($inviteesPath, $inviteesMapPath);
  } catch (Throwable $err) {
    return $data;
  }
  $users = is_array($inviteesPayload['users'] ?? null) ? $inviteesPayload['users'] : [];
  $groups = array_values(array_unique(array_map(static fn(array $user): string => (string)($user['workIdGroup'] ?? 'نامشخص'), $users)));
  usort($groups, static fn(string $a, string $b): int => strnatcasecmp($a, $b));
  if ($groups) {
    $data['workIdGroups'] = $groups;
    $data['selectedWorkIdGroups'] = tcMonitoringRequestedWorkIdGroups($groups);
  }
  return $data;
}

if ($tcMonitoringAction === 'export') {
  try {
    require_once __DIR__ . '/monitoring_excel_export.php';
    try {
      $exportData = tcMonitoringBuildStats(__DIR__);
    } catch (Throwable $statsErr) {
      error_log('Task Club monitoring export full stats failed; using fallback: ' . $statsErr->getMessage());
      $exportData = tcMonitoringBuildFallbackStats(__DIR__, [
        'بخشی از محاسبات کامل مانیتورینگ در دسترس نبود؛ خروجی از داده‌های پایه ساخته شد.'
      ]);
      $exportData = tcMonitoringAttachInviteeGroupsToStats($exportData, __DIR__);
    }
    tcMonitoringSendExcelExport($exportData, __DIR__);
  } catch (Throwable $err) {
    error_log('Task Club monitoring export failed: ' . $err->getMessage());
    if (!headers_sent()) {
      http_response_code(500);
      header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'ساخت فایل اکسل مانیتورینگ ناموفق بود.';
  }
  exit;
}

if ($tcMonitoringIsJsonRequest) {
  $preStatsOutput = '';
  if (ob_get_level() > 0) {
    $preStatsOutput = trim((string)ob_get_clean());
  }
  header('Content-Type: application/json; charset=utf-8');
  ob_start();
  try {
    $data = tcMonitoringBuildStats(__DIR__);
    $statsOutput = trim((string)ob_get_clean());
    $unexpectedOutput = trim($preStatsOutput . "\n" . $statsOutput);
    if ($unexpectedOutput !== '') {
      if (!isset($data['warnings']) || !is_array($data['warnings'])) {
        $data['warnings'] = [];
      }
      $data['warnings'][] = 'در زمان محاسبه مانیتورینگ یک پیام غیرمنتظره تولید شد؛ اطلاعات موجود نمایش داده می‌شود.';
    }
    $response = tcMonitoringJsonResponse(['status' => 'ok', 'data' => $data]);
    $tcMonitoringJsonCompleted = true;
    echo $response;
  } catch (Throwable $err) {
    if (ob_get_level() > 0) {
      ob_end_clean();
    }
    error_log('Task Club monitoring stats failed: ' . $err->getMessage());
    try {
      $fallbackData = tcMonitoringBuildFallbackStats(__DIR__, [
        'بخشی از داده‌های مانیتورینگ قابل محاسبه نبود؛ داده‌های پایه نمایش داده می‌شود.'
      ]);
    } catch (Throwable $fallbackErr) {
      error_log('Task Club monitoring fallback stats failed: ' . $fallbackErr->getMessage());
      $fallbackData = tcMonitoringEmptyStats([
        'بخشی از داده‌های مانیتورینگ قابل محاسبه نبود؛ داده‌های در دسترس نمایش داده می‌شود.'
      ]);
    }
    if ($preStatsOutput !== '') {
      if (!isset($fallbackData['warnings']) || !is_array($fallbackData['warnings'])) {
        $fallbackData['warnings'] = [];
      }
      $fallbackData['warnings'][] = 'در زمان آماده‌سازی مانیتورینگ یک پیام غیرمنتظره تولید شد؛ داده‌های پایه نمایش داده می‌شود.';
    }
    $fallbackData = tcMonitoringAttachInviteeGroupsToStats($fallbackData, __DIR__);
    $fallbackData['diagnostics'] = [
      'type' => 'stats_exception',
      'message' => $err->getMessage(),
      'file' => $err->getFile(),
      'line' => $err->getLine()
    ];
    if (isset($fallbackErr) && $fallbackErr instanceof Throwable) {
      $fallbackData['diagnostics']['fallbackMessage'] = $fallbackErr->getMessage();
      $fallbackData['diagnostics']['fallbackFile'] = $fallbackErr->getFile();
      $fallbackData['diagnostics']['fallbackLine'] = $fallbackErr->getLine();
    }
    $response = tcMonitoringJsonResponse([
      'status' => 'partial',
      'message' => 'بخشی از داده‌های مانیتورینگ قابل محاسبه نبود.',
      'data' => $fallbackData
    ]);
    $tcMonitoringJsonCompleted = true;
    echo $response;
  }
  exit;
}
?>

<div class="card">
  <div class="section-header">
    <h3>مانیتورینگ</h3>
    <div class="tc-monitoring-header-actions">
      <button type="button" class="btn ghost" id="tc-monitoring-export">خروجی اکسل</button>
      <button type="button" class="btn ghost" id="tc-monitoring-refresh">بروزرسانی</button>
    </div>
  </div>
  <p class="muted small">نمای کلی کاربران، ماموریت‌ها، سطوح جایزه و موجودی جوایز.</p>
  <p id="tc-monitoring-status" class="muted small" aria-live="polite"></p>
  <div id="tc-monitoring-loading" class="tc-monitoring-loading hidden" role="status" aria-live="polite">
    <div class="tc-monitoring-loading-head">
      <span id="tc-monitoring-loading-text">در حال محاسبه داده‌ها</span>
      <span id="tc-monitoring-loading-percent">۰٪</span>
    </div>
    <div class="tc-monitoring-loading-track" aria-hidden="true">
      <span id="tc-monitoring-loading-fill" class="tc-monitoring-loading-fill" style="width:0%"></span>
    </div>
  </div>
  <pre id="tc-monitoring-diagnostics" class="muted small" style="display:none; white-space:pre-wrap; direction:ltr; text-align:left;"></pre>
  <p id="tc-monitoring-updated" class="muted small"></p>
</div>

<div id="tc-monitoring-export-modal" class="tc-monitoring-export-modal" hidden>
  <section class="tc-monitoring-export-dialog" role="dialog" aria-modal="true" aria-labelledby="tc-monitoring-export-title">
    <div class="section-header">
      <h3 id="tc-monitoring-export-title">خروجی اکسل مانیتورینگ</h3>
      <button type="button" class="icon-btn" id="tc-monitoring-export-close" aria-label="بستن">×</button>
    </div>
    <p class="muted small">گروه‌های شماره پرسنلی موردنظر برای گزارش را انتخاب کنید.</p>
    <div class="tc-monitoring-export-selection-actions">
      <button type="button" class="btn ghost" id="tc-monitoring-export-select-all">انتخاب همه</button>
      <button type="button" class="btn ghost" id="tc-monitoring-export-select-none">لغو همه</button>
    </div>
    <div id="tc-monitoring-export-groups" class="tc-monitoring-group-filter"></div>
    <div id="tc-monitoring-export-progress" class="tc-monitoring-export-progress" hidden aria-live="polite">
      <div class="tc-monitoring-loading-head">
        <span id="tc-monitoring-export-progress-text">در حال ساخت فایل اکسل...</span>
        <span id="tc-monitoring-export-progress-percent">۰٪</span>
      </div>
      <div class="tc-monitoring-loading-track" aria-hidden="true">
        <span id="tc-monitoring-export-progress-fill" class="tc-monitoring-loading-fill" style="width:0%"></span>
      </div>
    </div>
    <p id="tc-monitoring-export-status" class="muted small" aria-live="polite"></p>
    <div class="modal-actions">
      <button type="button" class="btn ghost" id="tc-monitoring-export-cancel">انصراف</button>
      <button type="button" class="btn primary standard-primary-button" id="tc-monitoring-export-submit">ساخت و دانلود فایل</button>
    </div>
  </section>
</div>

<div class="card">
  <div class="section-header">
    <h3>اطلاعات رویداد</h3>
  </div>
  <div id="tc-monitoring-event-info" class="tc-monitoring-kpi-grid"></div>
</div>

<div class="card">
  <div class="section-header">
    <h3>فیلتر گروه شماره پرسنلی</h3>
  </div>
  <div id="tc-monitoring-workid-filter" class="tc-monitoring-group-filter"></div>
  <div class="tc-monitoring-filter-actions">
    <button type="button" class="btn primary standard-primary-button" id="tc-monitoring-workid-apply">اعمال فیلتر</button>
    <span id="tc-monitoring-workid-filter-status" class="muted small"></span>
  </div>
</div>

<div class="card">
  <div class="section-header">
    <h3>مشارکت بر اساس گروه شماره پرسنلی</h3>
  </div>
  <div class="table-wrapper">
    <table class="tc-monitoring-table">
      <thead>
        <tr>
          <th>گروه</th>
          <th>کاربران</th>
          <th>مشارکت‌کننده</th>
          <th>نرخ مشارکت</th>
          <th>میانگین ماموریت شروع‌شده</th>
          <th>میانگین امتیاز</th>
        </tr>
      </thead>
      <tbody id="tc-monitoring-workid-groups"></tbody>
      <tfoot id="tc-monitoring-workid-groups-footer"></tfoot>
    </table>
  </div>
</div>

<div id="tc-monitoring-kpis" class="tc-monitoring-kpi-grid"></div>

<div class="card">
  <div class="section-header">
    <h3>تکمیل ماموریت‌ها</h3>
  </div>
  <div id="tc-monitoring-task-chart" class="tc-monitoring-bars"></div>
</div>

<div class="card tc-monitoring-score-card">
  <div class="section-header">
    <h3>پراکندگی مرحله‌های امتیاز</h3>
  </div>
  <div id="tc-monitoring-score-stages" class="tc-monitoring-bars"></div>
</div>

<div class="card">
  <div class="section-header">
    <h3>وضعیت رسیدن به سطوح جایزه</h3>
  </div>
  <p id="tc-monitoring-level-subtitle" class="muted small">حداکثر کارت جایزه قابل باز شدن: ۰</p>
  <div id="tc-monitoring-level-chart" class="tc-monitoring-bars"></div>
</div>

<div class="card">
  <div class="section-header">
    <h3>شاخص‌های میزان مشارکت</h3>
  </div>
  <p class="muted small">نتایج بر اساس گروه‌های انتخاب‌شده در فیلتر شماره پرسنلی محاسبه می‌شوند. برای مشاهده جزئیات روی هر ستون بروید.</p>
  <div id="tc-monitoring-participation-chart" class="tc-monitoring-vertical-chart"></div>
</div>

<div class="card">
  <div class="section-header">
    <h3>میانگین چالش‌های انجام‌شده</h3>
  </div>
  <div id="tc-monitoring-challenge-stats" class="tc-monitoring-kpi-grid"></div>
</div>

<div class="card">
  <div class="section-header">
    <h3>فعال‌ترین کاربران</h3>
  </div>
  <div class="table-wrapper">
    <table class="tc-monitoring-table">
      <thead>
        <tr>
          <th>ردیف</th>
          <th>نام</th>
          <th>شماره پرسنلی</th>
          <th>امتیاز</th>
          <th>ورودها</th>
          <th>ماموریت تکمیل‌شده</th>
          <th>شاخص فعالیت</th>
        </tr>
      </thead>
      <tbody id="tc-monitoring-active-users"></tbody>
    </table>
  </div>
</div>
