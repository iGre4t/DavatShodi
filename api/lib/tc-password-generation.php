<?php
declare(strict_types=1);
function tcGenerateInviteePassword(int $length, string $type): string
{
    $groups = ['23456789'];
    if ($type === 'numeric') $groups = ['0123456789'];
    elseif ($type === 'letters') $groups = ['abcdefghjkmnpqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ'];
    elseif ($type === 'mixed' || $type === 'strong') {
        $groups = ['abcdefghjkmnpqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789'];
        if ($type === 'strong') $groups[] = '!@#$%+-_';
    } else throw new InvalidArgumentException('Invalid password type.');
    if ($length < 5 || $length > 64) throw new InvalidArgumentException('Password length must be between 5 and 64.');
    $chars = [];
    foreach ($groups as $group) $chars[] = $group[random_int(0, strlen($group) - 1)];
    $alphabet = implode('', $groups);
    while (count($chars) < $length) $chars[] = $alphabet[random_int(0, strlen($alphabet) - 1)];
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }
    return implode('', $chars);
}
