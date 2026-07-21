<?php
declare(strict_types=1);

require_once __DIR__ . '/activity-logger.php';

/*
 * Event Guest Manager examples only. Pass a PDO instance as the second argument
 * when an event should also be saved to the taskclub_user_activity_logs
 * audit table.
 */

function taskClubActivityLogExamples(?PDO $pdo = null): void
{
    egmActivityLogUserActivity([
        'level' => 'info',
        'user_id' => 'EGM-1001',
        'action' => 'taskclub.user.login',
        'entity_type' => 'taskclub_user',
        'entity_id' => 'EGM-1001',
        'status' => 'success',
        'message' => 'Event Guest Manager user logged in successfully.',
        'metadata' => ['username' => 'EGM-1001'],
        'audit' => true
    ], $pdo);

    egmActivityLogUserActivity([
        'level' => 'warning',
        'action' => 'taskclub.user.login_failed',
        'entity_type' => 'taskclub_user',
        'status' => 'failed',
        'message' => 'Invalid Event Guest Manager username or password.',
        'metadata' => ['username' => 'unknown_user', 'reason' => 'bad_credentials'],
        'audit' => true
    ], $pdo);

    egmActivityLogUserActivity([
        'level' => 'info',
        'user_id' => 'EGM-1001',
        'action' => 'taskclub.product.viewed',
        'entity_type' => 'product',
        'entity_id' => 'REWARD-1001',
        'status' => 'success',
        'metadata' => ['source' => 'taskclub', 'category' => 'reward']
    ], $pdo);

    egmActivityLogUserActivity([
        'level' => 'info',
        'user_id' => 'EGM-1001',
        'action' => 'taskclub.order.created',
        'entity_type' => 'order',
        'entity_id' => 'EGM-ORD-20260527-001',
        'status' => 'success',
        'message' => 'Event Guest Manager order was created.',
        'metadata' => ['total' => 1250000, 'currency' => 'IRR'],
        'audit' => true
    ], $pdo);

    egmActivityLogUserActivity([
        'level' => 'error',
        'user_id' => 'EGM-1001',
        'action' => 'taskclub.payment.failed',
        'entity_type' => 'payment',
        'entity_id' => 'EGM-PAY-20260527-001',
        'status' => 'failed',
        'message' => 'Event Guest Manager payment failed.',
        'metadata' => ['gateway' => 'example_gateway', 'reason' => 'insufficient_funds'],
        'audit' => true
    ], $pdo);

    egmActivityLogUserActivity([
        'level' => 'info',
        'user_id' => 'admin-000001',
        'action' => 'taskclub.admin.user_status_changed',
        'entity_type' => 'taskclub_user',
        'entity_id' => 'EGM-1042',
        'status' => 'success',
        'message' => 'Event Guest Manager admin changed user status.',
        'metadata' => ['previous_active' => true, 'new_active' => false],
        'audit' => true
    ], $pdo);
}
