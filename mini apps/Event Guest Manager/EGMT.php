<?php
declare(strict_types=1);

$tctEarlyJsonRequest = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') && isset($_POST['tct_action']);
if ($tctEarlyJsonRequest) {
  ob_start();
}

require_once __DIR__ . '/egm-database-runtime.php';
require_once __DIR__ . '/../../api/lib/tab-permissions.php';
require_once __DIR__ . '/../../api/lib/common.php';
require_once __DIR__ . '/../../api/lib/egm-instance-storage.php';
require_once __DIR__ . '/../../api/lib/egm-period-end.php';
require_once __DIR__ . '/egm-security.php';
require_once __DIR__ . '/invitees_csv_safety.php';
$tctIsJsonRequest = $tctEarlyJsonRequest;
$tctSessionUser = requireTabPermissionFromSession('event-guest-manager', $tctIsJsonRequest);
$tctSessionUserCode = strtolower(trim((string)($tctSessionUser['code'] ?? '')));
$tctCanAccessManageTasks = userHasPermissionId($tctSessionUser, 'event-guest-manager:manage-tasks');
$tctManageTasksOverride = null;
$tctHasTaskSubtabAccess = false;
if ($tctSessionUserCode !== '') {
  $tctTaskAccessPath = __DIR__ . '/tasks/task-access.json';
  if (egmDbIsFile($tctTaskAccessPath)) {
    $tctTaskAccessRaw = egmDbFileGetContents($tctTaskAccessPath);
    $tctTaskAccessDecoded = is_string($tctTaskAccessRaw) ? json_decode($tctTaskAccessRaw, true) : null;
    $tctTaskAccessUsers = is_array($tctTaskAccessDecoded['users'] ?? null) ? $tctTaskAccessDecoded['users'] : [];
    foreach ($tctTaskAccessUsers as $rawCode => $entry) {
      if (!is_array($entry)) {
        continue;
      }
      if (strtolower(trim((string)$rawCode)) !== $tctSessionUserCode) {
        continue;
      }
      $rawAllowManageTasks = $entry['allowManageTasksTab'] ?? ($entry['allow_manage_tasks_tab'] ?? false);
      if (is_bool($rawAllowManageTasks)) {
        $tctManageTasksOverride = $rawAllowManageTasks;
      } elseif (is_numeric($rawAllowManageTasks)) {
        $tctManageTasksOverride = ((int)$rawAllowManageTasks) === 1;
      } else {
        $tctManageTasksOverride = in_array(strtolower(trim((string)$rawAllowManageTasks)), ['1', 'true', 'on', 'yes'], true);
      }
      $rawTaskRules = is_array($entry['tasks'] ?? null) ? $entry['tasks'] : [];
      foreach ($rawTaskRules as $rawTaskRule) {
        if (!is_array($rawTaskRule)) {
          continue;
        }
        $rawRuleEnabled = $rawTaskRule['enabled'] ?? true;
        $ruleEnabled = is_bool($rawRuleEnabled)
          ? $rawRuleEnabled
          : (is_numeric($rawRuleEnabled)
            ? (((int)$rawRuleEnabled) === 1)
            : in_array(strtolower(trim((string)$rawRuleEnabled)), ['1', 'true', 'on', 'yes'], true));
        if (!$ruleEnabled) {
          continue;
        }
        $rawPaneRules = is_array($rawTaskRule['panes'] ?? null) ? $rawTaskRule['panes'] : [];
        if (count($rawPaneRules) === 0) {
          $tctHasTaskSubtabAccess = true;
          break;
        }
        foreach ($rawPaneRules as $rawPaneAllowed) {
          $paneAllowed = is_bool($rawPaneAllowed)
            ? $rawPaneAllowed
            : (is_numeric($rawPaneAllowed)
              ? (((int)$rawPaneAllowed) === 1)
              : in_array(strtolower(trim((string)$rawPaneAllowed)), ['1', 'true', 'on', 'yes'], true));
          if ($paneAllowed) {
            $tctHasTaskSubtabAccess = true;
            break 2;
          }
        }
      }
      break;
    }
  }
}
if ($tctManageTasksOverride !== null) {
  $tctCanAccessManageTasks = $tctManageTasksOverride;
}
if (!$tctCanAccessManageTasks && !$tctHasTaskSubtabAccess) {
  denyPanelAccess(403, 'You do not have permission to access this Event Guest Manager section.', $tctIsJsonRequest);
}
$tctCsrfToken = egmSecurityGetCsrfToken();

$tctTasksDir = __DIR__ . '/tasks';
$tctStorePath = $tctTasksDir . '/tasks.js';
$tctEventInviteesPath = __DIR__ . '/EGM Event/Invitees mapped.csv';
$tctEventInviteesMapPath = __DIR__ . '/EGM Event/EGM Mapped.json';
const EGMT_SCORE_SETTINGS_FILE = 'task-score.json';
const EGMT_INFO_SETTINGS_FILE = 'info-task.json';
const EGMT_INFO_SCORES_FILE = 'info-task-scores.json';
const EGMT_TEAM_SETTINGS_FILE = 'team-settings.json';
const EGMT_DESCRIBE_PHOTO_DIR = 'photos';
const EGMT_DESCRIBE_PHOTO_META_FILE = 'photos.json';
const EGMT_DESCRIBE_PHOTO_ARTICLES_DIR = 'articles';
const EGMT_TEAM_CHALLENGES_FILE = 'team-challenges.json';
const EGMT_TEAM_RUNTIME_FILE = 'team-runtime.json';
const EGMT_TASK_ACCESS_FILE = 'task-access.json';

function tctEncodeResponseJson(array $payload): string
{
  try {
    return json_encode(
      $payload,
      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
    );
  } catch (JsonException $error) {
    error_log('Failed to encode EGM control response: ' . $error->getMessage());
    return '{"status":"error","message":"The server could not encode the response. Please reload the panel to verify the saved state."}';
  }
}

