<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/egm-period-invite-cards.php';

function egmInviteCardExportAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$export = egmPeriodInviteCardsBuildExportData([
    [
        'first_name' => 'علی',
        'last_name' => 'احمدی',
        'national_id' => '0012345678',
        'work_id' => '00042',
        'phone_number' => '09120000000',
        'invite_card_code' => 'AbC1230000001',
    ],
    [
        'first_name' => 'سارا',
        'last_name' => 'محمدی',
        'national_id' => '0098765432',
        'work_id' => '00117',
        'phone_number' => '09350000000',
        'invite_card_code' => 'XyZ9870000001',
    ],
], '00000', '01', 'https://example.test/DavatShodi', '2026-08-22');

egmInviteCardExportAssert($export['count'] === 2, 'The generated row count is incorrect.');
egmInviteCardExportAssert($export['filename'] === 'لینک کارت‌های دعوت 31 مردادماه.xlsx', 'The Shamsi-dated export filename is incorrect.');
egmInviteCardExportAssert(($export['period_date'] ?? '') === '2026-08-22', 'The export did not retain its period date.');
egmInviteCardExportAssert(
    egmExportPeriodDatedFilename('لینک کارت‌های دعوت', '2026-08-25') === 'لینک کارت‌های دعوت 3 شهریورماه.xlsx',
    'A future period export was named with the wrong Shamsi date.'
);
egmInviteCardExportAssert(
    egmExportPeriodDate(['startDate' => '', 'endDate' => '2026-08-25']) === '2026-08-25',
    'The period date did not fall back to another configured timeline date.'
);
$missingPeriodDateRejected = false;
try {
    egmPeriodInviteCardsBuildExportData([], '00000', '01', 'https://example.test/DavatShodi', null);
} catch (InvalidArgumentException) {
    $missingPeriodDateRejected = true;
}
egmInviteCardExportAssert($missingPeriodDateRejected, 'A period export silently fell back to today.');
$firstRow = $export['rows'][0] ?? [];
egmInviteCardExportAssert(is_array($firstRow) && count($firstRow) === 5, 'The XLSX source columns are malformed.');
egmInviteCardExportAssert(($firstRow['national_id'] ?? '') === '0012345678', 'National ID leading zeroes were not preserved.');
egmInviteCardExportAssert(($firstRow['work_id'] ?? '') === '00042', 'Work ID leading zeroes were not preserved.');
egmInviteCardExportAssert(($firstRow['phone_number'] ?? '') === '09120000000', 'Phone number leading zeroes were not preserved.');
egmInviteCardExportAssert(
    ($firstRow['invite_url'] ?? '') === 'https://example.test/DavatShodi/Invited/AbC1230000001',
    'The public invite-card URL is incorrect.'
);

fwrite(STDOUT, "EGM period Invite Card XLSX source-data test passed.\n");
