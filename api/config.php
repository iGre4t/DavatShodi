<?php
declare(strict_types=1);

/**
 * Local MySQL configuration used by the API.
 * Override the defaults below by exporting environment variables
 * like DB_HOST, DB_NAME, DB_USER, DB_PASSWORD, DB_TABLE, and DB_RECORD.
 */
return [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => (int)(getenv('DB_PORT') ?: 3306),
    'dbname' => getenv('DB_NAME') ?: 'great_panel',
    'user' => getenv('DB_USER') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
    'table' => getenv('DB_TABLE') ?: 'great_panel_store',
    'record' => getenv('DB_RECORD') ?: 'store',
    'charset' => 'utf8mb4'
];
