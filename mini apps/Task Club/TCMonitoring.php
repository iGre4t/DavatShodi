<?php
declare(strict_types=1);

require_once __DIR__ . '/../../api/lib/tab-permissions.php';

$tcMonitoringAction = strtolower(trim((string)($_GET['action'] ?? '')));
$tcMonitoringIsJsonRequest = $tcMonitoringAction === 'stats';
requireTabPermissionFromSession('task-club', $tcMonitoringIsJsonRequest);

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

function tcMonitoringReadCsvRows(string $path): array
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
    $rows[] = is_array($row) ? $row : [];
  }
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
    if ($separatorPos === false) {
      continue;
    }
    $taskId = trim(substr($item, 0, $separatorPos));
    if ($taskId === '') {
      continue;
    }
    $scoreRaw = trim(substr($item, $separatorPos + 2));
    $map[$taskId] = tcMonitoringParseNumber($scoreRaw);
  }
  return $map;
}

function tcMonitoringNormalizeTaskType(string $value): string
{
  $token = strtolower(trim($value));
  if (in_array($token, ['quiz', 'quiz-task', 'quiz task'], true)) {
    return 'quiz';
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

function tcMonitoringReadTaskScoreSettings(string $tasksDir, string $tagCode): array
{
  $defaults = ['score' => 0, 'afterEndtimeScore' => 0];
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
    'afterEndtimeScore' => max(0, tcMonitoringParseInt($decoded['afterEndtimeScore'] ?? ($decoded['after_endtime_score'] ?? 0)))
  ];
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
    $tasks[] = [
      'id' => $id,
      'title' => $title,
      'tagCode' => $tagCode,
      'taskType' => $taskType,
      'score' => max(0, tcMonitoringParseInt($item['score'] ?? $scoreSettings['score'] ?? 0)),
      'afterEndtimeScore' => max(0, tcMonitoringParseInt($item['afterEndtimeScore'] ?? ($item['after_endtime_score'] ?? ($scoreSettings['afterEndtimeScore'] ?? 0)))),
      'order' => max(1, tcMonitoringParseInt($item['order'] ?? 0))
    ];
  }

  usort($tasks, static function (array $a, array $b): int {
    return (int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0);
  });
  return $tasks;
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
    $name = trim((string)($item['name'] ?? ($item['levelName'] ?? ('Level ' . $score))));
    if ($name === '') {
      $name = 'Level ' . $score;
    }
    $typeToken = strtolower(trim((string)($item['type'] ?? ($item['levelType'] ?? 'value_sum'))));
    $type = $typeToken === 'out_of_value' ? 'out_of_value' : 'value_sum';
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
  $decoded = tcMonitoringReadJson($path, []);
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
  $describeTasksIndex = tcMonitoringFindHeaderIndex($header, ['describe photo task']);
  $cardFlipsIndex = tcMonitoringFindHeaderIndex($header, ['card flips count']);
  $prizeWonIndex = tcMonitoringFindHeaderIndex($header, ['prize won']);
  $outOfValueRewardsIndex = tcMonitoringFindHeaderIndex($header, ['out of value rewards']);

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
    $describeTasksMap = tcMonitoringParseTaskScoreMap(tcMonitoringCell($row, $describeTasksIndex));

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
    foreach (array_keys($describeTasksMap) as $taskId) {
      $allCompletedLookup[(string)$taskId] = true;
    }

    $cardFlips = max(0, tcMonitoringParseInt(tcMonitoringCell($row, $cardFlipsIndex)));
    $prizeWonList = tcMonitoringSplitTokens(tcMonitoringCell($row, $prizeWonIndex));
    $outOfValueRewards = tcMonitoringSplitTokens(tcMonitoringCell($row, $outOfValueRewardsIndex));
    $completedTaskCount = count($allCompletedLookup);
    $activityScore = ($completedTaskCount * 3) + $loginCount + $cardFlips;

    $users[] = [
      'workId' => $workId,
      'name' => $displayName,
      'firstName' => $firstName,
      'lastName' => $lastName,
      'score' => $score,
      'loginCount' => $loginCount,
      'hasLoggedIn' => ($loginCount > 0) || (count($loginStamps) > 0),
      'completedTaskIds' => $completedTaskIds,
      'taskScoreMap' => $taskScoreMap,
      'infoTasksMap' => $infoTasksMap,
      'describeTasksMap' => $describeTasksMap,
      'completedTaskCount' => $completedTaskCount,
      'cardFlips' => $cardFlips,
      'prizeWonCount' => count($prizeWonList),
      'outOfValueRewardCount' => count($outOfValueRewards),
      'activityScore' => $activityScore
    ];
  }

  return ['users' => $users, 'rowsCount' => max(0, count($rows) - 1)];
}

