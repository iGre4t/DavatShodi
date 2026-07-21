<?php
declare(strict_types=1);

/**
 * Transactional storage for Event Guest Manager prize levels.
 *
 * The default write target is restricted to the level file beside this helper.
 * Tests may define EGM_PRIZE_LEVELS_ALLOWED_DIRECTORY before loading this file.
 */

const EGM_PRIZE_LEVELS_FILENAME = 'EGM Prize Levels.json';

if (!defined('EGM_PRIZE_LEVELS_ALLOWED_DIRECTORY')) {
  define('EGM_PRIZE_LEVELS_ALLOWED_DIRECTORY', __DIR__);
}

function egmPrizeLevelsDefaultPath(): string
{
  return rtrim((string)EGM_PRIZE_LEVELS_ALLOWED_DIRECTORY, "\\/")
    . DIRECTORY_SEPARATOR
    . EGM_PRIZE_LEVELS_FILENAME;
}

function egmPrizeLevelsNormalizePathToken(string $path): string
{
  $normalized = str_replace('\\', '/', $path);
  return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
}

function egmPrizeLevelsIsAllowedPath(string $path): bool
{
  $expectedName = EGM_PRIZE_LEVELS_FILENAME;
  $actualName = basename($path);
  $nameMatches = PHP_OS_FAMILY === 'Windows'
    ? strcasecmp($actualName, $expectedName) === 0
    : $actualName === $expectedName;
  if (!$nameMatches) {
    return false;
  }

  $allowedDirectory = realpath((string)EGM_PRIZE_LEVELS_ALLOWED_DIRECTORY);
  $targetDirectory = realpath(dirname($path));
  if (!is_string($allowedDirectory) || !is_string($targetDirectory)) {
    return false;
  }
  return egmPrizeLevelsNormalizePathToken($allowedDirectory)
    === egmPrizeLevelsNormalizePathToken($targetDirectory);
}

function egmPrizeLevelsIsList(array $value): bool
{
  if (function_exists('array_is_list')) {
    return array_is_list($value);
  }
  if ($value === []) {
    return true;
  }
  return array_keys($value) === range(0, count($value) - 1);
}

function egmPrizeLevelsStableId(string $name, int $score, int $index, int $attempt = 0): string
{
  return 'lvl_' . substr(
    hash('sha256', $name . "\0" . $score . "\0" . $index . "\0" . $attempt),
    0,
    20
  );
}

function egmPrizeLevelsReadAliasedValue(array $record, array $keys, bool &$found)
{
  foreach ($keys as $key) {
    if (array_key_exists($key, $record)) {
      $found = true;
      return $record[$key];
    }
  }
  $found = false;
  return null;
}

