<?php
declare(strict_types=1);


require_once __DIR__ . '/egm-database-runtime.php';
/**
 * Transactional storage for Event Guest Manager prize inventory.
 *
 * All readers/writers share one sidecar lock. A complete JSON file is prepared
 * and synced before replacing the live file, and the latest valid version is
 * mirrored to a backup. The helper is intentionally restricted to the one
 * inventory filename so a bad caller cannot target unrelated application data.
 */

if (!defined('EGM_PRIZE_INVENTORY_FILENAME')) {
  define('EGM_PRIZE_INVENTORY_FILENAME', 'EGM Prizes.json');
}
if (!defined('EGM_PRIZE_INVENTORY_ALLOWED_DIRECTORY')) {
  define('EGM_PRIZE_INVENTORY_ALLOWED_DIRECTORY', __DIR__);
}

function egmPrizeInventoryIsAllowedPath(string $path): bool
{
  $expectedDirectory = realpath((string)EGM_PRIZE_INVENTORY_ALLOWED_DIRECTORY);
  $actualDirectory = realpath(dirname($path));
  if (!is_string($expectedDirectory) || !is_string($actualDirectory)) {
    return false;
  }
  $sameDirectory = PHP_OS_FAMILY === 'Windows'
    ? strcasecmp($actualDirectory, $expectedDirectory) === 0
    : $actualDirectory === $expectedDirectory;
  $sameFilename = PHP_OS_FAMILY === 'Windows'
    ? strcasecmp(basename($path), (string)EGM_PRIZE_INVENTORY_FILENAME) === 0
    : basename($path) === (string)EGM_PRIZE_INVENTORY_FILENAME;
  return $sameDirectory && $sameFilename;
}

function egmPrizeInventoryStableId(string $seed, int $index = 0): string
{
  return 'prize_' . substr(hash('sha256', $seed . "\0" . $index), 0, 20);
}

function egmPrizeInventoryArrayIsList(array $value): bool
{
  if (function_exists('array_is_list')) return array_is_list($value);
  if ($value === []) return true;
  return array_keys($value) === range(0, count($value) - 1);
}

function egmPrizeInventoryNormalizePendingAwardIds($value): array
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

function egmPrizeInventoryParseNonnegativeInt($value): ?int
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

function egmPrizeInventoryNormalizeBool($value): bool
{
  if (is_bool($value)) return $value;
  if (is_int($value) || is_float($value)) return (int)$value === 1;
  return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function egmPrizeInventoryNormalizeRecords(array $records): array
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
      $id = egmPrizeInventoryStableId($name . "\0" . (string)($record['onWheelName'] ?? ''), (int)$index);
      while (isset($seenIds[$id]) || isset($reservedIds[$id])) {
        $id = egmPrizeInventoryStableId($id, (int)$index + count($seenIds));
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
      'isFake' => egmPrizeInventoryNormalizeBool($record['isFake'] ?? false),
      'pendingAwardIds' => egmPrizeInventoryNormalizePendingAwardIds($record['pendingAwardIds'] ?? [])
    ];
  }
  return $normalized;
}

/**
 * Validates every stored row before normalization. This deliberately rejects
 * the whole document instead of allowing normalization to drop a damaged row.
 * Missing IDs are the one supported legacy migration and are persisted while
 * the inventory lock is held by egmPrizeInventoryReadForUpdate().
 */
function egmPrizeInventoryValidateRecords(array $records, bool $allowMissingIds, ?bool &$needsIdMigration = null): bool
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
      if (array_key_exists($integerField, $record) && egmPrizeInventoryParseNonnegativeInt($record[$integerField]) === null) {
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
      if (!is_array($record['pendingAwardIds']) || !egmPrizeInventoryArrayIsList($record['pendingAwardIds'])) return false;
      $seenMarkers = [];
      foreach ($record['pendingAwardIds'] as $marker) {
        if (!is_scalar($marker)) return false;
        $marker = trim((string)$marker);
        if ($marker === '' || strlen($marker) > 128 || isset($seenMarkers[$marker])) return false;
        $seenMarkers[$marker] = true;
      }
    }
  }
  return true;
}

