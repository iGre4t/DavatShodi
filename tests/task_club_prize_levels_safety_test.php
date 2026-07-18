<?php
declare(strict_types=1);

function tcPrizeLevelsTestAssert(bool $condition, string $message): void
{
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

function tcPrizeLevelsTestJson(array $value): string
{
  $encoded = json_encode(
    $value,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
  );
  if (!is_string($encoded)) {
    throw new RuntimeException('Test fixture JSON encoding failed.');
  }
  return $encoded . PHP_EOL;
}

function tcPrizeLevelsTestWrite(string $path, string $payload): void
{
  $written = file_put_contents($path, $payload, LOCK_EX);
  if (!is_int($written) || $written !== strlen($payload)) {
    throw new RuntimeException('Unable to write isolated prize-level test fixture.');
  }
}

function tcPrizeLevelsTestRemoveTree(string $path): void
{
  if (!is_dir($path)) {
    return;
  }
  $items = scandir($path);
  if (!is_array($items)) {
    return;
  }
  foreach ($items as $item) {
    if ($item === '.' || $item === '..') {
      continue;
    }
    $child = $path . DIRECTORY_SEPARATOR . $item;
    if (is_dir($child) && !is_link($child)) {
      tcPrizeLevelsTestRemoveTree($child);
    } else {
      @unlink($child);
    }
  }
  @rmdir($path);
}

function tcPrizeLevelsTestValidRow(string $id, string $name, int $score): array
{
  return [
    'id' => $id,
    'name' => $name,
    'type' => 'value_sum',
    'score' => $score,
    'description' => '',
    'buttonText' => '',
    'potSettings' => [
      'title' => '',
      'winnerLimit' => 1,
      'prizeName' => '',
      'locked' => false
    ]
  ];
}

$workerMode = ($argv[1] ?? '') === 'optimistic-worker';
$workerTargetPath = $workerMode ? (string)($argv[2] ?? '') : '';
$testRoot = $workerMode
  ? dirname($workerTargetPath)
  : sys_get_temp_dir()
    . DIRECTORY_SEPARATOR
    . 'tc-prize-levels-safety-'
    . bin2hex(random_bytes(8));
if (!$workerMode && !mkdir($testRoot, 0700, true) && !is_dir($testRoot)) {
  fwrite(STDERR, "Unable to create isolated prize-level test directory.\n");
  exit(1);
}

define('TC_PRIZE_LEVELS_ALLOWED_DIRECTORY', $testRoot);
require_once dirname(__DIR__) . '/mini apps/Task Club/prize_levels_store.php';

if ($workerMode) {
  $expectedVersion = (string)($argv[3] ?? '');
  $marker = (string)($argv[4] ?? 'worker');
  $snapshot = tcPrizeLevelsReadSnapshot($workerTargetPath);
  if (!($snapshot['ok'] ?? false) || !isset($snapshot['records'][0])) {
    fwrite(STDERR, "Worker could not read the prize-level snapshot.\n");
    exit(2);
  }
  $records = $snapshot['records'];
  $records[0]['description'] = $marker;
  $result = tcPrizeLevelsReplaceIfVersion($workerTargetPath, $records, $expectedVersion);
  $encodedResult = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($encodedResult)) {
    fwrite(STDERR, "Worker could not encode its optimistic result.\n");
    exit(2);
  }
  fwrite(STDOUT, $encodedResult . PHP_EOL);
  exit(($result['ok'] ?? false) || ($result['reason'] ?? '') === 'conflict' ? 0 : 2);
}

