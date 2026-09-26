<?php
declare(strict_types=1);

// Load only the creator functions; do not authenticate or connect to production databases.
$source = file_get_contents(dirname(__DIR__) . '/mini apps/Task Club/TCCreator.php');
$start = strpos($source, 'function tcCreatorIsWithinPath(');
$end = strpos($source, 'function tcCreatorInitializeMission(');
if ($start === false || $end === false) throw new RuntimeException('Creator functions not found.');
eval(substr($source, $start, $end - $start));
$start = strpos($source, 'function tcCreatorNormalizeMissionName(');
$end = strpos($source, 'function tcCreatorMissionWebPath(');
eval(substr($source, $start, $end - $start));
$start = strpos($source, 'function tcCreatorCreateMission(');
$end = strpos($source, 'function tcCreatorUpdateMissionBranchSetting(');
eval(substr($source, $start, $end - $start));

function tcCreatorEnsureDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0777, true)) throw new RuntimeException('mkdir failed.');
}
function tcCreatorMissionDirectoryLabel(string $folder): string { return 'mini apps/missions/' . $folder; }
function tcCreatorMissionsRoot(): string { return $GLOBALS['root']; }
function tcCreatorEnsureGeneratorStorage(): void {}
function tcDbCopy(...$args) { throw new RuntimeException('Code copying must not query the database.'); }
function tcDbFileGetContents(...$args) { throw new RuntimeException('Code reads must not query the database.'); }
function tcDbFilePutContents(...$args) { throw new RuntimeException('Code writes must not query the database.'); }
function tcDbUnlink(...$args) { throw new RuntimeException('Staging cleanup must not query a deleted registry.'); }

$root = sys_get_temp_dir() . '/tc-creator-filesystem-' . bin2hex(random_bytes(6));
mkdir($root);
try {
    mkdir($root . '/هنوز');
    file_put_contents($root . '/هنوز/keep.txt', 'existing club');
    try {
        tcCreatorCreateMission('هنوز', 'custom', 'iran-map');
        throw new RuntimeException('Duplicate Persian club name was accepted.');
    } catch (InvalidArgumentException $expected) {
        if (!str_contains($expected->getMessage(), 'already exists')) throw $expected;
    }
    if (file_get_contents($root . '/هنوز/keep.txt') !== 'existing club') {
        throw new RuntimeException('Duplicate creation altered the existing club.');
    }
    mkdir($root . '/source/tasks', 0777, true);
    file_put_contents($root . '/source/TCM.php', '<?php /* mini apps/Task Club */');
    file_put_contents($root . '/source/tasks/tasks.js', 'window.TC_TASKS = [];');
    file_put_contents($root . '/source/Setting.json', '{"participants":"must not copy"}');
    $artifacts = ['TC Prize Levels.json.bak', 'TC Prizes.json.rollback', 'TC Prize Levels.json.corrupt-20260926', 'TC Prize Levels.json.lock'];
    foreach ($artifacts as $artifact) file_put_contents($root . '/source/' . $artifact, '[{"name":"another club"}]');
    tcCreatorCopyTaskClubTemplate($root . '/source', $root . '/build', 'Example', 'mini%20apps/missions/Example');
    if (!str_contains(file_get_contents($root . '/build/TCM.php'), 'mini apps/missions/Example')) {
        throw new RuntimeException('Generated code paths were not patched.');
    }
    if (is_file($root . '/build/Setting.json')) throw new RuntimeException('Runtime data was copied.');
    foreach ($artifacts as $artifact) {
        if (is_file($root . '/build/' . $artifact)) throw new RuntimeException('Another club runtime artifact was copied: ' . $artifact);
    }
    file_put_contents($root . '/build/TC Prize Levels.json.bak', '[{"name":"this club"}]');
    tcCreatorCopyTaskClubUpdates($root . '/source', $root . '/build', 'Example', 'mini%20apps/missions/Example');
    if (file_get_contents($root . '/build/TC Prize Levels.json.bak') !== '[{"name":"this club"}]') {
        throw new RuntimeException('Branch update overwrote the club backup with template data.');
    }
    tcCreatorRemoveTree($root . '/build', $root);
    if (is_dir($root . '/build')) throw new RuntimeException('Staging cleanup failed.');
    echo "Task Club creator filesystem tests passed.\n";
} finally {
    tcCreatorRemoveTree($root, $root);
}
