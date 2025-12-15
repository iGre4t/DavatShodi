<?php
declare(strict_types=1);

/**
 * Loads rows from the `users` table, normalizes the values, and exposes `username` so the frontend always shows the proper login name.
 */
function loadUsersFromUsersTable(PDO $pdo): array
{
    try {
        $stmt = $pdo->query('SELECT `code`, `username`, `fullname`, `phone`, `work_id`, `id_number`, `email` FROM `users` ORDER BY `fullname` ASC, `code` ASC');
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
        $stmt = $pdo->prepare('SELECT `code`, `username`, `fullname`, `phone`, `email`, `id_number`, `work_id`, `password_hash` FROM `users` WHERE `code` = :code LIMIT 1');
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
    $passwordHash = trim((string)($user['password_hash'] ?? '')) ?: getDefaultUserPasswordHash();
    try {
        $stmt = $pdo->prepare('
            INSERT INTO `users` (
                `code`,
                `username`,
                `fullname`,
                `phone`,
                `email`,
                `id_number`,
                `work_id`,
                `password_hash`
            ) VALUES (
                :code,
                :username,
                :fullname,
                :phone,
                :email,
                :id_number,
                :work_id,
                :password_hash
            )
        ');
        return $stmt->execute([
            ':code' => $code,
            ':username' => $username,
            ':fullname' => $fullname,
            ':phone' => $phone,
            ':email' => $email,
            ':id_number' => $idNumber,
            ':work_id' => $workId,
            ':password_hash' => $passwordHash
        ]);
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
