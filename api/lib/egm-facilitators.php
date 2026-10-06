<?php
declare(strict_types=1);

require_once __DIR__ . '/egm-instance-storage.php';
require_once __DIR__ . '/tc-password-vault.php';

const EGM_FACILITATORS_KEY = 'refmonitor_facilitators';
const EGM_FACILITATORS_MAX = 1000;

function egmFacilitatorsRead(PDO $pdo, string $eventCode): array
{
    $rows = egmInstanceReadData($pdo, $eventCode, EGM_FACILITATORS_KEY, []);
    if (!is_array($rows)) throw new RuntimeException('اطلاعات تسهیلگرها معتبر نیست.');
    return $rows;
}

function egmFacilitatorsPublic(array $rows): array
{
    return array_map(static fn(array $row): array => [
        'id' => $row['id'], 'username' => $row['username'], 'name' => $row['fullname'],
    ], array_values($rows));
}

function egmFacilitatorValidate(array $input, bool $passwordRequired = true): array
{
    foreach (['username', 'name', 'password'] as $key) {
        if (isset($input[$key]) && !is_string($input[$key])) throw new InvalidArgumentException('اطلاعات تسهیلگر نامعتبر است.');
    }
    $username = trim((string)($input['username'] ?? ''));
    $name = trim((string)($input['name'] ?? ''));
    $password = (string)($input['password'] ?? '');
    if (!preg_match('/^[\p{L}\p{N}._@+\-]{1,80}$/uD', $username)) {
        throw new InvalidArgumentException('نام کاربری باید ۱ تا ۸۰ نویسه و بدون فاصله باشد.');
    }
    if ($name === '' || mb_strlen($name, 'UTF-8') > 100 || !preg_match('//u', $name) || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
        throw new InvalidArgumentException('نام تسهیلگر باید ۱ تا ۱۰۰ نویسه باشد.');
    }
    if (($passwordRequired && $password === '') || ($password !== '' && trim($password) === '') || strlen($password) > 72 || str_contains($password, "\0") || !preg_match('//u', $password)) {
        throw new InvalidArgumentException('رمز عبور الزامی است و باید حداکثر ۷۲ بایت باشد.');
    }
    return ['username' => $username, 'name' => $name, 'password' => $password];
}

function egmFacilitatorUsernameKey(string $username): string
{
    return mb_strtolower(trim($username), 'UTF-8');
}

/** Apply to a copy so validation failures never partially change saved accounts. */
function egmFacilitatorsApplySave(array $rows, array $input, string $id = ''): array
{
    $index = null;
    foreach ($rows as $i => $row) {
        if ($row['id'] === $id) $index = $i;
    }
    if ($id !== '' && $index === null) throw new InvalidArgumentException('تسهیلگر پیدا نشد.');
    $entry = egmFacilitatorValidate($input, $index === null);
    foreach ($rows as $i => $row) {
        if ($i !== $index && egmFacilitatorUsernameKey($row['username']) === egmFacilitatorUsernameKey($entry['username'])) {
            throw new InvalidArgumentException('این نام کاربری قبلاً برای یک تسهیلگر ثبت شده است.');
        }
    }
    if ($index === null && count($rows) >= EGM_FACILITATORS_MAX) throw new InvalidArgumentException('حداکثر ۱۰۰۰ تسهیلگر قابل ثبت است.');
    $row = $index === null ? ['id' => bin2hex(random_bytes(16)), 'created_at' => gmdate('c'), 'auth_version' => bin2hex(random_bytes(16))] : $rows[$index];
    if ($index !== null && $row['username'] !== $entry['username']) $row['auth_version'] = bin2hex(random_bytes(16));
    $row['username'] = $entry['username'];
    $row['fullname'] = $entry['name'];
    if ($entry['password'] !== '') {
        $row['password_hash'] = password_hash($entry['password'], PASSWORD_DEFAULT);
        $row['password_encrypted'] = tcPasswordVaultEncrypt($entry['password']);
        $row['auth_version'] = bin2hex(random_bytes(16));
    }
    $row['updated_at'] = gmdate('c');
    if ($index === null) $rows[] = $row; else $rows[$index] = $row;
    return array_values($rows);
}

function egmFacilitatorsApplyImport(array $rows, array $entries, bool $updateExisting): array
{
    if (!$entries || count($entries) > EGM_FACILITATORS_MAX) throw new InvalidArgumentException('فایل باید بین ۱ تا ۱۰۰۰ ردیف داشته باشد.');
    $seen = [];
    // Validate the full batch before encrypting or replacing any account.
    foreach ($entries as $i => $input) {
        try {
            if (!is_array($input)) throw new InvalidArgumentException('ردیف نامعتبر است.');
            $entry = egmFacilitatorValidate($input);
            $key = egmFacilitatorUsernameKey($entry['username']);
            if (isset($seen[$key])) throw new InvalidArgumentException('نام کاربری در فایل تکراری است.');
            $seen[$key] = true;
            foreach ($rows as $row) {
                if (!$updateExisting && egmFacilitatorUsernameKey($row['username']) === $key) throw new InvalidArgumentException('نام کاربری از قبل موجود است؛ گزینهٔ به‌روزرسانی را انتخاب کنید.');
            }
        } catch (InvalidArgumentException $error) {
            throw new InvalidArgumentException('ردیف ' . ($i + 1) . ': ' . $error->getMessage());
        }
    }
    foreach ($entries as $input) {
        $id = '';
        foreach ($rows as $row) {
            if (egmFacilitatorUsernameKey($row['username']) === egmFacilitatorUsernameKey($input['username'])) $id = $row['id'];
        }
        $rows = egmFacilitatorsApplySave($rows, $input, $id);
    }
    return $rows;
}

function egmFacilitatorsMutate(PDO $pdo, string $eventCode, callable $callback): array
{
    $lockName = 'egm-facilitators:' . substr(hash('sha256', $eventCode), 0, 40);
    $lock = $pdo->prepare('SELECT GET_LOCK(:name, 5)');
    $lock->execute([':name' => $lockName]);
    if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('ذخیره‌سازی در حال انجام است. دوباره امتحان کنید.');
    try {
        $rows = $callback(egmFacilitatorsRead($pdo, $eventCode));
        egmInstanceWriteData($pdo, $eventCode, EGM_FACILITATORS_KEY, $rows);
        return egmFacilitatorsPublic($rows);
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
        $release->execute([':name' => $lockName]);
    }
}

function egmFacilitatorsAuthenticateRows(array $rows, string $username, string $password): ?array
{
    foreach ($rows as $row) {
        if (egmFacilitatorUsernameKey($row['username']) === egmFacilitatorUsernameKey($username)
            && password_verify($password, $row['password_hash'])) return $row;
    }
    return null;
}

function egmFacilitatorsSessionUser(array $rows, string $id, string $version): ?array
{
    foreach ($rows as $row) {
        if ($row['id'] === $id && $version !== '' && hash_equals($row['auth_version'], $version)) {
            return ['code' => 'facilitator:' . $id, 'username' => $row['username'], 'fullname' => $row['fullname']];
        }
    }
    return null;
}
