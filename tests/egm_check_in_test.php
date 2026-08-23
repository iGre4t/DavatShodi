<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/common.php';
require_once dirname(__DIR__) . '/api/lib/egm-check-in.php';

function egmCheckInAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

egmCheckInAssert(egmCheckInNormalizeNationalId('۱۲۳۴۵۶۷۸۹۰') === '1234567890', 'Persian National ID digits were not normalized');
egmCheckInAssert(egmCheckInNormalizeNationalId('123456789') === '', 'A National ID shorter than 10 digits was accepted');
egmCheckInAssert(egmCheckInNormalizeGuestCode('۱۲۳۴۵۶۷۸') === '12345678', 'Persian Work ID digits were not normalized');
egmCheckInAssert(egmCheckInNormalizeGuestCode('123') === '', 'A Guest ID shorter than 4 digits was accepted');
egmCheckInAssert(egmCheckInNormalizeGuestCode('12345678901') === '', 'A Guest ID longer than 10 digits was accepted');
egmCheckInAssert(egmCheckInNormalizeWorkId('1234') === '1234', 'A 4-digit Work ID was rejected');
egmCheckInAssert(egmCheckInNormalizeWorkId('123456789') === '123456789', 'A 9-digit Work ID was rejected');
egmCheckInAssert(egmCheckInNormalizeWorkId('1234567890') === '', 'A 10-digit value was accepted as a Work ID');

$checkInSource = file_get_contents(dirname(__DIR__) . '/api/lib/egm-check-in.php');
egmCheckInAssert(is_string($checkInSource), 'Could not inspect the Guest Control frontend');
foreach (['SCANNER_MAX_KEY_GAP_MS=50', 'SCANNER_MIN_FAST_GAPS=3', 'SCANNER_COMPLETION_DELAY_MS=90', 'performance.now()', 'isSubmitting', "event.key==='Enter'", 'guest_code:guestCode'] as $scannerRequirement) {
    egmCheckInAssert(str_contains($checkInSource, $scannerRequirement), "Scanner requirement is missing: {$scannerRequirement}");
}
egmCheckInAssert(
    str_contains($checkInSource, 'setInterval(()=>void syncVisibleLogs(),LOG_SYNC_INTERVAL_MS)'),
    'Cross-PC record synchronization polling is missing'
);
egmCheckInAssert(
    !str_contains($checkInSource, 'setInterval(()=>void submit')
        && !str_contains($checkInSource, 'setInterval(enqueueScan'),
    'Scanner detection itself uses forbidden polling'
);
egmCheckInAssert(!str_contains($checkInSource, 'data-reset-all-records'), 'The destructive full-reset button is exposed in Guest Control');
egmCheckInAssert(!str_contains($checkInSource, 'reset-records-button'), 'Guest Control still contains reset-button UI');

$timezone = new DateTimeZone('Asia/Tehran');
$inside = new DateTimeImmutable('2026-08-18 10:30:00', $timezone);
$scheduledPeriod = [
    'tagCode' => '01', 'title' => 'Scheduled', 'duration' => true,
    'startDate' => '2026-08-18', 'startTime' => '10:00',
    'endDate' => '2026-08-18', 'endTime' => '11:00',
];
egmCheckInAssert(egmCheckInPeriodAvailability($scheduledPeriod, $inside)['eligible'], 'An active scheduled period was rejected');
egmCheckInAssert(!egmCheckInPeriodAvailability($scheduledPeriod, new DateTimeImmutable('2026-08-18 11:01:00', $timezone))['eligible'], 'An ended period was accepted');
egmCheckInAssert(
    egmCheckInSelectInvitation(
        [['id' => 1, 'period_code' => '01'], ['id' => 2, 'period_code' => '02']],
        [['tagCode' => '01', 'duration' => false], ['tagCode' => '02', 'duration' => false]],
        $inside
    )['result'] === 'ambiguous',
    'Multiple unscheduled invited periods were not rejected as ambiguous'
);

