<?php
declare(strict_types=1);


require_once __DIR__ . '/tc-database-runtime.php';
/**
 * Transactional storage for Task Club prize inventory.
 *
 * All readers/writers share one sidecar lock. A complete JSON file is prepared
 * and synced before replacing the live file, and the latest valid version is
 * mirrored to a backup. The helper is intentionally restricted to the one
 * inventory filename so a bad caller cannot target unrelated application data.
 */

if (!defined('TC_PRIZE_INVENTORY_FILENAME')) {
  define('TC_PRIZE_INVENTORY_FILENAME', 'TC Prizes.json');
}
if (!defined('TC_PRIZE_INVENTORY_ALLOWED_DIRECTORY')) {
  define('TC_PRIZE_INVENTORY_ALLOWED_DIRECTORY', __DIR__);
}

function tcPrizeInventoryIsAllowedPath(string $path): bool
{
  $expectedDirectory = realpath((string)TC_PRIZE_INVENTORY_ALLOWED_DIRECTORY);
  $actualDirectory = realpath(dirname($path));
  if (!is_string($expectedDirectory) || !is_string($actualDirectory)) {
    return false;
  }
  $sameDirectory = PHP_OS_FAMILY === 'Windows'
    ? strcasecmp($actualDirectory, $expectedDirectory) === 0
    : $actualDirectory === $expectedDirectory;
  $sameFilename = PHP_OS_FAMILY === 'Windows'
    ? strcasecmp(basename($path), (string)TC_PRIZE_INVENTORY_FILENAME) === 0
    : basename($path) === (string)TC_PRIZE_INVENTORY_FILENAME;
  return $sameDirectory && $sameFilename;
}

function tcPrizeInventoryStableId(string $seed, int $index = 0): string
{
  return 'prize_' . substr(hash('sha256', $seed . "\0" . $index), 0, 20);
}

function tcPrizeInventoryArrayIsList(array $value): bool
{
  if (function_exists('array_is_list')) return array_is_list($value);
  if ($value === []) return true;
  return array_keys($value) === range(0, count($value) - 1);
}

function tcPrizeInventoryNormalizePendingAwardIds($value): array
{
  if (!is_array($value)) {
    return [];
  }
  $result = [];
  $seen = [];
  foreach ($value as $item) {
    $id = trim((string)$item);
    if ($id === '' || strlen($id) > 128 || isset($seen[$id])) {
      continue;
    }
    $seen[$id] = true;
    $result[] = $id;
  }
  return $result;
}

function tcPrizeInventoryNormalizePendingResetAwardIds($value): array
{
  return tcPrizeInventoryNormalizePendingAwardIds($value);
}

