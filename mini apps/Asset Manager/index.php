<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../../api/lib/tab-permissions.php';
$sessionUser = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];
if (empty($_SESSION['authenticated'])) {
    header('Location: ../../login.php');
    exit;
}
if (!userHasTabPermission($sessionUser, 'asset-manager')) {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    http_response_code(403);
    if ($isPost) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'status' => 'error',
            'message' => 'You do not have permission to access asset management.'
        ], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'You do not have permission to access asset management.';
    }
    exit;
}
$allowedAssetChildPermissions = resolveAllowedPanelChildTabsForUser($sessionUser, 'asset-manager');
$allowedAssetChildPermissionSet = array_fill_keys($allowedAssetChildPermissions, true);
$canManageAssetPermissions = isset($allowedAssetChildPermissionSet['asset-manager:permissions']);

const DATA_DIR = __DIR__ . '/data';
const ASSETS_FILE = DATA_DIR . '/assets.json';
const STORAGES_FILE = DATA_DIR . '/storages.json';
const ANCESTOR_ASSETS_FILE = DATA_DIR . '/ancestor_assets.json';
const LABELS_FILE = DATA_DIR . '/labels.json';
const STORAGE_PERMISSIONS_FILE = DATA_DIR . '/storage_permissions.json';
const SPECIAL_PERMISSIONS_FILE = DATA_DIR . '/special_permissions.json';
const API_CONFIG_FILE = __DIR__ . '/../../api/config.php';
const API_COMMON_FILE = __DIR__ . '/../../api/lib/common.php';
const API_USERS_FILE = __DIR__ . '/../../api/lib/users.php';
const SPECIAL_PERMISSION_BOARD_MEMBER = 'board_member';
const STORAGE_KIND_BRANCH = 'branch';
const STORAGE_KIND_PERSON = 'person';
const STORAGE_KIND_REPAIR_SHOP = 'repair_shop';
const STORAGE_KINDS = [
    STORAGE_KIND_BRANCH,
    STORAGE_KIND_PERSON,
    STORAGE_KIND_REPAIR_SHOP
];
const LEGACY_DATA_DIRS = [
    __DIR__ . '/../preopreties manager',
    __DIR__ . '/../Asset Manager data'
];
require_once __DIR__ . '/asset_logs.php';

function legacyPathCandidates(string $filename): array
{
    $paths = [];
    foreach (LEGACY_DATA_DIRS as $legacyDir) {
        $paths[] = $legacyDir . '/' . $filename;
    }
    return $paths;
}

function ensureFileInitialized(string $targetPath, array $legacyCandidates = []): void
{
    if (is_file($targetPath)) {
        return;
    }
    foreach ($legacyCandidates as $candidate) {
        if (!is_file($candidate)) {
            continue;
        }
        $raw = @file_get_contents($candidate);
        if ($raw === false) {
            continue;
        }
        if (@file_put_contents($targetPath, $raw, LOCK_EX) !== false) {
            return;
        }
    }
    @file_put_contents($targetPath, "[]\n", LOCK_EX);
}

function ensureObjectFileInitialized(string $targetPath): void
{
    if (is_file($targetPath)) {
        return;
    }
    @file_put_contents($targetPath, "{}\n", LOCK_EX);
}

function ensureDataDir(): void
{
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0755, true);
    }
    ensureFileInitialized(ASSETS_FILE, legacyPathCandidates('assets.json'));
    ensureFileInitialized(STORAGES_FILE, legacyPathCandidates('storages.json'));
    ensureFileInitialized(ANCESTOR_ASSETS_FILE, legacyPathCandidates('ancestor_assets.json'));
    ensureFileInitialized(LABELS_FILE, legacyPathCandidates('labels.json'));
    ensureObjectFileInitialized(STORAGE_PERMISSIONS_FILE);
    ensureObjectFileInitialized(SPECIAL_PERMISSIONS_FILE);
}

function clean(string $value): string
{
    $trim = trim($value);
    return preg_replace('/\s+/', ' ', $trim) ?? $trim;
}

function parseBool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function normalizeStorageKind(string $kind): string
{
    $normalized = strtolower(trim($kind));
    return in_array($normalized, STORAGE_KINDS, true) ? $normalized : STORAGE_KIND_BRANCH;
}

function isValidStorageKind(string $kind): bool
{
    return in_array(strtolower(trim($kind)), STORAGE_KINDS, true);
}

function uniqueNonEmptyStrings(array $values): array
{
    $out = [];
    $seen = [];
    foreach ($values as $value) {
        $candidate = trim((string)$value);
        if ($candidate === '' || isset($seen[$candidate])) {
            continue;
        }
        $seen[$candidate] = true;
        $out[] = $candidate;
    }
    return $out;
}

function parseJsonArrayRaw(string $raw): array
{
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    return uniqueNonEmptyStrings($decoded);
}

function parseJsonMapRaw(string $raw): array
{
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    $out = [];
    foreach ($decoded as $key => $value) {
        $k = trim((string)$key);
        if ($k === '') {
            continue;
        }
        $out[$k] = trim((string)$value);
    }
    return $out;
}

function readMap(string $path): array
{
    ensureDataDir();
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    $result = [];
    foreach ($decoded as $key => $value) {
        $mapKey = clean((string)$key);
        if ($mapKey === '' || !is_array($value)) {
            continue;
        }
        $result[$mapKey] = uniqueNonEmptyStrings($value);
    }
    return $result;
}

function writeMap(string $path, array $items): bool
{
    $normalized = [];
    foreach ($items as $key => $value) {
        $mapKey = clean((string)$key);
        if ($mapKey === '') {
            continue;
        }
        $normalized[$mapKey] = uniqueNonEmptyStrings(is_array($value) ? $value : []);
    }
    ksort($normalized);
    $json = json_encode((object)$normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    return @file_put_contents($path, $json . "\n", LOCK_EX) !== false;
}

function readArray(string $path): array
{
    ensureDataDir();
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? array_values($decoded) : [];
}

function writeArray(string $path, array $items): bool
{
    $json = json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    return @file_put_contents($path, $json . "\n", LOCK_EX) !== false;
}

function idxById(array $items, string $id): int
{
    foreach ($items as $i => $item) {
        if ((string)($item['id'] ?? '') === $id) {
            return (int)$i;
        }
    }
    return -1;
}

function storageExists(array $storages, string $storageId): bool
{
    return idxById($storages, $storageId) >= 0;
}

function ancestorExists(array $ancestors, string $ancestorId): bool
{
    return idxById($ancestors, $ancestorId) >= 0;
}

function labelExists(array $labels, string $labelId): bool
{
    return idxById($labels, $labelId) >= 0;
}

function labelHasChildren(array $labels, string $labelId): bool
{
    foreach ($labels as $label) {
        if ((string)($label['parent_id'] ?? '') === $labelId) {
            return true;
        }
    }
    return false;
}

function labelIsDirectChild(array $labels, string $parentId, string $childId): bool
{
    foreach ($labels as $label) {
        if ((string)($label['id'] ?? '') !== $childId) {
            continue;
        }
        return (string)($label['parent_id'] ?? '') === $parentId;
    }
    return false;
}

function sanitizeLabelIds(array $labels, array $labelIds): array
{
    $result = [];
    $seen = [];
    foreach ($labelIds as $labelId) {
        $id = trim((string)$labelId);
        if ($id === '' || isset($seen[$id]) || !labelExists($labels, $id)) {
            continue;
        }
        $seen[$id] = true;
        $result[] = $id;
    }
    return $result;
}

function sanitizeParentLabelIds(array $labels, array $labelIds): array
{
    $result = [];
    foreach (sanitizeLabelIds($labels, $labelIds) as $labelId) {
        if (!labelHasChildren($labels, $labelId)) {
            continue;
        }
        $result[] = $labelId;
    }
    return $result;
}

function normalizeAssetLabelValues(array $labelValues, array $ancestorLabelIds, array $labels): array
{
    $normalized = [];
    foreach ($ancestorLabelIds as $labelId) {
        $value = trim((string)($labelValues[$labelId] ?? ''));
        if ($value !== '' && (!labelExists($labels, $value) || !labelIsDirectChild($labels, $labelId, $value))) {
            $value = '';
        }
        $normalized[$labelId] = $value;
    }
    return $normalized;
}

function loadStorages(): array
{
    $items = [];
    foreach (readArray(STORAGES_FILE) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string)($row['id'] ?? ''));
        $name = clean((string)($row['name'] ?? ''));
        if ($id === '' || $name === '') {
            continue;
        }
        $items[] = [
            'id' => $id,
            'name' => $name,
            'kind' => normalizeStorageKind((string)($row['kind'] ?? '')),
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? '')
        ];
    }
    return $items;
}

function loadLabels(): array
{
    $items = [];
    foreach (readArray(LABELS_FILE) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string)($row['id'] ?? ''));
        $name = clean((string)($row['name'] ?? ''));
        $parentId = trim((string)($row['parent_id'] ?? ''));
        if ($id === '' || $name === '') {
            continue;
        }
        if ($parentId === $id) {
            $parentId = '';
        }
        $items[] = [
            'id' => $id,
            'name' => $name,
            'parent_id' => $parentId,
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? '')
        ];
    }

    $validIds = [];
    foreach ($items as $label) {
        $validIds[(string)$label['id']] = true;
    }
    foreach ($items as &$label) {
        $parentId = (string)($label['parent_id'] ?? '');
        if ($parentId !== '' && !isset($validIds[$parentId])) {
            $label['parent_id'] = '';
        }
    }
    unset($label);

    return $items;
}

