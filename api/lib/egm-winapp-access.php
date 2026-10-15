<?php
declare(strict_types=1);
require_once __DIR__ . '/egm-facilitators.php';
require_once __DIR__ . '/tab-permissions.php';
require_once __DIR__ . '/egm-registry.php';

function egmWinAppPolicy(string $level): array
{
    $details = in_array($level, ['full_access', 'scan_and_details'], true);
    return [
        'level' => $level, 'can_scan' => true,
        'can_view_user_info' => $details, 'can_view_event_info' => $level !== 'scan_only',
        'can_manage_scan_actions' => $details || $level === 'scan_and_actions',
        'can_view_logs' => $details || $level === 'scan_and_actions',
        'can_open_guest_details' => $details, 'can_reset_guest_entry' => $details,
        'mask_national_id' => $level === 'scan_and_actions',
        'can_manage_settings' => $level === 'full_access', 'can_use_printer' => $level === 'full_access',
    ];
}

/** Matching credentials can belong to multiple EGMs; retain only proven memberships. */
function egmWinAppAuthenticateFacilitator(PDO $pdo, array $events, string $username, string $password): ?array
{
    $memberships = [];
    $identity = null;
    foreach ($events as $event) {
        $code = normalizeEgmRegistryCode($event['code'] ?? '');
        if ($code === '') continue;
        $row = egmFacilitatorsAuthenticateRows(egmFacilitatorsRead($pdo, $code), $username, $password);
        if (!$row || empty($row['auth_version'])) continue;
        $memberships[$code] = ['id' => $row['id'], 'version' => $row['auth_version']];
        $identity ??= egmFacilitatorsSessionUser([$row], $row['id'], $row['auth_version']);
    }
    return $identity ? $identity + ['auth_type' => 'facilitator', 'permissions' => [], 'egm_memberships' => $memberships] : null;
}

function egmWinAppFacilitatorForEvent(PDO $pdo, array $user, string $eventCode): ?array
{
    $membership = $user['egm_memberships'][$eventCode] ?? null;
    if (!is_array($membership)) return null;
    foreach (egmFacilitatorsRead($pdo, $eventCode) as $row) {
        if (($row['id'] ?? '') === ($membership['id'] ?? '') && !empty($row['auth_version'])
            && hash_equals((string)$row['auth_version'], (string)($membership['version'] ?? ''))) return $row;
    }
    return null;
}

function egmWinAppRefreshFacilitator(PDO $pdo, array $user): ?array
{
    $valid = []; $identity = null;
    foreach (($user['egm_memberships'] ?? []) as $code => $membership) {
        $code = (string)$code;
        if (normalizeEgmRegistryCode($code) === '' || !findEgmRegistryByCode($pdo, $code)) continue;
        $row = egmWinAppFacilitatorForEvent($pdo, $user, $code);
        if (!$row) continue;
        $valid[$code] = $membership;
        $identity ??= egmFacilitatorsSessionUser([$row], $row['id'], $row['auth_version']);
    }
    return $identity ? $identity + ['auth_type' => 'facilitator', 'permissions' => [], 'egm_memberships' => $valid] : null;
}

function egmWinAppCanAccessEvent(PDO $pdo, array $user, array $event): bool
{
    $code = (string)($event['code'] ?? '');
    if (($user['auth_type'] ?? '') === 'facilitator') return egmWinAppFacilitatorForEvent($pdo, $user, $code) !== null;
    $permissions = normalizeTabPermissions($user['permissions'] ?? null, true);
    $roots = ['event-guest-manager'];
    if (str_starts_with(str_replace('\\', '/', (string)($event['directory'] ?? '')), 'mini apps/EGMs/')) $roots[] = 'event-guest-manager-mission-' . $code;
    foreach ($roots as $root) {
        foreach (['scan', 'main'] as $capability) {
            if (in_array($root . ':' . $capability, $permissions, true)) return true;
        }
    }
    return false;
}
