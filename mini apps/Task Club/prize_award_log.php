<?php
declare(strict_types=1);

/**
 * Durable JSON ledger for Task Club card-flip awards.
 *
 * The primary file is a bounded active ledger. Older entries are moved to
 * bounded JSON archive shards derived from the primary path. Every managed
 * JSON file has a validated backup, and all replacements are prepared and
 * flushed before they are made visible.
 */

const TC_PRIZE_AWARD_LOG_FILENAME = 'TC Prize Awards Log.json';
const TC_PRIZE_AWARD_LOG_MAX_ACTIVE_ENTRIES = 256;
const TC_PRIZE_AWARD_LOG_ARCHIVE_BATCH_SIZE = 128;
const TC_PRIZE_AWARD_LOG_ARCHIVE_SUFFIX = '.archive';

function tcPrizeAwardLogIsAllowedPath(string $path): bool
{
  return strcasecmp(basename($path), TC_PRIZE_AWARD_LOG_FILENAME) === 0
    && strcasecmp(basename(dirname($path)), 'TC Event') === 0;
}

function tcPrizeAwardLogSamePath(string $left, string $right): bool
{
  $normalise = static function (string $path): string {
    return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
  };
  $left = $normalise($left);
  $right = $normalise($right);
  return PHP_OS_FAMILY === 'Windows' ? strcasecmp($left, $right) === 0 : $left === $right;
}

function tcPrizeAwardLogArchiveDirectory(string $path): string
{
  return $path . TC_PRIZE_AWARD_LOG_ARCHIVE_SUFFIX;
}

function tcPrizeAwardLogIsManagedDataPath(string $mainPath, string $candidate): bool
{
  if (!tcPrizeAwardLogIsAllowedPath($mainPath)) {
    return false;
  }
  if (tcPrizeAwardLogSamePath($mainPath, $candidate)) {
    return true;
  }
  return tcPrizeAwardLogSamePath(dirname($candidate), tcPrizeAwardLogArchiveDirectory($mainPath))
    && preg_match('/^[0-9]{8}\.json$/D', basename($candidate)) === 1;
}

function tcPrizeAwardLogIsManagedWritablePath(string $mainPath, string $candidate): bool
{
  if (tcPrizeAwardLogIsManagedDataPath($mainPath, $candidate)) {
    return true;
  }
  if (str_ends_with(strtolower($candidate), '.bak')) {
    return tcPrizeAwardLogIsManagedDataPath($mainPath, substr($candidate, 0, -4));
  }
  return false;
}

function tcPrizeAwardLogArrayIsList(array $value): bool
{
  if (function_exists('array_is_list')) {
    return array_is_list($value);
  }
  $expectedKey = 0;
  foreach ($value as $key => $_item) {
    if ($key !== $expectedKey) {
      return false;
    }
    $expectedKey++;
  }
  return true;
}

/** @return array<int, array<string, mixed>>|null */
function tcPrizeAwardLogValidateEntries(mixed $decoded): ?array
{
  if (!is_array($decoded) || !tcPrizeAwardLogArrayIsList($decoded)) {
    return null;
  }

  $seenAwardIds = [];
  foreach ($decoded as $entry) {
    if (!is_array($entry) || tcPrizeAwardLogArrayIsList($entry)) {
      return null;
    }
    $awardId = $entry['awardId'] ?? null;
    if (!is_string($awardId) || trim($awardId) === '' || trim($awardId) !== $awardId) {
      return null;
    }
    if (array_key_exists('status', $entry) && !is_string($entry['status'])) {
      return null;
    }
    $key = 'award:' . $awardId;
    if (isset($seenAwardIds[$key])) {
      return null;
    }
    $seenAwardIds[$key] = true;
  }
  return $decoded;
}

