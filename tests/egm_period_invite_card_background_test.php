<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/common.php';
require_once dirname(__DIR__) . '/api/lib/egm-period-invite-cards.php';

function egmPeriodBackgroundAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = connectDatabase(loadConfig(dirname(__DIR__) . '/api/config.php'));
egmPeriodBackgroundAssert($pdo instanceof PDO, 'Could not connect to the database.');

do {
    $code = '97' . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $tables = egmInstanceTableNames($code);
} while (egmInstanceTableExists($pdo, $tables['data']));

$sharedImage = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
$periodImage = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl8sAAAAASUVORK5CYII=';
$periodOne = '01';
$periodTwo = '02';
$context = ['pdo' => $pdo, 'code' => $code];

try {
    ensureEgmInstanceTables($pdo, $code);
    $configuration = egmInviteCardMergeImageDraft(null, [
        'imageData' => $sharedImage,
        'imageName' => 'shared.png',
    ]);
    $configuration = egmInviteCardMergeDraft($configuration, [
        'sections' => ['layout', 'content'],
        'qrRect' => ['x' => 10, 'y' => 70, 'width' => 20, 'height' => 20],
        'textRect' => ['x' => 10, 'y' => 15, 'width' => 80, 'height' => 35],
        'textHtml' => '<p><strong>[fullname]</strong></p>',
    ]);
    $configuration = egmInviteCardPersistAssets($pdo, $code, $configuration, ['image']);
    egmInstanceWriteData($pdo, $code, 'invite_card', $configuration);

    $fallback = egmPeriodInviteCardsBackground($context, $periodOne, true);
    egmPeriodBackgroundAssert($fallback['source'] === 'shared', 'A period without an override did not use the shared image.');
    egmPeriodBackgroundAssert($fallback['imageData'] === $sharedImage, 'The shared image was not hydrated for a period fallback.');

    $saved = egmPeriodInviteCardsSaveBackground($context, $periodOne, $periodImage, 'period-one.png');
    egmPeriodBackgroundAssert($saved['has_override'] === true, 'The period override was not marked as saved.');
    egmPeriodBackgroundAssert($saved['source'] === 'period', 'The saved period image was not selected.');
    egmPeriodBackgroundAssert($saved['imageData'] === $periodImage, 'The period image did not survive database storage.');

    $otherPeriod = egmPeriodInviteCardsBackground($context, $periodTwo, true);
    egmPeriodBackgroundAssert($otherPeriod['source'] === 'shared', 'An override leaked into a different period.');
    egmPeriodBackgroundAssert($otherPeriod['imageData'] === $sharedImage, 'A different period lost the shared fallback image.');

    $generatedConfiguration = egmPeriodInviteCardsConfiguration($context, $periodOne);
    egmPeriodBackgroundAssert(is_array($generatedConfiguration), 'The generation configuration was not loaded.');
    egmPeriodBackgroundAssert($generatedConfiguration['imageData'] === $periodImage, 'Generation did not use the period image.');
    egmPeriodBackgroundAssert($generatedConfiguration['qrRect'] == $configuration['qrRect'], 'The period image changed the shared QR position.');
    egmPeriodBackgroundAssert($generatedConfiguration['textRect'] == $configuration['textRect'], 'The period image changed the shared text position.');
    egmPeriodBackgroundAssert($generatedConfiguration['textHtml'] === $configuration['textHtml'], 'The period image changed the shared invitation text.');

    $deleted = egmPeriodInviteCardsDeleteBackground($context, $periodOne);
    egmPeriodBackgroundAssert($deleted['has_override'] === false, 'The period override metadata was not deleted.');
    egmPeriodBackgroundAssert($deleted['source'] === 'shared', 'Deleting an override did not restore the shared image.');
    egmPeriodBackgroundAssert($deleted['imageData'] === $sharedImage, 'The shared image was not restored after deletion.');
} finally {
    dropEgmInstanceTables($pdo, $code);
}

fwrite(STDOUT, "EGM period Invite Card background test passed.\n");