$egmPeriods = [
    ['tagCode' => 'P1', 'title' => 'Previous', 'duration' => true, 'startDate' => '2026-08-18', 'startTime' => '08:00', 'endDate' => '2026-08-18', 'endTime' => '09:00'],
    ['tagCode' => 'P2', 'title' => 'Current', 'duration' => true, 'startDate' => '2026-08-18', 'startTime' => '10:00', 'endDate' => '2026-08-18', 'endTime' => '11:00'],
    ['tagCode' => 'P3', 'title' => 'Next', 'duration' => true, 'startDate' => '2026-08-18', 'startTime' => '12:00', 'endDate' => '2026-08-18', 'endTime' => '13:00'],
];
$egmPeriodState = egmCheckInResolveEgmPeriodState($egmPeriods, $inside);
egmCheckInAssert(($egmPeriodState['result'] ?? '') === 'active', 'The EGM-wide active period was not resolved');
egmCheckInAssert(egmCheckInPeriodCode($egmPeriodState['period'] ?? []) === 'P2', 'The wrong EGM period was selected');
egmCheckInAssert(egmCheckInPeriodCode($egmPeriodState['previous'] ?? []) === 'P1', 'The previous period was not resolved');
egmCheckInAssert(egmCheckInPeriodCode($egmPeriodState['next'] ?? []) === 'P3', 'The next period was not resolved');
$betweenPeriods = egmCheckInResolveEgmPeriodState($egmPeriods, new DateTimeImmutable('2026-08-18 11:30:00', $timezone));
egmCheckInAssert(($betweenPeriods['result'] ?? '') === 'no_active_period', 'A gap between periods was treated as active');
egmCheckInAssert(egmCheckInPeriodCode($betweenPeriods['previous'] ?? []) === 'P2', 'Gap previous period is incorrect');
egmCheckInAssert(egmCheckInPeriodCode($betweenPeriods['next'] ?? []) === 'P3', 'Gap next period is incorrect');
$overlapState = egmCheckInResolveEgmPeriodState([
    $egmPeriods[1],
    ['tagCode' => 'PX', 'duration' => true, 'startDate' => '2026-08-18', 'startTime' => '10:15', 'endDate' => '2026-08-18', 'endTime' => '11:15'],
], $inside);
egmCheckInAssert(($overlapState['result'] ?? '') === 'multiple_active_periods', 'Overlapping active periods were not rejected');

$pdo = connectDatabase(loadConfig(dirname(__DIR__) . '/api/config.php'));
egmCheckInAssert($pdo instanceof PDO, 'Could not connect to the check-in test database');
$logsPdo = connectActivityLogDatabase(loadConfig(dirname(__DIR__) . '/api/config.php'));
egmCheckInAssert($logsPdo instanceof PDO, 'Could not connect to the check-in logs test database');
do {
    $code = '99' . (string)random_int(10000000, 99999999);
    $names = egmInstanceTableNames($code);
} while (egmInstanceTableExists($pdo, $names['data']));

