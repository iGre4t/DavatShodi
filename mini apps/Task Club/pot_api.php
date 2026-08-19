<?php
declare(strict_types=1);


require_once __DIR__ . '/tc-database-runtime.php';
require_once __DIR__ . '/../../api/lib/tab-permissions.php';
require_once __DIR__ . '/tc-security.php';
require_once __DIR__ . '/pot_service.php';

tcSecuritySendCommonHeaders();
tcSecurityHardenSessionSettings();
$user = requireTabPermissionFromSession('task-club', true);
if (!userHasPermissionId($user, 'task-club:main')) {
  denyPanelAccess(403, 'You do not have permission to manage Pot draws.', true);
}
header('Content-Type: application/json; charset=UTF-8');

function tcPotRespond(array $payload, int $status = 200): void
{
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  tcPotRespond(['status' => 'error', 'message' => 'Method not allowed.'], 405);
}
$contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') {
  tcPotRespond(['status' => 'error', 'message' => 'Content-Type must be application/json.'], 415);
}
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 16384) {
  tcPotRespond(['status' => 'error', 'message' => 'Request body is too large.'], 413);
}
$rawPayload = (string)tcDbFileGetContents('php://input');
if (strlen($rawPayload) > 16384) {
  tcPotRespond(['status' => 'error', 'message' => 'Request body is too large.'], 413);
}
$payload = json_decode($rawPayload, true);
if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
  tcPotRespond(['status' => 'error', 'message' => 'Invalid JSON request body.'], 400);
}
$payload = is_array($payload) ? $payload : [];
if (!tcSecurityIsValidCsrfToken(trim((string)($payload['csrf'] ?? '')))) {
  tcPotRespond(['status' => 'error', 'message' => 'Invalid CSRF token.'], 403);
}
$levelId = trim((string)($payload['levelId'] ?? ''));
if ($levelId === '' || strlen($levelId) > 128) {
  tcPotRespond(['status' => 'error', 'message' => 'Invalid Pot level ID.'], 400);
}
$level = tcPotLevel($levelId);
if (!$level) tcPotRespond(['status' => 'error', 'message' => 'Pot level was not found.'], 404);
$action = strtolower(trim((string)($payload['action'] ?? 'state')));
if (!in_array($action, ['state', 'roll', 'confirm', 'reset'], true)) {
  tcPotRespond(['status' => 'error', 'message' => 'Unsupported action.'], 400);
}
$winners = tcPotReadWinners($levelId);
$limit = (int)$level['potSettings']['winnerLimit'];
$locked = !empty($level['potSettings']['locked']);

if ($locked && in_array($action, ['roll', 'confirm', 'reset'], true)) {
  tcPotRespond(['status' => 'error', 'message' => 'This Pot is locked. Unlock it in Pot Settings to change the draw.'], 409);
}

if ($action === 'state') {
  $eligible = tcPotEligibleParticipants($level, $winners);
  tcPotRespond([
    'status' => 'ok',
    'level' => $level,
    'winners' => tcPotPublicWinners($winners),
    'eligibleCount' => count($eligible),
    'eligibleParticipants' => tcPotPublicParticipants($eligible)
  ]);
}

if ($action === 'roll') {
  if (count($winners) >= $limit) tcPotRespond(['status' => 'error', 'message' => 'The winner limit has been reached.'], 409);
  $eligible = tcPotEligibleParticipants($level, $winners);
  if (!$eligible) tcPotRespond(['status' => 'error', 'message' => 'No eligible participants remain.'], 409);
  $selected = $eligible[random_int(0, count($eligible) - 1)];
  $_SESSION['tc_pot_pending'][$levelId] = [
    'participantKey' => $selected['key'],
    'createdAt' => time()
  ];
  tcPotRespond(['status' => 'ok', 'participant' => [
    'key' => $selected['key'],
    'code' => $selected['code'],
    'fullName' => $selected['fullName'],
    'workId' => $selected['workId'],
    'score' => $selected['score']
  ]]);
}

if ($action === 'confirm') {
  $pending = is_array($_SESSION['tc_pot_pending'][$levelId] ?? null) ? $_SESSION['tc_pot_pending'][$levelId] : [];
  $participantKey = trim((string)($pending['participantKey'] ?? ''));
  if ($participantKey === '' || time() - (int)($pending['createdAt'] ?? 0) > 3600) {
    tcPotRespond(['status' => 'error', 'message' => 'The pending selection expired. Roll again.'], 409);
  }
  $adminCode = trim((string)($user['code'] ?? ($user['username'] ?? 'admin')));
  try {
    $winners = tcPotMutateWinners($levelId, static function (array $current) use ($level, $participantKey, $limit, $adminCode): array {
      if (count($current) >= $limit) throw new RuntimeException('The winner limit has been reached.');
      $eligible = tcPotEligibleParticipants($level, $current);
      $participant = null;
      foreach ($eligible as $candidate) {
        if (hash_equals((string)$candidate['key'], $participantKey)) {
          $participant = $candidate;
          break;
        }
      }
      if (!$participant) throw new RuntimeException('This participant is no longer eligible.');
      $current[] = [
        'participantKey' => $participant['key'],
        'selectedAt' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format(DATE_ATOM),
        'selectedBy' => $adminCode,
        'levelId' => $level['id'],
        'levelName' => $level['name'],
        'requiredScore' => $level['score'],
        'participant' => $participant
      ];
      return $current;
    });
  } catch (RuntimeException $error) {
    tcPotRespond(['status' => 'error', 'message' => $error->getMessage()], 409);
  }
  unset($_SESSION['tc_pot_pending'][$levelId]);
  $eligible = tcPotEligibleParticipants($level, $winners);
  tcPotRespond([
    'status' => 'ok',
    'winners' => tcPotPublicWinners($winners),
    'eligibleCount' => count($eligible),
    'eligibleParticipants' => tcPotPublicParticipants($eligible)
  ]);
}

if ($action === 'reset') {
  try {
    $winners = tcPotMutateWinners($levelId, static fn (array $current): array => []);
  } catch (RuntimeException $error) {
    tcPotRespond(['status' => 'error', 'message' => $error->getMessage()], 500);
  }
  unset($_SESSION['tc_pot_pending'][$levelId]);
  tcPotRespond(['status' => 'ok', 'winners' => [], 'eligibleCount' => count(tcPotEligibleParticipants($level, []))]);
}

tcPotRespond(['status' => 'error', 'message' => 'Unsupported action.'], 400);