function egmPrizeInventoryDecodeFile(string $path, ?bool &$needsIdMigration = null): ?array
{
  $needsIdMigration = false;
  if (!egmDbIsFile($path)) {
    return [];
  }
  $content = egmDbFileGetContents($path);
  if (!is_string($content) || trim($content) === '') {
    return null;
  }
  $trimmedContent = ltrim($content);
  if ($trimmedContent === '' || $trimmedContent[0] !== '[') {
    return null;
  }
  $decoded = json_decode($trimmedContent, true);
  if (!is_array($decoded) || !egmPrizeInventoryArrayIsList($decoded)) {
    return null;
  }
  if (!egmPrizeInventoryValidateRecords($decoded, true, $needsIdMigration)) {
    return null;
  }
  return egmPrizeInventoryNormalizeRecords($decoded);
}

function egmPrizeInventoryVersion(array $records): string
{
  $encoded = json_encode(egmPrizeInventoryNormalizeRecords($records), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  return hash('sha256', is_string($encoded) ? $encoded : '[]');
}

function &egmPrizeInventoryTransactions(): array
{
  if (!isset($GLOBALS['egm_prize_inventory_transactions']) || !is_array($GLOBALS['egm_prize_inventory_transactions'])) {
    $GLOBALS['egm_prize_inventory_transactions'] = [];
  }
  return $GLOBALS['egm_prize_inventory_transactions'];
}

function egmPrizeInventoryTransactionKey(string $path): string
{
  $normalized = str_replace('\\', '/', $path);
  return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
}

function egmPrizeInventoryBegin(string $path): bool
{
  if (!egmPrizeInventoryIsAllowedPath($path)) {
    return false;
  }
  $transactions =& egmPrizeInventoryTransactions();
  $key = egmPrizeInventoryTransactionKey($path);
  if (isset($transactions[$key]) && is_resource($transactions[$key]['handle'] ?? null)) {
    return true;
  }
  $directory = dirname($path);
  if (!is_dir($directory) && !(mkdir($directory, 0755, true) || is_dir($directory))) {
    return false;
  }
  $handle = egmDbFopen($path . '.lock', 'c+b');
  if ($handle === false || !flock($handle, LOCK_EX)) {
    if (is_resource($handle)) fclose($handle);
    return false;
  }
  $rollbackPath = $path . '.rollback';
  if (!egmDbIsFile($path) && egmDbIsFile($rollbackPath)) {
    @egmDbRename($rollbackPath, $path);
  } elseif (egmDbIsFile($path) && egmDbIsFile($rollbackPath)) {
    @egmDbUnlink($rollbackPath);
  }
  $transactions[$key] = ['handle' => $handle, 'path' => $path];
  return true;
}

function egmPrizeInventoryEnd(string $path): void
{
  $transactions =& egmPrizeInventoryTransactions();
  $key = egmPrizeInventoryTransactionKey($path);
  $handle = $transactions[$key]['handle'] ?? null;
  if (is_resource($handle)) {
    flock($handle, LOCK_UN);
    fclose($handle);
  }
  unset($transactions[$key]);
}

function egmPrizeInventoryRecoverFromBackup(string $path, ?bool &$needsIdMigration = null): ?array
{
  $needsIdMigration = false;
  if (egmDbIsFile($path . '.backup-unsynced')) {
    return null;
  }
  $backupPath = $path . '.bak';
  $backup = egmPrizeInventoryDecodeFile($backupPath, $needsIdMigration);
  if ($backup === null || !egmDbIsFile($backupPath)) {
    return null;
  }
  $temporaryPath = tempnam(dirname($path), '.prize-recover-');
  if (!is_string($temporaryPath) || !@egmDbCopy($backupPath, $temporaryPath)) {
    if (is_string($temporaryPath)) @egmDbUnlink($temporaryPath);
    return null;
  }
  if (egmPrizeInventoryDecodeFile($temporaryPath) === null) {
    @egmDbUnlink($temporaryPath);
    return null;
  }
  if (egmDbIsFile($path)) {
    try {
      $corruptSuffix = bin2hex(random_bytes(6));
    } catch (Throwable) {
      $corruptSuffix = str_replace('.', '', uniqid('', true));
    }
    $corruptPath = $path . '.corrupt-' . gmdate('YmdHis') . '-' . $corruptSuffix;
    if (!@egmDbRename($path, $corruptPath)) {
      @egmDbUnlink($temporaryPath);
      return null;
    }
  }
  if (!@egmDbRename($temporaryPath, $path)) {
    @egmDbUnlink($temporaryPath);
    return null;
  }
  return $backup;
}

function egmPrizeInventoryReadForUpdate(string $path): ?array
{
  if (!egmPrizeInventoryBegin($path)) {
    return null;
  }
  $needsIdMigration = false;
  $records = !egmDbIsFile($path) && egmDbIsFile($path . '.bak')
    ? egmPrizeInventoryRecoverFromBackup($path, $needsIdMigration)
    : egmPrizeInventoryDecodeFile($path, $needsIdMigration);
  if ($records === null) {
    $records = egmPrizeInventoryRecoverFromBackup($path, $needsIdMigration);
  }
  if ($records === null) {
    egmPrizeInventoryEnd($path);
    return null;
  }
  if ($needsIdMigration && !egmPrizeInventoryCommit($path, $records, false)) {
    egmPrizeInventoryEnd($path);
    return null;
  }
  if (egmDbIsFile($path)) {
    $backup = egmPrizeInventoryDecodeFile($path . '.bak');
    $backupMatches = egmDbIsFile($path . '.bak') && is_array($backup)
      && hash_equals(egmPrizeInventoryVersion($records), egmPrizeInventoryVersion($backup));
    if (egmDbIsFile($path . '.backup-unsynced') || !$backupMatches) {
      if (!egmPrizeInventoryRefreshBackup($path)) {
        @egmDbFilePutContents($path . '.backup-unsynced', egmPrizeInventoryVersion($records) . PHP_EOL, LOCK_EX);
        egmPrizeInventoryEnd($path);
        return null;
      }
      @egmDbUnlink($path . '.backup-unsynced');
    }
  }
  return $records;
}

function egmPrizeInventoryRefreshBackup(string $path): bool
{
  $backupPath = $path . '.bak';
  $temporaryPath = $backupPath . '.tmp';
  @egmDbUnlink($temporaryPath);
  if (!@egmDbCopy($path, $temporaryPath) || egmPrizeInventoryDecodeFile($temporaryPath) === null) {
    @egmDbUnlink($temporaryPath);
    return false;
  }
  $handle = egmDbFopen($temporaryPath, 'r+b');
  if ($handle === false) {
    @egmDbUnlink($temporaryPath);
    return false;
  }
  $ok = fflush($handle);
  if ($ok && function_exists('fsync')) $ok = fsync($handle);
  fclose($handle);
  if (!$ok) {
    @egmDbUnlink($temporaryPath);
    return false;
  }
  @egmDbUnlink($backupPath);
  if (!@egmDbRename($temporaryPath, $backupPath)) {
    @egmDbUnlink($temporaryPath);
    return false;
  }
  return true;
}

function egmPrizeInventoryCommit(string $path, array $records, bool $endTransaction = true): bool
{
  if (!egmPrizeInventoryBegin($path)) {
    return false;
  }
  $needsIdMigration = false;
  if (!egmPrizeInventoryValidateRecords($records, true, $needsIdMigration)) {
    if ($endTransaction) egmPrizeInventoryEnd($path);
    return false;
  }
  $records = egmPrizeInventoryNormalizeRecords($records);
  $encoded = json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  if (!is_string($encoded)) {
    if ($endTransaction) egmPrizeInventoryEnd($path);
    return false;
  }
  $temporaryPath = tempnam(dirname($path), '.prize-inventory-');
  if (!is_string($temporaryPath) || $temporaryPath === '') {
    if ($endTransaction) egmPrizeInventoryEnd($path);
    return false;
  }
  $handle = egmDbFopen($temporaryPath, 'wb');
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
  if (!$ok || egmPrizeInventoryDecodeFile($temporaryPath) === null) {
    @egmDbUnlink($temporaryPath);
    if ($endTransaction) egmPrizeInventoryEnd($path);
    return false;
  }

  if (PHP_OS_FAMILY !== 'Windows') {
    $ok = @egmDbRename($temporaryPath, $path);
  } else {
    $rollbackPath = $path . '.rollback';
    @egmDbUnlink($rollbackPath);
    $hadOriginal = egmDbIsFile($path);
    $ok = (!$hadOriginal || @egmDbRename($path, $rollbackPath)) && @egmDbRename($temporaryPath, $path);
    if ($ok) @egmDbUnlink($rollbackPath);
    elseif ($hadOriginal && egmDbIsFile($rollbackPath)) @egmDbRename($rollbackPath, $path);
  }
  if (egmDbIsFile($temporaryPath)) @egmDbUnlink($temporaryPath);
  if ($ok) {
    if (egmPrizeInventoryRefreshBackup($path)) {
      @egmDbUnlink($path . '.backup-unsynced');
    } else {
      @egmDbFilePutContents($path . '.backup-unsynced', egmPrizeInventoryVersion($records) . PHP_EOL, LOCK_EX);
      error_log('Event Guest Manager prize inventory backup refresh failed; recovery is fail-closed until it is repaired: ' . $path);
    }
  }
  if ($endTransaction) egmPrizeInventoryEnd($path);
  return $ok;
}

function egmPrizeInventoryReadSnapshot(string $path): ?array
{
  $records = egmPrizeInventoryReadForUpdate($path);
  if ($records !== null) egmPrizeInventoryEnd($path);
  return $records;
}

function egmPrizeInventoryReplaceIfVersion(
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
  $current = egmPrizeInventoryReadForUpdate($path);
  if ($current === null) {
    $failureReason = 'unavailable';
    return false;
  }
  $actualVersion = egmPrizeInventoryVersion($current);
  if ($expectedVersion === '' || !hash_equals($actualVersion, $expectedVersion)) {
    $failureReason = 'conflict';
    egmPrizeInventoryEnd($path);
    return false;
  }
  // Pending recovery markers are server-owned and must survive an admin edit.
  $pendingById = [];
  foreach ($current as $item) {
    $pendingById[(string)($item['id'] ?? '')] = egmPrizeInventoryNormalizePendingAwardIds($item['pendingAwardIds'] ?? []);
  }
  $normalized = egmPrizeInventoryNormalizeRecords($records);
  $submittedById = [];
  foreach ($normalized as $item) {
    $submittedById[(string)($item['id'] ?? '')] = $item;
  }
  foreach ($current as $currentItem) {
    $currentId = (string)($currentItem['id'] ?? '');
    $markers = egmPrizeInventoryNormalizePendingAwardIds($currentItem['pendingAwardIds'] ?? []);
    if (!$markers) continue;
    if (!isset($submittedById[$currentId])) {
      $failureReason = 'pending_awards';
      egmPrizeInventoryEnd($path);
      return false;
    }
    $submittedComparable = $submittedById[$currentId];
    $currentComparable = $currentItem;
    $submittedComparable['pendingAwardIds'] = [];
    $currentComparable['pendingAwardIds'] = [];
    if (egmPrizeInventoryVersion([$submittedComparable]) !== egmPrizeInventoryVersion([$currentComparable])) {
      $failureReason = 'pending_awards';
      egmPrizeInventoryEnd($path);
      return false;
    }
  }
  foreach ($normalized as &$item) {
    $id = (string)($item['id'] ?? '');
    $item['pendingAwardIds'] = $pendingById[$id] ?? [];
  }
  unset($item);
  $saved = egmPrizeInventoryCommit($path, $normalized, false);
  if ($saved) {
    $committedRecords = $normalized;
    $actualVersion = egmPrizeInventoryVersion($normalized);
  } else {
    $failureReason = 'write_failed';
  }
  egmPrizeInventoryEnd($path);
  return $saved;
}

register_shutdown_function(static function (): void {
  $transactions = array_values(egmPrizeInventoryTransactions());
  foreach ($transactions as $transaction) {
    $path = (string)($transaction['path'] ?? '');
    if ($path !== '') egmPrizeInventoryEnd($path);
  }
});
