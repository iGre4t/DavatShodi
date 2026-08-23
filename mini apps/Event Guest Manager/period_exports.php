<?php
declare(strict_types=1);

// Capture anything emitted by production-only includes so the XLSX response
// can discard it before sending the workbook ZIP.
ob_start();

require_once __DIR__ . '/egm-database-runtime.php';
require_once __DIR__ . '/egm-security.php';
require_once dirname(__DIR__, 2) . '/api/lib/tab-permissions.php';
require_once dirname(__DIR__, 2) . '/api/lib/egm-period-exports.php';

$sessionUser = requireTabPermissionFromSession('event-guest-manager', false);
handleEgmPeriodExportRequest(__DIR__, $sessionUser);
