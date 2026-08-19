<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/egm-period-invite-cards.php';

function egmExcelIdentitySyncAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$pdo = connectDatabase(loadConfig($root . '/api/config.php'));
egmExcelIdentitySyncAssert($pdo instanceof PDO, 'Could not connect to the database.');
orgUsersEnsureTable($pdo);

do {
    $code = '97' . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $tables = egmInstanceTableNames($code);
} while (egmInstanceTableExists($pdo, $tables['data']));

$workId = (string)random_int(10000000, 89999999);
$fallbackWorkId = (string)random_int(90000000, 99999999);
$nationalId = '5' . str_pad((string)random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
$conflictingNationalId = '4' . str_pad((string)random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
$oeuId = 0;
$fallbackOeuId = 0;

try {
    ensureEgmInstanceTables($pdo, $code);
    $insertOeu = $pdo->prepare(
        'INSERT INTO `organizational_event_users` '
        . '(`work_id`,`first_name`,`last_name`,`national_id`,`phone_number`,`deputy`,`general_department`,`department`,`gender`,`postal_level`,`source_row`) '
        . "VALUES (:work_id,'Excel','OEU','','','','','','','',999999)"
    );
    $insertOeu->execute([':work_id' => $workId]);
    $oeuId = (int)$pdo->lastInsertId();

    $usersTable = $tables['users'];
    $insertGuest = $pdo->prepare(
        "INSERT INTO `{$usersTable}` (`work_id`,`first_name`,`last_name`,`national_id`,`source_row`,`source_type`) "
        . "VALUES (:work_id,:first_name,'',NULL,999999,'custom')"
    );
    $insertGuest->execute([':work_id' => $workId, ':first_name' => 'Excel match']);
    $matchedGuestId = (int)$pdo->lastInsertId();
    $insertGuest->execute([':work_id' => $fallbackWorkId, ':first_name' => 'Work fallback']);
    $fallbackGuestId = (int)$pdo->lastInsertId();

    $context = [
        'root' => $root,
        'mission_dir' => $root . '/mini apps/Event Guest Manager',
        'pdo' => $pdo,
        'registry' => null,
        'tables' => $tables,
        'code' => $code,
    ];
    $result = egmPeriodInvitesMatchExcel($context, 'sync-period', [[
        'work_id' => $workId,
        'national_id' => $nationalId,
        'first_name' => 'Excel match',
        'source_row' => 2,
    ]]);
    egmExcelIdentitySyncAssert((int)$result['matched'] === 1, 'The Excel row did not match the EGM guest by Work ID.');

    $readOeu = $pdo->prepare('SELECT `national_id` FROM `organizational_event_users` WHERE `id` = :id');
    $readOeu->execute([':id' => $oeuId]);
    egmExcelIdentitySyncAssert((string)$readOeu->fetchColumn() === $nationalId, 'Excel National ID was not written to OEU.');
    $readGuest = $pdo->prepare("SELECT `national_id`, `source_user_id` FROM `{$usersTable}` WHERE `id` = :id");
    $readGuest->execute([':id' => $matchedGuestId]);
    $matchedGuest = $readGuest->fetch(PDO::FETCH_ASSOC);
    egmExcelIdentitySyncAssert(
        is_array($matchedGuest)
            && (string)$matchedGuest['national_id'] === $nationalId
            && (int)$matchedGuest['source_user_id'] === $oeuId,
        'Excel National ID was not written to the matching EGM guest or linked to OEU.'
    );

    $conflictRejected = false;
    try {
        egmPeriodInvitesMatchExcel($context, 'sync-period', [[
            'work_id' => $workId,
            'national_id' => $conflictingNationalId,
            'source_row' => 3,
        ]]);
    } catch (InvalidArgumentException $error) {
        $conflictRejected = true;
    }
    egmExcelIdentitySyncAssert($conflictRejected, 'A conflicting Excel National ID overwrote the existing identity.');

    $insertOeu->execute([':work_id' => $fallbackWorkId]);
    $fallbackOeuId = (int)$pdo->lastInsertId();
    $invited = egmPeriodInvitesInsertRegistered(
        $context,
        'work-fallback',
        'oeu',
        ['o:' . $fallbackOeuId],
        'test'
    );
    egmExcelIdentitySyncAssert($invited === 1, 'An OEU guest without a National ID could not be invited by Work ID.');
    $readGuest->execute([':id' => $fallbackGuestId]);
    $fallbackGuest = $readGuest->fetch(PDO::FETCH_ASSOC);
    egmExcelIdentitySyncAssert(
        is_array($fallbackGuest)
            && trim((string)$fallbackGuest['national_id']) === ''
            && (int)$fallbackGuest['source_user_id'] === $fallbackOeuId,
        'The Work-ID-only OEU guest was duplicated or linked incorrectly.'
    );
    egmPeriodInviteCardsAssertQrIdentifiers($context, 'work-fallback');
} finally {
    if ($fallbackOeuId > 0) {
        $pdo->prepare('DELETE FROM `organizational_event_users` WHERE `id` = :id')->execute([':id' => $fallbackOeuId]);
    }
    if ($oeuId > 0) {
        $pdo->prepare('DELETE FROM `organizational_event_users` WHERE `id` = :id')->execute([':id' => $oeuId]);
    }
    dropEgmInstanceTables($pdo, $code);
}

fwrite(STDOUT, "EGM Excel identity sync test passed.\n");
