<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/common.php';
require_once dirname(__DIR__) . '/api/lib/egm-instance-storage.php';
require_once dirname(__DIR__) . '/modules/minor/Organizational Event Userbase/org_users_store.php';

function egmOeuEnrichmentAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = connectDatabase(loadConfig(dirname(__DIR__) . '/api/config.php'));
egmOeuEnrichmentAssert($pdo instanceof PDO, 'Could not connect to the database.');
orgUsersEnsureTable($pdo);

do {
    $code = '98' . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $tables = egmInstanceTableNames($code);
} while (egmInstanceTableExists($pdo, $tables['data']));

$suffix = bin2hex(random_bytes(5));
$workId = 'oeu-enrich-' . $suffix;
$ambiguousWorkId = 'oeu-duplicate-' . $suffix;
$nationalId = '8' . str_pad((string)random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
$otherNationalId = '7' . str_pad((string)random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
$thirdNationalId = '6' . str_pad((string)random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
$oeuIds = [];

try {
    ensureEgmInstanceTables($pdo, $code);
    $insertOeu = $pdo->prepare(
        'INSERT INTO `organizational_event_users` '
        . '(`work_id`, `first_name`, `last_name`, `national_id`, `phone_number`, `deputy`, '
        . '`general_department`, `department`, `gender`, `postal_level`, `source_row`) '
        . 'VALUES (:work_id, :first_name, :last_name, :national_id, :phone_number, :deputy, '
        . ':general_department, :department, :gender, :postal_level, :source_row)'
    );
    $insertOeu->execute([
        ':work_id' => $workId,
        ':first_name' => 'OEU first',
        ':last_name' => 'OEU last',
        ':national_id' => $nationalId,
        ':phone_number' => '09120000000',
        ':deputy' => 'Deputy',
        ':general_department' => 'General',
        ':department' => 'Department',
        ':gender' => 'Test',
        ':postal_level' => 'Level',
        ':source_row' => 41,
    ]);
    $oeuIds[] = (int)$pdo->lastInsertId();
    foreach ([$otherNationalId, $thirdNationalId] as $duplicateNationalId) {
        $insertOeu->execute([
            ':work_id' => $ambiguousWorkId,
            ':first_name' => 'Duplicate',
            ':last_name' => 'Work ID',
            ':national_id' => $duplicateNationalId,
            ':phone_number' => '',
            ':deputy' => '',
            ':general_department' => '',
            ':department' => '',
            ':gender' => '',
            ':postal_level' => '',
            ':source_row' => 42,
        ]);
        $oeuIds[] = (int)$pdo->lastInsertId();
    }

    $usersTable = $tables['users'];
    $insertGuest = $pdo->prepare(
        "INSERT INTO `{$usersTable}` (`work_id`, `first_name`, `last_name`, `national_id`, `phone_number`, "
        . "`deputy`, `general_department`, `department`, `gender`, `postal_level`, `source_row`, `source_type`) "
        . "VALUES (:work_id, :first_name, '', NULL, '', '', '', '', '', '', 0, 'period_excel')"
    );
    $insertGuest->execute([':work_id' => $workId, ':first_name' => 'Keep this name']);
    $guestId = (int)$pdo->lastInsertId();
    $insertGuest->execute([':work_id' => $ambiguousWorkId, ':first_name' => 'Ambiguous guest']);
    $ambiguousGuestId = (int)$pdo->lastInsertId();

    $updated = egmInstanceEnrichUsersFromOeu($pdo, $code);
    egmOeuEnrichmentAssert($updated === 1, 'Exactly one safely matched guest should be enriched.');

    $lookup = $pdo->prepare("SELECT * FROM `{$usersTable}` WHERE `id` = :id");
    $lookup->execute([':id' => $guestId]);
    $guest = $lookup->fetch(PDO::FETCH_ASSOC);
    egmOeuEnrichmentAssert(is_array($guest), 'The enriched guest was not found.');
    egmOeuEnrichmentAssert((string)$guest['national_id'] === $nationalId, 'National ID was not recovered from OEU.');
    egmOeuEnrichmentAssert((string)$guest['phone_number'] === '09120000000', 'Missing phone number was not recovered.');
    egmOeuEnrichmentAssert((string)$guest['department'] === 'Department', 'Missing department was not recovered.');
    egmOeuEnrichmentAssert((string)$guest['first_name'] === 'Keep this name', 'Existing EGM data was overwritten.');
    egmOeuEnrichmentAssert((string)$guest['source_type'] === 'period_excel', 'The original guest source was changed.');
    egmOeuEnrichmentAssert((int)$guest['source_user_id'] === $oeuIds[0], 'The recovered OEU relationship was not stored.');

    $lookup->execute([':id' => $ambiguousGuestId]);
    $ambiguousGuest = $lookup->fetch(PDO::FETCH_ASSOC);
    egmOeuEnrichmentAssert(
        is_array($ambiguousGuest) && trim((string)$ambiguousGuest['national_id']) === '',
        'A duplicated OEU Work ID must not be used for automatic identity matching.'
    );
} finally {
    if ($oeuIds) {
        $placeholders = implode(',', array_fill(0, count($oeuIds), '?'));
        $pdo->prepare("DELETE FROM `organizational_event_users` WHERE `id` IN ({$placeholders})")->execute($oeuIds);
    }
    dropEgmInstanceTables($pdo, $code);
}

fwrite(STDOUT, "EGM OEU enrichment test passed.\n");
