<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/egm-seat-map.php';

function seatAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$map = egmSeatMapNormalize(['enabled' => true, 'ticketId' => 'vip', 'rows' => [10, 8, 12]]);
seatAssert($map['rows'] === [10, 8, 12], 'Different chair counts per row were not retained.');

$grouped = egmSeatMapNormalize(['enabled' => true, 'ticketId' => 'vip', 'segments' => [[14, 14], [6, 15, 6]]]);
seatAssert($grouped['rows'] === [28, 27] && $grouped['segments'][1] === [6, 15, 6], 'Aisle blocks were not retained.');
seatAssert(egmSeatMapFindContiguous($grouped['rows'], [], 16, $grouped['segments']) === [], 'A contiguous group incorrectly crossed an aisle.');
$blockSeat = egmSeatMapFindContiguous([4], ['1:1' => true, '1:2' => true], 2, [[2, 2]]);
seatAssert($blockSeat === [['row' => 1, 'chair' => 3, 'block' => 2], ['row' => 1, 'chair' => 4, 'block' => 2]], 'Chair numbering did not continue across blocks.');
$closest = egmSeatMapFindClosest([[2, 2]], [], 3);
seatAssert(count($closest) === 3 && $closest[0]['row'] === 1 && $closest[0]['chair'] === 1 && $closest[2]['chair'] === 3, 'Closest split seats were not offered.');
$largeGroup = egmSeatMapFindClosest(array_fill(0, 15, [13, 13]), [], 30);
seatAssert(count($largeGroup) === 30
    && count(array_filter($largeGroup, static fn(array $seat): bool => $seat['row'] === 1)) === 15
    && count(array_filter($largeGroup, static fn(array $seat): bool => $seat['row'] === 2)) === 15,
    'A split 30-person group should stay balanced across two rows.');
$blockOnly = egmSeatMapFindContiguous(array_fill(0, 15, 26), [], 30, array_fill(0, 15, [13, 13]));
seatAssert(count(array_unique(array_column($blockOnly, 'row'))) > count(array_unique(array_column($largeGroup, 'row'))),
    'Row-first allocation must take priority over a narrow block spanning more rows.');
$salonBlocks = array_merge(array_fill(0, 11, [14, 14]), array_fill(0, 6, [6, 15, 6]));
$salonRows = array_map('array_sum', $salonBlocks);
$row14 = egmSeatMapFindContiguous($salonRows, [], 14, $salonBlocks);
seatAssert(count($row14) === 14 && count(array_unique(array_column($row14, 'row'))) === 1
    && $row14[0]['row'] === 1 && $row14[13]['chair'] === 14,
    'A party that fits one block should stay in the earliest row.');
$usedForParties = [];
foreach ([4, 5, 6, 7] as $partySize) {
    $party = egmSeatMapFindContiguous($salonRows, $usedForParties, $partySize, $salonBlocks);
    seatAssert(count($party) === $partySize && count(array_unique(array_column($party, 'row'))) === 1
        && $party[0]['row'] === 1, 'Small parties opened new rows before filling the first row.');
    foreach ($party as $seat) $usedForParties[$seat['row'] . ':' . $seat['chair']] = true;
}
$rectangle30 = egmSeatMapFindContiguous($salonRows, [], 30, $salonBlocks);
seatAssert(count(array_filter($rectangle30, static fn(array $seat): bool => $seat['row'] === 12)) === 15
    && count(array_filter($rectangle30, static fn(array $seat): bool => $seat['row'] === 13)) === 15,
    'A 30-person party should use the 15-chair middle block in two rows.');
$alignedBlocks = egmSeatMapFindContiguous([4, 4], [], 3, [[2, 2], [2, 2]]);
seatAssert($alignedBlocks === [
    ['row' => 1, 'chair' => 1, 'block' => 1], ['row' => 1, 'chair' => 2, 'block' => 1],
    ['row' => 2, 'chair' => 1, 'block' => 1],
], 'Aligned neighboring blocks were not treated as one group.');

$single = egmSeatMapFindContiguous([10, 8], ['1:1' => true], 5);
seatAssert($single === [
    ['row' => 1, 'chair' => 2], ['row' => 1, 'chair' => 3],
    ['row' => 1, 'chair' => 4], ['row' => 1, 'chair' => 5], ['row' => 1, 'chair' => 6],
], 'A free run in the earliest row was not preferred.');

$used = [];
foreach ([1, 2, 3, 4, 5, 6] as $chair) $used['1:' . $chair] = true;
foreach ([1, 2, 3, 4, 5, 6] as $chair) $used['2:' . $chair] = true;
$split = egmSeatMapFindContiguous([10, 8], $used, 5);
seatAssert($split === [
    ['row' => 1, 'chair' => 7], ['row' => 1, 'chair' => 8],
    ['row' => 1, 'chair' => 9], ['row' => 2, 'chair' => 7],
    ['row' => 2, 'chair' => 8],
], 'Neighboring rows did not form a balanced rectangle.');

$misaligned = [];
foreach ([1, 2, 3, 4, 5, 6] as $chair) $misaligned['1:' . $chair] = true;
foreach ([5, 6, 7, 8, 9, 10] as $chair) $misaligned['2:' . $chair] = true;
seatAssert(egmSeatMapFindContiguous([10, 10], $misaligned, 5) === [], 'Separated blocks were incorrectly assigned to one group.');

$full = [];
foreach ([1, 2] as $row) foreach ([1, 2, 3] as $chair) $full[$row . ':' . $chair] = true;
seatAssert(egmSeatMapFindContiguous([3, 3], $full, 1) === [], 'A full salon still returned a chair.');

echo "Seat map allocation checks passed.\n";
