<?php
declare(strict_types=1);


require_once __DIR__ . '/egm-database-runtime.php';
/**
 * Durable JSON ledger for Event Guest Manager card-flip awards.
 *
 * The primary file is a bounded active ledger. Older entries are moved to
 * bounded JSON archive shards derived from the primary path. Every managed
 * JSON file has a validated backup, and all replacements are prepared and
 * flushed before they are made visible.
 */

const EGM_PRIZE_AWARD_LOG_FILENAME = 'EGM Prize Awards Log.json';
const EGM_PRIZE_AWARD_LOG_MAX_ACTIVE_ENTRIES = 256;
const EGM_PRIZE_AWARD_LOG_ARCHIVE_BATCH_SIZE = 128;
const EGM_PRIZE_AWARD_LOG_ARCHIVE_SUFFIX = '.archive';

function egmPrizeAwardLogIsAllowedPath(string $path): bool
{
  return strcasecmp(basename($path), EGM_PRIZE_AWARD_LOG_FILENAME) === 0
    && strcasecmp(basename(dirname($path)), 'EGM Event') === 0;
}

function egmPrizeAwardLogSamePath(string $left, string $right): bool
{
  $normalise = static function (string $path): string {
    return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
  };
  $left = $normalise($left);
  $right = $normalise($right);
  return PHP_OS_FAMILY === 'Windows' ? strcasecmp($left, $right) === 0 : $left === $right;
}

function egmPrizeAwardLogArchiveDirectory(string $path): string
{
  return $path . EGM_PRIZE_AWARD_LOG_ARCHIVE_SUFFIX;
}

function egmPrizeAwardLogIsManagedDataPath(string $mainPath, string $candidate): bool
{
  if (!egmPrizeAwardLogIsAllowedPath($mainPath)) {
    return false;
  }
  if (egmPrizeAwardLogSamePath($mainPath, $candidate)) {
    return true;
  }
  return egmPrizeAwardLogSamePath(dirname($candidate), egmPrizeAwardLogArchiveDirectory($mainPath))
    && preg_match('/^[0-9]{8}\.json$/D', basename($candidate)) === 1;
}

function egmPrizeAwardLogIsManagedWritablePath(string $mainPath, string $candidate): bool
{
  if (egmPrizeAwardLogIsManagedDataPath($mainPath, $candidate)) {
    return true;
  }
  if (str_ends_with(strtolower($candidate), '.bak')) {
    return egmPrizeAwardLogIsManagedDataPath($mainPath, substr($candidate, 0, -4));
  }
  return false;
}

