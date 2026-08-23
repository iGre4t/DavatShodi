<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/lib/egm-period-end.php';
require_once __DIR__ . '/../api/lib/egm-check-in.php';

function egmPeriodEndAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(<<<SQL
CREATE TABLE user_periods (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  period_code TEXT NOT NULL,
  entered_date TEXT NULL,
  entered_time TEXT NULL,
  quit_date TEXT NULL,
  quit_time TEXT NULL,
  correct_presence INTEGER NOT NULL DEFAULT 0,
  fake_presence INTEGER NOT NULL DEFAULT 0,
  attendance_state TEXT NOT NULL DEFAULT 'not_entered'
)
SQL);
$insert = $pdo->prepare(
    'INSERT INTO user_periods '
    . '(period_code,entered_date,entered_time,quit_date,quit_time,correct_presence,fake_presence,attendance_state) '
    . 'VALUES (?,?,?,?,?,?,?,?)'
);
$insert->execute(['01', '2026-08-23', '08:00:00', null, null, 0, 0, 'entered']);
$insert->execute(['01', '2026-08-23', '08:01:00', null, null, 0, 1, 'entered']);
$insert->execute(['01', '2026-08-23', '08:02:00', '2026-08-23', '12:00:00', 1, 0, 'quit_completed']);
$insert->execute(['01', '2026-08-23', null, null, null, 0, 0, 'not_entered']);
$insert->execute(['02', '2026-08-23', '08:03:00', null, null, 0, 0, 'entered']);

$result = egmPeriodEndClassifyOpenAttendance($pdo, 'user_periods', '01', 'correct_presence');
egmPeriodEndAssert($result['pending'] === 1, 'The unresolved entered/no-quit count is wrong.');
egmPeriodEndAssert($result['classified'] === 1, 'The unresolved record was not classified.');
$rows = $pdo->query('SELECT * FROM user_periods ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
egmPeriodEndAssert((int)$rows[0]['correct_presence'] === 1 && (int)$rows[0]['fake_presence'] === 0, 'Correct Presence was not applied.');
egmPeriodEndAssert((int)$rows[1]['fake_presence'] === 1, 'An existing Fake Presence decision was overwritten.');
egmPeriodEndAssert($rows[0]['quit_date'] === null && $rows[0]['quit_time'] === null, 'A fake quit timestamp was created.');
egmPeriodEndAssert($rows[0]['attendance_state'] === 'entered', 'Ending the period rewrote attendance state.');
egmPeriodEndAssert((int)$rows[4]['correct_presence'] === 0, 'Another period was modified.');

$invalidRejected = false;
try {
    egmPeriodEndClassifyOpenAttendance($pdo, 'user_periods', '01', 'unknown');
} catch (InvalidArgumentException) {
    $invalidRejected = true;
}
egmPeriodEndAssert($invalidRejected, 'An invalid period-end resolution was accepted.');

$endedAvailability = egmCheckInPeriodAvailability([
    'duration' => true,
    'active' => true,
    'quitRequired' => true,
    'startDate' => '2026-08-23',
    'startTime' => '08:00',
    'endDate' => '2026-08-23',
    'endTime' => '18:00',
    'endedAt' => '2026-08-23 12:30:00',
], new DateTimeImmutable('2026-08-23 13:00:00', new DateTimeZone('Asia/Tehran')));
egmPeriodEndAssert($endedAvailability['eligible'] === false, 'A manually ended period remained scannable.');
egmPeriodEndAssert($endedAvailability['reason'] === 'ended', 'A manually ended period returned the wrong status.');

$panelSource = (string)file_get_contents(__DIR__ . '/../mini apps/Event Guest Manager/egm-panel-local.js');
$endpointSource = (string)file_get_contents(__DIR__ . '/../mini apps/Event Guest Manager/EGMT.php');
egmPeriodEndAssert(str_contains($panelSource, 'data-action="end-period"'), 'The End Period control is missing.');
egmPeriodEndAssert(str_contains($panelSource, 'correct_presence') && str_contains($panelSource, 'fake_presence'), 'The period-end resolution choices are missing.');
egmPeriodEndAssert(str_contains($endpointSource, "\$action === 'end_period'"), 'The period-end endpoint action is missing.');
egmPeriodEndAssert(str_contains($endpointSource, 'beginTransaction()') && str_contains($endpointSource, 'rollBack()'), 'Period ending is no longer transactional.');

fwrite(STDOUT, "EGM period end test passed.\n");
