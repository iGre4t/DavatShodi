<?php
declare(strict_types=1);

require_once __DIR__ . '/egm-database-runtime.php';
$projectRoot = dirname(__DIR__, 3);
require_once __DIR__ . '/egm-security.php';
require_once $projectRoot . '/api/lib/tab-permissions.php';
require_once $projectRoot . '/api/lib/egm-invite-card-store.php';

$user = requireTabPermissionFromSession('event-guest-manager', true);
handleEgmInviteCardStore($projectRoot, __DIR__, $user, 'print_card', 'print-card');
