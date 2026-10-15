<?php
declare(strict_types=1);
require_once __DIR__ . '/panel-session.php';


/** Registry entries are read on every request, including hidden sidebar events. */
function panelPermissionDatabase(): ?PDO
{
    if (array_key_exists('panelPermissionPdo', $GLOBALS)) return $GLOBALS['panelPermissionPdo'];
    require_once __DIR__ . '/common.php';
    return $GLOBALS['panelPermissionPdo'] = connectDatabase(loadConfig(dirname(__DIR__) . '/config.php'));
}

function panelPermissionInstances(): array
{
    if (isset($GLOBALS['panelPermissionInstanceCatalog'])) return $GLOBALS['panelPermissionInstanceCatalog'];
    $entries = [];
    $pdo = panelPermissionDatabase();
    if (!$pdo) return [];
    foreach (['egm' => 'event-guest-manager', 'tc' => 'task-club'] as $table => $parent) {
        try { $rows = $pdo->query("SELECT code, name, directory FROM `$table`")->fetchAll(PDO::FETCH_ASSOC); }
        catch (Throwable $error) { continue; }
        foreach ($rows as $row) {
            $directory = str_replace('\\', '/', trim((string)$row['directory']));
            if ($table === 'egm' && !str_starts_with($directory, 'mini apps/EGMs/')) continue;
            if ($table === 'tc' && !str_starts_with($directory, 'mini apps/missions/')) continue;
            $id = $table === 'egm' ? $parent . '-mission-' . $row['code']
                : $parent . '-mission-' . substr(hash('sha256', basename($directory)), 0, 12);
            $entries[$id] = ['id' => $id, 'label' => (string)$row['name'], 'title' => (string)$row['name'], 'parent' => $parent, 'directory' => $directory];
        }
    }
    return $GLOBALS['panelPermissionInstanceCatalog'] = $entries;
}

function panelPermissionInstanceChildren(array $children): array
{
    foreach (panelPermissionInstances() as $id => $entry) {
        $children[$id] = array_map(static function ($child) use ($entry, $id) {
            $child['id'] = $id . substr($child['id'], strlen($entry['parent']));
            return $child;
        }, $children[$entry['parent']] ?? []);
    }
    return $children;
}

function getDefaultPanelUserPermissions(): array { return ['home', 'settings']; }

/** A global module grant covers its instances; individual grants never grant the creator. */
function panelPermissionGranted(array $permissions, string $id): bool
{
    if (in_array($id, $permissions, true)) return true;
    [$root, $suffix] = array_pad(explode(':', $id, 2), 2, null);
    $entry = panelPermissionInstances()[$root] ?? null;
    return $entry && in_array($entry['parent'] . ($suffix === null ? '' : ':' . $suffix), $permissions, true);
}

/** Use the requested script, including materialized wrappers, rather than template __DIR__. */
function panelPermissionContextId(string $id): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    foreach (panelPermissionInstances() as $entry) {
        if (str_starts_with($script, $root . '/' . $entry['directory'] . '/')) {
            if ($id === $entry['parent'] || str_starts_with($id, $entry['parent'] . ':')) {
                return $entry['id'] . substr($id, strlen($entry['parent']));
            }
        }
    }
    return $id;
}

