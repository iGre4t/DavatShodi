<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/common.php';
require_once dirname(__DIR__) . '/api/lib/egm-instance-storage.php';
require_once dirname(__DIR__) . '/api/lib/egm-invite-card-store.php';

function inviteCardPersistenceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$pixel = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
$config = egmInviteCardMergeImageDraft(null, [
    'imageData' => $pixel,
    'imageName' => 'template.png',
]);

$config = egmInviteCardMergeDraft($config, [
    'sections' => ['content'],
    'textHtml' => '<p><strong><span style="color:#0066cc">[code]</span></strong> - [fullname]</p>',
    'qrData' => 'https://example.test/this-value-must-be-ignored',
    'conditionalVariables' => [[
        'token' => 'code',
        'field' => 'gender',
        'rules' => [[
            'operator' => 'equals',
            'value' => 'مرد',
            'text' => 'جناب آقای',
        ]],
        'fallback' => 'مهمان گرامی',
    ]],
    'conditionalBuilderDraft' => [
        'token' => 'department_title',
        'field' => 'department',
        'rules' => [[
            'operator' => 'equals',
            'value' => '',
            'text' => 'در حال تکمیل',
        ]],
        'fallback' => '',
        'editingToken' => '',
    ],
]);

$config = egmInviteCardMergeDraft($config, [
    'sections' => ['layout', 'font'],
    'qrRect' => ['x' => 10, 'y' => 70, 'width' => 20, 'height' => 20],
    'textRect' => ['x' => 10, 'y' => 15, 'width' => 80, 'height' => 35],
    'fontData' => '',
    'fontName' => '',
]);

inviteCardPersistenceAssert(($config['imageName'] ?? '') === 'template.png', 'A content/layout autosave replaced the uploaded image');
inviteCardPersistenceAssert(str_contains((string)($config['textHtml'] ?? ''), '<strong>'), 'Bold invitation text was not retained');
inviteCardPersistenceAssert(str_contains((string)($config['textHtml'] ?? ''), 'color:#0066cc'), 'Partial text color was not retained');
inviteCardPersistenceAssert(($config['conditionalVariables'][0]['token'] ?? '') === 'code', 'Registered conditional code was not retained');
inviteCardPersistenceAssert(($config['conditionalBuilderDraft']['token'] ?? '') === 'department_title', 'Unfinished conditional builder state was not retained');
inviteCardPersistenceAssert(($config['conditionalBuilderDraft']['rules'][0]['value'] ?? null) === '', 'An unfinished conditional rule was rejected instead of being saved as a draft');
inviteCardPersistenceAssert(is_array($config['qrRect'] ?? null) && is_array($config['textRect'] ?? null), 'Selection areas were not retained');

$encoded = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
inviteCardPersistenceAssert(is_string($encoded), 'Invite Card data could not be serialized');
$roundTrip = json_decode($encoded, true);
inviteCardPersistenceAssert(is_array($roundTrip) && ($roundTrip['text'] ?? '') !== '', 'Invite Card data did not survive a JSON reload');

$databaseConfig = loadConfig(dirname(__DIR__) . '/api/config.php');
$pdo = connectDatabase($databaseConfig);
inviteCardPersistenceAssert($pdo instanceof PDO, 'Could not connect to the EGM database');

$testKey = 'invite_card_persistence_test_' . getmypid();
$testAsset = 'test_' . getmypid();
$tables = egmInstanceTableNames(EGM_DEVELOP_CODE);
try {
    egmInstanceWriteData($pdo, EGM_DEVELOP_CODE, $testKey, $config);
    $stored = egmInstanceReadData($pdo, EGM_DEVELOP_CODE, $testKey, null);
    inviteCardPersistenceAssert(is_array($stored), 'Invite Card data was not read back from the database');
    inviteCardPersistenceAssert(($stored['imageName'] ?? '') === 'template.png', 'The uploaded template did not survive the database round trip');
    inviteCardPersistenceAssert(($stored['conditionalVariables'][0]['token'] ?? '') === 'code', 'Conditional code did not survive the database round trip');
    inviteCardPersistenceAssert(($stored['qrData'] ?? '') === EGM_INVITE_CARD_QR_DATA, 'QR content was not fixed to the National ID variable');

    // This is intentionally larger than the local 1 MB max_allowed_packet. The
    // asset layer must split it into safe DB rows and reconstruct it losslessly.
    $largeDataUri = 'data:image/png;base64,' . base64_encode(str_repeat('invite-card-binary-', 80000));
    egmInviteCardWriteAsset($pdo, EGM_DEVELOP_CODE, $testAsset, $largeDataUri);
    inviteCardPersistenceAssert(egmInviteCardReadAsset($pdo, EGM_DEVELOP_CODE, $testAsset) === $largeDataUri, 'A large uploaded asset did not survive chunked database storage');
    $chunkStatement = $pdo->prepare("SELECT COUNT(*) FROM `{$tables['data']}` WHERE `file_path` = :file_path AND `storage_kind` = 'invite_card_chunk'");
    $chunkStatement->execute([':file_path' => 'invite-card/' . $testAsset]);
    inviteCardPersistenceAssert((int)$chunkStatement->fetchColumn() > 1, 'The large uploaded asset was not divided into safe database chunks');
} finally {
    $statement = $pdo->prepare("DELETE FROM `{$tables['data']}` WHERE `data_key` = :data_key");
    $statement->execute([':data_key' => $testKey]);
    egmInviteCardDeleteAsset($pdo, EGM_DEVELOP_CODE, $testAsset);
}

fwrite(STDOUT, "EGM Invite Card persistence test passed.\n");
