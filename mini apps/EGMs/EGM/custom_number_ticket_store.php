<?php
declare(strict_types=1);

require_once __DIR__ . '/egm-database-runtime.php';
$projectRoot = dirname(__DIR__, 3);
require_once __DIR__ . '/egm-security.php';
require_once $projectRoot . '/api/lib/tab-permissions.php';
require_once $projectRoot . '/api/lib/egm-invite-card-store.php';

$user = requireTabPermissionFromSession('event-guest-manager', true);
$ticketId = strtolower(trim((string)($_GET['ticket_id'] ?? 'default')));
$ticketId = preg_replace('/[^a-z0-9_-]+/', '-', $ticketId) ?? 'default';
$ticketId = trim(substr($ticketId, 0, 24), '-_');
if ($ticketId === '' || $ticketId === 'default') {
    handleEgmInviteCardStore($projectRoot, __DIR__, $user, 'custom_number_ticket', 'custom-number-ticket');
}
handleEgmInviteCardStore($projectRoot, __DIR__, $user, 'custom_number_ticket:' . $ticketId, 'cnt-' . $ticketId);
