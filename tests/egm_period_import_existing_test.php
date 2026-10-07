<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/egm-period-invites.php';
function checkImport(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$existing = ['first_name'=>'Guest','national_id'=>'0012345678','work_id'=>'AB12','phone_number'=>'09120000000'];
checkImport(egmPeriodInvitesExcelProfileChanges($existing, ['national_id'=>'12345678','work_id'=>'ab12','mapped_fields'=>['national_id','work_id']]) === [], 'Equivalent IDs or unmapped columns caused an update.');
checkImport(egmPeriodInvitesExcelProfileChanges($existing, ['phone_number'=>'','mapped_fields'=>['phone_number']]) === ['phone_number'=>''], 'An explicitly mapped blank must be detected.');
$pdo = connectDatabase(loadConfig(dirname(__DIR__) . '/api/config.php'));
checkImport($pdo instanceof PDO, 'Test database unavailable.');
do { $code = '98' . random_int(10000000,99999999); $tables = egmInstanceTableNames($code); } while (egmInstanceTableExists($pdo, $tables['data']));
try {
    $tables = ensureEgmInstanceTables($pdo, $code);
    $context = ['code'=>$code,'pdo'=>$pdo,'tables'=>$tables];
    $pdo->exec("INSERT INTO `{$tables['users']}` (`work_id`,`first_name`,`national_id`,`source_type`,`is_active`) VALUES ('AB12','Guest','0012345678','custom',1)");
    $id = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO `{$tables['user_periods']}` (`user_id`,`period_code`,`status`,`invitation_source`,`invited_at`,`attendance_state`,`entered_date`,`entered_time`) VALUES ({$id},'P1','invited','custom',NOW(),'entered','2026-10-07','08:30:00')");
    $input = ['excel_id'=>'x:2','work_id'=>'AB12','first_name'=>'Guest','mapped_fields'=>['work_id','first_name']];
    $same = egmPeriodInvitesMatchExcel($context, 'P1', [$input]);
    checkImport($same['rows'] === [] && $same['unmatched_rows'] === [] && $same['skipped_invited'] === 1, 'Unchanged invitation was not skipped.');
    $changed = egmPeriodInvitesMatchExcel($context, 'P1', [array_replace($input,['first_name'=>'Changed'])]);
    $review = $changed['unmatched_rows'][0] ?? [];
    checkImport(($review['profile_changes'] ?? []) === ['first_name'=>'Changed'] && $review['can_invite'] === false, 'Changed invitation was not offered for review.');
    checkImport(($review['existing_invitee']['attendance_state'] ?? '') === 'entered' && $review['existing_invitee']['entered_time'] === '08:30:00', 'Review discarded attendance data.');
    checkImport($pdo->query("SELECT first_name FROM `{$tables['users']}` WHERE id={$id}")->fetchColumn() === 'Guest', 'Matching changed the profile without approval.');
    $otherPeriod = egmPeriodInvitesMatchExcel($context, 'P2', [$input]);
    checkImport(count($otherPeriod['rows']) === 1 && !$otherPeriod['rows'][0]['invited'], 'Another period invitation was incorrectly skipped.');
} finally { dropEgmInstanceTables($pdo, $code); }
echo "Existing period imports: unchanged skip, changed review and period isolation passed.\n";