function tcMonitoringBuildStats(string $baseDir): array
{
  $tasksPath = $baseDir . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . 'tasks.js';
  $tasksDir = $baseDir . DIRECTORY_SEPARATOR . 'tasks';
  $inviteesPath = $baseDir . DIRECTORY_SEPARATOR . 'TC Event' . DIRECTORY_SEPARATOR . 'Invitees mapped.csv';
  $inviteesMapPath = $baseDir . DIRECTORY_SEPARATOR . 'TC Event' . DIRECTORY_SEPARATOR . 'TC Mapped.json';
  $prizesPath = $baseDir . DIRECTORY_SEPARATOR . 'TC Prizes.json';
  $levelsPath = $baseDir . DIRECTORY_SEPARATOR . 'TC Prize Levels.json';

  $tasks = tcMonitoringReadTasks($tasksPath, $tasksDir);
  $inviteesPayload = tcMonitoringBuildInviteesData($inviteesPath, $inviteesMapPath);
  $users = is_array($inviteesPayload['users'] ?? null) ? $inviteesPayload['users'] : [];
  $rowsCount = max(0, (int)($inviteesPayload['rowsCount'] ?? 0));
  $prizes = tcMonitoringReadPrizes($prizesPath);
  $levels = tcMonitoringReadPrizeLevels($levelsPath);

  $totalUsers = count($users);
  $loggedInUsers = 0;
  $usersWithCompletion = 0;
  $sumScores = 0;
  $topScore = 0;
  $sumCardFlips = 0;
  foreach ($users as $user) {
    if (($user['hasLoggedIn'] ?? false) === true) {
      $loggedInUsers += 1;
    }
    $completedTaskCount = (int)($user['completedTaskCount'] ?? 0);
    if ($completedTaskCount > 0) {
      $usersWithCompletion += 1;
    }
    $score = max(0, (int)($user['score'] ?? 0));
    $sumScores += $score;
    if ($score > $topScore) {
      $topScore = $score;
    }
    $sumCardFlips += max(0, (int)($user['cardFlips'] ?? 0));
  }
  $averageScore = $totalUsers > 0 ? round($sumScores / $totalUsers, 2) : 0.0;

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
      'completedUsers' => 0,
      'completionRate' => 0.0,
      'awardedScoreAvg' => 0.0,
      'awardedScoreTotal' => 0.0
    ];
    $taskScoreSums[$taskId] = 0.0;
    $taskScoreCounts[$taskId] = 0;
  }

  foreach ($users as $user) {
    $completedLookup = [];
    foreach ((array)($user['completedTaskIds'] ?? []) as $completedTaskId) {
      $completedLookup[(string)$completedTaskId] = true;
    }
    $taskScoreMap = is_array($user['taskScoreMap'] ?? null) ? $user['taskScoreMap'] : [];
    $infoTasksMap = is_array($user['infoTasksMap'] ?? null) ? $user['infoTasksMap'] : [];
    $describeTasksMap = is_array($user['describeTasksMap'] ?? null) ? $user['describeTasksMap'] : [];

    foreach ($taskStatsMap as $taskId => &$taskStat) {
      $taskType = (string)($taskStat['taskType'] ?? 'quiz');
      $completed = false;
      $awardedScore = null;
      if ($taskType === 'info' || $taskType === 'team_task') {
        if (array_key_exists($taskId, $infoTasksMap)) {
          $completed = true;
          $awardedScore = (float)$infoTasksMap[$taskId];
        }
      } elseif ($taskType === 'describe_photo') {
        if (array_key_exists($taskId, $describeTasksMap)) {
          $completed = true;
          $awardedScore = (float)$describeTasksMap[$taskId];
        }
      } else {
        if (isset($completedLookup[$taskId]) || array_key_exists($taskId, $taskScoreMap)) {
          $completed = true;
        }
        if (array_key_exists($taskId, $taskScoreMap)) {
          $awardedScore = (float)$taskScoreMap[$taskId];
        }
      }

      if ($completed) {
        $taskStat['completedUsers'] = (int)$taskStat['completedUsers'] + 1;
      }
      if ($awardedScore !== null) {
        $taskScoreSums[$taskId] += max(0.0, $awardedScore);
        $taskScoreCounts[$taskId] += 1;
      }
    }
    unset($taskStat);
  }

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
      'name' => (string)($level['name'] ?? ('Level ' . $targetScore)),
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

  $prizeCapacity = 0;
  $prizeRemaining = 0;
  $prizeGiven = 0;
  $prizeValueRemaining = 0.0;
  $prizeValueGiven = 0.0;
  foreach ($prizes as $prize) {
    $quantity = max(0, (int)($prize['quantity'] ?? 0));
    $last = max(0, min($quantity, (int)($prize['last'] ?? 0)));
    $value = max(0.0, (float)($prize['value'] ?? 0.0));
    $given = max(0, $quantity - $last);
    $prizeCapacity += $quantity;
    $prizeRemaining += $last;
    $prizeGiven += $given;
    $prizeValueRemaining += ($last * $value);
    $prizeValueGiven += ($given * $value);
  }

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
      'cardFlips' => max(0, (int)($user['cardFlips'] ?? 0)),
      'prizeWonCount' => max(0, (int)($user['prizeWonCount'] ?? 0)),
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
      'avgScore' => $averageScore,
      'topScore' => $topScore,
      'totalCardFlips' => $sumCardFlips,
      'taskCount' => count($tasks),
      'levelCount' => count($levelStats),
      'prizeTypes' => count($prizes),
      'prizeCapacity' => $prizeCapacity,
      'prizeRemaining' => $prizeRemaining,
      'prizeGiven' => $prizeGiven,
      'prizeValueRemaining' => round($prizeValueRemaining, 2),
      'prizeValueGiven' => round($prizeValueGiven, 2),
      'favoriteTask' => $favoriteTask
    ],
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
    'generatedAt' => gmdate('c')
  ];
}