function egmPrizeLevelsParsePositiveInt($value): ?int
{
  if (is_int($value)) {
    return $value > 0 ? $value : null;
  }
  if (is_float($value)) {
    return is_finite($value) && $value > 0 && floor($value) === $value && $value <= PHP_INT_MAX
      ? (int)$value
      : null;
  }
  if (!is_string($value)) {
    return null;
  }
  $trimmed = trim($value);
  if ($trimmed === '' || preg_match('/^[1-9][0-9]*$/D', $trimmed) !== 1) {
    return null;
  }
  $parsed = filter_var($trimmed, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
  return is_int($parsed) ? $parsed : null;
}

function egmPrizeLevelsNormalizeTextValue($value, bool $required, ?string &$reason): ?string
{
  if ($value === null && !$required) {
    return '';
  }
  if (!is_scalar($value)) {
    $reason = 'invalid_text_field';
    return null;
  }
  $text = trim((string)$value);
  if ($required && $text === '') {
    $reason = 'missing_level_name';
    return null;
  }
  return $text;
}

function egmPrizeLevelsNormalizePotSettings($value, ?string &$reason): ?array
{
  if ($value === null) {
    $source = [];
  } elseif (!is_array($value)) {
    $reason = 'invalid_pot_settings';
    return null;
  } else {
    $source = $value;
  }

  foreach (['title', 'prizeName', 'prize_name'] as $textKey) {
    if (array_key_exists($textKey, $source) && $source[$textKey] !== null && !is_scalar($source[$textKey])) {
      $reason = 'invalid_pot_settings';
      return null;
    }
  }
  foreach (['winnerLimit', 'winner_limit'] as $limitKey) {
    if (array_key_exists($limitKey, $source) && $source[$limitKey] !== null && !is_scalar($source[$limitKey])) {
      $reason = 'invalid_pot_settings';
      return null;
    }
  }
  if (array_key_exists('locked', $source) && !is_bool($source['locked']) && !is_scalar($source['locked']) && $source['locked'] !== null) {
    $reason = 'invalid_pot_settings';
    return null;
  }

  $title = trim((string)($source['title'] ?? ''));
  $prizeName = trim((string)($source['prizeName'] ?? ($source['prize_name'] ?? '')));
  $winnerLimitRaw = $source['winnerLimit'] ?? ($source['winner_limit'] ?? 1);
  $winnerLimit = is_numeric($winnerLimitRaw) ? (int)$winnerLimitRaw : 1;

  $normalized = $source;
  $normalized['title'] = function_exists('mb_substr')
    ? mb_substr($title, 0, 160, 'UTF-8')
    : substr($title, 0, 160);
  $normalized['winnerLimit'] = max(1, min(1000, $winnerLimit));
  $normalized['prizeName'] = function_exists('mb_substr')
    ? mb_substr($prizeName, 0, 160, 'UTF-8')
    : substr($prizeName, 0, 160);
  $normalized['locked'] = !empty($source['locked']);
  return $normalized;
}

/**
 * Strictly validates every row while preserving unknown top-level and nested
 * fields. Empty IDs are the only legacy defect repaired automatically.
 */
function egmPrizeLevelsNormalizeRecords(
  array $records,
  ?string &$reason = null,
  ?bool &$migrationNeeded = null
): ?array {
  $reason = null;
  $migrationNeeded = false;
  if (!egmPrizeLevelsIsList($records)) {
    $reason = 'not_a_list';
    return null;
  }

  $normalized = [];
  $seenIds = [];
  $seenScores = [];
  $missingIdIndexes = [];

  foreach ($records as $index => $record) {
    if (!is_array($record)) {
      $reason = 'malformed_row';
      return null;
    }

    $scoreFound = false;
    $scoreRaw = egmPrizeLevelsReadAliasedValue($record, ['score', 'levelScore', 'level_score'], $scoreFound);
    $score = $scoreFound ? egmPrizeLevelsParsePositiveInt($scoreRaw) : null;
    if ($score === null) {
      $reason = 'invalid_score';
      return null;
    }
    if (isset($seenScores[$score])) {
      $reason = 'duplicate_score';
      return null;
    }
    $seenScores[$score] = true;

    $nameFound = false;
    $nameRaw = egmPrizeLevelsReadAliasedValue($record, ['name', 'levelName', 'level_name', 'label'], $nameFound);
    $name = $nameFound ? egmPrizeLevelsNormalizeTextValue($nameRaw, true, $reason) : null;
    if ($name === null) {
      if ($reason === null) {
        $reason = 'missing_level_name';
      }
      return null;
    }

    $id = '';
    if (array_key_exists('id', $record)) {
      if ($record['id'] !== null && !is_scalar($record['id'])) {
        $reason = 'invalid_id';
        return null;
      }
      $id = trim((string)($record['id'] ?? ''));
    }
    if ($id !== '') {
      if (preg_match('/^[A-Za-z0-9._-]{1,96}$/D', $id) !== 1) {
        $reason = 'invalid_id';
        return null;
      }
      if (isset($seenIds[$id])) {
        $reason = 'duplicate_id';
        return null;
      }
      $seenIds[$id] = true;
    } else {
      $missingIdIndexes[] = (int)$index;
      $migrationNeeded = true;
    }

    $typeFound = false;
    $typeRaw = egmPrizeLevelsReadAliasedValue($record, ['type', 'levelType', 'level_type'], $typeFound);
    if ($typeFound && $typeRaw !== null && !is_scalar($typeRaw)) {
      $reason = 'invalid_type';
      return null;
    }
    $typeToken = strtolower(trim((string)($typeRaw ?? '')));
    if ($typeToken === '') {
      // Missing/empty type is the only supported legacy representation.
      $type = 'value_sum';
    } elseif (in_array($typeToken, ['value_sum', 'out_of_value', 'pot'], true)) {
      $type = $typeToken;
    } else {
      $reason = 'invalid_type';
      return null;
    }

    $descriptionFound = false;
    $descriptionRaw = egmPrizeLevelsReadAliasedValue(
      $record,
      ['description', 'describe', 'infoText', 'info_text'],
      $descriptionFound
    );
    $description = egmPrizeLevelsNormalizeTextValue($descriptionFound ? $descriptionRaw : null, false, $reason);
    if ($description === null) {
      return null;
    }

    $buttonFound = false;
    $buttonRaw = egmPrizeLevelsReadAliasedValue($record, ['buttonText', 'button_text'], $buttonFound);
    $buttonText = egmPrizeLevelsNormalizeTextValue($buttonFound ? $buttonRaw : null, false, $reason);
    if ($buttonText === null) {
      return null;
    }

    $potFound = false;
    $potRaw = egmPrizeLevelsReadAliasedValue($record, ['potSettings', 'pot_settings'], $potFound);
    $potSettings = egmPrizeLevelsNormalizePotSettings($potFound ? $potRaw : null, $reason);
    if ($potSettings === null) {
      return null;
    }

    $item = $record;
    $item['id'] = $id;
    $item['name'] = $name;
    $item['type'] = $type;
    $item['score'] = $score;
    $item['description'] = $description;
    $item['buttonText'] = $buttonText;
    $item['potSettings'] = $potSettings;
    $normalized[] = $item;
  }

  foreach ($missingIdIndexes as $index) {
    $attempt = 0;
    do {
      $id = egmPrizeLevelsStableId(
        (string)$normalized[$index]['name'],
        (int)$normalized[$index]['score'],
        $index,
        $attempt
      );
      $attempt++;
    } while (isset($seenIds[$id]));
    $normalized[$index]['id'] = $id;
    $seenIds[$id] = true;
  }

  return $normalized;
}

function egmPrizeLevelsVersion(array $records): string
{
  $encoded = json_encode(
    array_values($records),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
  );
  return hash('sha256', is_string($encoded) ? $encoded : 'invalid');
}

/**
 * Merges fields omitted by a schema-older client from the current record with
 * the same explicit stable ID. Submitted values always win. potSettings gets
 * its own shallow merge so custom/future keys inside it are not erased when
 * the browser submits only today's canonical pot fields.
 */
function egmPrizeLevelsMergeCurrentFields(array $submittedRecords, array $currentRecords): array
{
  $currentById = [];
  foreach ($currentRecords as $record) {
    if (!is_array($record)) {
      continue;
    }
    $id = trim((string)($record['id'] ?? ''));
    if ($id !== '') {
      $currentById[$id] = $record;
    }
  }

  $canonicalAliases = [
    'name' => ['levelName', 'level_name', 'label'],
    'type' => ['levelType', 'level_type'],
    'score' => ['levelScore', 'level_score'],
    'description' => ['describe', 'infoText', 'info_text'],
    'buttonText' => ['button_text']
  ];
  $mergedRecords = [];
  foreach ($submittedRecords as $submitted) {
    if (!is_array($submitted)) {
      $mergedRecords[] = $submitted;
      continue;
    }
    $id = array_key_exists('id', $submitted) && is_scalar($submitted['id'])
      ? trim((string)$submitted['id'])
      : '';
    if ($id === '' || !isset($currentById[$id])) {
      $mergedRecords[] = $submitted;
      continue;
    }

    $current = $currentById[$id];
    $merged = array_replace($current, $submitted);
    // If a legacy alias was submitted without its canonical counterpart, make
    // it override the current canonical value just as a canonical field would.
    foreach ($canonicalAliases as $canonical => $aliases) {
      if (array_key_exists($canonical, $submitted)) {
        continue;
      }
      foreach ($aliases as $alias) {
        if (array_key_exists($alias, $submitted)) {
          $merged[$canonical] = $submitted[$alias];
          break;
        }
      }
    }

    $submittedPotFound = false;
    $submittedPot = egmPrizeLevelsReadAliasedValue(
      $submitted,
      ['potSettings', 'pot_settings'],
      $submittedPotFound
    );
    if ($submittedPotFound) {
      $currentPot = $current['potSettings'] ?? ($current['pot_settings'] ?? null);
      $merged['potSettings'] = is_array($currentPot) && is_array($submittedPot)
        ? array_replace($currentPot, $submittedPot)
        : $submittedPot;
    }
    $mergedRecords[] = $merged;
  }
  return $mergedRecords;
}

function egmPrizeLevelsDecodeFile(string $path): array
{
  if (!is_file($path)) {
    return [
      'ok' => true,
      'exists' => false,
      'records' => [],
      'migrationNeeded' => false,
      'reason' => ''
    ];
  }
  $raw = file_get_contents($path);
  if (!is_string($raw) || trim($raw) === '') {
    return ['ok' => false, 'exists' => true, 'records' => [], 'migrationNeeded' => false, 'reason' => 'empty_file'];
  }
  $trimmedRaw = trim($raw);
  // With associative decoding, PHP represents both [] and {} as an empty
  // array. Check the JSON container itself so an object can never be mistaken
  // for a valid (empty) prize-level list.
  if ($trimmedRaw[0] !== '[') {
    return ['ok' => false, 'exists' => true, 'records' => [], 'migrationNeeded' => false, 'reason' => 'not_a_list'];
  }
  $decoded = json_decode($trimmedRaw, true);
  if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE || !egmPrizeLevelsIsList($decoded)) {
    return ['ok' => false, 'exists' => true, 'records' => [], 'migrationNeeded' => false, 'reason' => 'invalid_json'];
  }
  $reason = null;
  $migrationNeeded = false;
  $records = egmPrizeLevelsNormalizeRecords($decoded, $reason, $migrationNeeded);
  if (!is_array($records) || count($records) !== count($decoded)) {
    return [
      'ok' => false,
      'exists' => true,
      'records' => [],
      'migrationNeeded' => false,
      'reason' => $reason ?? 'invalid_records'
    ];
  }
  return [
    'ok' => true,
    'exists' => true,
    'records' => $records,
    'migrationNeeded' => $migrationNeeded,
    'reason' => ''
  ];
}

