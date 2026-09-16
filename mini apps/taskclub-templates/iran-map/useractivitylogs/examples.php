<?php
declare(strict_types=1);


require_once dirname(__DIR__) . '/tc-database-runtime.php';
require_once __DIR__ . '/activity-logger.php';

/*
 * Task Club examples only. Pass a PDO instance as the second argument
 * when an event should also be saved to the taskclub_user_activity_logs
 * audit table.
 */

function taskClubActivityLogExamples(?PDO $pdo = null): void
{
    tcActivityLogUserActivity([
        'level' => 'info',
        'user_id' => 'TC-1001',
        'action' => 'taskclub.user.login',
        'entity_type' => 'taskclub_user',
        'entity_id' => 'TC-1001',
        'status' => 'success',
        'message' => 'Task Club user logged in successfully.',
        'metadata' => ['username' => 'TC-1001'],
        'audit' => true
    ], $pdo);

    tcActivityLogUserActivity([
        'level' => 'warning',
        'action' => 'taskclub.user.login_failed',
        'entity_type' => 'taskclub_user',
        'status' => 'failed',
        'message' => 'Invalid Task Club username or password.',
        'metadata' => ['username' => 'unknown_user', 'reason' => 'bad_credentials'],
        'audit' => true
    ], $pdo);

    tcActivityLogUserActivity([
        'level' => 'info',
        'user_id' => 'TC-1001',
        'action' => 'taskclub.product.viewed',
        'entity_type' => 'product',
        'entity_id' => 'REWARD-1001',
        'status' => 'success',
        'metadata' => ['source' => 'taskclub', 'category' => 'reward']
    ], $pdo);

    tcActivityLogUserActivity([
        'level' => 'info',
        'user_id' => 'TC-1001',
        'action' => 'taskclub.order.created',
        'entity_type' => 'order',
        'entity_id' => 'TC-ORD-20260527-001',
        'status' => 'success',
        'message' => 'Task Club order was created.',
        'metadata' => ['total' => 1250000, 'currency' => 'IRR'],
        'audit' => true
    ], $pdo);

    tcActivityLogUserActivity([
        'level' => 'error',
        'user_id' => 'TC-1001',
        'action' => 'taskclub.payment.failed',
        'entity_type' => 'payment',
        'entity_id' => 'TC-PAY-20260527-001',
        'status' => 'failed',
        'message' => 'Task Club payment failed.',
        'metadata' => ['gateway' => 'example_gateway', 'reason' => 'insufficient_funds'],
        'audit' => true
    ], $pdo);

    tcActivityLogUserActivity([
        'level' => 'info',
        'user_id' => 'admin-000001',
        'action' => 'taskclub.admin.user_status_changed',
        'entity_type' => 'taskclub_user',
        'entity_id' => 'TC-1042',
        'status' => 'success',
        'message' => 'Task Club admin changed user status.',
        'metadata' => ['previous_active' => true, 'new_active' => false],
        'audit' => true
    ], $pdo);
}
