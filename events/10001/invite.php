<?php
if (!defined('EVENT_SCOPED_EVENT_CODE')) {
    define('EVENT_SCOPED_EVENT_CODE', '10001');
}
$previousScriptName = $_SERVER['SCRIPT_NAME'] ?? null;
$normalizedScriptName = null;
if ($previousScriptName !== null) {
    $normalizedScriptName = preg_replace('@/events/[^/]+/invite\\.php$@', '/invite.php', $previousScriptName);
}
if (!is_string($normalizedScriptName) || $normalizedScriptName === '') {
    $normalizedScriptName = '/invite.php';
}
$_SERVER['SCRIPT_NAME'] = $normalizedScriptName;
require dirname(dirname(__DIR__)) . '/invite.php';
if ($previousScriptName === null) {
    unset($_SERVER['SCRIPT_NAME']);
} else {
    $_SERVER['SCRIPT_NAME'] = $previousScriptName;
}
