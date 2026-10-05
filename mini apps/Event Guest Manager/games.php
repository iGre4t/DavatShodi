<?php
declare(strict_types=1);
require_once __DIR__ . '/egm-database-runtime.php';
require_once __DIR__ . '/egm-security.php';
require_once dirname(__DIR__, 2) . '/api/lib/tab-permissions.php';
require_once dirname(__DIR__, 2) . '/api/lib/egm-games.php';
$user = requireTabPermissionFromSession('event-guest-manager', true);
$canMain = userHasPermissionId($user, 'event-guest-manager:main');
handleEgmGamesRequest(__DIR__, $canMain, $canMain || userHasPermissionId($user, 'event-guest-manager:manage-tasks'), (string)($user['code'] ?? ''));
