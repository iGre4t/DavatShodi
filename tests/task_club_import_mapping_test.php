<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/tc-database-runtime.php';

$rows = [
    ['نام', 'کد ملی', 'نام خانوادگی', 'شماره پرسنلی', 'شماره موبایل شخصی', 'password'],
    ['Example', '0012345678', 'Participant', '1234', '09123456789', '54321'],
];
$mapped = tcDatabaseRuntimeMapImportedInvitees($rows, [
    'workId' => 3, 'firstName' => 0, 'lastName' => 2, 'nationalId' => 1, 'phoneNumber' => 4,
]);
if ($mapped[0] !== ['Work ID', 'First Name', 'Last Name', 'National ID', 'Phone Number', 'password']) {
    throw new RuntimeException('Imported headers must match the relational writer.');
}
if ($mapped[1] !== ['1234', 'Example', 'Participant', '0012345678', '09123456789', '54321']) {
    throw new RuntimeException('Column mapping or leading zeros were lost.');
}
$optional = tcDatabaseRuntimeMapImportedInvitees($rows, ['workId' => 3]);
if (array_slice($optional[1], 0, 5) !== ['1234', '', '', '', '']) {
    throw new RuntimeException('Unmapped optional fields must remain empty.');
}
echo "TaskClub import mapping test passed.\n";
