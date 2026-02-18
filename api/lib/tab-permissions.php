<?php
declare(strict_types=1);

function getPanelTabDefinitions(): array
{
    static $tabs = null;
    if ($tabs !== null) {
        return $tabs;
    }
    $tabs = [
        ['id' => 'home', 'label' => 'خانه', 'title' => 'Home'],
        ['id' => 'users', 'label' => 'کاربران', 'title' => 'Users'],
        ['id' => 'settings', 'label' => 'تنظیمات حساب', 'title' => 'Account Settings'],
        ['id' => 'features', 'label' => 'Features', 'title' => 'Features'],
        ['id' => 'wheel-of-fortune', 'label' => 'گردونه شانس', 'title' => 'گردونه شانس'],
        ['id' => 'task-club', 'label' => 'باشگاه تعاملی', 'title' => 'باشگاه تعاملی'],
        ['id' => 'asset-manager', 'label' => 'مدیریت اموال', 'title' => 'مدیریت اموال'],
        ['id' => 'devsettings', 'label' => 'تنظیمات توسعه‌دهنده', 'title' => 'Developer Settings']
    ];
    return $tabs;
}

function getPanelChildTabDefinitionsByParent(): array
{
    static $children = null;
    if ($children !== null) {
        return $children;
    }
    $children = [
        'devsettings' => [
            ['id' => 'devsettings:panel-settings', 'label' => 'عمومی'],
            ['id' => 'devsettings:appearance', 'label' => 'ظاهر'],
            ['id' => 'devsettings:database', 'label' => 'پایگاه داده'],
            ['id' => 'devsettings:printer-settings', 'label' => 'تنظیمات چاپگر']
        ],
        'asset-manager' => [
            ['id' => 'asset-manager:assets', 'label' => 'افزودن مال'],
            ['id' => 'asset-manager:storages', 'label' => 'افزودن انبار'],
            ['id' => 'asset-manager:ancestors', 'label' => 'افزودن مال مرسوم'],
            ['id' => 'asset-manager:labels', 'label' => 'برچسب‌ها'],
            ['id' => 'asset-manager:permissions', 'label' => 'دسترسی‌ها']
        ]
    ];
    return $children;
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
        if (!$fallbackToAll) {
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
        if (in_array($tabId, $normalized, true)) {
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
    $children = getPanelChildTabIdsByParent()[$parent] ?? [];
    if (empty($children)) {
        return [];
    }
    $normalized = normalizeTabPermissions($user['permissions'] ?? null, true);
    if (!in_array($parent, $normalized, true)) {
        return [];
    }
    $allowed = [];
    foreach ($children as $childId) {
        if (in_array($childId, $normalized, true)) {
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
    return in_array($id, $normalized, true);
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
    if (empty($_SESSION['authenticated']) || !is_array($_SESSION['user'] ?? null)) {
        denyPanelAccess(401, 'You must be logged in to access this resource.', $json);
    }
    return $_SESSION['user'];
}

function requireTabPermissionFromSession(string $tabId, bool $json = false): array
{
    $user = requireAuthenticatedSessionUser($json);
    if (!userHasTabPermission($user, $tabId)) {
        denyPanelAccess(403, 'You do not have permission to access this resource.', $json);
    }
    return $user;
}
