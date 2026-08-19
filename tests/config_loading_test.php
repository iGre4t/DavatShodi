<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/common.php';

function configLoadingAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'davatshodi-config-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Unable to create the configuration test directory.');
}
$basePath = $directory . DIRECTORY_SEPARATOR . 'config.php';
$localPath = $directory . DIRECTORY_SEPARATOR . 'config.local.php';
$priorEnvironment = getenv('DB_NAME');

try {
    file_put_contents($basePath, "<?php return ['host'=>'base-host','dbname'=>'base-db','user'=>'base-user','password'=>'base-password'];\n");
    file_put_contents($localPath, "<?php return ['host'=>'local-host','dbname'=>'local-db','user'=>'local-user','password'=>'local-password'];\n");
    putenv('DB_NAME=environment_db');
    $config = loadConfig($basePath);
    configLoadingAssert($config['host'] === 'local-host', 'The ignored local configuration did not override tracked defaults.');
    configLoadingAssert($config['user'] === 'local-user', 'The local database user was not loaded.');
    configLoadingAssert($config['password'] === 'local-password', 'The local database password was not loaded.');
    configLoadingAssert($config['dbname'] === 'environment_db', 'The server environment did not take final precedence.');
    configLoadingAssert(getLocalDatabaseConfigPath($basePath) === $localPath, 'The local configuration path is incorrect.');
} finally {
    if ($priorEnvironment === false) putenv('DB_NAME');
    else putenv('DB_NAME=' . $priorEnvironment);
    if (is_file($localPath)) unlink($localPath);
    if (is_file($basePath)) unlink($basePath);
    if (is_dir($directory)) rmdir($directory);
}

echo "Configuration loading test passed.\n";
