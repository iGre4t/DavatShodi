<?php
declare(strict_types=1);

/**
 * Local XAMPP MySQL configuration.
 */
return [
    'host' => 'localhost',
    'port' => 3306,
    'dbname' => 'MCI',
    'user' => 'root',
    'password' => '',
    // Activity/audit history is intentionally kept outside the core database.
    // On cPanel, assign the configured log user to this second database too.
    'logs_host' => 'localhost',
    'logs_port' => 3306,
    'logs_dbname' => 'MCI_logs',
    'logs_user' => 'root',
    'logs_password' => '',
    'table' => 'mci_store',
    'record' => 'store',
    'charset' => 'utf8mb4'
];
