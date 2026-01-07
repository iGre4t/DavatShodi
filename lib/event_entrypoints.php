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
    $invitePath = $eventDir . '/invite.php';
    $drawWritten = writeEventEntryPoint($drawPath, $eventCode, 'draw.php');
    $prizeWritten = writeEventEntryPoint($prizePath, $eventCode, 'prizes.php');
    $inviteWritten = writeEventEntryPoint($invitePath, $eventCode, 'invite.php');
    $logsReady = ensureInviteLogFile($eventDir);
    return $drawWritten && $prizeWritten && $inviteWritten && $logsReady;
}

function ensureInviteLogFile(string $eventDir): bool
{
    $logPath = rtrim($eventDir, '/\\') . DIRECTORY_SEPARATOR . 'InviteLogs.json';
    if (is_file($logPath)) {
        return true;
    }
    $encoded = json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        return false;
    }
    return file_put_contents($logPath, $encoded) !== false;
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
    $baseRequire = "require dirname(dirname(__DIR__)) . '/{$targetScript}';";

    if ($targetScript === 'invite.php') {
        return "<?php\nif (!defined('EVENT_SCOPED_EVENT_CODE')) {\n    define('EVENT_SCOPED_EVENT_CODE', {$exportedCode});\n}\n\$previousScriptName = \$_SERVER['SCRIPT_NAME'] ?? null;\n\$normalizedScriptName = null;\nif (\$previousScriptName !== null) {\n    \$normalizedScriptName = preg_replace('@/events/[^/]+/invite\\\\.php$@', '/invite.php', \$previousScriptName);\n}\nif (!is_string(\$normalizedScriptName) || \$normalizedScriptName === '') {\n    \$normalizedScriptName = '/invite.php';\n}\n\$_SERVER['SCRIPT_NAME'] = \$normalizedScriptName;\n{$baseRequire}\nif (\$previousScriptName === null) {\n    unset(\$_SERVER['SCRIPT_NAME']);\n} else {\n    \$_SERVER['SCRIPT_NAME'] = \$previousScriptName;\n}\n";
    }

    return "<?php\nif (!defined('EVENT_SCOPED_EVENT_CODE')) {\n    define('EVENT_SCOPED_EVENT_CODE', {$exportedCode});\n}\n{$baseRequire}\n";
}
