<?php
declare(strict_types=1);

$workerPath = ($argv[1] ?? '') === 'worker' ? (string)($argv[2] ?? '') : '';
$directory = $workerPath !== ''
  ? dirname($workerPath)
  : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tc-prize-inventory-' . bin2hex(random_bytes(6));
if ($workerPath === '' && !mkdir($directory, 0700, true)) {
  fwrite(STDERR, "FAIL: temporary directory could not be created\n");
  exit(1);
}
define('TC_PRIZE_INVENTORY_ALLOWED_DIRECTORY', $directory);
require_once dirname(__DIR__) . '/mini apps/Task Club/prize_inventory_store.php';

if (($argv[1] ?? '') === 'worker') {
  $path = (string)($argv[2] ?? '');
  $awardId = (string)($argv[3] ?? '');
  $records = tcPrizeInventoryReadForUpdate($path);
  if (!is_array($records) || !isset($records[0])) exit(1);
  $records[0]['last'] = max(0, (int)$records[0]['last'] - 1);
  $records[0]['pendingAwardIds'][] = $awardId;
  exit(tcPrizeInventoryCommit($path, $records) ? 0 : 1);
}

function tcInventoryAssert(bool $condition, string $message): void
{
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
}

function tcInventoryRemoveTree(string $path): void
{
  if (!is_dir($path)) return;
  foreach (scandir($path) ?: [] as $name) {
    if ($name === '.' || $name === '..') continue;
    $item = $path . DIRECTORY_SEPARATOR . $name;
    is_dir($item) ? tcInventoryRemoveTree($item) : @unlink($item);
  }
  @rmdir($path);
}

$path = $directory . DIRECTORY_SEPARATOR . 'TC Prizes.json';

