<?php
declare(strict_types=1);

/**
 * MySQL configuration used by the API (XAMPP defaults below).
 * Override these values by exporting environment variables
 * like DB_HOST, DB_NAME, DB_USER, DB_PASSWORD, DB_TABLE, and DB_RECORD.
 */
return [
    'host' => getenv('DB_HOST') ?: 'localhost',
    'port' => (int)(getenv('DB_PORT') ?: 3306),
    'dbname' => getenv('DB_NAME') ?: 'MCI',
    'user' => getenv('DB_USER') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
    'table' => getenv('DB_TABLE') ?: 'mci_store',
    'record' => getenv('DB_RECORD') ?: 'store',
    'charset' => 'utf8mb4'
];