function egmPrizeLevelsWriteAll($handle, string $payload): bool
{
  $offset = 0;
  $length = strlen($payload);
  while ($offset < $length) {
    $written = fwrite($handle, substr($payload, $offset));
    if (!is_int($written) || $written <= 0) {
      return false;
    }
    $offset += $written;
  }
  if (!fflush($handle)) {
    return false;
  }
  return !function_exists('fsync') || fsync($handle);
}

function egmPrizeLevelsPrepareFile(string $directory, array $records): ?string
{
  $encoded = json_encode(
    array_values($records),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
  );
  if (!is_string($encoded)) {
    return null;
  }
  $temporaryPath = tempnam($directory, '.prize-levels-');
  if (!is_string($temporaryPath) || $temporaryPath === '') {
    return null;
  }
  $handle = fopen($temporaryPath, 'wb');
  if ($handle === false) {
    @unlink($temporaryPath);
    return null;
  }
  $ok = egmPrizeLevelsWriteAll($handle, $encoded . PHP_EOL);
  fclose($handle);
  if (!$ok) {
    @unlink($temporaryPath);
    return null;
  }
  $decoded = egmPrizeLevelsDecodeFile($temporaryPath);
  if (!($decoded['ok'] ?? false) || ($decoded['migrationNeeded'] ?? false)) {
    @unlink($temporaryPath);
    return null;
  }
  return $temporaryPath;
}

