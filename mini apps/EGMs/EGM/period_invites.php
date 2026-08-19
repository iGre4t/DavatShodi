<?php
declare(strict_types=1);


require_once __DIR__ . '/egm-database-runtime.php';
require_once __DIR__ . '/egm-security.php';
require_once dirname(__DIR__, 3) . '/api/lib/tab-permissions.php';
require_once dirname(__DIR__, 3) . '/api/lib/egm-period-invites.php';

$sessionUser = requireTabPermissionFromSession('event-guest-manager', true);
$canManage = userHasPermissionId($sessionUser, 'event-guest-manager:manage-tasks')
    || userHasPermissionId($sessionUser, 'event-guest-manager:invitees');
if (!$canManage) {
    denyPanelAccess(403, 'You do not have permission to manage period invitations.', true);
}

handleEgmPeriodInvitesRequest(__DIR__, $sessionUser);