function tcPrizeInventoryParseNonnegativeInt($value): ?int
{
  if (is_int($value)) return $value >= 0 ? $value : null;
  if (is_float($value)) {
    return is_finite($value) && $value >= 0 && floor($value) === $value && $value <= PHP_INT_MAX
      ? (int)$value
      : null;
  }
  if (!is_string($value)) return null;
  $token = trim($value);
  if ($token === '' || preg_match('/^[0-9]+$/D', $token) !== 1) return null;
  $parsed = filter_var($token, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
  return is_int($parsed) ? $parsed : null;
}

function tcPrizeInventoryNormalizeBool($value): bool
{
  if (is_bool($value)) return $value;
  if (is_int($value) || is_float($value)) return (int)$value === 1;
  return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function tcPrizeInventoryNormalizeRecords(array $records): array
{
  $normalized = [];
  $seenIds = [];
  $reservedIds = [];
  foreach ($records as $record) {
    if (!is_array($record)) continue;
    $candidateId = trim((string)($record['id'] ?? ''));
    if (preg_match('/^[A-Za-z0-9._-]{1,96}$/', $candidateId) && !isset($reservedIds[$candidateId])) {
      $reservedIds[$candidateId] = true;
    }
  }
  foreach (array_values($records) as $index => $record) {
    if (!is_array($record)) {
      continue;
    }
    $name = trim((string)($record['name'] ?? ''));
    if ($name === '') {
      continue;
    }
    $id = trim((string)($record['id'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9._-]{1,96}$/', $id) || isset($seenIds[$id])) {
      $id = tcPrizeInventoryStableId($name . "\0" . (string)($record['onWheelName'] ?? ''), (int)$index);
      while (isset($seenIds[$id]) || isset($reservedIds[$id])) {
        $id = tcPrizeInventoryStableId($id, (int)$index + count($seenIds));
      }
    }
    $seenIds[$id] = true;
    $onWheelName = trim((string)($record['onWheelName'] ?? $name));
    if ($onWheelName === '') {
      $onWheelName = $name;
    }
    $quantity = max(0, (int)($record['quantity'] ?? 0));
    $last = max(0, (int)($record['last'] ?? $quantity));
    $last = min($last, $quantity);
    $value = is_numeric($record['value'] ?? null) ? max(0, (float)$record['value']) : 0.0;
    $normalized[] = [
      'id' => $id,
      'name' => $name,
      'onWheelName' => $onWheelName,
      'quantity' => $quantity,
      'last' => $last,
      'value' => $value,
      'isFake' => tcPrizeInventoryNormalizeBool($record['isFake'] ?? false),
      'pendingAwardIds' => tcPrizeInventoryNormalizePendingAwardIds($record['pendingAwardIds'] ?? []),
      'pendingResetAwardIds' => tcPrizeInventoryNormalizePendingResetAwardIds($record['pendingResetAwardIds'] ?? [])
    ];
  }
  return $normalized;
}

/**
 * Validates every stored row before normalization. This deliberately rejects
 * the whole document instead of allowing normalization to drop a damaged row.
 * Missing IDs are the one supported legacy migration and are persisted while
 * the inventory lock is held by tcPrizeInventoryReadForUpdate().
 */
function tcPrizeInventoryValidateRecords(array $records, bool $allowMissingIds, ?bool &$needsIdMigration = null): bool
{
  $needsIdMigration = false;
  $seenIds = [];
  foreach (array_values($records) as $record) {
    if (!is_array($record)) return false;
    $rawName = $record['name'] ?? null;
    if (!is_scalar($rawName) || trim((string)$rawName) === '') return false;
    if (array_key_exists('onWheelName', $record) && !is_scalar($record['onWheelName'])) return false;

    $id = '';
    if (array_key_exists('id', $record)) {
      if (!is_scalar($record['id'])) return false;
      $id = trim((string)$record['id']);
    }
    if ($id === '') {
      if (!$allowMissingIds) return false;
      $needsIdMigration = true;
    } elseif (!preg_match('/^[A-Za-z0-9._-]{1,96}$/', $id) || isset($seenIds[$id])) {
      return false;
    } else {
      $seenIds[$id] = true;
    }

    foreach (['quantity', 'last'] as $integerField) {
      if (array_key_exists($integerField, $record) && tcPrizeInventoryParseNonnegativeInt($record[$integerField]) === null) {
        return false;
      }
    }
    if (array_key_exists('value', $record)
      && (!is_scalar($record['value']) || !is_numeric($record['value']) || !is_finite((float)$record['value']) || (float)$record['value'] < 0)) {
      return false;
    }
    if (isset($record['quantity'], $record['last']) && (float)$record['last'] > (float)$record['quantity']) {
      return false;
    }
    if (array_key_exists('isFake', $record)) {
      $rawFake = $record['isFake'];
      $validFake = is_bool($rawFake)
        || $rawFake === 0 || $rawFake === 1 || $rawFake === 0.0 || $rawFake === 1.0
        || (is_string($rawFake) && in_array(strtolower(trim($rawFake)), ['0', '1', 'false', 'true', 'no', 'yes', 'off', 'on'], true));
      if (!$validFake) return false;
    }
    if (array_key_exists('pendingAwardIds', $record)) {
      if (!is_array($record['pendingAwardIds']) || !tcPrizeInventoryArrayIsList($record['pendingAwardIds'])) return false;
      $seenMarkers = [];
      foreach ($record['pendingAwardIds'] as $marker) {
        if (!is_scalar($marker)) return false;
        $marker = trim((string)$marker);
        if ($marker === '' || strlen($marker) > 128 || isset($seenMarkers[$marker])) return false;
        $seenMarkers[$marker] = true;
      }
    }
    if (array_key_exists('pendingResetAwardIds', $record)) {
      if (!is_array($record['pendingResetAwardIds']) || !tcPrizeInventoryArrayIsList($record['pendingResetAwardIds'])) return false;
      $seenResetMarkers = [];
      foreach ($record['pendingResetAwardIds'] as $marker) {
        if (!is_scalar($marker)) return false;
        $marker = trim((string)$marker);
        if ($marker === '' || strlen($marker) > 128 || isset($seenResetMarkers[$marker])) return false;
        $seenResetMarkers[$marker] = true;
      }
    }
  }
  return true;
}

function tcPrizeInventoryDecodeFile(string $path, ?bool &$needsIdMigration = null): ?array
{
  $needsIdMigration = false;
  if (!tcDbIsFile($path)) {
    return [];
  }
  $content = tcDbFileGetContents($path);
  if (!is_string($content) || trim($content) === '') {
    return null;
  }
  $trimmedContent = ltrim($content);
  if ($trimmedContent === '' || $trimmedContent[0] !== '[') {
    return null;
  }
  $decoded = json_decode($trimmedContent, true);
  if (!is_array($decoded) || !tcPrizeInventoryArrayIsList($decoded)) {
    return null;
  }
  if (!tcPrizeInventoryValidateRecords($decoded, true, $needsIdMigration)) {
    return null;
  }
  return tcPrizeInventoryNormalizeRecords($decoded);
}

function tcPrizeInventoryVersion(array $records): string
{
  $encoded = json_encode(tcPrizeInventoryNormalizeRecords($records), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  return hash('sha256', is_string($encoded) ? $encoded : '[]');
}

function &tcPrizeInventoryTransactions(): array
{
  if (!isset($GLOBALS['tc_prize_inventory_transactions']) || !is_array($GLOBALS['tc_prize_inventory_transactions'])) {
    $GLOBALS['tc_prize_inventory_transactions'] = [];
  }
  return $GLOBALS['tc_prize_inventory_transactions'];
}

function tcPrizeInventoryTransactionKey(string $path): string
{
  $normalized = str_replace('\\', '/', $path);
  return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
}

function tcPrizeInventoryBegin(string $path): bool
{
  if (!tcPrizeInventoryIsAllowedPath($path)) {
    return false;
  }
  $transactions =& tcPrizeInventoryTransactions();
  $key = tcPrizeInventoryTransactionKey($path);
  if (isset($transactions[$key]) && is_resource($transactions[$key]['handle'] ?? null)) {
    return true;
  }
  $directory = dirname($path);
  if (!is_dir($directory) && !(mkdir($directory, 0755, true) || is_dir($directory))) {
    return false;
  }
  $handle = tcDbFopen($path . '.lock', 'c+b');
  if ($handle === false || !flock($handle, LOCK_EX)) {
    if (is_resource($handle)) fclose($handle);
    return false;
  }
  $rollbackPath = $path . '.rollback';
  if (!tcDbIsFile($path) && tcDbIsFile($rollbackPath)) {
    @tcDbRename($rollbackPath, $path);
  } elseif (tcDbIsFile($path) && tcDbIsFile($rollbackPath)) {
    @tcDbUnlink($rollbackPath);
  }
  $transactions[$key] = ['handle' => $handle, 'path' => $path];
  return true;
}

function tcPrizeInventoryEnd(string $path): void
{
  $transactions =& tcPrizeInventoryTransactions();
  $key = tcPrizeInventoryTransactionKey($path);
  $handle = $transactions[$key]['handle'] ?? null;
  if (is_resource($handle)) {
    flock($handle, LOCK_UN);
    fclose($handle);
  }
  unset($transactions[$key]);
}

function tcPrizeInventoryRecoverFromBackup(string $path, ?bool &$needsIdMigration = null): ?array
{
  $needsIdMigration = false;
  if (tcDbIsFile($path . '.backup-unsynced')) {
    return null;
  }
  $backupPath = $path . '.bak';
  $backup = tcPrizeInventoryDecodeFile($backupPath, $needsIdMigration);
  if ($backup === null || !tcDbIsFile($backupPath)) {
    return null;
  }
  $temporaryPath = tempnam(dirname($path), '.prize-recover-');
  if (!is_string($temporaryPath) || !@tcDbCopy($backupPath, $temporaryPath)) {
    if (is_string($temporaryPath)) @tcDbUnlink($temporaryPath);
    return null;
  }
  if (tcPrizeInventoryDecodeFile($temporaryPath) === null) {
    @tcDbUnlink($temporaryPath);
    return null;
  }
  if (tcDbIsFile($path)) {
    try {
      $corruptSuffix = bin2hex(random_bytes(6));
    } catch (Throwable) {
      $corruptSuffix = str_replace('.', '', uniqid('', true));
    }
    $corruptPath = $path . '.corrupt-' . gmdate('YmdHis') . '-' . $corruptSuffix;
    if (!@tcDbRename($path, $corruptPath)) {
      @tcDbUnlink($temporaryPath);
      return null;
    }
  }
  if (!@tcDbRename($temporaryPath, $path)) {
    @tcDbUnlink($temporaryPath);
    return null;
  }
  return $backup;
}

function tcPrizeInventoryReadForUpdate(string $path): ?array
{
  if (!tcPrizeInventoryBegin($path)) {
    return null;
  }
  $needsIdMigration = false;
  $records = !tcDbIsFile($path) && tcDbIsFile($path . '.bak')
    ? tcPrizeInventoryRecoverFromBackup($path, $needsIdMigration)
    : tcPrizeInventoryDecodeFile($path, $needsIdMigration);
  if ($records === null) {
    $records = tcPrizeInventoryRecoverFromBackup($path, $needsIdMigration);
  }
  if ($records === null) {
    tcPrizeInventoryEnd($path);
    return null;
  }
  if ($needsIdMigration && !tcPrizeInventoryCommit($path, $records, false)) {
    tcPrizeInventoryEnd($path);
    return null;
  }
  if (tcDbIsFile($path)) {
    $backup = tcPrizeInventoryDecodeFile($path . '.bak');
    $backupMatches = tcDbIsFile($path . '.bak') && is_array($backup)
      && hash_equals(tcPrizeInventoryVersion($records), tcPrizeInventoryVersion($backup));
    if (tcDbIsFile($path . '.backup-unsynced') || !$backupMatches) {
      if (!tcPrizeInventoryRefreshBackup($path)) {
        @tcDbFilePutContents($path . '.backup-unsynced', tcPrizeInventoryVersion($records) . PHP_EOL, LOCK_EX);
        tcPrizeInventoryEnd($path);
        return null;
      }
      @tcDbUnlink($path . '.backup-unsynced');
    }
  }
  return $records;
}

function tcPrizeInventoryRefreshBackup(string $path): bool
{
  $backupPath = $path . '.bak';
  $temporaryPath = $backupPath . '.tmp';
  @tcDbUnlink($temporaryPath);
  if (!@tcDbCopy($path, $temporaryPath) || tcPrizeInventoryDecodeFile($temporaryPath) === null) {
    @tcDbUnlink($temporaryPath);
    return false;
  }
  $handle = tcDbFopen($temporaryPath, 'r+b');
  if ($handle === false) {
    @tcDbUnlink($temporaryPath);
    return false;
  }
  $ok = fflush($handle);
  if ($ok && function_exists('fsync')) $ok = fsync($handle);
  fclose($handle);
  if (!$ok) {
    @tcDbUnlink($temporaryPath);
    return false;
  }
  @tcDbUnlink($backupPath);
  if (!@tcDbRename($temporaryPath, $backupPath)) {
    @tcDbUnlink($temporaryPath);
    return false;
  }
  return true;
}

function tcPrizeInventoryCommit(string $path, array $records, bool $endTransaction = true): bool
{
  if (!tcPrizeInventoryBegin($path)) {
    return false;
  }
  $needsIdMigration = false;
  if (!tcPrizeInventoryValidateRecords($records, true, $needsIdMigration)) {
    if ($endTransaction) tcPrizeInventoryEnd($path);
    return false;
  }
  $records = tcPrizeInventoryNormalizeRecords($records);
  $encoded = json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  if (!is_string($encoded)) {
    if ($endTransaction) tcPrizeInventoryEnd($path);
    return false;
  }
  $temporaryPath = tempnam(dirname($path), '.prize-inventory-');
  if (!is_string($temporaryPath) || $temporaryPath === '') {
    if ($endTransaction) tcPrizeInventoryEnd($path);
    return false;
  }
  $handle = tcDbFopen($temporaryPath, 'wb');
  $ok = $handle !== false;
  if ($handle !== false) {
    $payload = $encoded . PHP_EOL;
    $offset = 0;
    while ($ok && $offset < strlen($payload)) {
      $written = fwrite($handle, substr($payload, $offset));
      if (!is_int($written) || $written <= 0) $ok = false;
      else $offset += $written;
    }
    if ($ok) {
      $ok = fflush($handle);
      if ($ok && function_exists('fsync')) $ok = fsync($handle);
    }
    fclose($handle);
  }
  if (!$ok || tcPrizeInventoryDecodeFile($temporaryPath) === null) {
    @tcDbUnlink($temporaryPath);
    if ($endTransaction) tcPrizeInventoryEnd($path);
    return false;
  }

  if (PHP_OS_FAMILY !== 'Windows') {
    $ok = @tcDbRename($temporaryPath, $path);
  } else {
    $rollbackPath = $path . '.rollback';
    @tcDbUnlink($rollbackPath);
    $hadOriginal = tcDbIsFile($path);
    $ok = (!$hadOriginal || @tcDbRename($path, $rollbackPath)) && @tcDbRename($temporaryPath, $path);
    if ($ok) @tcDbUnlink($rollbackPath);
    elseif ($hadOriginal && tcDbIsFile($rollbackPath)) @tcDbRename($rollbackPath, $path);
  }
  if (tcDbIsFile($temporaryPath)) @tcDbUnlink($temporaryPath);
  if ($ok) {
    if (tcPrizeInventoryRefreshBackup($path)) {
      @tcDbUnlink($path . '.backup-unsynced');
    } else {
      @tcDbFilePutContents($path . '.backup-unsynced', tcPrizeInventoryVersion($records) . PHP_EOL, LOCK_EX);
      error_log('Task Club prize inventory backup refresh failed; recovery is fail-closed until it is repaired: ' . $path);
    }
  }
  if ($endTransaction) tcPrizeInventoryEnd($path);
  return $ok;
}

function tcPrizeInventoryReadSnapshot(string $path): ?array
{
  $records = tcPrizeInventoryReadForUpdate($path);
  if ($records !== null) tcPrizeInventoryEnd($path);
  return $records;
}

function tcPrizeInventoryReplaceIfVersion(
  string $path,
  array $records,
  string $expectedVersion,
  ?string &$actualVersion = null,
  ?array &$committedRecords = null,
  ?string &$failureReason = null
): bool
{
  $committedRecords = null;
  $failureReason = null;
  $current = tcPrizeInventoryReadForUpdate($path);
  if ($current === null) {
    $failureReason = 'unavailable';
    return false;
  }
  $actualVersion = tcPrizeInventoryVersion($current);
  if ($expectedVersion === '' || !hash_equals($actualVersion, $expectedVersion)) {
    $failureReason = 'conflict';
    tcPrizeInventoryEnd($path);
    return false;
  }
  // Pending recovery markers are server-owned and must survive an admin edit.
  $pendingById = [];
  $pendingResetsById = [];
  foreach ($current as $item) {
    $pendingById[(string)($item['id'] ?? '')] = tcPrizeInventoryNormalizePendingAwardIds($item['pendingAwardIds'] ?? []);
    $pendingResetsById[(string)($item['id'] ?? '')] = tcPrizeInventoryNormalizePendingResetAwardIds($item['pendingResetAwardIds'] ?? []);
  }
  $normalized = tcPrizeInventoryNormalizeRecords($records);
  $submittedById = [];
  foreach ($normalized as $item) {
    $submittedById[(string)($item['id'] ?? '')] = $item;
  }
  foreach ($current as $currentItem) {
    $currentId = (string)($currentItem['id'] ?? '');
    $markers = tcPrizeInventoryNormalizePendingAwardIds($currentItem['pendingAwardIds'] ?? []);
    $resetMarkers = tcPrizeInventoryNormalizePendingResetAwardIds($currentItem['pendingResetAwardIds'] ?? []);
    if (!$markers && !$resetMarkers) continue;
    if (!isset($submittedById[$currentId])) {
      $failureReason = 'pending_awards';
      tcPrizeInventoryEnd($path);
      return false;
    }
    $submittedComparable = $submittedById[$currentId];
    $currentComparable = $currentItem;
    $submittedComparable['pendingAwardIds'] = [];
    $currentComparable['pendingAwardIds'] = [];
    $submittedComparable['pendingResetAwardIds'] = [];
    $currentComparable['pendingResetAwardIds'] = [];
    if (tcPrizeInventoryVersion([$submittedComparable]) !== tcPrizeInventoryVersion([$currentComparable])) {
      $failureReason = 'pending_awards';
      tcPrizeInventoryEnd($path);
      return false;
    }
  }
  foreach ($normalized as &$item) {
    $id = (string)($item['id'] ?? '');
    $item['pendingAwardIds'] = $pendingById[$id] ?? [];
    $item['pendingResetAwardIds'] = $pendingResetsById[$id] ?? [];
  }
  unset($item);
  $saved = tcPrizeInventoryCommit($path, $normalized, false);
  if ($saved) {
    $committedRecords = $normalized;
    $actualVersion = tcPrizeInventoryVersion($normalized);
  } else {
    $failureReason = 'write_failed';
  }
  tcPrizeInventoryEnd($path);
  return $saved;
}

register_shutdown_function(static function (): void {
  $transactions = array_values(tcPrizeInventoryTransactions());
  foreach ($transactions as $transaction) {
    $path = (string)($transaction['path'] ?? '');
    if ($path !== '') tcPrizeInventoryEnd($path);
  }
});
