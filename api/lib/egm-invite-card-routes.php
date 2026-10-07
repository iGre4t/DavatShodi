<?php
declare(strict_types=1);

require_once __DIR__ . '/egm-instance-storage.php';

const EGM_INVITE_CARD_ROUTES_TABLE = 'egm_invite_card_routes';

function egmInviteCardPage(string $imageUrl, array $settings, string $eventName, string $projectBase): string
{
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $name = trim((string)($settings['eventName'] ?? '')) ?: trim($eventName);
    $title = 'کارت دعوت' . ($name !== '' ? ' | ' . $name : '');
    $logo = trim((string)($settings['eventLogo'] ?? ''));
    if (preg_match('~^https?://~i', $logo) || preg_match('~^data:image/(?:png|jpeg|webp|gif|x-icon);base64,[A-Za-z0-9+/=]+$~D', $logo)) {
        $icon = $logo;
    } elseif ($logo !== '' && !preg_match('~[\\x00-\\x1f\\\\:]~', $logo) && !str_starts_with($logo, '//') && !in_array('..', explode('/', $logo), true)) {
        $path = str_contains($logo, '/') ? $logo : 'uploads/gallery/' . $logo;
        $icon = rtrim($projectBase, '/') . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    } else {
        $icon = 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="14" fill="#1999df"/><path d="M14 20h36v24H14z M14 20l18 14 18-14" fill="none" stroke="white" stroke-width="4" stroke-linejoin="round"/></svg>');
    }
    return '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
        . '<title>' . $escape($title) . '</title><link rel="icon" href="' . $escape($icon) . '">'
        . '<style>html,body{margin:0;min-height:100%;background:#f3f4f6}body{display:flex;justify-content:center;align-items:flex-start}img{display:block;max-width:100%;height:auto;margin:auto}@media print{html,body{background:white}img{max-height:100vh;object-fit:contain}}</style>'
        . '</head><body><img src="' . $escape($imageUrl) . '" alt="' . $escape($title) . '"></body></html>';
}

function ensureEgmInviteCardRoutesTable(PDO $pdo): void
{
    static $ensured = [];
    $cacheKey = spl_object_id($pdo);
    if (isset($ensured[$cacheKey])) {
        return;
    }
    $table = EGM_INVITE_CARD_ROUTES_TABLE;
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `{$table}` (
  `invite_code` VARCHAR(191) NOT NULL,
  `egm_code` VARCHAR(64) NOT NULL,
  `period_code` VARCHAR(128) NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `image_web_path` VARCHAR(512) NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`invite_code`),
  KEY `idx_invite_route_period` (`egm_code`, `period_code`, `status`),
  KEY `idx_invite_route_user` (`egm_code`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $migrate = $pdo->prepare(
        "UPDATE `{$table}` SET `image_web_path` = REPLACE(`image_web_path`, :legacy, :canonical) "
        . "WHERE `image_web_path` LIKE :legacy_pattern"
    );
    $legacyPrefix = EGM_LEGACY_INSTANCES_DIRECTORY . '/';
    $migrate->execute([
        ':legacy' => $legacyPrefix,
        ':canonical' => EGM_INSTANCES_DIRECTORY . '/',
        ':legacy_pattern' => $legacyPrefix . '%',
    ]);
    $ensured[$cacheKey] = true;
}

function egmInviteCardRandomPrefix(int $length = 16): string
{
    $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $last = strlen($alphabet) - 1;
    $value = '';
    for ($index = 0; $index < $length; $index++) {
        $value .= $alphabet[random_int(0, $last)];
    }
    return $value;
}

function egmInviteCardAllocateCode(PDO $pdo, string $egmCode, string $periodCode): string
{
    $egmCode = normalizeEgmInstanceCode($egmCode);
    $periodCode = trim($periodCode);
    if ($egmCode === '' || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $periodCode) !== 1) {
        throw new InvalidArgumentException('کد EGM یا بازه برای ساخت کارت دعوت نامعتبر است.');
    }
    ensureEgmInviteCardRoutesTable($pdo);
    $lookup = $pdo->prepare('SELECT 1 FROM `' . EGM_INVITE_CARD_ROUTES_TABLE . '` WHERE `invite_code` = :code LIMIT 1');
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $code = egmInviteCardRandomPrefix() . $egmCode . $periodCode;
        $lookup->execute([':code' => $code]);
        if ($lookup->fetchColumn() === false) {
            return $code;
        }
    }
    throw new RuntimeException('ساخت کد یکتای کارت دعوت ناموفق بود.');
}

