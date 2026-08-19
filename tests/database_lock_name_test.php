<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/egm-database-runtime.php';
require_once dirname(__DIR__) . '/api/lib/tc-database-runtime.php';

function databaseLockNameAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$identity = '0001|EGM Event/Invitees mapped.csv';
$locks = [
    egmDatabaseRuntimeLockName('egm-invitees', $identity),
    egmDatabaseRuntimeLockName('egm-file', $identity),
    tcDatabaseRuntimeLockName('tc-invitees', $identity),
    tcDatabaseRuntimeLockName('tc-file', $identity),
];
foreach ($locks as $lockName) {
    databaseLockNameAssert(strlen($lockName) <= 64, "Database lock name exceeds MariaDB's 64-character limit: {$lockName}");
}
databaseLockNameAssert(count(array_unique($locks)) === count($locks), 'Lock scopes are not isolated.');
databaseLockNameAssert(
    egmDatabaseRuntimeLockName('egm-invitees', $identity) === $locks[0],
    'Database lock names are not deterministic.'
);

$pdo = connectDatabase(loadConfig(dirname(__DIR__) . '/api/config.php'));
databaseLockNameAssert($pdo instanceof PDO, 'Could not connect to the database.');
$acquire = $pdo->prepare('SELECT GET_LOCK(:name, 0)');
$release = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
foreach ($locks as $lockName) {
    $acquire->execute([':name' => $lockName]);
    databaseLockNameAssert((int)$acquire->fetchColumn() === 1, "Could not acquire database lock {$lockName}.");
    $release->execute([':name' => $lockName]);
}

fwrite(STDOUT, "Database lock name test passed.\n");
