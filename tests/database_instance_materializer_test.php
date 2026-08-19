<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/database-instance-materializer.php';

function materializerTestAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function materializerTestRemoveTree(string $path): void
{
    $resolved = realpath($path);
    $temp = realpath(sys_get_temp_dir());
    if (!is_string($resolved) || !is_string($temp) || strcasecmp(dirname($resolved), $temp) !== 0
        || !str_starts_with(basename($resolved), 'database-instance-materializer-')) return;
    databaseInstanceMaterializerRemoveTree($resolved);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'database-instance-materializer-' . bin2hex(random_bytes(6));
$tcSource = $root . '/tc-source';
$egmSource = $root . '/egm-source';
mkdir($tcSource . '/tasks', 0775, true);
mkdir($tcSource . '/useractivitylogs/logs', 0775, true);
mkdir($egmSource . '/EGM Event', 0775, true);

try {
    file_put_contents($tcSource . '/TC Panel.php', "<?php // mini apps/Task Club\n");
    file_put_contents($tcSource . '/worker.php', "<?php return __DIR__ . '/../../api/config.php';\n");
    file_put_contents($tcSource . '/Setting.json', '{"must":"stay-in-db"}');
    file_put_contents($tcSource . '/tasks/tasks.js', 'window.TC_TASKS = [{"must":"stay-in-db"}];');
    file_put_contents($tcSource . '/useractivitylogs/logs/.htaccess', 'Require all denied');
    $tcTarget = $root . '/tc-target';
    $tcFiles = databaseInstanceMaterializeCodeShell('tc', $tcSource, $tcTarget, 'RestoredMission');
    materializerTestAssert($tcFiles === 3, 'TC materializer copied an unexpected number of code files.');
    materializerTestAssert(is_file($tcTarget . '/TC Panel.php'), 'TC panel code was not restored.');
    materializerTestAssert(!is_file($tcTarget . '/Setting.json'), 'TC settings escaped the database during restore.');
    materializerTestAssert(!is_file($tcTarget . '/tasks/tasks.js'), 'TC task state escaped the database during restore.');
    $tcPanel = file_get_contents($tcTarget . '/TC Panel.php');
    materializerTestAssert(is_string($tcPanel) && str_contains($tcPanel, 'mini apps/missions/RestoredMission'), 'TC code path was not patched.');

    file_put_contents($egmSource . '/EGM Panel.php', "<?php // mini apps/Event Guest Manager\n");
    file_put_contents($egmSource . '/worker.php', "<?php return __DIR__ . '/../../api/config.php';\n");
    file_put_contents($egmSource . '/Setting.json', '{"must":"stay-in-db"}');
    file_put_contents($egmSource . '/EGM Event/Answers.csv', "Work ID\n");
    $egmTarget = $root . '/egm-target';
    $egmFiles = databaseInstanceMaterializeCodeShell('egm', $egmSource, $egmTarget, 'RestoredEgm');
    materializerTestAssert($egmFiles === 2, 'EGM materializer copied an unexpected number of code files.');
    materializerTestAssert(is_file($egmTarget . '/EGM Panel.php'), 'EGM panel code was not restored.');
    materializerTestAssert(!is_file($egmTarget . '/Setting.json'), 'EGM settings escaped the database during restore.');
    materializerTestAssert(!is_file($egmTarget . '/EGM Event/Answers.csv'), 'EGM answers escaped the database during restore.');
    $egmPanel = file_get_contents($egmTarget . '/EGM Panel.php');
    materializerTestAssert(is_string($egmPanel) && str_contains($egmPanel, 'mini apps/EGMs/RestoredEgm'), 'EGM code path was not patched.');

    echo "Database instance materializer test passed.\n";
} finally {
    materializerTestRemoveTree($root);
}