function egmInviteCardUpsertRoute(
    PDO $pdo,
    string $inviteCode,
    string $egmCode,
    string $periodCode,
    int $userId,
    ?string $imageWebPath,
    string $status
): void {
    ensureEgmInviteCardRoutesTable($pdo);
    $status = $status === 'generated' ? 'generated' : 'pending';
    $statement = $pdo->prepare(
        'INSERT INTO `' . EGM_INVITE_CARD_ROUTES_TABLE . '` '
        . '(`invite_code`, `egm_code`, `period_code`, `user_id`, `image_web_path`, `status`) '
        . 'VALUES (:invite_code, :egm_code, :period_code, :user_id, :image_web_path, :status) '
        . 'ON DUPLICATE KEY UPDATE `egm_code` = VALUES(`egm_code`), `period_code` = VALUES(`period_code`), '
        . '`user_id` = VALUES(`user_id`), `image_web_path` = VALUES(`image_web_path`), '
        . '`status` = VALUES(`status`), `updated_at` = CURRENT_TIMESTAMP'
    );
    $statement->execute([
        ':invite_code' => $inviteCode,
        ':egm_code' => $egmCode,
        ':period_code' => $periodCode,
        ':user_id' => $userId,
        ':image_web_path' => $imageWebPath,
        ':status' => $status,
    ]);
}

function egmInviteCardFindRoute(PDO $pdo, string $inviteCode): ?array
{
    if (!egmInstanceTableExists($pdo, EGM_INVITE_CARD_ROUTES_TABLE)) {
        return null;
    }
    $statement = $pdo->prepare(
        'SELECT `invite_code`, `egm_code`, `period_code`, `user_id`, `image_web_path`, `status` '
        . 'FROM `' . EGM_INVITE_CARD_ROUTES_TABLE . '` WHERE `invite_code` = :code LIMIT 1'
    );
    $statement->execute([':code' => $inviteCode]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/**
 * Returns the managed InviteCards path when a stored card URL points to the
 * database-backed asset endpoint for this exact EGM and invite code.
 */
function egmInviteCardDatabaseAssetRelative(
    string $storedPath,
    string $registryDirectory,
    string $inviteCode
): string {
    $storedPath = trim(str_replace('\\', '/', $storedPath));
    $registryDirectory = trim(str_replace('\\', '/', $registryDirectory), '/');
    if ($storedPath === '' || $registryDirectory === '' || preg_match('/^[A-Za-z0-9]{8,191}$/D', $inviteCode) !== 1) {
        return '';
    }

    $parts = parse_url('/' . ltrim($storedPath, '/'));
    if (!is_array($parts) || isset($parts['host'], $parts['fragment'])) {
        return '';
    }
    $scriptPath = trim(rawurldecode((string)($parts['path'] ?? '')), '/');
    if (!hash_equals($registryDirectory . '/egm_asset.php', $scriptPath)) {
        return '';
    }
    $query = [];
    parse_str((string)($parts['query'] ?? ''), $query);
    $assetRelative = trim(str_replace('\\', '/', (string)($query['path'] ?? '')), '/');
    $expected = 'InviteCards/' . $inviteCode . '.jpg';
    return hash_equals($expected, $assetRelative) ? $expected : '';
}