function tctNormalizeTaskType(string $value): string
{
  return 'period';
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
  if (!egmDbIsFile($storePath)) {
    return egmDbFilePutContents($storePath, "window.EGM_TASKS = [];\n", LOCK_EX) !== false;
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
  return $taskDir . DIRECTORY_SEPARATOR . EGMT_SCORE_SETTINGS_FILE;
}

function tctLoadTaskScoreSettings(string $tasksDir, string $tagCode): array
{
  $defaults = [
    'score' => 0,
    'afterEndtimeScore' => 0,
    'hasGoldenTime' => true,
    'anotherChanceIfZero' => false
  ];
  $path = tctBuildTaskScoreSettingsPath($tasksDir, $tagCode);
  if ($path === '' || !egmDbIsFile($path)) {
    return $defaults;
  }
  $content = egmDbFileGetContents($path);
  if ($content === false) {
    return $defaults;
  }
  $decoded = json_decode($content, true);
  if (!is_array($decoded)) {
    return $defaults;
  }
  return [
    'score' => tctNormalizeScoreValue($decoded['score'] ?? 0),
    'afterEndtimeScore' => tctNormalizeScoreValue($decoded['afterEndtimeScore'] ?? ($decoded['after_endtime_score'] ?? 0)),
    'hasGoldenTime' => array_key_exists('hasGoldenTime', $decoded) || array_key_exists('has_golden_time', $decoded)
      ? tctNormalizeBoolValue($decoded['hasGoldenTime'] ?? ($decoded['has_golden_time'] ?? true))
      : true,
    'anotherChanceIfZero' => tctNormalizeBoolValue($decoded['anotherChanceIfZero'] ?? ($decoded['another_chance_if_zero'] ?? false))
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
    'afterEndtimeScore' => tctNormalizeScoreValue($settings['afterEndtimeScore'] ?? ($settings['after_endtime_score'] ?? 0)),
    'hasGoldenTime' => array_key_exists('hasGoldenTime', $settings) || array_key_exists('has_golden_time', $settings)
      ? tctNormalizeBoolValue($settings['hasGoldenTime'] ?? ($settings['has_golden_time'] ?? true))
      : true,
    'anotherChanceIfZero' => tctNormalizeBoolValue($settings['anotherChanceIfZero'] ?? ($settings['another_chance_if_zero'] ?? false))
  ];
  $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($json === false) {
    return false;
  }
  return egmDbFilePutContents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

function tctBuildTaskInfoSettingsPath(string $tasksDir, string $tagCode): string
{
  $taskDir = tctBuildTaskDirPath($tasksDir, $tagCode);
  if ($taskDir === '') {
    return '';
  }
  return $taskDir . DIRECTORY_SEPARATOR . EGMT_INFO_SETTINGS_FILE;
}

function tctBuildTaskInfoScoresPath(string $tasksDir, string $tagCode): string
{
  $taskDir = tctBuildTaskDirPath($tasksDir, $tagCode);
  if ($taskDir === '') {
    return '';
  }
  return $taskDir . DIRECTORY_SEPARATOR . EGMT_INFO_SCORES_FILE;
}

function tctLoadTaskInfoSettings(string $tasksDir, string $tagCode): array
{
  $defaults = [
    'title' => '',
    'text' => '',
    'guidePrefix' => '',
    'guideSuffix' => ''
  ];
  $path = tctBuildTaskInfoSettingsPath($tasksDir, $tagCode);
  if ($path === '' || !egmDbIsFile($path)) {
    return $defaults;
  }
  $content = egmDbFileGetContents($path);
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
    'text' => trim((string)($payload['text'] ?? '')),
    'guidePrefix' => trim((string)($payload['guidePrefix'] ?? ($payload['guide_prefix'] ?? ''))),
    'guideSuffix' => trim((string)($payload['guideSuffix'] ?? ($payload['guide_suffix'] ?? '')))
  ];
  $json = json_encode($safePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($json === false) {
    return false;
  }
  return egmDbFilePutContents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

function tctLoadTaskInfoScores(string $tasksDir, string $tagCode): array
{
  $path = tctBuildTaskInfoScoresPath($tasksDir, $tagCode);
  if ($path === '' || !egmDbIsFile($path)) {
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
  return egmDbFilePutContents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

function tctBuildTaskTeamSettingsPath(string $tasksDir, string $tagCode): string
{
  $taskDir = tctBuildTaskDirPath($tasksDir, $tagCode);
  if ($taskDir === '') {
    return '';
  }
  return $taskDir . DIRECTORY_SEPARATOR . EGMT_TEAM_SETTINGS_FILE;
}

function tctLoadTaskTeamSettings(string $tasksDir, string $tagCode): array
{
  $defaults = [
    'teamMin' => 1,
    'teamMax' => 1,
    'teamAdditionalNote' => ''
  ];
  $path = tctBuildTaskTeamSettingsPath($tasksDir, $tagCode);
  if ($path === '' || !egmDbIsFile($path)) {
    return $defaults;
  }
  $content = egmDbFileGetContents($path);
  if ($content === false) {
    return $defaults;
  }
  $decoded = json_decode($content, true);
  if (!is_array($decoded)) {
    return $defaults;
  }
  $teamMin = tctNormalizeScoreValue($decoded['teamMin'] ?? ($decoded['team_min'] ?? 1));
  $teamMax = tctNormalizeScoreValue($decoded['teamMax'] ?? ($decoded['team_max'] ?? 1));
  $teamAdditionalNote = str_replace(["\r\n", "\r"], "\n", (string)($decoded['teamAdditionalNote'] ?? ($decoded['team_additional_note'] ?? '')));
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

function tctSaveTaskTeamSettings(string $tasksDir, string $tagCode, array $settings): bool
{
  if (!tctEnsureTaskFolder($tasksDir, $tagCode)) {
    return false;
  }
  $path = tctBuildTaskTeamSettingsPath($tasksDir, $tagCode);
  if ($path === '') {
    return false;
  }
  $teamMin = tctNormalizeScoreValue($settings['teamMin'] ?? ($settings['team_min'] ?? 1));
  $teamMax = tctNormalizeScoreValue($settings['teamMax'] ?? ($settings['team_max'] ?? 1));
  $teamAdditionalNote = str_replace(["\r\n", "\r"], "\n", (string)($settings['teamAdditionalNote'] ?? ($settings['team_additional_note'] ?? '')));
  if ($teamMin < 1) {
    $teamMin = 1;
  }
  if ($teamMax < $teamMin) {
    $teamMax = $teamMin;
  }
  $payload = [
    'teamMin' => $teamMin,
    'teamMax' => $teamMax,
    'teamAdditionalNote' => $teamAdditionalNote
  ];
  $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($json === false) {
    return false;
  }
  return egmDbFilePutContents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

function tctBuildTaskTeamChallengesPath(string $tasksDir, string $tagCode): string
{
  $taskDir = tctBuildTaskDirPath($tasksDir, $tagCode);
  if ($taskDir === '') {
    return '';
  }
  return $taskDir . DIRECTORY_SEPARATOR . EGMT_TEAM_CHALLENGES_FILE;
}

function tctMakeTaskTeamChallengeId(): string
{
  try {
    return 'tch_' . bin2hex(random_bytes(6));
  } catch (Throwable $e) {
    return 'tch_' . str_replace('.', '', uniqid('', true));
  }
}

function tctLoadTaskTeamChallenges(string $tasksDir, string $tagCode): array
{
  $path = tctBuildTaskTeamChallengesPath($tasksDir, $tagCode);
  if ($path === '' || !egmDbIsFile($path)) {
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
    $guide = str_replace(["\r\n", "\r"], "\n", (string)($item['guide'] ?? ($item['challengeGuide'] ?? ($item['challenge_guide'] ?? ''))));

    $quantity = max(0, tctNormalizeScoreValue($item['quantity'] ?? 0));
    $last = max(0, tctNormalizeScoreValue($item['last'] ?? $quantity));
    if ($quantity === 0 && $last > 0) {
      $quantity = $last;
    }
    if ($last > $quantity) {
      $last = $quantity;
    }

    $createdAt = trim((string)($item['createdAt'] ?? ($item['created_at'] ?? '')));
    if ($createdAt === '') {
      $createdAt = date('Y-m-d H:i:s');
    }

    $result[] = [
      'id' => $challengeId,
      'name' => $name,
      'guide' => $guide,
      'quantity' => $quantity,
      'last' => $last,
      'createdAt' => $createdAt
    ];
  }
  return $result;
}

function tctSaveTaskTeamChallenges(string $tasksDir, string $tagCode, array $challenges): bool
{
  if (!tctEnsureTaskFolder($tasksDir, $tagCode)) {
    return false;
  }
  $path = tctBuildTaskTeamChallengesPath($tasksDir, $tagCode);
  if ($path === '') {
    return false;
  }

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
    $guide = str_replace(["\r\n", "\r"], "\n", (string)($item['guide'] ?? ($item['challengeGuide'] ?? ($item['challenge_guide'] ?? ''))));

    $quantity = max(0, tctNormalizeScoreValue($item['quantity'] ?? 0));
    $last = max(0, tctNormalizeScoreValue($item['last'] ?? $quantity));
    if ($quantity === 0 && $last > 0) {
      $quantity = $last;
    }
    if ($last > $quantity) {
      $last = $quantity;
    }

    $createdAt = trim((string)($item['createdAt'] ?? ($item['created_at'] ?? '')));
    if ($createdAt === '') {
      $createdAt = date('Y-m-d H:i:s');
    }

    $safe[] = [
      'id' => $challengeId,
      'name' => $name,
      'guide' => $guide,
      'quantity' => $quantity,
      'last' => $last,
      'createdAt' => $createdAt
    ];
  }

  $json = json_encode($safe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($json === false) {
    return false;
  }
  return egmDbFilePutContents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

function tctBuildTaskTeamRuntimePath(string $tasksDir, string $tagCode): string
{
  $taskDir = tctBuildTaskDirPath($tasksDir, $tagCode);
  if ($taskDir === '') {
    return '';
  }
  return $taskDir . DIRECTORY_SEPARATOR . EGMT_TEAM_RUNTIME_FILE;
}

function tctNormalizeTeamJoinType(string $value): string
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

function tctLoadTaskTeamRuntime(string $tasksDir, string $tagCode): array
{
  $path = tctBuildTaskTeamRuntimePath($tasksDir, $tagCode);
  if ($path === '' || !egmDbIsFile($path)) {
    return ['teams' => []];
  }
  $content = egmDbFileGetContents($path);
  if ($content === false) {
    return ['teams' => []];
  }
  $decoded = json_decode($content, true);
  if (!is_array($decoded)) {
    return ['teams' => []];
  }
  $teams = is_array($decoded['teams'] ?? null) ? $decoded['teams'] : [];
  $safeTeams = [];
  $seen = [];
  foreach ($teams as $team) {
    if (!is_array($team)) {
      continue;
    }
    $teamId = trim((string)($team['id'] ?? ''));
    $teamName = trim((string)($team['name'] ?? ''));
    $leaderWorkId = trim((string)($team['leaderWorkId'] ?? ($team['leader_work_id'] ?? '')));
    if ($teamId === '' || $teamName === '' || $leaderWorkId === '' || isset($seen[$teamId])) {
      continue;
    }
    $seen[$teamId] = true;

    $members = [];
    foreach ((array)($team['members'] ?? []) as $memberRaw) {
      $memberId = trim((string)$memberRaw);
      if ($memberId !== '' && !in_array($memberId, $members, true)) {
        $members[] = $memberId;
      }
    }
    if (!in_array($leaderWorkId, $members, true)) {
      array_unshift($members, $leaderWorkId);
    }

    $invites = [];
    foreach ((array)($team['invites'] ?? []) as $inviteRaw) {
      $inviteId = trim((string)$inviteRaw);
      if (
        $inviteId !== ''
        && !in_array($inviteId, $invites, true)
        && !in_array($inviteId, $members, true)
      ) {
        $invites[] = $inviteId;
      }
    }

    $requests = [];
    foreach ((array)($team['requests'] ?? []) as $requestRaw) {
      $requestId = trim((string)$requestRaw);
      if (
        $requestId !== ''
        && !in_array($requestId, $requests, true)
        && !in_array($requestId, $members, true)
      ) {
        $requests[] = $requestId;
      }
    }

    $safeTeams[] = [
      'id' => $teamId,
      'name' => $teamName,
      'leaderWorkId' => $leaderWorkId,
      'joinType' => tctNormalizeTeamJoinType((string)($team['joinType'] ?? ($team['join_type'] ?? 'private'))),
      'members' => array_values($members),
      'invites' => array_values($invites),
      'requests' => array_values($requests),
      'renameCount' => max(0, min(3, (int)($team['renameCount'] ?? ($team['rename_count'] ?? 0)))),
      'started' => (bool)($team['started'] ?? false),
      'challengeAccepted' => (bool)($team['challengeAccepted'] ?? ($team['challenge_accepted'] ?? false)),
      'startedAt' => trim((string)($team['startedAt'] ?? ($team['started_at'] ?? ''))),
      'challengeId' => trim((string)($team['challengeId'] ?? ($team['challenge_id'] ?? ''))),
      'challengeName' => trim((string)($team['challengeName'] ?? ($team['challenge_name'] ?? ''))),
      'challengeGuide' => trim((string)($team['challengeGuide'] ?? ($team['challenge_guide'] ?? ''))),
      'createdAt' => trim((string)($team['createdAt'] ?? ($team['created_at'] ?? '')))
    ];
  }
  return ['teams' => $safeTeams];
}

function tctSaveTaskTeamRuntime(string $tasksDir, string $tagCode, array $runtime): bool
{
  if (!tctEnsureTaskFolder($tasksDir, $tagCode)) {
    return false;
  }
  $path = tctBuildTaskTeamRuntimePath($tasksDir, $tagCode);
  if ($path === '') {
    return false;
  }
  $teams = is_array($runtime['teams'] ?? null) ? $runtime['teams'] : [];
  $json = json_encode(['teams' => array_values($teams)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($json === false) {
    return false;
  }
  return egmDbFilePutContents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

function tctFindTaskTeamIndexById(array $teams, string $teamId): int
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

function tctResolveTeamTaskStatusForUser(array $teams, string $workId): array
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

function tctParseTeamTaskMap(string $value): array
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
    $segments = explode('::', $chunk);
    if (count($segments) >= 4) {
      $taskId = trim((string)($segments[0] ?? ''));
      if ($taskId === '') {
        continue;
      }
      $map[$taskId] = [
        'teamName' => trim((string)($segments[1] ?? '')),
        'status' => trim((string)($segments[2] ?? '')),
        'score' => tctNormalizeScoreValue($segments[3] ?? 0)
      ];
      continue;
    }
    if (count($segments) >= 2) {
      $taskId = trim((string)($segments[0] ?? ''));
      if ($taskId === '') {
        continue;
      }
      $map[$taskId] = [
        'teamName' => '',
        'status' => '',
        'score' => tctNormalizeScoreValue($segments[1] ?? 0)
      ];
    }
  }
  return $map;
}

function tctSerializeTeamTaskMap(array $map): string
{
  $tokens = [];
  foreach ($map as $taskId => $entry) {
    $normalizedTaskId = trim((string)$taskId);
    if ($normalizedTaskId === '' || !is_array($entry)) {
      continue;
    }
    $teamName = trim((string)($entry['teamName'] ?? ''));
    $status = trim((string)($entry['status'] ?? ''));
    $score = tctNormalizeScoreValue($entry['score'] ?? 0);
    $tokens[] = $normalizedTaskId . '::' . $teamName . '::' . $status . '::' . (string)$score;
  }
  return implode(', ', $tokens);
}

function tctSyncTeamTaskCsvState(string $inviteesPath, string $mapPath, string $taskId, array $teams): bool
{
  $normalizedTaskId = trim($taskId);
  if ($normalizedTaskId === '' || !egmDbIsFile($inviteesPath)) {
    return false;
  }
  $rows = tctReadCsvRows($inviteesPath);
  $columnIndexByName = tctEnsureInviteesColumns($rows, ['Work ID', 'Team Task']);
  $header = (isset($rows[0]) && is_array($rows[0])) ? $rows[0] : [];
  $workIdIndex = tctResolveWorkIdIndexFromHeaderAndMap($header, $mapPath);
  if ($workIdIndex < 0) {
    $workIdIndex = (int)($columnIndexByName[tctNormalizeHeaderName('Work ID')] ?? -1);
  }
  $teamTaskIndex = (int)($columnIndexByName[tctNormalizeHeaderName('Team Task')] ?? -1);
  if ($workIdIndex < 0 || $teamTaskIndex < 0) {
    return false;
  }

  for ($rowIndex = 1; $rowIndex < count($rows); $rowIndex += 1) {
    if (!is_array($rows[$rowIndex])) {
      $rows[$rowIndex] = [];
    }
    $row = &$rows[$rowIndex];
    $workId = trim((string)($row[$workIdIndex] ?? ''));
    if ($workId === '') {
      unset($row);
      continue;
    }
    $teamMap = tctParseTeamTaskMap((string)($row[$teamTaskIndex] ?? ''));
    $previousScore = isset($teamMap[$normalizedTaskId]) && is_array($teamMap[$normalizedTaskId])
      ? tctNormalizeScoreValue($teamMap[$normalizedTaskId]['score'] ?? 0)
      : 0;
    $resolved = tctResolveTeamTaskStatusForUser($teams, $workId);
    $status = trim((string)($resolved['status'] ?? ''));
    if ($status === '') {
      unset($teamMap[$normalizedTaskId]);
    } else {
      $teamMap[$normalizedTaskId] = [
        'teamName' => trim((string)($resolved['teamName'] ?? '')),
        'status' => $status,
        'score' => $previousScore
      ];
    }
    $row[$teamTaskIndex] = tctSerializeTeamTaskMap($teamMap);
    unset($row);
  }

  return tctWriteCsvRows($inviteesPath, $rows);
}

function tctBuildTaskDescribePhotoDirPath(string $tasksDir, string $tagCode): string
{
  $taskDir = tctBuildTaskDirPath($tasksDir, $tagCode);
  if ($taskDir === '') {
    return '';
  }
  return $taskDir . DIRECTORY_SEPARATOR . EGMT_DESCRIBE_PHOTO_DIR;
}

function tctBuildTaskDescribePhotoMetaPath(string $tasksDir, string $tagCode): string
{
  $photoDir = tctBuildTaskDescribePhotoDirPath($tasksDir, $tagCode);
  if ($photoDir === '') {
    return '';
  }
  return $photoDir . DIRECTORY_SEPARATOR . EGMT_DESCRIBE_PHOTO_META_FILE;
}

function tctBuildTaskDescribePhotoArticlesPath(string $tasksDir, string $tagCode): string
{
  $photoDir = tctBuildTaskDescribePhotoDirPath($tasksDir, $tagCode);
  if ($photoDir === '') {
    return '';
  }
  return $photoDir . DIRECTORY_SEPARATOR . EGMT_DESCRIBE_PHOTO_ARTICLES_DIR;
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
  if (!is_string($real) || $real === '' || !egmDbIsFile($real)) {
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
  $base = 'mini%20apps/Event%20Guest%20Manager/egm_asset.php?path=';
  $relative = implode('/', array_map('rawurlencode', [
    'tasks', $normalizedTagCode, EGMT_DESCRIBE_PHOTO_DIR, $safeFileName
  ]));
  return $base . rawurlencode($relative);
}

function tctNormalizeMinimumStayMinutes($value): int
{
  if (!is_scalar($value) || !is_numeric(trim((string)$value))) {
    return 1;
  }
  return max(1, min(1440, (int)$value));
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
  if (!egmDbIsFile($metaPath)) {
    $json = json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
      return false;
    }
    if (egmDbFilePutContents($metaPath, $json . PHP_EOL, LOCK_EX) === false) {
      return false;
    }
  }
  return true;
}

function tctLoadTaskDescribePhotos(string $tasksDir, string $tagCode): array
{
  $metaPath = tctBuildTaskDescribePhotoMetaPath($tasksDir, $tagCode);
  if ($metaPath === '' || !egmDbIsFile($metaPath)) {
    return [];
  }
  $content = egmDbFileGetContents($metaPath);
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
  return egmDbFilePutContents($metaPath, $json . PHP_EOL, LOCK_EX) !== false;
}

function tctParseDescribePhotoPicksMap(string $raw): array
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
    $articleFile = basename(trim((string)($parts[2] ?? '')));
    if ($taskId === '' || $photoId === '' || $articleFile === '') {
      continue;
    }
    if (!isset($map[$taskId]) || !is_array($map[$taskId])) {
      $map[$taskId] = [];
    }
    $map[$taskId][$photoId] = $articleFile;
  }
  return $map;
}

function tctCountWordsInText(string $text): int
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

function tctCollectDescribePhotoSubmissionsByWorkId(
  string $tasksDir,
  string $tagCode,
  string $taskId,
  string $inviteesPath,
  string $mapPath = ''
): array {
  $normalizedTagCode = tctNormalizeTagCode($tagCode);
  $normalizedTaskId = trim($taskId);
  if ($normalizedTagCode === '' || $normalizedTaskId === '') {
    return [];
  }

  $rows = tctReadCsvRows($inviteesPath);
  if (!$rows || !isset($rows[0]) || !is_array($rows[0])) {
    return [];
  }
  $header = $rows[0];
  $workIdIndex = tctResolveWorkIdIndexFromHeaderAndMap($header, $mapPath);
  $picksIndex = tctFindHeaderIndex($header, 'describe photo picks');
  if ($workIdIndex < 0 || $picksIndex < 0) {
    return [];
  }

  $articlesDirPath = tctBuildTaskDescribePhotoArticlesPath($tasksDir, $normalizedTagCode);
  if ($articlesDirPath === '' || !is_dir($articlesDirPath)) {
    return [];
  }

  $photos = tctLoadTaskDescribePhotos($tasksDir, $normalizedTagCode);
  $photoById = [];
  foreach ($photos as $photo) {
    if (!is_array($photo)) {
      continue;
    }
    $photoId = trim((string)($photo['id'] ?? ''));
    if ($photoId === '') {
      continue;
    }
    $photoById[$photoId] = $photo;
  }

  $submissionsByWorkId = [];
  for ($rowIndex = 1; $rowIndex < count($rows); $rowIndex += 1) {
    $row = is_array($rows[$rowIndex] ?? null) ? $rows[$rowIndex] : [];
    $workId = trim((string)($row[$workIdIndex] ?? ''));
    if ($workId === '') {
      continue;
    }
    $pickMap = tctParseDescribePhotoPicksMap((string)($row[$picksIndex] ?? ''));
    $taskPicks = is_array($pickMap[$normalizedTaskId] ?? null) ? $pickMap[$normalizedTaskId] : [];
    if (!$taskPicks) {
      continue;
    }

    $submittedItems = [];
    foreach ($taskPicks as $photoId => $articleFileRaw) {
      $normalizedPhotoId = trim((string)$photoId);
      $articleFile = basename(trim((string)$articleFileRaw));
      if ($normalizedPhotoId === '' || $articleFile === '') {
        continue;
      }
      $articlePath = $articlesDirPath . DIRECTORY_SEPARATOR . $articleFile;
      if (!egmDbIsFile($articlePath)) {
        continue;
      }
      $content = egmDbFileGetContents($articlePath);
      if (!is_string($content)) {
        continue;
      }
      $wordCount = tctCountWordsInText($content);
      if ($wordCount < 1) {
        continue;
      }

      $photoMeta = is_array($photoById[$normalizedPhotoId] ?? null) ? $photoById[$normalizedPhotoId] : [];
      $photoName = trim((string)($photoMeta['name'] ?? ''));
      if ($photoName === '') {
        $photoName = trim((string)pathinfo($articleFile, PATHINFO_FILENAME));
      }
      if ($photoName === '') {
        $photoName = 'Photo';
      }
      $submittedItems[] = [
        'photoId' => $normalizedPhotoId,
        'photoName' => $photoName,
        'photoUrl' => trim((string)($photoMeta['url'] ?? '')),
        'articleFile' => $articleFile,
        'wordCount' => $wordCount
      ];
    }

    if ($submittedItems) {
      $submissionsByWorkId[$workId] = $submittedItems;
    }
  }

  return $submissionsByWorkId;
}

function tctReadDescribePhotoSubmissionArticle(
  string $tasksDir,
  string $tagCode,
  string $taskId,
  string $workId,
  string $photoId,
  string $inviteesPath,
  string $mapPath = ''
): array {
  $normalizedTagCode = tctNormalizeTagCode($tagCode);
  $normalizedTaskId = trim($taskId);
  $normalizedWorkId = trim($workId);
  $normalizedPhotoId = trim($photoId);
  if (
    $normalizedTagCode === '' ||
    $normalizedTaskId === '' ||
    $normalizedWorkId === '' ||
    $normalizedPhotoId === ''
  ) {
    return ['ok' => false, 'message' => 'Invalid result payload.'];
  }

  $rows = tctReadCsvRows($inviteesPath);
  if (!$rows || !isset($rows[0]) || !is_array($rows[0])) {
    return ['ok' => false, 'message' => 'Invitees mapped file is not available.'];
  }
  $header = $rows[0];
  $workIdIndex = tctResolveWorkIdIndexFromHeaderAndMap($header, $mapPath);
  $picksIndex = tctFindHeaderIndex($header, 'describe photo picks');
  if ($workIdIndex < 0 || $picksIndex < 0) {
    return ['ok' => false, 'message' => 'Describe Photo picks are not configured.'];
  }

  $targetRow = null;
  for ($rowIndex = 1; $rowIndex < count($rows); $rowIndex += 1) {
    $row = is_array($rows[$rowIndex] ?? null) ? $rows[$rowIndex] : [];
    $rowWorkId = trim((string)($row[$workIdIndex] ?? ''));
    if ($rowWorkId !== '' && $rowWorkId === $normalizedWorkId) {
      $targetRow = $row;
      break;
    }
  }
  if (!is_array($targetRow)) {
    return ['ok' => false, 'message' => 'Invitee not found.'];
  }

  $pickMap = tctParseDescribePhotoPicksMap((string)($targetRow[$picksIndex] ?? ''));
  $taskPicks = is_array($pickMap[$normalizedTaskId] ?? null) ? $pickMap[$normalizedTaskId] : [];
  $articleFile = basename(trim((string)($taskPicks[$normalizedPhotoId] ?? '')));
  if ($articleFile === '') {
    return ['ok' => false, 'message' => 'No result was submitted for this photo.'];
  }

  $articlesDirPath = tctBuildTaskDescribePhotoArticlesPath($tasksDir, $normalizedTagCode);
  if ($articlesDirPath === '') {
    return ['ok' => false, 'message' => 'Invalid article directory.'];
  }
  $articlePath = $articlesDirPath . DIRECTORY_SEPARATOR . $articleFile;
  if (!egmDbIsFile($articlePath)) {
    return ['ok' => false, 'message' => 'Result file not found.'];
  }

  $content = egmDbFileGetContents($articlePath);
  if (!is_string($content)) {
    return ['ok' => false, 'message' => 'Failed to read result file.'];
  }
  $wordCount = tctCountWordsInText($content);
  if ($wordCount < 1) {
    return ['ok' => false, 'message' => 'This result has no saved text.'];
  }

  $photoMeta = [];
  foreach (tctLoadTaskDescribePhotos($tasksDir, $normalizedTagCode) as $photo) {
    if (!is_array($photo)) {
      continue;
    }
    if (trim((string)($photo['id'] ?? '')) === $normalizedPhotoId) {
      $photoMeta = $photo;
      break;
    }
  }

  $photoName = trim((string)($photoMeta['name'] ?? ''));
  if ($photoName === '') {
    $photoName = trim((string)pathinfo($articleFile, PATHINFO_FILENAME));
  }
  if ($photoName === '') {
    $photoName = 'Photo';
  }

  return [
    'ok' => true,
    'result' => [
      'photoId' => $normalizedPhotoId,
      'photoName' => $photoName,
      'photoUrl' => trim((string)($photoMeta['url'] ?? '')),
      'articleFile' => $articleFile,
      'wordCount' => $wordCount
    ],
    'text' => $content
  ];
}

function tctMergeTaskScores(array $tasks, string $tasksDir): array
{
  $merged = [];
  foreach ($tasks as $task) {
    if (!is_array($task)) {
      continue;
    }
    $tagCode = (string)($task['tagCode'] ?? '');
    $taskType = tctNormalizeTaskType((string)($task['taskType'] ?? 'period'));
    if ($taskType !== 'period') {
      $scoreSettings = tctLoadTaskScoreSettings($tasksDir, $tagCode);
      $task['score'] = $scoreSettings['score'];
      $task['afterEndtimeScore'] = $scoreSettings['afterEndtimeScore'];
      $task['hasGoldenTime'] = (bool)($scoreSettings['hasGoldenTime'] ?? true);
      $task['anotherChanceIfZero'] = (bool)($scoreSettings['anotherChanceIfZero'] ?? false);
    } else {
      unset($task['score'], $task['afterEndtimeScore'], $task['hasGoldenTime'], $task['anotherChanceIfZero']);
    }
    if ($taskType === 'period' || $taskType === 'quiz' || $taskType === 'conditional_quiz' || $taskType === 'info' || $taskType === 'team_task' || $taskType === 'describe_photo') {
      $info = tctLoadTaskInfoSettings($tasksDir, $tagCode);
      $task['infoTitle'] = (string)($info['title'] ?? '');
      $task['infoText'] = (string)($info['text'] ?? '');
      $task['guidePrefix'] = (string)($info['guidePrefix'] ?? '');
      $task['guideSuffix'] = (string)($info['guideSuffix'] ?? '');
      $teamSettings = $taskType === 'team_task'
        ? tctLoadTaskTeamSettings($tasksDir, $tagCode)
        : ['teamMin' => 0, 'teamMax' => 0, 'teamAdditionalNote' => ''];
      $task['teamMin'] = (int)($teamSettings['teamMin'] ?? 0);
      $task['teamMax'] = (int)($teamSettings['teamMax'] ?? 0);
      $task['teamAdditionalNote'] = (string)($teamSettings['teamAdditionalNote'] ?? '');
      $task['taskPhotos'] = $taskType === 'describe_photo'
        ? tctLoadTaskDescribePhotos($tasksDir, $tagCode)
        : [];
      $task['taskChallenges'] = $taskType === 'team_task'
        ? tctLoadTaskTeamChallenges($tasksDir, $tagCode)
        : [];
    } else {
      $task['infoTitle'] = '';
      $task['infoText'] = '';
      $task['guidePrefix'] = '';
      $task['guideSuffix'] = '';
      $task['teamMin'] = 0;
      $task['teamMax'] = 0;
      $task['teamAdditionalNote'] = '';
      $task['taskPhotos'] = [];
      $task['taskChallenges'] = [];
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
  $active = tctNormalizeBoolValue($task['active'] ?? false);
  $duration = tctNormalizeBoolValue($task['duration'] ?? false);
  $quitRequired = tctNormalizeBoolValue($task['quitRequired'] ?? ($task['quit_required'] ?? false));
  $quitTimelineRequired = tctNormalizeBoolValue($task['quitTimelineRequired'] ?? ($task['quit_timeline_required'] ?? true));
  $minimumStayMinutes = tctNormalizeMinimumStayMinutes($task['minimumStayMinutes'] ?? ($task['minimum_stay_minutes'] ?? 1));
  $devPhase = tctNormalizeBoolValue($task['devPhase'] ?? ($task['dev_phase'] ?? false));
  $startDate = tctNormalizeDateValue((string)($task['startDate'] ?? ($task['start_date'] ?? '')));
  $startTime = tctNormalizeTimeValue((string)($task['startTime'] ?? ($task['start_time'] ?? '')));
  $endDate = tctNormalizeDateValue((string)($task['endDate'] ?? ($task['end_date'] ?? '')));
  $endTime = tctNormalizeTimeValue((string)($task['endTime'] ?? ($task['end_time'] ?? '')));
  $enterDeadlineDate = tctNormalizeDateValue((string)($task['enterDeadlineDate'] ?? ($task['enter_deadline_date'] ?? '')));
  $enterDeadlineTime = tctNormalizeTimeValue((string)($task['enterDeadlineTime'] ?? ($task['enter_deadline_time'] ?? '')));
  $quitOpeningDate = tctNormalizeDateValue((string)($task['quitOpeningDate'] ?? ($task['quit_opening_date'] ?? '')));
  $quitOpeningTime = tctNormalizeTimeValue((string)($task['quitOpeningTime'] ?? ($task['quit_opening_time'] ?? '')));
  $order = (int)($task['order'] ?? $fallbackOrder);
  if ($order < 1) {
    $order = $fallbackOrder;
  }
  $createdAt = trim((string)($task['createdAt'] ?? ''));
  if ($createdAt === '') {
    $createdAt = date('Y-m-d H:i:s');
  }
  $endedAt = trim((string)($task['endedAt'] ?? ($task['ended_at'] ?? '')));
  $endedBy = trim((string)($task['endedBy'] ?? ($task['ended_by'] ?? '')));
  $endedNoQuitResolution = trim((string)($task['endedNoQuitResolution'] ?? ($task['ended_no_quit_resolution'] ?? '')));
  if (!in_array($endedNoQuitResolution, ['correct_presence', 'fake_presence'], true)) {
    $endedNoQuitResolution = '';
  }
  $endedNoQuitCount = max(0, (int)($task['endedNoQuitCount'] ?? ($task['ended_no_quit_count'] ?? 0)));
  return [
    'id' => $id,
    'title' => $title,
    'tagCode' => $tagCode,
    'active' => $active,
    'duration' => $duration,
    'quitRequired' => $quitRequired,
    'quitTimelineRequired' => $quitTimelineRequired,
    'minimumStayMinutes' => $minimumStayMinutes,
    'devPhase' => $devPhase,
    'startDate' => $startDate,
    'startTime' => $startTime,
    'endDate' => $endDate,
    'endTime' => $endTime,
    'enterDeadlineDate' => $enterDeadlineDate,
    'enterDeadlineTime' => $enterDeadlineTime,
    'quitOpeningDate' => $quitOpeningDate,
    'quitOpeningTime' => $quitOpeningTime,
    'order' => $order,
    'createdAt' => $createdAt,
    'endedAt' => $endedAt,
    'endedBy' => $endedBy,
    'endedNoQuitResolution' => $endedNoQuitResolution,
    'endedNoQuitCount' => $endedNoQuitCount
  ];
}

function tctReadStoreTasks(string $storePath): array
{
  $databaseContext = egmDatabaseRuntimeContextForPath($storePath);
  if (is_array($databaseContext) && ($databaseContext['pdo'] ?? null) instanceof PDO) {
    // Periods are first-class database records. Reading the canonical row
    // avoids depending on a duplicated runtime-file representation.
    return egmInstanceReadPeriods($databaseContext['pdo'], (string)$databaseContext['code']);
  }
  if (!egmDbIsFile($storePath)) {
    return [];
  }
  $content = egmDbFileGetContents($storePath);
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
    unset($task['taskType'], $task['task_type']);
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
  $payload = "window.EGM_TASKS = {$json};\n";
  if (egmDbFilePutContents($storePath, $payload, LOCK_EX) === false) {
    return false;
  }
  try {
    return egmInstanceWriteMissionPeriodsUsingProjectConfig(dirname(__DIR__, 2), __DIR__, array_values($tasks));
  } catch (Throwable $error) {
    error_log('Failed to synchronize EGM periods database column: ' . $error->getMessage());
    return false;
  }
}

function tctReadCsvRows(string $path): array
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

function tctWriteCsvRows(string $path, array $rows): bool
{
  if (egmInviteesCsvIsManagedPath($path)) {
    return egmInviteesCsvCommitRows($path, $rows);
  }
  $dir = dirname($path);
  if (!is_dir($dir) && !(mkdir($dir, 0777, true) || is_dir($dir))) {
    return false;
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
  if (!egmDbIsFile($path)) {
    return [];
  }
  $content = egmDbFileGetContents($path);
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

function tctParseTaskScoreMap(string $value): array
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
    $sepPos = strpos($chunk, ':');
    if ($sepPos === false) {
      continue;
    }
    $taskId = trim(substr($chunk, 0, $sepPos));
    $scoreRaw = trim(substr($chunk, $sepPos + 1));
    if ($taskId === '') {
      continue;
    }
    $map[$taskId] = tctNormalizeScoreValue($scoreRaw);
  }
  return $map;
}

function tctSerializeTaskScoreMap(array $map): string
{
  $pairs = [];
  foreach ($map as $taskId => $score) {
    $normalizedTaskId = trim((string)$taskId);
    if ($normalizedTaskId === '') {
      continue;
    }
    $pairs[] = $normalizedTaskId . ':' . (string)tctNormalizeScoreValue($score);
  }
  return implode(',', $pairs);
}

function tctResolveTaskScoreColumnByType(string $taskType): string
{
  if ($taskType === 'describe_photo') {
    return 'Describe Photo Task';
  }
  if ($taskType === 'team_task') {
    return 'Team Task';
  }
  return 'Info Tasks';
}

function tctResolveTaskPaneKeysByType(string $taskType): array
{
  $normalizedType = tctNormalizeTaskType($taskType);
  if ($normalizedType === 'period') {
    return ['control', 'information', 'invite', 'invitees', 'invite-card', 'export'];
  }
  if ($normalizedType === 'quiz' || $normalizedType === 'conditional_quiz') {
    if ($normalizedType === 'conditional_quiz') {
      return ['control', 'information', 'quiz', 'crisis-control'];
    }
    return ['control', 'information', 'quiz'];
  }
  if ($normalizedType === 'info') {
    return ['control', 'information', 'invitees-rate'];
  }
  if ($normalizedType === 'team_task') {
    return ['control', 'information', 'challenge-storage', 'team', 'invitees-rate'];
  }
  if ($normalizedType === 'describe_photo') {
    return ['control', 'information', 'photo', 'invitees-rate'];
  }
  return ['control', 'information', 'invite', 'invitees', 'invite-card', 'export'];
}

function tctReadTaskAccessConfig(string $tasksDir): array
{
  $path = $tasksDir . DIRECTORY_SEPARATOR . EGMT_TASK_ACCESS_FILE;
  if (!egmDbIsFile($path)) {
    return ['users' => []];
  }
  $content = egmDbFileGetContents($path);
  if (!is_string($content) || trim($content) === '') {
    return ['users' => []];
  }
  $decoded = json_decode($content, true);
  if (!is_array($decoded)) {
    return ['users' => []];
  }
  $users = is_array($decoded['users'] ?? null) ? $decoded['users'] : [];
  return ['users' => $users];
}

function tctWriteTaskAccessConfig(string $tasksDir, array $config): bool
{
  $path = $tasksDir . DIRECTORY_SEPARATOR . EGMT_TASK_ACCESS_FILE;
  $normalized = [
    'users' => is_array($config['users'] ?? null) ? $config['users'] : [],
    'updatedAt' => date('Y-m-d H:i:s')
  ];
  $json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    return false;
  }
  return egmDbFilePutContents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

function tctResolveUserTaskAccessForTask(array $task, string $sessionUserCode, array $taskAccessConfig): array
{
  $paneKeys = tctResolveTaskPaneKeysByType((string)($task['taskType'] ?? 'period'));
  $resolved = [
    'enabled' => true,
    'panes' => []
  ];
  foreach ($paneKeys as $paneKey) {
    $resolved['panes'][$paneKey] = true;
  }

  $normalizedUserCode = strtolower(trim($sessionUserCode));
  if ($normalizedUserCode === '') {
    return $resolved;
  }
  $users = is_array($taskAccessConfig['users'] ?? null) ? $taskAccessConfig['users'] : [];
  $userEntry = null;
  foreach ($users as $rawUserCode => $entry) {
    if (!is_array($entry)) {
      continue;
    }
    if (strtolower(trim((string)$rawUserCode)) === $normalizedUserCode) {
      $userEntry = $entry;
      break;
    }
  }
  if (!is_array($userEntry)) {
    return $resolved;
  }
  $taskId = trim((string)($task['id'] ?? ''));
  if ($taskId === '') {
    return $resolved;
  }
  $taskRules = is_array($userEntry['tasks'] ?? null) ? $userEntry['tasks'] : [];
  $taskRule = null;
  foreach ($taskRules as $ruleTaskId => $entry) {
    if (!is_array($entry)) {
      continue;
    }
    if (strtolower(trim((string)$ruleTaskId)) === strtolower($taskId)) {
      $taskRule = $entry;
      break;
    }
  }
  if (!is_array($taskRule)) {
    return $resolved;
  }

  $resolved['enabled'] = tctNormalizeBoolValue($taskRule['enabled'] ?? true);
  $paneRules = is_array($taskRule['panes'] ?? null) ? $taskRule['panes'] : [];
  foreach ($paneKeys as $paneKey) {
    $resolved['panes'][$paneKey] = tctNormalizeBoolValue($paneRules[$paneKey] ?? true);
  }
  if (!$resolved['enabled']) {
    return $resolved;
  }
  $hasAllowedPane = false;
  foreach ($resolved['panes'] as $isPaneAllowed) {
    if ($isPaneAllowed) {
      $hasAllowedPane = true;
      break;
    }
  }
  if (!$hasAllowedPane) {
    $resolved['enabled'] = false;
  }
  return $resolved;
}

function tctCanSessionUserAccessTask(array $task, string $sessionUserCode, array $taskAccessConfig): bool
{
  $access = tctResolveUserTaskAccessForTask($task, $sessionUserCode, $taskAccessConfig);
  return (bool)($access['enabled'] ?? false);
}

function tctCanSessionUserAccessTaskPane(array $task, string $sessionUserCode, array $taskAccessConfig, string $paneKey): bool
{
  $normalizedPane = strtolower(trim($paneKey));
  if ($normalizedPane === '') {
    return tctCanSessionUserAccessTask($task, $sessionUserCode, $taskAccessConfig);
  }
  $access = tctResolveUserTaskAccessForTask($task, $sessionUserCode, $taskAccessConfig);
  if (!($access['enabled'] ?? false)) {
    return false;
  }
  return tctNormalizeBoolValue(($access['panes'][$normalizedPane] ?? false));
}

function tctAttachTaskAccessMeta(array $tasks, string $sessionUserCode, array $taskAccessConfig): array
{
  $result = [];
  foreach ($tasks as $task) {
    if (!is_array($task)) {
      continue;
    }
    $access = tctResolveUserTaskAccessForTask($task, $sessionUserCode, $taskAccessConfig);
    $paneMap = is_array($access['panes'] ?? null) ? $access['panes'] : [];
    $allowedPanes = [];
    foreach ($paneMap as $paneKey => $allowed) {
      if (tctNormalizeBoolValue($allowed)) {
        $allowedPanes[] = (string)$paneKey;
      }
    }
    $task['taskAccessEnabled'] = tctNormalizeBoolValue($access['enabled'] ?? true);
    $task['allowedTopPanes'] = array_values($allowedPanes);
    $result[] = $task;
  }
  return $result;
}

function tctCleanupTaskAccessForRemovedTask(string $tasksDir, string $taskId): bool
{
  $trimmedTaskId = trim($taskId);
  if ($trimmedTaskId === '') {
    return true;
  }
  $config = tctReadTaskAccessConfig($tasksDir);
  $users = is_array($config['users'] ?? null) ? $config['users'] : [];
  $changed = false;
  foreach ($users as $userCode => $entry) {
    if (!is_array($entry)) {
      continue;
    }
    $taskRules = is_array($entry['tasks'] ?? null) ? $entry['tasks'] : [];
    $nextRules = [];
    foreach ($taskRules as $ruleTaskId => $ruleValue) {
      if (strtolower(trim((string)$ruleTaskId)) === strtolower($trimmedTaskId)) {
        $changed = true;
        continue;
      }
      $nextRules[$ruleTaskId] = $ruleValue;
    }
    $entry['tasks'] = $nextRules;
    $users[$userCode] = $entry;
  }
  if (!$changed) {
    return true;
  }
  $config['users'] = $users;
  return tctWriteTaskAccessConfig($tasksDir, $config);
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
    EGMT_INFO_SETTINGS_FILE => [
      'title' => '',
      'text' => '',
      'guidePrefix' => '',
      'guideSuffix' => ''
    ]
  ];
  foreach ($defaultJsonFiles as $fileName => $payload) {
    $filePath = $taskDir . DIRECTORY_SEPARATOR . $fileName;
    if (egmDbIsFile($filePath)) {
      continue;
    }
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
      return false;
    }
    if (egmDbFilePutContents($filePath, $json . PHP_EOL, LOCK_EX) === false) {
      return false;
    }
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
    if (!@egmDbUnlink($full)) {
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
  $taskScoreMapIndex = tctFindHeaderIndex($header, 'task score map');
  if ($taskId !== '' && ($completedIdsIndex >= 0 || $taskScoreMapIndex >= 0)) {
    for ($i = 1; $i < count($rows); $i += 1) {
      if (!is_array($rows[$i])) {
        $rows[$i] = [];
      }
      $ids = $completedIdsIndex >= 0
        ? tctParseTaskCompletedIds((string)($rows[$i][$completedIdsIndex] ?? ''))
        : [];
      $taskScoreMap = $taskScoreMapIndex >= 0
        ? tctParseTaskScoreMap((string)($rows[$i][$taskScoreMapIndex] ?? ''))
        : [];
      $hadTask = in_array($taskId, $ids, true);
      $storedTaskScore = array_key_exists($taskId, $taskScoreMap)
        ? tctNormalizeScoreValue($taskScoreMap[$taskId] ?? 0)
        : $taskScore;
      if (!$hadTask && !array_key_exists($taskId, $taskScoreMap)) {
        continue;
      }
      if ($completedIdsIndex >= 0) {
        $ids = array_values(array_filter($ids, static fn(string $value): bool => $value !== $taskId));
        $rows[$i][$completedIdsIndex] = tctSerializeTaskCompletedIds($ids);
      }
      if ($taskScoreMapIndex >= 0) {
        unset($taskScoreMap[$taskId]);
        $rows[$i][$taskScoreMapIndex] = tctSerializeTaskScoreMap($taskScoreMap);
      }

      if ($scoreIndex >= 0 && $storedTaskScore > 0) {
        $currentScore = max(0, (int)($rows[$i][$scoreIndex] ?? 0));
        $rows[$i][$scoreIndex] = (string)max(0, $currentScore - $storedTaskScore);
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

if (!defined('EGMT_INCLUDE_ONLY')) {
  define('EGMT_INCLUDE_ONLY', false);
}

if (!EGMT_INCLUDE_ONLY && (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') && isset($_POST['tct_action'])) {
  header('Content-Type: application/json; charset=utf-8');
  $emitTaskJson = static function (array $payload, int $httpStatus = 200): never {
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    if (ob_get_level() > 0) {
      ob_clean();
    }
    echo tctEncodeResponseJson($payload);
    exit;
  };
  $csrfToken = egmSecurityReadCsrfFromRequest($_POST, 'csrf');
  if (!egmSecurityIsValidCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if (!tctEnsureTasksStorage($tctTasksDir, $tctStorePath)) {
    echo json_encode(['status' => 'error', 'message' => 'آماده‌سازی فضای ذخیره‌سازی بازه‌ها ناموفق بود.'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $action = trim((string)($_POST['tct_action'] ?? ''));
  $tasks = tctReindexTasks(tctReadStoreTasks($tctStorePath));
  $tctTaskAccessConfig = tctReadTaskAccessConfig($tctTasksDir);
  $buildTasksForResponse = static function (array $taskRows) use ($tctTasksDir, $tctSessionUserCode, $tctTaskAccessConfig): array {
    return tctAttachTaskAccessMeta(
      tctMergeTaskScores($taskRows, $tctTasksDir),
      $tctSessionUserCode,
      $tctTaskAccessConfig
    );
  };
  $findTaskById = static function (array $taskRows, string $taskId): ?array {
    $needle = trim($taskId);
    if ($needle === '') {
      return null;
    }
    foreach ($taskRows as $taskRow) {
      if (!is_array($taskRow)) {
        continue;
      }
      if (trim((string)($taskRow['id'] ?? '')) === $needle) {
        return $taskRow;
      }
    }
    return null;
  };
  $actionPaneMap = [
    'remove' => 'control',
    'save_task_title' => 'control',
    'save_task_settings' => 'control',
    'end_period' => 'control',
    'save_task_score_system' => 'control',
    'save_conditional_quiz_crisis_control' => 'crisis-control',
    'save_team_task_settings' => 'team',
    'save_info_task_content' => 'information',
    'add_describe_task_photo' => 'photo',
    'rename_describe_task_photo' => 'photo',
    'remove_describe_task_photo' => 'photo',
    'add_team_task_challenge' => 'challenge-storage',
    'save_team_task_challenge' => 'challenge-storage',
    'save_team_task_challenge_guide' => 'challenge-storage',
    'remove_team_task_challenge' => 'challenge-storage',
    'get_info_task_rate_data' => 'invitees-rate',
    'team_task_admin_get_team' => 'invitees-rate',
    'team_task_admin_update_team' => 'invitees-rate',
    'team_task_admin_set_challenge_accepted' => 'invitees-rate',
    'team_task_admin_remove_member' => 'invitees-rate',
    'get_describe_task_result_text' => 'invitees-rate',
    'save_info_task_scores' => 'invitees-rate'
  ];
  if (isset($actionPaneMap[$action])) {
    $targetTaskId = trim((string)($_POST['id'] ?? ''));
    if ($targetTaskId === '') {
      echo json_encode(['status' => 'error', 'message' => 'شناسه بازه نامعتبر است.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $accessTask = $findTaskById($tasks, $targetTaskId);
    if (!is_array($accessTask)) {
      echo json_encode(['status' => 'error', 'message' => 'بازه پیدا نشد.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if (!tctCanSessionUserAccessTask($accessTask, $tctSessionUserCode, $tctTaskAccessConfig)) {
      echo json_encode(['status' => 'error', 'message' => 'شما به این بازه دسترسی ندارید.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $requiredPane = (string)($actionPaneMap[$action] ?? '');
    if (
      $requiredPane !== ''
      && !tctCanSessionUserAccessTaskPane($accessTask, $tctSessionUserCode, $tctTaskAccessConfig, $requiredPane)
    ) {
      echo json_encode(['status' => 'error', 'message' => 'شما به این بخش از بازه دسترسی ندارید.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
  }
  if ($action === 'reorder') {
    foreach ($tasks as $task) {
      if (!is_array($task)) {
        continue;
      }
      if (!tctCanSessionUserAccessTask($task, $tctSessionUserCode, $tctTaskAccessConfig)) {
        echo json_encode(['status' => 'error', 'message' => 'تغییر ترتیب فقط برای کاربران دارای دسترسی به همه بازه‌ها مجاز است.'], JSON_UNESCAPED_UNICODE);
        exit;
      }
    }
  }

  if ($action === 'list') {
    // Period discovery must never rewrite the store. The former write on every
    // refresh caused concurrent EGM requests to deadlock, leaving period tabs
    // empty until a later retry happened to succeed.
    echo json_encode(['status' => 'ok', 'tasks' => $buildTasksForResponse($tasks)], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'add') {
    if (!$tctCanAccessManageTasks) {
      echo json_encode(['status' => 'error', 'message' => 'شما به مدیریت بازه‌ها دسترسی ندارید.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $title = tctNormalizeTaskTitle((string)($_POST['title'] ?? ''));
    if ($title === '') {
      echo json_encode(['status' => 'error', 'message' => 'نام بازه الزامی است.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $tagCode = tctGenerateNextTagCode($tasks);
    if (!tctEnsureTaskFolder($tctTasksDir, $tagCode)) {
      echo json_encode(['status' => 'error', 'message' => 'ایجاد پوشه بازه ناموفق بود.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $tasks[] = [
      'id' => tctMakeTaskId(),
      'title' => $title,
      'tagCode' => $tagCode,
      'devPhase' => false,
      'order' => count($tasks) + 1,
      'createdAt' => date('Y-m-d H:i:s')
    ];
    $tasks = tctReindexTasks($tasks);
    if (!tctSaveStoreTasks($tctStorePath, $tasks)) {
      echo json_encode(['status' => 'error', 'message' => 'ذخیره فهرست بازه‌ها ناموفق بود.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    echo json_encode([
      'status' => 'ok',
      'message' => "بازه با کد یکتای {$tagCode} افزوده شد.",
      'generatedTagCode' => $tagCode,
      'tasks' => $buildTasksForResponse($tasks)
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'remove') {
    $id = trim((string)($_POST['id'] ?? ''));
    if ($id === '') {
      echo json_encode(['status' => 'error', 'message' => 'شناسه بازه نامعتبر است.'], JSON_UNESCAPED_UNICODE);
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
      echo json_encode(['status' => 'error', 'message' => 'بازه پیدا نشد.'], JSON_UNESCAPED_UNICODE);
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
        echo json_encode(['status' => 'error', 'message' => 'بازه از فهرست حذف شد، اما پاک‌سازی فایل‌های آن ناموفق بود.'], JSON_UNESCAPED_UNICODE);
        exit;
      }
    }

    $tasks = tctReindexTasks($nextTasks);
    if (!tctCleanupTaskAccessForRemovedTask($tctTasksDir, $id)) {
      echo json_encode(['status' => 'error', 'message' => 'بازه حذف شد، اما پاک‌سازی تنظیمات دسترسی آن ناموفق بود.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if (!tctSaveStoreTasks($tctStorePath, $tasks)) {
      echo json_encode(['status' => 'error', 'message' => 'ذخیره فهرست بازه‌ها ناموفق بود.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    echo json_encode(['status' => 'ok', 'message' => 'بازه و اطلاعات وابسته به آن حذف شد.', 'tasks' => $buildTasksForResponse($tasks)], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'end_period') {
    $id = trim((string)($_POST['id'] ?? ''));
    $resolution = trim((string)($_POST['no_quit_resolution'] ?? ''));
    if ($id === '') {
      $emitTaskJson(['status' => 'error', 'message' => 'شناسه بازه نامعتبر است.'], 422);
    }
    try {
      $resolution = egmPeriodEndNormalizeResolution($resolution);
    } catch (InvalidArgumentException $error) {
      $emitTaskJson(['status' => 'error', 'message' => $error->getMessage()], 422);
    }

    $targetIndex = null;
    foreach ($tasks as $index => $task) {
      if ((string)($task['id'] ?? '') === $id) {
        $targetIndex = $index;
        break;
      }
    }
    if ($targetIndex === null) {
      $emitTaskJson(['status' => 'error', 'message' => 'بازه پیدا نشد.'], 404);
    }
    $databaseContext = egmDatabaseRuntimeContextForPath($tctStorePath);
    if (!is_array($databaseContext) || !($databaseContext['pdo'] ?? null) instanceof PDO) {
      $emitTaskJson(['status' => 'error', 'message' => 'اتصال پایگاه داده بازه در دسترس نیست.'], 503);
    }

    $pdo = $databaseContext['pdo'];
    $dataTable = (string)($databaseContext['tables']['data'] ?? '');
    $userPeriodsTable = (string)($databaseContext['tables']['user_periods'] ?? '');
    $endedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d H:i:s');
    $alreadyEnded = false;
    $conflictingResolution = '';
    $responseJson = '';
    try {
      $pdo->beginTransaction();
      // Serialize simultaneous End Period requests. The periods JSON row is
      // the canonical state, so locking it also prevents a stale settings save
      // from being silently overwritten while attendance is classified.
      $lockStatement = $pdo->query("SELECT `periods` FROM `{$dataTable}` WHERE `data_key` = 'periods' LIMIT 1 FOR UPDATE");
      $lockedJson = $lockStatement ? $lockStatement->fetchColumn() : false;
      $lockedDecoded = is_string($lockedJson) ? json_decode($lockedJson, true) : null;
      if (!is_array($lockedDecoded)) {
        throw new RuntimeException('period_state_unavailable');
      }
      $tasks = tctReindexTasks($lockedDecoded);
      $targetIndex = null;
      foreach ($tasks as $index => $task) {
        if ((string)($task['id'] ?? '') === $id) {
          $targetIndex = $index;
          break;
        }
      }
      if ($targetIndex === null) {
        throw new RuntimeException('period_not_found');
      }
      $storedEndedAt = trim((string)($tasks[$targetIndex]['endedAt'] ?? ''));
      if ($storedEndedAt !== '') {
        $storedResolution = trim((string)($tasks[$targetIndex]['endedNoQuitResolution'] ?? ''));
        if ($storedResolution !== '' && $storedResolution !== $resolution) {
          $conflictingResolution = $storedResolution;
          throw new RuntimeException('period_resolution_conflict');
        }
        $alreadyEnded = true;
        $endedAt = $storedEndedAt;
        if ($storedResolution !== '') {
          $resolution = $storedResolution;
        }
        $classification = [
          'pending' => (int)($tasks[$targetIndex]['endedNoQuitCount'] ?? 0),
          'classified' => (int)($tasks[$targetIndex]['endedNoQuitCount'] ?? 0),
          'resolution' => $resolution,
        ];
      } else {
        $periodCode = tctNormalizeTagCode((string)($tasks[$targetIndex]['tagCode'] ?? ''));
        if ($periodCode === '') {
          throw new RuntimeException('period_code_invalid');
        }
        $classification = egmPeriodEndClassifyOpenAttendance($pdo, $userPeriodsTable, $periodCode, $resolution);
        $tasks[$targetIndex]['active'] = false;
        $tasks[$targetIndex]['endedAt'] = $endedAt;
        $tasks[$targetIndex]['endedBy'] = $tctSessionUserCode;
        $tasks[$targetIndex]['endedNoQuitResolution'] = $resolution;
        $tasks[$targetIndex]['endedNoQuitCount'] = (int)($classification['classified'] ?? 0);
        $tasks = tctReindexTasks($tasks);
        if (!tctSaveStoreTasks($tctStorePath, $tasks)) {
          throw new RuntimeException('ذخیره وضعیت پایان بازه ناموفق بود.');
        }
      }

      $classifiedCount = max(0, (int)($classification['classified'] ?? 0));
      $presenceLabel = $resolution === 'correct_presence' ? 'حضور واقعی' : 'حضور نامعقول';
      $message = $alreadyEnded
        ? "بازه قبلاً با موفقیت پایان یافته بود و وضعیت {$classifiedCount} مهمان بدون خروج به «{$presenceLabel}» ثبت شده است."
        : "بازه پایان یافت و وضعیت {$classifiedCount} مهمان بدون خروج به «{$presenceLabel}» تغییر کرد.";
      $responseJson = json_encode([
        'status' => 'ok',
        'message' => $message,
        'classified_count' => $classifiedCount,
        'resolution' => $resolution,
        'ended_at' => $endedAt,
        'already_ended' => $alreadyEnded,
        'tasks' => $buildTasksForResponse($tasks),
      ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
      $pdo->commit();
    } catch (Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      if ($error->getMessage() === 'period_resolution_conflict') {
        $savedPresenceLabel = $conflictingResolution === 'correct_presence' ? 'حضور واقعی' : 'حضور نامعقول';
        $emitTaskJson([
          'status' => 'error',
          'message' => "این بازه قبلاً پایان یافته و مهمانان بدون خروج با وضعیت «{$savedPresenceLabel}» ثبت شده‌اند؛ وضعیت ذخیره‌شده تغییر نکرد.",
          'resolution' => $conflictingResolution,
          'already_ended' => true,
        ], 409);
      }
      error_log('Failed to end EGM period: ' . $error->getMessage());
      $emitTaskJson(['status' => 'error', 'message' => 'پایان بازه ناموفق بود؛ هیچ تغییری ثبت نشد.'], 500);
    }

    if (ob_get_level() > 0) {
      ob_clean();
    }
    echo $responseJson;
    exit;
  }

  if ($action === 'save_task_title') {
    $id = trim((string)($_POST['id'] ?? ''));
    $title = tctNormalizeTaskTitle((string)($_POST['title'] ?? ''));
    if ($id === '') {
      echo json_encode(['status' => 'error', 'message' => 'شناسه بازه نامعتبر است.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if ($title === '') {
      echo json_encode(['status' => 'error', 'message' => 'نام بازه الزامی است.'], JSON_UNESCAPED_UNICODE);
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
      echo json_encode(['status' => 'error', 'message' => 'بازه پیدا نشد.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $tasks = tctReindexTasks($tasks);
    if (!tctSaveStoreTasks($tctStorePath, $tasks)) {
      echo json_encode(['status' => 'error', 'message' => 'ذخیره نام بازه ناموفق بود.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    echo json_encode(['status' => 'ok', 'message' => 'نام بازه ذخیره شد.', 'tasks' => $buildTasksForResponse($tasks)], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'save_task_settings') {
    $id = trim((string)($_POST['id'] ?? ''));
    $title = tctNormalizeTaskTitle((string)($_POST['title'] ?? ''));
    if ($id === '') {
      echo json_encode(['status' => 'error', 'message' => 'شناسه بازه نامعتبر است.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if ($title === '') {
      echo json_encode(['status' => 'error', 'message' => 'نام بازه الزامی است.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $active = tctNormalizeBoolValue($_POST['active'] ?? '0');
    $duration = tctNormalizeBoolValue($_POST['duration'] ?? '0');
    $quitRequired = tctNormalizeBoolValue($_POST['quit_required'] ?? '0');
    // Save requests from older/cached clients may omit this field. Missing is
    // treated as off so optional deadline/opening fields never become required
    // unless the user explicitly enables the switch.
    $quitTimelineRequired = $quitRequired
      && tctNormalizeBoolValue($_POST['quit_timeline_required'] ?? '0');
    $minimumStayMinutes = tctNormalizeMinimumStayMinutes($_POST['minimum_stay_minutes'] ?? '1');
    $devPhase = tctNormalizeBoolValue($_POST['dev_phase'] ?? '0');
    $startDate = tctNormalizeDateValue((string)($_POST['start_date'] ?? ''));
    $startTime = tctNormalizeTimeValue((string)($_POST['start_time'] ?? ''));
    $endDate = tctNormalizeDateValue((string)($_POST['end_date'] ?? ''));
    $endTime = tctNormalizeTimeValue((string)($_POST['end_time'] ?? ''));
    $enterDeadlineDate = tctNormalizeDateValue((string)($_POST['enter_deadline_date'] ?? ''));
    $enterDeadlineTime = tctNormalizeTimeValue((string)($_POST['enter_deadline_time'] ?? ''));
    $quitOpeningDate = tctNormalizeDateValue((string)($_POST['quit_opening_date'] ?? ''));
    $quitOpeningTime = tctNormalizeTimeValue((string)($_POST['quit_opening_time'] ?? ''));
    $optionalTimelineValues = [$enterDeadlineDate, $enterDeadlineTime, $quitOpeningDate, $quitOpeningTime];
    if ($quitTimelineRequired && count(array_filter($optionalTimelineValues, static fn(string $value): bool => $value !== '')) === 0) {
      // A cached panel can incorrectly submit the switch as enabled while all
      // four disabled optional controls are empty. Empty means flexible mode.
      $quitTimelineRequired = false;
    }
    if ($duration) {
      if (in_array('', [$startDate, $startTime, $endDate, $endTime], true)) {
        echo json_encode(['status' => 'error', 'message' => 'تاریخ و ساعت شروع و پایان بازه الزامی هستند.'], JSON_UNESCAPED_UNICODE);
        exit;
      }
      if (!(($startDate . ' ' . $startTime) < ($endDate . ' ' . $endTime))) {
        echo json_encode(['status' => 'error', 'message' => 'زمان پایان بازه باید بعد از زمان شروع باشد.'], JSON_UNESCAPED_UNICODE);
        exit;
      }
    }
    if ($quitRequired) {
      if (!$duration) {
        echo json_encode(['status' => 'error', 'message' => 'برای الزام ثبت خروج، زمان‌بندی بازه باید فعال باشد.'], JSON_UNESCAPED_UNICODE);
        exit;
      }
      if ($quitTimelineRequired) {
        if (in_array('', $optionalTimelineValues, true)) {
          echo json_encode(['status' => 'error', 'message' => 'همه تاریخ‌ها و ساعت‌های خط زمانی ورود و خروج الزامی هستند.'], JSON_UNESCAPED_UNICODE);
          exit;
        }
        $startAt = $startDate . ' ' . $startTime;
        $enterDeadlineAt = $enterDeadlineDate . ' ' . $enterDeadlineTime;
        $quitOpeningAt = $quitOpeningDate . ' ' . $quitOpeningTime;
        $endAt = $endDate . ' ' . $endTime;
        if (!($startAt < $enterDeadlineAt && $enterDeadlineAt < $quitOpeningAt && $quitOpeningAt < $endAt)) {
          echo json_encode(['status' => 'error', 'message' => 'ترتیب زمان‌ها باید شروع، مهلت ورود، آغاز خروج و سپس پایان باشد.'], JSON_UNESCAPED_UNICODE);
          exit;
        }
      }
      if (!$quitTimelineRequired) {
        $enterDeadlineDate = '';
        $enterDeadlineTime = '';
        $quitOpeningDate = '';
        $quitOpeningTime = '';
      }
    }

    $found = false;
    foreach ($tasks as $index => $task) {
      if ((string)($task['id'] ?? '') !== $id) {
        continue;
      }
      $tasks[$index]['title'] = $title;
      $tasks[$index]['active'] = $active;
      $tasks[$index]['duration'] = $duration;
      $tasks[$index]['quitRequired'] = $quitRequired;
      $tasks[$index]['quitTimelineRequired'] = $quitTimelineRequired;
      $tasks[$index]['minimumStayMinutes'] = $minimumStayMinutes;
      $tasks[$index]['devPhase'] = $devPhase;
      $tasks[$index]['startDate'] = $startDate;
      $tasks[$index]['startTime'] = $startTime;
      $tasks[$index]['endDate'] = $endDate;
      $tasks[$index]['endTime'] = $endTime;
      $tasks[$index]['enterDeadlineDate'] = $enterDeadlineDate;
      $tasks[$index]['enterDeadlineTime'] = $enterDeadlineTime;
      $tasks[$index]['quitOpeningDate'] = $quitOpeningDate;
      $tasks[$index]['quitOpeningTime'] = $quitOpeningTime;
      $found = true;
      break;
    }
    if (!$found) {
      echo json_encode(['status' => 'error', 'message' => 'بازه پیدا نشد.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $tasks = tctReindexTasks($tasks);
    if (!tctSaveStoreTasks($tctStorePath, $tasks)) {
      echo json_encode(['status' => 'error', 'message' => 'ذخیره تنظیمات بازه ناموفق بود.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    echo json_encode(['status' => 'ok', 'message' => 'تنظیمات بازه ذخیره شد.', 'tasks' => $buildTasksForResponse($tasks)], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'save_task_score_system') {
    $id = trim((string)($_POST['id'] ?? ''));
    if ($id === '') {
      echo json_encode(['status' => 'error', 'message' => 'شناسه بازه نامعتبر است.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $score = tctNormalizeScoreValue($_POST['score'] ?? 0);
    $afterEndtimeScore = tctNormalizeScoreValue($_POST['after_endtime_score'] ?? 0);
    $hasGoldenTime = tctNormalizeBoolValue($_POST['has_golden_time'] ?? true);

    $targetTagCode = '';
    $targetTaskType = 'period';
    foreach ($tasks as $task) {
      if ((string)($task['id'] ?? '') !== $id) {
        continue;
      }
      $targetTagCode = tctNormalizeTagCode((string)($task['tagCode'] ?? ''));
      $targetTaskType = tctNormalizeTaskType((string)($task['taskType'] ?? 'period'));
      break;
    }
    if ($targetTagCode === '') {
      echo json_encode(['status' => 'error', 'message' => 'بازه پیدا نشد.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    if ($targetTaskType === 'period') {
      echo json_encode(['status' => 'error', 'message' => 'بازه سیستم امتیازدهی یا زمان طلایی ندارد.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    if ($targetTaskType === 'conditional_quiz' && !$hasGoldenTime) {
      $afterEndtimeScore = 0;
    } elseif ($targetTaskType !== 'conditional_quiz') {
      $hasGoldenTime = true;
    }

    if ($targetTaskType === 'info' || $targetTaskType === 'team_task' || $targetTaskType === 'describe_photo') {
      $afterEndtimeScore = 0;
    }
    $existingScoreSettings = tctLoadTaskScoreSettings($tctTasksDir, $targetTagCode);

    if (!tctSaveTaskScoreSettings($tctTasksDir, $targetTagCode, [
      'score' => $score,
      'afterEndtimeScore' => $afterEndtimeScore,
      'hasGoldenTime' => $hasGoldenTime,
      'anotherChanceIfZero' => (bool)($existingScoreSettings['anotherChanceIfZero'] ?? false)
    ])) {
      echo json_encode(['status' => 'error', 'message' => 'ذخیره تنظیمات امتیازدهی ناموفق بود.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    echo json_encode(['status' => 'ok', 'message' => 'تنظیمات امتیازدهی ذخیره شد.', 'tasks' => $buildTasksForResponse($tasks)], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'save_conditional_quiz_crisis_control') {
    $id = trim((string)($_POST['id'] ?? ''));
    if ($id === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid task id.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

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
    if ($targetTaskType !== 'conditional_quiz') {
      echo json_encode(['status' => 'error', 'message' => 'Crisis Control is only available for Conditional Quiz tasks.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $existingScoreSettings = tctLoadTaskScoreSettings($tctTasksDir, $targetTagCode);
    $anotherChanceIfZero = tctNormalizeBoolValue($_POST['another_chance_if_zero'] ?? false);
    if (!tctSaveTaskScoreSettings($tctTasksDir, $targetTagCode, [
      'score' => tctNormalizeScoreValue($existingScoreSettings['score'] ?? 0),
      'afterEndtimeScore' => tctNormalizeScoreValue($existingScoreSettings['afterEndtimeScore'] ?? 0),
      'hasGoldenTime' => (bool)($existingScoreSettings['hasGoldenTime'] ?? true),
      'anotherChanceIfZero' => $anotherChanceIfZero
    ])) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to save Crisis Control settings.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    echo json_encode(['status' => 'ok', 'message' => 'Crisis Control settings saved.', 'tasks' => $buildTasksForResponse($tasks)], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'save_team_task_settings') {
    $id = trim((string)($_POST['id'] ?? ''));
    if ($id === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid task id.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

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
    if ($targetTaskType !== 'team_task') {
      echo json_encode(['status' => 'error', 'message' => 'This action is only for Team Task.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $teamMin = tctNormalizeScoreValue($_POST['team_min'] ?? 0);
    $teamMax = tctNormalizeScoreValue($_POST['team_max'] ?? 0);
    $teamAdditionalNote = str_replace(["\r\n", "\r"], "\n", (string)($_POST['team_additional_note'] ?? ''));
    if ($teamMin < 1 || $teamMax < 1) {
      echo json_encode(['status' => 'error', 'message' => 'Team Min and Team Max must be at least 1.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if ($teamMax < $teamMin) {
      echo json_encode(['status' => 'error', 'message' => 'Team Max must be equal to or greater than Team Min.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    if (!tctSaveTaskTeamSettings($tctTasksDir, $targetTagCode, [
      'teamMin' => $teamMin,
      'teamMax' => $teamMax,
      'teamAdditionalNote' => $teamAdditionalNote
    ])) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to save team settings.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    echo json_encode([
      'status' => 'ok',
      'message' => 'Team settings saved.',
      'tasks' => $buildTasksForResponse($tasks)
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'save_info_task_content') {
    $id = trim((string)($_POST['id'] ?? ''));
    if ($id === '') {
      echo json_encode(['status' => 'error', 'message' => 'شناسه بازه نامعتبر است.'], JSON_UNESCAPED_UNICODE);
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
      echo json_encode(['status' => 'error', 'message' => 'بازه پیدا نشد.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $targetTaskType = tctNormalizeTaskType((string)($targetTask['taskType'] ?? 'period'));
    if ($targetTaskType !== 'period' && $targetTaskType !== 'quiz' && $targetTaskType !== 'conditional_quiz' && $targetTaskType !== 'info' && $targetTaskType !== 'team_task' && $targetTaskType !== 'describe_photo') {
      echo json_encode(['status' => 'error', 'message' => 'این عملیات فقط برای بازه‌های دارای بخش اطلاعات مجاز است.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $tagCode = tctNormalizeTagCode((string)($targetTask['tagCode'] ?? ''));
    if ($tagCode === '') {
      echo json_encode(['status' => 'error', 'message' => 'کد یکتای بازه نامعتبر است.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $title = trim((string)($_POST['info_title'] ?? ''));
    $text = str_replace(["\r\n", "\r"], "\n", (string)($_POST['info_text'] ?? ''));
    $guidePrefix = str_replace(["\r\n", "\r"], "\n", (string)($_POST['info_guide_prefix'] ?? ''));
    $guideSuffix = str_replace(["\r\n", "\r"], "\n", (string)($_POST['info_guide_suffix'] ?? ''));
    if (!tctSaveTaskInfoSettings($tctTasksDir, $tagCode, [
      'title' => $title,
      'text' => $text,
      'guidePrefix' => $guidePrefix,
      'guideSuffix' => $guideSuffix
    ])) {
      echo json_encode(['status' => 'error', 'message' => 'ذخیره اطلاعات بازه ناموفق بود.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    echo json_encode([
      'status' => 'ok',
      'message' => 'اطلاعات بازه ذخیره شد.',
      'tasks' => $buildTasksForResponse($tasks)
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
    if (!@egmDbCopy($sourceAbsolutePath, $destinationAbsolutePath)) {
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
      @egmDbUnlink($destinationAbsolutePath);
      echo json_encode(['status' => 'error', 'message' => 'Failed to save photo metadata.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    echo json_encode([
      'status' => 'ok',
      'message' => 'Photo added.',
      'tasks' => $buildTasksForResponse($tasks)
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
      'tasks' => $buildTasksForResponse($tasks)
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
      if (egmDbIsFile($removedAbsolutePath)) {
        @egmDbUnlink($removedAbsolutePath);
      }
    }

    if (!tctSaveTaskDescribePhotos($tctTasksDir, $tagCode, $nextPhotos)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to update photo list.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    echo json_encode([
      'status' => 'ok',
      'message' => 'Photo removed.',
      'tasks' => $buildTasksForResponse($tasks)
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'add_team_task_challenge') {
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
    if ($targetTaskType !== 'team_task') {
      echo json_encode(['status' => 'error', 'message' => 'This action is only for Team Task.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $tagCode = tctNormalizeTagCode((string)($targetTask['tagCode'] ?? ''));
    if ($tagCode === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid task tag code.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $challengeName = trim((string)($_POST['challenge_name'] ?? ''));
    $quantity = max(0, tctNormalizeScoreValue($_POST['quantity'] ?? 0));
    if ($challengeName === '') {
      echo json_encode(['status' => 'error', 'message' => 'Challenge name is required.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if ($quantity < 1) {
      echo json_encode(['status' => 'error', 'message' => 'Challenge quantity must be at least 1.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $challenges = tctLoadTaskTeamChallenges($tctTasksDir, $tagCode);
    $challenges[] = [
      'id' => tctMakeTaskTeamChallengeId(),
      'name' => $challengeName,
      'guide' => '',
      'quantity' => $quantity,
      'last' => $quantity,
      'createdAt' => date('Y-m-d H:i:s')
    ];
    if (!tctSaveTaskTeamChallenges($tctTasksDir, $tagCode, $challenges)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to save challenge list.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    echo json_encode([
      'status' => 'ok',
      'message' => 'Challenge added.',
      'tasks' => $buildTasksForResponse($tasks)
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'save_team_task_challenge') {
    $id = trim((string)($_POST['id'] ?? ''));
    $challengeId = trim((string)($_POST['challenge_id'] ?? ''));
    if ($id === '' || $challengeId === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid challenge payload.'], JSON_UNESCAPED_UNICODE);
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
    if ($targetTaskType !== 'team_task') {
      echo json_encode(['status' => 'error', 'message' => 'This action is only for Team Task.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $tagCode = tctNormalizeTagCode((string)($targetTask['tagCode'] ?? ''));
    if ($tagCode === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid task tag code.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $challengeName = trim((string)($_POST['challenge_name'] ?? ''));
    if ($challengeName === '') {
      echo json_encode(['status' => 'error', 'message' => 'Challenge name is required.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $quantity = max(0, tctNormalizeScoreValue($_POST['quantity'] ?? 0));
    $providedLast = isset($_POST['last']) ? max(0, tctNormalizeScoreValue($_POST['last'])) : null;
    $providedGuide = array_key_exists('challenge_guide', $_POST)
      ? str_replace(["\r\n", "\r"], "\n", (string)($_POST['challenge_guide'] ?? ''))
      : null;

    $challenges = tctLoadTaskTeamChallenges($tctTasksDir, $tagCode);
    $found = false;
    foreach ($challenges as $index => $challenge) {
      if (!is_array($challenge) || trim((string)($challenge['id'] ?? '')) !== $challengeId) {
        continue;
      }
      $nextLast = is_int($providedLast) ? $providedLast : max(0, tctNormalizeScoreValue($challenge['last'] ?? 0));
      if ($quantity === 0) {
        $nextLast = 0;
      } elseif ($nextLast > $quantity) {
        $nextLast = $quantity;
      }
      $challenges[$index]['name'] = $challengeName;
      $challenges[$index]['quantity'] = $quantity;
      $challenges[$index]['last'] = $nextLast;
      if (is_string($providedGuide)) {
        $challenges[$index]['guide'] = $providedGuide;
      }
      $found = true;
      break;
    }
    if (!$found) {
      echo json_encode(['status' => 'error', 'message' => 'Challenge not found.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if (!tctSaveTaskTeamChallenges($tctTasksDir, $tagCode, $challenges)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to update challenge.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    echo json_encode([
      'status' => 'ok',
      'message' => 'Challenge updated.',
      'tasks' => $buildTasksForResponse($tasks)
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'save_team_task_challenge_guide') {
    $id = trim((string)($_POST['id'] ?? ''));
    $challengeId = trim((string)($_POST['challenge_id'] ?? ''));
    if ($id === '' || $challengeId === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid challenge payload.'], JSON_UNESCAPED_UNICODE);
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
    if ($targetTaskType !== 'team_task') {
      echo json_encode(['status' => 'error', 'message' => 'This action is only for Team Task.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $tagCode = tctNormalizeTagCode((string)($targetTask['tagCode'] ?? ''));
    if ($tagCode === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid task tag code.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $guide = str_replace(["\r\n", "\r"], "\n", (string)($_POST['challenge_guide'] ?? ''));
    $challenges = tctLoadTaskTeamChallenges($tctTasksDir, $tagCode);
    $found = false;
    foreach ($challenges as $index => $challenge) {
      if (!is_array($challenge) || trim((string)($challenge['id'] ?? '')) !== $challengeId) {
        continue;
      }
      $challenges[$index]['guide'] = $guide;
      $found = true;
      break;
    }
    if (!$found) {
      echo json_encode(['status' => 'error', 'message' => 'Challenge not found.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if (!tctSaveTaskTeamChallenges($tctTasksDir, $tagCode, $challenges)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to save challenge guide.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    echo json_encode([
      'status' => 'ok',
      'message' => 'Challenge guide saved.',
      'tasks' => $buildTasksForResponse($tasks)
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'remove_team_task_challenge') {
    $id = trim((string)($_POST['id'] ?? ''));
    $challengeId = trim((string)($_POST['challenge_id'] ?? ''));
    if ($id === '' || $challengeId === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid challenge payload.'], JSON_UNESCAPED_UNICODE);
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
    if ($targetTaskType !== 'team_task') {
      echo json_encode(['status' => 'error', 'message' => 'This action is only for Team Task.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $tagCode = tctNormalizeTagCode((string)($targetTask['tagCode'] ?? ''));
    if ($tagCode === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid task tag code.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $challenges = tctLoadTaskTeamChallenges($tctTasksDir, $tagCode);
    $nextChallenges = [];
    $found = false;
    foreach ($challenges as $challenge) {
      if (!is_array($challenge)) {
        continue;
      }
      if (!$found && trim((string)($challenge['id'] ?? '')) === $challengeId) {
        $found = true;
        continue;
      }
      $nextChallenges[] = $challenge;
    }
    if (!$found) {
      echo json_encode(['status' => 'error', 'message' => 'Challenge not found.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if (!tctSaveTaskTeamChallenges($tctTasksDir, $tagCode, $nextChallenges)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to update challenge list.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    echo json_encode([
      'status' => 'ok',
      'message' => 'Challenge removed.',
      'tasks' => $buildTasksForResponse($tasks)
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
    if ($targetTaskType !== 'info' && $targetTaskType !== 'team_task' && $targetTaskType !== 'describe_photo') {
      echo json_encode(['status' => 'error', 'message' => 'This action is only for Info Task.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $tagCode = tctNormalizeTagCode((string)($targetTask['tagCode'] ?? ''));
    if ($tagCode === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid task tag code.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if (!egmDbIsFile($tctEventInviteesPath)) {
      echo json_encode(['status' => 'error', 'message' => 'Invitees mapped file not found in EGM Event.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $scoreSettings = tctLoadTaskScoreSettings($tctTasksDir, $tagCode);
    $maxScore = max(0, (int)($scoreSettings['score'] ?? 0));
    $taskId = trim((string)($targetTask['id'] ?? ''));
    $taskScoreMapByWorkId = tctLoadTaskInfoScores($tctTasksDir, $tagCode);
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
        if ($targetTaskType === 'team_task') {
          $teamMap = tctParseTeamTaskMap((string)($row[$infoTasksIndex] ?? ''));
          $entry = is_array($teamMap[$taskId] ?? null) ? $teamMap[$taskId] : [];
          $infoTaskScoreByWorkId[$workId] = tctNormalizeScoreValue($entry['score'] ?? 0);
        } else {
          $infoMap = tctParseInfoTasksMap((string)($row[$infoTasksIndex] ?? ''));
          $infoTaskScoreByWorkId[$workId] = tctNormalizeScoreValue($infoMap[$taskId] ?? 0);
        }
      }
    }
    if ($targetTaskType === 'team_task') {
      $runtime = tctLoadTaskTeamRuntime($tctTasksDir, $tagCode);
      $teams = is_array($runtime['teams'] ?? null) ? $runtime['teams'] : [];
      $teamSettings = tctLoadTaskTeamSettings($tctTasksDir, $tagCode);
      $teamMin = max(1, (int)($teamSettings['teamMin'] ?? 1));
      $teamMax = max($teamMin, (int)($teamSettings['teamMax'] ?? 1));

      $inviteesByWorkId = [];
      foreach ($invitees as $invitee) {
        $workIdToken = trim((string)($invitee['workId'] ?? ''));
        if ($workIdToken !== '' && !isset($inviteesByWorkId[$workIdToken])) {
          $inviteesByWorkId[$workIdToken] = $invitee;
        }
      }

      $teamRows = [];
      foreach ($teams as $team) {
        if (!is_array($team)) {
          continue;
        }
        $teamId = trim((string)($team['id'] ?? ''));
        $teamName = trim((string)($team['name'] ?? ''));
        if ($teamId === '' || $teamName === '') {
          continue;
        }
        $membersRaw = is_array($team['members'] ?? null) ? $team['members'] : [];
        $members = [];
        foreach ($membersRaw as $memberRaw) {
          $memberId = trim((string)$memberRaw);
          if ($memberId !== '' && !in_array($memberId, $members, true)) {
            $members[] = $memberId;
          }
        }
        $memberPreview = [];
        $memberScores = [];
        $scoreSubmitted = false;
        foreach ($members as $memberId) {
          $profile = is_array($inviteesByWorkId[$memberId] ?? null) ? $inviteesByWorkId[$memberId] : [];
          $memberScore = max(0, min($maxScore, (int)($infoTaskScoreByWorkId[$memberId] ?? 0)));
          $memberScores[] = $memberScore;
          if (!$scoreSubmitted && array_key_exists($memberId, $taskScoreMapByWorkId)) {
            $scoreSubmitted = true;
          }
          $memberPreview[] = [
            'workId' => $memberId,
            'firstName' => trim((string)($profile['firstName'] ?? '')),
            'lastName' => trim((string)($profile['lastName'] ?? '')),
            'phone' => trim((string)($profile['phone'] ?? '')),
            'score' => $memberScore
          ];
        }
        $leaderWorkId = trim((string)($team['leaderWorkId'] ?? ''));
        $leaderProfile = is_array($inviteesByWorkId[$leaderWorkId] ?? null) ? $inviteesByWorkId[$leaderWorkId] : [];
        $leaderName = trim(((string)($leaderProfile['firstName'] ?? '')) . ' ' . ((string)($leaderProfile['lastName'] ?? '')));
        if ($leaderName === '') {
          $leaderName = $leaderWorkId;
        }
        $teamRows[] = [
          'id' => $teamId,
          'name' => $teamName,
          'joinType' => tctNormalizeTeamJoinType((string)($team['joinType'] ?? 'private')),
          'status' => (bool)($team['started'] ?? false) ? 'started' : 'draft',
          'challengeAccepted' => (bool)($team['challengeAccepted'] ?? false),
          'leaderWorkId' => $leaderWorkId,
          'leaderName' => $leaderName,
          'memberCount' => count($members),
          'inviteCount' => count(is_array($team['invites'] ?? null) ? $team['invites'] : []),
          'requestCount' => count(is_array($team['requests'] ?? null) ? $team['requests'] : []),
          'minMembers' => $teamMin,
          'maxMembers' => $teamMax,
          'scoreSubmitted' => $scoreSubmitted,
          'assignedScore' => $memberScores ? max($memberScores) : 0,
          'members' => $memberPreview
        ];
      }
      echo json_encode([
        'status' => 'ok',
        'taskType' => $targetTaskType,
        'maxScore' => $maxScore,
        'teams' => $teamRows
      ], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $describeSubmissionsByWorkId = [];
    if ($targetTaskType === 'describe_photo') {
      $describeSubmissionsByWorkId = tctCollectDescribePhotoSubmissionsByWorkId(
        $tctTasksDir,
        $tagCode,
        $taskId,
        $tctEventInviteesPath,
        $tctEventInviteesMapPath
      );
    }
    $filteredInvitees = [];
    foreach ($invitees as $invitee) {
      $workId = trim((string)($invitee['workId'] ?? ''));
      $invitee['customScore'] = max(0, min($maxScore, (int)($infoTaskScoreByWorkId[$workId] ?? 0)));
      if ($targetTaskType === 'describe_photo') {
        $describeResults = is_array($describeSubmissionsByWorkId[$workId] ?? null)
          ? $describeSubmissionsByWorkId[$workId]
          : [];
        if (!$describeResults) {
          continue;
        }
        $invitee['describeResults'] = array_values($describeResults);
      }
      $filteredInvitees[] = $invitee;
    }
    $invitees = $filteredInvitees;
    echo json_encode([
      'status' => 'ok',
      'taskType' => $targetTaskType,
      'maxScore' => $maxScore,
      'invitees' => $invitees
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'team_task_admin_get_team') {
    $id = trim((string)($_POST['id'] ?? ''));
    $teamId = trim((string)($_POST['team_id'] ?? ''));
    if ($id === '' || $teamId === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid team payload.'], JSON_UNESCAPED_UNICODE);
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
    if ($targetTaskType !== 'team_task') {
      echo json_encode(['status' => 'error', 'message' => 'This action is only for Team Task.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $tagCode = tctNormalizeTagCode((string)($targetTask['tagCode'] ?? ''));
    if ($tagCode === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid task tag code.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if (!egmDbIsFile($tctEventInviteesPath)) {
      echo json_encode(['status' => 'error', 'message' => 'Invitees mapped file not found in EGM Event.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $runtime = tctLoadTaskTeamRuntime($tctTasksDir, $tagCode);
    $teams = is_array($runtime['teams'] ?? null) ? $runtime['teams'] : [];
    $teamIndex = tctFindTaskTeamIndexById($teams, $teamId);
    if ($teamIndex < 0) {
      echo json_encode(['status' => 'error', 'message' => 'Team not found.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $team = is_array($teams[$teamIndex] ?? null) ? $teams[$teamIndex] : [];

    $scoreSettings = tctLoadTaskScoreSettings($tctTasksDir, $tagCode);
    $maxScore = max(0, (int)($scoreSettings['score'] ?? 0));
    $taskId = trim((string)($targetTask['id'] ?? ''));
    $teamSettings = tctLoadTaskTeamSettings($tctTasksDir, $tagCode);
    $teamMin = max(1, (int)($teamSettings['teamMin'] ?? 1));
    $teamMax = max($teamMin, (int)($teamSettings['teamMax'] ?? 1));

    $invitees = tctResolveInviteesForRateTable($tctEventInviteesPath, $tctEventInviteesMapPath);
    $inviteesByWorkId = [];
    foreach ($invitees as $invitee) {
      $workIdToken = trim((string)($invitee['workId'] ?? ''));
      if ($workIdToken !== '' && !isset($inviteesByWorkId[$workIdToken])) {
        $inviteesByWorkId[$workIdToken] = $invitee;
      }
    }

    $eventRows = tctReadCsvRows($tctEventInviteesPath);
    $eventHeader = (isset($eventRows[0]) && is_array($eventRows[0])) ? $eventRows[0] : [];
    $workIdIndex = tctResolveWorkIdIndexFromHeaderAndMap($eventHeader, $tctEventInviteesMapPath);
    $teamTaskIndex = tctFindHeaderIndex($eventHeader, 'Team Task');
    $scoreByWorkId = [];
    if ($workIdIndex >= 0 && $teamTaskIndex >= 0) {
      for ($rowIndex = 1; $rowIndex < count($eventRows); $rowIndex += 1) {
        $row = is_array($eventRows[$rowIndex] ?? null) ? $eventRows[$rowIndex] : [];
        $workId = trim((string)($row[$workIdIndex] ?? ''));
        if ($workId === '') {
          continue;
        }
        $teamMap = tctParseTeamTaskMap((string)($row[$teamTaskIndex] ?? ''));
        $entry = is_array($teamMap[$taskId] ?? null) ? $teamMap[$taskId] : [];
        $scoreByWorkId[$workId] = tctNormalizeScoreValue($entry['score'] ?? 0);
      }
    }

    $buildUserPayload = static function (string $workId, string $status) use ($inviteesByWorkId, $scoreByWorkId, $maxScore): array {
      $profile = is_array($inviteesByWorkId[$workId] ?? null) ? $inviteesByWorkId[$workId] : [];
      return [
        'workId' => $workId,
        'firstName' => trim((string)($profile['firstName'] ?? '')),
        'lastName' => trim((string)($profile['lastName'] ?? '')),
        'phone' => trim((string)($profile['phone'] ?? '')),
        'score' => max(0, min($maxScore, (int)($scoreByWorkId[$workId] ?? 0))),
        'status' => $status
      ];
    };

    $leaderWorkId = trim((string)($team['leaderWorkId'] ?? ''));
    $membersRaw = is_array($team['members'] ?? null) ? $team['members'] : [];
    $members = [];
    foreach ($membersRaw as $memberRaw) {
      $memberId = trim((string)$memberRaw);
      if ($memberId !== '' && !in_array($memberId, $members, true)) {
        $members[] = $memberId;
      }
    }
    if ($leaderWorkId !== '' && !in_array($leaderWorkId, $members, true)) {
      array_unshift($members, $leaderWorkId);
    }
    $memberPayload = [];
    foreach ($members as $memberId) {
      $memberPayload[] = $buildUserPayload($memberId, $memberId === $leaderWorkId ? 'leader' : 'member');
    }
    $invitePayload = [];
    foreach ((array)($team['invites'] ?? []) as $inviteRaw) {
      $inviteId = trim((string)$inviteRaw);
      if ($inviteId === '' || in_array($inviteId, $members, true)) {
        continue;
      }
      $invitePayload[] = $buildUserPayload($inviteId, 'invited');
    }
    $requestPayload = [];
    foreach ((array)($team['requests'] ?? []) as $requestRaw) {
      $requestId = trim((string)$requestRaw);
      if ($requestId === '' || in_array($requestId, $members, true)) {
        continue;
      }
      $requestPayload[] = $buildUserPayload($requestId, 'requested');
    }

    echo json_encode([
      'status' => 'ok',
      'maxScore' => $maxScore,
      'team' => [
        'id' => trim((string)($team['id'] ?? '')),
        'name' => trim((string)($team['name'] ?? '')),
        'joinType' => tctNormalizeTeamJoinType((string)($team['joinType'] ?? 'private')),
        'status' => (bool)($team['started'] ?? false) ? 'started' : 'draft',
        'challengeAccepted' => (bool)($team['challengeAccepted'] ?? false),
        'leaderWorkId' => $leaderWorkId,
        'memberCount' => count($memberPayload),
        'minMembers' => $teamMin,
        'maxMembers' => $teamMax,
        'members' => $memberPayload,
        'invites' => $invitePayload,
        'requests' => $requestPayload
      ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'team_task_admin_update_team') {
    $id = trim((string)($_POST['id'] ?? ''));
    $teamId = trim((string)($_POST['team_id'] ?? ''));
    $teamName = trim((string)($_POST['team_name'] ?? ''));
    if ($id === '' || $teamId === '' || $teamName === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid team update payload.'], JSON_UNESCAPED_UNICODE);
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
    if ($targetTaskType !== 'team_task') {
      echo json_encode(['status' => 'error', 'message' => 'This action is only for Team Task.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $tagCode = tctNormalizeTagCode((string)($targetTask['tagCode'] ?? ''));
    if ($tagCode === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid task tag code.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $runtime = tctLoadTaskTeamRuntime($tctTasksDir, $tagCode);
    $teams = is_array($runtime['teams'] ?? null) ? $runtime['teams'] : [];
    $teamIndex = tctFindTaskTeamIndexById($teams, $teamId);
    if ($teamIndex < 0) {
      echo json_encode(['status' => 'error', 'message' => 'Team not found.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $team = is_array($teams[$teamIndex] ?? null) ? $teams[$teamIndex] : [];
    $members = [];
    foreach ((array)($team['members'] ?? []) as $memberRaw) {
      $memberId = trim((string)$memberRaw);
      if ($memberId !== '' && !in_array($memberId, $members, true)) {
        $members[] = $memberId;
      }
    }
    if (!$members) {
      echo json_encode(['status' => 'error', 'message' => 'Team has no members.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $leaderWorkId = trim((string)($_POST['leader_work_id'] ?? (string)($team['leaderWorkId'] ?? '')));
    if ($leaderWorkId === '') {
      $leaderWorkId = $members[0];
    }
    if (!in_array($leaderWorkId, $members, true)) {
      echo json_encode(['status' => 'error', 'message' => 'Selected team admin must be a member of this team.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $joinType = tctNormalizeTeamJoinType((string)($_POST['join_type'] ?? ($team['joinType'] ?? 'private')));
    $statusToken = strtolower(trim((string)($_POST['team_status'] ?? ((bool)($team['started'] ?? false) ? 'started' : 'draft'))));
    $started = in_array($statusToken, ['started', 'active', '1', 'true'], true);

    $team['name'] = $teamName;
    $team['joinType'] = $joinType;
    $team['leaderWorkId'] = $leaderWorkId;
    $team['started'] = $started;
    if ($started && trim((string)($team['startedAt'] ?? '')) === '') {
      $team['startedAt'] = date('Y-m-d H:i:s');
    }
    $teams[$teamIndex] = $team;

    if (!tctSaveTaskTeamRuntime($tctTasksDir, $tagCode, ['teams' => $teams])) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to update team runtime.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if (egmDbIsFile($tctEventInviteesPath)
      && !tctSyncTeamTaskCsvState($tctEventInviteesPath, $tctEventInviteesMapPath, $id, $teams)) {
      echo json_encode(['status' => 'error', 'message' => 'Team runtime was saved, but invitee team state could not be synchronized.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    echo json_encode([
      'status' => 'ok',
      'message' => 'Team updated.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'team_task_admin_set_challenge_accepted') {
    $id = trim((string)($_POST['id'] ?? ''));
    $teamId = trim((string)($_POST['team_id'] ?? ''));
    if ($id === '' || $teamId === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid challenge accepted payload.'], JSON_UNESCAPED_UNICODE);
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
    if ($targetTaskType !== 'team_task') {
      echo json_encode(['status' => 'error', 'message' => 'This action is only for Team Task.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $tagCode = tctNormalizeTagCode((string)($targetTask['tagCode'] ?? ''));
    if ($tagCode === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid task tag code.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $runtime = tctLoadTaskTeamRuntime($tctTasksDir, $tagCode);
    $teams = is_array($runtime['teams'] ?? null) ? $runtime['teams'] : [];
    $teamIndex = tctFindTaskTeamIndexById($teams, $teamId);
    if ($teamIndex < 0) {
      echo json_encode(['status' => 'error', 'message' => 'Team not found.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $team = is_array($teams[$teamIndex] ?? null) ? $teams[$teamIndex] : [];
    $acceptedToken = strtolower(trim((string)($_POST['challenge_accepted'] ?? '0')));
    $isAccepted = in_array($acceptedToken, ['1', 'true', 'yes', 'on'], true);
    $team['challengeAccepted'] = $isAccepted;
    $teams[$teamIndex] = $team;

    if (!tctSaveTaskTeamRuntime($tctTasksDir, $tagCode, ['teams' => $teams])) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to update challenge accepted state.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if (egmDbIsFile($tctEventInviteesPath)
      && !tctSyncTeamTaskCsvState($tctEventInviteesPath, $tctEventInviteesMapPath, $id, $teams)) {
      echo json_encode(['status' => 'error', 'message' => 'Challenge state was saved, but invitee team state could not be synchronized.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    echo json_encode([
      'status' => 'ok',
      'message' => 'Challenge accepted state updated.',
      'challengeAccepted' => $isAccepted
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'team_task_admin_remove_member') {
    $id = trim((string)($_POST['id'] ?? ''));
    $teamId = trim((string)($_POST['team_id'] ?? ''));
    $workId = trim((string)($_POST['work_id'] ?? ''));
    if ($id === '' || $teamId === '' || $workId === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid member remove payload.'], JSON_UNESCAPED_UNICODE);
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
    if ($targetTaskType !== 'team_task') {
      echo json_encode(['status' => 'error', 'message' => 'This action is only for Team Task.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $tagCode = tctNormalizeTagCode((string)($targetTask['tagCode'] ?? ''));
    if ($tagCode === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid task tag code.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $runtime = tctLoadTaskTeamRuntime($tctTasksDir, $tagCode);
    $teams = is_array($runtime['teams'] ?? null) ? $runtime['teams'] : [];
    $teamIndex = tctFindTaskTeamIndexById($teams, $teamId);
    if ($teamIndex < 0) {
      echo json_encode(['status' => 'error', 'message' => 'Team not found.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $team = is_array($teams[$teamIndex] ?? null) ? $teams[$teamIndex] : [];

    $members = array_values(array_filter((array)($team['members'] ?? []), static fn($value) => trim((string)$value) !== ''));
    $invites = array_values(array_filter((array)($team['invites'] ?? []), static fn($value) => trim((string)$value) !== ''));
    $requests = array_values(array_filter((array)($team['requests'] ?? []), static fn($value) => trim((string)$value) !== ''));
    $beforeCount = count($members) + count($invites) + count($requests);

    $members = array_values(array_filter($members, static fn($value) => trim((string)$value) !== trim($workId)));
    $invites = array_values(array_filter($invites, static fn($value) => trim((string)$value) !== trim($workId)));
    $requests = array_values(array_filter($requests, static fn($value) => trim((string)$value) !== trim($workId)));
    $afterCount = count($members) + count($invites) + count($requests);
    if ($beforeCount === $afterCount) {
      echo json_encode(['status' => 'error', 'message' => 'Member was not found in this team.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    if (!$members) {
      array_splice($teams, $teamIndex, 1);
    } else {
      $leaderWorkId = trim((string)($team['leaderWorkId'] ?? ''));
      if ($leaderWorkId === '' || !in_array($leaderWorkId, $members, true)) {
        $leaderWorkId = $members[0];
      }
      $team['leaderWorkId'] = $leaderWorkId;
      $team['members'] = array_values($members);
      $team['invites'] = array_values($invites);
      $team['requests'] = array_values($requests);
      $teams[$teamIndex] = $team;
    }

    if (!tctSaveTaskTeamRuntime($tctTasksDir, $tagCode, ['teams' => $teams])) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to update team runtime.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if (egmDbIsFile($tctEventInviteesPath)
      && !tctSyncTeamTaskCsvState($tctEventInviteesPath, $tctEventInviteesMapPath, $id, $teams)) {
      echo json_encode(['status' => 'error', 'message' => 'Team runtime was saved, but invitee team state could not be synchronized.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    echo json_encode([
      'status' => 'ok',
      'message' => 'Team member removed.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'get_describe_task_result_text') {
    $id = trim((string)($_POST['id'] ?? ''));
    $workId = trim((string)($_POST['work_id'] ?? ''));
    $photoId = trim((string)($_POST['photo_id'] ?? ''));
    if ($id === '' || $workId === '' || $photoId === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid result payload.'], JSON_UNESCAPED_UNICODE);
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

    $result = tctReadDescribePhotoSubmissionArticle(
      $tctTasksDir,
      $tagCode,
      $id,
      $workId,
      $photoId,
      $tctEventInviteesPath,
      $tctEventInviteesMapPath
    );
    if (!($result['ok'] ?? false)) {
      echo json_encode(['status' => 'error', 'message' => (string)($result['message'] ?? 'Failed to load result text.')], JSON_UNESCAPED_UNICODE);
      exit;
    }

    echo json_encode([
      'status' => 'ok',
      'result' => $result['result'] ?? [],
      'text' => (string)($result['text'] ?? '')
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
    if ($targetTaskType !== 'info' && $targetTaskType !== 'team_task' && $targetTaskType !== 'describe_photo') {
      echo json_encode(['status' => 'error', 'message' => 'This action is only for Info Task.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $tagCode = tctNormalizeTagCode((string)($targetTask['tagCode'] ?? ''));
    if ($tagCode === '') {
      echo json_encode(['status' => 'error', 'message' => 'Invalid task tag code.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if (!egmDbIsFile($tctEventInviteesPath)) {
      echo json_encode(['status' => 'error', 'message' => 'Invitees mapped file not found in EGM Event.'], JSON_UNESCAPED_UNICODE);
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
    $runtimeTeams = [];
    $acceptedByWorkId = [];
    if ($targetTaskType === 'team_task') {
      $runtime = tctLoadTaskTeamRuntime($tctTasksDir, $tagCode);
      $runtimeTeams = is_array($runtime['teams'] ?? null) ? $runtime['teams'] : [];
      foreach ($runtimeTeams as $runtimeTeam) {
        if (!is_array($runtimeTeam)) {
          continue;
        }
        $members = is_array($runtimeTeam['members'] ?? null) ? $runtimeTeam['members'] : [];
        $isAccepted = (bool)($runtimeTeam['challengeAccepted'] ?? false);
        foreach ($members as $memberRaw) {
          $memberId = trim((string)$memberRaw);
          if ($memberId === '') {
            continue;
          }
          if (!isset($acceptedByWorkId[$memberId])) {
            $acceptedByWorkId[$memberId] = $isAccepted;
          } elseif ($isAccepted) {
            $acceptedByWorkId[$memberId] = true;
          }
        }
      }
      $blockedWorkIds = [];
      foreach ($workIds as $selectedWorkId) {
        if (!($acceptedByWorkId[$selectedWorkId] ?? false)) {
          $blockedWorkIds[] = $selectedWorkId;
        }
      }
      if ($blockedWorkIds) {
        echo json_encode([
          'status' => 'error',
          'message' => 'Challenge Accepted must be enabled before scoring this team.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
      }
    }
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
      $previousScore = 0;
      if ($targetTaskType === 'team_task') {
        $teamMap = tctParseTeamTaskMap((string)($row[$infoTasksIndex] ?? ''));
        $currentEntry = is_array($teamMap[$taskId] ?? null) ? $teamMap[$taskId] : [];
        $previousScore = tctNormalizeScoreValue($currentEntry['score'] ?? 0);
      } else {
        $infoMap = tctParseInfoTasksMap((string)($row[$infoTasksIndex] ?? ''));
        $previousScore = tctNormalizeScoreValue($infoMap[$taskId] ?? 0);
      }
      $delta = $assignedScore - $previousScore;
      $currentTotal = tctNormalizeScoreValue($row[$scoreIndex] ?? 0);
      $row[$scoreIndex] = (string)max(0, $currentTotal + $delta);
      if ($targetTaskType === 'team_task') {
        $teamMap = tctParseTeamTaskMap((string)($row[$infoTasksIndex] ?? ''));
        $currentEntry = is_array($teamMap[$taskId] ?? null) ? $teamMap[$taskId] : [];
        $resolved = tctResolveTeamTaskStatusForUser($runtimeTeams, $workId);
        $teamName = trim((string)($resolved['teamName'] ?? ''));
        $status = trim((string)($resolved['status'] ?? ''));
        if ($teamName === '') {
          $teamName = trim((string)($currentEntry['teamName'] ?? ''));
        }
        if ($status === '') {
          $status = trim((string)($currentEntry['status'] ?? ''));
        }
        if ($status === '') {
          $status = 'member';
        }
        $teamMap[$taskId] = [
          'teamName' => $teamName,
          'status' => $status,
          'score' => $assignedScore
        ];
        $row[$infoTasksIndex] = tctSerializeTeamTaskMap($teamMap);
      } else {
        $infoMap = tctParseInfoTasksMap((string)($row[$infoTasksIndex] ?? ''));
        $infoMap[$taskId] = $assignedScore;
        $row[$infoTasksIndex] = tctSerializeInfoTasksMap($infoMap);
      }
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
      'message' => $targetTaskType === 'team_task' ? 'Team members scores updated.' : 'Invitees scores updated.',
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
    foreach ($reordered as $index => $task) {
      if (!is_array($task)) {
        continue;
      }
      $task['order'] = $index + 1;
      $reordered[$index] = $task;
    }
    $tasks = tctReindexTasks($reordered);
    if (!tctSaveStoreTasks($tctStorePath, $tasks)) {
      echo json_encode(['status' => 'error', 'message' => 'ذخیره ترتیب بازه‌ها ناموفق بود.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    echo json_encode(['status' => 'ok', 'message' => 'ترتیب بازه‌ها به‌روزرسانی شد.', 'tasks' => $buildTasksForResponse($tasks)], JSON_UNESCAPED_UNICODE);
    exit;
  }

  echo json_encode(['status' => 'error', 'message' => 'Unsupported action.'], JSON_UNESCAPED_UNICODE);
  exit;
}

if (EGMT_INCLUDE_ONLY) {
  return;
}
?>

<div class="egm-period-management" dir="rtl">
<div class="card">
  <div class="section-header">
    <h3>ایجاد بازه</h3>
  </div>
  <form id="tct-form" class="form" style="gap:12px;">
    <label class="field standard-width">
      <span>نام بازه</span>
      <input id="tct-title" name="title" type="text" autocomplete="off" required />
    </label>
    <div class="field full">
      <button type="submit" class="btn primary standard-primary-button">افزودن بازه</button>
    </div>
    <p id="tct-status" class="muted small" aria-live="polite"></p>
  </form>
</div>

<div class="card">
  <div class="section-header">
    <h3>فهرست بازه‌ها</h3>
  </div>
  <div class="table-wrapper">
    <table class="tct-list-table">
      <thead>
        <tr>
          <th>ترتیب</th>
          <th>نام بازه</th>
          <th>کد یکتا</th>
          <th>عملیات</th>
          <th>جابجایی</th>
        </tr>
      </thead>
      <tbody id="tct-list-body">
        <tr><td colspan="5" class="muted">در حال بارگذاری بازه‌ها...</td></tr>
      </tbody>
    </table>
  </div>
</div>
</div>

<script>
(() => {
  const endpoint = 'mini%20apps/Event%20Guest%20Manager/EGMT.php';
  const csrfToken = <?= json_encode($tctCsrfToken, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  const form = document.getElementById('tct-form');
  const titleInput = document.getElementById('tct-title');
  const statusEl = document.getElementById('tct-status');
  const listBody = document.getElementById('tct-list-body');
  if (!form || !titleInput || !statusEl || !listBody) return;

  let tasks = [];

  const esc = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

  const normalizeTaskType = (value) => {
    return 'period';
  };

  const normalizeScore = (value) => {
    const parsed = Number.parseInt(String(value ?? '').trim(), 10);
    if (!Number.isFinite(parsed) || parsed < 0) {
      return 0;
    }
    return parsed;
  };

  const resolveDefaultTopPanes = (taskType) => {
    return ['control', 'information', 'invite', 'invitees', 'invite-card', 'export'];
  };

  const normalizeAllowedTopPanes = (value, taskType) => {
    const defaults = resolveDefaultTopPanes(taskType);
    const allowedSet = new Set(defaults);
    if (!Array.isArray(value) || !value.length) {
      return defaults;
    }
    const filtered = value
      .map((item) => String(item ?? '').trim().toLowerCase())
      .filter((item) => item && allowedSet.has(item));
    if (!filtered.length) {
      return defaults;
    }
    return Array.from(new Set(filtered));
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
      taskAccessEnabled: task.taskAccessEnabled !== false,
      allowedTopPanes: normalizeAllowedTopPanes(task.allowedTopPanes, task.taskType || 'quiz'),
      active: Boolean(task.active),
      duration: Boolean(task.duration),
      quitRequired: Boolean(task.quitRequired),
      quitTimelineRequired: task.quitTimelineRequired !== false,
      minimumStayMinutes: Math.max(1, Math.min(1440, Number.parseInt(task.minimumStayMinutes, 10) || 1)),
      devPhase: Boolean(task.devPhase),
      startDate: String(task.startDate || ''),
      startTime: String(task.startTime || ''),
      endDate: String(task.endDate || ''),
      endTime: String(task.endTime || ''),
      enterDeadlineDate: String(task.enterDeadlineDate || ''),
      enterDeadlineTime: String(task.enterDeadlineTime || ''),
      quitOpeningDate: String(task.quitOpeningDate || ''),
      quitOpeningTime: String(task.quitOpeningTime || ''),
      endedAt: String(task.endedAt || ''),
      endedBy: String(task.endedBy || ''),
      endedNoQuitResolution: String(task.endedNoQuitResolution || ''),
      endedNoQuitCount: Math.max(0, Number.parseInt(task.endedNoQuitCount, 10) || 0),
      score: normalizeScore(task.score),
      afterEndtimeScore: normalizeScore(task.afterEndtimeScore),
      hasGoldenTime: task.hasGoldenTime !== false,
      anotherChanceIfZero: Boolean(task.anotherChanceIfZero),
      order: Number.parseInt(task.order, 10) || (index + 1),
      createdAt: String(task.createdAt || '')
    }));
    try {
      window.EGM_TASKS = snapshot;
      window.dispatchEvent(new CustomEvent('egmTasksChanged', {
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
      taskAccessEnabled: task.taskAccessEnabled !== false,
      allowedTopPanes: normalizeAllowedTopPanes(task.allowedTopPanes, task.taskType || 'quiz'),
      active: Boolean(task.active),
      duration: Boolean(task.duration),
      quitRequired: Boolean(task.quitRequired),
      quitTimelineRequired: task.quitTimelineRequired !== false,
      minimumStayMinutes: Math.max(1, Math.min(1440, Number.parseInt(task.minimumStayMinutes, 10) || 1)),
      devPhase: Boolean(task.devPhase),
      startDate: String(task.startDate || ''),
      startTime: String(task.startTime || ''),
      endDate: String(task.endDate || ''),
      endTime: String(task.endTime || ''),
      enterDeadlineDate: String(task.enterDeadlineDate || ''),
      enterDeadlineTime: String(task.enterDeadlineTime || ''),
      quitOpeningDate: String(task.quitOpeningDate || ''),
      quitOpeningTime: String(task.quitOpeningTime || ''),
      endedAt: String(task.endedAt || ''),
      endedBy: String(task.endedBy || ''),
      endedNoQuitResolution: String(task.endedNoQuitResolution || ''),
      endedNoQuitCount: Math.max(0, Number.parseInt(task.endedNoQuitCount, 10) || 0),
      score: normalizeScore(task.score),
      afterEndtimeScore: normalizeScore(task.afterEndtimeScore),
      hasGoldenTime: task.hasGoldenTime !== false,
      anotherChanceIfZero: Boolean(task.anotherChanceIfZero),
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
    if (!tasks.every((item) => item.taskAccessEnabled !== false)) {
      return false;
    }
    const fromIndex = tasks.findIndex((item) => item.id === id);
    if (fromIndex < 0) {
      return false;
    }
    if (tasks[fromIndex]?.taskAccessEnabled === false) {
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
      listBody.innerHTML = '<tr><td colspan="5" class="muted">هنوز بازه‌ای ایجاد نشده است.</td></tr>';
      return;
    }
    const visibleTasks = tasks.filter((task) => task.taskAccessEnabled !== false);
    if (!visibleTasks.length) {
      listBody.innerHTML = '<tr><td colspan="5" class="muted">هیچ بازه‌ای در دسترس شما نیست.</td></tr>';
      return;
    }
    const canReorderAll = visibleTasks.length === tasks.length;
    listBody.innerHTML = visibleTasks.map((task, index) => `
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
            <button type="button" class="btn ghost" data-save-title-id="${esc(task.id)}">ذخیره</button>
          </div>
        </td>
        <td><code>${esc(task.tagCode)}</code></td>
        <td>
          <div class="tct-action-wrap">
            <button type="button" class="btn ghost" data-remove-id="${esc(task.id)}">حذف</button>
          </div>
        </td>
        <td>
          <div class="tct-order-actions">
            <button type="button" class="btn ghost" data-move-up-id="${esc(task.id)}" ${(index === 0 || !canReorderAll) ? 'disabled' : ''}>بالا</button>
            <button type="button" class="btn ghost" data-move-down-id="${esc(task.id)}" ${(index === (visibleTasks.length - 1) || !canReorderAll) ? 'disabled' : ''}>پایین</button>
          </div>
          ${canReorderAll ? '' : '<p class="muted small">برای تغییر ترتیب باید به همه بازه‌ها دسترسی داشته باشید.</p>'}
        </td>
      </tr>
    `).join('');
  };

  const postAction = async (action, payload = {}) => {
    const formData = new FormData();
    formData.append('tct_action', action);
    if (csrfToken) {
      formData.append('csrf', csrfToken);
    }
    Object.entries(payload).forEach(([key, value]) => {
      formData.append(key, String(value ?? ''));
    });
    const response = await fetch(endpoint, { method: 'POST', body: formData });
    const data = await response.json();
    if (!response.ok || data?.status !== 'ok') {
      throw new Error(data?.message || 'انجام درخواست ناموفق بود.');
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
    setStatus(data.message || 'ترتیب بازه‌ها به‌روزرسانی شد.');
  };

  const saveTaskTitle = async (id, nextTitle) => {
    const title = String(nextTitle || '').trim();
    if (!id) {
      throw new Error('شناسه بازه نامعتبر است.');
    }
    if (!title) {
      throw new Error('نام بازه الزامی است.');
    }
    const data = await postAction('save_task_title', { id, title });
    setTasks(data.tasks);
    renderTasks();
    emitTasksChanged();
    setStatus(data.message || 'نام بازه ذخیره شد.');
  };

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const title = String(titleInput.value || '').trim();
    if (!title) {
      setStatus('نام بازه الزامی است.', true);
      titleInput.focus();
      return;
    }
    const submitButton = form.querySelector('button[type="submit"]');
    if (submitButton instanceof HTMLButtonElement) {
      submitButton.disabled = true;
    }
    try {
      const data = await postAction('add', { title });
      setTasks(Array.isArray(data.tasks) ? data.tasks : tasks);
      renderTasks();
      emitTasksChanged();
      setStatus(data.message || 'بازه افزوده شد.');
      form.reset();
      titleInput.focus();
    } catch (error) {
      setStatus(error?.message || 'افزودن بازه ناموفق بود.', true);
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
        setStatus(error?.message || 'ذخیره نام بازه ناموفق بود.', true);
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
        setStatus(error?.message || 'ذخیره ترتیب بازه‌ها ناموفق بود.', true);
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
        setStatus(error?.message || 'ذخیره ترتیب بازه‌ها ناموفق بود.', true);
        await syncTasks();
      }
      return;
    }

    const removeBtn = target.closest('[data-remove-id]');
    if (!(removeBtn instanceof HTMLButtonElement)) return;
    const id = removeBtn.getAttribute('data-remove-id') || '';
    if (!id) return;
    if (!window.confirm('این بازه حذف شود؟')) return;
    removeBtn.disabled = true;
    try {
      const data = await postAction('remove', { id });
      setTasks(Array.isArray(data.tasks) ? data.tasks : []);
      renderTasks();
      emitTasksChanged();
      setStatus(data.message || 'بازه حذف شد.');
    } catch (error) {
      setStatus(error?.message || 'حذف بازه ناموفق بود.', true);
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
      setStatus(error?.message || 'بارگذاری بازه‌ها ناموفق بود.', true);
    });
})();
</script>

