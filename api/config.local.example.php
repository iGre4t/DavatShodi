<?php
declare(strict_types=1);

/**
 * Copy this file to config.local.php on the deployed server.
 * config.local.php is ignored by Git and overrides api/config.php.
 */
return [
    'host' => 'localhost',
    'port' => 3306,
    'dbname' => 'CPANEL_MAIN_DATABASE',
    'user' => 'CPANEL_DATABASE_USER',
    'password' => 'CPANEL_DATABASE_PASSWORD',
    'logs_host' => 'localhost',
    'logs_port' => 3306,
    'logs_dbname' => 'CPANEL_LOGS_DATABASE',
    'logs_user' => 'CPANEL_DATABASE_USER',
    'logs_password' => 'CPANEL_DATABASE_PASSWORD',
    'table' => 'mci_store',
    'record' => 'store',
    'charset' => 'utf8mb4',
];
