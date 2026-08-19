<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/common.php';
require_once dirname(__DIR__) . '/api/lib/egm-instance-storage.php';

function egmDynamicAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$config = loadConfig(dirname(__DIR__) . '/api/config.php');
$pdo = connectDatabase($config);
egmDynamicAssert($pdo instanceof PDO, 'Could not connect to the EGM database');
$logsPdo = connectActivityLogDatabase($config);
egmDynamicAssert($logsPdo instanceof PDO, 'Could not connect to the EGM logs database');

$code = '99999999';
$temporaryDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'egm-dynamic-' . bin2hex(random_bytes(6));
mkdir($temporaryDir, 0700, true);
$csvPath = $temporaryDir . DIRECTORY_SEPARATOR . 'Invitees mapped.csv';
$mappingPath = $temporaryDir . DIRECTORY_SEPARATOR . 'EGM Mapped.json';
$nationalOne = (string)random_int(7000000000, 7999999998);
$nationalTwo = (string)((int)$nationalOne + 1);

try {
    dropEgmInstanceTables($pdo, $code);
    $tables = ensureEgmInstanceTables($pdo, $code);
    ensureActivityLogTable($logsPdo, 'EGM', $code);
    foreach ($tables as $key => $table) {
        egmDynamicAssert(egmInstanceTableExists($key === 'activity_logs' ? $logsPdo : $pdo, $table), "Missing dynamic table {$table}");
    }

    file_put_contents($mappingPath, json_encode([
        'workId' => 0,
        'firstName' => 1,
        'lastName' => 2,
        'nationalId' => 3,
        'phoneNumber' => 4,
    ], JSON_THROW_ON_ERROR));
    file_put_contents($csvPath, implode("\n", [
        'Work ID,First Name,Last Name,National ID,Phone Number,password,score,task completed ids',
        "W-1,Ali,One,{$nationalOne},09120000001,secret-one,10,P-1",
        "W-2,Sara,Two,{$nationalTwo},09120000002,secret-two,5,",
    ]) . "\n");
    egmInstanceSyncUsersFromCsv($pdo, $code, $csvPath, $mappingPath);

    $firstStatement = $pdo->prepare("SELECT `id`, `password_hash`, `guest_number` FROM `{$tables['users']}` WHERE `national_id` = :national_id");
    $firstStatement->execute([':national_id' => $nationalOne]);
    $first = $firstStatement->fetch(PDO::FETCH_ASSOC);
    egmDynamicAssert(is_array($first), 'First invitee was not synchronized');
    egmDynamicAssert(password_verify('secret-one', (string)$first['password_hash']), 'Invitee password was not hashed');
    egmDynamicAssert((string)$first['guest_number'] === '0001', 'First invitee did not receive guest number 0001');
    $firstId = (int)$first['id'];

    $insertProgress = $pdo->prepare("INSERT INTO `{$tables['user_periods']}` (`user_id`, `period_code`, `status`) VALUES (:user_id, 'P-1', 'started')");
    $insertProgress->execute([':user_id' => $firstId]);

    file_put_contents($csvPath, implode("\n", [
        'Work ID,First Name,Last Name,National ID,Phone Number,password,score,task completed ids',
        "W-9,Ali,Updated,{$nationalOne},09120000009,secret-one,20,P-1",
    ]) . "\n");
    egmInstanceSyncUsersFromCsv($pdo, $code, $csvPath, $mappingPath);

    $updatedStatement = $pdo->prepare("SELECT `id`, `work_id`, `last_name`, `is_active`, `guest_number` FROM `{$tables['users']}` WHERE `national_id` = :national_id");
    $updatedStatement->execute([':national_id' => $nationalOne]);
    $updated = $updatedStatement->fetch(PDO::FETCH_ASSOC);
    egmDynamicAssert((int)$updated['id'] === $firstId, 'National-ID upsert changed the stable user ID');
    egmDynamicAssert((string)$updated['guest_number'] === '0001', 'Guest number changed after Work ID/profile synchronization');
    $allUsers = $pdo->query("SELECT `id`, `work_id`, `national_id`, `is_active` FROM `{$tables['users']}` ORDER BY `id`")->fetchAll(PDO::FETCH_ASSOC);
    egmDynamicAssert((string)$updated['work_id'] === 'W-9', 'Changed Work ID was not updated: ' . json_encode([$updated, $allUsers, $nationalOne]));
    egmDynamicAssert((string)$updated['last_name'] === 'Updated', 'Changed profile data was not updated');
    egmDynamicAssert((int)$updated['is_active'] === 1, 'Retained invitee was not active');
    egmDynamicAssert(
        (int)$pdo->query("SELECT COUNT(*) FROM `{$tables['user_periods']}` WHERE `user_id` = {$firstId}")->fetchColumn() === 1,
        'User progress was lost during invitee synchronization'
    );
    $inactiveStatement = $pdo->prepare("SELECT `is_active` FROM `{$tables['users']}` WHERE `national_id` = :national_id");
    $inactiveStatement->execute([':national_id' => $nationalTwo]);
    egmDynamicAssert((int)$inactiveStatement->fetchColumn() === 0, 'Removed invitee was not retained as inactive');

    mkdir($temporaryDir . DIRECTORY_SEPARATOR . 'tasks', 0700, true);
    mkdir($temporaryDir . DIRECTORY_SEPARATOR . 'EGM Event', 0700, true);
    $settingsPath = $temporaryDir . DIRECTORY_SEPARATOR . 'Setting.json';
    $periodsPath = $temporaryDir . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . 'tasks.js';
    $largePhotoPath = $temporaryDir . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . 'large-photo.jpg';
    $answersPath = $temporaryDir . DIRECTORY_SEPARATOR . 'EGM Event' . DIRECTORY_SEPARATOR . 'Answers.csv';
    file_put_contents($settingsPath, '{"eventName":"Database canonical"}');
    file_put_contents($periodsPath, "window.EGM_TASKS = [];\n");
    file_put_contents($largePhotoPath, str_repeat("\x89EGM", 350000));
    file_put_contents($answersPath, "Work ID\n");
    $commit = egmInstanceCommitRuntimeFiles($pdo, $code, $temporaryDir);
    egmDynamicAssert($commit['files'] === 4, 'Not every managed runtime file was committed');

    file_put_contents($settingsPath, '{"eventName":"stale local value"}');
    unlink($periodsPath);
    $largePhotoHash = hash_file('sha256', $largePhotoPath);
    unlink($largePhotoPath);
    egmInstanceHydrateRuntimeFiles($pdo, $code, $temporaryDir);
    egmDynamicAssert(
        file_get_contents($settingsPath) === '{"eventName":"Database canonical"}',
        'Database hydration did not replace stale local state'
    );
    egmDynamicAssert(file_get_contents($periodsPath) === "window.EGM_TASKS = [];\n", 'Database hydration did not recreate a missing runtime file');
    egmDynamicAssert(hash_file('sha256', $largePhotoPath) === $largePhotoHash, 'Chunked runtime media was not restored correctly');

    file_put_contents($settingsPath, '{"eventName":"Committed update"}');
    egmInstanceCommitRuntimeFiles($pdo, $code, $temporaryDir);
    unlink($settingsPath);
    egmInstanceHydrateRuntimeFiles($pdo, $code, $temporaryDir);
    egmDynamicAssert(
        file_get_contents($settingsPath) === '{"eventName":"Committed update"}',
        'Updated canonical database content was not restored'
    );
} finally {
    dropEgmInstanceTables($pdo, $code);
    @unlink($csvPath);
    @unlink($mappingPath);
    @unlink($temporaryDir . DIRECTORY_SEPARATOR . 'Setting.json');
    @unlink($temporaryDir . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . 'tasks.js');
    @unlink($temporaryDir . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . 'large-photo.jpg');
    @unlink($temporaryDir . DIRECTORY_SEPARATOR . 'EGM Event' . DIRECTORY_SEPARATOR . 'Answers.csv');
    @rmdir($temporaryDir . DIRECTORY_SEPARATOR . 'tasks');
    @rmdir($temporaryDir . DIRECTORY_SEPARATOR . 'EGM Event');
    @rmdir($temporaryDir);
}

fwrite(STDOUT, "EGM dynamic tables test passed.\n");
