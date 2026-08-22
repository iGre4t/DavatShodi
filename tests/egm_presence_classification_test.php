<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/egm-check-in.php';
require_once dirname(__DIR__) . '/api/lib/egm-period-exports.php';

function egmPresenceAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

egmPresenceAssert(
    egmCheckInForceOption(['entered_date' => null, 'entered_time' => null, 'quit_date' => null, 'quit_time' => null])
        === ['force_action' => 'entry', 'force_label' => 'Force Enter'],
    'A guest without entry did not receive Force Enter.'
);
egmPresenceAssert(
    egmCheckInForceOption(['entered_date' => '2026-08-22', 'entered_time' => '09:00:00', 'quit_date' => null, 'quit_time' => null])
        === ['force_action' => 'quit', 'force_label' => 'Force Quit'],
    'An entered guest did not receive Force Quit.'
);
egmPresenceAssert(
    egmCheckInForceOption(['entered_date' => '2026-08-22', 'entered_time' => '09:00:00', 'quit_date' => '2026-08-22', 'quit_time' => '17:00:00']) === [],
    'A completed attendance record still received a forced action.'
);

$base = [
    'first_name' => 'Test', 'last_name' => 'Guest', 'national_id' => '0012345678', 'work_id' => '0042',
    'phone_number' => '09120000000', 'attendance_state' => 'quit_completed',
    'entered_date' => '2026-08-22', 'entered_time' => '09:00:00',
    'quit_date' => '2026-08-22', 'quit_time' => '17:00:00',
];
$rows = [
    $base + ['correct_presence' => 1, 'fake_presence' => 0],
    $base + ['national_id' => '0098765432', 'correct_presence' => 0, 'fake_presence' => 1],
];
$correctRecords = egmPeriodExportRecords('correct_presence', $rows);
$fakeRecords = egmPeriodExportRecords('fake_presence', $rows);
egmPresenceAssert(count($correctRecords) === 1, 'Correct Presence export filter is wrong.');
egmPresenceAssert(count($fakeRecords) === 1, 'Fake Presence export filter is wrong.');
$xlsx = appXlsxFromSpreadsheetXml(egmPeriodExportSpreadsheetXml('حضور واقعی', $correctRecords));
egmPresenceAssert(str_starts_with($xlsx, "PK\x03\x04"), 'Correct Presence export is not a real XLSX package.');
egmPresenceAssert(str_contains($xlsx, 'Correct Presence'), 'Correct Presence column is missing from the XLSX package.');
egmPresenceAssert(
    egmExportDatedFilename('حضور واقعی', '2026-08-22') === 'حضور واقعی 31 مردادماه.xlsx',
    'The Shamsi-dated presence filename is wrong.'
);

$source = file_get_contents(dirname(__DIR__) . '/api/lib/egm-check-in.php');
egmPresenceAssert(is_string($source), 'Could not read the Guest Control backend.');
egmPresenceAssert(
    str_contains($source, '`correct_presence` = 0, `fake_presence` = 1'),
    'Forced attendance no longer marks Fake Presence.'
);
egmPresenceAssert(
    str_contains($source, '`correct_presence` = CASE WHEN `fake_presence` = 1 THEN 0 ELSE 1 END'),
    'Ordinary quit no longer finalizes Correct Presence safely.'
);
egmPresenceAssert(
    str_contains($source, 'data-force-log=') && str_contains($source, '<th>عملیات</th>'),
    'Forced attendance is no longer rendered inside the records operations column.'
);
egmPresenceAssert(
    !str_contains($source, 'data-force-attendance'),
    'The obsolete forced-attendance button still exists above the records list.'
);
egmPresenceAssert(
    str_contains($source, '.table-wrap{overflow:visible')
        && str_contains($source, 'table-layout:fixed')
        && str_contains($source, 'white-space:normal'),
    'The records table can regress to horizontal scrolling.'
);
egmPresenceAssert(
    str_contains($source, 'class="log-summary-row"')
        && str_contains($source, 'class="log-detail-row"')
        && str_contains($source, 'colspan="6"'),
    'Guest records are no longer rendered as two responsive rows.'
);
egmPresenceAssert(
    !str_contains($source, '<small>صحیح: ${presenceMark(row.correct_presence)} · نامعقول: ${presenceMark(row.fake_presence)}</small>'),
    'The records list still exposes Correct/Fake Presence classification.'
);
egmPresenceAssert(
    str_contains($source, "'force_action' => 'entry'")
        && str_contains($source, "] + \$forceOption"),
    'Forced actions are no longer persisted with their relevant log record.'
);

fwrite(STDOUT, "EGM presence classification test passed.\n");
