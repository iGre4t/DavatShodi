<?php
declare(strict_types=1);

function tcPasswordVaultKey(): string
{
    static $cachedKey = null;
    if (is_string($cachedKey)) return $cachedKey;
    $path = getenv('TC_PASSWORD_VAULT_KEY_FILE') ?: dirname(__DIR__, 3) . '/.taskclub-password.key';
    if (!is_file($path)) {
        $handle = @fopen($path, 'x');
        if ($handle !== false) {
            chmod($path, 0600);
            fwrite($handle, base64_encode(random_bytes(32)));
            fclose($handle);
        }
    }
    $key = base64_decode(trim((string)@file_get_contents($path)), true);
    if (!is_string($key) || strlen($key) !== 32) throw new RuntimeException('TaskClub password encryption key is unavailable.');
    return $cachedKey = $key;
}

function tcPasswordVaultEncrypt(string $password): string
{
    $nonce = random_bytes(12);
    $encrypted = openssl_encrypt($password, 'aes-256-gcm', tcPasswordVaultKey(), OPENSSL_RAW_DATA, $nonce, $tag);
    if ($encrypted === false) throw new RuntimeException('Unable to encrypt invitee password.');
    return base64_encode($nonce . $tag . $encrypted);
}

function tcPasswordVaultDecrypt(string $value): string
{
    if ($value === '') return '';
    $bytes = base64_decode($value, true);
    if (!is_string($bytes) || strlen($bytes) < 28) throw new RuntimeException('Invalid encrypted invitee password.');
    $plain = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', tcPasswordVaultKey(), OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16));
    if ($plain === false) throw new RuntimeException('Unable to decrypt invitee password.');
    return $plain;
}