try {
  $initial = [[
    'name' => 'Gift', 'onWheelName' => 'Gift', 'quantity' => 5, 'last' => 5, 'value' => 100, 'isFake' => false
  ]];
  tcInventoryAssert(tcPrizeInventoryCommit($path, $initial), 'initial inventory commit failed');
  $snapshot = tcPrizeInventoryReadSnapshot($path);
  tcInventoryAssert(is_array($snapshot) && count($snapshot) === 1, 'inventory snapshot failed');
  tcInventoryAssert((string)($snapshot[0]['id'] ?? '') !== '', 'stable prize ID was not generated');
  tcInventoryAssert(is_file($path . '.bak'), 'inventory backup was not created');
  $staleBackup = $snapshot;
  $staleBackup[0]['last'] = 4;
  tcInventoryAssert(file_put_contents($path . '.bak', (string)json_encode($staleBackup), LOCK_EX) !== false, 'stale-backup setup failed');
  $afterBackupRepair = tcPrizeInventoryReadSnapshot($path);
  $repairedBackup = tcPrizeInventoryDecodeFile($path . '.bak');
  tcInventoryAssert(
    is_array($afterBackupRepair) && is_array($repairedBackup)
      && tcPrizeInventoryVersion($afterBackupRepair) === tcPrizeInventoryVersion($repairedBackup),
    'stale inventory backup was not synchronized to the live generation'
  );

  $version = tcPrizeInventoryVersion($snapshot);
  $edited = $snapshot;
  $edited[0]['value'] = 150;
  $actualVersion = null;
  $committed = null;
  tcInventoryAssert(tcPrizeInventoryReplaceIfVersion($path, $edited, $version, $actualVersion, $committed), 'versioned save failed');
  tcInventoryAssert(is_array($committed) && (float)($committed[0]['value'] ?? 0) === 150.0, 'exact committed records were not returned');
  tcInventoryAssert($actualVersion === tcPrizeInventoryVersion($committed), 'returned version does not hash returned records');
  tcInventoryAssert(!tcPrizeInventoryReplaceIfVersion($path, $snapshot, $version, $actualVersion), 'stale inventory version was accepted');
  $preAwardSnapshot = tcPrizeInventoryReadSnapshot($path);
  $preAwardVersion = tcPrizeInventoryVersion($preAwardSnapshot ?? []);

  $workers = [];
  foreach (['award-a', 'award-b'] as $awardId) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', $path, $awardId], [
      0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']
    ], $pipes);
    tcInventoryAssert(is_resource($process), 'worker could not start');
    fclose($pipes[0]);
    $workers[] = [$process, $pipes];
  }
  foreach ($workers as [$process, $pipes]) {
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    tcInventoryAssert(proc_close($process) === 0, 'worker failed: ' . trim($output));
  }
  $after = tcPrizeInventoryReadSnapshot($path);
  tcInventoryAssert((int)($after[0]['last'] ?? -1) === 3, 'concurrent decrements were lost');
  tcInventoryAssert(count($after[0]['pendingAwardIds'] ?? []) === 2, 'pending award markers were lost');
  tcInventoryAssert(
    !tcPrizeInventoryReplaceIfVersion($path, $preAwardSnapshot ?? [], $preAwardVersion, $actualVersion),
    'admin save racing later awards restored consumed inventory'
  );
  $pendingVersion = tcPrizeInventoryVersion($after);
  tcInventoryAssert(!tcPrizeInventoryReplaceIfVersion($path, [], $pendingVersion, $actualVersion), 'admin deletion discarded pending recovery markers');
  $unsafePendingEdit = $after;
  $unsafePendingEdit[0]['last'] = 5;
  tcInventoryAssert(!tcPrizeInventoryReplaceIfVersion($path, $unsafePendingEdit, $pendingVersion, $actualVersion), 'admin edit changed a prize with pending recovery markers');

  tcInventoryAssert(file_put_contents($path, '', LOCK_EX) !== false, 'corruption setup failed');
  $recovered = tcPrizeInventoryReadSnapshot($path);
  tcInventoryAssert(is_array($recovered) && (int)($recovered[0]['last'] ?? -1) === 3, 'empty inventory did not recover from backup');
  $damaged = json_encode([$recovered[0], 'malformed-row'], JSON_UNESCAPED_UNICODE);
  tcInventoryAssert(is_string($damaged) && file_put_contents($path, $damaged, LOCK_EX) !== false, 'malformed-row setup failed');
  $strictlyRecovered = tcPrizeInventoryReadSnapshot($path);
  tcInventoryAssert(is_array($strictlyRecovered) && count($strictlyRecovered) === 1, 'JSON-valid malformed row was silently accepted');
  tcInventoryAssert((int)($strictlyRecovered[0]['last'] ?? -1) === 3, 'strict recovery changed valid inventory data');
  $objectRootBytes = '{"0":{"id":"object-root","name":"Object root","quantity":1,"last":1,"value":1,"isFake":false}}';
  tcInventoryAssert(file_put_contents($path, $objectRootBytes, LOCK_EX) !== false, 'object-root corruption setup failed');
  $objectRootRecovered = tcPrizeInventoryReadSnapshot($path);
  tcInventoryAssert(is_array($objectRootRecovered) && count($objectRootRecovered) === 1, 'object-shaped inventory root was accepted as a list');
  $fractionalStock = $objectRootRecovered;
  $fractionalStock[0]['quantity'] = 3.5;
  tcInventoryAssert(file_put_contents($path, (string)json_encode($fractionalStock), LOCK_EX) !== false, 'fractional-stock corruption setup failed');
  $fractionalRecovered = tcPrizeInventoryReadSnapshot($path);
  tcInventoryAssert(
    is_array($fractionalRecovered) && (int)($fractionalRecovered[0]['quantity'] ?? -1) === 5,
    'fractional stock was silently truncated instead of recovered: ' . json_encode($fractionalRecovered)
  );
  tcInventoryAssert(unlink($path), 'missing inventory recovery setup failed');
  $missingRecovered = tcPrizeInventoryReadSnapshot($path);
  tcInventoryAssert(is_array($missingRecovered) && (int)($missingRecovered[0]['last'] ?? -1) === 3, 'missing inventory was not restored from its backup');
  $legacyBytes = '[{"name":"Legacy gift","onWheelName":"Legacy gift","quantity":2,"last":2,"value":50,"isFake":false}]';
  tcInventoryAssert(file_put_contents($path, $legacyBytes, LOCK_EX) !== false, 'legacy-ID migration setup failed');
  $migrated = tcPrizeInventoryReadSnapshot($path);
  tcInventoryAssert(is_array($migrated) && trim((string)($migrated[0]['id'] ?? '')) !== '', 'legacy inventory ID was not generated');
  $persistedMigration = json_decode((string)file_get_contents($path), true);
  tcInventoryAssert(is_array($persistedMigration) && trim((string)($persistedMigration[0]['id'] ?? '')) !== '', 'legacy inventory ID migration was not persisted');
  tcInventoryAssert(!tcPrizeInventoryCommit($directory . DIRECTORY_SEPARATOR . 'Setting.json', []), 'inventory writer accepted an unrelated path');
  tcInventoryAssert(!tcPrizeInventoryCommit(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'TC Prizes.json', []), 'inventory writer accepted another application directory');
  @unlink($path . '.bak');
  $unrecoverableBytes = '{inventory must not be overwritten';
  tcInventoryAssert(file_put_contents($path, $unrecoverableBytes, LOCK_EX) !== false, 'unrecoverable inventory setup failed');
  tcInventoryAssert(tcPrizeInventoryReadSnapshot($path) === null, 'corrupt inventory without a backup was accepted');
  tcInventoryAssert(file_get_contents($path) === $unrecoverableBytes, 'corrupt inventory bytes were overwritten without recovery data');

  fwrite(STDOUT, "Task Club prize inventory safety test passed.\n");
} finally {
  tcPrizeInventoryEnd($path);
  tcInventoryRemoveTree($directory);
}
