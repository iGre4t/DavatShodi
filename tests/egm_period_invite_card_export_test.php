<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/egm-period-invite-cards.php';

function egmInviteCardExportAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$export = egmPeriodInviteCardsBuildExportWorkbook([
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
], '00000', '01', 'https://example.test/DavatShodi');

egmInviteCardExportAssert($export['count'] === 2, 'The generated row count is incorrect.');
egmInviteCardExportAssert($export['filename'] === 'EGM-00000-period-01-invite-card-links.xlsx', 'The export filename is incorrect.');
egmInviteCardExportAssert(str_starts_with($export['content'], "PK\x03\x04"), 'The export is not a genuine XLSX package.');

$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'egm-invite-card-export-' . bin2hex(random_bytes(6)) . '.zip';
file_put_contents($temporary, $export['content']);
try {
    $archive = new PharData($temporary);
    $sheet = $archive['xl/worksheets/sheet1.xml']->getContent();
    egmInviteCardExportAssert(str_contains($sheet, '0012345678'), 'National ID leading zeroes were not preserved.');
    egmInviteCardExportAssert(str_contains($sheet, '00042'), 'Work ID leading zeroes were not preserved.');
    egmInviteCardExportAssert(str_contains($sheet, '09120000000'), 'Phone number was not exported.');
    egmInviteCardExportAssert(
        str_contains($sheet, 'https://example.test/DavatShodi/Invited/AbC1230000001'),
        'The public invite-card URL is incorrect.'
    );
    egmInviteCardExportAssert(str_contains($sheet, '<f>HYPERLINK('), 'The invite-card URL is not a clickable Excel hyperlink.');
} finally {
    unset($archive);
    @unlink($temporary);
}

fwrite(STDOUT, "EGM period Invite Card Excel export test passed.\n");
