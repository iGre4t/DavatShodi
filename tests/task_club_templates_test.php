<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/database-instance-materializer.php';

function templateAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function templateAssertTaskViewOptionScope(string $app, string $label): void
{
    $closeStart = strpos($app, 'const closeQuizOverlay = () => {');
    $closeEnd = strpos($app, 'const escapeHtml', $closeStart === false ? 0 : $closeStart);
    templateAssert(
        $closeStart !== false && $closeEnd !== false && $closeEnd > $closeStart,
        $label . ' closeQuizOverlay function must be detectable.'
    );
    $closeBody = substr($app, (int)$closeStart, (int)$closeEnd - (int)$closeStart);
    templateAssert(
        !str_contains($closeBody, 'options?.'),
        $label . ' closeQuizOverlay must not read the out-of-scope options variable.'
    );
    templateAssert(
        preg_match('/const openInfoTaskView = \(taskTitle, infoTitle, infoText, options = \{\}\) => \{[\s\S]*?donationAmountTitle/', $app) === 1,
        $label . ' must initialize Donation options while opening the task view.'
    );
    templateAssert(
        str_contains($app, 'donationAmountInputEl.readOnly = donationAmountLocked;'),
        $label . ' must lock the amount when editing a pending direct Donation.'
    );
    templateAssert(
        str_contains($app, "if (\$method !== 'payroll')")
          && str_contains($app, "if (\$lockedAmount <= 0 || \$amount !== \$lockedAmount)"),
        $label . ' must enforce the direct-to-payroll-only change and immutable amount on the server.'
    );
    templateAssert(
        preg_match('/\.app\s*\{[^}]*overflow:\s*visible;/s', $app) === 1
          && str_contains($app, '0 8px 24px rgba(29, 55, 96, 0.1)'),
        $label . ' must render the outer frame shadow without hard clipped corner artifacts.'
    );
}

$standard = tcTemplateResolve('standard');
$iranMap = tcTemplateResolve('iran-map');
templateAssert(tcTemplateSelection('custom', 'iran-map')['templateId'] === 'iran-map', 'Iran Map must be selectable.');
templateAssert(is_file($standard['source'] . '/TCM.php'), 'Standard template must resolve.');
$standardApp = file_get_contents($standard['source'] . '/TCM.php');
templateAssert(is_string($standardApp), 'Standard Task Club app must be readable.');
templateAssert(
    preg_match("/setMetaWithScoreBlock\\(\\s*metaEl,\\s*'در انتظار مدیر سیستم'/u", $standardApp) === 1,
    'Pending Donation cards must render the standard divided score block.'
);
templateAssert(!str_contains($standardApp, 'در انتظار تایید ادمین'), 'Legacy Donation admin wording must not remain.');
templateAssertTaskViewOptionScope($standardApp, 'Standard template');
templateAssert(str_contains($standardApp, '.task-slide-action {'), 'Standard task slides must expose the shared bottom action style.');
templateAssert(str_contains($standardApp, 'id="tc-task-info-ack" class="login-btn info-task-ack task-slide-action"'), 'Standard Info action must use the shared bottom action style.');
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
    templateAssert(
        preg_match("/setMetaWithScoreBlock\\(\\s*metaEl,\\s*'در انتظار مدیر سیستم'/u", $mapApp) === 1,
        'Generated Iran Map Donation cards must retain the divided score block.'
    );
    templateAssert(!str_contains($mapApp, 'در انتظار تایید ادمین'), 'Generated Iran Map must not restore legacy Donation wording.');
    templateAssertTaskViewOptionScope($mapApp, 'Generated Iran Map template');
    templateAssert(str_contains($mapApp, "pin.setAttribute('r', '10')"), 'Iran Map letter selection pin must be visibly larger.');
    templateAssert(str_contains($mapApp, '.iran-map-letter-pin { fill: var(--tc-secondary, #2f8fff);'), 'Iran Map letter selection pin must use the blue theme color.');
    templateAssert(str_contains($mapApp, 'id="tc-iran-map-letter-next" class="login-btn describe-photo-btn task-slide-action"'), 'Iran Map continue action must stick to the slide bottom.');
    templateAssert(!str_contains($mapApp, 'id="tc-iran-map-letter-prefix"'), 'Iran Map letter prefix must not render as a separate field.');
    templateAssert(str_contains($mapApp, 'const iranMapLetterFixedText = () => {'), 'Iran Map letter editor must compose its fixed prefix inside the textarea.');
    templateAssert(str_contains($mapApp, "describePhotoTextareaEl.addEventListener('beforeinput'"), 'Iran Map letter prefix must be protected from editing.');
    templateAssert(str_contains($mapApp, 'text: editableText,'), 'Iran Map letter save must submit only the editable suffix.');
    templateAssert(str_contains($mapApp, 'await window.tcIranMap.complete();'), 'Review submissions must route the user to the map.');
    $mapStore = file_get_contents($mapTarget . '/iran-map-store.php');
    templateAssert(str_contains($mapStore, "['pending', 'approved']"), 'Pending review submissions must receive provisional dots.');
    templateAssert(str_contains($mapStore, "if (\$status === 'rejected') return false;"), 'Rejected Donations must lose their provisional dots.');
    $mapScript = file_get_contents($mapTarget . '/iran-map.js');
    templateAssert(str_contains($mapScript, "circle.remove();"), 'Client map must remove dots rejected by review.');
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
