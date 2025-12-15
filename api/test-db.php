<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

$config = include __DIR__ . '/config.php';
$result = [
    'status' => 'error',
    'message' => 'MySQL configuration is missing.',
    'connection' => false
];

try {
    $pdo = connect($config);
    if ($pdo) {
        $result = [
            'status' => 'ok',
            'message' => 'MySQL connection successful.',
            'connection' => true,
            'database' => $config['dbname'] ?? null,
            'table' => $config['table'] ?? null
        ];
    } else {
        $result['message'] = 'Missing host/dbname in configuration.';
    }
} catch (PDOException $err) {
    $result = [
        'status' => 'error',
        'message' => 'MySQL connection failed: ' . $err->getMessage(),
        'connection' => false
    ];
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

function connect(array $config): ?PDO
{
    $host = $config['host'] ?? '';
    $dbname = $config['dbname'] ?? '';
    if ($host === '' || $dbname === '') {
        return null;
    }
    $port = (int)($config['port'] ?? 3306);
    $charset = $config['charset'] ?? 'utf8mb4';
    $user = $config['user'] ?? '';
    $password = $config['password'] ?? '';

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $dbname, $charset);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ];
    return new PDO($dsn, $user, $password, $options);
}