function getPanelTabDefinitions(): array
{
    static $tabs = null;
    if ($tabs !== null) {
        return array_merge($tabs, array_values(panelPermissionInstances()));
    }
    $tabs = [
        ['id' => 'home', 'label' => 'خانه', 'title' => 'میز کار رویدادها'],
        ['id' => 'users', 'label' => 'کاربران', 'title' => 'Users'],
        ['id' => 'settings', 'label' => 'تنظیمات حساب', 'title' => 'Account Settings'],
        ['id' => 'features', 'label' => 'Features', 'title' => 'Features'],
        ['id' => 'wheel-of-fortune', 'label' => 'گردونه شانس', 'title' => 'گردونه شانس'],
        ['id' => 'task-club', 'label' => 'باشگاه تعاملی (همه باشگاه‌ها)', 'title' => 'باشگاه تعاملی'],
        ['id' => 'event-guest-manager', 'label' => 'مدیریت مهمانان (همه رویدادها)', 'title' => 'مدیریت مهمانان'],
        ['id' => 'rate-me', 'label' => 'RateMe', 'title' => 'RateMe'],
        ['id' => 'asset-manager', 'label' => 'مدیریت اموال', 'title' => 'مدیریت اموال'],
        ['id' => 'organizational-event-userbase', 'label' => 'کاربران سازمان', 'title' => 'کاربران سازمان'],
        ['id' => 'utm-service', 'label' => 'UTM Service', 'title' => 'UTM Service'],
        ['id' => 'linker-service', 'label' => 'Linker Service', 'title' => 'Linker Service'],
        ['id' => 'devsettings', 'label' => 'تنظیمات توسعه‌دهنده', 'title' => 'Developer Settings']
    ];
    return array_merge($tabs, array_values(panelPermissionInstances()));
}

function getPanelChildTabDefinitionsByParent(): array
{
    static $children = null;
    if ($children !== null) {
        return panelPermissionInstanceChildren($children);
    }
    $children = [
        'task-club' => [
            ['id' => 'task-club:main', 'label' => 'کنترل پنل'],
            ['id' => 'task-club:invitees', 'label' => 'دعوت‌شدگان'],
            ['id' => 'task-club:manage-tasks', 'label' => 'مدیریت تسک‌ها'],
            ['id' => 'task-club:task-access', 'label' => 'دسترسی ادمین'],
            ['id' => 'task-club:monitoring', 'label' => 'مانیتورینگ'],
            ['id' => 'task-club:export', 'label' => 'خروجی'],
            ['id' => 'task-club:linker', 'label' => 'Linker'],
            ['id' => 'task-club:event-style', 'label' => 'استایل رویداد'],
            ['id' => 'task-club:logs', 'label' => 'Logs']
        ],
        'event-guest-manager' => [
            ['id' => 'event-guest-manager:main', 'label' => 'کنترل پنل'],
            ['id' => 'event-guest-manager:scan', 'label' => 'اسکن و کنترل مهمانان'],
            ['id' => 'event-guest-manager:games', 'label' => 'بازی‌ها و اتاق‌ها'],
            ['id' => 'event-guest-manager:groups', 'label' => 'گروه‌ها'],
            ['id' => 'event-guest-manager:prizes', 'label' => 'جوایز و قرعه‌کشی'],
            ['id' => 'event-guest-manager:invite-card', 'label' => 'طراحی کارت دعوت'],
            ['id' => 'event-guest-manager:print-card', 'label' => 'طراحی کارت چاپی'],
            ['id' => 'event-guest-manager:custom-number-ticket', 'label' => 'بلیت شماره‌دار'],
            ['id' => 'event-guest-manager:guest-reports', 'label' => 'گزارش‌های مهمانان'],
            ['id' => 'event-guest-manager:facilitators', 'label' => 'تسهیلگرها'],

            ['id' => 'event-guest-manager:invitees', 'label' => 'دعوت‌شدگان'],
            ['id' => 'event-guest-manager:manage-tasks', 'label' => 'بازه‌ها'],
            ['id' => 'event-guest-manager:task-access', 'label' => 'دسترسی ادمین'],
            ['id' => 'event-guest-manager:monitoring', 'label' => 'مانیتورینگ'],
            ['id' => 'event-guest-manager:export', 'label' => 'خروجی'],
            ['id' => 'event-guest-manager:linker', 'label' => 'پیوندساز'],
            ['id' => 'event-guest-manager:logs', 'label' => 'گزارش‌ها']
        ],
        'rate-me' => [
            ['id' => 'rate-me:main', 'label' => 'Main Panel'],
            ['id' => 'rate-me:invitees', 'label' => 'Invitees'],
            ['id' => 'rate-me:manage-tasks', 'label' => 'Manage Tasks'],
            ['id' => 'rate-me:task-access', 'label' => 'Task Access'],
            ['id' => 'rate-me:monitoring', 'label' => 'Monitoring'],
            ['id' => 'rate-me:export', 'label' => 'Export'],
            ['id' => 'rate-me:event-style', 'label' => 'Event Style']
        ],
        'devsettings' => [
            ['id' => 'devsettings:panel-settings', 'label' => 'عمومی'],
            ['id' => 'devsettings:appearance', 'label' => 'ظاهر'],
            ['id' => 'devsettings:database', 'label' => 'پایگاه داده'],
            ['id' => 'devsettings:printer-settings', 'label' => 'تنظیمات چاپگر'],
            ['id' => 'devsettings:windows-app', 'label' => 'برنامه ویندوز و به‌روزرسانی‌ها']
        ],
        'asset-manager' => [
            ['id' => 'asset-manager:assets', 'label' => 'افزودن مال'],
            ['id' => 'asset-manager:storages', 'label' => 'افزودن انبار'],
            ['id' => 'asset-manager:ancestors', 'label' => 'افزودن مال مرسوم'],
            ['id' => 'asset-manager:labels', 'label' => 'برچسب‌ها'],
            ['id' => 'asset-manager:permissions', 'label' => 'دسترسی‌ها']
        ]
    ];
    return panelPermissionInstanceChildren($children);
}

