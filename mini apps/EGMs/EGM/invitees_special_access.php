<?php
declare(strict_types=1);


require_once __DIR__ . '/egm-database-runtime.php';
function egmInviteesSpecialAccessNormalizeToken(string $value): string
{
  $token = trim($value);
  return $token === '' ? '' : strtolower($token);
}

function egmInviteesSpecialAccessNormalizeBool($value): bool
{
  if (is_bool($value)) {
    return $value;
  }
  if (is_numeric($value)) {
    return ((int)$value) === 1;
  }
  $token = egmInviteesSpecialAccessNormalizeToken((string)$value);
  return in_array($token, ['1', 'true', 'yes', 'on'], true);
}

function egmInviteesSpecialAccessDefaults(): array
{
  return [
    'manageInvitees' => true,
    'resetInvitee' => true,
    'revealPassword' => true,
    'editInvitee' => true
  ];
}

function egmInviteesSpecialAccessNormalize($raw): array
{
  $defaults = egmInviteesSpecialAccessDefaults();
  $source = is_array($raw) ? $raw : [];
  $normalized = [];
  foreach ($defaults as $key => $defaultValue) {
    $value = $source[$key] ?? $defaultValue;
    $normalized[$key] = egmInviteesSpecialAccessNormalizeBool($value);
  }
  return $normalized;
}

function egmInviteesSpecialAccessForUserCode(string $userCode, ?string $taskAccessPath = null): array
{
  $normalizedCode = egmInviteesSpecialAccessNormalizeToken($userCode);
  if ($normalizedCode === '') {
    return egmInviteesSpecialAccessDefaults();
  }
  $path = $taskAccessPath ?: (__DIR__ . '/tasks/task-access.json');
  if (!egmDbIsFile($path)) {
    return egmInviteesSpecialAccessDefaults();
  }
  $rawContent = egmDbFileGetContents($path);
  $decoded = is_string($rawContent) ? json_decode($rawContent, true) : null;
  $users = is_array($decoded['users'] ?? null) ? $decoded['users'] : [];
  foreach ($users as $rawCode => $entry) {
    if (!is_array($entry)) {
      continue;
    }
    if (egmInviteesSpecialAccessNormalizeToken((string)$rawCode) !== $normalizedCode) {
      continue;
    }
    return egmInviteesSpecialAccessNormalize($entry['inviteesSpecialAccess'] ?? null);
  }
  return egmInviteesSpecialAccessDefaults();
}

function egmInviteesSpecialAccessForPanelUser(array $panelUser, ?string $taskAccessPath = null): array
{
  $userCode = trim((string)($panelUser['code'] ?? ''));
  return egmInviteesSpecialAccessForUserCode($userCode, $taskAccessPath);
}