function loadAncestors(array $labels = []): array
{
    $items = [];
    foreach (readArray(ANCESTOR_ASSETS_FILE) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string)($row['id'] ?? ''));
        $name = clean((string)($row['name'] ?? ''));
        $labelIds = uniqueNonEmptyStrings((array)($row['label_ids'] ?? []));
        if ($labels) {
            $labelIds = sanitizeParentLabelIds($labels, $labelIds);
        }
        if ($id === '' || $name === '') {
            continue;
        }
        $items[] = [
            'id' => $id,
            'name' => $name,
            'label_ids' => $labelIds,
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? '')
        ];
    }
    return $items;
}

function ancestorById(array $ancestors, string $ancestorId): ?array
{
    foreach ($ancestors as $ancestor) {
        if ((string)($ancestor['id'] ?? '') === $ancestorId) {
            return $ancestor;
        }
    }
    return null;
}

function ancestorNameById(array $ancestors, string $ancestorId): string
{
    $ancestor = ancestorById($ancestors, $ancestorId);
    if ($ancestor === null) {
        return '';
    }
    return clean((string)($ancestor['name'] ?? ''));
}

function storageNameById(array $storages, string $storageId): string
{
    foreach ($storages as $storage) {
        if ((string)($storage['id'] ?? '') !== $storageId) {
            continue;
        }
        return clean((string)($storage['name'] ?? ''));
    }
    return '';
}

