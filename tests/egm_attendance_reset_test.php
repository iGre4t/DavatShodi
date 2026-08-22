<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/egm-check-in.php';

function egmAttendanceResetAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = new PDO('sqlite::memory:');
$logsPdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$logsPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(<<<SQL
CREATE TABLE user_periods (
  id INTEGER PRIMARY KEY,
  period_code TEXT NOT NULL,
  entered_date TEXT NULL,
  entered_time TEXT NULL,
  quit_date TEXT NULL,
  quit_time TEXT NULL,
  attendance_state TEXT NOT NULL,
  last_control_condition TEXT NULL,
  last_control_action TEXT NULL,
  last_control_message TEXT NULL,
  last_control_at TEXT NULL,
  invitation_source TEXT NOT NULL
)
SQL);
$logsPdo->exec(<<<SQL
CREATE TABLE activity_logs (
  id INTEGER PRIMARY KEY,
  action TEXT NOT NULL,
  entity_id TEXT NULL
)
SQL);
$pdo->exec(
    "INSERT INTO user_periods VALUES "
    . "(1,'P1','2026-08-18','09:00:00','2026-08-18','17:00:00','quit_completed','quit_success','quit','done','2026-08-18 17:00:00','oeu'),"
    . "(2,'P2','2026-08-19','09:10:00',NULL,NULL,'entered','success','entry','done','2026-08-19 09:10:00','walk_in')"
);
$logsPdo->exec(
    "INSERT INTO activity_logs VALUES "
    . "(1,'" . EGM_CHECK_IN_ACTION . "','P1'),"
    . "(2,'" . EGM_CHECK_IN_ACTION . "','P2'),"
    . "(3,'unrelated_action','P1')"
);
$context = [
    'pdo' => $pdo,
    'logs_pdo' => $logsPdo,
    'tables' => ['user_periods' => 'user_periods', 'activity_logs' => 'activity_logs'],
    'periods' => [['tagCode' => 'P1'], ['tagCode' => 'P2']],
];

$invalidRejected = false;
try {
    egmCheckInResetAttendanceRecords($context, 'missing');
} catch (InvalidArgumentException $error) {
    $invalidRejected = true;
}
egmAttendanceResetAssert($invalidRejected, 'An unknown period was accepted for reset.');

$periodResult = egmCheckInResetAttendanceRecords($context, 'P1');
egmAttendanceResetAssert(($periodResult['scope'] ?? '') === 'period', 'Period reset returned the wrong scope.');
$periodOne = $pdo->query("SELECT * FROM user_periods WHERE period_code='P1'")->fetch(PDO::FETCH_ASSOC);
egmAttendanceResetAssert(($periodOne['attendance_state'] ?? '') === 'not_entered', 'Period reset did not reset attendance state.');
foreach (['entered_date', 'entered_time', 'quit_date', 'quit_time', 'last_control_condition', 'last_control_action', 'last_control_message', 'last_control_at'] as $column) {
    egmAttendanceResetAssert(($periodOne[$column] ?? null) === null, "Period reset did not clear {$column}.");
}
$periodTwo = $pdo->query("SELECT * FROM user_periods WHERE period_code='P2'")->fetch(PDO::FETCH_ASSOC);
egmAttendanceResetAssert(($periodTwo['entered_date'] ?? '') === '2026-08-19', 'Period reset changed another period.');
egmAttendanceResetAssert(
    (int)$logsPdo->query("SELECT COUNT(*) FROM activity_logs WHERE action='" . EGM_CHECK_IN_ACTION . "' AND entity_id='P1'")->fetchColumn() === 0,
    'Period reset left its Guest Control log behind.'
);
egmAttendanceResetAssert(
    (int)$logsPdo->query("SELECT COUNT(*) FROM activity_logs WHERE action='unrelated_action'")->fetchColumn() === 1,
    'Period reset deleted an unrelated audit action.'
);

$allResult = egmCheckInResetAttendanceRecords($context);
egmAttendanceResetAssert(($allResult['scope'] ?? '') === 'all', 'Full reset returned the wrong scope.');
egmAttendanceResetAssert(
    (int)$pdo->query("SELECT COUNT(*) FROM user_periods WHERE attendance_state<>'not_entered' OR entered_date IS NOT NULL OR quit_date IS NOT NULL OR last_control_at IS NOT NULL")->fetchColumn() === 0,
    'Full reset left attendance records behind.'
);
egmAttendanceResetAssert((int)$pdo->query('SELECT COUNT(*) FROM user_periods')->fetchColumn() === 2, 'Reset deleted invitations.');
egmAttendanceResetAssert(
    (int)$logsPdo->query("SELECT COUNT(*) FROM activity_logs WHERE action='" . EGM_CHECK_IN_ACTION . "'")->fetchColumn() === 0,
    'Full reset left Guest Control logs behind.'
);
egmAttendanceResetAssert(
    (int)$logsPdo->query("SELECT COUNT(*) FROM activity_logs WHERE action='unrelated_action'")->fetchColumn() === 1,
    'Full reset deleted unrelated logs.'
);

fwrite(STDOUT, "EGM attendance reset test passed.\n");
