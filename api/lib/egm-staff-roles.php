<?php
declare(strict_types=1);
require_once __DIR__ . '/egm-facilitators.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/egm-registry.php';

const EGM_PANEL_STAFF_ROLES_KEY = 'panel_staff_roles';

/** Eligibility depends on current panel grants, never on a saved event role. */
function egmStaffPanelCanAccess(array $user, array $event): bool
{
    $permissions = normalizeTabPermissions($user['permissions'] ?? null, true);
    $roots = ['event-guest-manager'];
    if (str_starts_with(str_replace('\\', '/', (string)($event['directory'] ?? '')), 'mini apps/EGMs/')) {
        $roots[] = 'event-guest-manager-mission-' . (string)$event['code'];
    }
    foreach ($permissions as $permission) {
        foreach ($roots as $root) {
            if ($permission === $root || str_starts_with($permission, $root . ':')) return true;
        }
    }
    return false;
}

function egmStaffPanelRoles(PDO $pdo, string $code): array
{
    $roles = egmInstanceReadData($pdo, $code, EGM_PANEL_STAFF_ROLES_KEY, []);
    if (!is_array($roles)) throw new RuntimeException('اطلاعات نقش‌های رویداد معتبر نیست.');
    return $roles;
}

function egmStaffPanelRole(PDO $pdo, string $code, string $userCode): string
{
    return egmStaffRole(egmStaffPanelRoles($pdo, $code)[$userCode] ?? null);
}

function egmStaffPanelUsers(PDO $pdo, string $code): array
{
    $event = findEgmRegistryByCode($pdo, $code);
    if (!$event) throw new InvalidArgumentException('رویداد پیدا نشد.');
    $roles = egmStaffPanelRoles($pdo, $code);
    $users = [];
    foreach (loadUsersFromUsersTable($pdo) as $user) {
        if (!egmStaffPanelCanAccess($user, $event)) continue;
        $users[] = ['code'=>$user['code'], 'name'=>$user['name'], 'username'=>$user['username'], 'event_role'=>egmStaffRole($roles[$user['code']] ?? null)];
    }
    return $users;
}

function egmStaffSavePanelRole(PDO $pdo, string $code, string $userCode, $role): void
{
    $role = egmStaffValidateRole($role);
    $lockName = 'egm-staff:' . hash('sha256', $code);
    $lockName = substr($lockName, 0, 64);
    $lock = $pdo->prepare('SELECT GET_LOCK(:name, 5)');
    $lock->execute([':name'=>$lockName]);
    if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('ذخیره‌سازی در حال انجام است. دوباره امتحان کنید.');
    try {
        $event = findEgmRegistryByCode($pdo, $code);
        $user = loadUserByCode($pdo, $userCode);
        if (!$event || !$user || !egmStaffPanelCanAccess($user, $event)) throw new InvalidArgumentException('این کاربر به رویداد دسترسی ندارد.');
        $roles = egmStaffPanelRoles($pdo, $code);
        $roles[$userCode] = $role;
        egmInstanceWriteData($pdo, $code, EGM_PANEL_STAFF_ROLES_KEY, $roles);
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
        $release->execute([':name'=>$lockName]);
    }
}
