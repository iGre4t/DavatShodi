<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

/** @param array<string,string> $replacements */
function scaffoldTaskClubFile(string $source, string $target, array $replacements): void
{
    $content = file_get_contents($source);
    if (!is_string($content)) {
        throw new RuntimeException('Unable to read scaffold source: ' . $source);
    }
    $content = str_replace(array_keys($replacements), array_values($replacements), $content);
    if (file_put_contents($target, $content, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write scaffold target: ' . $target);
    }
}

$common = [
    'EGM' => 'TC',
    'Egm' => 'Tc',
    'egm' => 'tc',
];

scaffoldTaskClubFile(
    $root . '/api/lib/egm-registry.php',
    $root . '/api/lib/tc-registry.php',
    [
        "const EGM_INSTANCES_DIRECTORY = 'mini apps/EGMs';" => "const TC_INSTANCES_DIRECTORY = 'mini apps/missions';",
        "const EGM_LEGACY_INSTANCES_DIRECTORY = 'miniapps/EGMs';" => "const TC_LEGACY_INSTANCES_DIRECTORY = 'mini apps/missions';",
        "if (\$directory === 'mini apps/Event Guest Manager')" => "if (\$directory === 'mini apps/Task Club')",
        "#^mini apps/EGMs/[^/]+\$#u" => "#^mini apps/missions/[^/]+\$#u",
    ] + $common
);

scaffoldTaskClubFile(
    $root . '/api/lib/egm-instance-storage.php',
    $root . '/api/lib/tc-instance-storage.php',
    [
        "const EGM_DEVELOP_NAME = 'EGM Develop';" => "const TC_DEVELOP_NAME = 'TaskClub Develop';",
        "const EGM_DEVELOP_DIRECTORY = 'mini apps/Event Guest Manager';" => "const TC_DEVELOP_DIRECTORY = 'mini apps/Task Club';",
        "'egm.unspecified'" => "'taskclub.unspecified'",
    ] + $common
);

scaffoldTaskClubFile(
    $root . '/api/lib/egm-database-runtime.php',
    $root . '/api/lib/tc-database-runtime.php',
    [
        'mini apps/Event Guest Manager' => 'mini apps/Task Club',
        'mini apps/EGMs/' => 'mini apps/missions/',
    ] + $common
);

scaffoldTaskClubFile(
    $root . '/mini apps/Event Guest Manager/egm_asset.php',
    $root . '/mini apps/Task Club/tc_asset.php',
    $common
);

file_put_contents(
    $root . '/mini apps/Task Club/tc-database-runtime.php',
    "<?php\ndeclare(strict_types=1);\n\nrequire_once dirname(__DIR__, 2) . '/api/lib/tc-database-runtime.php';\n",
    LOCK_EX
);

echo "TaskClub database storage scaffolded.\n";
