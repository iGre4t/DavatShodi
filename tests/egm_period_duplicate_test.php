<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/lib/egm-period-duplicate.php';
require_once __DIR__ . '/../api/lib/egm-check-in.php';

$source = [
    'id' => 'old', 'tagCode' => '001', 'title' => 'صبح', 'active' => true,
    'duration' => true, 'startDate' => '2026-10-05', 'quitRequired' => true,
    'prizeEntryWindowEnabled' => true, 'endedAt' => '2026-10-05 15:00:00',
    'endedNoQuitCount' => 2,
];
$copy = egmPeriodDuplicateRow($source, 'new', '002', 2);
if ($copy['id'] !== 'new' || $copy['tagCode'] !== '002' || $copy['title'] !== 'کپی صبح'
    || $copy['active'] !== false || $copy['activationPending'] !== true
    || $copy['endedAt'] !== '' || $copy['endedNoQuitCount'] !== 0
    || $copy['duration'] !== true || $copy['quitRequired'] !== true
    || $copy['prizeEntryWindowEnabled'] !== true || $copy['startDate'] !== '2026-10-05') {
    throw new RuntimeException('Period settings or fresh runtime state were not copied correctly.');
}
if ($source['active'] !== true || $source['endedAt'] === '') {
    throw new RuntimeException('Duplicating mutated the source period.');
}
$timezone = new DateTimeZone('Asia/Tehran');
$scheduled = egmPeriodDuplicateRow([
    'id' => 'scheduled', 'tagCode' => '003', 'title' => 'زمان‌بندی',
    'duration' => true, 'startDate' => '2026-10-05', 'startTime' => '10:00',
    'endDate' => '2026-10-05', 'endTime' => '12:00',
], 'scheduled-copy', '004', 3);
$availability = egmCheckInPeriodAvailability($scheduled, new DateTimeImmutable('2026-10-05 11:00', $timezone));
if ($availability['reason'] !== 'inactive' || $availability['eligible'] !== false) {
    throw new RuntimeException('A duplicated scheduled period became active before its settings were saved.');
}

$draws = ['draw-1' => [
    'id' => 'draw-1', 'name' => 'جایزه', 'winnerLimit' => 3,
    'locked' => true, 'winners' => [['participantKey' => '10']],
    'pending' => ['admin' => ['key' => '11']],
]];
$drawCopies = egmPeriodDuplicateDraws($draws);
if ($drawCopies['draw-1']['name'] !== 'جایزه' || $drawCopies['draw-1']['winnerLimit'] !== 3
    || $drawCopies['draw-1']['locked'] !== false || $drawCopies['draw-1']['winners'] !== []
    || $drawCopies['draw-1']['pending'] !== [] || count($draws['draw-1']['winners']) !== 1) {
    throw new RuntimeException('Draw configuration or result reset was incorrect.');
}

echo "EGM period duplicate tests passed.\n";
