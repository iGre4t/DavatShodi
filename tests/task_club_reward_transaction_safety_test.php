<?php
declare(strict_types=1);

$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tc-reward-transaction-' . bin2hex(random_bytes(6));
$eventDirectory = $temporaryRoot . DIRECTORY_SEPARATOR . 'TC Event';
define('TC_PRIZE_INVENTORY_ALLOWED_DIRECTORY', $eventDirectory);
ob_start();
define('TCM_FUNCTIONS_ONLY', true);
require_once dirname(__DIR__) . '/mini apps/Task Club/TCM.php';
ob_end_clean();

function tcRewardTransactionAssert(bool $condition, string $message): void
{
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

function tcRewardTransactionRemoveTree(string $path): void
{
  if (!is_dir($path)) return;
  foreach (scandir($path) ?: [] as $name) {
    if ($name === '.' || $name === '..') continue;
    $item = $path . DIRECTORY_SEPARATOR . $name;
    if (is_dir($item) && !is_link($item)) {
      tcRewardTransactionRemoveTree($item);
    } else {
      @unlink($item);
    }
  }
  @rmdir($path);
}

function tcRewardTransactionRemoveTempRoot(string $path): void
{
  $resolvedPath = realpath($path);
  $resolvedTemp = realpath(sys_get_temp_dir());
  if (
    !is_string($resolvedPath)
    || !is_string($resolvedTemp)
    || strcasecmp(dirname($resolvedPath), $resolvedTemp) !== 0
    || !str_starts_with(basename($resolvedPath), 'tc-reward-transaction-')
  ) {
    return;
  }
  tcRewardTransactionRemoveTree($resolvedPath);
}

function tcRewardTransactionLedgerEntry(
  string $awardId,
  string $requestId,
  string $workId,
  string $levelId,
  int $cardIndex
): array {
  return [
    'awardId' => $awardId,
    'requestId' => $requestId,
    'cardIndex' => $cardIndex,
    'status' => 'inventory_reserved',
    'source' => 'TCM.reward_flip',
    'workId' => $workId,
    'level' => ['id' => $levelId, 'name' => 'Test level'],
    'prize' => [
      'id' => 'prize-main',
      'name' => 'Test prize',
      'inventoryName' => 'Test prize',
      'inventoryBefore' => 5,
      'inventoryAfter' => 4
    ]
  ];
}

function tcRewardTransactionFindAward(array $entries, string $awardId): array
{
  foreach ($entries as $entry) {
    if (is_array($entry) && (string)($entry['awardId'] ?? '') === $awardId) {
      return $entry;
    }
  }
  throw new RuntimeException('award ledger entry was not found: ' . $awardId);
}

function tcRewardTransactionReconcile(
  string $ledgerPath,
  string $inventoryPath,
  array $rows,
  int $workIdIndex,
  int $wonLevelIdsIndex
): bool {
  $prizes = tcPrizeInventoryReadForUpdate($inventoryPath);
  if (!is_array($prizes)) return false;
  try {
    return tcRewardReconcileAwardTransactions(
      $ledgerPath,
      $inventoryPath,
      $prizes,
      $rows,
      $workIdIndex,
      $wonLevelIdsIndex
    );
  } finally {
    tcPrizeInventoryEnd($inventoryPath);
  }
}

$weightedPrizes = [
  ['name' => 'Fake prize', 'last' => 50, 'isFake' => true],
  ['name' => 'Empty prize', 'last' => 0, 'isFake' => false],
  ['name' => 'First real prize', 'last' => 2, 'isFake' => false],
  ['name' => '', 'onWheelName' => '', 'last' => 100, 'isFake' => false],
  ['name' => 'Second real prize', 'last' => 3, 'isFake' => false]
];
tcRewardTransactionAssert(tcRewardWeightedPrizeIndex($weightedPrizes, 1) === 2, 'ticket 1 did not select the first weighted prize');
tcRewardTransactionAssert(tcRewardWeightedPrizeIndex($weightedPrizes, 2) === 2, 'ticket 2 did not select the first weighted prize');
tcRewardTransactionAssert(tcRewardWeightedPrizeIndex($weightedPrizes, 3) === 4, 'ticket 3 did not cross to the second weighted prize');
tcRewardTransactionAssert(tcRewardWeightedPrizeIndex($weightedPrizes, 5) === 4, 'last valid ticket did not select the second weighted prize');
tcRewardTransactionAssert(tcRewardWeightedPrizeIndex($weightedPrizes, 0) === -1, 'ticket zero was accepted');
tcRewardTransactionAssert(tcRewardWeightedPrizeIndex($weightedPrizes, 6) === -1, 'out-of-range ticket was accepted');
tcRewardTransactionAssert(
  tcRewardWeightedPrizeIndex([
    ['name' => 'Fake only', 'last' => 5, 'isFake' => true],
    ['name' => 'Zero only', 'last' => 0, 'isFake' => false]
  ], 1) === -1,
  'an ineligible inventory produced a winner'
);
tcRewardTransactionAssert(
  tcRewardWeightedPrizeIndex([
    ['name' => 'Maximum weight', 'last' => PHP_INT_MAX, 'isFake' => false],
    ['name' => 'Overflow weight', 'last' => 1, 'isFake' => false]
  ]) === -1,
  'weighted inventory integer overflow was not rejected'
);

$specialPrizeNames = ['Comma, prize', 'Colon: prize', '"quoted" prize', 'جایزه فارسی', "Line one\nLine two"];
$specialPrizeJson = serializeRewardStoredList($specialPrizeNames);
$specialPrizeHistoryValid = false;
$decodedSpecialPrizeNames = parseRewardPrizeNames($specialPrizeJson, $specialPrizeHistoryValid);
tcRewardTransactionAssert($specialPrizeHistoryValid, 'valid structured prize history was rejected');
tcRewardTransactionAssert($decodedSpecialPrizeNames === $specialPrizeNames, 'structured prize names did not round-trip exactly');
$malformedHistoryValid = true;
tcRewardTransactionAssert(
  parseRewardStoredList('[{"broken":true}]', $malformedHistoryValid) === [] && !$malformedHistoryValid,
  'object-shaped reward history entry was not rejected'
);
$truncatedHistoryValid = true;
tcRewardTransactionAssert(
  parseRewardPrizeNames('["unfinished"', $truncatedHistoryValid) === [] && !$truncatedHistoryValid,
  'truncated structured reward history fell back to legacy parsing'
);

$inventoryPath = $eventDirectory . DIRECTORY_SEPARATOR . 'TC Prizes.json';
$ledgerPath = $eventDirectory . DIRECTORY_SEPARATOR . 'TC Prize Awards Log.json';
$failure = null;

try {
  tcRewardTransactionAssert(mkdir($eventDirectory, 0700, true), 'temporary TC Event directory could not be created');

  $workId = 'work-1001';
  $restoreAwardId = 'award-restore-once';
  $restoreRequestId = 'request-restore-0001';
  $restoreLevelId = 'level-restore';
  tcRewardTransactionAssert(tcPrizeInventoryCommit($inventoryPath, [[
    'id' => 'prize-main',
    'name' => 'Test prize',
    'onWheelName' => 'Test prize',
    'quantity' => 5,
    'last' => 4,
    'value' => 100,
    'isFake' => false,
    'pendingAwardIds' => [$restoreAwardId]
  ]]), 'reserved inventory setup failed');
  tcRewardTransactionAssert(tcPrizeAwardLogAppend(
    $ledgerPath,
    tcRewardTransactionLedgerEntry($restoreAwardId, $restoreRequestId, $workId, $restoreLevelId, 2)
  ), 'reserved award ledger setup failed');

  $rowsWithoutWin = [
    ['Work ID', 'Reward Level Won IDs'],
    [$workId, serializeRewardStoredList([])]
  ];
  tcRewardTransactionAssert(
    tcRewardTransactionReconcile($ledgerPath, $inventoryPath, $rowsWithoutWin, 0, 1),
    'reservation rollback reconciliation failed'
  );
  $afterRestore = tcPrizeInventoryReadSnapshot($inventoryPath);
  tcRewardTransactionAssert(is_array($afterRestore) && count($afterRestore) === 1, 'restored inventory could not be read');
  tcRewardTransactionAssert((int)($afterRestore[0]['last'] ?? -1) === 5, 'reserved unit was not restored');
  tcRewardTransactionAssert(($afterRestore[0]['pendingAwardIds'] ?? null) === [], 'restored reservation marker was not removed');
  $restoredLedgerEntry = tcRewardTransactionFindAward(tcPrizeAwardLogRead($ledgerPath), $restoreAwardId);
  tcRewardTransactionAssert(($restoredLedgerEntry['status'] ?? '') === 'cancelled', 'rolled-back ledger entry was not cancelled');
  tcRewardTransactionAssert(($restoredLedgerEntry['inventoryRestored'] ?? null) === true, 'ledger did not record inventory restoration');
  tcRewardTransactionAssert(
    ($restoredLedgerEntry['cancellationReason'] ?? '') === 'recovered_before_csv_commit',
    'rollback cancellation reason was incorrect'
  );

  tcRewardTransactionAssert(
    tcRewardTransactionReconcile($ledgerPath, $inventoryPath, $rowsWithoutWin, 0, 1),
    'second rollback reconciliation failed'
  );
  $afterSecondRestore = tcPrizeInventoryReadSnapshot($inventoryPath);
  tcRewardTransactionAssert((int)($afterSecondRestore[0]['last'] ?? -1) === 5, 'repeated reconciliation restored inventory twice');
  tcRewardTransactionAssert(($afterSecondRestore[0]['pendingAwardIds'] ?? null) === [], 'repeated reconciliation recreated a marker');

  $finalizeAwardId = 'award-finalize-once';
  $finalizeRequestId = 'request-finalize-0001';
  $finalizeLevelId = 'level-finalized';
  $finalizeCardIndex = 7;
  tcRewardTransactionAssert(tcPrizeInventoryCommit($inventoryPath, [[
    'id' => 'prize-main',
    'name' => 'Test prize',
    'onWheelName' => 'Test prize',
    'quantity' => 5,
    'last' => 3,
    'value' => 100,
    'isFake' => false,
    'pendingAwardIds' => [$finalizeAwardId]
  ]]), 'CSV-committed inventory setup failed');
  tcRewardTransactionAssert(tcPrizeAwardLogAppend(
    $ledgerPath,
    tcRewardTransactionLedgerEntry(
      $finalizeAwardId,
      $finalizeRequestId,
      $workId,
      $finalizeLevelId,
      $finalizeCardIndex
    )
  ), 'CSV-committed ledger setup failed');

  $rowsWithWin = [
    ['Work ID', 'Reward Level Won IDs'],
    [$workId, serializeRewardStoredList([$finalizeLevelId])]
  ];
  tcRewardTransactionAssert(
    tcRewardTransactionReconcile($ledgerPath, $inventoryPath, $rowsWithWin, 0, 1),
    'CSV-committed reconciliation failed'
  );
  $afterFinalize = tcPrizeInventoryReadSnapshot($inventoryPath);
  tcRewardTransactionAssert((int)($afterFinalize[0]['last'] ?? -1) === 3, 'CSV-committed award incorrectly restored inventory');
  tcRewardTransactionAssert(($afterFinalize[0]['pendingAwardIds'] ?? null) === [], 'CSV-committed marker was not removed');
  $finalizedEntries = tcPrizeAwardLogRead($ledgerPath);
  $finalizedLedgerEntry = tcRewardTransactionFindAward($finalizedEntries, $finalizeAwardId);
  tcRewardTransactionAssert(($finalizedLedgerEntry['status'] ?? '') === 'awarded', 'CSV-committed ledger entry was not finalized');
  tcRewardTransactionAssert(trim((string)($finalizedLedgerEntry['reconciledAt'] ?? '')) !== '', 'finalized entry has no reconciliation timestamp');

  tcRewardTransactionAssert(
    tcRewardTransactionReconcile($ledgerPath, $inventoryPath, $rowsWithWin, 0, 1),
    'second finalize reconciliation failed'
  );
  $afterSecondFinalize = tcPrizeInventoryReadSnapshot($inventoryPath);
  tcRewardTransactionAssert((int)($afterSecondFinalize[0]['last'] ?? -1) === 3, 'repeated finalize reconciliation changed inventory');
  tcRewardTransactionAssert(($afterSecondFinalize[0]['pendingAwardIds'] ?? null) === [], 'repeated finalize reconciliation recreated a marker');

  $idempotent = tcRewardFindRequestEntry(
    $finalizedEntries,
    $finalizeRequestId,
    $workId,
    $finalizeLevelId,
    $finalizeCardIndex
  );
  tcRewardTransactionAssert(
    is_array($idempotent) && ($idempotent['awardId'] ?? '') === $finalizeAwardId && empty($idempotent['requestConflict']),
    'same request body was not recognized as idempotent'
  );
  $workConflict = tcRewardFindRequestEntry($finalizedEntries, $finalizeRequestId, 'work-2002', $finalizeLevelId, $finalizeCardIndex);
  tcRewardTransactionAssert(
    is_array($workConflict) && !empty($workConflict['requestConflict']) && ($workConflict['conflictReason'] ?? '') === 'work_id',
    'changed work ID did not produce a request conflict'
  );
  $levelConflict = tcRewardFindRequestEntry($finalizedEntries, $finalizeRequestId, $workId, 'level-changed', $finalizeCardIndex);
  tcRewardTransactionAssert(
    is_array($levelConflict) && !empty($levelConflict['requestConflict']) && ($levelConflict['conflictReason'] ?? '') === 'level_id',
    'changed level ID did not produce a request conflict'
  );
  $cardConflict = tcRewardFindRequestEntry($finalizedEntries, $finalizeRequestId, $workId, $finalizeLevelId, $finalizeCardIndex + 1);
  tcRewardTransactionAssert(
    is_array($cardConflict) && !empty($cardConflict['requestConflict']) && ($cardConflict['conflictReason'] ?? '') === 'card_index',
    'changed card index did not produce a request conflict'
  );
  tcRewardTransactionAssert(
    tcRewardFindRequestEntry($finalizedEntries, 'request-not-present', $workId, $finalizeLevelId, $finalizeCardIndex) === null,
    'unknown request ID returned an award'
  );

  $malformedAwardId = 'award-malformed-history';
  $malformedLevelId = 'level-malformed-history';
  tcRewardTransactionAssert(tcPrizeInventoryCommit($inventoryPath, [[
    'id' => 'prize-main',
    'name' => 'Test prize',
    'onWheelName' => 'Test prize',
    'quantity' => 5,
    'last' => 2,
    'value' => 100,
    'isFake' => false,
    'pendingAwardIds' => [$malformedAwardId]
  ]]), 'malformed-history inventory setup failed');
  tcRewardTransactionAssert(tcPrizeAwardLogAppend(
    $ledgerPath,
    tcRewardTransactionLedgerEntry($malformedAwardId, 'request-malformed-0001', $workId, $malformedLevelId, 3)
  ), 'malformed-history ledger setup failed');
  $malformedRows = [['Work ID', 'Reward Level Won IDs'], [$workId, '["truncated"']];
  tcRewardTransactionAssert(
    !tcRewardTransactionReconcile($ledgerPath, $inventoryPath, $malformedRows, 0, 1),
    'reconciliation accepted malformed structured reward history'
  );
  $afterMalformedHistory = tcPrizeInventoryReadSnapshot($inventoryPath);
  tcRewardTransactionAssert((int)($afterMalformedHistory[0]['last'] ?? -1) === 2, 'malformed history changed inventory');
  tcRewardTransactionAssert(
    ($afterMalformedHistory[0]['pendingAwardIds'] ?? []) === [$malformedAwardId],
    'malformed history removed the recovery marker'
  );
  tcRewardTransactionAssert(
    tcRewardTransactionReconcile(
      $ledgerPath,
      $inventoryPath,
      [['Work ID', 'Reward Level Won IDs'], [$workId, serializeRewardStoredList([$malformedLevelId])]],
      0,
      1
    ),
    'malformed-history test award could not be safely finalized'
  );

  $restorePhaseAwardId = 'award-restore-phase-committed';
  $restorePhaseEntry = tcRewardTransactionLedgerEntry(
    $restorePhaseAwardId,
    'request-restore-phase',
    $workId,
    'level-restore-phase',
    4
  );
  $restorePhaseEntry['status'] = 'inventory_restore_pending';
  $restorePhaseEntry['inventoryRestored'] = false;
  tcRewardTransactionAssert(
    tcPrizeAwardLogAppend($ledgerPath, $restorePhaseEntry),
    'restore-phase ledger setup failed'
  );
  tcRewardTransactionAssert(
    tcRewardTransactionReconcile(
      $ledgerPath,
      $inventoryPath,
      [['Work ID', 'Reward Level Won IDs'], [$workId, serializeRewardStoredList([])]],
      0,
      1
    ),
    'committed restoration phase could not finish after a simulated ledger-update failure'
  );
  $restorePhaseFinal = tcRewardTransactionFindAward(tcPrizeAwardLogRead($ledgerPath), $restorePhaseAwardId);
  tcRewardTransactionAssert(($restorePhaseFinal['status'] ?? '') === 'cancelled', 'restore phase was not finalized as cancelled');
  tcRewardTransactionAssert(($restorePhaseFinal['inventoryRestored'] ?? null) === true, 'restore phase lost its restoration result');

  $missingMarkerAwardId = 'award-reserved-marker-missing';
  tcRewardTransactionAssert(tcPrizeAwardLogAppend(
    $ledgerPath,
    tcRewardTransactionLedgerEntry($missingMarkerAwardId, 'request-missing-marker', $workId, 'level-marker-missing', 4)
  ), 'missing-marker ledger setup failed');
  tcRewardTransactionAssert(
    !tcRewardTransactionReconcile(
      $ledgerPath,
      $inventoryPath,
      [['Work ID', 'Reward Level Won IDs'], [$workId, serializeRewardStoredList([])]],
      0,
      1
    ),
    'reserved ledger entry without an inventory marker was silently cancelled'
  );

  $orphanMarkerId = 'award-marker-without-ledger';
  tcRewardTransactionAssert(tcPrizeInventoryCommit($inventoryPath, [[
    'id' => 'prize-main',
    'name' => 'Test prize',
    'onWheelName' => 'Test prize',
    'quantity' => 5,
    'last' => 1,
    'value' => 100,
    'isFake' => false,
    'pendingAwardIds' => [$orphanMarkerId]
  ]]), 'orphan-marker inventory setup failed');
  tcRewardTransactionAssert(
    !tcRewardTransactionReconcile(
      $ledgerPath,
      $inventoryPath,
      [['Work ID', 'Reward Level Won IDs'], [$workId, serializeRewardStoredList([])]],
      0,
      1
    ),
    'inventory marker without an audit entry was accepted'
  );
} catch (Throwable $error) {
  $failure = $error;
} finally {
  tcPrizeInventoryEnd($inventoryPath);
  tcRewardTransactionRemoveTempRoot($temporaryRoot);
}

if ($failure instanceof Throwable) {
  fwrite(STDERR, 'FAIL: ' . $failure->getMessage() . PHP_EOL);
  exit(1);
}

fwrite(STDOUT, "Task Club reward transaction safety test passed.\n");
