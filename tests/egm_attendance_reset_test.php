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
  user_id INTEGER NOT NULL,
  period_code TEXT NOT NULL,
  status TEXT NOT NULL,
  score INTEGER NOT NULL,
  attempt_count INTEGER NOT NULL,
  started_at TEXT NULL,
  completed_at TEXT NULL,
  state_json TEXT NULL,
  entered_date TEXT NULL,
  entered_time TEXT NULL,
  quit_date TEXT NULL,
  quit_time TEXT NULL,
  correct_presence INTEGER NOT NULL DEFAULT 0,
  fake_presence INTEGER NOT NULL DEFAULT 0,
  attendance_state TEXT NOT NULL,
  last_control_condition TEXT NULL,
  last_control_action TEXT NULL,
  last_control_message TEXT NULL,
  last_control_at TEXT NULL,
  invitation_source TEXT NOT NULL,
  invited_by TEXT NULL,
  invited_at TEXT NULL,
  is_uninvited_guest INTEGER NOT NULL,
  uninvited_registered_at TEXT NULL,
  uninvited_registered_by TEXT NULL,
  invite_card_code TEXT NULL,
  invite_card_file TEXT NULL,
  invite_card_generated_at TEXT NULL
)
SQL);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT NOT NULL, last_name TEXT NOT NULL)');
$pdo->exec('CREATE TABLE egm_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL)');
$logsPdo->exec(<<<SQL
CREATE TABLE activity_logs (
  id INTEGER PRIMARY KEY,
  action TEXT NOT NULL,
  entity_id TEXT NULL
)
SQL);
$pdo->exec("INSERT INTO users VALUES (101,'Ali','One'),(102,'Sara','Two')");
$pdo->exec("INSERT INTO egm_settings VALUES ('periods','[{\"tagCode\":\"P1\"}]'),('invite_card','{\"enabled\":true}')");
$pdo->exec(<<<SQL
INSERT INTO user_periods (
  id,user_id,period_code,status,score,attempt_count,started_at,completed_at,state_json,
  entered_date,entered_time,quit_date,quit_time,correct_presence,fake_presence,attendance_state,
  last_control_condition,last_control_action,last_control_message,last_control_at,
  invitation_source,invited_by,invited_at,is_uninvited_guest,uninvited_registered_at,
  uninvited_registered_by,invite_card_code,invite_card_file,invite_card_generated_at
) VALUES
  (1,101,'P1','completed',75,2,'2026-08-18 08:00:00','2026-08-18 08:30:00','{"answers":[1]}',
   '2026-08-18','09:00:00','2026-08-18','17:00:00',1,0,'quit_completed',
   'quit_success','quit','done','2026-08-18 17:00:00',
   'oeu','admin','2026-08-17 12:00:00',0,NULL,NULL,'CARD-P1','cards/p1.png','2026-08-17 13:00:00'),
  (2,102,'P2','not_started',0,0,NULL,NULL,'{"answers":[]}',
   '2026-08-19','09:10:00',NULL,NULL,0,1,'entered',
   'success','entry','done','2026-08-19 09:10:00',
   'walk_in','operator','2026-08-19 09:00:00',1,'2026-08-19 09:00:00','operator','CARD-P2','cards/p2.png','2026-08-19 09:05:00')
SQL);
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
$protectedColumns = 'id,user_id,period_code,status,score,attempt_count,started_at,completed_at,state_json,'
    . 'invitation_source,invited_by,invited_at,is_uninvited_guest,uninvited_registered_at,'
    . 'uninvited_registered_by,invite_card_code,invite_card_file,invite_card_generated_at';
$protectedBefore = $pdo->query("SELECT {$protectedColumns} FROM user_periods ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$usersBefore = $pdo->query('SELECT * FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$settingsBefore = $pdo->query('SELECT * FROM egm_settings ORDER BY setting_key')->fetchAll(PDO::FETCH_ASSOC);

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
egmAttendanceResetAssert((int)($periodOne['correct_presence'] ?? -1) === 0, 'Period reset did not clear Correct Presence.');
egmAttendanceResetAssert((int)($periodOne['fake_presence'] ?? -1) === 0, 'Period reset did not clear Fake Presence.');
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
    $pdo->query("SELECT {$protectedColumns} FROM user_periods ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) === $protectedBefore,
    'Reset changed invitation, task progress, walk-in identity, or Invite Card data.'
);
egmAttendanceResetAssert(
    $pdo->query('SELECT * FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $usersBefore,
    'Reset changed or deleted EGM users.'
);
egmAttendanceResetAssert(
    $pdo->query('SELECT * FROM egm_settings ORDER BY setting_key')->fetchAll(PDO::FETCH_ASSOC) === $settingsBefore,
    'Reset changed period or Invite Card settings.'
);
egmAttendanceResetAssert(
    (int)$logsPdo->query("SELECT COUNT(*) FROM activity_logs WHERE action='" . EGM_CHECK_IN_ACTION . "'")->fetchColumn() === 0,
    'Full reset left Guest Control logs behind.'
);
egmAttendanceResetAssert(
    (int)$logsPdo->query("SELECT COUNT(*) FROM activity_logs WHERE action='unrelated_action'")->fetchColumn() === 1,
    'Full reset deleted unrelated logs.'
);

fwrite(STDOUT, "EGM attendance reset test passed.\n");
