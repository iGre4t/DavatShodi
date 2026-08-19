<?php
declare(strict_types=1);


require_once __DIR__ . '/egm-database-runtime.php';
define('EGM_SKIP_DATABASE_MIRROR', true);
require_once __DIR__ . '/egm-security.php';
require_once dirname(__DIR__, 2) . '/api/lib/tab-permissions.php';
require_once dirname(__DIR__, 2) . '/api/lib/egm-period-invite-cards.php';

$sessionUser = requireTabPermissionFromSession('event-guest-manager', true);
$canManage = userHasPermissionId($sessionUser, 'event-guest-manager:manage-tasks')
    || userHasPermissionId($sessionUser, 'event-guest-manager:invitees');
if (!$canManage) {
    denyPanelAccess(403, 'You do not have permission to generate period invite cards.', true);
}

handleEgmPeriodInviteCardsRequest(__DIR__);
