<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/database-instance-materializer.php';

function templateAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$standard = tcTemplateResolve('standard');
$iranMap = tcTemplateResolve('iran-map');
templateAssert(tcTemplateSelection('custom', 'iran-map')['templateId'] === 'iran-map', 'Iran Map must be selectable.');
templateAssert(is_file($standard['source'] . '/TCM.php'), 'Standard template must resolve.');
templateAssert(tcTemplateSelection('custom', 'standard') === ['type' => 'custom', 'templateId' => 'standard'], 'Custom type must be retained.');
templateAssert(tcTemplateSelection('standard', 'ignored')['templateId'] === 'standard', 'Legacy creation uses standard.');
foreach ([['custom', '../Task Club'], ['custom', 'missing'], ['unknown', 'standard']] as [$type, $id]) {
    try {
        tcTemplateSelection($type, $id);
        throw new RuntimeException('Invalid selection was accepted.');
    } catch (InvalidArgumentException $expected) {
    }
}

$temporaryRoot = sys_get_temp_dir() . '/tc-template-test-' . bin2hex(random_bytes(6));
mkdir($temporaryRoot);
try {
    $mapTarget = $temporaryRoot . '/iran-map-club';
    databaseInstanceMaterializeCodeShell('tc', $iranMap['source'], $mapTarget, 'IranExample');
    $mapApp = file_get_contents($mapTarget . '/TCM.php');
    templateAssert(str_contains($mapApp, 'id="tc-open-iran-map"'), 'Generated club must expose map entry.');
    templateAssert(str_contains($mapApp, "require __DIR__ . '/iran-map-slide.php'"), 'Generated club must include slide.');
    templateAssert(is_file($mapTarget . '/iran-map.svg'), 'Map asset must survive restoration.');
    templateAssert(is_file($mapTarget . '/iran-map-slide.php'), 'Map implementation must survive restoration.');
    templateAssert(!str_contains(file_get_contents($standard['source'] . '/TCM.php'), 'tc-open-iran-map'), 'Standard template must remain unchanged.');
    // Two distinct coded experiences must produce distinct shells, without merging.
    foreach (['first', 'second'] as $id) {
        $source = $temporaryRoot . '/' . $id;
        mkdir($source);
        file_put_contents($source . '/TCM.php', '<?php // ' . $id . ' mini apps/Task Club');
        file_put_contents($source . '/TC Panel.php', '<?php // panel');
        file_put_contents($source . '/' . $id . '.js', 'window.experience = "' . $id . '";');
        file_put_contents($source . '/Setting.json', '{"eventName":"private source data"}');
        $target = $temporaryRoot . '/generated-' . $id;
        databaseInstanceMaterializeCodeShell('tc', $source, $target, 'Example');
        templateAssert(str_contains(file_get_contents($target . '/TCM.php'), $id . ' mini apps/missions/Example'), 'Selected source and generated paths must survive.');
        templateAssert(is_file($target . '/' . $id . '.js'), 'Template feature code must be copied.');
        templateAssert(!is_file($target . '/Setting.json'), 'Source event data must not be copied.');
        $other = $id === 'first' ? 'second' : 'first';
        templateAssert(!is_file($target . '/' . $other . '.js'), 'Other template features must not leak.');
    }
} finally {
    databaseInstanceMaterializerRemoveTree($temporaryRoot);
}
echo "Task Club template tests passed.\n";
