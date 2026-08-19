<?php
declare(strict_types=1);

function egmPeriodAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$periodManagers = [
    $root . '/mini apps/Event Guest Manager/EGMT.php',
    $root . '/mini apps/EGMs/EGM/EGMT.php',
];

foreach ($periodManagers as $path) {
    $source = file_get_contents($path);
    egmPeriodAssert(is_string($source), "Could not read {$path}");
    egmPeriodAssert(str_contains($source, '<h3>ایجاد بازه</h3>'), "Persian period creation heading is missing in {$path}");
    egmPeriodAssert(str_contains($source, '<h3>فهرست بازه‌ها</h3>'), "Persian period list heading is missing in {$path}");
    egmPeriodAssert(str_contains($source, "postAction('add', { title })"), "Period creation does not submit only its name in {$path}");
    egmPeriodAssert(!str_contains($source, 'id="tct-task-type"'), "Legacy task type selector is still visible in {$path}");
    egmPeriodAssert(!str_contains($source, 'id="tct-tag-code-auto"'), "Generated code is still exposed as a creation field in {$path}");
    egmPeriodAssert(str_contains($source, 'tctGenerateNextTagCode($tasks)'), "Automatic unique-code generation is missing in {$path}");
    egmPeriodAssert(str_contains($source, "unset(\$task['taskType'], \$task['task_type']);"), "Legacy task type data is not removed from periods in {$path}");
    egmPeriodAssert(str_contains($source, "return ['control', 'information', 'invite', 'invitees', 'invite-card', 'export'];"), "Period invitation/card/export panes are missing in {$path}");
    egmPeriodAssert(
        str_contains($source, 'egmInstanceWriteMissionPeriodsUsingProjectConfig'),
        "Period changes are not synchronized to the EGM database table in {$path}"
    );
    egmPeriodAssert(
        str_contains($source, 'return egmInstanceReadPeriods('),
        "Period discovery does not read the canonical EGM database row in {$path}"
    );
}

$periodFrontends = [
    $root . '/mini apps/Event Guest Manager/egm-panel-local.js',
    $root . '/mini apps/EGMs/EGM/egm-panel-local.js',
];
foreach ($periodFrontends as $path) {
    $source = file_get_contents($path);
    egmPeriodAssert(is_string($source), "Could not read {$path}");
    egmPeriodAssert(str_contains($source, 'data-period-excel-sheet'), "The Excel sheet selector is missing in {$path}");
    egmPeriodAssert(str_contains($source, 'state.excelWorkbook.SheetNames.forEach'), "Workbook sheet names are not added to the selector in {$path}");
    egmPeriodAssert(str_contains($source, 'applyPeriodExcelSheet(pane, selectedSheet)'), "Selecting a sheet does not trigger column processing in {$path}");
    egmPeriodAssert(str_contains($source, 'state.excelWorkbook.Sheets?.[sheetName]'), "The chosen workbook sheet is not read in {$path}");
    egmPeriodAssert(!str_contains($source, 'workbook.Sheets[workbook.SheetNames[0]]'), "The first workbook sheet is still selected implicitly in {$path}");
    egmPeriodAssert(str_contains($source, 'data-period-invite-card-export'), "The Invite Card Excel export button is missing in {$path}");
    egmPeriodAssert(str_contains($source, 'data-period-invite-card-background-file'), "The per-period Invite Card background upload is missing in {$path}");
    egmPeriodAssert(str_contains($source, "'save_period_background'"), "The per-period Invite Card background save action is not wired in {$path}");
    egmPeriodAssert(str_contains($source, "'remove_period_background'"), "The per-period Invite Card background fallback action is not wired in {$path}");
    egmPeriodAssert(str_contains($source, 'data-task-top-trigger="export"'), "The period export pane is missing in {$path}");
    egmPeriodAssert(str_contains($source, 'type=all_guests'), "The all-guests period export is not linked in {$path}");
    egmPeriodAssert(str_contains($source, 'type=uninvited_guests'), "The uninvited-guests period export is not linked in {$path}");
    egmPeriodAssert(str_contains($source, 'type=full_log'), "The full period log export is not linked in {$path}");
    egmPeriodAssert(str_contains($source, 'type=user_conditions'), "The user-conditions period export is not linked in {$path}");
    egmPeriodAssert(str_contains($source, "url.searchParams.set('action', 'export_excel')"), "The Invite Card Excel export action is not wired in {$path}");
    egmPeriodAssert(str_contains($source, "String(invitee.nationalId || '')"), "Period Invite Card QR data is not sourced from the invitee National ID in {$path}");
    egmPeriodAssert(!str_contains($source, 'const inviteUrl = new URL(`Invited/'), "Period Invite Card QR still contains the public invite URL in {$path}");
    egmPeriodAssert(
        str_contains($source, 'window.initEventGuestManagerPanel = initWheelSubLayouts;'),
        "The dynamically loaded EGM panel does not expose an explicit initializer in {$path}"
    );
}

$panelApp = file_get_contents($root . '/app.js');
egmPeriodAssert(is_string($panelApp), 'Could not inspect the main panel loader');
egmPeriodAssert(
    str_contains($panelApp, 'window.initEventGuestManagerPanel();'),
    'The main panel loader does not explicitly initialize dynamically injected EGM panels'
);
egmPeriodAssert(
    str_contains($panelApp, 'attribute.name === "defer" || attribute.name === "async"'),
    'The main panel loader still preserves parser-only scheduling attributes on dynamic scripts'
);

$taskAccessStores = [
    $root . '/mini apps/Event Guest Manager/task_access_store.php',
    $root . '/mini apps/EGMs/EGM/task_access_store.php',
];
foreach ($taskAccessStores as $path) {
    $source = file_get_contents($path);
    egmPeriodAssert(is_string($source), "Could not read {$path}");
    egmPeriodAssert(str_contains($source, "return ['control', 'information', 'invite', 'invitees', 'invite-card', 'export'];"), "Task access does not expose the period export pane in {$path}");
}

$renderer = file_get_contents($root . '/assets/egm-invite-card.js');
egmPeriodAssert(is_string($renderer), 'Could not inspect the Invite Card renderer');
egmPeriodAssert(str_contains($renderer, 'data: inviteeNationalIdForQr(invitee)'), 'The renderer does not enforce National ID QR content');
egmPeriodAssert(str_contains($renderer, '/^\\d{10}$/'), 'The renderer does not require an exact 10-digit National ID');

fwrite(STDOUT, "EGM period management test passed.\n");