function egmPrizeLevelsReplacePrepared(string $preparedPath, string $targetPath): bool
{
  if (PHP_OS_FAMILY !== 'Windows') {
    return @rename($preparedPath, $targetPath);
  }
  $rollbackPath = $targetPath . '.rollback';
  @unlink($rollbackPath);
  $hadOriginal = is_file($targetPath);
  if ($hadOriginal && !@rename($targetPath, $rollbackPath)) {
    return false;
  }
  if (@rename($preparedPath, $targetPath)) {
    @unlink($rollbackPath);
    return true;
  }
  if ($hadOriginal && is_file($rollbackPath)) {
    @rename($rollbackPath, $targetPath);
  }
  return false;
}

function egmPrizeLevelsRefreshBackup(string $path): bool
{
  $live = egmPrizeLevelsDecodeFile($path);
  if (!($live['ok'] ?? false) || !($live['exists'] ?? false) || ($live['migrationNeeded'] ?? false)) {
    return false;
  }
  $temporaryPath = egmPrizeLevelsPrepareFile(dirname($path), (array)$live['records']);
  if (!is_string($temporaryPath)) {
    return false;
  }
  $backupPath = $path . '.bak';
  $ok = egmPrizeLevelsReplacePrepared($temporaryPath, $backupPath);
  if (is_file($temporaryPath)) {
    @unlink($temporaryPath);
  }
  return $ok;
}

