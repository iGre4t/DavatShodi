<?php
declare(strict_types=1);


require_once __DIR__ . '/egm-database-runtime.php';
require_once __DIR__ . '/egm-security.php';
require_once dirname(__DIR__, 3) . '/api/lib/tab-permissions.php';
require_once dirname(__DIR__, 3) . '/api/lib/egm-period-exports.php';

$sessionUser = requireTabPermissionFromSession('event-guest-manager', false);
handleEgmPeriodExportRequest(__DIR__, $sessionUser);
