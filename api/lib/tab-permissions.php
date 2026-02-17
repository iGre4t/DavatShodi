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
        ['id' => 'asset-manager', 'label' => 'مدیریت اموال', 'title' => 'مدیریت اموال'],
        ['id' => 'devsettings', 'label' => 'تنظیمات توسعه‌دهنده', 'title' => 'Developer Settings']
    ];
    return $tabs;
}

function getPanelTabDefinitionMap(): array
{
    $map = [];
    foreach (getPanelTabDefinitions() as $tab) {
        $id = trim((string)($tab['id'] ?? ''));
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
        return (string)($tab['id'] ?? '');
    }, getPanelTabDefinitions()));
}

function getPanelTabOptionsForFrontend(): array
{
    $options = [];
    foreach (getPanelTabDefinitions() as $tab) {
        $id = trim((string)($tab['id'] ?? ''));
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

function normalizeTabPermissions($value, bool $fallbackToAll = true): array
{
    $validMap = getPanelTabDefinitionMap();
    $selected = [];
    foreach (parseTabPermissionRaw($value) as $entry) {
        $id = normalizePanelTabId($entry);
        if ($id === '' || !isset($validMap[$id])) {
            continue;
        }
        $selected[$id] = true;
    }
    if (empty($selected)) {
        return $fallbackToAll ? getAllPanelTabIds() : [];
    }
    $ordered = [];
    foreach (getAllPanelTabIds() as $tabId) {
        if (isset($selected[$tabId])) {
            $ordered[] = $tabId;
        }
    }
    return $ordered;
}

function encodeTabPermissionsForStorage(array $permissions): string
{
    $normalized = normalizeTabPermissions($permissions, false);
    $json = json_encode(array_values($normalized), JSON_UNESCAPED_UNICODE);
    return is_string($json) ? $json : '[]';
}

function resolveAllowedPanelTabsForUser(array $user): array
{
    return normalizeTabPermissions($user['permissions'] ?? null, true);
}

function userHasTabPermission(array $user, string $tabId): bool
{
    $requiredTab = normalizePanelTabId($tabId);
    if ($requiredTab === '') {
        return false;
    }
    return in_array($requiredTab, resolveAllowedPanelTabsForUser($user), true);
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
