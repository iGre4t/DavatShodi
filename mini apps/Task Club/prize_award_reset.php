<?php
declare(strict_types=1);

require_once __DIR__ . '/invitees_csv_safety.php';
require_once __DIR__ . '/prize_inventory_store.php';
require_once __DIR__ . '/prize_award_log.php';

function tcPrizeResetHeaderToken(string $value): string
{
  $value = str_replace("\xEF\xBB\xBF", '', trim($value));
  $value = preg_replace('/\p{Cf}+/u', '', $value) ?? $value;
  $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
  $value = preg_replace('/[\s_-]+/u', ' ', $value);
  return is_string($value) ? trim($value) : '';
}

function tcPrizeResetMappedWorkIdColumn(string $inviteesPath, array $header): int
{
  $mappingPath = dirname($inviteesPath) . DIRECTORY_SEPARATOR . 'TC Mapped.json';
  if (!is_file($mappingPath)) return -1;
  $content = file_get_contents($mappingPath);
  $mapping = is_string($content) ? json_decode($content, true) : null;
  if (!is_array($mapping)) return -1;
  foreach (['workId', 'work_id', 'username'] as $key) {
    if (!is_numeric($mapping[$key] ?? null)) continue;
    $index = (int)$mapping[$key];
    if ($index >= 0 && array_key_exists($index, $header)) return $index;
  }
  return -1;
}

function tcPrizeResetUniqueValueColumn(array $rows, string $value): int
{
  $matches = [];
  $width = count(is_array($rows[0] ?? null) ? $rows[0] : []);
  for ($column = 0; $column < $width; $column++) {
    $count = 0;
    for ($row = 1; $row < count($rows); $row++) {
      if (trim((string)($rows[$row][$column] ?? '')) === $value) $count++;
    }
    if ($count === 1) $matches[] = $column;
  }
  return count($matches) === 1 ? (int)$matches[0] : -1;
}

function tcPrizeResetColumn(array $header, array $names): int
{
  $wanted = [];
  foreach ($names as $name) $wanted[tcPrizeResetHeaderToken((string)$name)] = true;
  foreach ($header as $index => $name) {
    if (isset($wanted[tcPrizeResetHeaderToken((string)$name)])) return (int)$index;
  }
  return -1;
}

/** @return list<string>|null */
function tcPrizeResetStoredList(string $raw, bool $prizeNames = false): ?array
{
  $raw = trim($raw);
  if ($raw === '') return [];
  if (str_starts_with($raw, '[')) {
    try {
      $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
      return null;
    }
    if (!is_array($decoded) || (function_exists('array_is_list') && !array_is_list($decoded))) return null;
    $items = $decoded;
  } else {
    $items = explode(',', $raw);
  }
  $result = [];
  foreach ($items as $item) {
    if (!is_scalar($item)) return null;
    $token = trim((string)$item);
    if ($prizeNames && !str_starts_with($raw, '[')) {
      $separator = strpos($token, ':');
      if ($separator !== false && $separator > 0) $token = trim(substr($token, $separator + 1));
    }
    if ($token === '') return null;
    $result[] = $token;
  }
  return $result;
}

function tcPrizeResetSerializeList(array $items): string
{
  $encoded = json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  return is_string($encoded) ? $encoded : '[]';
}

function tcPrizeResetNumber(mixed $value): float
{
  $normalized = preg_replace('/[,\s]+/', '', (string)$value);
  return is_string($normalized) && is_numeric($normalized) ? max(0, (float)$normalized) : 0.0;
}

function tcPrizeResetFindAward(array $entries, string $awardId): ?array
{
  foreach ($entries as $entry) {
    if (is_array($entry) && hash_equals((string)($entry['awardId'] ?? ''), $awardId)) return $entry;
  }
  return null;
}

