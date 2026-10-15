<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/mini apps/Event Guest Manager/prize_inventory_store.php';
require_once dirname(__DIR__) . '/api/lib/egm-competition-prizes.php';

$queue = egmCompetitionQueueValidate([2, 3, 4, 2, 3, 1]);
if ($queue !== [2, 3, 4, 2, 3, 1]) throw new RuntimeException('Queue order changed.');
foreach ([[0], [5], ['x'], array_fill(0, 101, 1)] as $invalid) {
    try { egmCompetitionQueueValidate($invalid); throw new RuntimeException('Invalid queue accepted.'); }
    catch (InvalidArgumentException $expected) {}
}
$inventory = egmPrizeInventoryNormalizeRecords([
    ['id' => 'a', 'name' => 'دوم', 'rank' => 2, 'quantity' => 2, 'last' => 1],
    ['id' => 'b', 'name' => 'ناموجود', 'rank' => 2, 'quantity' => 1, 'last' => 0],
    ['id' => 'c', 'name' => 'سوم', 'rank' => 3, 'quantity' => 1, 'last' => 1],
    ['id' => 'd', 'name' => 'نمایشی', 'rank' => 2, 'quantity' => 1, 'last' => 1, 'isFake' => true],
]);
if (array_column(egmCompetitionEligiblePrizes($inventory, 2), 'id') !== ['a']) {
    throw new RuntimeException('Prize selection ignored rank, stock, or fake flag.');
}
$state = egmCompetitionStatePublic(['confirmed' => [['prizeId' => 'a']], 'pending' => null], $queue, $inventory);
if ($state['nextRank'] !== 3 || $state['position'] !== 1) throw new RuntimeException('Queue did not advance after confirmation.');
echo "EGM competition prize rules passed.\n";
$awards = [['card' => 8, 'prizeId' => 'a', 'prizeName' => 'دوم']];
try { egmCompetitionRequireUnusedCard($awards, 8); throw new RuntimeException('Locked card accepted.'); }
catch (InvalidArgumentException $expected) {}
egmCompetitionRequireUnusedCard($awards, 8, false);
$reuse = egmCompetitionStatePublic(['confirmed' => $awards], $queue, $inventory, true, false);
if ($reuse['keepConfirmedCards'] !== false || count($reuse['confirmed']) !== 1 || $reuse['nextRank'] !== 3) throw new RuntimeException('Reusable cards lost history or queue progress.');
if (egmCompetitionAvailablePrizes($inventory, $awards, 2) !== []) throw new RuntimeException('Reusable cards repeated an awarded prize.');
