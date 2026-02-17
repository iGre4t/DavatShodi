<?php
declare(strict_types=1);

require_once __DIR__ . '/tab-permissions.php';

/**
 * Loads rows from the `users` table, normalizes the values, and exposes `username` so the frontend always shows the proper login name.
 */
function getUsersTableColumns(PDO $pdo, bool $refresh = false): array
{
    static $cache = [];
    $cacheKey = spl_object_id($pdo);
    if ($refresh) {
        unset($cache[$cacheKey]);
    }
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM `users`');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $err) {
        error_log('Failed to load users table columns: ' . $err->getMessage());
        $cache[$cacheKey] = [];
        return [];
    }
    $columns = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string)($row['Field'] ?? ''));
        if ($name !== '') {
            $columns[] = $name;
        }
    }
    $cache[$cacheKey] = $columns;
    return $columns;
}

function usersTableHasColumn(PDO $pdo, string $column): bool
{
    return in_array($column, getUsersTableColumns($pdo), true);
}

function ensureUsersExtendedColumns(PDO $pdo): void
{
    $alterParts = [];
    if (!usersTableHasColumn($pdo, 'telegram_id')) {
        $alterParts[] = "ADD COLUMN `telegram_id` BIGINT UNSIGNED NULL COMMENT 'Telegram user id, numerals only'";
    }
    if (!usersTableHasColumn($pdo, 'pin_code')) {
        $alterParts[] = "ADD COLUMN `pin_code` CHAR(4) NULL COMMENT '4-digit numeric PIN'";
    }
    if (!usersTableHasColumn($pdo, 'permissions')) {
        $alterParts[] = "ADD COLUMN `permissions` TEXT NULL COMMENT 'JSON array of allowed panel tab ids'";
    }
    if (!$alterParts) {
        return;
    }
    try {
        $sql = 'ALTER TABLE `users` ' . implode(', ', $alterParts);
        $pdo->exec($sql);
        getUsersTableColumns($pdo, true);
    } catch (PDOException $err) {
        error_log('Failed to ensure users extended columns: ' . $err->getMessage());
    }
}

function loadUsersFromUsersTable(PDO $pdo): array
{
    try {
        $selectColumns = ['`code`', '`username`', '`fullname`', '`phone`', '`work_id`', '`id_number`', '`email`'];
        if (usersTableHasColumn($pdo, 'telegram_id')) {
            $selectColumns[] = '`telegram_id`';
        }
        if (usersTableHasColumn($pdo, 'pin_code')) {
            $selectColumns[] = '`pin_code`';
        }
        if (usersTableHasColumn($pdo, 'permissions')) {
            $selectColumns[] = '`permissions`';
        }
        $sql = 'SELECT ' . implode(', ', $selectColumns) . ' FROM `users` ORDER BY `fullname` ASC, `code` ASC';
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $err) {
        error_log('Failed to fetch users table: ' . $err->getMessage());
        return [];
    }

    if (!is_array($rows)) {
        return [];
    }

    return array_values(array_map(function ($row) {
        $code = trim((string)($row['code'] ?? ''));
        $username = trim((string)($row['username'] ?? ''));
        if ($username === '' || $username === '0') {
            $username = $code;
        }
        $fullname = trim((string)($row['fullname'] ?? ''));
        $displayName = ($fullname !== '' && $fullname !== '0') ? $fullname : ($username ?: $code);
        if ($displayName === '') {
            $displayName = 'User';
        }
        return [
            'code' => $code,
            'username' => $username,
            'fullname' => $fullname,
            'name' => $displayName,
            'phone' => trim((string)($row['phone'] ?? '')),
            'work_id' => trim((string)($row['work_id'] ?? '')),
            'id_number' => trim((string)($row['id_number'] ?? '')),
            'email' => trim((string)($row['email'] ?? '')),
            'telegram_id' => trim((string)($row['telegram_id'] ?? '')),
            'pin_code' => trim((string)($row['pin_code'] ?? '')),
            'permissions' => normalizeTabPermissions($row['permissions'] ?? null, true),
            'active' => true
        ];
    }, $rows));
}

function normalizeUserTableEmail(string $value): string
{
    $normalized = trim($value);
    return $normalized === '' ? '' : mb_strtolower($normalized);
}

function normalizeUserTableIdNumber(string $value): string
{
    return preg_replace('/\D+/', '', trim($value)) ?? '';
}