function getPanelChildTabIdsByParent(): array
{
    $map = [];
    foreach (getPanelChildTabDefinitionsByParent() as $parentId => $entries) {
        $parent = normalizePanelTabId($parentId);
        if ($parent === '') {
            continue;
        }
        $ids = [];
        foreach ($entries as $entry) {
            $id = normalizePanelTabId($entry['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $ids[] = $id;
        }
        $map[$parent] = $ids;
    }
    return $map;
}

function getPanelChildrenCustomizationMarker(string $parentTabId): string
{
    $parent = normalizePanelTabId($parentTabId);
    return $parent === '' ? '' : $parent . ':custom';
}

function getPanelTabDefinitionMap(): array
{
    $map = [];
    foreach (getPanelTabDefinitions() as $tab) {
        $id = normalizePanelTabId($tab['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $map[$id] = $tab;
    }
    return $map;
}

function getAllPanelTabIds(): array
{
    return array_values(array_map(static function (array $tab): string {
        return normalizePanelTabId($tab['id'] ?? '');
    }, getPanelTabDefinitions()));
}

function getAllPanelChildTabIds(): array
{
    $all = [];
    foreach (getPanelChildTabIdsByParent() as $childIds) {
        foreach ($childIds as $childId) {
            $all[] = $childId;
        }
    }
    return $all;
}

function getAllPanelPermissionIds(bool $includeCustomizationMarkers = false): array
{
    $ids = getAllPanelTabIds();
    foreach (getPanelChildTabIdsByParent() as $parentId => $childIds) {
        if ($includeCustomizationMarkers && !empty($childIds)) {
            $marker = getPanelChildrenCustomizationMarker($parentId);
            if ($marker !== '') {
                $ids[] = $marker;
            }
        }
        foreach ($childIds as $childId) {
            $ids[] = $childId;
        }
    }
    return array_values(array_unique(array_filter($ids, static fn ($id) => $id !== '')));
}

function getPanelTabOptionsForFrontend(): array
{
    $options = [];
    foreach (getPanelTabDefinitions() as $tab) {
        $id = normalizePanelTabId($tab['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $options[] = [
            'id' => $id,
            'label' => trim((string)($tab['label'] ?? $id)),
            'title' => trim((string)($tab['title'] ?? $id))
        ];
    }
    return $options;
}

function getPanelPermissionTreeForFrontend(): array
{
    $childrenByParent = getPanelChildTabDefinitionsByParent();
    $tree = [];
    foreach (getPanelTabDefinitions() as $tab) {
        $parentId = normalizePanelTabId($tab['id'] ?? '');
        if ($parentId === '') {
            continue;
        }
        $children = [];
        foreach (($childrenByParent[$parentId] ?? []) as $child) {
            $childId = normalizePanelTabId($child['id'] ?? '');
            if ($childId === '') {
                continue;
            }
            $children[] = [
                'id' => $childId,
                'label' => trim((string)($child['label'] ?? $childId))
            ];
        }
        $tree[] = [
            'id' => $parentId,
            'label' => trim((string)($tab['label'] ?? $parentId)),
            'title' => trim((string)($tab['title'] ?? $parentId)),
            'children' => $children
        ];
    }
    return $tree;
}

function normalizePanelTabId($value): string
{
    return strtolower(trim((string)$value));
}

function parseTabPermissionRaw($value): array
{
    if (is_array($value)) {
        return array_values($value);
    }
    $raw = trim((string)$value);
    if ($raw === '') {
        return [];
    }
    if ($raw[0] === '[' || $raw[0] === '{') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_values($decoded);
        }
    }
    $parts = preg_split('/[\s,;|]+/', $raw);
    return is_array($parts) ? $parts : [];
}

function orderNormalizedPermissionIds(array $set): array
{
    $ordered = [];
    $childrenByParent = getPanelChildTabIdsByParent();
    foreach (getAllPanelTabIds() as $parentId) {
        if (!isset($set[$parentId])) {
            continue;
        }
        $ordered[] = $parentId;
        $children = $childrenByParent[$parentId] ?? [];
        if (empty($children)) {
            continue;
        }
        $marker = getPanelChildrenCustomizationMarker($parentId);
        if ($marker !== '' && isset($set[$marker])) {
            $ordered[] = $marker;
        }
        foreach ($children as $childId) {
            if (isset($set[$childId])) {
                $ordered[] = $childId;
            }
        }
    }
    return $ordered;
}

function normalizeTabPermissions($value, bool $fallbackToAll = true): array
{
    $rootIds = getAllPanelTabIds();
    $childrenByParent = getPanelChildTabIdsByParent();
    $validIds = array_fill_keys(getAllPanelPermissionIds(true), true);
    $rawTokens = parseTabPermissionRaw($value);
    $inputSet = [];
    foreach ($rawTokens as $token) {
        $id = normalizePanelTabId($token);
        if ($id === '' || !isset($validIds[$id])) {
            continue;
        }
        $inputSet[$id] = true;
    }
    $selectedRoots = [];
    foreach ($rootIds as $rootId) {
        if (isset($inputSet[$rootId])) {
            $selectedRoots[] = $rootId;
        }
    }
    if (empty($selectedRoots)) {
        if (!$fallbackToAll || ($value !== null && $value !== '')) {
            return [];
        }
        $selectedRoots = $rootIds;
    }
    $normalizedSet = [];
    foreach ($selectedRoots as $rootId) {
        $normalizedSet[$rootId] = true;
        $children = $childrenByParent[$rootId] ?? [];
        if (empty($children)) {
            continue;
        }
        $marker = getPanelChildrenCustomizationMarker($rootId);
        $hasCustomizationMarker = $marker !== '' && isset($inputSet[$marker]);
        $selectedChildren = [];
        foreach ($children as $childId) {
            if (isset($inputSet[$childId])) {
                $selectedChildren[] = $childId;
            }
        }
        // Older full-EGM grants had every legacy child but no explicit customization marker.
        // Preserve their administrative access when introducing the new section catalog.
        $legacyEgmChildren = ['main', 'invitees', 'manage-tasks', 'task-access', 'monitoring', 'export', 'linker', 'logs'];
        if ($rootId === 'event-guest-manager' && !$hasCustomizationMarker
            && count(array_filter($legacyEgmChildren, static fn ($suffix) => isset($inputSet[$rootId . ':' . $suffix]))) === count($legacyEgmChildren)) {
            $selectedChildren = $children;
        }
        if ($hasCustomizationMarker || !empty($selectedChildren)) {
            if ($marker !== '') {
                $normalizedSet[$marker] = true;
            }
            foreach ($selectedChildren as $childId) {
                $normalizedSet[$childId] = true;
            }
            continue;
        }
        foreach ($children as $childId) {
            $normalizedSet[$childId] = true;
        }
    }
    return orderNormalizedPermissionIds($normalizedSet);
}

function encodeTabPermissionsForStorage(array $permissions): string
{
    $normalized = normalizeTabPermissions($permissions, false);
    $json = json_encode(array_values($normalized), JSON_UNESCAPED_UNICODE);
    return is_string($json) ? $json : '[]';
}

function resolveAllowedPanelTabsForUser(array $user): array
{
    $normalized = normalizeTabPermissions($user['permissions'] ?? null, true);
    $allowed = [];
    foreach (getAllPanelTabIds() as $tabId) {
        if (panelPermissionGranted($normalized, $tabId)) {
            $allowed[] = $tabId;
        }
    }
    return $allowed;
}

function resolveAllowedPanelChildTabsForUser(array $user, string $parentTabId): array
{
    $parent = normalizePanelTabId($parentTabId);
    if ($parent === '') {
        return [];
    }
    $effectiveParent = panelPermissionContextId($parent);
    $children = getPanelChildTabIdsByParent()[$parent] ?? [];
    if (empty($children)) {
        return [];
    }
    $normalized = normalizeTabPermissions($user['permissions'] ?? null, true);
    if (!panelPermissionGranted($normalized, $effectiveParent)) {
        return [];
    }
    $allowed = [];
    foreach ($children as $childId) {
        if (panelPermissionGranted($normalized, panelPermissionContextId($childId))) {
            $allowed[] = $childId;
        }
    }
    return $allowed;
}

function userHasPermissionId(array $user, string $permissionId): bool
{
    $id = normalizePanelTabId($permissionId);
    if ($id === '') {
        return false;
    }
    $normalized = normalizeTabPermissions($user['permissions'] ?? null, true);
    return panelPermissionGranted($normalized, panelPermissionContextId($id));
}

function userHasTabPermission(array $user, string $tabId): bool
{
    return userHasPermissionId($user, $tabId);
}

function ensurePanelSessionStarted(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function denyPanelAccess(int $statusCode, string $message, bool $json = false): void
{
    http_response_code($statusCode);
    if ($json) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'status' => 'error',
            'message' => $message
        ]);
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $message;
    }
    exit;
}

function requireAuthenticatedSessionUser(bool $json = false): array
{
    ensurePanelSessionStarted();
    panelSessionClearExpired();
    if (empty($_SESSION['authenticated']) || !is_array($_SESSION['user'] ?? null)) {
        header('X-Panel-Session-Expired: 1');
        if (!$json) { header('Location: /login.php'); exit; }
        denyPanelAccess(401, 'نشست شما پایان یافته است. دوباره وارد شوید.', true);
    }
    // Permissions can be revoked while this browser is open. Never authorize a stale session copy.
    $pdo = panelPermissionDatabase();
    if (!$pdo) denyPanelAccess(503, 'بررسی دسترسی ممکن نشد. دوباره تلاش کنید.', $json);
    try {
        $statement = $pdo->prepare('SELECT permissions FROM users WHERE code = :code LIMIT 1');
        $statement->execute([':code' => (string)($_SESSION['user']['code'] ?? '')]);
        $record = $statement->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $error) { denyPanelAccess(503, 'بررسی دسترسی ممکن نشد. دوباره تلاش کنید.', $json); }
    if (!$record) {
        $_SESSION = [];
        header('X-Panel-Session-Expired: 1');
        if (!$json) { header('Location: /login.php'); exit; }
        denyPanelAccess(401, 'حساب شما دیگر در دسترس نیست.', true);
    }
    $_SESSION['user']['permissions'] = normalizeTabPermissions($record['permissions'], true);
    return $_SESSION['user'];
}

function requireTabPermissionFromSession(string $tabId, bool $json = false): array
{
    $user = requireAuthenticatedSessionUser($json);
    if (!userHasTabPermission($user, $tabId)) {
        denyPanelAccess(403, 'شما به این بخش دسترسی ندارید.', $json);
    }
    return $user;
}
