<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/egm-period-invites.php';

function egmPeriodInviteeEditAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$config = loadConfig(dirname(__DIR__) . '/api/config.php');
$pdo = connectDatabase($config);
$logsPdo = connectActivityLogDatabase($config);
egmPeriodInviteeEditAssert($pdo instanceof PDO, 'Could not connect to the EGM invitee-edit test database.');
egmPeriodInviteeEditAssert($logsPdo instanceof PDO, 'Could not connect to the EGM invitee-edit logs database.');
orgUsersEnsureTable($pdo);

do {
    $code = '99' . (string)random_int(10000000, 99999999);
    $tables = egmInstanceTableNames($code);
} while (egmInstanceTableExists($pdo, $tables['data']));

$oeuIds = [];
try {
    $tables = ensureEgmInstanceTables($pdo, $code);
    ensureActivityLogTable($logsPdo, 'EGM', $code);
    $context = [
        'code' => $code,
        'pdo' => $pdo,
        'logs_pdo' => $logsPdo,
        'tables' => $tables,
    ];

    $insertUser = $pdo->prepare(
        "INSERT INTO `{$tables['users']}` (`work_id`,`first_name`,`last_name`,`national_id`,`phone_number`,`deputy`,`general_department`,`department`,`gender`,`postal_level`,`guest_number`,`source_row`,`source_type`,`is_active`) "
        . "VALUES (:work_id,:first_name,:last_name,:national_id,'09120000000','Old deputy','Old general','Old department','Old gender','Old level',:guest_number,:source_row,'custom',1)"
    );
    $insertUser->execute([
        ':work_id' => '41001', ':first_name' => 'Old', ':last_name' => 'Guest',
        ':national_id' => '8100000001', ':guest_number' => 'TEST-1', ':source_row' => 1,
    ]);
    $userId = (int)$pdo->lastInsertId();
    $insertUser->execute([
        ':work_id' => '41002', ':first_name' => 'Other', ':last_name' => 'Guest',
        ':national_id' => '8100000002', ':guest_number' => 'TEST-2', ':source_row' => 2,
    ]);

    $insertPeriod = $pdo->prepare(
        "INSERT INTO `{$tables['user_periods']}` (`user_id`,`period_code`,`status`,`invitation_source`,`invited_at`) VALUES (:user_id,:period_code,'invited','custom',NOW())"
    );
    $insertPeriod->execute([':user_id' => $userId, ':period_code' => 'P1']);
    $inviteId = (int)$pdo->lastInsertId();
    $insertPeriod->execute([':user_id' => $userId, ':period_code' => 'P2']);

    $result = egmPeriodInvitesUpdateInvitedRow($context, 'P1', (string)$inviteId, [
        'work_id' => '42001', 'first_name' => 'Edited', 'last_name' => 'Person',
        'national_id' => '8200000001', 'phone_number' => '09121111111', 'guest_number' => 'EDIT-1',
        'deputy' => 'New deputy', 'general_department' => 'New general', 'department' => 'New department',
        'gender' => 'Female', 'postal_level' => 'Manager', 'is_active' => true,
        'is_uninvited_guest' => true, 'outside_organization' => true,
        'attendance_state' => 'quit_completed', 'entered_date' => '2026-08-23', 'entered_time' => '09:05:00',
        'quit_date' => '2026-08-23', 'quit_time' => '16:20:00', 'presence_classification' => 'correct_presence',
    ], 'test-admin');
    egmPeriodInviteeEditAssert(($result['updated'] ?? false) === true, 'The invited guest was not updated.');
    egmPeriodInviteeEditAssert(($result['oeu_updated'] ?? true) === false, 'A custom EGM guest incorrectly updated OEU.');

    $editedUser = $pdo->query("SELECT * FROM `{$tables['users']}` WHERE `id`={$userId}")->fetch(PDO::FETCH_ASSOC);
    egmPeriodInviteeEditAssert(($editedUser['first_name'] ?? '') === 'Edited', 'The shared EGM profile was not updated.');
    egmPeriodInviteeEditAssert(($editedUser['national_id'] ?? '') === '8200000001', 'The edited National ID was not stored.');
    egmPeriodInviteeEditAssert((int)($editedUser['is_uninvited_guest'] ?? 0) === 1, 'The walk-in flag was not stored.');
    egmPeriodInviteeEditAssert((int)($editedUser['outside_organization'] ?? 0) === 1, 'The outside-organization flag was not stored.');

    $periodOne = $pdo->query("SELECT * FROM `{$tables['user_periods']}` WHERE `id`={$inviteId}")->fetch(PDO::FETCH_ASSOC);
    egmPeriodInviteeEditAssert(($periodOne['attendance_state'] ?? '') === 'quit_completed', 'The attendance state was not updated.');
    egmPeriodInviteeEditAssert(($periodOne['entered_time'] ?? '') === '09:05:00', 'The entry time was not stored.');
    egmPeriodInviteeEditAssert(($periodOne['quit_time'] ?? '') === '16:20:00', 'The quit time was not stored.');
    egmPeriodInviteeEditAssert((int)($periodOne['correct_presence'] ?? 0) === 1, 'Correct Presence was not stored.');
    egmPeriodInviteeEditAssert((int)($periodOne['fake_presence'] ?? 0) === 0, 'Fake Presence was incorrectly stored.');
    egmPeriodInviteeEditAssert(($periodOne['last_control_condition'] ?? '') === 'manual_edit', 'The manual edit was not marked.');
    $periodTwo = $pdo->query("SELECT * FROM `{$tables['user_periods']}` WHERE `user_id`={$userId} AND `period_code`='P2'")->fetch(PDO::FETCH_ASSOC);
    egmPeriodInviteeEditAssert(($periodTwo['attendance_state'] ?? '') === 'not_entered', 'Editing one period changed another period.');

    $listed = egmPeriodInvitesListInvitedRows($context, 'P1');
    egmPeriodInviteeEditAssert(count($listed) === 1 && ($listed[0]['attendance_state'] ?? '') === 'quit_completed', 'The invited-user list omitted editable attendance data.');
    egmPeriodInviteeEditAssert(array_key_exists('phone_number', $listed[0]), 'The invited-user list omitted editable profile data.');

    $duplicateRejected = false;
    try {
        egmPeriodInvitesUpdateInvitedRow($context, 'P1', (string)$inviteId, [
            'work_id' => '42001', 'first_name' => 'Edited', 'last_name' => 'Person',
            'national_id' => '8100000002', 'phone_number' => '09121111111', 'guest_number' => 'EDIT-1',
            'is_active' => true, 'attendance_state' => 'entered',
            'entered_date' => '2026-08-23', 'entered_time' => '10:00:00', 'presence_classification' => 'none',
        ], 'test-admin');
    } catch (InvalidArgumentException) {
        $duplicateRejected = true;
    }
    egmPeriodInviteeEditAssert($duplicateRejected, 'A duplicate EGM National ID was accepted.');
    egmPeriodInviteeEditAssert(
        (string)$pdo->query("SELECT `national_id` FROM `{$tables['users']}` WHERE `id`={$userId}")->fetchColumn() === '8200000001',
        'A rejected update was not rolled back.'
    );

    egmPeriodInvitesUpdateInvitedRow($context, 'P1', (string)$inviteId, [
        'work_id' => '42001', 'first_name' => 'Edited', 'last_name' => 'Person',
        'national_id' => '8200000001', 'phone_number' => '09121111111', 'guest_number' => 'EDIT-1',
        'is_active' => true, 'is_uninvited_guest' => true, 'outside_organization' => true,
        'attendance_state' => 'not_entered', 'presence_classification' => 'fake_presence',
    ], 'test-admin');
    $cleared = $pdo->query("SELECT * FROM `{$tables['user_periods']}` WHERE `id`={$inviteId}")->fetch(PDO::FETCH_ASSOC);
    foreach (['entered_date', 'entered_time', 'quit_date', 'quit_time'] as $column) {
        egmPeriodInviteeEditAssert($cleared[$column] === null, "{$column} was not cleared for a not-entered guest.");
    }
    egmPeriodInviteeEditAssert((int)$cleared['correct_presence'] === 0 && (int)$cleared['fake_presence'] === 0, 'Presence flags survived a not-entered reset.');

    $oeuNational = '83' . (string)random_int(10000000, 99999999);
    $oeuInsert = $pdo->prepare(
        'INSERT INTO `organizational_event_users` (`work_id`,`first_name`,`last_name`,`national_id`,`phone_number`,`deputy`,`general_department`,`department`,`gender`,`postal_level`,`source_row`) '
        . "VALUES ('51001','OEU','Guest',:national_id,'09123333333','D','G','U','Male','L',999999)"
    );
    $oeuInsert->execute([':national_id' => $oeuNational]);
    $oeuId = (int)$pdo->lastInsertId();
    $oeuIds[] = $oeuId;
    $oeuEgmInsert = $pdo->prepare(
        "INSERT INTO `{$tables['users']}` (`work_id`,`first_name`,`last_name`,`national_id`,`phone_number`,`source_row`,`source_type`,`source_user_id`,`is_active`) "
        . "VALUES ('51001','OEU','Guest',:national_id,'09123333333',999999,'oeu',:source_user_id,1)"
    );
    $oeuEgmInsert->execute([':national_id' => $oeuNational, ':source_user_id' => $oeuId]);
    $oeuEgmUserId = (int)$pdo->lastInsertId();
    $insertPeriod->execute([':user_id' => $oeuEgmUserId, ':period_code' => 'P1']);
    $oeuInviteId = (int)$pdo->lastInsertId();
    $oeuEditedNational = '84' . (string)random_int(10000000, 99999999);
    $oeuResult = egmPeriodInvitesUpdateInvitedRow($context, 'P1', (string)$oeuInviteId, [
        'work_id' => '51002', 'first_name' => 'OEU Edited', 'last_name' => 'Guest',
        'national_id' => $oeuEditedNational, 'phone_number' => '09124444444', 'guest_number' => 'OEU-1',
        'deputy' => 'D2', 'general_department' => 'G2', 'department' => 'U2', 'gender' => 'Female', 'postal_level' => 'L2',
        'is_active' => true, 'attendance_state' => 'not_entered', 'presence_classification' => 'none',
    ], 'test-admin');
    egmPeriodInviteeEditAssert(($oeuResult['oeu_updated'] ?? false) === true, 'An OEU-sourced profile was not synchronized to OEU.');
    $oeuEdited = $pdo->query("SELECT `first_name`,`national_id`,`work_id` FROM `organizational_event_users` WHERE `id`={$oeuId}")->fetch(PDO::FETCH_ASSOC);
    egmPeriodInviteeEditAssert(($oeuEdited['first_name'] ?? '') === 'OEU Edited' && ($oeuEdited['national_id'] ?? '') === $oeuEditedNational, 'The OEU profile did not receive the manual edit.');

    $auditCount = (int)$logsPdo->query(
        "SELECT COUNT(*) FROM `{$tables['activity_logs']}` WHERE `action`='egm.period_invitee_manual_edit'"
    )->fetchColumn();
    egmPeriodInviteeEditAssert($auditCount >= 3, 'Manual invitee edits were not written to the audit database.');
} finally {
    foreach ($oeuIds as $oeuId) {
        $deleteOeu = $pdo->prepare('DELETE FROM `organizational_event_users` WHERE `id`=:id');
        $deleteOeu->execute([':id' => $oeuId]);
    }
    dropEgmInstanceTables($pdo, $code);
    $logsPdo->exec("DROP TABLE IF EXISTS `{$tables['activity_logs']}`");
    $cleanupRegistry = $logsPdo->prepare("DELETE FROM `activity_log_instances` WHERE `instance_kind`='EGM' AND `instance_code`=:code");
    $cleanupRegistry->execute([':code' => $code]);
}

fwrite(STDOUT, "EGM period invitee edit test passed.\n");