function isEmailTakenInTable(PDO $pdo, string $email, string $excludeCode = ''): bool
{
    $normalizedEmail = normalizeUserTableEmail($email);
    if ($normalizedEmail === '') {
        return false;
    }
    $sql = 'SELECT COUNT(*) FROM `users` WHERE LOWER(`email`) = :email';
    if ($excludeCode !== '') {
        $sql .= ' AND `code` != :code';
    }
    try {
        $stmt = $pdo->prepare($sql);
        $params = [':email' => $normalizedEmail];
        if ($excludeCode !== '') {
            $params[':code'] = $excludeCode;
        }
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    } catch (PDOException $err) {
        error_log('Failed to check email uniqueness: ' . $err->getMessage());
        return true;
    }
}

function isIdNumberTakenInTable(PDO $pdo, string $idNumber, string $excludeCode = ''): bool
{
    $normalizedId = normalizeUserTableIdNumber($idNumber);
    if ($normalizedId === '') {
        return false;
    }
    $sql = 'SELECT COUNT(*) FROM `users` WHERE `id_number` = :id_number';
    if ($excludeCode !== '') {
        $sql .= ' AND `code` != :code';
    }
    try {
        $stmt = $pdo->prepare($sql);
        $params = [':id_number' => $normalizedId];
        if ($excludeCode !== '') {
            $params[':code'] = $excludeCode;
        }
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    } catch (PDOException $err) {
        error_log('Failed to check ID number uniqueness: ' . $err->getMessage());
        return true;
    }
}

function loadUserByCode(PDO $pdo, string $code): ?array
{
    $code = trim($code);
    if ($code === '') {
        return null;
    }
    try {
        $selectColumns = ['`code`', '`username`', '`fullname`', '`phone`', '`email`', '`id_number`', '`work_id`'];
        if (usersTableHasColumn($pdo, 'telegram_id')) {
            $selectColumns[] = '`telegram_id`';
        }
        if (usersTableHasColumn($pdo, 'pin_code')) {
            $selectColumns[] = '`pin_code`';
        }
        if (usersTableHasColumn($pdo, 'permissions')) {
            $selectColumns[] = '`permissions`';
        }
        $selectColumns[] = '`password_hash`';
        $sql = 'SELECT ' . implode(', ', $selectColumns) . ' FROM `users` WHERE `code` = :code LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $err) {
        error_log('Failed to load user by code: ' . $err->getMessage());
        return null;
    }
    return is_array($row) ? $row : null;
}

function isUsernameTaken(PDO $pdo, string $username, string $excludeCode = ''): bool
{
    $username = trim($username);
    if ($username === '') {
        return true;
    }
    $sql = 'SELECT COUNT(*) FROM `users` WHERE `username` = :username';
    if ($excludeCode !== '') {
        $sql .= ' AND `code` != :code';
    }
    try {
        $stmt = $pdo->prepare($sql);
        $params = [':username' => $username];
        if ($excludeCode !== '') {
            $params[':code'] = $excludeCode;
        }
        $stmt->execute($params);
        $count = (int)$stmt->fetchColumn();
    } catch (PDOException $err) {
        error_log('Failed to check username availability: ' . $err->getMessage());
        return true;
    }
    return $count > 0;
}

function updateUserByCode(PDO $pdo, string $code, array $fields): bool
{
    $code = trim($code);
    if ($code === '') {
        return false;
    }
    $allowed = ['fullname', 'username', 'phone', 'email', 'password_hash', 'id_number', 'work_id'];
    if (usersTableHasColumn($pdo, 'telegram_id')) {
        $allowed[] = 'telegram_id';
    }
    if (usersTableHasColumn($pdo, 'pin_code')) {
        $allowed[] = 'pin_code';
    }
    if (usersTableHasColumn($pdo, 'permissions')) {
        $allowed[] = 'permissions';
    }
    $updates = [];
    $params = [];
    foreach ($fields as $key => $value) {
        if (!in_array($key, $allowed, true)) {
            continue;
        }
        $updates[] = "`$key` = :$key";
        $params[":$key"] = $value;
    }
    if (!$updates) {
        return false;
    }
    $params[':code'] = $code;
    $setClause = implode(', ', $updates);
    try {
        $stmt = $pdo->prepare("UPDATE `users` SET $setClause WHERE `code` = :code");
        return $stmt->execute($params);
    } catch (PDOException $err) {
        error_log('Failed to update user: ' . $err->getMessage());
        return false;
    }
}