/** @return array<int, array<string, mixed>>|null */
function tcPrizeAwardLogDecodeFile(string $path): ?array
{
  if (!is_file($path) || is_link($path)) {
    return null;
  }
  $content = file_get_contents($path);
  if (!is_string($content) || trim($content) === '') {
    return null;
  }

  // json_decode() turns some numeric-keyed objects into PHP lists. Checking
  // the source delimiter ensures an object-shaped ledger is never accepted.
  $trimmed = ltrim($content);
  if ($trimmed === '' || $trimmed[0] !== '[') {
    return null;
  }
  try {
    $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
  } catch (JsonException) {
    return null;
  }
  return tcPrizeAwardLogValidateEntries($decoded);
}

function tcPrizeAwardLogWriteAll(string $path, string $payload): bool
{
  $handle = @fopen($path, 'xb');
  if ($handle === false) {
    return false;
  }
  $offset = 0;
  $length = strlen($payload);
  $ok = true;
  while ($offset < $length) {
    $written = fwrite($handle, substr($payload, $offset));
    if (!is_int($written) || $written <= 0) {
      $ok = false;
      break;
    }
    $offset += $written;
  }
  if ($ok) {
    $ok = fflush($handle);
    if ($ok && function_exists('fsync')) {
      $ok = fsync($handle);
    }
  }
  fclose($handle);
  if (!$ok) {
    @unlink($path);
  }
  return $ok;
}

function tcPrizeAwardLogTemporaryPath(string $directory, string $prefix): ?string
{
  if (!in_array($prefix, ['.tc-prize-awards-', '.tc-prize-backup-'], true)) {
    return null;
  }
  try {
    $randomSuffix = bin2hex(random_bytes(16));
  } catch (Throwable) {
    return null;
  }
  $temporaryPath = $directory . DIRECTORY_SEPARATOR . $prefix . $randomSuffix . '.tmp';
  return file_exists($temporaryPath) || is_link($temporaryPath) ? null : $temporaryPath;
}

function tcPrizeAwardLogIsPreparedTemporaryPath(string $temporaryPath, string $targetPath): bool
{
  return tcPrizeAwardLogSamePath(dirname($temporaryPath), dirname($targetPath))
    && preg_match('/^\.tc-prize-(?:awards|backup)-[a-f0-9]{32}\.tmp$/D', basename($temporaryPath)) === 1
    && is_file($temporaryPath)
    && !is_link($temporaryPath);
}

/**
 * Replaces one already-authorised managed file with a prepared temporary file.
 * On Windows a two-slot rollback scheme keeps the previous file recoverable if
 * the process stops between the two renames.
 */
function tcPrizeAwardLogReplacePrepared(string $mainPath, string $temporaryPath, string $targetPath): bool
{
  $temporaryPathIsManaged = tcPrizeAwardLogIsPreparedTemporaryPath($temporaryPath, $targetPath);
  if (!$temporaryPathIsManaged
    || !tcPrizeAwardLogIsManagedWritablePath($mainPath, $targetPath)
    || is_link($targetPath)
    || (file_exists($targetPath) && !is_file($targetPath))) {
    if ($temporaryPathIsManaged) {
      @unlink($temporaryPath);
    }
    return false;
  }

  if (PHP_OS_FAMILY !== 'Windows') {
    $replaced = @rename($temporaryPath, $targetPath);
    if (!$replaced) {
      @unlink($temporaryPath);
    }
    return $replaced;
  }

  $rollbackPath = null;
  foreach ([$targetPath . '.replace-a', $targetPath . '.replace-b'] as $candidate) {
    if (!file_exists($candidate) && !is_link($candidate)) {
      $rollbackPath = $candidate;
      break;
    }
  }
  if ($rollbackPath === null) {
    @unlink($temporaryPath);
    return false;
  }

  $hadOriginal = is_file($targetPath);
  if ($hadOriginal && !@rename($targetPath, $rollbackPath)) {
    @unlink($temporaryPath);
    return false;
  }
  if (@rename($temporaryPath, $targetPath)) {
    if ($hadOriginal) {
      @unlink($rollbackPath);
    }
    return true;
  }
  if ($hadOriginal && is_file($rollbackPath)) {
    @rename($rollbackPath, $targetPath);
  }
  @unlink($temporaryPath);
  return false;
}