/** @return array{ok:bool,message:string,alreadyReset?:bool} */
function tcPrizeResetAward(
  string $ledgerPath,
  string $inviteesPath,
  string $inventoryPath,
  string $awardId,
  string $resetBy
): array {
  $awardId = trim($awardId);
  if ($awardId === '' || preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $awardId) !== 1) {
    return ['ok' => false, 'message' => 'The selected award ID is invalid.'];
  }

  $rows = tcInviteesCsvReadRowsForUpdate($inviteesPath);
  if (!$rows || !is_array($rows[0] ?? null)) {
    tcInviteesCsvEndTransaction($inviteesPath);
    return ['ok' => false, 'message' => 'Invitees CSV is unavailable; no data was changed.'];
  }
  $inventory = tcPrizeInventoryReadForUpdate($inventoryPath);
  if (!is_array($inventory)) {
    tcInviteesCsvEndTransaction($inviteesPath);
    return ['ok' => false, 'message' => 'Prize inventory is unavailable; no data was changed.'];
  }
  $release = static function () use ($inventoryPath, $inviteesPath): void {
    tcPrizeInventoryEnd($inventoryPath);
    tcInviteesCsvEndTransaction($inviteesPath);
  };

  $award = tcPrizeResetFindAward(tcPrizeAwardLogRead($ledgerPath), $awardId);
  if (!is_array($award)) {
    $release();
    return ['ok' => false, 'message' => 'The award record was not found.'];
  }
  $status = (string)($award['status'] ?? '');
  if ($status === 'reset') {
    $release();
    return ['ok' => true, 'message' => 'This prize was already reset.', 'alreadyReset' => true];
  }
  if (!in_array($status, ['awarded', 'reset_pending'], true)) {
    $release();
    return ['ok' => false, 'message' => 'This award is not confirmed and cannot be reset.'];
  }

  $header = $rows[0];
  $workId = trim((string)($award['workId'] ?? ''));
  $workIdIndex = tcPrizeResetMappedWorkIdColumn($inviteesPath, $header);
  if ($workIdIndex < 0) $workIdIndex = tcPrizeResetColumn($header, ['Work ID', 'workid', 'work id', 'username', 'employee id', 'personnel code']);
  if ($workIdIndex < 0 && $workId !== '') $workIdIndex = tcPrizeResetUniqueValueColumn($rows, $workId);
  $flipIndex = tcPrizeResetColumn($header, ['Card Flips Count', 'Card Flip Count', 'Card Flips']);
  $prizesIndex = tcPrizeResetColumn($header, ['Each Level Won Prize', 'Each Level Won Prizes', 'Won Prize By Level']);
  $totalIndex = tcPrizeResetColumn($header, ['Total Prize Won', 'Total Prizes Won', 'مجموع جوایز برنده شده']);
  $levelsIndex = tcPrizeResetColumn($header, ['Reward Level Won IDs', 'Reward Levels Won IDs', 'Won Reward Level IDs']);
  $requiredColumns = [
    'Work ID mapping' => $workIdIndex,
    'Card Flips Count' => $flipIndex,
    'Each Level Won Prize' => $prizesIndex,
    'Total Prize Won' => $totalIndex,
    'Reward Level Won IDs' => $levelsIndex
  ];
  $missingColumns = array_keys(array_filter($requiredColumns, static fn(int $index): bool => $index < 0));
  if ($missingColumns) {
    $release();
    return ['ok' => false, 'message' => 'Required CSV column(s) not found: ' . implode(', ', $missingColumns) . '. The CSV was not changed.'];
  }

  $rowIndex = -1;
  for ($index = 1; $index < count($rows); $index++) {
    if (trim((string)($rows[$index][$workIdIndex] ?? '')) === $workId) {
      if ($rowIndex >= 0) {
        $release();
        return ['ok' => false, 'message' => 'Duplicate Work ID rows were found; the CSV was not changed.'];
      }
      $rowIndex = $index;
    }
  }
  if ($rowIndex < 0) {
    $release();
    return ['ok' => false, 'message' => 'The winner row was not found; the CSV was not changed.'];
  }

  $level = is_array($award['level'] ?? null) ? $award['level'] : [];
  $prize = is_array($award['prize'] ?? null) ? $award['prize'] : [];
  $levelId = trim((string)($level['id'] ?? ''));
  $prizeName = trim((string)($prize['name'] ?? ''));
  $levelIds = tcPrizeResetStoredList((string)($rows[$rowIndex][$levelsIndex] ?? ''));
  $prizeNames = tcPrizeResetStoredList((string)($rows[$rowIndex][$prizesIndex] ?? ''), true);
  if ($levelIds === null || $prizeNames === null || count($levelIds) !== count($prizeNames)) {
    $release();
    return ['ok' => false, 'message' => 'Prize history is malformed; the CSV was not changed.'];
  }
  $matches = array_keys($levelIds, $levelId, true);
  if (count($matches) > 1) {
    $release();
    return ['ok' => false, 'message' => 'Duplicate prize-level history was found; the CSV was not changed.'];
  }
  $historyIndex = $matches[0] ?? null;
  if ($historyIndex !== null && (string)($prizeNames[$historyIndex] ?? '') !== $prizeName) {
    $release();
    return ['ok' => false, 'message' => 'The stored prize does not match the award log; the CSV was not changed.'];
  }
  if ($historyIndex === null && $status === 'awarded') {
    $release();
    return ['ok' => false, 'message' => 'The award is missing from the user history; the CSV was not changed.'];
  }

  $prizeId = trim((string)($prize['id'] ?? ''));
  $inventoryName = trim((string)($prize['inventoryName'] ?? ''));
  $inventoryIndex = -1;
  foreach ($inventory as $index => $item) {
    if (!is_array($item)) continue;
    if (($prizeId !== '' && (string)($item['id'] ?? '') === $prizeId)
      || ($prizeId === '' && $inventoryName !== '' && (string)($item['name'] ?? '') === $inventoryName)) {
      $inventoryIndex = (int)$index;
      break;
    }
  }
  if ($inventoryIndex < 0) {
    $release();
    return ['ok' => false, 'message' => 'The original prize inventory item was not found; no data was changed.'];
  }

  if ($status === 'awarded' && !tcPrizeAwardLogUpdate($ledgerPath, $awardId, [
    'status' => 'reset_pending',
    'resetRequestedAt' => gmdate('c'),
    'resetRequestedBy' => $resetBy
  ])) {
    $release();
    return ['ok' => false, 'message' => 'Could not prepare the reset audit record; no data was changed.'];
  }

  if ($historyIndex !== null) {
    array_splice($levelIds, $historyIndex, 1);
    array_splice($prizeNames, $historyIndex, 1);
    $rows[$rowIndex][$levelsIndex] = tcPrizeResetSerializeList($levelIds);
    $rows[$rowIndex][$prizesIndex] = tcPrizeResetSerializeList($prizeNames);
    $rows[$rowIndex][$flipIndex] = (string)max(0, (int)($rows[$rowIndex][$flipIndex] ?? 0) - 1);
    $newTotal = max(0, tcPrizeResetNumber($rows[$rowIndex][$totalIndex] ?? 0) - tcPrizeResetNumber($prize['value'] ?? 0));
    $rows[$rowIndex][$totalIndex] = (string)$newTotal;
    if (!tcInviteesCsvCommitRows($inviteesPath, $rows, false)) {
      tcPrizeAwardLogUpdate($ledgerPath, $awardId, ['status' => 'awarded', 'resetFailure' => 'csv_write_failed']);
      $release();
      return ['ok' => false, 'message' => 'The CSV write failed; the original CSV was preserved.'];
    }
  }

  $resetMarkers = tcPrizeInventoryNormalizePendingResetAwardIds($inventory[$inventoryIndex]['pendingResetAwardIds'] ?? []);
  if (!in_array($awardId, $resetMarkers, true)) {
    $inventory[$inventoryIndex]['last'] = min(
      max(0, (int)($inventory[$inventoryIndex]['quantity'] ?? 0)),
      max(0, (int)($inventory[$inventoryIndex]['last'] ?? 0)) + 1
    );
    $resetMarkers[] = $awardId;
    $inventory[$inventoryIndex]['pendingResetAwardIds'] = tcPrizeInventoryNormalizePendingResetAwardIds($resetMarkers);
    if (!tcPrizeInventoryCommit($inventoryPath, $inventory, false)) {
      $release();
      return ['ok' => false, 'message' => 'The user record was safely reset, but inventory recovery is pending. Press Reset again to finish.'];
    }
  }

  if (!tcPrizeAwardLogUpdate($ledgerPath, $awardId, [
    'status' => 'reset',
    'resetAt' => gmdate('c'),
    'resetBy' => $resetBy,
    'resetFailure' => null
  ])) {
    $release();
    return ['ok' => false, 'message' => 'The reset is safe but audit finalization is pending. Press Reset again to finish.'];
  }
  $inventory[$inventoryIndex]['pendingResetAwardIds'] = array_values(array_filter(
    tcPrizeInventoryNormalizePendingResetAwardIds($inventory[$inventoryIndex]['pendingResetAwardIds'] ?? []),
    static fn(string $id): bool => $id !== $awardId
  ));
  tcPrizeInventoryCommit($inventoryPath, $inventory, false);
  $release();
  return ['ok' => true, 'message' => 'Prize reset successfully. The user may win this level again.'];
}
