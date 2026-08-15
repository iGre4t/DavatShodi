<?php
declare(strict_types=1);

const EGM_REGISTRY_TABLE = 'EGM';

function ensureEgmRegistryTable(PDO $pdo): void
{
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `EGM` (
  `code` VARCHAR(64) NOT NULL,
  `name` VARCHAR(128) NOT NULL,
  `directory` VARCHAR(512) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`code`),
  UNIQUE KEY `uniq_egm_directory` (`directory`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `EGM_sequence` (
  `id` TINYINT UNSIGNED NOT NULL,
  `next_code` VARCHAR(64) NOT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $pdo->exec("INSERT IGNORE INTO `EGM_sequence` (`id`, `next_code`) VALUES (1, '0001')");
}

function normalizeEgmRegistryCode($value): string
{
    $code = trim((string)$value);
    return preg_match('/^[0-9]{4,}$/D', $code) === 1 ? $code : '';
}

function normalizeEgmSequenceCode($value): string
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

function compareEgmSequenceCodes(string $left, string $right): int
{
    $leftNumber = ltrim($left, '0') ?: '0';
    $rightNumber = ltrim($right, '0') ?: '0';
    if (strlen($leftNumber) !== strlen($rightNumber)) {
        return strlen($leftNumber) <=> strlen($rightNumber);
    }
    return strcmp($leftNumber, $rightNumber);
}

function incrementEgmSequenceCode(string $code): string
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

function allocateEgmRegistryCode(PDO $pdo): string
{
    ensureEgmRegistryTable($pdo);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->query('SELECT `next_code` FROM `EGM_sequence` WHERE `id` = 1 FOR UPDATE');
        $nextCode = normalizeEgmSequenceCode($stmt ? $stmt->fetchColumn() : '') ?: '0001';
        $records = $pdo->query('SELECT `code` FROM `EGM`');
        foreach ($records ? $records->fetchAll(PDO::FETCH_ASSOC) : [] as $record) {
            $existingCode = normalizeEgmSequenceCode($record['code'] ?? '');
            if ($existingCode !== '' && compareEgmSequenceCodes($existingCode, $nextCode) >= 0) {
                $nextCode = incrementEgmSequenceCode($existingCode);
            }
        }
        $update = $pdo->prepare('UPDATE `EGM_sequence` SET `next_code` = :next_code, `updated_at` = CURRENT_TIMESTAMP WHERE `id` = 1');
        $update->execute([':next_code' => incrementEgmSequenceCode($nextCode)]);
        $pdo->commit();
        return $nextCode;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function normalizeEgmRegistryDirectory($value): string
{
    $directory = trim(str_replace('\\', '/', (string)$value), '/');
    if (preg_match('#^miniapps/EGMs/[^/]+$#u', $directory) !== 1) {
        return '';
    }
    return $directory;
}

function listEgmRegistry(PDO $pdo): array
{
    ensureEgmRegistryTable($pdo);
    $stmt = $pdo->query('SELECT `code`, `name`, `directory`, `created_at`, `updated_at` FROM `EGM` ORDER BY LENGTH(`code`), `code`');
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function findEgmRegistryByCode(PDO $pdo, string $code): ?array
{
    ensureEgmRegistryTable($pdo);
    $stmt = $pdo->prepare('SELECT `code`, `name`, `directory`, `created_at`, `updated_at` FROM `EGM` WHERE `code` = :code LIMIT 1');
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function findEgmRegistryByDirectory(PDO $pdo, string $directory): ?array
{
    ensureEgmRegistryTable($pdo);
    $stmt = $pdo->prepare('SELECT `code`, `name`, `directory`, `created_at`, `updated_at` FROM `EGM` WHERE `directory` = :directory LIMIT 1');
    $stmt->execute([':directory' => $directory]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function insertEgmRegistry(PDO $pdo, string $code, string $name, string $directory): void
{
    $code = normalizeEgmRegistryCode($code);
    $name = trim($name);
    $directory = normalizeEgmRegistryDirectory($directory);
    if ($code === '' || $name === '' || $directory === '') {
        throw new InvalidArgumentException('Invalid EGM registry record.');
    }
    ensureEgmRegistryTable($pdo);
    $stmt = $pdo->prepare('INSERT INTO `EGM` (`code`, `name`, `directory`) VALUES (:code, :name, :directory)');
    $stmt->execute([':code' => $code, ':name' => $name, ':directory' => $directory]);
}

function upsertEgmRegistry(PDO $pdo, string $code, string $name, string $directory): void
{
    $code = normalizeEgmRegistryCode($code);
    $name = trim($name);
    $directory = normalizeEgmRegistryDirectory($directory);
    if ($code === '' || $name === '' || $directory === '') {
        throw new InvalidArgumentException('Invalid EGM registry record.');
    }
    ensureEgmRegistryTable($pdo);
    $stmt = $pdo->prepare(<<<SQL
INSERT INTO `EGM` (`code`, `name`, `directory`)
VALUES (:code, :name, :directory)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `directory` = VALUES(`directory`), `updated_at` = CURRENT_TIMESTAMP
SQL);
    $stmt->execute([':code' => $code, ':name' => $name, ':directory' => $directory]);
}

function updateEgmRegistry(PDO $pdo, string $code, string $name, string $directory): bool
{
    $code = normalizeEgmRegistryCode($code);
    $name = trim($name);
    $directory = normalizeEgmRegistryDirectory($directory);
    if ($code === '' || $name === '' || $directory === '') {
        return false;
    }
    ensureEgmRegistryTable($pdo);
    $stmt = $pdo->prepare('UPDATE `EGM` SET `name` = :name, `directory` = :directory, `updated_at` = CURRENT_TIMESTAMP WHERE `code` = :code');
    $stmt->execute([':code' => $code, ':name' => $name, ':directory' => $directory]);
    return $stmt->rowCount() > 0 || findEgmRegistryByCode($pdo, $code) !== null;
}

function deleteEgmRegistry(PDO $pdo, string $code): bool
{
    ensureEgmRegistryTable($pdo);
    $stmt = $pdo->prepare('DELETE FROM `EGM` WHERE `code` = :code');
    $stmt->execute([':code' => $code]);
    return $stmt->rowCount() > 0;
}