function tcPrizeAwardLogRefreshManagedBackup(string $mainPath, string $dataPath): bool
{
  if (!tcPrizeAwardLogIsManagedDataPath($mainPath, $dataPath) || is_link($dataPath)) {
    return false;
  }
  $entries = tcPrizeAwardLogDecodeFile($dataPath);
  if ($entries === null) {
    return false;
  }
  $directory = dirname($dataPath);
  $temporaryPath = tcPrizeAwardLogTemporaryPath($directory, '.tc-prize-backup-');
  if ($temporaryPath === null || !@copy($dataPath, $temporaryPath)) {
    if (is_string($temporaryPath)) {
      @unlink($temporaryPath);
    }
    return false;
  }
  $handle = @fopen($temporaryPath, 'r+b');
  if ($handle === false) {
    @unlink($temporaryPath);
    return false;
  }
  $ok = fflush($handle);
  if ($ok && function_exists('fsync')) {
    $ok = fsync($handle);
  }
  fclose($handle);
  if (!$ok || tcPrizeAwardLogDecodeFile($temporaryPath) === null) {
    @unlink($temporaryPath);
    return false;
  }
  return tcPrizeAwardLogReplacePrepared($mainPath, $temporaryPath, $dataPath . '.bak');
}

function tcPrizeAwardLogRefreshBackup(string $path): bool
{
  return tcPrizeAwardLogIsAllowedPath($path)
    && tcPrizeAwardLogRefreshManagedBackup($path, $path);
}

/** @param array<int, array<string, mixed>> $entries */
function tcPrizeAwardLogWriteManaged(string $mainPath, string $dataPath, array $entries, bool $allowInvalidCurrent = false): bool
{
  if (!tcPrizeAwardLogIsManagedDataPath($mainPath, $dataPath)
    || tcPrizeAwardLogValidateEntries($entries) === null
    || is_link($dataPath)) {
    return false;
  }
  $directory = dirname($dataPath);
  if (is_link($directory)
    || (!is_dir($directory) && !(@mkdir($directory, 0755, true) || is_dir($directory)))) {
    return false;
  }
  if (!$allowInvalidCurrent && file_exists($dataPath) && tcPrizeAwardLogDecodeFile($dataPath) === null) {
    return false;
  }

  try {
    $json = json_encode(
      array_values($entries),
      JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
    );
  } catch (JsonException) {
    return false;
  }
  $temporaryPath = tcPrizeAwardLogTemporaryPath($directory, '.tc-prize-awards-');
  if ($temporaryPath === null
    || !tcPrizeAwardLogWriteAll($temporaryPath, $json . PHP_EOL)
    || tcPrizeAwardLogDecodeFile($temporaryPath) === null) {
    if (is_string($temporaryPath)) {
      @unlink($temporaryPath);
    }
    return false;
  }
  if (!tcPrizeAwardLogReplacePrepared($mainPath, $temporaryPath, $dataPath)) {
    return false;
  }

  // A committed primary is useful even if backup media has a transient error,
  // but callers are told the operation failed until the second durable copy is
  // confirmed. Retried idempotent updates repair the backup and then succeed.
  for ($attempt = 0; $attempt < 3; $attempt++) {
    if (tcPrizeAwardLogRefreshManagedBackup($mainPath, $dataPath)) {
      return true;
    }
    usleep(10000 * ($attempt + 1));
  }
  return false;
}

function tcPrizeAwardLogWritePrepared(string $path, array $entries): bool
{
  return tcPrizeAwardLogIsAllowedPath($path)
    && tcPrizeAwardLogWriteManaged($path, $path, $entries);
}

/** @return list<string> */
function tcPrizeAwardLogRecoveryCandidates(string $dataPath): array
{
  return [
    $dataPath . '.bak',
    $dataPath . '.bak.replace-a',
    $dataPath . '.bak.replace-b',
    $dataPath . '.replace-a',
    $dataPath . '.replace-b',
    $dataPath . '.rollback' // Compatibility with the original implementation.
  ];
}

