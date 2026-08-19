<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/egm-period-invite-cards.php';

function egmInviteCardRegenerationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

egmInviteCardRegenerationAssert(
    egmPeriodInviteCardsNormalizeNationalId('۰۰۱۲۳۴۵۶۷۸') === '0012345678',
    'Persian-digit National IDs are not normalized without losing leading zeroes'
);
egmInviteCardRegenerationAssert(
    egmPeriodInviteCardsNormalizeNationalId('001-234-5678') === '0012345678',
    'Formatted 10-digit National IDs are not normalized'
);
egmInviteCardRegenerationAssert(
    egmPeriodInviteCardsNormalizeNationalId('123456789') === '',
    'A non-10-digit National ID was accepted'
);
egmInviteCardRegenerationAssert(
    egmPeriodInviteCardsNormalizeWorkId('۱۲۳۴۵۶') === '123456',
    'A Persian-digit Work ID was not normalized'
);
egmInviteCardRegenerationAssert(
    egmPeriodInviteCardsNormalizeWorkId('1234') === '1234'
        && egmPeriodInviteCardsNormalizeWorkId('123456789') === '123456789',
    'A valid 4-to-9-digit Work ID was rejected'
);
egmInviteCardRegenerationAssert(
    egmPeriodInviteCardsNormalizeWorkId('123') === ''
        && egmPeriodInviteCardsNormalizeWorkId('1234567890') === ''
        && egmPeriodInviteCardsNormalizeWorkId('12-345') === '',
    'An invalid-length Work ID was accepted'
);
egmInviteCardRegenerationAssert(
    egmPeriodInvitesValidNationalId('۰۰۱۲۳۴۵۶۷۸') === '0012345678'
        && egmPeriodInvitesValidNationalId('123456789') === '',
    'Excel National IDs are not restricted to an exact 10 digits'
);

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'egm-invite-card-regeneration-' . bin2hex(random_bytes(6));
egmInviteCardRegenerationAssert(mkdir($directory, 0700), 'Could not create the regeneration test directory');
$destination = $directory . DIRECTORY_SEPARATOR . 'stable-code.jpg';
$staging = $destination . '.upload-test';
try {
    file_put_contents($destination, 'old-card');
    file_put_contents($staging, 'new-card');
    egmPeriodInviteCardsReplaceGeneratedFile($staging, $destination);
    egmInviteCardRegenerationAssert(file_get_contents($destination) === 'new-card', 'Regeneration did not replace the existing JPG');
    egmInviteCardRegenerationAssert(!is_file($staging), 'Regeneration left its staging file behind');
    egmInviteCardRegenerationAssert(glob($destination . '.previous-*') === [], 'Regeneration left a backup file behind');
} finally {
    if (is_file($staging)) unlink($staging);
    if (is_file($destination)) unlink($destination);
    foreach (glob($destination . '.previous-*') ?: [] as $backup) unlink($backup);
    rmdir($directory);
}

fwrite(STDOUT, "EGM period Invite Card regeneration test passed.\n");
