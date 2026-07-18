<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/mini apps/Task Club/prize_award_log.php';

if (($argv[1] ?? '') === 'worker') {
  $path = (string)($argv[2] ?? '');
  $awardId = (string)($argv[3] ?? '');
  exit(tcPrizeAwardLogAppend($path, [
    'awardId' => $awardId,
    'status' => 'pending',
    'workId' => $awardId,
    'prize' => ['name' => 'Concurrent prize']
  ]) ? 0 : 1);
}

if (($argv[1] ?? '') === 'update-worker') {
  $path = (string)($argv[2] ?? '');
  $awardId = (string)($argv[3] ?? '');
  exit(tcPrizeAwardLogUpdate($path, $awardId, [
    'status' => 'awarded',
    'awardedAt' => '2026-01-02T00:00:00+00:00'
  ]) ? 0 : 1);
}

function tcPrizeLogAssert(bool $condition, string $message): void
{
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
}

function tcPrizeLogRemoveTree(string $path): void
{
  if (!is_dir($path)) {
    return;
  }
  foreach (scandir($path) ?: [] as $name) {
    if ($name === '.' || $name === '..') {
      continue;
    }
    $item = $path . DIRECTORY_SEPARATOR . $name;
    is_dir($item) ? tcPrizeLogRemoveTree($item) : @unlink($item);
  }
  @rmdir($path);
}

/** @return array{resource, array<int, resource>} */
function tcPrizeLogStartWorker(string $mode, string $path, string $awardId): array
{
  $pipes = [];
  $process = proc_open(
    [PHP_BINARY, __FILE__, $mode, $path, $awardId],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
  );
  tcPrizeLogAssert(is_resource($process), $mode . ' could not be started');
  fclose($pipes[0]);
  return [$process, $pipes];
}

/** @param array{resource, array<int, resource>} $worker */
function tcPrizeLogFinishWorker(array $worker, string $description): void
{
  [$process, $pipes] = $worker;
  $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  tcPrizeLogAssert(proc_close($process) === 0, $description . ': ' . trim($output));
}