/** @return array<int, array<string, mixed>>|null */
function tcPrizeAwardLogLoadManaged(string $mainPath, string $dataPath, bool $allowNew): ?array
{
  if (!tcPrizeAwardLogIsManagedDataPath($mainPath, $dataPath) || is_link($dataPath)) {
    return null;
  }

  $entries = tcPrizeAwardLogDecodeFile($dataPath);
  if ($entries !== null) {
    foreach ([$dataPath . '.replace-a', $dataPath . '.replace-b', $dataPath . '.rollback'] as $stalePath) {
      if (is_file($stalePath) && !is_link($stalePath)) {
        @unlink($stalePath);
      }
    }
    // Repair a missing or malformed backup while the valid primary is locked.
    if (tcPrizeAwardLogDecodeFile($dataPath . '.bak') === null) {
      tcPrizeAwardLogRefreshManagedBackup($mainPath, $dataPath);
    }
    return $entries;
  }

  $dataExists = file_exists($dataPath) || is_link($dataPath);
  foreach (tcPrizeAwardLogRecoveryCandidates($dataPath) as $candidate) {
    if (is_link($candidate)) {
      continue;
    }
    $backupEntries = tcPrizeAwardLogDecodeFile($candidate);
    if ($backupEntries === null) {
      continue;
    }
    if (tcPrizeAwardLogWriteManaged($mainPath, $dataPath, $backupEntries, true)) {
      return $backupEntries;
    }
    // The primary replacement may have committed even if refreshing its backup
    // failed. Accept it only after validating the bytes now at the target.
    $recoveredEntries = tcPrizeAwardLogDecodeFile($dataPath);
    if ($recoveredEntries !== null) {
      return $recoveredEntries;
    }
  }

  // A genuinely new main ledger may start empty. Existing empty, truncated,
  // object-shaped, symlinked, or otherwise invalid files always fail closed.
  if ($allowNew && !$dataExists) {
    $hasRecoveryArtifact = false;
    foreach (tcPrizeAwardLogRecoveryCandidates($dataPath) as $candidate) {
      if (file_exists($candidate) || is_link($candidate)) {
        $hasRecoveryArtifact = true;
        break;
      }
    }
    if (!$hasRecoveryArtifact) {
      return [];
    }
  }
  return null;
}

/** @return resource|null */
function tcPrizeAwardLogAcquireLock(string $path)
{
  if (!tcPrizeAwardLogIsAllowedPath($path)
    || is_link($path)
    || is_link($path . '.lock')) {
    return null;
  }
  $directory = dirname($path);
  if (is_link($directory)
    || (!is_dir($directory) && !(@mkdir($directory, 0755, true) || is_dir($directory)))) {
    return null;
  }
  $lock = @fopen($path . '.lock', 'c+b');
  if ($lock === false) {
    return null;
  }
  if (!flock($lock, LOCK_EX)) {
    fclose($lock);
    return null;
  }
  return $lock;
}

/** @param resource $lock */
function tcPrizeAwardLogReleaseLock($lock): void
{
  flock($lock, LOCK_UN);
  fclose($lock);
}

/** @return list<string>|null */
function tcPrizeAwardLogArchivePaths(string $path): ?array
{
  $archiveDirectory = tcPrizeAwardLogArchiveDirectory($path);
  if (is_link($archiveDirectory)) {
    return null;
  }
  if (!is_dir($archiveDirectory)) {
    return file_exists($archiveDirectory) ? null : [];
  }

  $paths = [];
  foreach (scandir($archiveDirectory) ?: [] as $name) {
    if (preg_match('/^([0-9]{8}\.json)(?:$|\.(?:bak|rollback|replace-[ab])(?:\.replace-[ab])?$)/D', $name, $matches) !== 1) {
      continue;
    }
    $paths[$matches[1]] = $archiveDirectory . DIRECTORY_SEPARATOR . $matches[1];
  }
  ksort($paths, SORT_STRING);
  return array_values($paths);
}