if ($tcMonitoringIsJsonRequest) {
  header('Content-Type: application/json; charset=utf-8');
  $data = tcMonitoringBuildStats(__DIR__);
  echo json_encode(['status' => 'ok', 'data' => $data], JSON_UNESCAPED_UNICODE);
  exit;
}
?>

<div class="card">
  <div class="section-header">
    <h3>Monitoring</h3>
    <button type="button" class="btn ghost" id="tc-monitoring-refresh">Refresh</button>
  </div>
  <p class="muted small">Overview of users, tasks, prize eligibility, and reward inventory.</p>
  <p id="tc-monitoring-status" class="muted small" aria-live="polite"></p>
  <p id="tc-monitoring-updated" class="muted small"></p>
</div>

<div id="tc-monitoring-kpis" class="tc-monitoring-kpi-grid"></div>

<div class="card">
  <div class="section-header">
    <h3>Task Completion</h3>
  </div>
  <div id="tc-monitoring-task-chart" class="tc-monitoring-bars"></div>
</div>

<div class="card">
  <div class="section-header">
    <h3>Prize Level Reach</h3>
  </div>
  <div id="tc-monitoring-level-chart" class="tc-monitoring-bars"></div>
</div>

<div class="card">
  <div class="section-header">
    <h3>Most Active Users</h3>
  </div>
  <div class="table-wrapper">
    <table class="tc-monitoring-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Name</th>
          <th>Work ID</th>
          <th>Score</th>
          <th>Logins</th>
          <th>Completed Tasks</th>
          <th>Card Flips</th>
          <th>Won Prizes</th>
        </tr>
      </thead>
      <tbody id="tc-monitoring-active-users"></tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="section-header">
    <h3>Task Stats</h3>
  </div>
  <div class="table-wrapper">
    <table class="tc-monitoring-table">
      <thead>
        <tr>
          <th>Task</th>
          <th>Type</th>
          <th>Completed</th>
          <th>Completion Rate</th>
          <th>Avg Awarded Score</th>
          <th>Total Awarded Score</th>
        </tr>
      </thead>
      <tbody id="tc-monitoring-task-stats"></tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="section-header">
    <h3>Prize Inventory</h3>
  </div>
  <div class="table-wrapper">
    <table class="tc-monitoring-table">
      <thead>
        <tr>
          <th>Prize</th>
          <th>On Wheel Name</th>
          <th>Capacity</th>
          <th>Remaining</th>
          <th>Given</th>
          <th>Value (Each)</th>
        </tr>
      </thead>
      <tbody id="tc-monitoring-prize-inventory"></tbody>
    </table>
  </div>
</div>