function egmPrizeLevelsAtomicWrite(string $path, array $records, ?bool &$backupSynced = null): bool
{
  $backupSynced = false;
  $temporaryPath = egmPrizeLevelsPrepareFile(dirname($path), $records);
  if (!is_string($temporaryPath)) {
    return false;
  }
  $ok = egmPrizeLevelsReplacePrepared($temporaryPath, $path);
  if (is_file($temporaryPath)) {
    @unlink($temporaryPath);
  }
  if (!$ok) {
    return false;
  }
  $backupSynced = egmPrizeLevelsRefreshBackup($path);
  if (!$backupSynced) {
    error_log('Event Guest Manager prize-level backup refresh failed: ' . $path);
  }
  return true;
}

function egmPrizeLevelsCorruptPath(string $path): string
{
  try {
    $suffix = bin2hex(random_bytes(6));
  } catch (Throwable $error) {
    $suffix = str_replace('.', '', uniqid('', true));
  }
  return $path . '.corrupt-' . gmdate('YmdHis') . '-' . $suffix;
}

function egmPrizeLevelsRestoreFromFile(string $sourcePath, string $targetPath): bool
{
  $source = egmPrizeLevelsDecodeFile($sourcePath);
  if (!($source['ok'] ?? false) || !($source['exists'] ?? false)) {
    return false;
  }
  $temporaryPath = egmPrizeLevelsPrepareFile(dirname($targetPath), (array)$source['records']);
  if (!is_string($temporaryPath)) {
    return false;
  }
  $corruptPath = '';
  if (is_file($targetPath)) {
    $corruptPath = egmPrizeLevelsCorruptPath($targetPath);
    if (!@rename($targetPath, $corruptPath)) {
      @unlink($temporaryPath);
      return false;
    }
  }
  if (@rename($temporaryPath, $targetPath)) {
    return true;
  }
  @unlink($temporaryPath);
  if ($corruptPath !== '' && is_file($corruptPath)) {
    @rename($corruptPath, $targetPath);
  }
  return false;
}