function currentActorDisplayName(): string
{
    $user = $_SESSION['user'] ?? [];
    if (!is_array($user)) {
        return 'کاربر نامشخص';
    }

    foreach (['fullname', 'display_name', 'name', 'username', 'code'] as $field) {
        $value = clean((string)($user[$field] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return 'کاربر نامشخص';
}

function loadSystemUsersForPermissions(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $usersByCode = [];

    if (is_file(API_CONFIG_FILE) && is_file(API_COMMON_FILE) && is_file(API_USERS_FILE)) {
        require_once API_COMMON_FILE;
        require_once API_USERS_FILE;

        if (function_exists('loadConfig') && function_exists('connectDatabase') && function_exists('loadUsersFromUsersTable')) {
            try {
                $config = loadConfig(API_CONFIG_FILE);
                $pdo = connectDatabase($config);
                if ($pdo instanceof PDO) {
                    if (function_exists('ensureUsersExtendedColumns')) {
                        ensureUsersExtendedColumns($pdo);
                    }
                    $rows = loadUsersFromUsersTable($pdo);
                    foreach ($rows as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $code = clean((string)($row['code'] ?? ''));
                        if ($code === '') {
                            continue;
                        }
                        $fullname = clean((string)($row['fullname'] ?? ''));
                        $username = clean((string)($row['username'] ?? ''));
                        $name = clean((string)($row['name'] ?? ''));
                        $display = $fullname !== ''
                            ? $fullname
                            : ($name !== '' ? $name : ($username !== '' ? $username : $code));

                        $usersByCode[$code] = [
                            'code' => $code,
                            'fullname' => $fullname,
                            'username' => $username,
                            'name' => $display
                        ];
                    }
                }
            } catch (Throwable $error) {
                // Ignore user lookup failures and fall back to session user.
            }
        }
    }

    $sessionUser = $_SESSION['user'] ?? [];
    if (is_array($sessionUser)) {
        $sessionCode = clean((string)($sessionUser['code'] ?? ''));
        if ($sessionCode !== '' && !isset($usersByCode[$sessionCode])) {
            $sessionFullname = clean((string)($sessionUser['fullname'] ?? ''));
            $sessionUsername = clean((string)($sessionUser['username'] ?? ''));
            $sessionName = clean((string)($sessionUser['name'] ?? ''));
            $display = $sessionFullname !== ''
                ? $sessionFullname
                : ($sessionName !== '' ? $sessionName : ($sessionUsername !== '' ? $sessionUsername : $sessionCode));
            $usersByCode[$sessionCode] = [
                'code' => $sessionCode,
                'fullname' => $sessionFullname,
                'username' => $sessionUsername,
                'name' => $display
            ];
        }
    }

    $users = array_values($usersByCode);
    usort($users, function (array $left, array $right): int {
        $leftName = clean((string)($left['name'] ?? ''));
        $rightName = clean((string)($right['name'] ?? ''));
        return strnatcasecmp($leftName, $rightName);
    });

    $cached = $users;
    return $cached;
}

function systemUserExistsByCode(array $users, string $userCode): bool
{
    $needle = clean($userCode);
    if ($needle === '') {
        return false;
    }
    foreach ($users as $user) {
        if (clean((string)($user['code'] ?? '')) === $needle) {
            return true;
        }
    }
    return false;
}

function loadStoragePermissions(array $storages = [], array $users = []): array
{
    $validStorageIds = [];
    foreach ($storages as $storage) {
        $storageId = trim((string)($storage['id'] ?? ''));
        if ($storageId !== '') {
            $validStorageIds[$storageId] = true;
        }
    }

    $validUserCodes = [];
    foreach ($users as $user) {
        $userCode = clean((string)($user['code'] ?? ''));
        if ($userCode !== '') {
            $validUserCodes[$userCode] = true;
        }
    }

    $raw = readMap(STORAGE_PERMISSIONS_FILE);
    $result = [];
    foreach ($raw as $userCode => $storageIds) {
        $code = clean((string)$userCode);
        if ($code === '') {
            continue;
        }
        if ($validUserCodes && !isset($validUserCodes[$code])) {
            continue;
        }

        $allowed = [];
        foreach (uniqueNonEmptyStrings((array)$storageIds) as $storageId) {
            if ($validStorageIds && !isset($validStorageIds[$storageId])) {
                continue;
            }
            $allowed[] = $storageId;
        }
        $result[$code] = $allowed;
    }

    ksort($result);
    return $result;
}

function writeStoragePermissions(array $permissions): bool
{
    return writeMap(STORAGE_PERMISSIONS_FILE, $permissions);
}

function loadSpecialPermissions(array $users = []): array
{
    $validUserCodes = [];
    foreach ($users as $user) {
        $userCode = clean((string)($user['code'] ?? ''));
        if ($userCode !== '') {
            $validUserCodes[$userCode] = true;
        }
    }

    $raw = readMap(SPECIAL_PERMISSIONS_FILE);
    $boardMembers = uniqueNonEmptyStrings((array)($raw[SPECIAL_PERMISSION_BOARD_MEMBER] ?? []));
    if ($validUserCodes) {
        $boardMembers = array_values(array_filter($boardMembers, function (string $userCode) use ($validUserCodes): bool {
            return isset($validUserCodes[$userCode]);
        }));
    }

    return [
        SPECIAL_PERMISSION_BOARD_MEMBER => $boardMembers
    ];
}

function writeSpecialPermissions(array $permissions): bool
{
    $normalized = [];
    foreach ($permissions as $permissionKey => $userCodes) {
        $key = clean((string)$permissionKey);
        if ($key === '') {
            continue;
        }
        $normalized[$key] = uniqueNonEmptyStrings(is_array($userCodes) ? $userCodes : []);
    }

    if (!isset($normalized[SPECIAL_PERMISSION_BOARD_MEMBER])) {
        $normalized[SPECIAL_PERMISSION_BOARD_MEMBER] = [];
    }

    return writeMap(SPECIAL_PERMISSIONS_FILE, $normalized);
}
function assetNameForLog(array $asset): string
{
    $name = clean((string)($asset['name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    $code = clean((string)($asset['code'] ?? ''));
    if ($code !== '') {
        return 'کد ' . $code;
    }
    return 'مال بدون نام';
}

function assetCodeForLog(array $asset): string
{
    return clean((string)($asset['code'] ?? ''));
}

function ancestorIdByName(array $ancestors, string $name): string
{
    $needle = strtolower(clean($name));
    if ($needle === '') {
        return '';
    }
    foreach ($ancestors as $ancestor) {
        if (strtolower(clean((string)($ancestor['name'] ?? ''))) === $needle) {
            return (string)($ancestor['id'] ?? '');
        }
    }
    return '';
}

function loadAssets(array $ancestors = [], array $labels = []): array
{
    $items = [];
    foreach (readArray(ASSETS_FILE) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string)($row['id'] ?? ''));
        $ancestorId = trim((string)($row['ancestor_id'] ?? ''));
        $name = clean((string)($row['name'] ?? ''));
        if ($ancestorId === '' && $name !== '') {
            $ancestorId = ancestorIdByName($ancestors, $name);
        }
        $hasExplicitSpecial = array_key_exists('special_asset', $row);
        $specialAsset = $hasExplicitSpecial
            ? parseBool($row['special_asset'] ?? false)
            : ($ancestorId === '' && $name !== '');
        if (!$specialAsset && $ancestorId !== '') {
            $ancestorName = ancestorNameById($ancestors, $ancestorId);
            if ($ancestorName !== '') {
                $name = $ancestorName;
            }
        }
        $code = clean((string)($row['code'] ?? ''));
        $storageId = trim((string)($row['storage_id'] ?? ''));
        if ($id === '' || $code === '' || ($specialAsset && $name === '')) {
            continue;
        }

        $ancestor = ancestorById($ancestors, $ancestorId);
        $ancestorLabelIds = [];
        if ($ancestor !== null) {
            $ancestorLabelIds = sanitizeParentLabelIds($labels, (array)($ancestor['label_ids'] ?? []));
        }

        $rawLabelValues = is_array($row['label_values'] ?? null)
            ? $row['label_values']
            : [];
        $labelValues = normalizeAssetLabelValues($rawLabelValues, $ancestorLabelIds, $labels);

        $items[] = [
            'id' => $id,
            'special_asset' => $specialAsset,
            'ancestor_id' => $ancestorId,
            'name' => $name,
            'code' => $code,
            'storage_id' => $storageId,
            'label_values' => $labelValues,
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? '')
        ];
    }
    return $items;
}

function storageNameExists(array $storages, string $name, string $except = ''): bool
{
    $needle = strtolower(clean($name));
    if ($needle === '') {
        return false;
    }
    foreach ($storages as $storage) {
        $id = (string)($storage['id'] ?? '');
        if ($except !== '' && $id === $except) {
            continue;
        }
        if (strtolower(clean((string)($storage['name'] ?? ''))) === $needle) {
            return true;
        }
    }
    return false;
}

function ancestorNameExists(array $ancestors, string $name, string $except = ''): bool
{
    $needle = strtolower(clean($name));
    if ($needle === '') {
        return false;
    }
    foreach ($ancestors as $ancestor) {
        $id = (string)($ancestor['id'] ?? '');
        if ($except !== '' && $id === $except) {
            continue;
        }
        if (strtolower(clean((string)($ancestor['name'] ?? ''))) === $needle) {
            return true;
        }
    }
    return false;
}

function labelNameExists(array $labels, string $name, string $except = ''): bool
{
    $needle = strtolower(clean($name));
    if ($needle === '') {
        return false;
    }
    foreach ($labels as $label) {
        $id = (string)($label['id'] ?? '');
        if ($except !== '' && $id === $except) {
            continue;
        }
        if (strtolower(clean((string)($label['name'] ?? ''))) === $needle) {
            return true;
        }
    }
    return false;
}

function assetCodeExists(array $assets, string $code, string $except = ''): bool
{
    $needle = strtolower(clean($code));
    if ($needle === '') {
        return false;
    }
    foreach ($assets as $asset) {
        $id = (string)($asset['id'] ?? '');
        if ($except !== '' && $id === $except) {
            continue;
        }
        if (strtolower(clean((string)($asset['code'] ?? ''))) === $needle) {
            return true;
        }
    }
    return false;
}

function storageUsed(array $assets, string $storageId): bool
{
    foreach ($assets as $asset) {
        if ((string)($asset['storage_id'] ?? '') === $storageId) {
            return true;
        }
    }
    return false;
}

function ancestorUsed(array $assets, string $ancestorId): bool
{
    foreach ($assets as $asset) {
        if ((string)($asset['ancestor_id'] ?? '') === $ancestorId) {
            return true;
        }
    }
    return false;
}

function labelUsedAsParent(array $labels, string $labelId): bool
{
    foreach ($labels as $label) {
        if ((string)($label['parent_id'] ?? '') === $labelId) {
            return true;
        }
    }
    return false;
}

function ancestorUsesLabel(array $ancestors, string $labelId): bool
{
    foreach ($ancestors as $ancestor) {
        foreach ((array)($ancestor['label_ids'] ?? []) as $ancestorLabelId) {
            if ((string)$ancestorLabelId === $labelId) {
                return true;
            }
        }
    }
    return false;
}

function assetUsesLabel(array $assets, string $labelId): bool
{
    foreach ($assets as $asset) {
        $labelValues = (array)($asset['label_values'] ?? []);
        foreach ($labelValues as $key => $value) {
            if ((string)$key === $labelId || (string)$value === $labelId) {
                return true;
            }
        }
    }
    return false;
}

function labelParentCreatesCycle(array $labels, string $labelId, string $newParentId): bool
{
    if ($newParentId === '' || $labelId === '') {
        return false;
    }
    if ($newParentId === $labelId) {
        return true;
    }
    $parentById = [];
    foreach ($labels as $label) {
        $parentById[(string)($label['id'] ?? '')] = (string)($label['parent_id'] ?? '');
    }

    $current = $newParentId;
    $guard = 0;
    while ($current !== '' && $guard < 2000) {
        if ($current === $labelId) {
            return true;
        }
        $current = $parentById[$current] ?? '';
        $guard++;
    }
    return false;
}

function writeAssetActionLog(string $action, string $message, array $context = []): void
{
    $payload = [
        'action' => $action,
        'timestamp' => gmdate('c'),
        'message' => $message
    ];
    foreach ($context as $key => $value) {
        if (!is_string($key) || $key === '') {
            continue;
        }
        if (is_scalar($value) || $value === null) {
            $payload[$key] = (string)$value;
        }
    }
    assetLogsAppend($payload);
}

function out(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function okData(
    array $storages,
    array $labels,
    array $ancestors,
    array $assets,
    bool $includePermissionPaneData = true
): array
{
    $users = $includePermissionPaneData ? loadSystemUsersForPermissions() : [];
    $storagePermissions = $includePermissionPaneData
        ? loadStoragePermissions($storages, $users)
        : [];
    $specialPermissions = $includePermissionPaneData
        ? loadSpecialPermissions($users)
        : [];
    return [
        'status' => 'ok',
        'storages' => array_values($storages),
        'labels' => array_values($labels),
        'ancestors' => array_values($ancestors),
        'assets' => array_values($assets),
        'users' => array_values($users),
        'storage_permissions' => $storagePermissions,
        'special_permissions' => $specialPermissions
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = clean((string)($_POST['action'] ?? ''));
    $requiredPermissionByAction = [
        'add_asset' => 'asset-manager:assets',
        'update_asset' => 'asset-manager:assets',
        'remove_asset' => 'asset-manager:assets',
        'add_storage' => 'asset-manager:storages',
        'update_storage' => 'asset-manager:storages',
        'remove_storage' => 'asset-manager:storages',
        'add_ancestor' => 'asset-manager:ancestors',
        'update_ancestor' => 'asset-manager:ancestors',
        'remove_ancestor' => 'asset-manager:ancestors',
        'add_label' => 'asset-manager:labels',
        'update_label' => 'asset-manager:labels',
        'remove_label' => 'asset-manager:labels',
        'update_storage_permissions' => 'asset-manager:permissions'
    ];
    if (isset($requiredPermissionByAction[$action])) {
        $requiredPermission = $requiredPermissionByAction[$action];
        if (!isset($allowedAssetChildPermissionSet[$requiredPermission])) {
            out([
                'status' => 'error',
                'message' => 'You do not have permission to perform this action.'
            ], 403);
        }
    }
    if ($action === 'load_data' && empty($allowedAssetChildPermissionSet)) {
        out([
            'status' => 'error',
            'message' => 'You do not have permission to access this section.'
        ], 403);
    }

    if ($action === 'load_asset_dashboard_metrics') {
        $labelsForMetrics = loadLabels();
        $ancestorsForMetrics = loadAncestors($labelsForMetrics);
        $assetsForMetrics = loadAssets($ancestorsForMetrics, $labelsForMetrics);
        out([
            'status' => 'ok',
            'asset_count' => count($assetsForMetrics),
            'recent_logs_count' => assetLogsCountRecentHours(24)
        ]);
    }

    if ($action === 'load_asset_logs') {
        $dayOffsetRaw = $_POST['day_offset'] ?? null;
        if ($dayOffsetRaw !== null && trim((string)$dayOffsetRaw) !== '') {
            $dayOffset = (int)$dayOffsetRaw;
            $window = assetLogsReadRecentDayWindow($dayOffset);
            out([
                'status' => 'ok',
                'logs' => $window['items'],
                'has_more' => (bool)($window['has_more'] ?? false),
                'next_day_offset' => (int)($window['next_day_offset'] ?? ($dayOffset + 1))
            ]);
        }

        $limit = (int)($_POST['limit'] ?? 30);
        $cursor = trim((string)($_POST['cursor'] ?? ''));
        $logsPage = assetLogsReadPage($limit, $cursor);
        out([
            'status' => 'ok',
            'logs' => $logsPage['items'],
            'next_cursor' => (string)($logsPage['next_cursor'] ?? '')
        ]);
    }

    $storages = loadStorages();
    $labels = loadLabels();
    $ancestors = loadAncestors($labels);
    $assets = loadAssets($ancestors, $labels);

    if ($action === 'load_data') {
        out(okData($storages, $labels, $ancestors, $assets, $canManageAssetPermissions));
    }

    if ($action === 'add_storage') {
        $name = clean((string)($_POST['name'] ?? ''));
        $kindRaw = strtolower(trim((string)($_POST['kind'] ?? '')));
        $kind = $kindRaw === '' ? STORAGE_KIND_BRANCH : $kindRaw;
        if ($name === '') {
            out(['status' => 'error', 'message' => 'Storage name is required.'], 422);
        }
        if (!isValidStorageKind($kind)) {
            out(['status' => 'error', 'message' => 'Storage kind is invalid.'], 422);
        }
        if (storageNameExists($storages, $name)) {
            out(['status' => 'error', 'message' => 'Storage name must be unique.'], 422);
        }
        $now = date('c');
        $storages[] = [
            'id' => bin2hex(random_bytes(8)),
            'name' => $name,
            'kind' => $kind,
            'created_at' => $now,
            'updated_at' => $now
        ];
        if (!writeArray(STORAGES_FILE, $storages)) {
            out(['status' => 'error', 'message' => 'Unable to save storage.'], 500);
        }
        out(okData($storages, $labels, $ancestors, $assets, $canManageAssetPermissions));
    }

    if ($action === 'update_storage') {
        $id = trim((string)($_POST['id'] ?? ''));
        $field = trim((string)($_POST['field'] ?? 'name'));
        $value = clean((string)($_POST['value'] ?? ''));
        if ($id === '' || $field === '') {
            out(['status' => 'error', 'message' => 'Invalid storage update.'], 422);
        }
        $idx = idxById($storages, $id);
        if ($idx < 0) {
            out(['status' => 'error', 'message' => 'Storage not found.'], 404);
        }
        if ($field === 'name') {
            if ($value === '') {
                out(['status' => 'error', 'message' => 'Invalid storage update.'], 422);
            }
            if (storageNameExists($storages, $value, $id)) {
                out(['status' => 'error', 'message' => 'Storage name must be unique.'], 422);
            }
            $storages[$idx]['name'] = $value;
        } elseif ($field === 'kind') {
            $kind = strtolower(trim((string)($_POST['value'] ?? '')));
            if (!isValidStorageKind($kind)) {
                out(['status' => 'error', 'message' => 'Storage kind is invalid.'], 422);
            }
            $storages[$idx]['kind'] = $kind;
        } else {
            out(['status' => 'error', 'message' => 'Invalid storage update field.'], 422);
        }
        $storages[$idx]['updated_at'] = date('c');
        if (!writeArray(STORAGES_FILE, $storages)) {
            out(['status' => 'error', 'message' => 'Unable to save storage changes.'], 500);
        }
        out(okData($storages, $labels, $ancestors, $assets, $canManageAssetPermissions));
    }

    if ($action === 'remove_storage') {
        $id = trim((string)($_POST['id'] ?? ''));
        if ($id === '') {
            out(['status' => 'error', 'message' => 'Storage id is required.'], 422);
        }
        if (storageUsed($assets, $id)) {
            out(['status' => 'error', 'message' => 'Storage is used by assets and cannot be removed.'], 422);
        }
        $next = [];
        $removed = false;
        foreach ($storages as $storage) {
            if ((string)($storage['id'] ?? '') === $id) {
                $removed = true;
                continue;
            }
            $next[] = $storage;
        }
        if (!$removed) {
            out(['status' => 'error', 'message' => 'Storage not found.'], 404);
        }
        if (!writeArray(STORAGES_FILE, $next)) {
            out(['status' => 'error', 'message' => 'Unable to remove storage.'], 500);
        }
        $usersForPermissions = loadSystemUsersForPermissions();
        $permissions = loadStoragePermissions($next, $usersForPermissions);
        if (!writeStoragePermissions($permissions)) {
            out(['status' => 'error', 'message' => 'Unable to update storage permissions.'], 500);
        }
        out(okData($next, $labels, $ancestors, $assets, $canManageAssetPermissions));
    }

    if ($action === 'update_storage_permissions') {
        $userCode = clean((string)($_POST['user_code'] ?? ''));
        if ($userCode === '') {
            out(['status' => 'error', 'message' => 'User code is required.'], 422);
        }

        $usersForPermissions = loadSystemUsersForPermissions();
        if (!systemUserExistsByCode($usersForPermissions, $userCode)) {
            out(['status' => 'error', 'message' => 'User not found.'], 404);
        }

        $storageIds = parseJsonArrayRaw((string)($_POST['storage_ids'] ?? '[]'));
        foreach ($storageIds as $storageId) {
            if (!storageExists($storages, $storageId)) {
                out(['status' => 'error', 'message' => 'Selected storage is invalid.'], 422);
            }
        }

        $permissions = loadStoragePermissions($storages, $usersForPermissions);
        $permissions[$userCode] = $storageIds;
        if (!writeStoragePermissions($permissions)) {
            out(['status' => 'error', 'message' => 'Unable to save storage permissions.'], 500);
        }

        if (array_key_exists('board_member', $_POST)) {
            $specialPermissions = loadSpecialPermissions($usersForPermissions);
            $boardMemberCodes = uniqueNonEmptyStrings((array)($specialPermissions[SPECIAL_PERMISSION_BOARD_MEMBER] ?? []));
            $isBoardMember = parseBool($_POST['board_member'] ?? false);
            if ($isBoardMember) {
                if (!in_array($userCode, $boardMemberCodes, true)) {
                    $boardMemberCodes[] = $userCode;
                }
            } else {
                $boardMemberCodes = array_values(array_filter($boardMemberCodes, function (string $code) use ($userCode): bool {
                    return $code !== $userCode;
                }));
            }
            $specialPermissions[SPECIAL_PERMISSION_BOARD_MEMBER] = $boardMemberCodes;
            if (!writeSpecialPermissions($specialPermissions)) {
                out(['status' => 'error', 'message' => 'Unable to save special permissions.'], 500);
            }
        }

        out(okData($storages, $labels, $ancestors, $assets, $canManageAssetPermissions));
    }

    if ($action === 'add_label') {
        $name = clean((string)($_POST['name'] ?? ''));
        $parentId = trim((string)($_POST['parent_id'] ?? ''));
        if ($name === '') {
            out(['status' => 'error', 'message' => 'Label name is required.'], 422);
        }
        if (labelNameExists($labels, $name)) {
            out(['status' => 'error', 'message' => 'Label name must be unique.'], 422);
        }
        if ($parentId !== '' && !labelExists($labels, $parentId)) {
            out(['status' => 'error', 'message' => 'Selected parent label is invalid.'], 422);
        }

        $now = date('c');
        $labels[] = [
            'id' => bin2hex(random_bytes(8)),
            'name' => $name,
            'parent_id' => $parentId,
            'created_at' => $now,
            'updated_at' => $now
        ];
        if (!writeArray(LABELS_FILE, $labels)) {
            out(['status' => 'error', 'message' => 'Unable to save label.'], 500);
        }

        $ancestors = loadAncestors($labels);
        $assets = loadAssets($ancestors, $labels);
        out(okData($storages, $labels, $ancestors, $assets, $canManageAssetPermissions));
    }

    if ($action === 'update_label') {
        $id = trim((string)($_POST['id'] ?? ''));
        $field = trim((string)($_POST['field'] ?? ''));
        $value = trim((string)($_POST['value'] ?? ''));
        if ($id === '') {
            out(['status' => 'error', 'message' => 'Label id is required.'], 422);
        }
        $idx = idxById($labels, $id);
        if ($idx < 0) {
            out(['status' => 'error', 'message' => 'Label not found.'], 404);
        }

        if ($field === 'name') {
            $name = clean($value);
            if ($name === '') {
                out(['status' => 'error', 'message' => 'Label name is required.'], 422);
            }
            if (labelNameExists($labels, $name, $id)) {
                out(['status' => 'error', 'message' => 'Label name must be unique.'], 422);
            }
            $labels[$idx]['name'] = $name;
        } elseif ($field === 'parent_id') {
            $parentId = trim($value);
            if ($parentId !== '' && !labelExists($labels, $parentId)) {
                out(['status' => 'error', 'message' => 'Selected parent label is invalid.'], 422);
            }
            if (labelParentCreatesCycle($labels, $id, $parentId)) {
                out(['status' => 'error', 'message' => 'Label parent cannot create a cycle.'], 422);
            }
            $labels[$idx]['parent_id'] = $parentId;
        } else {
            out(['status' => 'error', 'message' => 'Invalid label field.'], 422);
        }

        $labels[$idx]['updated_at'] = date('c');
        if (!writeArray(LABELS_FILE, $labels)) {
            out(['status' => 'error', 'message' => 'Unable to save label changes.'], 500);
        }

        $ancestors = loadAncestors($labels);
        $assets = loadAssets($ancestors, $labels);
        out(okData($storages, $labels, $ancestors, $assets, $canManageAssetPermissions));
    }

    if ($action === 'remove_label') {
        $id = trim((string)($_POST['id'] ?? ''));
        if ($id === '') {
            out(['status' => 'error', 'message' => 'Label id is required.'], 422);
        }
        if (labelUsedAsParent($labels, $id)) {
            out(['status' => 'error', 'message' => 'Label has child labels and cannot be removed.'], 422);
        }
        if (ancestorUsesLabel($ancestors, $id) || assetUsesLabel($assets, $id)) {
            out(['status' => 'error', 'message' => 'Label is used and cannot be removed.'], 422);
        }

        $next = [];
        $removed = false;
        foreach ($labels as $label) {
            if ((string)($label['id'] ?? '') === $id) {
                $removed = true;
                continue;
            }
            $next[] = $label;
        }
        if (!$removed) {
            out(['status' => 'error', 'message' => 'Label not found.'], 404);
        }

        if (!writeArray(LABELS_FILE, $next)) {
            out(['status' => 'error', 'message' => 'Unable to remove label.'], 500);
        }

        $labels = loadLabels();
        $ancestors = loadAncestors($labels);
        $assets = loadAssets($ancestors, $labels);
        out(okData($storages, $labels, $ancestors, $assets, $canManageAssetPermissions));
    }

    if ($action === 'add_ancestor') {
        $name = clean((string)($_POST['name'] ?? ''));
        $labelIds = parseJsonArrayRaw((string)($_POST['label_ids'] ?? '[]'));
        foreach ($labelIds as $labelId) {
            if (!labelExists($labels, $labelId) || !labelHasChildren($labels, $labelId)) {
                out(['status' => 'error', 'message' => 'Only parent labels that have children can be selected.'], 422);
            }
        }
        $labelIds = sanitizeParentLabelIds($labels, $labelIds);
        if ($name === '') {
            out(['status' => 'error', 'message' => 'Ancestor asset name is required.'], 422);
        }
        if (ancestorNameExists($ancestors, $name)) {
            out(['status' => 'error', 'message' => 'Ancestor asset name must be unique.'], 422);
        }
        $now = date('c');
        $ancestors[] = [
            'id' => bin2hex(random_bytes(8)),
            'name' => $name,
            'label_ids' => $labelIds,
            'created_at' => $now,
            'updated_at' => $now
        ];
        if (!writeArray(ANCESTOR_ASSETS_FILE, $ancestors)) {
            out(['status' => 'error', 'message' => 'Unable to save ancestor asset.'], 500);
        }

        $assets = loadAssets($ancestors, $labels);
        out(okData($storages, $labels, $ancestors, $assets, $canManageAssetPermissions));
    }

    if ($action === 'update_ancestor') {
        $id = trim((string)($_POST['id'] ?? ''));
        $field = trim((string)($_POST['field'] ?? 'name'));
        if ($id === '') {
            out(['status' => 'error', 'message' => 'Ancestor asset id is required.'], 422);
        }
        $idx = idxById($ancestors, $id);
        if ($idx < 0) {
            out(['status' => 'error', 'message' => 'Ancestor asset not found.'], 404);
        }

        if ($field === '' || $field === 'name') {
            $name = clean((string)($_POST['value'] ?? ''));
            if ($name === '') {
                out(['status' => 'error', 'message' => 'Invalid ancestor asset update.'], 422);
            }
            if (ancestorNameExists($ancestors, $name, $id)) {
                out(['status' => 'error', 'message' => 'Ancestor asset name must be unique.'], 422);
            }
            $ancestors[$idx]['name'] = $name;
        } elseif ($field === 'label_ids') {
            $labelIds = parseJsonArrayRaw((string)($_POST['value'] ?? '[]'));
            foreach ($labelIds as $labelId) {
                if (!labelExists($labels, $labelId) || !labelHasChildren($labels, $labelId)) {
                    out(['status' => 'error', 'message' => 'Only parent labels that have children can be selected.'], 422);
                }
            }
            $ancestors[$idx]['label_ids'] = sanitizeParentLabelIds($labels, $labelIds);
        } else {
            out(['status' => 'error', 'message' => 'Invalid ancestor asset field.'], 422);
        }

        $ancestors[$idx]['updated_at'] = date('c');
        if (!writeArray(ANCESTOR_ASSETS_FILE, $ancestors)) {
            out(['status' => 'error', 'message' => 'Unable to save ancestor asset changes.'], 500);
        }

        $assets = loadAssets($ancestors, $labels);
        out(okData($storages, $labels, $ancestors, $assets, $canManageAssetPermissions));
    }

    if ($action === 'remove_ancestor') {
        $id = trim((string)($_POST['id'] ?? ''));
        if ($id === '') {
            out(['status' => 'error', 'message' => 'Ancestor asset id is required.'], 422);
        }
        if (ancestorUsed($assets, $id)) {
            out(['status' => 'error', 'message' => 'Ancestor asset is used by assets and cannot be removed.'], 422);
        }
        $next = [];
        $removed = false;
        foreach ($ancestors as $ancestor) {
            if ((string)($ancestor['id'] ?? '') === $id) {
                $removed = true;
                continue;
            }
            $next[] = $ancestor;
        }
        if (!$removed) {
            out(['status' => 'error', 'message' => 'Ancestor asset not found.'], 404);
        }
        if (!writeArray(ANCESTOR_ASSETS_FILE, $next)) {
            out(['status' => 'error', 'message' => 'Unable to remove ancestor asset.'], 500);
        }
        out(okData($storages, $labels, $next, $assets, $canManageAssetPermissions));
    }

    if ($action === 'add_asset') {
        $specialAsset = parseBool($_POST['special_asset'] ?? false);
        $name = clean((string)($_POST['name'] ?? ''));
        $ancestorId = trim((string)($_POST['ancestor_id'] ?? ''));
        $code = clean((string)($_POST['code'] ?? ''));
        $storageId = trim((string)($_POST['storage_id'] ?? ''));
        $rawLabelValues = parseJsonMapRaw((string)($_POST['label_values'] ?? '{}'));

        if ($code === '' || $storageId === '') {
            out(['status' => 'error', 'message' => 'All asset fields are required.'], 422);
        }

        $ancestorLabelIds = [];
        if ($specialAsset) {
            if ($name === '') {
                out(['status' => 'error', 'message' => 'Special asset name is required.'], 422);
            }
        } else {
            if ($ancestorId === '') {
                out(['status' => 'error', 'message' => 'All asset fields are required.'], 422);
            }
            $ancestor = ancestorById($ancestors, $ancestorId);
            if ($ancestor === null) {
                out(['status' => 'error', 'message' => 'Selected ancestor asset is invalid.'], 422);
            }
            $name = ancestorNameById($ancestors, $ancestorId);
            $ancestorLabelIds = sanitizeParentLabelIds($labels, (array)($ancestor['label_ids'] ?? []));
        }

        if (!storageExists($storages, $storageId)) {
            out(['status' => 'error', 'message' => 'Selected storage is invalid.'], 422);
        }
        if (assetCodeExists($assets, $code)) {
            out(['status' => 'error', 'message' => 'Asset code must be unique.'], 422);
        }

        $labelValues = $specialAsset
            ? []
            : normalizeAssetLabelValues($rawLabelValues, $ancestorLabelIds, $labels);

        $now = date('c');
        $assets[] = [
            'id' => bin2hex(random_bytes(8)),
            'special_asset' => $specialAsset,
            'ancestor_id' => $specialAsset ? '' : $ancestorId,
            'name' => $name,
            'code' => $code,
            'storage_id' => $storageId,
            'label_values' => $labelValues,
            'created_at' => $now,
            'updated_at' => $now
        ];
        if (!writeArray(ASSETS_FILE, $assets)) {
            out(['status' => 'error', 'message' => 'Unable to save asset.'], 500);
        }

        $savedAsset = $assets[count($assets) - 1] ?? [];
        $actor = currentActorDisplayName();
        $assetName = assetNameForLog($savedAsset);
        $assetCode = assetCodeForLog($savedAsset);
        $storageName = storageNameById($storages, $storageId) ?: 'نامشخص';
        $assetCodeText = $assetCode !== '' ? $assetCode : 'بدون کد';
        writeAssetActionLog(
            'asset_created',
            sprintf(
                'مال %s با کد %s توسط کاربر %s در انبار %s ثبت شد',
                $assetName,
                $assetCodeText,
                $actor,
                $storageName
            ),
            [
                'asset_id' => (string)($savedAsset['id'] ?? ''),
                'asset_code' => $assetCode,
                'actor' => $actor,
                'storage_id' => $storageId
            ]
        );

        out(okData($storages, $labels, $ancestors, $assets, $canManageAssetPermissions));
    }

    if ($action === 'update_asset') {
        $id = trim((string)($_POST['id'] ?? ''));
        $field = trim((string)($_POST['field'] ?? ''));
        $value = trim((string)($_POST['value'] ?? ''));
        if ($id === '') {
            out(['status' => 'error', 'message' => 'Invalid asset update.'], 422);
        }
        if (!in_array($field, ['ancestor_id', 'name', 'code', 'storage_id', 'label_values'], true)) {
            out(['status' => 'error', 'message' => 'Invalid asset field.'], 422);
        }
        if ($field !== 'label_values' && $value === '') {
            out(['status' => 'error', 'message' => 'Invalid asset update.'], 422);
        }
        $idx = idxById($assets, $id);
        if ($idx < 0) {
            out(['status' => 'error', 'message' => 'Asset not found.'], 404);
        }
        $beforeAsset = $assets[$idx];

        $isSpecialAsset = parseBool($assets[$idx]['special_asset'] ?? false);
        if ($field === 'code' && assetCodeExists($assets, clean($value), $id)) {
            out(['status' => 'error', 'message' => 'Asset code must be unique.'], 422);
        }
        if ($field === 'storage_id' && !storageExists($storages, $value)) {
            out(['status' => 'error', 'message' => 'Selected storage is invalid.'], 422);
        }
        if ($field === 'ancestor_id') {
            if ($isSpecialAsset) {
                out(['status' => 'error', 'message' => 'Special asset cannot use ancestor selection.'], 422);
            }
            if (!ancestorExists($ancestors, $value)) {
                out(['status' => 'error', 'message' => 'Selected ancestor asset is invalid.'], 422);
            }
        }
        if ($field === 'name' && !$isSpecialAsset) {
            out(['status' => 'error', 'message' => 'Only special assets can use custom text names.'], 422);
        }

        if ($field === 'ancestor_id') {
            $assets[$idx]['ancestor_id'] = $value;
            $assets[$idx]['name'] = ancestorNameById($ancestors, $value);
            $ancestor = ancestorById($ancestors, $value);
            $ancestorLabelIds = sanitizeParentLabelIds($labels, (array)($ancestor['label_ids'] ?? []));
            $assets[$idx]['label_values'] = normalizeAssetLabelValues([], $ancestorLabelIds, $labels);
        } elseif ($field === 'label_values') {
            if ($isSpecialAsset) {
                $assets[$idx]['label_values'] = [];
            } else {
                $currentAncestorId = (string)($assets[$idx]['ancestor_id'] ?? '');
                $ancestor = ancestorById($ancestors, $currentAncestorId);
                $ancestorLabelIds = sanitizeParentLabelIds($labels, (array)($ancestor['label_ids'] ?? []));
                $mapValue = parseJsonMapRaw($value);
                $assets[$idx]['label_values'] = normalizeAssetLabelValues($mapValue, $ancestorLabelIds, $labels);
            }
        } else {
            $assets[$idx][$field] = clean($value);
        }

        $assets[$idx]['updated_at'] = date('c');
        if (!writeArray(ASSETS_FILE, $assets)) {
            out(['status' => 'error', 'message' => 'Unable to save asset changes.'], 500);
        }

        $afterAsset = $assets[$idx];
        $actor = currentActorDisplayName();
        $assetName = assetNameForLog($afterAsset);
        $assetCode = assetCodeForLog($afterAsset);
        $assetCodeText = $assetCode !== '' ? $assetCode : 'بدون کد';

        if ($field === 'storage_id') {
            $beforeStorageId = trim((string)($beforeAsset['storage_id'] ?? ''));
            $afterStorageId = trim((string)($afterAsset['storage_id'] ?? ''));
            if ($beforeStorageId !== $afterStorageId) {
                $fromStorageName = storageNameById($storages, $beforeStorageId) ?: 'نامشخص';
                $toStorageName = storageNameById($storages, $afterStorageId) ?: 'نامشخص';
                writeAssetActionLog(
                    'asset_transferred',
                    sprintf(
                        'مال %s از انبار %s توسط کاربر %s به انبار %s منتقل شد',
                        $assetName,
                        $fromStorageName,
                        $actor,
                        $toStorageName
                    ),
                    [
                        'asset_id' => $id,
                        'asset_code' => $assetCode,
                        'actor' => $actor,
                        'from_storage_id' => $beforeStorageId,
                        'to_storage_id' => $afterStorageId
                    ]
                );
            }
        } else {
            $beforeComparable = $field === 'label_values'
                ? json_encode((array)($beforeAsset['label_values'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : clean((string)($beforeAsset[$field] ?? ''));
            $afterComparable = $field === 'label_values'
                ? json_encode((array)($afterAsset['label_values'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : clean((string)($afterAsset[$field] ?? ''));

            if ((string)$beforeComparable !== (string)$afterComparable) {
                writeAssetActionLog(
                    'asset_updated',
                    sprintf(
                        'اطلاعات مال %s با کد %s توسط کاربر %s ویرایش شد',
                        $assetName,
                        $assetCodeText,
                        $actor
                    ),
                    [
                        'asset_id' => $id,
                        'asset_code' => $assetCode,
                        'actor' => $actor,
                        'field' => $field
                    ]
                );
            }
        }

        out(okData($storages, $labels, $ancestors, $assets, $canManageAssetPermissions));
    }

    if ($action === 'remove_asset') {
        $id = trim((string)($_POST['id'] ?? ''));
        if ($id === '') {
            out(['status' => 'error', 'message' => 'Asset id is required.'], 422);
        }
        $next = [];
        $removed = false;
        $removedAsset = null;
        foreach ($assets as $asset) {
            if ((string)($asset['id'] ?? '') === $id) {
                $removed = true;
                $removedAsset = $asset;
                continue;
            }
            $next[] = $asset;
        }
        if (!$removed) {
            out(['status' => 'error', 'message' => 'Asset not found.'], 404);
        }
        if (!writeArray(ASSETS_FILE, $next)) {
            out(['status' => 'error', 'message' => 'Unable to remove asset.'], 500);
        }

        if (is_array($removedAsset)) {
            $actor = currentActorDisplayName();
            $assetName = assetNameForLog($removedAsset);
            $assetCode = assetCodeForLog($removedAsset);
            $assetCodeText = $assetCode !== '' ? $assetCode : 'بدون کد';
            writeAssetActionLog(
                'asset_removed',
                sprintf(
                    'مال %s با کد %s توسط کاربر %s حذف شد',
                    $assetName,
                    $assetCodeText,
                    $actor
                ),
                [
                    'asset_id' => $id,
                    'asset_code' => $assetCode,
                    'actor' => $actor
                ]
            );
        }

        out(okData($storages, $labels, $ancestors, $next, $canManageAssetPermissions));
    }

    out(['status' => 'error', 'message' => 'Unsupported action.'], 400);
}

$storages = loadStorages();
$labels = loadLabels();
$ancestors = loadAncestors($labels);
$assets = loadAssets($ancestors, $labels);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Assets Manager</title>
  <style>
    :root { font-family: "Segoe UI", Tahoma, Arial, sans-serif; }
    * { box-sizing: border-box; }
    body { margin: 0; background: #f3f6fb; color: #111827; }
    .layout { max-width: 1200px; margin: 0 auto; min-height: 100vh; display: grid; grid-template-columns: 250px 1fr; gap: 18px; padding: 18px; }
    .sidebar, .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; box-shadow: 0 10px 24px rgba(16,24,40,.06); }
    .sidebar { padding: 14px; height: fit-content; }
    .sidebar h1 { margin: 0 0 12px; font-size: 1rem; }
    .nav { display: grid; gap: 8px; }
    .nav button { border: 1px solid #d1d5db; border-radius: 9px; background: #fff; padding: 10px 12px; text-align: left; cursor: pointer; }
    .nav button.active { background: #1d4ed8; border-color: #1d4ed8; color: #fff; }
    .content { display: grid; gap: 14px; }
    .pane { display: none; gap: 14px; }
    .pane.active { display: grid; }
    .card { padding: 16px; }
    h2 { margin: 0 0 12px; font-size: 1.1rem; }
    .grid { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 10px; }
    .grid.one { grid-template-columns: 1fr; }
    .field { display: grid; gap: 6px; }
    .field label { color: #4b5563; font-size: .9rem; }
    input, select { width: 100%; border: 1px solid #d1d5db; border-radius: 8px; padding: 10px 11px; font-size: .95rem; }
    input:focus, select:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.14); }
    .actions { margin-top: 12px; display: flex; gap: 10px; align-items: center; }
    .btn { border: 0; border-radius: 8px; padding: 9px 14px; font-size: .92rem; cursor: pointer; }
    .primary { background: #2563eb; color: #fff; }
    .danger { background: #ef4444; color: #fff; padding: 8px 12px; }
    .status { font-size: .9rem; color: #374151; }
    .status.error { color: #b91c1c; }
    .table-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; border-bottom: 1px solid #eef2f7; padding: 10px 8px; white-space: nowrap; }
    th { color: #6b7280; font-size: .85rem; }
    td input, td select { min-width: 180px; }
    .empty { color: #6b7280; }
    .hidden { display: none !important; }
    .switch-row { display: flex; align-items: center; gap: 8px; min-height: 42px; }
    .switch-row input[type="checkbox"] { width: 18px; height: 18px; margin: 0; border-radius: 4px; box-shadow: none; padding: 0; }
    @media (max-width: 960px) { .layout { grid-template-columns: 1fr; } }
    @media (max-width: 820px) { .grid { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <div class="layout">
    <aside class="sidebar">
      <h1>assets manager</h1>
      <div class="nav">
        <button class="active" data-pane-target="assets-pane" type="button">add assets</button>
        <button data-pane-target="storages-pane" type="button">add storage</button>
        <button data-pane-target="ancestors-pane" type="button">Add Ancestor Assets</button>
      </div>
    </aside>

    <main class="content">
      <section class="pane active" data-pane="assets-pane">
        <section class="card">
          <h2>Add Assets</h2>
          <form id="add-asset-form">
            <div class="grid">
              <div class="field">
                <label for="asset-special">Special Asset</label>
                <div class="switch-row">
                  <input id="asset-special" name="special_asset" type="checkbox" />
                  <span>Enable custom asset text</span>
                </div>
              </div>
              <div class="field" id="asset-ancestor-field"><label for="asset-ancestor">Ancestor Asset</label><select id="asset-ancestor" name="ancestor_id" required></select></div>
              <div class="field hidden" id="asset-special-name-field"><label for="asset-special-name">Special Asset Name</label><input id="asset-special-name" name="name" type="text" disabled /></div>
              <div class="field"><label for="asset-code">Asset Code</label><input id="asset-code" name="code" type="text" required /></div>
              <div class="field"><label for="asset-storage">Storage</label><select id="asset-storage" name="storage_id" required></select></div>
            </div>
            <div class="actions">
              <button class="btn primary" type="submit">Add Asset</button>
              <span id="asset-status" class="status" aria-live="polite"></span>
            </div>
          </form>
        </section>
        <section class="card">
          <h2>Assets</h2>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Asset / Ancestor</th><th>Asset Code</th><th>Storage</th><th>Action</th></tr></thead>
              <tbody id="assets-body"></tbody>
            </table>
          </div>
        </section>
      </section>

      <section class="pane" data-pane="storages-pane">
        <section class="card">
          <h2>Add Storage</h2>
          <form id="add-storage-form">
            <div class="grid one">
              <div class="field"><label for="storage-name">Storage Name</label><input id="storage-name" name="name" type="text" required /></div>
              <div class="field">
                <label for="storage-kind">Storage Kind</label>
                <select id="storage-kind" name="kind" required>
                  <option value="branch">Branch</option>
                  <option value="person">Person</option>
                  <option value="repair_shop">Repair Shop</option>
                </select>
              </div>
            </div>
            <div class="actions">
              <button class="btn primary" type="submit">Add Storage</button>
              <span id="storage-status" class="status" aria-live="polite"></span>
            </div>
          </form>
        </section>
        <section class="card">
          <h2>Storages</h2>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Storage Name</th><th>Storage Kind</th><th>Action</th></tr></thead>
              <tbody id="storages-body"></tbody>
            </table>
          </div>
        </section>
      </section>

      <section class="pane" data-pane="ancestors-pane">
        <section class="card">
          <h2>Add Ancestor Asset</h2>
          <form id="add-ancestor-form">
            <div class="grid one">
              <div class="field"><label for="ancestor-name">Ancestor Asset Name</label><input id="ancestor-name" name="name" type="text" required /></div>
            </div>
            <div class="actions">
              <button class="btn primary" type="submit">Add Ancestor Asset</button>
              <span id="ancestor-status" class="status" aria-live="polite"></span>
            </div>
          </form>
        </section>
        <section class="card">
          <h2>Ancestor Assets</h2>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Ancestor Asset Name</th><th>Action</th></tr></thead>
              <tbody id="ancestors-body"></tbody>
            </table>
          </div>
        </section>
      </section>
    </main>
  </div>

  <script>
    const state = {
      storages: <?= json_encode($storages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      ancestors: <?= json_encode($ancestors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      assets: <?= json_encode($assets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    };
    const STORAGE_KIND_OPTIONS = [
      { value: "branch", label: "Branch" },
      { value: "person", label: "Person" },
      { value: "repair_shop", label: "Repair Shop" }
    ];
    const DEFAULT_STORAGE_KIND = STORAGE_KIND_OPTIONS[0].value;

    const paneButtons = [...document.querySelectorAll("[data-pane-target]")];
    const panes = [...document.querySelectorAll("[data-pane]")];
    const assetForm = document.getElementById("add-asset-form");
    const storageForm = document.getElementById("add-storage-form");
    const ancestorForm = document.getElementById("add-ancestor-form");
    const assetStatus = document.getElementById("asset-status");
    const storageStatus = document.getElementById("storage-status");
    const ancestorStatus = document.getElementById("ancestor-status");
    const assetSpecialToggle = document.getElementById("asset-special");
    const assetAncestorField = document.getElementById("asset-ancestor-field");
    const assetSpecialNameField = document.getElementById("asset-special-name-field");
    const assetAncestorSelect = document.getElementById("asset-ancestor");
    const assetSpecialNameInput = document.getElementById("asset-special-name");
    const assetStorageSelect = document.getElementById("asset-storage");
    const storageKindSelect = document.getElementById("storage-kind");
    const assetsBody = document.getElementById("assets-body");
    const storagesBody = document.getElementById("storages-body");
    const ancestorsBody = document.getElementById("ancestors-body");

    function setStatus(el, msg, isError = false) {
      el.textContent = msg || "";
      el.classList.toggle("error", !!isError);
    }

    function setPane(paneId) {
      paneButtons.forEach((b) => b.classList.toggle("active", b.dataset.paneTarget === paneId));
      panes.forEach((p) => p.classList.toggle("active", p.dataset.pane === paneId));
    }

    function isSpecialAsset(item) {
      return item?.special_asset === true || String(item?.special_asset || "") === "1";
    }

    function normalizeStorageKind(kind) {
      const value = String(kind || "").trim();
      return STORAGE_KIND_OPTIONS.some((option) => option.value === value)
        ? value
        : DEFAULT_STORAGE_KIND;
    }

    function fillStorageKindOptions(select, selected = DEFAULT_STORAGE_KIND) {
      if (!select) return;
      const value = normalizeStorageKind(selected);
      select.innerHTML = "";
      STORAGE_KIND_OPTIONS.forEach((optionData) => {
        const option = document.createElement("option");
        option.value = optionData.value;
        option.textContent = optionData.label;
        if (option.value === value) option.selected = true;
        select.appendChild(option);
      });
    }

    function setAssetSpecialMode(isSpecial) {
      assetAncestorField?.classList.toggle("hidden", isSpecial);
      assetSpecialNameField?.classList.toggle("hidden", !isSpecial);
      if (assetAncestorSelect) {
        assetAncestorSelect.required = !isSpecial;
        if (isSpecial) {
          assetAncestorSelect.value = "";
          assetAncestorSelect.disabled = true;
        } else {
          assetAncestorSelect.disabled = false;
          if (!assetAncestorSelect.value) {
            fillAncestorOptions(assetAncestorSelect, "");
          }
        }
      }
      if (assetSpecialNameInput) {
        assetSpecialNameInput.disabled = !isSpecial;
        assetSpecialNameInput.required = isSpecial;
        if (!isSpecial) {
          assetSpecialNameInput.value = "";
        }
      }
    }

    function fillStorageOptions(select, selected = "") {
      if (!select) return;
      const value = String(selected || "");
      select.innerHTML = "";
      const empty = document.createElement("option");
      empty.value = "";
      empty.textContent = state.storages.length ? "Select storage" : "No storage available";
      select.appendChild(empty);
      state.storages.forEach((s) => {
        const o = document.createElement("option");
        o.value = String(s.id || "");
        o.textContent = String(s.name || "");
        if (o.value === value) o.selected = true;
        select.appendChild(o);
      });
      select.disabled = state.storages.length === 0;
    }

    function fillAncestorOptions(select, selected = "") {
      if (!select) return;
      const value = String(selected || "");
      select.innerHTML = "";
      const empty = document.createElement("option");
      empty.value = "";
      empty.textContent = state.ancestors.length ? "Select ancestor asset" : "No ancestor asset available";
      select.appendChild(empty);
      state.ancestors.forEach((a) => {
        const o = document.createElement("option");
        o.value = String(a.id || "");
        o.textContent = String(a.name || "");
        if (o.value === value) o.selected = true;
        select.appendChild(o);
      });
      select.disabled = state.ancestors.length === 0;
    }

    function renderAssets() {
      assetsBody.innerHTML = "";
      if (!state.assets.length) {
        const tr = document.createElement("tr");
        tr.innerHTML = '<td class="empty" colspan="4">No assets added yet.</td>';
        assetsBody.appendChild(tr);
        return;
      }
      state.assets.forEach((p) => {
        const tr = document.createElement("tr");
        tr.dataset.id = String(p.id || "");
        const ancestorCell = document.createElement("td");
        if (isSpecialAsset(p)) {
          const specialNameInput = document.createElement("input");
          specialNameInput.type = "text";
          specialNameInput.dataset.field = "name";
          specialNameInput.value = String(p.name || "");
          ancestorCell.appendChild(specialNameInput);
        } else {
          const ancestorSelect = document.createElement("select");
          ancestorSelect.dataset.field = "ancestor_id";
          fillAncestorOptions(ancestorSelect, String(p.ancestor_id || ""));
          ancestorCell.appendChild(ancestorSelect);
        }

        const codeCell = document.createElement("td");
        const codeInput = document.createElement("input");
        codeInput.type = "text";
        codeInput.dataset.field = "code";
        codeInput.value = String(p.code || "");
        codeCell.appendChild(codeInput);

        const storageCell = document.createElement("td");
        const storageSelect = document.createElement("select");
        storageSelect.dataset.field = "storage_id";
        fillStorageOptions(storageSelect, String(p.storage_id || ""));
        storageCell.appendChild(storageSelect);

        const actionCell = document.createElement("td");
        const remove = document.createElement("button");
        remove.type = "button";
        remove.className = "btn danger";
        remove.dataset.action = "remove-asset";
        remove.textContent = "Remove";
        actionCell.appendChild(remove);

        tr.append(ancestorCell, codeCell, storageCell, actionCell);
        assetsBody.appendChild(tr);
      });
    }

    function renderStorages() {
      storagesBody.innerHTML = "";
      if (!state.storages.length) {
        const tr = document.createElement("tr");
        tr.innerHTML = '<td class="empty" colspan="3">No storages added yet.</td>';
        storagesBody.appendChild(tr);
        return;
      }
      state.storages.forEach((s) => {
        const tr = document.createElement("tr");
        tr.dataset.id = String(s.id || "");
        const nameCell = document.createElement("td");
        const nameInput = document.createElement("input");
        nameInput.type = "text";
        nameInput.dataset.field = "name";
        nameInput.value = String(s.name || "");
        nameCell.appendChild(nameInput);

        const kindCell = document.createElement("td");
        const kindSelect = document.createElement("select");
        kindSelect.dataset.field = "kind";
        fillStorageKindOptions(kindSelect, s.kind);
        kindCell.appendChild(kindSelect);

        const actionCell = document.createElement("td");
        const remove = document.createElement("button");
        remove.type = "button";
        remove.className = "btn danger";
        remove.dataset.action = "remove-storage";
        remove.textContent = "Remove";
        actionCell.appendChild(remove);
        tr.append(nameCell, kindCell, actionCell);
        storagesBody.appendChild(tr);
      });
    }

    function renderAncestors() {
      ancestorsBody.innerHTML = "";
      if (!state.ancestors.length) {
        const tr = document.createElement("tr");
        tr.innerHTML = '<td class="empty" colspan="2">No ancestor assets added yet.</td>';
        ancestorsBody.appendChild(tr);
        return;
      }
      state.ancestors.forEach((a) => {
        const tr = document.createElement("tr");
        tr.dataset.id = String(a.id || "");
        const nameCell = document.createElement("td");
        const nameInput = document.createElement("input");
        nameInput.type = "text";
        nameInput.dataset.field = "name";
        nameInput.value = String(a.name || "");
        nameCell.appendChild(nameInput);
        const actionCell = document.createElement("td");
        const remove = document.createElement("button");
        remove.type = "button";
        remove.className = "btn danger";
        remove.dataset.action = "remove-ancestor";
        remove.textContent = "Remove";
        actionCell.appendChild(remove);
        tr.append(nameCell, actionCell);
        ancestorsBody.appendChild(tr);
      });
    }

    function renderAll() {
      fillAncestorOptions(assetAncestorSelect, assetAncestorSelect.value);
      fillStorageOptions(assetStorageSelect, assetStorageSelect.value);
      fillStorageKindOptions(storageKindSelect, storageKindSelect?.value);
      setAssetSpecialMode(!!assetSpecialToggle?.checked);
      renderAssets();
      renderStorages();
      renderAncestors();
    }

    async function action(name, payload = {}) {
      const fd = new FormData();
      fd.append("action", name);
      Object.entries(payload).forEach(([k, v]) => fd.append(k, String(v ?? "")));
      const res = await fetch("index.php", { method: "POST", body: fd });
      const data = await res.json();
      if (!res.ok || data.status !== "ok") throw new Error(data.message || "Request failed.");
      state.storages = Array.isArray(data.storages) ? data.storages : [];
      state.ancestors = Array.isArray(data.ancestors) ? data.ancestors : [];
      state.assets = Array.isArray(data.assets) ? data.assets : [];
      renderAll();
      return data;
    }

    paneButtons.forEach((btn) => btn.addEventListener("click", () => setPane(btn.dataset.paneTarget)));
    assetSpecialToggle?.addEventListener("change", () => setAssetSpecialMode(!!assetSpecialToggle.checked));

    storageForm.addEventListener("submit", async (e) => {
      e.preventDefault();
      const name = storageForm.elements.name.value.trim();
      const kind = normalizeStorageKind(storageForm.elements.kind?.value);
      if (!name) return setStatus(storageStatus, "Storage name is required.", true);
      setStatus(storageStatus, "Saving...");
      try {
        await action("add_storage", { name, kind });
        storageForm.reset();
        fillStorageKindOptions(storageKindSelect, DEFAULT_STORAGE_KIND);
        setStatus(storageStatus, "Storage added.");
      } catch (err) {
        setStatus(storageStatus, err.message || "Unable to add storage.", true);
      }
    });

    ancestorForm.addEventListener("submit", async (e) => {
      e.preventDefault();
      const name = ancestorForm.elements.name.value.trim();
      if (!name) return setStatus(ancestorStatus, "Ancestor asset name is required.", true);
      setStatus(ancestorStatus, "Saving...");
      try {
        await action("add_ancestor", { name });
        ancestorForm.reset();
        setStatus(ancestorStatus, "Ancestor asset added.");
      } catch (err) {
        setStatus(ancestorStatus, err.message || "Unable to add ancestor asset.", true);
      }
    });

    assetForm.addEventListener("submit", async (e) => {
      e.preventDefault();
      const special_asset = !!assetSpecialToggle?.checked;
      const ancestor_id = String(assetForm.elements.ancestor_id.value || "").trim();
      const name = String(assetForm.elements.name?.value || "").trim();
      const code = assetForm.elements.code.value.trim();
      const storage_id = String(assetForm.elements.storage_id.value || "").trim();
      if (!code || !storage_id || (!special_asset && !ancestor_id) || (special_asset && !name)) {
        return setStatus(assetStatus, "All fields are required.", true);
      }
      setStatus(assetStatus, "Saving...");
      try {
        await action("add_asset", {
          special_asset: special_asset ? "1" : "0",
          ancestor_id: special_asset ? "" : ancestor_id,
          name: special_asset ? name : "",
          code,
          storage_id
        });
        assetForm.reset();
        if (assetSpecialToggle) assetSpecialToggle.checked = false;
        setAssetSpecialMode(false);
        fillAncestorOptions(assetAncestorSelect, "");
        fillStorageOptions(assetStorageSelect, "");
        setStatus(assetStatus, "Asset added.");
      } catch (err) {
        setStatus(assetStatus, err.message || "Unable to add asset.", true);
      }
    });

    assetsBody.addEventListener("change", async (e) => {
      const t = e.target;
      if (!(t instanceof HTMLInputElement || t instanceof HTMLSelectElement)) return;
      const field = String(t.dataset.field || "");
      if (!field) return;
      const row = t.closest("tr");
      const id = String(row?.dataset.id || "");
      const value = String(t.value || "").trim();
      if (!id || !value) return renderAssets();
      t.disabled = true;
      try {
        await action("update_asset", { id, field, value });
      } catch (err) {
        renderAssets();
        alert(err.message || "Unable to save asset changes.");
      } finally {
        t.disabled = false;
      }
    });

    storagesBody.addEventListener("change", async (e) => {
      const t = e.target;
      if (!(t instanceof HTMLInputElement || t instanceof HTMLSelectElement)) return;
      const row = t.closest("tr");
      const id = String(row?.dataset.id || "");
      const field = String(t.dataset.field || "");
      const value = String(t.value || "").trim();
      if (!id || !field || (field === "name" && !value)) return renderStorages();
      t.disabled = true;
      try {
        await action("update_storage", { id, field, value });
      } catch (err) {
        renderStorages();
        alert(err.message || "Unable to save storage changes.");
      } finally {
        t.disabled = false;
      }
    });

    ancestorsBody.addEventListener("change", async (e) => {
      const t = e.target;
      if (!(t instanceof HTMLInputElement)) return;
      const row = t.closest("tr");
      const id = String(row?.dataset.id || "");
      const value = String(t.value || "").trim();
      if (!id || !value) return renderAncestors();
      t.disabled = true;
      try {
        await action("update_ancestor", { id, value });
      } catch (err) {
        renderAncestors();
        alert(err.message || "Unable to save ancestor asset changes.");
      } finally {
        t.disabled = false;
      }
    });

    assetsBody.addEventListener("click", async (e) => {
      const btn = e.target instanceof HTMLElement ? e.target.closest('[data-action="remove-asset"]') : null;
      if (!(btn instanceof HTMLButtonElement)) return;
      const id = String(btn.closest("tr")?.dataset.id || "");
      if (!id || !confirm("Remove this asset?")) return;
      btn.disabled = true;
      try { await action("remove_asset", { id }); }
      catch (err) { alert(err.message || "Unable to remove asset."); }
      finally { btn.disabled = false; }
    });

    storagesBody.addEventListener("click", async (e) => {
      const btn = e.target instanceof HTMLElement ? e.target.closest('[data-action="remove-storage"]') : null;
      if (!(btn instanceof HTMLButtonElement)) return;
      const id = String(btn.closest("tr")?.dataset.id || "");
      if (!id || !confirm("Remove this storage?")) return;
      btn.disabled = true;
      try { await action("remove_storage", { id }); }
      catch (err) { alert(err.message || "Unable to remove storage."); }
      finally { btn.disabled = false; }
    });

    ancestorsBody.addEventListener("click", async (e) => {
      const btn = e.target instanceof HTMLElement ? e.target.closest('[data-action="remove-ancestor"]') : null;
      if (!(btn instanceof HTMLButtonElement)) return;
      const id = String(btn.closest("tr")?.dataset.id || "");
      if (!id || !confirm("Remove this ancestor asset?")) return;
      btn.disabled = true;
      try { await action("remove_ancestor", { id }); }
      catch (err) { alert(err.message || "Unable to remove ancestor asset."); }
      finally { btn.disabled = false; }
    });

    renderAll();
    setPane("assets-pane");
  </script>
</body>
</html>
