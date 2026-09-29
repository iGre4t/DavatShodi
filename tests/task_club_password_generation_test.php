<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/tc-password-generation.php';
foreach ([5, 8, 64] as $length) {
    foreach (['numeric', 'letters', 'mixed', 'strong'] as $type) {
        for ($i = 0; $i < 20; $i++) {
            $password = tcGenerateInviteePassword($length, $type);
            if (strlen($password) !== $length) throw new RuntimeException('Wrong length.');
            if ($type === 'numeric' && !ctype_digit($password)) throw new RuntimeException('Numeric password contains letters.');
            if ($type !== 'numeric' && (!preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password))) throw new RuntimeException('Letter complexity missing.');
            if (in_array($type, ['mixed', 'strong'], true) && !preg_match('/[0-9]/', $password)) throw new RuntimeException('Digits missing.');
            if ($type === 'strong' && !preg_match('/[^a-zA-Z0-9]/', $password)) throw new RuntimeException('Symbols missing.');
        }
    }
}
foreach ([[4, 'mixed'], [65, 'numeric'], [8, 'invalid']] as [$length, $type]) {
    $rejected = false;
    try { tcGenerateInviteePassword($length, $type); } catch (InvalidArgumentException $error) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Invalid settings accepted.');
}
echo "TaskClub password generation tests passed.\n";
