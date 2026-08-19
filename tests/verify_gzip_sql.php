<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
$file = (string)($argv[1] ?? '');
if (!is_file($file)) throw new RuntimeException('SQL gzip file was not found.');
$gzip = gzopen($file, 'rb');
if ($gzip === false) throw new RuntimeException('Unable to open the gzip stream.');
$bytes = 0;
$hash = hash_init('sha256');
$tail = '';
$tables = [];
while (!gzeof($gzip)) {
    $chunk = gzread($gzip, 1024 * 1024);
    if ($chunk === false) throw new RuntimeException('Corrupt gzip stream.');
    $bytes += strlen($chunk);
    hash_update($hash, $chunk);
    $scan = $tail . $chunk;
    if (preg_match_all('/CREATE TABLE `([^`]+)`/i', $scan, $matches)) {
        foreach ($matches[1] as $table) $tables[(string)$table] = true;
    }
    $tail = substr($scan, -256);
}
gzclose($gzip);
echo json_encode([
    'file' => realpath($file) ?: $file,
    'compressed_bytes' => filesize($file),
    'uncompressed_bytes' => $bytes,
    'compressed_sha256' => hash_file('sha256', $file),
    'sql_sha256' => hash_final($hash),
    'tables' => array_keys($tables),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