function updateUserRecord(PDO $pdo, string $code, string $fullname, string $phone, string $workId, string $idNumber): bool
{
    if ($code === '') {
        return false;
    }
    $stmt = $pdo->prepare('
        UPDATE `users`
        SET `fullname` = :fullname,
            `phone` = :phone,
            `work_id` = :work_id,
            `id_number` = :id_number
        WHERE `code` = :code
    ');
    return $stmt->execute([
        ':fullname' => $fullname,
        ':phone' => $phone,
        ':work_id' => $workId,
        ':id_number' => $idNumber,
        ':code' => $code
    ]);
}

function getDefaultUserPasswordHash(): string
{
    static $hash = '';
    if ($hash !== '') {
        return $hash;
    }
    $seed = '';
    try {
        $seed = bin2hex(random_bytes(16));
    } catch (Throwable $err) {
        error_log('Failed to generate user password seed: ' . $err->getMessage());
        $seed = uniqid('user-', true);
    }
    $hashCandidate = password_hash($seed, PASSWORD_DEFAULT);
    if ($hashCandidate === false) {
        $hashCandidate = password_hash(uniqid('fallback-', true), PASSWORD_DEFAULT);
    }
    $hash = $hashCandidate ?: '';
    return $hash;
}

function insertUserRecord(PDO $pdo, array $user): bool
{
    $code = trim((string)($user['code'] ?? ''));
    $username = trim((string)($user['username'] ?? ''));
    $fullname = trim((string)($user['name'] ?? ''));
    $phone = trim((string)($user['phone'] ?? ''));
    if ($code === '' || $username === '' || $fullname === '' || $phone === '') {
        return false;
    }
    $email = trim((string)($user['email'] ?? ''));
    $idNumber = trim((string)($user['id_number'] ?? ''));
    $workId = trim((string)($user['work_id'] ?? ''));
    $telegramId = trim((string)($user['telegram_id'] ?? ''));
    $pinCode = trim((string)($user['pin_code'] ?? ''));
    $permissions = normalizeTabPermissions($user['permissions'] ?? null, true);
    $permissionsJson = encodeTabPermissionsForStorage($permissions);
    $passwordHash = trim((string)($user['password_hash'] ?? '')) ?: getDefaultUserPasswordHash();
    try {
        $columns = [
            '`code`' => ':code',
            '`username`' => ':username',
            '`fullname`' => ':fullname',
            '`phone`' => ':phone',
            '`email`' => ':email',
            '`id_number`' => ':id_number',
            '`work_id`' => ':work_id',
            '`password_hash`' => ':password_hash'
        ];
        if (usersTableHasColumn($pdo, 'telegram_id')) {
            $columns['`telegram_id`'] = ':telegram_id';
        }
        if (usersTableHasColumn($pdo, 'pin_code')) {
            $columns['`pin_code`'] = ':pin_code';
        }
        if (usersTableHasColumn($pdo, 'permissions')) {
            $columns['`permissions`'] = ':permissions';
        }
        $sql = 'INSERT INTO `users` (' . implode(', ', array_keys($columns)) . ') VALUES (' . implode(', ', array_values($columns)) . ')';
        $params = [
            ':code' => $code,
            ':username' => $username,
            ':fullname' => $fullname,
            ':phone' => $phone,
            ':email' => $email,
            ':id_number' => $idNumber,
            ':work_id' => $workId,
            ':password_hash' => $passwordHash
        ];
        if (usersTableHasColumn($pdo, 'telegram_id')) {
            $params[':telegram_id'] = ($telegramId === '' ? null : $telegramId);
        }
        if (usersTableHasColumn($pdo, 'pin_code')) {
            $params[':pin_code'] = ($pinCode === '' ? null : $pinCode);
        }
        if (usersTableHasColumn($pdo, 'permissions')) {
            $params[':permissions'] = $permissionsJson;
        }
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    } catch (PDOException $err) {
        error_log('Failed to insert user record: ' . $err->getMessage());
        return false;
    }
}

function isPhoneTakenInTable(PDO $pdo, string $phone, string $excludeCode = ''): bool
{
    $phone = trim($phone);
    if ($phone === '') {
        return false;
    }
    $sql = 'SELECT COUNT(*) FROM `users` WHERE `phone` = :phone';
    if ($excludeCode !== '') {
        $sql .= ' AND `code` != :code';
    }
    try {
        $stmt = $pdo->prepare($sql);
        $params = [':phone' => $phone];
        if ($excludeCode !== '') {
            $params[':code'] = $excludeCode;
        }
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    } catch (PDOException $err) {
        error_log('Failed to check phone uniqueness: ' . $err->getMessage());
        return true;
    }
}

function deleteUserByCode(PDO $pdo, string $code): bool
{
    $code = trim($code);
    if ($code === '') {
        return false;
    }
    try {
        $stmt = $pdo->prepare('DELETE FROM `users` WHERE `code` = :code');
        return $stmt->execute([':code' => $code]);
    } catch (PDOException $err) {
        error_log('Failed to delete user record: ' . $err->getMessage());
        return false;
    }
}