function egmPrizeLevelsRecoverInterruptedReplace(string $path): void
{
  $rollbackPath = $path . '.rollback';
  if (!is_file($rollbackPath)) {
    return;
  }
  $rollback = egmPrizeLevelsDecodeFile($rollbackPath);
  if (!is_file($path)) {
    if (($rollback['ok'] ?? false) && ($rollback['exists'] ?? false)) {
      @rename($rollbackPath, $path);
    }
    return;
  }
  $live = egmPrizeLevelsDecodeFile($path);
  if (($live['ok'] ?? false) && ($live['exists'] ?? false)) {
    @unlink($rollbackPath);
    return;
  }
  if (($rollback['ok'] ?? false) && ($rollback['exists'] ?? false)) {
    $corruptPath = egmPrizeLevelsCorruptPath($path);
    if (@rename($path, $corruptPath)) {
      if (!@rename($rollbackPath, $path)) {
        @rename($corruptPath, $path);
      }
    }
  }
}

function egmPrizeLevelsLoadUnderLock(string $path): array
{
  egmPrizeLevelsRecoverInterruptedReplace($path);
  $live = egmPrizeLevelsDecodeFile($path);
  if (($live['ok'] ?? false) && ($live['exists'] ?? false)) {
    $live['recovered'] = false;
    return $live;
  }

  $backupPath = $path . '.bak';
  if (is_file($backupPath)) {
    $backup = egmPrizeLevelsDecodeFile($backupPath);
    if (($backup['ok'] ?? false) && ($backup['exists'] ?? false)
      && egmPrizeLevelsRestoreFromFile($backupPath, $path)) {
      $restored = egmPrizeLevelsDecodeFile($path);
      if (($restored['ok'] ?? false) && ($restored['exists'] ?? false)) {
        $restored['recovered'] = true;
        return $restored;
      }
    }
    return [
      'ok' => false,
      'exists' => is_file($path),
      'records' => [],
      'migrationNeeded' => false,
      'recovered' => false,
      'reason' => 'backup_recovery_failed'
    ];
  }

  if (!($live['exists'] ?? false)) {
    return [
      'ok' => true,
      'exists' => false,
      'records' => [],
      'migrationNeeded' => false,
      'recovered' => false,
      'reason' => ''
    ];
  }
  $live['recovered'] = false;
  return $live;
}

function egmPrizeLevelsWithExclusiveLock(string $path, callable $operation): array
{
  if (!egmPrizeLevelsIsAllowedPath($path)) {
    return ['ok' => false, 'reason' => 'path_not_allowed', 'records' => [], 'version' => ''];
  }
  $lock = fopen($path . '.lock', 'c+b');
  if ($lock === false) {
    return ['ok' => false, 'reason' => 'lock_open_failed', 'records' => [], 'version' => ''];
  }
  if (!flock($lock, LOCK_EX)) {
    fclose($lock);
    return ['ok' => false, 'reason' => 'lock_failed', 'records' => [], 'version' => ''];
  }
  try {
    $result = $operation();
    return is_array($result)
      ? $result
      : ['ok' => false, 'reason' => 'invalid_operation_result', 'records' => [], 'version' => ''];
  } catch (Throwable $error) {
    error_log('Event Guest Manager prize-level store failure: ' . $error->getMessage());
    return ['ok' => false, 'reason' => 'store_exception', 'records' => [], 'version' => ''];
  } finally {
    flock($lock, LOCK_UN);
    fclose($lock);
  }
}

/**
 * @return array{ok:bool,reason:string,records:array,version:string,migrated?:bool,recovered?:bool,backupSynced?:bool}
 */