$exitCode = 0;
try {
  $path = $testRoot . DIRECTORY_SEPARATOR . 'TC Prize Levels.json';
  tcPrizeLevelsTestAssert(tcPrizeLevelsDefaultPath() === $path, 'Default path escaped the isolated allowed directory.');

  $legacyRows = [
    [
      'name' => 'Bronze',
      'score' => '10',
      'description' => 'توضیح، با ویرگول',
      'buttonText' => 'Choose prize',
      'potSettings' => [
        'title' => 'Bronze pot',
        'winnerLimit' => '2',
        'prizeName' => 'Gift A',
        'locked' => false,
        'customPotField' => ['keep' => true]
      ],
      'customTopLevelField' => ['doNotLose' => 17]
    ],
    [
      'id' => 'lvl_gold',
      'name' => 'Gold',
      'type' => 'pot',
      'score' => 20,
      'description' => 'Gold description',
      'buttonText' => 'Open pot',
      'potSettings' => [
        'title' => 'Gold pot',
        'winnerLimit' => 3,
        'prizeName' => 'Gift B',
        'locked' => true
      ],
      'futureSchemaField' => 'preserved'
    ]
  ];
  tcPrizeLevelsTestWrite($path, tcPrizeLevelsTestJson($legacyRows));

  $first = tcPrizeLevelsReadSnapshot($path);
  tcPrizeLevelsTestAssert(($first['ok'] ?? false) === true, 'Legacy snapshot failed.');
  tcPrizeLevelsTestAssert(($first['migrated'] ?? false) === true, 'Missing legacy ID was not persistently migrated.');
  tcPrizeLevelsTestAssert(count($first['records'] ?? []) === 2, 'Legacy migration lost a prize-level row.');
  tcPrizeLevelsTestAssert(is_file($path . '.lock'), 'Read did not use the shared sidecar lock.');
  tcPrizeLevelsTestAssert(is_file($path . '.bak'), 'Migration did not create a valid recovery backup.');

  $migratedRows = $first['records'];
  $generatedId = (string)($migratedRows[0]['id'] ?? '');
  tcPrizeLevelsTestAssert(preg_match('/^[A-Za-z0-9._-]{1,96}$/D', $generatedId) === 1, 'Generated legacy ID is invalid.');
  tcPrizeLevelsTestAssert(($migratedRows[0]['type'] ?? '') === 'value_sum', 'Legacy type was not normalized.');
  tcPrizeLevelsTestAssert(($migratedRows[1]['type'] ?? '') === 'pot', 'Pot type was not preserved.');
  tcPrizeLevelsTestAssert(($migratedRows[0]['description'] ?? '') === 'توضیح، با ویرگول', 'Description data was lost.');
  tcPrizeLevelsTestAssert(($migratedRows[0]['buttonText'] ?? '') === 'Choose prize', 'Button text was lost.');
  tcPrizeLevelsTestAssert(($migratedRows[0]['customTopLevelField']['doNotLose'] ?? null) === 17, 'Unknown top-level schema data was lost.');
  tcPrizeLevelsTestAssert(($migratedRows[0]['potSettings']['customPotField']['keep'] ?? null) === true, 'Unknown pot settings data was lost.');

  $liveAfterMigration = tcPrizeLevelsDecodeFile($path);
  tcPrizeLevelsTestAssert(($liveAfterMigration['ok'] ?? false) === true, 'Migrated live file is not strictly readable.');
  tcPrizeLevelsTestAssert(count($liveAfterMigration['records'] ?? []) === 2, 'Persisted migration changed row count.');
  tcPrizeLevelsTestAssert(($liveAfterMigration['records'][0]['id'] ?? '') === $generatedId, 'Generated legacy ID was not persisted.');
  $backupAfterMigration = tcPrizeLevelsDecodeFile($path . '.bak');
  tcPrizeLevelsTestAssert(($backupAfterMigration['ok'] ?? false) === true, 'Migration backup is invalid.');
  tcPrizeLevelsTestAssert(($backupAfterMigration['records'] ?? null) === ($liveAfterMigration['records'] ?? null), 'Backup does not match the migrated live records.');

  $second = tcPrizeLevelsReadSnapshot($path);
  tcPrizeLevelsTestAssert(($second['ok'] ?? false) === true, 'Second snapshot failed.');
  tcPrizeLevelsTestAssert(($second['migrated'] ?? true) === false, 'Legacy ID migration repeated instead of persisting.');
  tcPrizeLevelsTestAssert(($second['records'][0]['id'] ?? '') === $generatedId, 'Deterministic migrated ID changed on reread.');
  tcPrizeLevelsTestAssert(($second['version'] ?? '') === ($first['version'] ?? ''), 'Stable snapshot version changed without a write.');

  $baseVersion = (string)$second['version'];
  $replacement = $second['records'];
  $replacement[0]['description'] = 'Updated safely';
  $replacement[0]['potSettings']['futureNestedField'] = ['still' => 'here'];
  $replacement[0]['futureFloatField'] = 1.0;
  $replacement[] = [
    'name' => 'Outside value',
    'type' => 'out_of_value',
    'score' => 30,
    'description' => 'Third row',
    'buttonText' => 'See result',
    'potSettings' => ['title' => 'Third', 'winnerLimit' => 1, 'prizeName' => '', 'locked' => false],
    'anotherFutureField' => 99
  ];

  $committed = tcPrizeLevelsReplaceIfVersion($path, $replacement, $baseVersion);
  tcPrizeLevelsTestAssert(($committed['ok'] ?? false) === true, 'Valid optimistic replacement failed.');
  tcPrizeLevelsTestAssert(count($committed['records'] ?? []) === 3, 'Valid replacement lost or added rows.');
  tcPrizeLevelsTestAssert(($committed['backupSynced'] ?? false) === true, 'Successful commit did not synchronize its backup.');
  tcPrizeLevelsTestAssert(($committed['records'][2]['type'] ?? '') === 'out_of_value', 'Out-of-value type was not preserved.');
  tcPrizeLevelsTestAssert(preg_match('/^[A-Za-z0-9._-]{1,96}$/D', (string)($committed['records'][2]['id'] ?? '')) === 1, 'Replacement did not generate a valid missing ID.');
  tcPrizeLevelsTestAssert(($committed['records'][0]['potSettings']['futureNestedField']['still'] ?? '') === 'here', 'Replacement lost nested pot schema data.');
  tcPrizeLevelsTestAssert(is_float($committed['records'][0]['futureFloatField'] ?? null), 'Commit changed a future floating-point field type.');
  tcPrizeLevelsTestAssert(($committed['records'][2]['anotherFutureField'] ?? null) === 99, 'Replacement lost unknown top-level data.');
  tcPrizeLevelsTestAssert(($committed['version'] ?? '') === tcPrizeLevelsVersion($committed['records']), 'Commit returned the wrong version.');

  $afterCommit = tcPrizeLevelsReadSnapshot($path);
  tcPrizeLevelsTestAssert(($afterCommit['ok'] ?? false) === true, 'Committed file could not be read.');
  tcPrizeLevelsTestAssert(($afterCommit['records'] ?? null) === ($committed['records'] ?? null), 'Commit did not return the exact stored records.');
  tcPrizeLevelsTestAssert(($afterCommit['version'] ?? '') === ($committed['version'] ?? ''), 'Commit did not return the exact stored version.');
  // Simulate today's browser, which round-trips only canonical fields. Future
  // server-side fields must be merged back by stable ID instead of disappearing.
  $canonicalOnlyRows = array_map(static function (array $row): array {
    $pot = is_array($row['potSettings'] ?? null) ? $row['potSettings'] : [];
    return [
      'id' => $row['id'],
      'name' => $row['name'],
      'type' => $row['type'],
      'score' => $row['score'],
      'description' => $row['description'],
      'buttonText' => $row['buttonText'],
      'potSettings' => [
        'title' => $pot['title'] ?? '',
        'winnerLimit' => $pot['winnerLimit'] ?? 1,
        'prizeName' => $pot['prizeName'] ?? '',
        'locked' => $pot['locked'] ?? false
      ]
    ];
  }, $afterCommit['records']);
  $canonicalOnlyRows[0]['description'] = 'Known submitted field wins';
  $canonicalOnlyRows[0]['potSettings']['title'] = 'Known pot field wins';
  $preservedCommit = tcPrizeLevelsReplaceIfVersion(
    $path,
    $canonicalOnlyRows,
    (string)$afterCommit['version']
  );
  tcPrizeLevelsTestAssert(($preservedCommit['ok'] ?? false) === true, 'Canonical-only client commit failed.');
  tcPrizeLevelsTestAssert(($preservedCommit['records'][0]['description'] ?? '') === 'Known submitted field wins', 'Known submitted top-level field did not override current data.');
  tcPrizeLevelsTestAssert(($preservedCommit['records'][0]['potSettings']['title'] ?? '') === 'Known pot field wins', 'Known submitted pot field did not override current data.');
  tcPrizeLevelsTestAssert(($preservedCommit['records'][0]['customTopLevelField']['doNotLose'] ?? null) === 17, 'Canonical-only client erased an unknown stored field.');
  tcPrizeLevelsTestAssert(($preservedCommit['records'][0]['potSettings']['customPotField']['keep'] ?? null) === true, 'Canonical-only client erased an unknown stored pot field.');
  tcPrizeLevelsTestAssert(($preservedCommit['records'][0]['potSettings']['futureNestedField']['still'] ?? '') === 'here', 'Canonical-only client erased a future nested pot field.');
  tcPrizeLevelsTestAssert(($preservedCommit['records'][2]['anotherFutureField'] ?? null) === 99, 'Canonical-only client erased a future field on another row.');

  $afterPreservedCommit = tcPrizeLevelsReadSnapshot($path);
  tcPrizeLevelsTestAssert(($afterPreservedCommit['records'] ?? null) === ($preservedCommit['records'] ?? null), 'Preserved-field commit differs from stored records.');

  // Two browser saves based on the same revision must serialize through the
  // same sidecar lock. Exactly one may commit; the other must see a conflict.
  $raceVersion = (string)$afterPreservedCommit['version'];
  $workers = [];
  foreach (['optimistic-worker-a', 'optimistic-worker-b'] as $marker) {
    $pipes = [];
    $process = proc_open(
      [PHP_BINARY, __FILE__, 'optimistic-worker', $path, $raceVersion, $marker],
      [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
      $pipes
    );
    tcPrizeLevelsTestAssert(is_resource($process), 'Unable to start optimistic-lock worker.');
    fclose($pipes[0]);
    $workers[] = [$process, $pipes];
  }
  $raceSuccesses = 0;
  $raceConflicts = 0;
  foreach ($workers as [$process, $pipes]) {
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $workerExit = proc_close($process);
    tcPrizeLevelsTestAssert($workerExit === 0, 'Optimistic-lock worker failed: ' . trim((string)$stderr));
    $workerResult = json_decode((string)$stdout, true);
    tcPrizeLevelsTestAssert(is_array($workerResult), 'Optimistic-lock worker returned invalid JSON.');
    if (($workerResult['ok'] ?? false) === true) {
      $raceSuccesses++;
    } elseif (($workerResult['reason'] ?? '') === 'conflict') {
      $raceConflicts++;
    }
  }
  tcPrizeLevelsTestAssert($raceSuccesses === 1 && $raceConflicts === 1, 'Concurrent same-revision saves did not produce one commit and one conflict.');

  $afterCommit = tcPrizeLevelsReadSnapshot($path);
  tcPrizeLevelsTestAssert(($afterCommit['ok'] ?? false) === true, 'Snapshot after concurrent optimistic saves failed.');
  tcPrizeLevelsTestAssert(in_array((string)($afterCommit['records'][0]['description'] ?? ''), ['optimistic-worker-a', 'optimistic-worker-b'], true), 'Concurrent winner was not the exact stored record.');
  tcPrizeLevelsTestAssert(($afterCommit['records'][0]['customTopLevelField']['doNotLose'] ?? null) === 17, 'Concurrent save erased an unknown stored field.');
  tcPrizeLevelsTestAssert(($afterCommit['records'][0]['potSettings']['customPotField']['keep'] ?? null) === true, 'Concurrent save erased an unknown stored pot field.');
  $currentVersion = (string)$afterCommit['version'];
  $goodBytes = file_get_contents($path);
  tcPrizeLevelsTestAssert(is_string($goodBytes), 'Unable to capture valid committed bytes.');
  $goodHash = hash('sha256', $goodBytes);

  $staleRows = $afterCommit['records'];
  $staleRows[0]['description'] = 'This stale write must not land';
  $conflict = tcPrizeLevelsReplaceIfVersion($path, $staleRows, $baseVersion);
  tcPrizeLevelsTestAssert(($conflict['ok'] ?? true) === false, 'Stale revision was accepted.');
  tcPrizeLevelsTestAssert(($conflict['reason'] ?? '') === 'conflict', 'Stale revision did not return an explicit conflict.');
  tcPrizeLevelsTestAssert(($conflict['records'] ?? null) === ($afterCommit['records'] ?? null), 'Conflict did not return current records.');
  tcPrizeLevelsTestAssert(($conflict['version'] ?? '') === $currentVersion, 'Conflict did not return current version.');
  tcPrizeLevelsTestAssert(hash_file('sha256', $path) === $goodHash, 'Stale revision changed the live file.');

  $invalidCases = [];
  $duplicateIds = $afterCommit['records'];
  $duplicateIds[1]['id'] = $duplicateIds[0]['id'];
  $invalidCases[] = ['duplicate_id', $duplicateIds];
  $duplicateScores = $afterCommit['records'];
  $duplicateScores[1]['score'] = $duplicateScores[0]['score'];
  $invalidCases[] = ['duplicate_score', $duplicateScores];
  $invalidId = $afterCommit['records'];
  $invalidId[0]['id'] = '../escape';
  $invalidCases[] = ['invalid_id', $invalidId];
  $invalidScore = $afterCommit['records'];
  $invalidScore[0]['score'] = 0;
  $invalidCases[] = ['invalid_score', $invalidScore];
  $invalidType = $afterCommit['records'];
  $invalidType[0]['type'] = 'unexpected_type';
  $invalidCases[] = ['invalid_type', $invalidType];
  $malformed = $afterCommit['records'];
  $malformed[1] = 'not-a-row';
  $invalidCases[] = ['malformed_row', $malformed];

  foreach ($invalidCases as [$expectedReason, $invalidRows]) {
    $result = tcPrizeLevelsReplaceIfVersion($path, $invalidRows, $currentVersion);
    tcPrizeLevelsTestAssert(($result['ok'] ?? true) === false, $expectedReason . ' replacement was accepted.');
    tcPrizeLevelsTestAssert(($result['reason'] ?? '') === $expectedReason, $expectedReason . ' did not fail closed with the expected reason.');
    tcPrizeLevelsTestAssert(hash_file('sha256', $path) === $goodHash, $expectedReason . ' replacement changed the live file.');
  }

  // Simulate an interruption after the live rename but before backup refresh.
  // A valid but stale backup must be detected and synchronized on the next
  // locked read; validity alone is not enough for safe recovery.
  $staleBackupRows = [$afterCommit['records'][0]];
  tcPrizeLevelsTestWrite($path . '.bak', tcPrizeLevelsTestJson($staleBackupRows));
  $staleBackupRepair = tcPrizeLevelsReadSnapshot($path);
  tcPrizeLevelsTestAssert(($staleBackupRepair['ok'] ?? false) === true, 'Snapshot failed while repairing a stale valid backup.');
  tcPrizeLevelsTestAssert(($staleBackupRepair['backupSynced'] ?? false) === true, 'Stale valid backup was not reported synchronized.');
  $repairedBackup = tcPrizeLevelsDecodeFile($path . '.bak');
  tcPrizeLevelsTestAssert(($repairedBackup['ok'] ?? false) === true, 'Repaired backup is invalid.');
  tcPrizeLevelsTestAssert(($repairedBackup['records'] ?? null) === ($afterCommit['records'] ?? null), 'Stale valid backup was not updated to every live row.');
  tcPrizeLevelsTestAssert(hash_file('sha256', $path) === $goodHash, 'Stale-backup repair changed the live file.');

  $sentinelPath = $testRoot . DIRECTORY_SEPARATOR . 'Setting.json';
  tcPrizeLevelsTestWrite($sentinelPath, "critical-setting-must-stay\n");
  $sentinelHash = hash_file('sha256', $sentinelPath);
  $blocked = tcPrizeLevelsReadSnapshot($sentinelPath);
  tcPrizeLevelsTestAssert(($blocked['ok'] ?? true) === false && ($blocked['reason'] ?? '') === 'path_not_allowed', 'A non-prize filename was not blocked.');
  tcPrizeLevelsTestAssert(hash_file('sha256', $sentinelPath) === $sentinelHash, 'Path rejection changed an unrelated file.');

  $nestedDirectory = $testRoot . DIRECTORY_SEPARATOR . 'nested';
  tcPrizeLevelsTestAssert(mkdir($nestedDirectory, 0700), 'Unable to make nested path-isolation fixture.');
  $nestedPath = $nestedDirectory . DIRECTORY_SEPARATOR . 'TC Prize Levels.json';
  tcPrizeLevelsTestWrite($nestedPath, tcPrizeLevelsTestJson([tcPrizeLevelsTestValidRow('nested', 'Nested', 1)]));
  $nestedHash = hash_file('sha256', $nestedPath);
  $nestedBlocked = tcPrizeLevelsReadSnapshot($nestedPath);
  tcPrizeLevelsTestAssert(($nestedBlocked['ok'] ?? true) === false && ($nestedBlocked['reason'] ?? '') === 'path_not_allowed', 'Nested same-name path escaped isolation.');
  tcPrizeLevelsTestAssert(hash_file('sha256', $nestedPath) === $nestedHash, 'Nested path rejection changed the nested file.');

  // A JSON object must not be confused with an empty list by PHP's
  // associative decoder, and a strict failure must not rewrite it.
  @unlink($path . '.bak');
  $objectBytes = "{}\n";
  tcPrizeLevelsTestWrite($path, $objectBytes);
  $objectResult = tcPrizeLevelsReadSnapshot($path);
  tcPrizeLevelsTestAssert(($objectResult['ok'] ?? true) === false && ($objectResult['reason'] ?? '') === 'not_a_list', 'JSON object was accepted as a prize-level list.');
  tcPrizeLevelsTestAssert(file_get_contents($path) === $objectBytes, 'Invalid JSON container was rewritten.');

  $strictDiskCases = [
    [
      'duplicate_id',
      [
        tcPrizeLevelsTestValidRow('same_id', 'One', 10),
        tcPrizeLevelsTestValidRow('same_id', 'Two', 20)
      ]
    ],
    [
      'duplicate_score',
      [
        tcPrizeLevelsTestValidRow('score_one', 'One', 10),
        tcPrizeLevelsTestValidRow('score_two', 'Two', 10)
      ]
    ],
    [
      'malformed_row',
      [tcPrizeLevelsTestValidRow('valid_first', 'One', 10), null]
    ],
    [
      'invalid_type',
      [array_replace(tcPrizeLevelsTestValidRow('invalid_type', 'One', 10), ['type' => 'unexpected_type'])]
    ]
  ];
  foreach ($strictDiskCases as [$expectedReason, $fixture]) {
    @unlink($path . '.bak');
    $fixtureBytes = tcPrizeLevelsTestJson($fixture);
    tcPrizeLevelsTestWrite($path, $fixtureBytes);
    $result = tcPrizeLevelsReadSnapshot($path);
    tcPrizeLevelsTestAssert(($result['ok'] ?? true) === false, 'Corrupt on-disk ' . $expectedReason . ' data was accepted.');
    tcPrizeLevelsTestAssert(($result['reason'] ?? '') === $expectedReason, 'Corrupt on-disk ' . $expectedReason . ' returned the wrong reason.');
    tcPrizeLevelsTestAssert(file_get_contents($path) === $fixtureBytes, 'Corrupt on-disk ' . $expectedReason . ' data was silently filtered or rewritten.');
  }

  // Restore the last known-good snapshot and let a read create a fresh valid
  // backup. A later broken live file must recover every row from that backup.
  tcPrizeLevelsTestWrite($path, $goodBytes);
  @unlink($path . '.bak');
  $restoredGood = tcPrizeLevelsReadSnapshot($path);
  tcPrizeLevelsTestAssert(($restoredGood['ok'] ?? false) === true && count($restoredGood['records'] ?? []) === 3, 'Unable to restore the valid three-row snapshot.');
  tcPrizeLevelsTestAssert(is_file($path . '.bak'), 'Valid snapshot did not recreate its backup.');
  $expectedRecoveryRecords = $restoredGood['records'];
  $expectedRecoveryVersion = $restoredGood['version'];

  tcPrizeLevelsTestWrite($path, "{broken-json\n");
  $recovered = tcPrizeLevelsReadSnapshot($path);
  tcPrizeLevelsTestAssert(($recovered['ok'] ?? false) === true, 'Valid backup did not recover a corrupt live file.');
  tcPrizeLevelsTestAssert(($recovered['recovered'] ?? false) === true, 'Backup recovery was not reported.');
  tcPrizeLevelsTestAssert(($recovered['records'] ?? null) === $expectedRecoveryRecords, 'Backup recovery lost or changed prize-level rows.');
  tcPrizeLevelsTestAssert(($recovered['version'] ?? '') === $expectedRecoveryVersion, 'Backup recovery returned the wrong version.');
  $decodedRecovered = tcPrizeLevelsDecodeFile($path);
  tcPrizeLevelsTestAssert(($decodedRecovered['ok'] ?? false) === true, 'Recovered live file is invalid.');
  tcPrizeLevelsTestAssert(($decodedRecovered['records'] ?? null) === $expectedRecoveryRecords, 'Recovered live file does not contain every expected row.');

  tcPrizeLevelsTestAssert(unlink($path), 'Unable to set up missing-live recovery test.');
  $missingRecovered = tcPrizeLevelsReadSnapshot($path);
  tcPrizeLevelsTestAssert(($missingRecovered['ok'] ?? false) === true, 'Valid backup did not recover a missing live file.');
  tcPrizeLevelsTestAssert(($missingRecovered['recovered'] ?? false) === true, 'Missing-live backup recovery was not reported.');
  tcPrizeLevelsTestAssert(($missingRecovered['records'] ?? null) === $expectedRecoveryRecords, 'Missing-live recovery lost or changed rows.');

  fwrite(STDOUT, "Task Club prize-level store safety test passed.\n");
} catch (Throwable $error) {
  $exitCode = 1;
  fwrite(STDERR, 'Task Club prize-level store safety test failed: ' . $error->getMessage() . PHP_EOL);
} finally {
  $resolvedTestRoot = realpath($testRoot);
  $resolvedTempRoot = realpath(sys_get_temp_dir());
  if (is_string($resolvedTestRoot) && is_string($resolvedTempRoot)
    && dirname($resolvedTestRoot) === $resolvedTempRoot
    && str_starts_with(basename($resolvedTestRoot), 'tc-prize-levels-safety-')) {
    tcPrizeLevelsTestRemoveTree($resolvedTestRoot);
  }
}

exit($exitCode);