/** @return array<string, array<int, array<string, mixed>>>|null */
function tcPrizeAwardLogLoadArchives(string $path): ?array
{
  $archivePaths = tcPrizeAwardLogArchivePaths($path);
  if ($archivePaths === null) {
    return null;
  }
  $archives = [];
  foreach ($archivePaths as $archivePath) {
    $entries = tcPrizeAwardLogLoadManaged($path, $archivePath, false);
    if ($entries === null) {
      return null;
    }
    $archives[$archivePath] = $entries;
  }
  return $archives;
}

/**
 * @param array<string, array<int, array<string, mixed>>> $archives
 * @param array<int, array<string, mixed>> $activeEntries
 * @return array<int, array<string, mixed>>
 */
function tcPrizeAwardLogConsolidate(array $archives, array $activeEntries): array
{
  $order = [];
  $byAwardId = [];
  foreach ([...array_values($archives), $activeEntries] as $entries) {
    foreach ($entries as $entry) {
      $key = 'award:' . (string)$entry['awardId'];
      if (!array_key_exists($key, $byAwardId)) {
        $order[] = $key;
      }
      // Later shards and the active ledger win after an interrupted rotation.
      $byAwardId[$key] = $entry;
    }
  }
  return array_values(array_map(static fn(string $key): array => $byAwardId[$key], $order));
}

/** @param array<string, array<int, array<string, mixed>>> $archives */
function tcPrizeAwardLogContainsAwardId(array $archives, array $activeEntries, string $awardId): bool
{
  foreach ([...array_values($archives), $activeEntries] as $entries) {
    foreach ($entries as $entry) {
      if ((string)$entry['awardId'] === $awardId) {
        return true;
      }
    }
  }
  return false;
}

function tcPrizeAwardLogNextArchivePath(string $path): ?string
{
  $archivePaths = tcPrizeAwardLogArchivePaths($path);
  if ($archivePaths === null) {
    return null;
  }
  $highest = 0;
  foreach ($archivePaths as $archivePath) {
    $highest = max($highest, (int)pathinfo($archivePath, PATHINFO_FILENAME));
  }
  if ($highest >= 99999999) {
    return null;
  }
  return tcPrizeAwardLogArchiveDirectory($path) . DIRECTORY_SEPARATOR
    . sprintf('%08d.json', $highest + 1);
}

/** @param array<int, array<string, mixed>> $activeEntries */
function tcPrizeAwardLogRotate(string $path, array &$activeEntries): bool
{
  while (count($activeEntries) > TC_PRIZE_AWARD_LOG_MAX_ACTIVE_ENTRIES) {
    $archiveIndexes = [];
    foreach ($activeEntries as $index => $_entry) {
      $archiveIndexes[] = $index;
      if (count($archiveIndexes) >= TC_PRIZE_AWARD_LOG_ARCHIVE_BATCH_SIZE) {
        break;
      }
    }

    $selected = array_fill_keys($archiveIndexes, true);
    $archiveEntries = [];
    $remainingEntries = [];
    foreach ($activeEntries as $index => $entry) {
      if (isset($selected[$index])) {
        $archiveEntries[] = $entry;
      } else {
        $remainingEntries[] = $entry;
      }
    }
    $archivePath = tcPrizeAwardLogNextArchivePath($path);
    if ($archivePath === null
      || !tcPrizeAwardLogWriteManaged($path, $archivePath, $archiveEntries)) {
      return false;
    }
    // The shard is durable before the active copy is removed. Pending entries
    // remain fully updateable in their bounded shard. A crash between these
    // commits can only create duplicates, which Read safely de-duplicates.
    $activeEntries = $remainingEntries;
  }
  return true;
}

