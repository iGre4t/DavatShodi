<?php
declare(strict_types=1);

function ensureEventEntryPoints(string $eventDir, string $eventCode = ''): bool
{
    $eventCode = trim($eventCode);
    if ($eventCode === '') {
        $eventCode = basename(trim($eventDir));
    }
    if ($eventCode === '') {
        return false;
    }
    if (!is_dir($eventDir) && !mkdir($eventDir, 0755, true) && !is_dir($eventDir)) {
        return false;
    }
    $drawPath = $eventDir . '/draw.php';
    $prizePath = $eventDir . '/prizes.php';
    $drawWritten = writeEventEntryPoint($drawPath, $eventCode, 'draw.php');
    $prizeWritten = writeEventEntryPoint($prizePath, $eventCode, 'prizes.php');
    return $drawWritten && $prizeWritten;
}

function writeEventEntryPoint(string $filePath, string $eventCode, string $targetScript): bool
{
    $eventCode = trim($eventCode);
    if ($eventCode === '') {
        return false;
    }
    $content = buildEventEntryPointContent($eventCode, $targetScript);
    return file_put_contents($filePath, $content) !== false;
}

function buildEventEntryPointContent(string $eventCode, string $targetScript): string
{
    $eventCode = trim($eventCode);
    $exportedCode = var_export($eventCode, true);
    return "<?php\nif (!defined('EVENT_SCOPED_EVENT_CODE')) {\n    define('EVENT_SCOPED_EVENT_CODE', {$exportedCode});\n}\nrequire dirname(dirname(__DIR__)) . '/{$targetScript}';\n";
}
