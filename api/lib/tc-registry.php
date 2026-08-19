<?php
declare(strict_types=1);

const TC_REGISTRY_TABLE = 'TC';
const TC_INSTANCES_DIRECTORY = 'mini apps/missions';
const TC_LEGACY_INSTANCES_DIRECTORY = 'mini apps/missions';

function ensureTcRegistryTable(PDO $pdo): void
{
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `TC` (
  `code` VARCHAR(64) NOT NULL,
  `name` VARCHAR(128) NOT NULL,
  `directory` VARCHAR(512) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`code`),
  UNIQUE KEY `uniq_tc_directory` (`directory`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `TC_sequence` (
  `id` TINYINT UNSIGNED NOT NULL,
  `next_code` VARCHAR(64) NOT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $pdo->exec("INSERT IGNORE INTO `TC_sequence` (`id`, `next_code`) VALUES (1, '0001')");
    migrateLegacyTcRegistryDirectories($pdo);
}

function migrateLegacyTcRegistryDirectories(PDO $pdo): void
{
    $legacyPrefix = TC_LEGACY_INSTANCES_DIRECTORY . '/';
    $canonicalPrefix = TC_INSTANCES_DIRECTORY . '/';
    $statement = $pdo->prepare(
        'UPDATE `TC` SET `directory` = CONCAT(:canonical_prefix, SUBSTRING(`directory`, :legacy_length)) '
        . 'WHERE `directory` LIKE :legacy_pattern'
    );
    $statement->execute([
        ':canonical_prefix' => $canonicalPrefix,
        ':legacy_length' => strlen($legacyPrefix) + 1,
        ':legacy_pattern' => $legacyPrefix . '%',
    ]);
}

function normalizeTcRegistryCode($value): string
{
    $code = trim((string)$value);
    return preg_match('/^[0-9]{4,}$/D', $code) === 1 ? $code : '';
}

function normalizeTcSequenceCode($value): string
{
    $digits = trim((string)$value);
    if ($digits === '' || preg_match('/^[0-9]+$/D', $digits) !== 1) {
        return '';
    }
    $digits = ltrim($digits, '0');
    if ($digits === '') {
        return '';
    }
    return strlen($digits) < 4 ? str_pad($digits, 4, '0', STR_PAD_LEFT) : $digits;
}

function compareTcSequenceCodes(string $left, string $right): int
{
    $leftNumber = ltrim($left, '0') ?: '0';
    $rightNumber = ltrim($right, '0') ?: '0';
    if (strlen($leftNumber) !== strlen($rightNumber)) {
        return strlen($leftNumber) <=> strlen($rightNumber);
    }
    return strcmp($leftNumber, $rightNumber);
}

function incrementTcSequenceCode(string $code): string
{
    $digits = ltrim($code, '0') ?: '0';
    $carry = 1;
    for ($index = strlen($digits) - 1; $index >= 0 && $carry === 1; $index--) {
        $digit = ((int)$digits[$index]) + 1;
        $digits[$index] = (string)($digit % 10);
        $carry = $digit >= 10 ? 1 : 0;
    }
    if ($carry === 1) {
        $digits = '1' . $digits;
    }
    return strlen($digits) < 4 ? str_pad($digits, 4, '0', STR_PAD_LEFT) : $digits;
}

function allocateTcRegistryCode(PDO $pdo): string
{
    ensureTcRegistryTable($pdo);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->query('SELECT `next_code` FROM `TC_sequence` WHERE `id` = 1 FOR UPDATE');
        $nextCode = normalizeTcSequenceCode($stmt ? $stmt->fetchColumn() : '') ?: '0001';
        $records = $pdo->query('SELECT `code` FROM `TC`');
        foreach ($records ? $records->fetchAll(PDO::FETCH_ASSOC) : [] as $record) {
            $existingCode = normalizeTcSequenceCode($record['code'] ?? '');
            if ($existingCode !== '' && compareTcSequenceCodes($existingCode, $nextCode) >= 0) {
                $nextCode = incrementTcSequenceCode($existingCode);
            }
        }
        $update = $pdo->prepare('UPDATE `TC_sequence` SET `next_code` = :next_code, `updated_at` = CURRENT_TIMESTAMP WHERE `id` = 1');
        $update->execute([':next_code' => incrementTcSequenceCode($nextCode)]);
        $pdo->commit();
        return $nextCode;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function normalizeTcRegistryDirectory($value): string
{
    $directory = trim(str_replace('\\', '/', (string)$value), '/');
    if ($directory === 'mini apps/Task Club') {
        return $directory;
    }
    $legacyPrefix = TC_LEGACY_INSTANCES_DIRECTORY . '/';
    if (str_starts_with($directory, $legacyPrefix)) {
        $directory = TC_INSTANCES_DIRECTORY . '/' . substr($directory, strlen($legacyPrefix));
    }
    if (preg_match('#^mini apps/missions/[^/]+$#u', $directory) !== 1) {
        return '';
    }
    return $directory;
}

function listTcRegistry(PDO $pdo): array
{
    ensureTcRegistryTable($pdo);
    $stmt = $pdo->query('SELECT `code`, `name`, `directory`, `created_at`, `updated_at` FROM `TC` ORDER BY LENGTH(`code`), `code`');
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function findTcRegistryByCode(PDO $pdo, string $code): ?array
{
    ensureTcRegistryTable($pdo);
    $stmt = $pdo->prepare('SELECT `code`, `name`, `directory`, `created_at`, `updated_at` FROM `TC` WHERE `code` = :code LIMIT 1');
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function findTcRegistryByDirectory(PDO $pdo, string $directory): ?array
{
    ensureTcRegistryTable($pdo);
    $directory = normalizeTcRegistryDirectory($directory);
    if ($directory === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT `code`, `name`, `directory`, `created_at`, `updated_at` FROM `TC` WHERE `directory` = :directory LIMIT 1');
    $stmt->execute([':directory' => $directory]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function insertTcRegistry(PDO $pdo, string $code, string $name, string $directory): void
{
    $code = normalizeTcRegistryCode($code);
    $name = trim($name);
    $directory = normalizeTcRegistryDirectory($directory);
    if ($code === '' || $name === '' || $directory === '') {
        throw new InvalidArgumentException('Invalid TC registry record.');
    }
    ensureTcRegistryTable($pdo);
    $stmt = $pdo->prepare('INSERT INTO `TC` (`code`, `name`, `directory`) VALUES (:code, :name, :directory)');
    $stmt->execute([':code' => $code, ':name' => $name, ':directory' => $directory]);
}

function upsertTcRegistry(PDO $pdo, string $code, string $name, string $directory): void
{
    $code = normalizeTcRegistryCode($code);
    $name = trim($name);
    $directory = normalizeTcRegistryDirectory($directory);
    if ($code === '' || $name === '' || $directory === '') {
        throw new InvalidArgumentException('Invalid TC registry record.');
    }
    ensureTcRegistryTable($pdo);
    $stmt = $pdo->prepare(<<<SQL
INSERT INTO `TC` (`code`, `name`, `directory`)
VALUES (:code, :name, :directory)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `directory` = VALUES(`directory`), `updated_at` = CURRENT_TIMESTAMP
SQL);
    $stmt->execute([':code' => $code, ':name' => $name, ':directory' => $directory]);
}

function updateTcRegistry(PDO $pdo, string $code, string $name, string $directory): bool
{
    $code = normalizeTcRegistryCode($code);
    $name = trim($name);
    $directory = normalizeTcRegistryDirectory($directory);
    if ($code === '' || $name === '' || $directory === '') {
        return false;
    }
    ensureTcRegistryTable($pdo);
    $stmt = $pdo->prepare('UPDATE `TC` SET `name` = :name, `directory` = :directory, `updated_at` = CURRENT_TIMESTAMP WHERE `code` = :code');
    $stmt->execute([':code' => $code, ':name' => $name, ':directory' => $directory]);
    return $stmt->rowCount() > 0 || findTcRegistryByCode($pdo, $code) !== null;
}

function deleteTcRegistry(PDO $pdo, string $code): bool
{
    ensureTcRegistryTable($pdo);
    $stmt = $pdo->prepare('DELETE FROM `TC` WHERE `code` = :code');
    $stmt->execute([':code' => $code]);
    return $stmt->rowCount() > 0;
}
