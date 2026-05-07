<?php
declare(strict_types=1);

function tcInviteesSpecialAccessNormalizeToken(string $value): string
{
  $token = trim($value);
  return $token === '' ? '' : strtolower($token);
}

function tcInviteesSpecialAccessNormalizeBool($value): bool
{
  if (is_bool($value)) {
    return $value;
  }
  if (is_numeric($value)) {
    return ((int)$value) === 1;
  }
  $token = tcInviteesSpecialAccessNormalizeToken((string)$value);
  return in_array($token, ['1', 'true', 'yes', 'on'], true);
}

function tcInviteesSpecialAccessDefaults(): array
{
  return [
    'manageInvitees' => true,
    'resetInvitee' => true,
    'revealPassword' => true,
    'editInvitee' => true
  ];
}

function tcInviteesSpecialAccessNormalize($raw): array
{
  $defaults = tcInviteesSpecialAccessDefaults();
  $source = is_array($raw) ? $raw : [];
  $normalized = [];
  foreach ($defaults as $key => $defaultValue) {
    $value = $source[$key] ?? $defaultValue;
    $normalized[$key] = tcInviteesSpecialAccessNormalizeBool($value);
  }
  return $normalized;
}

function tcInviteesSpecialAccessForUserCode(string $userCode, ?string $taskAccessPath = null): array
{
  $normalizedCode = tcInviteesSpecialAccessNormalizeToken($userCode);
  if ($normalizedCode === '') {
    return tcInviteesSpecialAccessDefaults();
  }
  $path = $taskAccessPath ?: (__DIR__ . '/tasks/task-access.json');
  if (!is_file($path)) {
    return tcInviteesSpecialAccessDefaults();
  }
  $rawContent = file_get_contents($path);
  $decoded = is_string($rawContent) ? json_decode($rawContent, true) : null;
  $users = is_array($decoded['users'] ?? null) ? $decoded['users'] : [];
  foreach ($users as $rawCode => $entry) {
    if (!is_array($entry)) {
      continue;
    }
    if (tcInviteesSpecialAccessNormalizeToken((string)$rawCode) !== $normalizedCode) {
      continue;
    }
    return tcInviteesSpecialAccessNormalize($entry['inviteesSpecialAccess'] ?? null);
  }
  return tcInviteesSpecialAccessDefaults();
}

function tcInviteesSpecialAccessForPanelUser(array $panelUser, ?string $taskAccessPath = null): array
{
  $userCode = trim((string)($panelUser['code'] ?? ''));
  return tcInviteesSpecialAccessForUserCode($userCode, $taskAccessPath);
}