function egmPrizeLevelsReadSnapshot(string $path = ''): array
{
  $targetPath = $path !== '' ? $path : egmPrizeLevelsDefaultPath();
  return egmPrizeLevelsWithExclusiveLock($targetPath, static function () use ($targetPath): array {
    $loaded = egmPrizeLevelsLoadUnderLock($targetPath);
    if (!($loaded['ok'] ?? false)) {
      return [
        'ok' => false,
        'reason' => (string)($loaded['reason'] ?? 'read_failed'),
        'records' => [],
        'version' => ''
      ];
    }

    $records = (array)($loaded['records'] ?? []);
    $migrated = false;
    $backupSynced = true;
    if (!empty($loaded['migrationNeeded'])) {
      if (!egmPrizeLevelsAtomicWrite($targetPath, $records, $backupSynced)) {
        return ['ok' => false, 'reason' => 'migration_write_failed', 'records' => [], 'version' => ''];
      }
      $migrated = true;
    } elseif (!empty($loaded['exists'])) {
      $backup = egmPrizeLevelsDecodeFile($targetPath . '.bak');
      $backupMatchesLive = ($backup['ok'] ?? false)
        && ($backup['exists'] ?? false)
        && empty($backup['migrationNeeded'])
        && hash_equals(
          egmPrizeLevelsVersion($records),
          egmPrizeLevelsVersion((array)($backup['records'] ?? []))
        );
      if (!$backupMatchesLive) {
        $backupSynced = egmPrizeLevelsRefreshBackup($targetPath);
      }
    }

    return [
      'ok' => true,
      'reason' => '',
      'records' => $records,
      'version' => egmPrizeLevelsVersion($records),
      'migrated' => $migrated,
      'recovered' => !empty($loaded['recovered']),
      'backupSynced' => $backupSynced
    ];
  });
}

/**
 * Optimistically replaces the complete list. On success, records and version
 * describe the exact bytes committed while the exclusive lock was held.
 *
 * @return array{ok:bool,reason:string,records:array,version:string,backupSynced?:bool}
 */
function egmPrizeLevelsReplaceIfVersion(
  string $path,
  array $records,
  string $expectedVersion
): array {
  return egmPrizeLevelsWithExclusiveLock($path, static function () use ($path, $records, $expectedVersion): array {
    $loaded = egmPrizeLevelsLoadUnderLock($path);
    if (!($loaded['ok'] ?? false)) {
      return ['ok' => false, 'reason' => (string)($loaded['reason'] ?? 'read_failed'), 'records' => [], 'version' => ''];
    }

    $current = (array)($loaded['records'] ?? []);
    if (!empty($loaded['migrationNeeded'])) {
      $migrationBackupSynced = false;
      if (!egmPrizeLevelsAtomicWrite($path, $current, $migrationBackupSynced)) {
        return ['ok' => false, 'reason' => 'migration_write_failed', 'records' => [], 'version' => ''];
      }
    }
    $currentVersion = egmPrizeLevelsVersion($current);
    if ($expectedVersion === '' || !hash_equals($currentVersion, $expectedVersion)) {
      return [
        'ok' => false,
        'reason' => 'conflict',
        'records' => $current,
        'version' => $currentVersion
      ];
    }

    $recordsWithPreservedFields = egmPrizeLevelsMergeCurrentFields($records, $current);
    $reason = null;
    $migrationNeeded = false;
    $normalized = egmPrizeLevelsNormalizeRecords($recordsWithPreservedFields, $reason, $migrationNeeded);
    if (!is_array($normalized) || count($normalized) !== count($recordsWithPreservedFields)) {
      return [
        'ok' => false,
        'reason' => $reason ?? 'invalid_records',
        'records' => $current,
        'version' => $currentVersion
      ];
    }

    $backupSynced = false;
    if (!egmPrizeLevelsAtomicWrite($path, $normalized, $backupSynced)) {
      return [
        'ok' => false,
        'reason' => 'write_failed',
        'records' => $current,
        'version' => $currentVersion
      ];
    }
    return [
      'ok' => true,
      'reason' => '',
      'records' => $normalized,
      'version' => egmPrizeLevelsVersion($normalized),
      'backupSynced' => $backupSynced
    ];
  });
}
