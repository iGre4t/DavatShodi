<?php
declare(strict_types=1);

function egmResetSafetyAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$path = $root . '/api/lib/egm-check-in.php';
$source = file_get_contents($path);
egmResetSafetyAssert(is_string($source), 'Could not read the EGM reset backend.');

$functionStart = strpos($source, 'function egmCheckInResetAttendanceRecords');
$functionEnd = strpos($source, 'function egmCheckInCleanText', $functionStart ?: 0);
egmResetSafetyAssert($functionStart !== false && $functionEnd !== false, 'Could not isolate the attendance reset function.');
$functionSource = substr($source, $functionStart, $functionEnd - $functionStart);

$updateStart = strpos($functionSource, '$update = $pdo->prepare(');
$updateEnd = strpos($functionSource, '$pdo->beginTransaction();', $updateStart ?: 0);
egmResetSafetyAssert($updateStart !== false && $updateEnd !== false, 'Could not isolate the attendance reset UPDATE.');
$updateSource = substr($functionSource, $updateStart, $updateEnd - $updateStart);

foreach ([
    'entered_date', 'entered_time', 'quit_date', 'quit_time', 'attendance_state',
    'correct_presence', 'fake_presence',
    'last_control_condition', 'last_control_action', 'last_control_message', 'last_control_at',
] as $attendanceColumn) {
    egmResetSafetyAssert(
        str_contains($updateSource, "`{$attendanceColumn}`"),
        "Attendance reset no longer explicitly handles {$attendanceColumn}."
    );
}

foreach ([
    'user_id', 'period_code', 'status', 'score', 'attempt_count', 'started_at', 'completed_at', 'state_json',
    'invitation_source', 'invited_by', 'invited_at', 'is_uninvited_guest', 'uninvited_registered_at',
    'uninvited_registered_by', 'invite_card_code', 'invite_card_file', 'invite_card_generated_at',
] as $protectedColumn) {
    egmResetSafetyAssert(
        preg_match('/`' . preg_quote($protectedColumn, '/') . '`\s*=/', $updateSource) !== 1,
        "Attendance reset modifies protected column {$protectedColumn}."
    );
}

foreach (['DELETE FROM', 'INSERT INTO', 'TRUNCATE', 'DROP TABLE'] as $destructiveSql) {
    egmResetSafetyAssert(
        stripos($updateSource, $destructiveSql) === false,
        "Attendance-table reset contains forbidden SQL: {$destructiveSql}."
    );
}

egmResetSafetyAssert(
    str_contains($functionSource, 'DELETE FROM `{$logsTable}` WHERE `action` = :action')
        && str_contains($functionSource, "[':action' => EGM_CHECK_IN_ACTION]"),
    'Log cleanup is not restricted to the Guest Control action.'
);
egmResetSafetyAssert(
    str_contains($functionSource, "AND `entity_id` = :period_code"),
    'Per-period log cleanup is not restricted to its period.'
);

egmResetSafetyAssert(!str_contains($source, 'data-reset-all-records'), 'Guest Control exposes the full reset button.');
egmResetSafetyAssert(!str_contains($source, 'reset-records-button'), 'Guest Control contains reset-button styling or markup.');

foreach ([
    $root . '/mini apps/Event Guest Manager/egm-panel-local.js',
    $root . '/mini apps/EGMs/EGM/egm-panel-local.js',
] as $panelPath) {
    $panelSource = file_get_contents($panelPath);
    egmResetSafetyAssert(is_string($panelSource), "Could not read {$panelPath}.");
    egmResetSafetyAssert(
        str_contains($panelSource, 'data-action="reset-period-attendance"')
            && str_contains($panelSource, "action: 'reset_period_records'"),
        "The admin-only per-period attendance reset is missing in {$panelPath}."
    );
    egmResetSafetyAssert(
        !str_contains($panelSource, "action: 'reset_all_records'"),
        "A full-reset control is exposed in the EGM panel frontend {$panelPath}."
    );
}

fwrite(STDOUT, "EGM attendance reset safety test passed.\n");
