<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/egm-check-in.php';

function egmGuestTimelineAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$timezone = new DateTimeZone('Asia/Tehran');
$period = [
    'tagCode' => '01',
    'active' => false,
    'duration' => true,
    'quitRequired' => true,
    'startDate' => '2026-08-18',
    'startTime' => '09:00',
    'enterDeadlineDate' => '2026-08-18',
    'enterDeadlineTime' => '10:00',
    'quitOpeningDate' => '2026-08-18',
    'quitOpeningTime' => '16:00',
    'endDate' => '2026-08-18',
    'endTime' => '17:00',
];

$cases = [
    ['08:59:59', 'upcoming', null],
    ['09:00:00', 'entry_time', 'entry'],
    ['09:59:59', 'entry_time', 'entry'],
    ['10:00:00', 'immune_time', null],
    ['15:59:59', 'immune_time', null],
    ['16:00:00', 'quit_time', 'quit'],
    ['16:59:59', 'quit_time', 'quit'],
    ['17:00:00', 'ended', null],
];

foreach ($cases as [$time, $reason, $action]) {
    $status = egmCheckInPeriodAvailability(
        $period,
        new DateTimeImmutable('2026-08-18 ' . $time, $timezone)
    );
    egmGuestTimelineAssert($status['reason'] === $reason, "Unexpected phase at {$time}");
    egmGuestTimelineAssert($status['action'] === $action, "Unexpected attendance action at {$time}");
}

$invalid = $period;
$invalid['quitOpeningTime'] = '09:30';
$invalidStatus = egmCheckInPeriodAvailability($invalid, new DateTimeImmutable('2026-08-18 09:15:00', $timezone));
egmGuestTimelineAssert($invalidStatus['reason'] === 'invalid_schedule', 'Invalid timeline ordering was accepted');

fwrite(STDOUT, "EGM guest control timeline test passed.\n");
