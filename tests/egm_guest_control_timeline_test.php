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

$flexible = $period;
$flexible['quitTimelineRequired'] = false;
$flexible['minimumStayMinutes'] = 5;
$flexibleStatus = egmCheckInPeriodAvailability($flexible, new DateTimeImmutable('2026-08-18 12:00:00', $timezone));
egmGuestTimelineAssert($flexibleStatus['eligible'] === true, 'Flexible attendance was not eligible during the period.');
egmGuestTimelineAssert($flexibleStatus['reason'] === 'flexible_attendance', 'Flexible attendance returned the wrong phase.');
egmGuestTimelineAssert($flexibleStatus['action'] === 'auto', 'Flexible attendance did not defer entry/quit choice to the invitation state.');
egmGuestTimelineAssert(egmCheckInMinimumStayMinutes($flexible) === 5, 'Minimum stay was not read from period settings.');
egmGuestTimelineAssert(egmCheckInMinimumStayMinutes(['minimumStayMinutes' => 0]) === 1, 'Minimum stay lower bound failed.');
egmGuestTimelineAssert(egmCheckInMinimumStayMinutes(['minimumStayMinutes' => 2000]) === 1440, 'Minimum stay upper bound failed.');

$noAttendance = ['entered_date' => null, 'entered_time' => null, 'quit_date' => null, 'quit_time' => null];
$entryDecision = egmCheckInFlexibleAttendanceDecision(
    $noAttendance,
    new DateTimeImmutable('2026-08-18 12:00:00', $timezone),
    5,
    false
);
egmGuestTimelineAssert($entryDecision['action'] === 'entry', 'A guest without entry was not assigned entry.');

$entered = ['entered_date' => '2026-08-18', 'entered_time' => '11:58:00', 'quit_date' => null, 'quit_time' => null];
$earlyQuit = egmCheckInFlexibleAttendanceDecision(
    $entered,
    new DateTimeImmutable('2026-08-18 12:00:00', $timezone),
    5,
    false
);
egmGuestTimelineAssert(!$earlyQuit['eligible'] && $earlyQuit['reason'] === 'minimum_stay', 'Early quit was not blocked.');
egmGuestTimelineAssert($earlyQuit['remaining_minutes'] === 3, 'Early quit returned the wrong remaining duration.');

$normalQuit = egmCheckInFlexibleAttendanceDecision(
    $entered,
    new DateTimeImmutable('2026-08-18 12:03:00', $timezone),
    5,
    false
);
egmGuestTimelineAssert($normalQuit['eligible'] && $normalQuit['action'] === 'quit', 'Quit after minimum stay was not allowed.');

$waveQuit = egmCheckInFlexibleAttendanceDecision(
    $entered,
    new DateTimeImmutable('2026-08-18 11:59:00', $timezone),
    5,
    true
);
egmGuestTimelineAssert($waveQuit['eligible'] && $waveQuit['action'] === 'quit', 'Quit wave did not bypass minimum stay.');
$waveEntry = egmCheckInFlexibleAttendanceDecision(
    $noAttendance,
    new DateTimeImmutable('2026-08-18 12:00:00', $timezone),
    5,
    true
);
egmGuestTimelineAssert(!$waveEntry['eligible'] && $waveEntry['reason'] === 'entry_closed_quit_wave', 'Quit wave did not close new entries.');
egmGuestTimelineAssert(!egmCheckInQuitWaveActive(10, 2), 'Exactly 20% incorrectly activated the quit wave.');
egmGuestTimelineAssert(egmCheckInQuitWaveActive(10, 3), 'More than 20% did not activate the quit wave.');

fwrite(STDOUT, "EGM guest control timeline test passed.\n");
