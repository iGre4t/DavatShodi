<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/common.php';
require_once dirname(__DIR__) . '/api/lib/egm-period-exports.php';

function egmPeriodExportsAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = connectDatabase(loadConfig(dirname(__DIR__) . '/api/config.php'));
egmPeriodExportsAssert($pdo instanceof PDO, 'Could not connect to the period export test database');
$logsPdo = connectActivityLogDatabase(loadConfig(dirname(__DIR__) . '/api/config.php'));
egmPeriodExportsAssert($logsPdo instanceof PDO, 'Could not connect to the period export logs database');
do {
    $code = '98' . (string)random_int(10000000, 99999999);
    $names = egmInstanceTableNames($code);
} while (egmInstanceTableExists($pdo, $names['data']));

try {
    $tables = ensureEgmInstanceTables($pdo, $code);
    ensureActivityLogTable($logsPdo, 'EGM', $code);
    egmInstanceWritePeriods($pdo, $code, [[
        'id' => 'period-export-test',
        'tagCode' => '01',
        'title' => 'روز آزمون خروجی',
        'startDate' => '2026-08-22',
    ]]);

    $insertUser = $pdo->prepare(
        "INSERT INTO `{$tables['users']}` (`work_id`,`first_name`,`last_name`,`national_id`,`phone_number`,"
        . "`deputy`,`general_department`,`department`,`gender`,`postal_level`,`source_row`,`guest_number`,"
        . "`source_type`,`is_active`,`is_uninvited_guest`,`outside_organization`) VALUES "
        . "(:work_id,:first_name,:last_name,:national_id,:phone_number,'معاونت','اداره کل','اداره','مرد','کارشناس',"
        . ":source_row,:guest_number,:source_type,1,:is_uninvited,:outside_organization)"
    );
    $insertUser->execute([
        ':work_id' => 'W-001', ':first_name' => 'مهمان', ':last_name' => 'دعوت‌شده',
        ':national_id' => '0381647730', ':phone_number' => '09120000001', ':source_row' => 1,
        ':guest_number' => '0001', ':source_type' => 'oeu', ':is_uninvited' => 0, ':outside_organization' => 0,
    ]);
    $invitedId = (int)$pdo->lastInsertId();
    $insertUser->execute([
        ':work_id' => 'W-002', ':first_name' => 'مهمان', ':last_name' => 'ناخوانده',
        ':national_id' => '0012345678', ':phone_number' => '09120000002', ':source_row' => 2,
        ':guest_number' => '0002', ':source_type' => 'walk_in', ':is_uninvited' => 1, ':outside_organization' => 1,
    ]);
    $walkInId = (int)$pdo->lastInsertId();

    $insertPeriod = $pdo->prepare(
        "INSERT INTO `{$tables['user_periods']}` (`user_id`,`period_code`,`invitation_source`,`invited_at`,"
        . "`entered_date`,`entered_time`,`quit_date`,`quit_time`,`correct_presence`,`fake_presence`,`attendance_state`,`last_control_condition`,"
        . "`last_control_action`,`last_control_message`,`last_control_at`,`is_uninvited_guest`,`uninvited_registered_at`,"
        . "`uninvited_registered_by`) VALUES (:user_id,'01',:source,'2026-08-18 08:00:00',:entered_date,:entered_time,"
        . ":quit_date,:quit_time,:correct_presence,:fake_presence,:attendance_state,:condition,:control_action,:message,:control_at,:is_uninvited,"
        . ":registered_at,:registered_by)"
    );
    $insertPeriod->execute([
        ':user_id' => $invitedId, ':source' => 'oeu', ':entered_date' => '2026-08-18', ':entered_time' => '09:05:00',
        ':quit_date' => '2026-08-18', ':quit_time' => '16:10:00', ':attendance_state' => 'quit_completed',
        ':correct_presence' => 1, ':fake_presence' => 0,
        ':condition' => 'quit_success', ':control_action' => 'quit', ':message' => 'خروج ثبت شد.',
        ':control_at' => '2026-08-18 16:10:00', ':is_uninvited' => 0, ':registered_at' => null, ':registered_by' => null,
    ]);
    $insertPeriod->execute([
        ':user_id' => $walkInId, ':source' => 'walk_in', ':entered_date' => '2026-08-18', ':entered_time' => '10:15:00',
        ':quit_date' => null, ':quit_time' => null, ':attendance_state' => 'entered',
        ':correct_presence' => 0, ':fake_presence' => 1,
        ':condition' => 'success', ':control_action' => 'entry', ':message' => 'ورود ثبت شد.',
        ':control_at' => '2026-08-18 10:15:00', ':is_uninvited' => 1,
        ':registered_at' => '2026-08-18 10:10:00', ':registered_by' => 'admin',
    ]);

    $insertLog = $logsPdo->prepare(
        "INSERT INTO `{$tables['activity_logs']}` (`source_key`,`user_id`,`work_id`,`level`,`action`,`entity_type`,"
        . "`entity_id`,`status`,`message`,`metadata_json`,`occurred_at`) VALUES (:source_key,:user_id,:work_id,'info',"
        . ":action,'period','01',:status,:message,:metadata_json,:occurred_at)"
    );
    $insertLog->execute([
        ':source_key' => hash('sha256', 'period-export-success'), ':user_id' => $invitedId, ':work_id' => 'W-001',
        ':action' => EGM_PERIOD_EXPORT_LOG_ACTION, ':status' => 'success', ':message' => 'ورود موفق',
        ':metadata_json' => json_encode(['national_id' => '0381647730', 'attendance_action' => 'entry'], JSON_UNESCAPED_UNICODE),
        ':occurred_at' => '2026-08-18 09:05:00',
    ]);
    $insertLog->execute([
        ':source_key' => hash('sha256', 'period-export-not-found'), ':user_id' => null, ':work_id' => null,
        ':action' => EGM_PERIOD_EXPORT_LOG_ACTION, ':status' => 'not_found', ':message' => 'کاربر پیدا نشد',
        ':metadata_json' => json_encode(['national_id' => '0099999999', 'attendance_action' => 'check'], JSON_UNESCAPED_UNICODE),
        ':occurred_at' => '2026-08-18 09:10:00',
    ]);

    $context = ['pdo' => $pdo, 'logs_pdo' => $logsPdo, 'code' => $code, 'tables' => $tables, 'mission_dir' => dirname(__DIR__)];
    foreach (['all_guests' => 2, 'entered_no_quit' => 1, 'uninvited_guests' => 1, 'full_log' => 2, 'user_conditions' => 2, 'correct_presence' => 1, 'fake_presence' => 1] as $type => $expectedRows) {
        $export = egmPeriodExportBuild($context, '01', $type);
        egmPeriodExportsAssert($export['row_count'] === $expectedRows, "Unexpected row count for {$type}");
        egmPeriodExportsAssert(str_ends_with($export['filename'], '.xlsx'), "Invalid filename for {$type}");
        egmPeriodExportsAssert(str_starts_with($export['content'], "PK\x03\x04"), "Invalid XLSX package for {$type}");
        foreach (['نام و نام خانوادگی', 'کد ملی', 'کد پرسنلی', 'شماره همراه', 'وضعیت دقیق', 'تاریخ ورود', 'زمان ورود', 'تاریخ خروج', 'زمان خروج'] as $header) {
            egmPeriodExportsAssert(str_contains($export['content'], $header), "Missing {$header} in {$type}");
        }
        egmPeriodExportsAssert(str_contains($export['content'], 'Correct Presence'), "Missing Correct Presence in {$type}");
        egmPeriodExportsAssert(str_contains($export['content'], 'Fake Presence'), "Missing Fake Presence in {$type}");
        egmPeriodExportsAssert(str_contains($export['filename'], '31 مردادماه'), "Missing Shamsi date in {$type} filename");
    }

    $allGuests = egmPeriodExportBuild($context, '01', 'all_guests');
    egmPeriodExportsAssert(str_contains($allGuests['content'], '0381647730'), 'Leading-zero National ID was not preserved');
    egmPeriodExportsAssert(str_contains($allGuests['content'], '16:10:00'), 'Quit time was not exported');
    $walkIns = egmPeriodExportBuild($context, '01', 'uninvited_guests');
    egmPeriodExportsAssert(str_contains($walkIns['content'], '0012345678'), 'Walk-in guest is missing');
    egmPeriodExportsAssert(!str_contains($walkIns['content'], '0381647730'), 'Invited guest leaked into walk-in export');
    $enteredNoQuit = egmPeriodExportBuild($context, '01', 'entered_no_quit');
    egmPeriodExportsAssert(str_contains($enteredNoQuit['content'], '0012345678'), 'Entered guest without quit is missing');
    egmPeriodExportsAssert(!str_contains($enteredNoQuit['content'], '0381647730'), 'Guest with a completed quit leaked into entered-without-quit export');
    egmPeriodExportsAssert(str_contains($enteredNoQuit['content'], 'ورود ثبت شده؛ خروج ثبت نشده'), 'Operational export incorrectly validates final presence');
    $unchangedPresence = $pdo->query(
        "SELECT `correct_presence`,`fake_presence` FROM `{$tables['user_periods']}` WHERE `user_id` = {$walkInId} AND `period_code` = '01'"
    )->fetch(PDO::FETCH_ASSOC);
    egmPeriodExportsAssert(
        (int)($unchangedPresence['correct_presence'] ?? -1) === 0 && (int)($unchangedPresence['fake_presence'] ?? -1) === 1,
        'Entered-without-quit export mutated attendance classification'
    );
    $fullLog = egmPeriodExportBuild($context, '01', 'full_log');
    egmPeriodExportsAssert(str_contains($fullLog['content'], '0099999999'), 'Unmatched National ID is missing from the full log');
    egmPeriodExportsAssert(str_contains($fullLog['content'], 'کاربر پیدا نشد'), 'Rejected condition is missing from the full log');
} finally {
    dropEgmInstanceTables($pdo, $code);
}

fwrite(STDOUT, "EGM period exports test passed.\n");
