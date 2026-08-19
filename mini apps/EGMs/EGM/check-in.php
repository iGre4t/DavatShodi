<?php
declare(strict_types=1);


require_once __DIR__ . '/egm-database-runtime.php';
require_once __DIR__ . '/egm-security.php';
require_once __DIR__ . '/../../../api/lib/tab-permissions.php';
require_once __DIR__ . '/../../../api/lib/egm-check-in.php';

$isJsonRequest = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
$sessionUser = requireTabPermissionFromSession('event-guest-manager', $isJsonRequest);
handleEgmCheckInPage(dirname(__DIR__, 3), __DIR__, $sessionUser);
