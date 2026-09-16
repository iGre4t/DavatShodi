<?php
declare(strict_types=1);
require_once __DIR__ . '/egm-database-runtime.php';
require_once __DIR__ . '/egm-security.php';
require_once dirname(__DIR__, 2) . '/api/lib/tab-permissions.php';
require_once dirname(__DIR__, 2) . '/api/lib/egm-period-invites.php';
$user = requireTabPermissionFromSession('event-guest-manager', true);
$canManage = userHasPermissionId($user, 'event-guest-manager:main');
if (!$canManage && !userHasPermissionId($user, 'event-guest-manager:invitees')) denyPanelAccess(403, 'You cannot access EGM groups.', true);
handleEgmGroupsRequest(__DIR__, $canManage);