function egmPrizeAwardLogArrayIsList(array $value): bool
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
function egmPrizeAwardLogValidateEntries(mixed $decoded): ?array
{
  if (!is_array($decoded) || !egmPrizeAwardLogArrayIsList($decoded)) {
    return null;
  }

  $seenAwardIds = [];
  foreach ($decoded as $entry) {
    if (!is_array($entry) || egmPrizeAwardLogArrayIsList($entry)) {
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
function egmPrizeAwardLogDecodeFile(string $path): ?array
{
  if (!egmDbIsFile($path) || is_link($path)) {
    return null;
  }
  $content = egmDbFileGetContents($path);
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
  return egmPrizeAwardLogValidateEntries($decoded);
}

function egmPrizeAwardLogWriteAll(string $path, string $payload): bool
{
  $handle = @egmDbFopen($path, 'xb');
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
    @egmDbUnlink($path);
  }
  return $ok;
}

function egmPrizeAwardLogTemporaryPath(string $directory, string $prefix): ?string
{
  if (!in_array($prefix, ['.egm-prize-awards-', '.egm-prize-backup-'], true)) {
    return null;
  }
  try {
    $randomSuffix = bin2hex(random_bytes(16));
  } catch (Throwable) {
    return null;
  }
  $temporaryPath = $directory . DIRECTORY_SEPARATOR . $prefix . $randomSuffix . '.tmp';
  return egmDbFileExists($temporaryPath) || is_link($temporaryPath) ? null : $temporaryPath;
}

function egmPrizeAwardLogIsPreparedTemporaryPath(string $temporaryPath, string $targetPath): bool
{
  return egmPrizeAwardLogSamePath(dirname($temporaryPath), dirname($targetPath))
    && preg_match('/^\.egm-prize-(?:awards|backup)-[a-f0-9]{32}\.tmp$/D', basename($temporaryPath)) === 1
    && egmDbIsFile($temporaryPath)
    && !is_link($temporaryPath);
}

/**
 * Replaces one already-authorised managed file with a prepared temporary file.
 * On Windows a two-slot rollback scheme keeps the previous file recoverable if
 * the process stops between the two renames.
 */
function egmPrizeAwardLogReplacePrepared(string $mainPath, string $temporaryPath, string $targetPath): bool
{
  $temporaryPathIsManaged = egmPrizeAwardLogIsPreparedTemporaryPath($temporaryPath, $targetPath);
  if (!$temporaryPathIsManaged
    || !egmPrizeAwardLogIsManagedWritablePath($mainPath, $targetPath)
    || is_link($targetPath)
    || (egmDbFileExists($targetPath) && !egmDbIsFile($targetPath))) {
    if ($temporaryPathIsManaged) {
      @egmDbUnlink($temporaryPath);
    }
    return false;
  }

  if (PHP_OS_FAMILY !== 'Windows') {
    $replaced = @egmDbRename($temporaryPath, $targetPath);
    if (!$replaced) {
      @egmDbUnlink($temporaryPath);
    }
    return $replaced;
  }

  $rollbackPath = null;
  foreach ([$targetPath . '.replace-a', $targetPath . '.replace-b'] as $candidate) {
    if (!egmDbFileExists($candidate) && !is_link($candidate)) {
      $rollbackPath = $candidate;
      break;
    }
  }
  if ($rollbackPath === null) {
    @egmDbUnlink($temporaryPath);
    return false;
  }

  $hadOriginal = egmDbIsFile($targetPath);
  if ($hadOriginal && !@egmDbRename($targetPath, $rollbackPath)) {
    @egmDbUnlink($temporaryPath);
    return false;
  }
  if (@egmDbRename($temporaryPath, $targetPath)) {
    if ($hadOriginal) {
      @egmDbUnlink($rollbackPath);
    }
    return true;
  }
  if ($hadOriginal && egmDbIsFile($rollbackPath)) {
    @egmDbRename($rollbackPath, $targetPath);
  }
  @egmDbUnlink($temporaryPath);
  return false;
}

function egmPrizeAwardLogRefreshManagedBackup(string $mainPath, string $dataPath): bool
{
  if (!egmPrizeAwardLogIsManagedDataPath($mainPath, $dataPath) || is_link($dataPath)) {
    return false;
  }
  $entries = egmPrizeAwardLogDecodeFile($dataPath);
  if ($entries === null) {
    return false;
  }
  $directory = dirname($dataPath);
  $temporaryPath = egmPrizeAwardLogTemporaryPath($directory, '.egm-prize-backup-');
  if ($temporaryPath === null || !@egmDbCopy($dataPath, $temporaryPath)) {
    if (is_string($temporaryPath)) {
      @egmDbUnlink($temporaryPath);
    }
    return false;
  }
  $handle = @egmDbFopen($temporaryPath, 'r+b');
  if ($handle === false) {
    @egmDbUnlink($temporaryPath);
    return false;
  }
  $ok = fflush($handle);
  if ($ok && function_exists('fsync')) {
    $ok = fsync($handle);
  }
  fclose($handle);
  if (!$ok || egmPrizeAwardLogDecodeFile($temporaryPath) === null) {
    @egmDbUnlink($temporaryPath);
    return false;
  }
  return egmPrizeAwardLogReplacePrepared($mainPath, $temporaryPath, $dataPath . '.bak');
}

function egmPrizeAwardLogRefreshBackup(string $path): bool
{
  return egmPrizeAwardLogIsAllowedPath($path)
    && egmPrizeAwardLogRefreshManagedBackup($path, $path);
}

/** @param array<int, array<string, mixed>> $entries */
function egmPrizeAwardLogWriteManaged(string $mainPath, string $dataPath, array $entries, bool $allowInvalidCurrent = false): bool
{
  if (!egmPrizeAwardLogIsManagedDataPath($mainPath, $dataPath)
    || egmPrizeAwardLogValidateEntries($entries) === null
    || is_link($dataPath)) {
    return false;
  }
  $directory = dirname($dataPath);
  if (is_link($directory)
    || (!is_dir($directory) && !(@mkdir($directory, 0755, true) || is_dir($directory)))) {
    return false;
  }
  if (!$allowInvalidCurrent && egmDbFileExists($dataPath) && egmPrizeAwardLogDecodeFile($dataPath) === null) {
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
  $temporaryPath = egmPrizeAwardLogTemporaryPath($directory, '.egm-prize-awards-');
  if ($temporaryPath === null
    || !egmPrizeAwardLogWriteAll($temporaryPath, $json . PHP_EOL)
    || egmPrizeAwardLogDecodeFile($temporaryPath) === null) {
    if (is_string($temporaryPath)) {
      @egmDbUnlink($temporaryPath);
    }
    return false;
  }
  if (!egmPrizeAwardLogReplacePrepared($mainPath, $temporaryPath, $dataPath)) {
    return false;
  }

  // A committed primary is useful even if backup media has a transient error,
  // but callers are told the operation failed until the second durable copy is
  // confirmed. Retried idempotent updates repair the backup and then succeed.
  for ($attempt = 0; $attempt < 3; $attempt++) {
    if (egmPrizeAwardLogRefreshManagedBackup($mainPath, $dataPath)) {
      return true;
    }
    usleep(10000 * ($attempt + 1));
  }
  return false;
}

function egmPrizeAwardLogWritePrepared(string $path, array $entries): bool
{
  return egmPrizeAwardLogIsAllowedPath($path)
    && egmPrizeAwardLogWriteManaged($path, $path, $entries);
}

/** @return list<string> */
function egmPrizeAwardLogRecoveryCandidates(string $dataPath): array
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
function egmPrizeAwardLogLoadManaged(string $mainPath, string $dataPath, bool $allowNew): ?array
{
  if (!egmPrizeAwardLogIsManagedDataPath($mainPath, $dataPath) || is_link($dataPath)) {
    return null;
  }

  $entries = egmPrizeAwardLogDecodeFile($dataPath);
  if ($entries !== null) {
    foreach ([$dataPath . '.replace-a', $dataPath . '.replace-b', $dataPath . '.rollback'] as $stalePath) {
      if (egmDbIsFile($stalePath) && !is_link($stalePath)) {
        @egmDbUnlink($stalePath);
      }
    }
    // Repair a missing or malformed backup while the valid primary is locked.
    if (egmPrizeAwardLogDecodeFile($dataPath . '.bak') === null) {
      egmPrizeAwardLogRefreshManagedBackup($mainPath, $dataPath);
    }
    return $entries;
  }

  $dataExists = egmDbFileExists($dataPath) || is_link($dataPath);
  foreach (egmPrizeAwardLogRecoveryCandidates($dataPath) as $candidate) {
    if (is_link($candidate)) {
      continue;
    }
    $backupEntries = egmPrizeAwardLogDecodeFile($candidate);
    if ($backupEntries === null) {
      continue;
    }
    if (egmPrizeAwardLogWriteManaged($mainPath, $dataPath, $backupEntries, true)) {
      return $backupEntries;
    }
    // The primary replacement may have committed even if refreshing its backup
    // failed. Accept it only after validating the bytes now at the target.
    $recoveredEntries = egmPrizeAwardLogDecodeFile($dataPath);
    if ($recoveredEntries !== null) {
      return $recoveredEntries;
    }
  }

  // A genuinely new main ledger may start empty. Existing empty, truncated,
  // object-shaped, symlinked, or otherwise invalid files always fail closed.
  if ($allowNew && !$dataExists) {
    $hasRecoveryArtifact = false;
    foreach (egmPrizeAwardLogRecoveryCandidates($dataPath) as $candidate) {
      if (egmDbFileExists($candidate) || is_link($candidate)) {
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
function egmPrizeAwardLogAcquireLock(string $path)
{
  if (!egmPrizeAwardLogIsAllowedPath($path)
    || is_link($path)
    || is_link($path . '.lock')) {
    return null;
  }
  $directory = dirname($path);
  if (is_link($directory)
    || (!is_dir($directory) && !(@mkdir($directory, 0755, true) || is_dir($directory)))) {
    return null;
  }
  $lock = @egmDbFopen($path . '.lock', 'c+b');
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
function egmPrizeAwardLogReleaseLock($lock): void
{
  flock($lock, LOCK_UN);
  fclose($lock);
}

/** @return list<string>|null */
function egmPrizeAwardLogArchivePaths(string $path): ?array
{
  $archiveDirectory = egmPrizeAwardLogArchiveDirectory($path);
  if (is_link($archiveDirectory)) {
    return null;
  }
  if (!is_dir($archiveDirectory)) {
    return egmDbFileExists($archiveDirectory) ? null : [];
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
function egmPrizeAwardLogLoadArchives(string $path): ?array
{
  $archivePaths = egmPrizeAwardLogArchivePaths($path);
  if ($archivePaths === null) {
    return null;
  }
  $archives = [];
  foreach ($archivePaths as $archivePath) {
    $entries = egmPrizeAwardLogLoadManaged($path, $archivePath, false);
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
function egmPrizeAwardLogConsolidate(array $archives, array $activeEntries): array
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
function egmPrizeAwardLogContainsAwardId(array $archives, array $activeEntries, string $awardId): bool
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

function egmPrizeAwardLogNextArchivePath(string $path): ?string
{
  $archivePaths = egmPrizeAwardLogArchivePaths($path);
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
  return egmPrizeAwardLogArchiveDirectory($path) . DIRECTORY_SEPARATOR
    . sprintf('%08d.json', $highest + 1);
}

/** @param array<int, array<string, mixed>> $activeEntries */
function egmPrizeAwardLogRotate(string $path, array &$activeEntries): bool
{
  while (count($activeEntries) > EGM_PRIZE_AWARD_LOG_MAX_ACTIVE_ENTRIES) {
    $archiveIndexes = [];
    foreach ($activeEntries as $index => $_entry) {
      $archiveIndexes[] = $index;
      if (count($archiveIndexes) >= EGM_PRIZE_AWARD_LOG_ARCHIVE_BATCH_SIZE) {
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
    $archivePath = egmPrizeAwardLogNextArchivePath($path);
    if ($archivePath === null
      || !egmPrizeAwardLogWriteManaged($path, $archivePath, $archiveEntries)) {
      return false;
    }
    // The shard is durable before the active copy is removed. Pending entries
    // remain fully updateable in their bounded shard. A crash between these
    // commits can only create duplicates, which Read safely de-duplicates.
    $activeEntries = $remainingEntries;
  }
  return true;
}

function egmPrizeAwardLogAppend(string $path, array $entry): bool
{
  $validatedEntry = egmPrizeAwardLogValidateEntries([$entry]);
  if ($validatedEntry === null || !egmPrizeAwardLogIsAllowedPath($path)) {
    return false;
  }
  $awardId = trim((string)$entry['awardId']);
  $lock = egmPrizeAwardLogAcquireLock($path);
  if ($lock === null) {
    return false;
  }

  try {
    $activeEntries = egmPrizeAwardLogLoadManaged($path, $path, true);
    $archives = egmPrizeAwardLogLoadArchives($path);
    if ($activeEntries === null || $archives === null
      || egmPrizeAwardLogContainsAwardId($archives, $activeEntries, $awardId)) {
      return false;
    }
    $activeEntries[] = $entry;
    if (!egmPrizeAwardLogRotate($path, $activeEntries)) {
      return false;
    }
    return egmPrizeAwardLogWriteManaged($path, $path, $activeEntries);
  } finally {
    egmPrizeAwardLogReleaseLock($lock);
  }
}

/** @return 'ok'|'not-found'|'failed' */
function egmPrizeAwardLogUpdateOnce(string $path, string $awardId, array $changes): string
{
  $lock = egmPrizeAwardLogAcquireLock($path);
  if ($lock === null) {
    return 'failed';
  }
  try {
    $activeEntries = egmPrizeAwardLogLoadManaged($path, $path, true);
    $archives = egmPrizeAwardLogLoadArchives($path);
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
    if (egmPrizeAwardLogValidateEntries([$updatedEntry]) === null) {
      return 'failed';
    }
    if ($updatedEntry === $targetEntries[$targetIndex]) {
      return egmPrizeAwardLogRefreshManagedBackup($path, $targetPath) ? 'ok' : 'failed';
    }
    $targetEntries[$targetIndex] = $updatedEntry;
    return egmPrizeAwardLogWriteManaged($path, $targetPath, $targetEntries) ? 'ok' : 'failed';
  } finally {
    egmPrizeAwardLogReleaseLock($lock);
  }
}

function egmPrizeAwardLogUpdate(string $path, string $awardId, array $changes): bool
{
  $awardId = trim($awardId);
  if ($awardId === '' || !egmPrizeAwardLogIsAllowedPath($path)) {
    return false;
  }
  for ($attempt = 0; $attempt < 3; $attempt++) {
    $result = egmPrizeAwardLogUpdateOnce($path, $awardId, $changes);
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
function egmPrizeAwardLogRead(string $path): array
{
  if (!egmPrizeAwardLogIsAllowedPath($path)) {
    return [];
  }
  $lock = egmPrizeAwardLogAcquireLock($path);
  if ($lock === null) {
    return [];
  }
  try {
    $activeEntries = egmPrizeAwardLogLoadManaged($path, $path, true);
    $archives = egmPrizeAwardLogLoadArchives($path);
    if ($activeEntries === null || $archives === null) {
      return [];
    }
    return egmPrizeAwardLogConsolidate($archives, $activeEntries);
  } finally {
    egmPrizeAwardLogReleaseLock($lock);
  }
}