function tcPrizeAwardLogAppend(string $path, array $entry): bool
{
  $validatedEntry = tcPrizeAwardLogValidateEntries([$entry]);
  if ($validatedEntry === null || !tcPrizeAwardLogIsAllowedPath($path)) {
    return false;
  }
  $awardId = trim((string)$entry['awardId']);
  $lock = tcPrizeAwardLogAcquireLock($path);
  if ($lock === null) {
    return false;
  }

  try {
    $activeEntries = tcPrizeAwardLogLoadManaged($path, $path, true);
    $archives = tcPrizeAwardLogLoadArchives($path);
    if ($activeEntries === null || $archives === null
      || tcPrizeAwardLogContainsAwardId($archives, $activeEntries, $awardId)) {
      return false;
    }
    $activeEntries[] = $entry;
    if (!tcPrizeAwardLogRotate($path, $activeEntries)) {
      return false;
    }
    return tcPrizeAwardLogWriteManaged($path, $path, $activeEntries);
  } finally {
    tcPrizeAwardLogReleaseLock($lock);
  }
}

/** @return 'ok'|'not-found'|'failed' */
function tcPrizeAwardLogUpdateOnce(string $path, string $awardId, array $changes): string
{
  $lock = tcPrizeAwardLogAcquireLock($path);
  if ($lock === null) {
    return 'failed';
  }
  try {
    $activeEntries = tcPrizeAwardLogLoadManaged($path, $path, true);
    $archives = tcPrizeAwardLogLoadArchives($path);
    if ($activeEntries === null || $archives === null) {
      return 'failed';
    }

    $targetPath = null;
    $targetEntries = null;
    foreach ($activeEntries as $index => $entry) {
      if ((string)$entry['awardId'] === $awardId) {
        $targetPath = $path;
        $targetEntries = $activeEntries;
        $targetIndex = $index;
        break;
      }
    }
    if ($targetPath === null) {
      foreach (array_reverse($archives, true) as $archivePath => $archiveEntries) {
        foreach ($archiveEntries as $index => $entry) {
          if ((string)$entry['awardId'] === $awardId) {
            $targetPath = $archivePath;
            $targetEntries = $archiveEntries;
            $targetIndex = $index;
            break 2;
          }
        }
      }
    }
    if ($targetPath === null || !is_array($targetEntries) || !isset($targetIndex)) {
      return 'not-found';
    }

    $updatedEntry = array_merge($targetEntries[$targetIndex], $changes);
    // An update may never rename an award and thereby defeat duplicate checks.
    $updatedEntry['awardId'] = $awardId;
    if (tcPrizeAwardLogValidateEntries([$updatedEntry]) === null) {
      return 'failed';
    }
    if ($updatedEntry === $targetEntries[$targetIndex]) {
      return tcPrizeAwardLogRefreshManagedBackup($path, $targetPath) ? 'ok' : 'failed';
    }
    $targetEntries[$targetIndex] = $updatedEntry;
    return tcPrizeAwardLogWriteManaged($path, $targetPath, $targetEntries) ? 'ok' : 'failed';
  } finally {
    tcPrizeAwardLogReleaseLock($lock);
  }
}

function tcPrizeAwardLogUpdate(string $path, string $awardId, array $changes): bool
{
  $awardId = trim($awardId);
  if ($awardId === '' || !tcPrizeAwardLogIsAllowedPath($path)) {
    return false;
  }
  for ($attempt = 0; $attempt < 3; $attempt++) {
    $result = tcPrizeAwardLogUpdateOnce($path, $awardId, $changes);
    if ($result === 'ok') {
      return true;
    }
    if ($result === 'not-found') {
      return false;
    }
    usleep(20000 * ($attempt + 1));
  }
  return false;
}

/** @return array<int, array<string, mixed>> */
function tcPrizeAwardLogRead(string $path): array
{
  if (!tcPrizeAwardLogIsAllowedPath($path)) {
    return [];
  }
  $lock = tcPrizeAwardLogAcquireLock($path);
  if ($lock === null) {
    return [];
  }
  try {
    $activeEntries = tcPrizeAwardLogLoadManaged($path, $path, true);
    $archives = tcPrizeAwardLogLoadArchives($path);
    if ($activeEntries === null || $archives === null) {
      return [];
    }
    return tcPrizeAwardLogConsolidate($archives, $activeEntries);
  } finally {
    tcPrizeAwardLogReleaseLock($lock);
  }
}