function tcPrizeLogCreateEventPath(string $root, string $name): string
{
  $eventDirectory = $root . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . 'TC Event';
  tcPrizeLogAssert(mkdir($eventDirectory, 0700, true), $name . ' directory could not be created');
  return $eventDirectory . DIRECTORY_SEPARATOR . TC_PRIZE_AWARD_LOG_FILENAME;
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tc-prize-log-' . bin2hex(random_bytes(6));
tcPrizeLogAssert(mkdir($directory, 0700, true), 'temporary directory could not be created');
$eventDirectory = $directory . DIRECTORY_SEPARATOR . 'TC Event';
tcPrizeLogAssert(mkdir($eventDirectory, 0700, true), 'temporary event directory could not be created');
$path = $eventDirectory . DIRECTORY_SEPARATOR . 'TC Prize Awards Log.json';

try {
  $inviteesSentinelPath = $eventDirectory . DIRECTORY_SEPARATOR . 'Invitees mapped.csv';
  $inventorySentinelPath = $eventDirectory . DIRECTORY_SEPARATOR . 'TC Prizes.json';
  $settingsSentinelPath = $eventDirectory . DIRECTORY_SEPARATOR . 'Setting.json';
  $sentinels = [
    $inviteesSentinelPath => "Work ID,score\n1001,25\n",
    $inventorySentinelPath => '[{"name":"Protected inventory","last":7}]',
    $settingsSentinelPath => '{"maintenanceMode":false}'
  ];
  foreach ($sentinels as $sentinelPath => $sentinelContent) {
    tcPrizeLogAssert(file_put_contents($sentinelPath, $sentinelContent, LOCK_EX) !== false, 'sentinel setup failed');
  }
  $sentinelHashes = array_map('hash_file', array_fill(0, count($sentinels), 'sha256'), array_keys($sentinels));

  $awardId = bin2hex(random_bytes(8));
  tcPrizeLogAssert(tcPrizeAwardLogAppend($path, [
    'awardId' => $awardId,
    'status' => 'pending',
    'workId' => '1001',
    'prize' => ['name' => 'Gift card', 'value' => 500]
  ]), 'pending award could not be logged');
  tcPrizeLogAssert(tcPrizeAwardLogUpdate($path, $awardId, [
    'status' => 'awarded',
    'awardedAt' => '2026-01-01T00:00:00+00:00'
  ]), 'award status could not be committed');

  $entries = tcPrizeAwardLogRead($path);
  tcPrizeLogAssert(count($entries) === 1, 'the ledger has the wrong entry count');
  tcPrizeLogAssert(($entries[0]['status'] ?? '') === 'awarded', 'the award was not marked awarded');
  tcPrizeLogAssert(($entries[0]['prize']['name'] ?? '') === 'Gift card', 'the prize details were not preserved');
  tcPrizeLogAssert(!tcPrizeAwardLogAppend($path, ['awardId' => $awardId]), 'duplicate award ID was accepted');
  tcPrizeLogAssert(
    !tcPrizeAwardLogAppend($inviteesSentinelPath, ['awardId' => 'wrong-target']),
    'the logger accepted the invitees CSV as a write target'
  );
  $unauthorisedTemporaryPath = $eventDirectory . DIRECTORY_SEPARATOR . '.unauthorised-ledger-replacement';
  tcPrizeLogAssert(
    file_put_contents($unauthorisedTemporaryPath, "replacement bytes\n", LOCK_EX) !== false,
    'unauthorised replacement setup failed'
  );
  tcPrizeLogAssert(
    !tcPrizeAwardLogReplacePrepared($path, $unauthorisedTemporaryPath, $inventorySentinelPath),
    'the atomic replacement helper accepted an unrelated file target'
  );
  tcPrizeLogAssert(
    file_get_contents($inventorySentinelPath) === $sentinels[$inventorySentinelPath],
    'the inventory changed during an unauthorised replacement attempt'
  );
  $ledgerHashBeforeBadSource = hash_file('sha256', $path);
  tcPrizeLogAssert(
    !tcPrizeAwardLogReplacePrepared($path, $inventorySentinelPath, $path),
    'the atomic replacement helper accepted an unrelated source file'
  );
  tcPrizeLogAssert(
    file_get_contents($inventorySentinelPath) === $sentinels[$inventorySentinelPath],
    'the inventory was moved or changed when supplied as an unauthorised source'
  );
  tcPrizeLogAssert(hash_file('sha256', $path) === $ledgerHashBeforeBadSource, 'the ledger changed after a bad source attempt');

  $workers = [];
  foreach (['concurrent-1', 'concurrent-2'] as $workerAwardId) {
    $workers[] = tcPrizeLogStartWorker('worker', $path, $workerAwardId);
  }
  foreach ($workers as $worker) {
    tcPrizeLogFinishWorker($worker, 'concurrency append worker failed');
  }
  tcPrizeLogAssert(count(tcPrizeAwardLogRead($path)) === 3, 'a concurrent log entry was lost');

  $workers = [];
  foreach (['concurrent-1', 'concurrent-2'] as $workerAwardId) {
    $workers[] = tcPrizeLogStartWorker('update-worker', $path, $workerAwardId);
  }
  foreach ($workers as $worker) {
    tcPrizeLogFinishWorker($worker, 'concurrency update worker failed');
  }
  $concurrentlyUpdated = tcPrizeAwardLogRead($path);
  $concurrentStatuses = [];
  foreach ($concurrentlyUpdated as $entry) {
    $concurrentStatuses[(string)$entry['awardId']] = (string)($entry['status'] ?? '');
  }
  tcPrizeLogAssert(($concurrentStatuses['concurrent-1'] ?? '') === 'awarded', 'first concurrent status update was lost');
  tcPrizeLogAssert(($concurrentStatuses['concurrent-2'] ?? '') === 'awarded', 'second concurrent status update was lost');

  $recoverableCorruptions = [
    'zero-byte ledger' => '',
    'truncated JSON' => '{broken json',
    'object-shaped root' => '{"awardId":"wrong-root"}',
    'entry without an award ID' => '[{"status":"pending"}]',
    'scalar-shaped JSON' => 'true'
  ];
  $recoveryMarker = 0;
  foreach ($recoverableCorruptions as $description => $corruptBytes) {
    tcPrizeLogAssert(file_put_contents($path, $corruptBytes, LOCK_EX) !== false, $description . ' setup failed');
    $recoveryMarker++;
    tcPrizeLogAssert(tcPrizeAwardLogUpdate($path, 'concurrent-1', [
      'status' => 'awarded',
      'recoveryMarker' => $recoveryMarker
    ]), $description . ' was not recovered from its valid backup');
    tcPrizeLogAssert(tcPrizeAwardLogDecodeFile($path) !== null, $description . ' did not restore a valid primary');
  }
  $recoveredEntries = tcPrizeAwardLogRead($path);
  tcPrizeLogAssert(count($recoveredEntries) === 3, 'backup recovery lost award entries');

  tcPrizeLogAssert(@unlink($path), 'missing-primary recovery setup could not remove the primary');
  $missingPrimaryEntries = tcPrizeAwardLogRead($path);
  tcPrizeLogAssert(count($missingPrimaryEntries) === 3, 'a missing primary was not restored from its valid backup');
  tcPrizeLogAssert(tcPrizeAwardLogDecodeFile($path) !== null, 'missing-primary recovery did not recreate valid JSON');

  foreach (array_keys($sentinels) as $index => $sentinelPath) {
    tcPrizeLogAssert(
      hash_file('sha256', $sentinelPath) === ($sentinelHashes[$index] ?? null),
      basename($sentinelPath) . ' was changed by prize logging'
    );
  }

  $isolatedRoot = $directory . DIRECTORY_SEPARATOR . 'corrupt-without-backup';
  $isolatedDirectory = $isolatedRoot . DIRECTORY_SEPARATOR . 'TC Event';
  tcPrizeLogAssert(mkdir($isolatedDirectory, 0700, true), 'isolated corruption directory could not be created');
  $isolatedCorruptPath = $isolatedDirectory . DIRECTORY_SEPARATOR . 'TC Prize Awards Log.json';
  $corruptContent = '{do not overwrite this corrupt file';
  tcPrizeLogAssert(file_put_contents($isolatedCorruptPath, $corruptContent, LOCK_EX) !== false, 'isolated corruption setup failed');
  tcPrizeLogAssert(
    !tcPrizeAwardLogAppend($isolatedCorruptPath, ['awardId' => 'must-not-write']),
    'corrupt ledger without a valid backup was overwritten'
  );
  tcPrizeLogAssert(file_get_contents($isolatedCorruptPath) === $corruptContent, 'corrupt ledger was changed without recovery data');

  foreach ($recoverableCorruptions as $description => $corruptBytes) {
    $casePath = tcPrizeLogCreateEventPath(
      $directory,
      'no-backup-' . preg_replace('/[^a-z]+/', '-', strtolower($description))
    );
    tcPrizeLogAssert(file_put_contents($casePath, $corruptBytes, LOCK_EX) !== false, $description . ' no-backup setup failed');
    tcPrizeLogAssert(
      !tcPrizeAwardLogAppend($casePath, ['awardId' => 'must-not-write', 'status' => 'pending']),
      $description . ' without a backup was accepted'
    );
    tcPrizeLogAssert(
      file_get_contents($casePath) === $corruptBytes,
      $description . ' without a backup did not preserve its exact bytes'
    );
  }

  $invalidBackupPath = tcPrizeLogCreateEventPath($directory, 'invalid-backup');
  $invalidPrimaryBytes = '{primary must survive';
  $invalidBackupBytes = '{backup is also broken';
  tcPrizeLogAssert(file_put_contents($invalidBackupPath, $invalidPrimaryBytes, LOCK_EX) !== false, 'invalid primary setup failed');
  tcPrizeLogAssert(file_put_contents($invalidBackupPath . '.bak', $invalidBackupBytes, LOCK_EX) !== false, 'invalid backup setup failed');
  tcPrizeLogAssert(
    !tcPrizeAwardLogAppend($invalidBackupPath, ['awardId' => 'must-not-write', 'status' => 'pending']),
    'an invalid primary with an invalid backup was accepted'
  );
  tcPrizeLogAssert(file_get_contents($invalidBackupPath) === $invalidPrimaryBytes, 'invalid primary bytes were overwritten');
  tcPrizeLogAssert(file_get_contents($invalidBackupPath . '.bak') === $invalidBackupBytes, 'invalid backup bytes were overwritten');

  $rotationPath = tcPrizeLogCreateEventPath($directory, 'rotation');
  $seedEntries = [];
  for ($index = 0; $index < TC_PRIZE_AWARD_LOG_MAX_ACTIVE_ENTRIES + 4; $index++) {
    $seedEntries[] = [
      'awardId' => 'archivable-' . $index,
      'status' => 'pending',
      'prize' => ['name' => 'Rotation prize']
    ];
  }
  tcPrizeLogAssert(tcPrizeAwardLogWritePrepared($rotationPath, $seedEntries), 'rotation seed could not be written');
  tcPrizeLogAssert(tcPrizeAwardLogAppend($rotationPath, [
    'awardId' => 'active-pending',
    'status' => 'pending',
    'prize' => ['name' => 'Active prize']
  ]), 'append that triggers rotation failed');
  $rotatedEntries = tcPrizeAwardLogRead($rotationPath);
  tcPrizeLogAssert(
    count($rotatedEntries) === count($seedEntries) + 1,
    'rotation lost or duplicated consolidated awards'
  );
  $activeAfterRotation = tcPrizeAwardLogDecodeFile($rotationPath);
  tcPrizeLogAssert(
    is_array($activeAfterRotation) && count($activeAfterRotation) <= TC_PRIZE_AWARD_LOG_MAX_ACTIVE_ENTRIES,
    'the active ledger remained unbounded after rotation'
  );
  $archivePaths = tcPrizeAwardLogArchivePaths($rotationPath);
  tcPrizeLogAssert(is_array($archivePaths) && count($archivePaths) >= 1, 'rotation did not create an archive shard');
  $firstArchivePath = $archivePaths[0];
  $firstArchiveEntries = tcPrizeAwardLogDecodeFile($firstArchivePath);
  tcPrizeLogAssert(
    is_array($firstArchiveEntries) && count($firstArchiveEntries) <= TC_PRIZE_AWARD_LOG_ARCHIVE_BATCH_SIZE,
    'archive shard exceeded its bounded size'
  );
  tcPrizeLogAssert(tcPrizeAwardLogUpdate($rotationPath, 'archivable-0', [
    'status' => 'awarded',
    'archiveUpdateVerified' => true
  ]), 'an archived award could not be updated');
  tcPrizeLogAssert(
    !tcPrizeAwardLogAppend($rotationPath, ['awardId' => 'archivable-0', 'status' => 'pending']),
    'a duplicate award ID already stored in an archive was accepted'
  );
  $updatedArchiveEntry = null;
  foreach (tcPrizeAwardLogRead($rotationPath) as $entry) {
    if (($entry['awardId'] ?? '') === 'archivable-0') {
      $updatedArchiveEntry = $entry;
      break;
    }
  }
  tcPrizeLogAssert(($updatedArchiveEntry['archiveUpdateVerified'] ?? false) === true, 'archived update was not consolidated');

  tcPrizeLogAssert(file_put_contents($firstArchivePath, '', LOCK_EX) !== false, 'archive corruption setup failed');
  tcPrizeLogAssert(
    count(tcPrizeAwardLogRead($rotationPath)) === count($seedEntries) + 1,
    'a zero-byte archive was not recovered from its valid backup'
  );
  tcPrizeLogAssert(tcPrizeAwardLogDecodeFile($firstArchivePath) !== null, 'archive recovery did not restore valid JSON');

  $archiveBytes = '{archive must remain untouched';
  $archiveBackupBytes = '{archive backup is invalid too';
  tcPrizeLogAssert(file_put_contents($firstArchivePath, $archiveBytes, LOCK_EX) !== false, 'unrecoverable archive setup failed');
  tcPrizeLogAssert(file_put_contents($firstArchivePath . '.bak', $archiveBackupBytes, LOCK_EX) !== false, 'unrecoverable archive backup setup failed');
  $rotationPrimaryHash = hash_file('sha256', $rotationPath);
  tcPrizeLogAssert(
    !tcPrizeAwardLogAppend($rotationPath, ['awardId' => 'blocked-by-corrupt-archive', 'status' => 'pending']),
    'append proceeded while an archive and its backup were corrupt'
  );
  tcPrizeLogAssert(file_get_contents($firstArchivePath) === $archiveBytes, 'corrupt archive bytes were overwritten without recovery');
  tcPrizeLogAssert(file_get_contents($firstArchivePath . '.bak') === $archiveBackupBytes, 'corrupt archive backup bytes were overwritten');
  tcPrizeLogAssert(hash_file('sha256', $rotationPath) === $rotationPrimaryHash, 'primary changed after archive recovery failed');

  tcPrizeLogAssert(
    !tcPrizeAwardLogAppend($path . '.bak', ['awardId' => 'companion-target', 'status' => 'pending']),
    'the public API accepted a backup companion as its target'
  );
  tcPrizeLogAssert(
    !tcPrizeAwardLogAppend($firstArchivePath, ['awardId' => 'archive-target', 'status' => 'pending']),
    'the public API accepted an archive shard as its target'
  );

  foreach (array_keys($sentinels) as $index => $sentinelPath) {
    tcPrizeLogAssert(
      hash_file('sha256', $sentinelPath) === ($sentinelHashes[$index] ?? null),
      basename($sentinelPath) . ' was changed by corruption and rotation tests'
    );
  }

  fwrite(STDOUT, "Task Club prize award log test passed.\n");
} finally {
  tcPrizeLogRemoveTree($directory);
}
