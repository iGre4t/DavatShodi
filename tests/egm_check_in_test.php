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
    $oeuWalkInAfter = (int)$pdo->query(
        "SELECT COUNT(*) FROM `organizational_event_users` WHERE `national_id` = '3234567890'"
    )->fetchColumn();
    egmCheckInAssert($oeuWalkInAfter === $oeuWalkInBefore, 'Walk-in guest was incorrectly added to OEU');
    $walkInEntry = egmCheckInProcess($context, '3234567890', new DateTimeImmutable('2026-08-18 10:26:00', $timezone));
    egmCheckInAssert(($walkInEntry['result'] ?? '') === 'success', 'Registered walk-in guest could not enter on the next scan');

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
        "SELECT `quit_date`, `quit_time`, `attendance_state`, `last_control_condition` "
        . "FROM `{$tables['user_periods']}` WHERE `user_id` = {$userId} AND `period_code` = '02'"
    )->fetch(PDO::FETCH_ASSOC);
    egmCheckInAssert(($storedQuit['quit_date'] ?? '') === '2026-08-18', 'The quit date was not stored separately');
    egmCheckInAssert(($storedQuit['quit_time'] ?? '') === '16:30:00', 'The quit time was not stored separately');
    egmCheckInAssert(($storedQuit['attendance_state'] ?? '') === 'quit_completed', 'Successful quit did not update attendance state');
    egmCheckInAssert(($storedQuit['last_control_condition'] ?? '') === 'quit_success', 'Successful quit condition was not retained');
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
} finally {
    dropEgmInstanceTables($pdo, $code);
}

fwrite(STDOUT, "EGM check-in test passed.\n");
