<?php
declare(strict_types=1);
$keyPath = sys_get_temp_dir() . '/tc-vault-test-' . bin2hex(random_bytes(8));
putenv('TC_PASSWORD_VAULT_KEY_FILE=' . $keyPath);
require_once dirname(__DIR__) . '/api/lib/tc-password-vault.php';
try {
    $plain = '00123456';
    $cipher = tcPasswordVaultEncrypt($plain);
    if ($cipher === $plain || tcPasswordVaultDecrypt($cipher) !== $plain) throw new RuntimeException('Password vault round trip failed.');
    if (tcPasswordVaultEncrypt($plain) === $cipher) throw new RuntimeException('Encryption must use a fresh nonce.');
    $bytes = base64_decode($cipher);
    $bytes[28] = chr(ord($bytes[28]) ^ 1);
    $rejected = false;
    try { tcPasswordVaultDecrypt(base64_encode($bytes)); } catch (RuntimeException $error) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Tampered ciphertext was accepted.');
    echo "TaskClub password vault test passed.\n";
} finally {
    if (is_file($keyPath)) unlink($keyPath);
}
