<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/egm-period-invites.php';

$map = egmSeatMapNormalize(['enabled' => true, 'ticketId' => 'cinema', 'segments' => [[14, 14], [6, 15, 6]]]);
$seats = egmPeriodInvitesNormalizeSeatSelection($map, [
    ['row' => 2, 'chair' => 27], ['row' => 1, 'chair' => 15], ['row' => 2, 'chair' => 7],
]);
if ($seats !== [
    ['row' => 1, 'chair' => 15, 'block' => 2],
    ['row' => 2, 'chair' => 7, 'block' => 2],
    ['row' => 2, 'chair' => 27, 'block' => 3],
]) throw new RuntimeException('Manual seat selection did not preserve row-wide chair numbers and blocks.');

foreach ([
    [['row' => 1, 'chair' => 15], ['row' => 1, 'chair' => 15]],
    [['row' => 2, 'chair' => 28]],
    [['row' => 3, 'chair' => 1]],
    [['row' => 1, 'chair' => 0]],
] as $invalid) {
    try {
        egmPeriodInvitesNormalizeSeatSelection($map, $invalid);
    } catch (InvalidArgumentException $error) {
        continue;
    }
    throw new RuntimeException('Invalid manual seat selection was accepted.');
}

echo "Manual seat selection checks passed.\n";