try {
    $tables = ensureEgmInstanceTables($pdo, $code);
    ensureActivityLogTable($logsPdo, 'EGM', $code);
    egmCheckInAssert(egmInstanceColumnExists($pdo, $tables['user_periods'], 'entered_date'), 'entered_date was not provisioned');
    egmCheckInAssert(egmInstanceColumnExists($pdo, $tables['user_periods'], 'entered_time'), 'entered_time was not provisioned');
    egmCheckInAssert(egmInstanceColumnExists($pdo, $tables['user_periods'], 'quit_date'), 'quit_date was not provisioned');
    egmCheckInAssert(egmInstanceColumnExists($pdo, $tables['user_periods'], 'quit_time'), 'quit_time was not provisioned');
    foreach (['attendance_state', 'last_control_condition', 'last_control_action', 'last_control_message', 'last_control_at'] as $conditionColumn) {
        egmCheckInAssert(
            egmInstanceColumnExists($pdo, $tables['user_periods'], $conditionColumn),
            "{$conditionColumn} was not provisioned"
        );
    }
    foreach (['is_uninvited_guest', 'uninvited_registered_at', 'uninvited_registered_by'] as $walkInPeriodColumn) {
        egmCheckInAssert(
            egmInstanceColumnExists($pdo, $tables['user_periods'], $walkInPeriodColumn),
            "{$walkInPeriodColumn} was not provisioned on user periods"
        );
    }
    foreach (['is_uninvited_guest', 'outside_organization', 'uninvited_registered_at', 'uninvited_registered_by'] as $walkInUserColumn) {
        egmCheckInAssert(
            egmInstanceColumnExists($pdo, $tables['users'], $walkInUserColumn),
            "{$walkInUserColumn} was not provisioned on EGM users"
        );
    }
    $insertUser = $pdo->prepare(
        "INSERT INTO `{$tables['users']}` (`work_id`, `first_name`, `last_name`, `national_id`, `phone_number`, "
        . "`deputy`, `general_department`, `department`, `gender`, `postal_level`, `source_row`) "
        . "VALUES ('W-1', 'Test', 'Guest', '1234567890', '', '', '', '', '', '', 1)"
    );
    $insertUser->execute();
    $userId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO `{$tables['user_periods']}` (`user_id`, `period_code`, `invited_at`) "
        . "VALUES (:user_id, '01', '2026-08-18 09:00:00')"
    )->execute([':user_id' => $userId]);

    $basePeriod = ['tagCode' => '01', 'title' => 'Test Period', 'duration' => false];
    $context = [
        'pdo' => $pdo,
        'logs_pdo' => $logsPdo,
        'code' => $code,
        'name' => 'Check-in Test',
        'tables' => $tables,
        'periods' => [$basePeriod],
        'period' => $basePeriod,
        'period_code' => '01',
        'period_state' => ['result' => 'active'],
        'can_scan' => true,
    ];
    $firstTime = new DateTimeImmutable('2026-08-18 10:15:30', $timezone);
    $first = egmCheckInProcess($context, '1234567890', $firstTime);
    egmCheckInAssert($first['result'] === 'success', 'The first valid check-in did not succeed');
    $stored = $pdo->query(
        "SELECT `entered_date`, `entered_time` FROM `{$tables['user_periods']}` WHERE `user_id` = {$userId}"
    )->fetch(PDO::FETCH_ASSOC);
    egmCheckInAssert(($stored['entered_date'] ?? '') === '2026-08-18', 'The entry date was not stored separately');
    egmCheckInAssert(($stored['entered_time'] ?? '') === '10:15:30', 'The entry time was not stored separately');

    $duplicate = egmCheckInProcess($context, '1234567890', new DateTimeImmutable('2026-08-18 10:20:00', $timezone));
    egmCheckInAssert($duplicate['result'] === 'duplicate', 'A second check-in was not reported as duplicate');
    $storedAfterDuplicate = $pdo->query(
        "SELECT `entered_date`, `entered_time` FROM `{$tables['user_periods']}` WHERE `user_id` = {$userId}"
    )->fetch(PDO::FETCH_ASSOC);
    egmCheckInAssert($storedAfterDuplicate === $stored, 'A duplicate check-in overwrote the original entry time');
    $statuses = $logsPdo->query(
        "SELECT `status` FROM `{$tables['activity_logs']}` WHERE `action` = '" . EGM_CHECK_IN_ACTION . "' ORDER BY `id`"
    )->fetchAll(PDO::FETCH_COLUMN);
    egmCheckInAssert($statuses === ['success', 'duplicate'], 'Success and duplicate attempts were not both audited');

    $pdo->exec(
        "INSERT INTO `{$tables['users']}` (`work_id`, `first_name`, `last_name`, `national_id`, `phone_number`, "
        . "`deputy`, `general_department`, `department`, `gender`, `postal_level`, `source_row`) "
        . "VALUES ('45678901', 'Work', 'Identifier', NULL, '', '', '', '', '', '', 2)"
    );
    $workIdUserId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO `{$tables['user_periods']}` (`user_id`, `period_code`, `invited_at`) "
        . "VALUES (:user_id, '01', '2026-08-18 09:00:00')"
    )->execute([':user_id' => $workIdUserId]);
    $workIdEntry = egmCheckInProcess($context, '45678901', new DateTimeImmutable('2026-08-18 10:21:00', $timezone));
    egmCheckInAssert(($workIdEntry['result'] ?? '') === 'success', 'A valid Work ID did not use the existing check-in flow');
    $workIdLogs = egmCheckInRecentLogs($context, 200, '45678901');
    egmCheckInAssert(count($workIdLogs) === 1, 'The Work ID check-in was not searchable in Guest Control logs');
    egmCheckInAssert(($workIdLogs[0]['work_id'] ?? '') === '45678901', 'The submitted Work ID was not retained in the check-in log');
    $missingWorkId = egmCheckInProcess($context, '87654321', new DateTimeImmutable('2026-08-18 10:22:00', $timezone));
    egmCheckInAssert(($missingWorkId['result'] ?? '') === 'not_found', 'An unknown Work ID did not use the existing not-found flow');

    $pdo->exec("UPDATE `{$tables['users']}` SET `gender` = 'مرد' WHERE `id` = {$userId}");
    $pdo->exec("UPDATE `{$tables['users']}` SET `gender` = 'زن' WHERE `id` = {$workIdUserId}");
    $pdo->exec(
        "UPDATE `{$tables['user_periods']}` SET `quit_date` = '2026-08-18', `quit_time` = '10:30:00', "
        . "`attendance_state` = 'quit_completed' WHERE `user_id` = {$userId} AND `period_code` = '01'"
    );
    $pdo->exec(
        "INSERT INTO `{$tables['users']}` (`work_id`, `first_name`, `last_name`, `national_id`, `phone_number`, "
        . "`deputy`, `general_department`, `department`, `gender`, `postal_level`, `source_row`) "
        . "VALUES ('W-STATS', 'Waiting', 'Guest', '4234567890', '', '', '', '', 'زن', '', 3)"
    );
    $waitingUserId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO `{$tables['user_periods']}` (`user_id`, `period_code`, `invited_at`) "
        . "VALUES (:user_id, '01', '2026-08-18 09:00:00')"
    )->execute([':user_id' => $waitingUserId]);
    $dashboardStats = egmCheckInDashboardStats($context);
    egmCheckInAssert(($dashboardStats['active'] ?? false) === true, 'Active-period dashboard statistics were unavailable');
    egmCheckInAssert(($dashboardStats['total'] ?? 0) === 3, 'Dashboard total invitation count is incorrect');
    egmCheckInAssert(($dashboardStats['invited_total'] ?? 0) === 3, 'Dashboard invited-guest count is incorrect');
    egmCheckInAssert(($dashboardStats['walk_in_total'] ?? -1) === 0, 'Dashboard walk-in count is incorrect before registration');
    egmCheckInAssert(($dashboardStats['entered'] ?? 0) === 2, 'Dashboard entered count is incorrect');
    egmCheckInAssert(($dashboardStats['waiting'] ?? 0) === 1, 'Dashboard waiting count is incorrect');
    egmCheckInAssert(($dashboardStats['inside'] ?? 0) === 1, 'Dashboard currently-inside count is incorrect');
    egmCheckInAssert(($dashboardStats['quit'] ?? 0) === 1, 'Dashboard quit count is incorrect');
    egmCheckInAssert(($dashboardStats['entry_percent'] ?? 0) === 66.7, 'Dashboard entry percentage is incorrect');
    egmCheckInAssert(($dashboardStats['gender']['male']['entered'] ?? 0) === 1, 'Male entry statistics are incorrect');
    egmCheckInAssert(
        ($dashboardStats['gender']['female']['total'] ?? 0) === 2
            && ($dashboardStats['gender']['female']['entered'] ?? 0) === 1
            && ($dashboardStats['gender']['female']['waiting'] ?? 0) === 1,
        'Female entry statistics are incorrect'
    );

    $shortCodeRejected = false;
    try {
        egmCheckInProcess($context, '123', new DateTimeImmutable('2026-08-18 10:23:00', $timezone));
    } catch (InvalidArgumentException $error) {
        $shortCodeRejected = true;
    }
    egmCheckInAssert($shortCodeRejected, 'A 1-3 digit Guest ID reached the lookup flow');

    $pdo->exec(
        "INSERT INTO `{$tables['users']}` (`work_id`, `first_name`, `last_name`, `national_id`, `phone_number`, "
        . "`deputy`, `general_department`, `department`, `gender`, `postal_level`, `source_row`) "
        . "VALUES ('W-2', 'Other', 'Period', '2234567890', '', '', '', '', '', '', 2)"
    );
    $otherPeriodUserId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO `{$tables['user_periods']}` (`user_id`, `period_code`, `invited_at`) "
        . "VALUES (:user_id, '02', '2026-08-18 09:00:00')"
    )->execute([':user_id' => $otherPeriodUserId]);
    $otherPeriodContext = $context;
    $otherPeriodContext['periods'][] = ['tagCode' => '02', 'title' => 'Other Period', 'duration' => false];
    $otherPeriodResult = egmCheckInProcess($otherPeriodContext, '2234567890', $firstTime);
    egmCheckInAssert(
        ($otherPeriodResult['result'] ?? '') === 'invited_other_period',
        'A guest invited only to another period was not identified correctly'
    );

    $statsVersionBeforeWalkIn = egmCheckInStatsVersion($context);
    $oeuWalkInBefore = (int)$pdo->query(
        "SELECT COUNT(*) FROM `organizational_event_users` WHERE `national_id` = '3234567890'"
    )->fetchColumn();
    $walkIn = egmCheckInRegisterUninvited($context, [
        'first_name' => 'Walk',
        'last_name' => 'In',
        'national_id' => '3234567890',
        'work_id' => '',
        'phone_number' => '09121111111',
        'outside_organization' => true,
    ], ['username' => 'test-admin'], new DateTimeImmutable('2026-08-18 10:25:00', $timezone));
    egmCheckInAssert(($walkIn['result'] ?? '') === 'walk_in_registered', 'Walk-in guest registration failed');
    egmCheckInAssert(($walkIn['guest_number'] ?? '') !== '', 'Walk-in guest did not receive a guest number');
    $walkInUser = $pdo->query(
        "SELECT `id`, `source_type`, `is_uninvited_guest`, `outside_organization`, `uninvited_registered_by` "
        . "FROM `{$tables['users']}` WHERE `national_id` = '3234567890'"
    )->fetch(PDO::FETCH_ASSOC);
    egmCheckInAssert(is_array($walkInUser) && ($walkInUser['source_type'] ?? '') === 'walk_in', 'Walk-in EGM user row was not created');
    egmCheckInAssert((int)($walkInUser['is_uninvited_guest'] ?? 0) === 1, 'Walk-in user flag was not stored');
    egmCheckInAssert((int)($walkInUser['outside_organization'] ?? 0) === 1, 'Outside-organization flag was not stored');
    egmCheckInAssert(($walkInUser['uninvited_registered_by'] ?? '') === 'test-admin', 'Walk-in registrar was not stored');
    $walkInInvitation = $pdo->query(
        "SELECT `invitation_source`, `is_uninvited_guest`, `attendance_state`, `last_control_condition` "
        . "FROM `{$tables['user_periods']}` WHERE `user_id` = " . (int)$walkInUser['id'] . " AND `period_code` = '01'"
    )->fetch(PDO::FETCH_ASSOC);
    egmCheckInAssert(($walkInInvitation['invitation_source'] ?? '') === 'walk_in', 'Walk-in period invitation source is incorrect');
    egmCheckInAssert((int)($walkInInvitation['is_uninvited_guest'] ?? 0) === 1, 'Walk-in period flag was not stored');
    egmCheckInAssert(($walkInInvitation['attendance_state'] ?? '') === 'not_entered', 'Walk-in registration incorrectly marked entry');
    egmCheckInAssert(($walkInInvitation['last_control_condition'] ?? '') === 'walk_in_registered', 'Walk-in condition was not stored');
    $registeredWalkInStats = egmCheckInDashboardStats($context);
    egmCheckInAssert(($registeredWalkInStats['total'] ?? 0) === 4, 'Registered walk-in was not added to the dashboard total');
    egmCheckInAssert(($registeredWalkInStats['invited_total'] ?? 0) === 3, 'Registered walk-in changed the ordinary invited count');
    egmCheckInAssert(($registeredWalkInStats['walk_in_total'] ?? 0) === 1, 'Registered walk-in was not shown in dashboard stats');
    egmCheckInAssert(($registeredWalkInStats['walk_in_entered'] ?? -1) === 0, 'Walk-in registration was incorrectly counted as entry');
    egmCheckInAssert(egmCheckInStatsVersion($context) !== $statsVersionBeforeWalkIn, 'Roster stats version did not change after walk-in registration');
    $oeuWalkInAfter = (int)$pdo->query(
        "SELECT COUNT(*) FROM `organizational_event_users` WHERE `national_id` = '3234567890'"
    )->fetchColumn();
    egmCheckInAssert($oeuWalkInAfter === $oeuWalkInBefore, 'Walk-in guest was incorrectly added to OEU');
    $walkInEntry = egmCheckInProcess($context, '3234567890', new DateTimeImmutable('2026-08-18 10:26:00', $timezone));
    egmCheckInAssert(($walkInEntry['result'] ?? '') === 'success', 'Registered walk-in guest could not enter on the next scan');
    $enteredWalkInStats = egmCheckInDashboardStats($context);
    egmCheckInAssert(($enteredWalkInStats['walk_in_total'] ?? 0) === 1, 'Walk-in total changed after entry');
    egmCheckInAssert(($enteredWalkInStats['walk_in_entered'] ?? 0) === 1, 'Entered walk-in was not shown in dashboard stats');

    $pdo->prepare(
        "INSERT INTO `{$tables['user_periods']}` (`user_id`, `period_code`, `invited_at`) "
        . "VALUES (:user_id, '02', '2026-08-18 09:00:00')"
    )->execute([':user_id' => $userId]);
    $quitPeriod = [
        'tagCode' => '02', 'title' => 'Quit Period', 'duration' => true, 'quitRequired' => true,
        'startDate' => '2026-08-18', 'startTime' => '09:00',
        'enterDeadlineDate' => '2026-08-18', 'enterDeadlineTime' => '10:00',
        'quitOpeningDate' => '2026-08-18', 'quitOpeningTime' => '16:00',
        'endDate' => '2026-08-18', 'endTime' => '17:00',
    ];
    $quitContext = array_replace($context, ['period' => $quitPeriod, 'period_code' => '02']);
    $quitContext['periods'] = [$quitPeriod];
    $quitWithoutEntry = egmCheckInProcess($quitContext, '1234567890', new DateTimeImmutable('2026-08-18 16:20:00', $timezone));
    egmCheckInAssert($quitWithoutEntry['result'] === 'quit_without_entry', 'Quit was accepted without a recorded entry');
    $emptyQuit = $pdo->query(
        "SELECT `quit_date`, `quit_time`, `attendance_state`, `last_control_condition`, `last_control_action`, `last_control_at` "
        . "FROM `{$tables['user_periods']}` WHERE `user_id` = {$userId} AND `period_code` = '02'"
    )->fetch(PDO::FETCH_ASSOC);
    egmCheckInAssert(($emptyQuit['quit_date'] ?? null) === null, 'Rejected quit wrote a quit date');
    egmCheckInAssert(($emptyQuit['quit_time'] ?? null) === null, 'Rejected quit wrote a quit time');
    egmCheckInAssert(($emptyQuit['attendance_state'] ?? '') === 'not_entered', 'Rejected quit changed the actual attendance state');
    egmCheckInAssert(($emptyQuit['last_control_condition'] ?? '') === 'quit_without_entry', 'Rejected quit condition was not saved on the invitation');
    egmCheckInAssert(($emptyQuit['last_control_action'] ?? '') === 'quit', 'Rejected quit action was not saved on the invitation');
    egmCheckInAssert(($emptyQuit['last_control_at'] ?? '') === '2026-08-18 16:20:00', 'Rejected quit time was not saved on the invitation');

    $periodEntry = egmCheckInProcess($quitContext, '1234567890', new DateTimeImmutable('2026-08-18 09:30:00', $timezone));
    egmCheckInAssert($periodEntry['result'] === 'success', 'Period entry required for quit was not recorded');
    $enteredState = $pdo->query(
        "SELECT `attendance_state`, `last_control_condition` FROM `{$tables['user_periods']}` "
        . "WHERE `user_id` = {$userId} AND `period_code` = '02'"
    )->fetch(PDO::FETCH_ASSOC);
    egmCheckInAssert(($enteredState['attendance_state'] ?? '') === 'entered', 'Successful entry did not update attendance state');
    egmCheckInAssert(($enteredState['last_control_condition'] ?? '') === 'success', 'Successful entry condition was not retained');
    $quit = egmCheckInProcess($quitContext, '1234567890', new DateTimeImmutable('2026-08-18 16:30:00', $timezone));
    egmCheckInAssert($quit['result'] === 'quit_success', 'A valid quit scan did not succeed');
    $storedQuit = $pdo->query(
        "SELECT `quit_date`, `quit_time`, `attendance_state`, `last_control_condition`, `correct_presence`, `fake_presence` "
        . "FROM `{$tables['user_periods']}` WHERE `user_id` = {$userId} AND `period_code` = '02'"
    )->fetch(PDO::FETCH_ASSOC);
    egmCheckInAssert(($storedQuit['quit_date'] ?? '') === '2026-08-18', 'The quit date was not stored separately');
    egmCheckInAssert(($storedQuit['quit_time'] ?? '') === '16:30:00', 'The quit time was not stored separately');
    egmCheckInAssert(($storedQuit['attendance_state'] ?? '') === 'quit_completed', 'Successful quit did not update attendance state');
    egmCheckInAssert(($storedQuit['last_control_condition'] ?? '') === 'quit_success', 'Successful quit condition was not retained');
    egmCheckInAssert((int)($storedQuit['correct_presence'] ?? 0) === 1, 'Ordinary entry and quit did not flag Correct Presence');
    egmCheckInAssert((int)($storedQuit['fake_presence'] ?? 0) === 0, 'Ordinary entry and quit incorrectly flagged Fake Presence');
    $periodLogs = egmCheckInRecentLogs($quitContext);
    egmCheckInAssert(count($periodLogs) === 3, 'Period-specific Guest Control logs were not filtered correctly');
    egmCheckInAssert(($periodLogs[0]['period_code'] ?? '') === '02', 'Guest Control returned a log from another period');
    egmCheckInAssert(($periodLogs[0]['quit_time'] ?? '') === '16:30:00', 'Guest Control did not return the stored quit time');
    egmCheckInAssert(($periodLogs[0]['attendance_action'] ?? '') === 'quit', 'The log operation type was not identified as quit');
    egmCheckInAssert(($periodLogs[0]['operation_time'] ?? '') === '16:30:00', 'The unified operation time is incorrect');
    egmCheckInAssert(($periodLogs[0]['work_id'] ?? '') === 'W-1', 'Guest details were not included with the log');
    egmCheckInAssert(($periodLogs[1]['status'] ?? '') === 'quit_without_entry', 'Rejected quit was not audited');
    $nationalIdSearch = egmCheckInRecentLogs($quitContext, 200, '1234567890');
    egmCheckInAssert(count($nationalIdSearch) === 3, 'Guest Control search did not match the national ID');
    $persianStatusSearch = egmCheckInRecentLogs($quitContext, 200, 'ورود ثبت نشده');
    egmCheckInAssert(count($persianStatusSearch) === 1, 'Guest Control search did not match the Persian condition label');
    egmCheckInAssert(($persianStatusSearch[0]['status'] ?? '') === 'quit_without_entry', 'Persian condition search returned the wrong log');
    $workIdSearch = egmCheckInRecentLogs($quitContext, 200, 'W-1');
    egmCheckInAssert(count($workIdSearch) === 3, 'Guest Control search did not match the work ID');
    $invitationCount = (int)$pdo->query("SELECT COUNT(*) FROM `{$tables['user_periods']}`")->fetchColumn();
    $periodReset = egmCheckInResetAttendanceRecords($quitContext, '02');
    egmCheckInAssert(($periodReset['scope'] ?? '') === 'period', 'The period reset reported the wrong scope');
    $resetPeriodState = $pdo->query(
        "SELECT `entered_date`,`entered_time`,`quit_date`,`quit_time`,`correct_presence`,`fake_presence`,`attendance_state`,`last_control_condition`,"
        . "`last_control_action`,`last_control_message`,`last_control_at` FROM `{$tables['user_periods']}` "
        . "WHERE `user_id` = {$userId} AND `period_code` = '02'"
    )->fetch(PDO::FETCH_ASSOC);
    foreach (['entered_date', 'entered_time', 'quit_date', 'quit_time', 'last_control_condition', 'last_control_action', 'last_control_message', 'last_control_at'] as $resetColumn) {
        egmCheckInAssert(($resetPeriodState[$resetColumn] ?? null) === null, "Period reset did not clear {$resetColumn}");
    }
    egmCheckInAssert(($resetPeriodState['attendance_state'] ?? '') === 'not_entered', 'Period reset did not restore the attendance state');
    egmCheckInAssert((int)($resetPeriodState['correct_presence'] ?? -1) === 0, 'Period reset did not clear Correct Presence');
    egmCheckInAssert((int)($resetPeriodState['fake_presence'] ?? -1) === 0, 'Period reset did not clear Fake Presence');
    egmCheckInAssert(count(egmCheckInRecentLogs($quitContext)) === 0, 'Period reset did not delete that period Guest Control logs');
    $periodOneEntry = (int)$pdo->query(
        "SELECT COUNT(*) FROM `{$tables['user_periods']}` WHERE `period_code`='01' AND `entered_date` IS NOT NULL"
    )->fetchColumn();
    egmCheckInAssert($periodOneEntry > 0, 'Period reset changed another period attendance record');

    $forcedEntry = egmCheckInProcess(
        $quitContext,
        '1234567890',
        new DateTimeImmutable('2026-08-18 08:30:00', $timezone),
        'entry',
        ['code' => 'test-admin']
    );
    egmCheckInAssert(($forcedEntry['result'] ?? '') === 'force_entry_success', 'Force Enter did not succeed outside entry time');
    $forcedQuit = egmCheckInProcess(
        $quitContext,
        '1234567890',
        new DateTimeImmutable('2026-08-18 08:31:00', $timezone),
        'quit',
        ['code' => 'test-admin']
    );
    egmCheckInAssert(($forcedQuit['result'] ?? '') === 'force_quit_success', 'Force Quit did not succeed before quit opening');
    $forcedState = $pdo->query(
        "SELECT `correct_presence`,`fake_presence`,`attendance_state` FROM `{$tables['user_periods']}` "
        . "WHERE `user_id` = {$userId} AND `period_code` = '02'"
    )->fetch(PDO::FETCH_ASSOC);
    egmCheckInAssert((int)($forcedState['correct_presence'] ?? 1) === 0, 'Forced attendance incorrectly flagged Correct Presence');
    egmCheckInAssert((int)($forcedState['fake_presence'] ?? 0) === 1, 'Forced attendance did not flag Fake Presence');

    $fullReset = egmCheckInResetAttendanceRecords($context);
    egmCheckInAssert(($fullReset['scope'] ?? '') === 'all', 'The EGM reset reported the wrong scope');
    $remainingAttendance = (int)$pdo->query(
        "SELECT COUNT(*) FROM `{$tables['user_periods']}` WHERE `entered_date` IS NOT NULL OR `entered_time` IS NOT NULL "
        . "OR `quit_date` IS NOT NULL OR `quit_time` IS NOT NULL OR `attendance_state` <> 'not_entered' "
        . "OR `last_control_condition` IS NOT NULL OR `last_control_action` IS NOT NULL "
        . "OR `last_control_message` IS NOT NULL OR `last_control_at` IS NOT NULL "
        . "OR `correct_presence` <> 0 OR `fake_presence` <> 0"
    )->fetchColumn();
    egmCheckInAssert($remainingAttendance === 0, 'Full EGM reset left attendance state behind');
    $remainingLogs = (int)$logsPdo->query(
        "SELECT COUNT(*) FROM `{$tables['activity_logs']}` WHERE `action`='" . EGM_CHECK_IN_ACTION . "'"
    )->fetchColumn();
    egmCheckInAssert($remainingLogs === 0, 'Full EGM reset left Guest Control logs behind');
    egmCheckInAssert(
        (int)$pdo->query("SELECT COUNT(*) FROM `{$tables['user_periods']}`")->fetchColumn() === $invitationCount,
        'Attendance reset deleted period invitations'
    );
    egmCheckInAssert(
        (int)$pdo->query("SELECT `is_uninvited_guest` FROM `{$tables['users']}` WHERE `id`=" . (int)$walkInUser['id'])->fetchColumn() === 1,
        'Attendance reset removed the walk-in guest identity'
    );
} finally {
    dropEgmInstanceTables($pdo, $code);
}

fwrite(STDOUT, "EGM check-in test passed.\n");
