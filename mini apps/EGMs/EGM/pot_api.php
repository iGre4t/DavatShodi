<?php
declare(strict_types=1);


require_once __DIR__ . '/egm-database-runtime.php';
require_once __DIR__ . '/../../../api/lib/tab-permissions.php';
require_once __DIR__ . '/egm-security.php';
require_once __DIR__ . '/pot_service.php';

egmSecuritySendCommonHeaders();
egmSecurityHardenSessionSettings();
$user = requireTabPermissionFromSession('event-guest-manager', true);
if (!userHasPermissionId($user, 'event-guest-manager:main')) {
  denyPanelAccess(403, 'You do not have permission to manage Pot draws.', true);
}
header('Content-Type: application/json; charset=UTF-8');

function egmPotRespond(array $payload, int $status = 200): void
{
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  egmPotRespond(['status' => 'error', 'message' => 'Method not allowed.'], 405);
}
$contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') {
  egmPotRespond(['status' => 'error', 'message' => 'Content-Type must be application/json.'], 415);
}
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 16384) {
  egmPotRespond(['status' => 'error', 'message' => 'Request body is too large.'], 413);
}
$rawPayload = (string)egmDbFileGetContents('php://input');
if (strlen($rawPayload) > 16384) {
  egmPotRespond(['status' => 'error', 'message' => 'Request body is too large.'], 413);
}
$payload = json_decode($rawPayload, true);
if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
  egmPotRespond(['status' => 'error', 'message' => 'Invalid JSON request body.'], 400);
}
$payload = is_array($payload) ? $payload : [];
if (!egmSecurityIsValidCsrfToken(trim((string)($payload['csrf'] ?? '')))) {
  egmPotRespond(['status' => 'error', 'message' => 'Invalid CSRF token.'], 403);
}
$levelId = trim((string)($payload['levelId'] ?? ''));
if ($levelId === '' || strlen($levelId) > 128) {
  egmPotRespond(['status' => 'error', 'message' => 'Invalid Pot level ID.'], 400);
}
$level = egmPotLevel($levelId);
if (!$level) egmPotRespond(['status' => 'error', 'message' => 'Pot level was not found.'], 404);
$action = strtolower(trim((string)($payload['action'] ?? 'state')));
if (!in_array($action, ['state', 'roll', 'confirm', 'reset'], true)) {
  egmPotRespond(['status' => 'error', 'message' => 'Unsupported action.'], 400);
}
$winners = egmPotReadWinners($levelId);
$limit = (int)$level['potSettings']['winnerLimit'];
$locked = !empty($level['potSettings']['locked']);

if ($locked && in_array($action, ['roll', 'confirm', 'reset'], true)) {
  egmPotRespond(['status' => 'error', 'message' => 'This Pot is locked. Unlock it in Pot Settings to change the draw.'], 409);
}

if ($action === 'state') {
  $eligible = egmPotEligibleParticipants($level, $winners);
  egmPotRespond([
    'status' => 'ok',
    'level' => $level,
    'winners' => egmPotPublicWinners($winners),
    'eligibleCount' => count($eligible),
    'eligibleParticipants' => egmPotPublicParticipants($eligible)
  ]);
}

if ($action === 'roll') {
  if (count($winners) >= $limit) egmPotRespond(['status' => 'error', 'message' => 'The winner limit has been reached.'], 409);
  $eligible = egmPotEligibleParticipants($level, $winners);
  if (!$eligible) egmPotRespond(['status' => 'error', 'message' => 'No eligible participants remain.'], 409);
  $selected = $eligible[random_int(0, count($eligible) - 1)];
  $_SESSION['egm_pot_pending'][$levelId] = [
    'participantKey' => $selected['key'],
    'createdAt' => time()
  ];
  egmPotRespond(['status' => 'ok', 'participant' => [
    'key' => $selected['key'],
    'code' => $selected['code'],
    'fullName' => $selected['fullName'],
    'workId' => $selected['workId'],
    'score' => $selected['score']
  ]]);
}

if ($action === 'confirm') {
  $pending = is_array($_SESSION['egm_pot_pending'][$levelId] ?? null) ? $_SESSION['egm_pot_pending'][$levelId] : [];
  $participantKey = trim((string)($pending['participantKey'] ?? ''));
  if ($participantKey === '' || time() - (int)($pending['createdAt'] ?? 0) > 3600) {
    egmPotRespond(['status' => 'error', 'message' => 'The pending selection expired. Roll again.'], 409);
  }
  $adminCode = trim((string)($user['code'] ?? ($user['username'] ?? 'admin')));
  try {
    $winners = egmPotMutateWinners($levelId, static function (array $current) use ($level, $participantKey, $limit, $adminCode): array {
      if (count($current) >= $limit) throw new RuntimeException('The winner limit has been reached.');
      $eligible = egmPotEligibleParticipants($level, $current);
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
    egmPotRespond(['status' => 'error', 'message' => $error->getMessage()], 409);
  }
  unset($_SESSION['egm_pot_pending'][$levelId]);
  $eligible = egmPotEligibleParticipants($level, $winners);
  egmPotRespond([
    'status' => 'ok',
    'winners' => egmPotPublicWinners($winners),
    'eligibleCount' => count($eligible),
    'eligibleParticipants' => egmPotPublicParticipants($eligible)
  ]);
}

if ($action === 'reset') {
  try {
    $winners = egmPotMutateWinners($levelId, static fn (array $current): array => []);
  } catch (RuntimeException $error) {
    egmPotRespond(['status' => 'error', 'message' => $error->getMessage()], 500);
  }
  unset($_SESSION['egm_pot_pending'][$levelId]);
  egmPotRespond(['status' => 'ok', 'winners' => [], 'eligibleCount' => count(egmPotEligibleParticipants($level, []))]);
}

egmPotRespond(['status' => 'error', 'message' => 'Unsupported action.'], 400);
