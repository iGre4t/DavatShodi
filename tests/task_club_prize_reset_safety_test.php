<?php
declare(strict_types=1);

function tcPrizeResetTestAssert(bool $condition, string $message): void
{
  if (!$condition) throw new RuntimeException($message);
}

function tcPrizeResetTestRemoveTree(string $path): void
{
  if (!is_dir($path)) return;
  foreach (scandir($path) ?: [] as $name) {
    if ($name === '.' || $name === '..') continue;
    $item = $path . DIRECTORY_SEPARATOR . $name;
    is_dir($item) ? tcPrizeResetTestRemoveTree($item) : @unlink($item);
  }
  @rmdir($path);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tc-prize-reset-' . bin2hex(random_bytes(6));
$eventDir = $root . DIRECTORY_SEPARATOR . 'TC Event';
tcPrizeResetTestAssert(mkdir($eventDir, 0700, true), 'temporary event directory could not be created');
define('TC_PRIZE_INVENTORY_ALLOWED_DIRECTORY', $root);
require_once dirname(__DIR__) . '/mini apps/Task Club/prize_award_reset.php';

$csvPath = $eventDir . DIRECTORY_SEPARATOR . 'Invitees mapped.csv';
$ledgerPath = $eventDir . DIRECTORY_SEPARATOR . 'TC Prize Awards Log.json';
$inventoryPath = $root . DIRECTORY_SEPARATOR . 'TC Prizes.json';
$awardId = 'tc-award-reset-safety-0001';

try {
  $rows = [
    ['Employee Number', 'Card Flips Count', 'Each Level Won Prize', 'Total Prize Won', 'Reward Level Won IDs', 'Untouched'],
    ['work-1', '2', '["Coffee","Gift card"]', '700', '["level-1","level-2"]', 'keep-me'],
    ['work-2', '0', '[]', '0', '[]', 'other-row']
  ];
  tcPrizeResetTestAssert(tcInviteesCsvCommitRows($csvPath, $rows), 'CSV fixture commit failed');
  tcPrizeResetTestAssert(
    file_put_contents($eventDir . DIRECTORY_SEPARATOR . 'TC Mapped.json', '{"workId":0}') !== false,
    'mapped Work ID fixture could not be created'
  );
  tcPrizeResetTestAssert(tcPrizeInventoryCommit($inventoryPath, [[
    'id' => 'gift-card', 'name' => 'Gift card inventory', 'onWheelName' => 'Gift card',
    'quantity' => 5, 'last' => 3, 'value' => 500, 'isFake' => false
  ]]), 'inventory fixture commit failed');
  tcPrizeResetTestAssert(tcPrizeAwardLogAppend($ledgerPath, [
    'awardId' => $awardId,
    'status' => 'awarded',
    'awardedAt' => '2026-07-22T10:00:00+00:00',
    'workId' => 'work-1',
    'userName' => 'Test User',
    'cardIndex' => 3,
    'level' => ['id' => 'level-2', 'name' => 'Gold'],
    'prize' => ['id' => 'gift-card', 'name' => 'Gift card', 'inventoryName' => 'Gift card inventory', 'value' => 500]
  ]), 'award fixture commit failed');

  $result = tcPrizeResetAward($ledgerPath, $csvPath, $inventoryPath, $awardId, 'test-admin');
  tcPrizeResetTestAssert($result['ok'], 'valid reset failed: ' . $result['message']);
  $afterRows = tcInviteesCsvReadRowsSnapshot($csvPath);
  tcPrizeResetTestAssert(count($afterRows) === count($rows), 'reset changed the CSV row count');
  tcPrizeResetTestAssert($afterRows[2] === $rows[2], 'reset changed an unrelated user row');
  tcPrizeResetTestAssert(($afterRows[1][1] ?? '') === '1', 'card flip count was not reduced');
  tcPrizeResetTestAssert(($afterRows[1][2] ?? '') === '["Coffee"]', 'matching prize name was not removed');
  tcPrizeResetTestAssert(($afterRows[1][3] ?? '') === '200', 'prize total was not reduced');
  tcPrizeResetTestAssert(($afterRows[1][4] ?? '') === '["level-1"]', 'matching prize level was not removed');
  tcPrizeResetTestAssert(($afterRows[1][5] ?? '') === 'keep-me', 'an unrelated target-row cell changed');
  $inventory = tcPrizeInventoryReadSnapshot($inventoryPath);
  tcPrizeResetTestAssert((int)($inventory[0]['last'] ?? -1) === 4, 'one inventory item was not restored');
  $award = tcPrizeResetFindAward(tcPrizeAwardLogRead($ledgerPath), $awardId);
  tcPrizeResetTestAssert(($award['status'] ?? '') === 'reset', 'award ledger was not marked reset');

  $csvHash = hash_file('sha256', $csvPath);
  $inventoryHash = hash_file('sha256', $inventoryPath);
  $again = tcPrizeResetAward($ledgerPath, $csvPath, $inventoryPath, $awardId, 'test-admin');
  tcPrizeResetTestAssert($again['ok'] && !empty($again['alreadyReset']), 'repeat reset was not idempotent');
  tcPrizeResetTestAssert(hash_file('sha256', $csvPath) === $csvHash, 'repeat reset changed the CSV');
  tcPrizeResetTestAssert(hash_file('sha256', $inventoryPath) === $inventoryHash, 'repeat reset restored inventory twice');

  $mismatchAwardId = 'tc-award-reset-safety-0002';
  tcPrizeResetTestAssert(tcPrizeAwardLogAppend($ledgerPath, [
    'awardId' => $mismatchAwardId,
    'status' => 'awarded',
    'workId' => 'work-1',
    'level' => ['id' => 'level-1', 'name' => 'Silver'],
    'prize' => ['id' => 'gift-card', 'name' => 'Wrong prize name', 'inventoryName' => 'Gift card inventory', 'value' => 200]
  ]), 'mismatch award fixture commit failed');
  $csvHash = hash_file('sha256', $csvPath);
  $inventoryHash = hash_file('sha256', $inventoryPath);
  $ledgerHash = hash_file('sha256', $ledgerPath);
  $rejected = tcPrizeResetAward($ledgerPath, $csvPath, $inventoryPath, $mismatchAwardId, 'test-admin');
  tcPrizeResetTestAssert(!$rejected['ok'], 'mismatched prize history was accepted');
  tcPrizeResetTestAssert(hash_file('sha256', $csvPath) === $csvHash, 'rejected reset changed the CSV');
  tcPrizeResetTestAssert(hash_file('sha256', $inventoryPath) === $inventoryHash, 'rejected reset changed inventory');
  tcPrizeResetTestAssert(hash_file('sha256', $ledgerPath) === $ledgerHash, 'rejected reset changed the ledger');

  fwrite(STDOUT, "Task Club prize reset safety test passed.\n");
} finally {
  tcInviteesCsvEndTransaction($csvPath);
  tcPrizeInventoryEnd($inventoryPath);
  tcPrizeResetTestRemoveTree($root);
}
