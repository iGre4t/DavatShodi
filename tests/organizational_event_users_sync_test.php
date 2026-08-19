<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/modules/minor/Organizational Event Userbase/org_users_store.php';

function orgUsersTestAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function orgUsersTestRow(int $id, string $nationalId, string $workId = 'W1'): array
{
    return [
        'id' => $id,
        'work_id' => $workId,
        'first_name' => 'Test',
        'last_name' => 'Employee',
        'national_id' => $nationalId,
        'phone_number' => '09120000000',
        'deputy' => 'Deputy',
        'general_department' => 'General',
        'department' => 'Department',
        'gender' => 'X',
        'postal_level' => '1',
        'source_row' => 2,
        'imported_at' => '2026-01-01 00:00:00'
    ];
}

$prepared = orgUsersPrepareImportRows([[
    'workId' => 'W2',
    'firstName' => 'Test',
    'lastName' => 'Employee',
    'nationalId' => '۰۰۱۲۳۴۵۶۷۸',
    'phoneNumber' => '09120000000',
    'deputy' => 'Deputy',
    'generalDepartment' => 'General',
    'department' => 'Department',
    'gender' => 'X',
    'postalLevel' => '1',
    'sourceRow' => 2
]]);
orgUsersTestAssert($prepared['errors'] === [], 'valid Persian-digit National ID was rejected');
orgUsersTestAssert(($prepared['rows'][0]['national_id'] ?? '') === '0012345678', 'National ID digits were not normalized');

$missing = orgUsersPrepareImportRows([['workId' => 'W3', 'nationalId' => '', 'sourceRow' => 7]]);
orgUsersTestAssert(count($missing['errors']) === 1, 'missing National ID was accepted');

$invalid = orgUsersPrepareImportRows([['workId' => 'W3', 'nationalId' => '123', 'sourceRow' => 8]]);
orgUsersTestAssert(count($invalid['errors']) === 1, 'invalid-length National ID was accepted');

$duplicate = orgUsersPrepareImportRows([
    ['workId' => 'W1', 'nationalId' => '0012345678', 'sourceRow' => 2],
    ['workId' => 'W9', 'nationalId' => '0012345678', 'sourceRow' => 3]
]);
orgUsersTestAssert(count($duplicate['errors']) === 1, 'duplicate upload National ID was accepted');

$incomingChanged = $prepared['rows'][0];
$incomingNew = $incomingChanged;
$incomingNew['work_id'] = 'W4';
$incomingNew['national_id'] = '0098765432';
$incomingNew['source_row'] = 3;
$summary = orgUsersAnalyzeSyncRows([
    orgUsersTestRow(1, '0012345678', 'W1'),
    orgUsersTestRow(2, '0012345678', 'OLD-DUPLICATE'),
    orgUsersTestRow(3, '0011111111', 'W3'),
    orgUsersTestRow(4, '', 'BROKEN')
], [$incomingChanged, $incomingNew]);

orgUsersTestAssert($summary['added'] === 1, 'new employee count is incorrect');
orgUsersTestAssert($summary['updated'] === 1, 'changed Work ID was not treated as an update');
orgUsersTestAssert($summary['unchanged'] === 0, 'unchanged count is incorrect');
orgUsersTestAssert($summary['quit'] === 2, 'absent employee count is incorrect');
orgUsersTestAssert($summary['deduplicated'] === 1, 'legacy duplicate count is incorrect');
orgUsersTestAssert($summary['final_active'] === 2, 'final active count is incorrect');

$sameIncoming = $prepared['rows'][0];
$sameExisting = orgUsersTestRow(5, '0012345678', 'W2');
$sameSummary = orgUsersAnalyzeSyncRows([$sameExisting], [$sameIncoming]);
orgUsersTestAssert($sameSummary['unchanged'] === 1, 'identical employee was not recognized as unchanged');
orgUsersTestAssert($sameSummary['quit'] === 0, 'present employee was incorrectly marked as quit');

$conflictRows = [
    orgUsersTestRow(10, '0012345678', 'WORK-A'),
    orgUsersTestRow(11, '0012345678', 'WORK-B'),
    orgUsersTestRow(12, '0099999999', 'work-a'),
    orgUsersTestRow(13, '', 'WORK-C')
];
$conflictRows[0]['phone_number'] = '0912-000-0000';
$conflictRows[1]['phone_number'] = '09350000000';
$conflictRows[2]['phone_number'] = '09120000000';
$conflictRows[3]['phone_number'] = '';
$conflicts = orgUsersFindConflicts($conflictRows);
$conflictsById = [];
$conflictPeersById = [];
foreach ($conflicts as $conflict) {
    $conflictsById[(int)$conflict['id']] = $conflict['conflicts'];
    $conflictPeersById[(int)$conflict['id']] = array_map(
        static fn(array $peer): int => (int)$peer['id'],
        $conflict['conflict_peers'] ?? []
    );
}
orgUsersTestAssert(count($conflicts) === 4, 'conflict detection missed an employee');
orgUsersTestAssert(count($conflictsById[10] ?? []) === 3, 'combined National ID, Work ID, and phone conflicts were not reported');
orgUsersTestAssert(($conflictPeersById[10] ?? []) === [11, 12], 'all users related to the first conflict were not returned');
orgUsersTestAssert(($conflictPeersById[11] ?? []) === [10], 'duplicate National ID relationship is not symmetric');
orgUsersTestAssert(($conflictPeersById[12] ?? []) === [10], 'duplicate Work ID and phone relationship is not symmetric');
orgUsersTestAssert(str_contains(implode(' ', $conflictsById[13] ?? []), 'Missing National ID'), 'missing National ID conflict was not reported');
orgUsersTestAssert(orgUsersSqlLike('A%_B') === '%A\\%\\_B%', 'SQL LIKE filter escaping is incorrect');

$filterOptions = orgUsersBuildFilterOptions($conflictRows, 'active');
orgUsersTestAssert(!isset($filterOptions['first_name'], $filterOptions['last_name'], $filterOptions['national_id'], $filterOptions['work_id'], $filterOptions['phone_number']), 'removed identity/contact filters were returned');
orgUsersTestAssert(isset($filterOptions['deputy'], $filterOptions['general_department'], $filterOptions['department'], $filterOptions['gender'], $filterOptions['postal_level']), 'required dropdown filters are missing');

fwrite(STDOUT, "Organizational event users sync test passed.\n");
