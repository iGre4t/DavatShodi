<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/lib/common.php';
require_once __DIR__ . '/lib/users.php';

header('Content-Type: application/json; charset=UTF-8');
// This single handler responds with the normalized payload used by both the users and gallery tabs.

$configFile = __DIR__ . '/config.php';
$config = loadConfig($configFile);
$pdo = connectDatabase($config);
if ($pdo) {
    ensureGallerySchema($pdo);
}

$dataDir = __DIR__ . '/../data';
$dataFile = $dataDir . '/store.json';
$defaultData = [
    'users' => [],
    'branches' => [
        [
            'id' => 'branch-main',
            'name' => 'Main Branch',
            'systems' => [
                ['id' => 'system-1', 'name' => 'Gaming Station', 'pricesByPeriod' => []]
            ],
            'periods' => [
                [
                    'id' => 'default',
                    'start' => 0,
                    'end' => 1440,
                    'defaultPrices' => [
                        'p1' => 150000,
                        'p2' => 200000,
                        'p3' => 260000,
                        'p4' => 320000,
                        'birthday' => 500000,
                        'film' => 350000
                    ]
                ]
            ]
        ]
    ],
    'settings' => [
        'title' => 'Great Panel',
        'timezone' => 'Asia/Tehran',
        'panelName' => 'Great Panel',
        'siteIcon' => '',
        'backupSettings' => [
            'autoIntervalMinutes' => 0,
            'autoLimit' => 0,
            'lastAutoBackupAt' => null
        ]
    ]
];

function parseUserCodeValue(string $value): int
{
    $digits = preg_replace('/\D+/', '', $value);
    return $digits === '' ? 0 : (int)$digits;
}

function generateNextUserCode(array $data): string
{
    $max = 0;
    $users = is_array($data['users'] ?? null) ? $data['users'] : [];
    foreach ($users as $user) {
        if (!is_array($user)) {
            continue;
        }
        $current = parseUserCodeValue((string)($user['code'] ?? ''));
        if ($current > $max) {
            $max = $current;
        }
    }
    return str_pad((string)($max + 1), 6, '0', STR_PAD_LEFT);
}

function isUserCodeTaken(string $code, array $data): bool
{
    foreach ((is_array($data['users'] ?? null) ? $data['users'] : []) as $user) {
        if (!is_array($user)) {
            continue;
        }
        if (($user['code'] ?? '') === $code) {
            return true;
        }
    }
    return false;
}

function isValidEmail(string $value): bool
{
    if ($value === '') {
        return true;
    }
    return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
}

function normalizeDigits(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?? '';
}

function isPhoneTaken(string $phone, array $data, string $excludeCode = ''): bool
{
    $target = normalizeDigits($phone);
    if ($target === '') {
        return false;
    }
    foreach ((is_array($data['users'] ?? null) ? $data['users'] : []) as $user) {
        if (!is_array($user)) {
            continue;
        }
        if ($excludeCode !== '' && (string)($user['code'] ?? '') === $excludeCode) {
            continue;
        }
        if (normalizeDigits((string)($user['phone'] ?? '')) === $target) {
            return true;
        }
    }
    return false;
}

function isEmailTaken(string $email, array $data, string $excludeCode = ''): bool
{
    $target = mb_strtolower(trim($email));
    if ($target === '') {
        return false;
    }
    foreach ((is_array($data['users'] ?? null) ? $data['users'] : []) as $user) {
        if (!is_array($user)) {
            continue;
        }
        if ($excludeCode !== '' && (string)($user['code'] ?? '') === $excludeCode) {
            continue;
        }
        if (mb_strtolower(trim((string)($user['email'] ?? ''))) === $target) {
            return true;
        }
    }
    return false;
}

function isIdNumberTaken(string $idNumber, array $data, string $excludeCode = ''): bool
{
    $target = normalizeDigits(trim($idNumber));
    if ($target === '') {
        return false;
    }
    foreach ((is_array($data['users'] ?? null) ? $data['users'] : []) as $user) {
        if (!is_array($user)) {
            continue;
        }
        if ($excludeCode !== '' && (string)($user['code'] ?? '') === $excludeCode) {
            continue;
        }
        if (normalizeDigits((string)($user['id_number'] ?? '')) === $target) {
            return true;
        }
    }
    return false;
}

function findUserIndexByCode(array $data, string $code): int
{
    $target = trim((string)$code);
    if ($target === '') {
        return -1;
    }
    $users = is_array($data['users'] ?? null) ? $data['users'] : [];
    foreach ($users as $index => $user) {
        if (!is_array($user)) {
            continue;
        }
        if (trim((string)($user['code'] ?? '')) === $target) {
            return $index;
        }
    }
    return -1;
}

function updateUserInStore(array &$data, string $code, array $values): bool
{
    $index = findUserIndexByCode($data, $code);
    if ($index < 0) {
        return false;
    }
    $current = is_array($data['users'][$index] ?? null) ? $data['users'][$index] : [];
    $data['users'][$index] = array_merge($current, $values);
    return true;
}

function removeUserFromStore(array &$data, string $code): bool
{
    $index = findUserIndexByCode($data, $code);
    if ($index < 0) {
        return false;
    }
    array_splice($data['users'], $index, 1);
    return true;
}

$data = normalizeData(loadJsonData($dataFile, $defaultData), $defaultData);

$dbData = null;
if ($pdo) {
    $dbData = loadDataFromDb($pdo, $config);
    if (is_array($dbData)) {
        $data = normalizeData($dbData, $defaultData);
    } else {
        saveDataToDb($pdo, $data, $config);
    }
}
$usersFromTable = $pdo ? loadUsersFromUsersTable($pdo) : null;

$isAuthenticated = !empty($_SESSION['authenticated']) && is_array($_SESSION['user']);
$currentUserCode = $isAuthenticated ? trim((string)($_SESSION['user']['code'] ?? '')) : '';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $isMultipart = stripos($contentType, 'multipart/form-data') !== false;
    $payload = $isMultipart
        ? ($_POST ?? [])
        : (json_decode(file_get_contents('php://input') ?: 'null', true) ?? []);
    if (!is_array($payload)) {
        $payload = [];
    }
    $action = $payload['action'] ?? '';
    $postResponse = ['status' => 'ok'];

    if ($action === 'update_user_personal') {
        handleUserUpdatePersonal($payload, $pdo, $currentUserCode);
    } elseif ($action === 'update_user_account') {
        handleUserUpdateAccount($payload, $pdo, $currentUserCode);
    } elseif ($action === 'update_user_password') {
        handleUserUpdatePassword($payload, $pdo, $currentUserCode);
    }

    // User tab CRUD actions (add, update, delete) land here so the panel can persist the JSON store and optional DB.
    if ($action === 'add_user' && !empty($payload['user'])) {
        $user = $payload['user'];
        $username = trim((string)($user['username'] ?? ''));
        $fullname = trim((string)($user['fullname'] ?? $user['name'] ?? ''));
        if ($fullname === '') {
            sendJsonResponse(['status' => 'error', 'message' => 'Full name is required.']);
        }
        if ($username === '') {
            sendJsonResponse(['status' => 'error', 'message' => 'Username is required.']);
        }
        $phone = normalizeDigits(trim((string)($user['phone'] ?? '')));
        if (strlen($phone) !== 11) {
            sendJsonResponse(['status' => 'error', 'message' => 'Phone number must be 11 digits.']);
        }
        if (isPhoneTaken($phone, $data)) {
            sendJsonResponse(['status' => 'error', 'message' => 'Phone number already exists.']);
        }
        if ($pdo && isUsernameTaken($pdo, $username)) {
            sendJsonResponse(['status' => 'error', 'message' => 'Username already exists.']);
        }
        if ($pdo && isPhoneTakenInTable($pdo, $phone)) {
            sendJsonResponse(['status' => 'error', 'message' => 'Phone number already exists.']);
        }
        $workId = trim((string)($user['work_id'] ?? ''));
        $idNumber = normalizeDigits(trim((string)($user['id_number'] ?? '')));
        $email = trim((string)($user['email'] ?? ''));
        if (!isValidEmail($email)) {
            sendJsonResponse(['status' => 'error', 'message' => 'Please provide a valid email address.']);
        }
        if (isEmailTaken($email, $data)) {
            sendJsonResponse(['status' => 'error', 'message' => 'Email already exists.']);
        }
        if ($pdo && isEmailTakenInTable($pdo, $email)) {
            sendJsonResponse(['status' => 'error', 'message' => 'Email already exists.']);
        }
        if (isIdNumberTaken($idNumber, $data)) {
            sendJsonResponse(['status' => 'error', 'message' => 'National ID already exists.']);
        }
        if ($pdo && isIdNumberTakenInTable($pdo, $idNumber)) {
            sendJsonResponse(['status' => 'error', 'message' => 'National ID already exists.']);
        }
        $code = trim((string)($user['code'] ?? ''));
        if ($code === '' || isUserCodeTaken($code, $data)) {
            $code = generateNextUserCode($data);
        }
        $newUser = [
            'code' => $code,
            'username' => $username,
            'name' => $fullname,
            'phone' => $phone,
            'work_id' => $workId,
            'id_number' => $idNumber,
            'email' => $email,
            'active' => !empty($user['active']),
            'createdAt' => time()
        ];
        if ($pdo && !insertUserRecord($pdo, [
            'code' => $newUser['code'],
            'username' => $newUser['username'],
            'name' => $newUser['name'],
            'phone' => $newUser['phone'],
            'work_id' => $newUser['work_id'],
            'id_number' => $newUser['id_number'],
            'email' => $email
        ])) {
            sendJsonResponse(['status' => 'error', 'message' => 'Failed to insert user into the database.']);
        }
        $data['users'][] = $newUser;
        $postResponse['message'] = 'User saved successfully.';
    } elseif ($action === 'update_user' && !empty($payload['user'])) {
        // Handles edits submitted from the user modal while keeping validations centralized.
        $user = $payload['user'];
        $code = trim((string)($user['code'] ?? ''));
        if ($code === '') {
            sendJsonResponse(['status' => 'error', 'message' => 'User code is required.']);
        }
        $username = trim((string)($user['username'] ?? ''));
        $fullname = trim((string)($user['fullname'] ?? $user['name'] ?? ''));
        if ($username === '' || $fullname === '') {
            sendJsonResponse(['status' => 'error', 'message' => 'Username and full name are required.']);
        }
        $phone = normalizeDigits(trim((string)($user['phone'] ?? '')));
        if (strlen($phone) !== 11) {
            sendJsonResponse(['status' => 'error', 'message' => 'Phone number must be 11 digits.']);
        }
        if (isPhoneTaken($phone, $data, $code)) {
            sendJsonResponse(['status' => 'error', 'message' => 'Phone number already exists.']);
        }
        $workId = trim((string)($user['work_id'] ?? ''));
        $idNumber = normalizeDigits(trim((string)($user['id_number'] ?? '')));
        $email = trim((string)($user['email'] ?? ''));
        if (!isValidEmail($email)) {
            if ($email !== '') {
                sendJsonResponse(['status' => 'error', 'message' => 'Please provide a valid email address.']);
            }
        }
        if ($email !== '' && isEmailTaken($email, $data, $code)) {
            sendJsonResponse(['status' => 'error', 'message' => 'Email already exists.']);
        }
        if ($email !== '' && $pdo && isEmailTakenInTable($pdo, $email, $code)) {
            sendJsonResponse(['status' => 'error', 'message' => 'Email already exists.']);
        }
        if ($idNumber !== '' && isIdNumberTaken($idNumber, $data, $code)) {
            sendJsonResponse(['status' => 'error', 'message' => 'National ID already exists.']);
        }
        if ($idNumber !== '' && $pdo && isIdNumberTakenInTable($pdo, $idNumber, $code)) {
            sendJsonResponse(['status' => 'error', 'message' => 'National ID already exists.']);
        }
        if ($pdo && isUsernameTaken($pdo, $username, $code)) {
            sendJsonResponse(['status' => 'error', 'message' => 'Username already exists.']);
        }
        if ($pdo && isPhoneTakenInTable($pdo, $phone, $code)) {
            sendJsonResponse(['status' => 'error', 'message' => 'Phone number already exists.']);
        }
        if ($pdo) {
            $updateFields = [
                'username' => $username,
                'fullname' => $fullname,
                'phone' => $phone,
                'work_id' => $workId,
                'id_number' => $idNumber,
                'email' => $email
            ];
            if (!updateUserByCode($pdo, $code, $updateFields)) {
                sendJsonResponse(['status' => 'error', 'message' => 'Failed to update user information.']);
            }
        }
        if (!updateUserInStore($data, $code, [
            'username' => $username,
            'name' => $fullname,
            'phone' => $phone,
            'work_id' => $workId,
            'id_number' => $idNumber,
            'email' => $email,
            'active' => !empty($user['active'])
        ])) {
            sendJsonResponse(['status' => 'error', 'message' => 'User not found.']);
        }
        $postResponse['message'] = 'User updated successfully.';
    } elseif ($action === 'delete_user' && !empty($payload['code'])) {
        // Confirmed deletions come from the users tab list after hitting the primary Delete button.
        $code = trim((string)($payload['code'] ?? ''));
        if ($code === '') {
            sendJsonResponse(['status' => 'error', 'message' => 'User code is required.']);
        }
        if ($pdo && !deleteUserByCode($pdo, $code)) {
            sendJsonResponse(['status' => 'error', 'message' => 'Failed to delete the user.']);
        }
        if (!removeUserFromStore($data, $code)) {
            sendJsonResponse(['status' => 'error', 'message' => 'User not found.']);
        }
        $postResponse['message'] = 'User deleted successfully.';
    } elseif ($action === 'save_settings' && !empty($payload['settings'])) {
        $settings = $payload['settings'];
        foreach (['title', 'timezone', 'panelName'] as $field) {
            if (array_key_exists($field, $settings)) {
                $value = $settings[$field];
                $data['settings'][$field] = is_string($value) ? trim($value) : $value;
            }
        }
        if (array_key_exists('siteIcon', $settings)) {
            $value = $settings['siteIcon'];
            $data['settings']['siteIcon'] = is_string($value) ? $value : '';
        }
    } elseif ($action === 'save_database_config' && !empty($payload['config']) && is_array($payload['config'])) {
        if (!persistDatabaseConfigOverrides($payload['config'])) {
            sendJsonResponse(['status' => 'error', 'message' => 'Failed to save database configuration.']);
        }
        $updatedConfig = loadConfig($configFile);
        $postResponse['message'] = 'Database configuration saved successfully.';
        $postResponse['databaseConfig'] = getDatabaseConfigForResponse($updatedConfig);
    } elseif ($action === 'download_database_backup') {
        $package = prepareBackupPackage($data, $pdo, $config);
        if ($package['payloadJson'] === null) {
            sendJsonResponse(['status' => 'error', 'message' => 'Failed to build backup payload.']);
        }
        $extension = $package['archive'] !== null ? 'zip' : 'json';
        $content = $package['archive'] ?? $package['payloadJson'];
        $encoding = $package['archive'] !== null ? 'base64' : null;
        $payloadContent = $encoding === 'base64' ? base64_encode($content) : $content;
        $entry = recordBackupPayload($content, 'manual', 0, $extension);
        $filename = $entry['filename'] ?? sprintf('backup-%s.%s', date('YmdHis'), $extension);
        $mime = $entry['mime'] ?? ($extension === 'zip' ? 'application/zip' : 'application/json');
        $postResponse['message'] = 'Backup exported successfully.';
        $postResponse['backup'] = $payloadContent;
        $postResponse['encoding'] = $encoding;
        $postResponse['mime'] = $mime;
        $postResponse['filename'] = $filename;
        $postResponse['backups'] = getBackupHistoryForResponse();
    } elseif ($action === 'fetch_backup_file') {
        $filename = sanitizeBackupFilename((string)($payload['filename'] ?? ''));
        if ($filename === '') {
            sendJsonResponse(['status' => 'error', 'message' => 'Invalid filename provided.']);
        }
        $path = getBackupFilePath($filename);
        if ($path === '' || !is_file($path)) {
            sendJsonResponse(['status' => 'error', 'message' => 'Backup file not found.']);
        }
        $content = file_get_contents($path);
        if ($content === false) {
            sendJsonResponse(['status' => 'error', 'message' => 'Unable to read backup file.']);
        }
        $isArchive = isBackupArchiveContent($content);
        $payloadContent = $isArchive ? base64_encode($content) : $content;
        sendJsonResponse([
            'status' => 'ok',
            'content' => $payloadContent,
            'encoding' => $isArchive ? 'base64' : null,
            'mime' => $isArchive ? 'application/zip' : 'application/json',
            'filename' => $filename
        ]);
    } elseif ($action === 'delete_backup_file') {
        $filename = sanitizeBackupFilename((string)($payload['filename'] ?? ''));
        if ($filename === '') {
            sendJsonResponse(['status' => 'error', 'message' => 'Invalid filename provided.']);
        }
        deleteBackupFile($filename);
        deleteBackupMetadataEntry($filename);
        sendJsonResponse([
            'status' => 'ok',
            'message' => 'Backup deleted successfully.',
            'backups' => getBackupHistoryForResponse()
        ]);
    } elseif ($action === 'apply_backup_by_filename') {
        $filename = sanitizeBackupFilename((string)($payload['filename'] ?? ''));
        if ($filename === '') {
            sendJsonResponse(['status' => 'error', 'message' => 'Invalid filename provided.']);
        }
        $path = getBackupFilePath($filename);
        if ($path === '' || !is_file($path)) {
            sendJsonResponse(['status' => 'error', 'message' => 'Backup file not found.']);
        }
        $content = file_get_contents($path);
        if ($content === false) {
            sendJsonResponse(['status' => 'error', 'message' => 'Unable to read backup file.']);
        }
        $decoded = decodeBackupPayloadContent($content);
        if (!is_array($decoded)) {
            sendJsonResponse(['status' => 'error', 'message' => 'Backup file is corrupted.']);
        }
        $data = normalizeData($decoded, $defaultData);
        $postResponse['message'] = 'Backup applied successfully.';
        $postResponse['backups'] = getBackupHistoryForResponse();
    } elseif ($action === 'import_database_backup') {
        if (!is_array($_FILES['backup'] ?? null)) {
            sendJsonResponse(['status' => 'error', 'message' => 'No backup file was provided.']);
        }
        $file = $_FILES['backup'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            sendJsonResponse(['status' => 'error', 'message' => 'Backup upload failed.']);
        }
        $content = file_get_contents($file['tmp_name']);
        if ($content === false) {
            sendJsonResponse(['status' => 'error', 'message' => 'Unable to read the uploaded backup.']);
        }
        $decoded = decodeBackupPayloadContent($content);
        if (!is_array($decoded)) {
            sendJsonResponse(['status' => 'error', 'message' => 'The backup file is not valid JSON.']);
        }
        $data = normalizeData($decoded, $defaultData);
        $postResponse['message'] = 'Backup imported successfully.';
        $postResponse['backups'] = getBackupHistoryForResponse();
    } elseif ($action === 'save_backup_settings') {
        $settings = is_array($payload['settings'] ?? null) ? $payload['settings'] : [];
        $interval = max(0, (int)($settings['autoIntervalMinutes'] ?? 0));
        $limit = max(0, (int)($settings['autoLimit'] ?? 0));
        if (!isset($data['settings']) || !is_array($data['settings'])) {
            $data['settings'] = [];
        }
        if (!isset($data['settings']['backupSettings']) || !is_array($data['settings']['backupSettings'])) {
            $data['settings']['backupSettings'] = [];
        }
        $data['settings']['backupSettings']['autoIntervalMinutes'] = $interval;
        $data['settings']['backupSettings']['autoLimit'] = $limit;
        $postResponse['message'] = 'Auto-backup settings saved.';
    } elseif ($action === 'run_sql_query') {
        if (!$pdo) {
            sendJsonResponse(['status' => 'error', 'message' => 'Unable to connect to the database.']);
        }
        $sql = trim((string)($payload['sql'] ?? ''));
        if ($sql === '') {
            sendJsonResponse(['status' => 'error', 'message' => 'SQL statement cannot be empty.']);
        }
        $isRead = preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN|PRAGMA|WITH)\b/i', $sql) === 1;
        try {
            if ($isRead) {
                $stmt = $pdo->query($sql);
                if ($stmt === false) {
                    throw new RuntimeException('Query execution failed.');
                }
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = [];
                $count = $stmt->columnCount();
                for ($i = 0; $i < $count; $i++) {
                    $meta = $stmt->getColumnMeta($i);
                    $columns[] = $meta['name'] ?? "column{$i}";
                }
                $postResponse = [
                    'status' => 'ok',
                    'type' => 'select',
                    'rows' => $rows,
                    'columns' => $columns,
                    'message' => 'Query executed successfully.',
                    'hasMore' => false
                ];
                sendJsonResponse($postResponse);
            }
            $affected = $pdo->exec($sql);
            $postResponse = [
                'status' => 'ok',
                'type' => 'exec',
                'affectedRows' => $affected === false ? 0 : $affected,
                'message' => 'Statement executed successfully.'
            ];
            sendJsonResponse($postResponse);
        } catch (PDOException $err) {
            sendJsonResponse(['status' => 'error', 'message' => $err->getMessage()]);
        } catch (RuntimeException $err) {
            sendJsonResponse(['status' => 'error', 'message' => $err->getMessage()]);
        }
    } elseif ($action === 'add_gallery_category') {
        if (!$pdo) {
            sendJsonResponse(['status' => 'error', 'message' => 'Unable to connect to the database.']);
        }
        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') {
            sendJsonResponse(['status' => 'error', 'message' => 'Invalid category name.']);
        }
        $motherCategoryId = parseMotherCategoryId($payload['mother_category_id'] ?? null);
        if ($motherCategoryId !== null && !galleryCategoryExists($pdo, $motherCategoryId)) {
            sendJsonResponse(['status' => 'error', 'message' => 'Selected mother category does not exist.']);
        }
        $category = createGalleryCategory($pdo, $name, $motherCategoryId);
        if (empty($category['id'])) {
            sendJsonResponse(['status' => 'error', 'message' => 'Failed to save the category.']);
        }
        $postResponse['message'] = 'Category saved successfully.';
    } elseif ($action === 'update_gallery_category') {
        if (!$pdo) {
            sendJsonResponse(['status' => 'error', 'message' => 'Unable to connect to the database.']);
        }
        $categoryId = (int)($payload['id'] ?? 0);
        $name = trim((string)($payload['name'] ?? ''));
        $motherCategoryId = parseMotherCategoryId($payload['mother_category_id'] ?? null);
        if ($categoryId <= 0 || $name === '') {
            sendJsonResponse(['status' => 'error', 'message' => 'Category data is invalid.']);
        }
        $result = updateGalleryCategory($pdo, $categoryId, $name, $motherCategoryId);
        if ($result['status'] !== 'ok') {
            sendJsonResponse($result);
        }
        $postResponse['message'] = $result['message'] ?? 'Category updated successfully.';
    } elseif ($action === 'delete_gallery_category') {
        if (!$pdo) {
            sendJsonResponse(['status' => 'error', 'message' => 'Unable to connect to the database.']);
        }
        $categoryId = (int)($payload['id'] ?? 0);
        if ($categoryId <= 0) {
            sendJsonResponse(['status' => 'error', 'message' => 'No category selected.']);
        }
        $result = deleteGalleryCategory($pdo, $categoryId);
        if ($result['status'] !== 'ok') {
            sendJsonResponse($result);
        }
        $postResponse['message'] = $result['message'] ?? 'Category deleted successfully.';
    } elseif ($action === 'update_gallery_photo') {
        if (!$pdo) {
            sendJsonResponse(['status' => 'error', 'message' => 'Unable to connect to the database.']);
        }
        $photoId = (int)($payload['photo_id'] ?? 0);
        $title = trim((string)($payload['title'] ?? ''));
        $alt = trim((string)($payload['alt_text'] ?? ''));
        $categoryId = (int)($payload['category_id'] ?? 0);
        $result = updateGalleryPhoto($pdo, $photoId, $title, $alt, $categoryId);
        if ($result['status'] !== 'ok') {
            sendJsonResponse($result);
        }
        $postResponse['message'] = $result['message'] ?? 'Photo details saved successfully.';
    } elseif ($action === 'replace_gallery_photo') {
        if (!$pdo) {
            sendJsonResponse(['status' => 'error', 'message' => 'Unable to connect to the database.']);
        }
        $photoId = (int)($payload['photo_id'] ?? 0);
        $file = $_FILES['photo'] ?? null;
        if (!is_array($file)) {
            sendJsonResponse(['status' => 'error', 'message' => 'No image file was uploaded.']);
        }
        $result = replaceGalleryPhoto($pdo, $photoId, $file);
        if ($result['status'] !== 'ok') {
            sendJsonResponse($result);
        }
        $postResponse['message'] = $result['message'] ?? 'Photo replaced successfully.';
    } elseif ($action === 'delete_gallery_photo') {
        if (!$pdo) {
            sendJsonResponse(['status' => 'error', 'message' => 'Unable to connect to the database.']);
        }
        $photoId = (int)($payload['photo_id'] ?? 0);
        if ($photoId <= 0) {
            sendJsonResponse(['status' => 'error', 'message' => 'No photo selected.']);
        }
        $result = deleteGalleryPhoto($pdo, $photoId);
        if ($result['status'] !== 'ok') {
            sendJsonResponse($result);
        }
        $postResponse['message'] = $result['message'] ?? 'Photo deleted successfully.';
    } elseif ($action === 'add_gallery_photo') {
        if (!$pdo) {
            sendJsonResponse(['status' => 'error', 'message' => 'Unable to connect to the database.']);
        }
        $file = $_FILES['photo'] ?? null;
        if (!is_array($file)) {
            sendJsonResponse(['status' => 'error', 'message' => 'No image file was uploaded.']);
        }
        $photoResult = saveGalleryPhoto($pdo, $payload, $file);
        if ($photoResult['status'] !== 'ok') {
            sendJsonResponse($photoResult);
        }
        $postResponse['message'] = 'Image uploaded successfully.';
    }

    $data = normalizeData($data, $defaultData);
    persistData($data, $dataFile, $pdo, $config);
    sendJsonResponse($postResponse);
}

if (is_array($usersFromTable)) {
    $data['users'] = $usersFromTable;
}

$galleryCategories = $pdo ? loadGalleryCategories($pdo) : [];
$galleryPhotos = $pdo ? loadGalleryPhotos($pdo) : [];

$data['galleryCategories'] = $galleryCategories;
$data['galleryPhotos'] = $galleryPhotos;

$autoBackupTriggered = false;
if ($method !== 'POST') {
    $autoBackupTriggered = maybeRunAutomaticBackup($data, $pdo, $config);
    if ($autoBackupTriggered) {
        persistData($data, $dataFile, $pdo, $config);
    }
}

$data['databaseConnected'] = $pdo !== null;
$data['databaseConfig'] = getDatabaseConfigForResponse($config);
$data['backups'] = getBackupHistoryForResponse();

echo json_encode($data);
exit;

function loadJsonData(string $path, array $defaults): array
{
    if (!is_file($path)) {
        return $defaults;
    }
    $content = file_get_contents($path);
    if ($content === false) {
        return $defaults;
    }
    $decoded = json_decode($content, true);
    if (!is_array($decoded)) {
        return $defaults;
    }
    return $decoded;
}

function normalizeData(array $data, array $defaults): array
{
    $result = $data;
    $result['users'] = array_values(
        is_array($result['users'] ?? null) ? $result['users'] : $defaults['users']
    );
    $result['branches'] = array_values(
        is_array($result['branches'] ?? null) ? $result['branches'] : $defaults['branches']
    );
    $result['settings'] = array_merge(
        $defaults['settings'],
        is_array($result['settings'] ?? null) ? $result['settings'] : []
    );
    return $result;
}

function persistData(array $data, string $file, ?PDO $pdo, array $config): void
{
    $directory = dirname($file);
    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    if ($pdo) {
        saveDataToDb($pdo, $data, $config);
    }
}

function sendJsonResponse(array $payload): void
{
    echo json_encode($payload);
    exit;
}

function ensureGallerySchema(PDO $pdo): void
{
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `gallery_category` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `slug` VARCHAR(64) NOT NULL,
  `mother_category_id` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_gallery_category_slug` (`slug`),
  KEY `idx_gallery_category_mother` (`mother_category_id`),
  CONSTRAINT `fk_gallery_category_mother` FOREIGN KEY (`mother_category_id`)
    REFERENCES `gallery_category` (`id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gallery` (
  `photo_id` INT(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
  `filename` VARCHAR(255) NOT NULL,
  `category_id` INT UNSIGNED DEFAULT NULL,
  `title` VARCHAR(255) NOT NULL,
  `alt_text` VARCHAR(255) NOT NULL,
  `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`photo_id`),
  KEY `idx_gallery_category` (`category_id`),
  CONSTRAINT `fk_gallery_category` FOREIGN KEY (`category_id`)
    REFERENCES `gallery_category` (`id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

    $pdo->exec($sql);
    try {
        $pdo->exec('ALTER TABLE `gallery` MODIFY `category_id` INT UNSIGNED DEFAULT NULL');
    } catch (PDOException $err) {
        // Ignore if the column is already configured or the table is missing.
    }
    try {
        $pdo->exec('ALTER TABLE `gallery_category` ADD COLUMN `mother_category_id` INT UNSIGNED DEFAULT NULL AFTER `slug`');
    } catch (PDOException $err) {
        // Ignore if the column already exists or the table is missing.
    }
    try {
        $pdo->exec('ALTER TABLE `gallery_category` ADD KEY `idx_gallery_category_mother` (`mother_category_id`)');
    } catch (PDOException $err) {
        // Ignore if the index already exists.
    }
    try {
        $pdo->exec('ALTER TABLE `gallery_category` ADD CONSTRAINT `fk_gallery_category_mother` FOREIGN KEY (`mother_category_id`) REFERENCES `gallery_category` (`id`) ON DELETE SET NULL ON UPDATE CASCADE');
    } catch (PDOException $err) {
        // Ignore if the constraint already exists.
    }
}

function loadGalleryCategories(PDO $pdo): array
{
    try {
        $stmt = $pdo->query('SELECT `id`, `name`, `slug`, `mother_category_id` FROM `gallery_category` ORDER BY `name` ASC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $err) {
        error_log('Failed to fetch gallery categories: ' . $err->getMessage());
        return [];
    }
    return is_array($rows) ? $rows : [];
}

function loadGalleryPhotos(PDO $pdo): array
{
    try {
        $stmt = $pdo->query(
            'SELECT
                g.photo_id,
                g.filename,
                g.title,
                g.alt_text,
                g.uploaded_at,
                g.category_id,
                gc.name AS category_name
             FROM `gallery` g
             LEFT JOIN `gallery_category` gc ON gc.id = g.category_id
             ORDER BY g.uploaded_at DESC'
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $err) {
        error_log('Failed to fetch gallery photos: ' . $err->getMessage());
        return [];
    }
    return is_array($rows) ? $rows : [];
}

function handleUserUpdatePersonal(array $payload, ?PDO $pdo, string $userCode): void
{
    if (!$pdo) {
        sendJsonResponse(['status' => 'error', 'message' => 'Unable to connect to the database.']);
    }
    if ($userCode === '') {
        sendJsonResponse(['status' => 'error', 'message' => 'You must be logged in to perform this action.']);
    }
    $fullname = trim((string)($payload['fullname'] ?? ''));
    if ($fullname === '') {
        sendJsonResponse(['status' => 'error', 'message' => 'Full name cannot be empty.']);
    }
    if (!updateUserByCode($pdo, $userCode, ['fullname' => $fullname])) {
        sendJsonResponse(['status' => 'error', 'message' => 'An error occurred while saving the full name.']);
    }
    $user = loadUserByCode($pdo, $userCode);
    if (!$user) {
        sendJsonResponse(['status' => 'error', 'message' => 'User not found.']);
    }
    refreshSessionUser($user);
    sendJsonResponse([
        'status' => 'ok',
        'message' => 'Full name updated successfully.',
        'user' => normalizeUserForResponse($user)
    ]);
}

// Handles the stable Account Settings tab action. Called from update_user_account, it enforces username/phone/email validation,
// persists the changes through the usual user table helpers, and refreshes the session so the frontend always renders the
// latest account information.
function handleUserUpdateAccount(array $payload, ?PDO $pdo, string $userCode): void
{
    if (!$pdo) {
        sendJsonResponse(['status' => 'error', 'message' => 'Unable to connect to the database.']);
    }
    if ($userCode === '') {
        sendJsonResponse(['status' => 'error', 'message' => 'You must be logged in to perform this action.']);
    }
    $username = trim((string)($payload['username'] ?? ''));
    $phone = trim((string)($payload['phone'] ?? ''));
    $email = trim((string)($payload['email'] ?? ''));
    if ($username === '') {
        sendJsonResponse(['status' => 'error', 'message' => 'Username cannot be empty.']);
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendJsonResponse(['status' => 'error', 'message' => 'Please provide a valid email address.']);
    }
    if ($phone !== '' && !preg_match('/^\d{11}$/', $phone)) {
        sendJsonResponse(['status' => 'error', 'message' => 'Phone number must be 11 digits.']);
    }
    if (isUsernameTaken($pdo, $username, $userCode)) {
        sendJsonResponse(['status' => 'error', 'message' => 'The chosen username is already in use.']);
    }
    if (!updateUserByCode($pdo, $userCode, ['username' => $username, 'phone' => $phone, 'email' => $email])) {
        sendJsonResponse(['status' => 'error', 'message' => 'An error occurred while saving account information.']);
    }
    $user = loadUserByCode($pdo, $userCode);
    if (!$user) {
        sendJsonResponse(['status' => 'error', 'message' => 'User not found.']);
    }
    refreshSessionUser($user);
    sendJsonResponse([
        'status' => 'ok',
        'message' => 'Account information updated successfully.',
        'user' => normalizeUserForResponse($user)
    ]);
}

function handleUserUpdatePassword(array $payload, ?PDO $pdo, string $userCode): void
{
    if (!$pdo) {
        sendJsonResponse(['status' => 'error', 'message' => 'Unable to connect to the database.']);
    }
    if ($userCode === '') {
        sendJsonResponse(['status' => 'error', 'message' => 'You must be logged in to perform this action.']);
    }
    $current = trim((string)($payload['current_password'] ?? ''));
    $new = trim((string)($payload['new_password'] ?? ''));
    $confirm = trim((string)($payload['confirm_password'] ?? ''));
    if ($new === '' || $confirm === '') {
        sendJsonResponse(['status' => 'error', 'message' => 'Both the new password and its confirmation are required.']);
    }
    if ($new !== $confirm) {
        sendJsonResponse(['status' => 'error', 'message' => 'The new password and confirmation must match.']);
    }
    if (mb_strlen($new, 'UTF-8') < 8) {
        sendJsonResponse(['status' => 'error', 'message' => 'The new password must be at least eight characters.']);
    }
    $user = loadUserByCode($pdo, $userCode);
    if (!$user || !password_verify($current, (string)($user['password_hash'] ?? ''))) {
        sendJsonResponse(['status' => 'error', 'message' => 'Current password is incorrect.']);
    }
    $hash = password_hash($new, PASSWORD_DEFAULT);
    if ($hash === false || !updateUserByCode($pdo, $userCode, ['password_hash' => $hash])) {
        sendJsonResponse(['status' => 'error', 'message' => 'An error occurred while saving the password.']);
    }
    $user = loadUserByCode($pdo, $userCode);
    if (!$user) {
        sendJsonResponse(['status' => 'error', 'message' => 'User not found.']);
    }
    refreshSessionUser($user);
    sendJsonResponse(['status' => 'ok', 'message' => 'Password updated successfully.']);
}

function normalizeUserForResponse(array $row): array
{
    return [
        'code' => $row['code'] ?? '',
        'username' => $row['username'] ?? '',
        'fullname' => $row['fullname'] ?? '',
        'phone' => $row['phone'] ?? '',
        'email' => $row['email'] ?? '',
        'id_number' => $row['id_number'] ?? '',
        'work_id' => $row['work_id'] ?? ''
    ];
}

function refreshSessionUser(array $row): void
{
    if (empty($row['code'])) {
        return;
    }
    $user = normalizeUserForResponse($row);
    $user['display_name'] = buildUserDisplayNameFromRow($user);
    $_SESSION['user'] = $user;
}

function buildUserDisplayNameFromRow(array $user): string
{
    foreach (['fullname', 'display_name', 'username'] as $field) {
        if (!array_key_exists($field, $user)) {
            continue;
        }
        $value = trim((string)$user[$field]);
        if ($value === '' || $value === '0') {
            continue;
        }
        return $value;
    }
    return 'Admin';
}

function createGalleryCategory(PDO $pdo, string $name, ?int $motherCategoryId = null): array
{
    $slug = slugify($name);
    if ($slug === '') {
        $slug = 'category';
    }
    $slug = ensureUniqueCategorySlug($pdo, $slug);
    $stmt = $pdo->prepare('INSERT INTO `gallery_category` (`name`, `slug`, `mother_category_id`) VALUES (:name, :slug, :mother)');
    $stmt->execute([
        ':name' => $name,
        ':slug' => $slug,
        ':mother' => $motherCategoryId
    ]);
    return [
        'id' => (int)$pdo->lastInsertId(),
        'name' => $name,
        'slug' => $slug,
        'mother_category_id' => $motherCategoryId
    ];
}

function updateGalleryCategory(PDO $pdo, int $id, string $name, ?int $motherCategoryId = null): array
{
    if ($motherCategoryId !== null) {
        if ($motherCategoryId === $id) {
            return ['status' => 'error', 'message' => 'A category cannot be its own mother.'];
        }
        if (!galleryCategoryExists($pdo, $motherCategoryId)) {
            return ['status' => 'error', 'message' => 'Selected mother category does not exist.'];
        }
    }
    $slug = slugify($name);
    if ($slug === '') {
        $slug = 'category';
    }
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM `gallery_category` WHERE `slug` = :slug AND `id` != :id');
        $stmt->execute([':slug' => $slug, ':id' => $id]);
        $count = (int)$stmt->fetchColumn();
    } catch (PDOException $err) {
        error_log('Failed to check category slug: ' . $err->getMessage());
        return ['status' => 'error', 'message' => 'Error verifying the category.'];
    }
    $attempt = 0;
    $baseSlug = $slug;
    while ($count > 0) {
        $attempt++;
        $slug = $baseSlug . '-' . $attempt;
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM `gallery_category` WHERE `slug` = :slug AND `id` != :id');
            $stmt->execute([':slug' => $slug, ':id' => $id]);
            $count = (int)$stmt->fetchColumn();
        } catch (PDOException $err) {
            error_log('Failed to check category slug: ' . $err->getMessage());
            return ['status' => 'error', 'message' => 'Error verifying the category.'];
        }
    }
    try {
        $stmt = $pdo->prepare('UPDATE `gallery_category` SET `name` = :name, `slug` = :slug, `mother_category_id` = :mother WHERE `id` = :id');
        $stmt->execute([
            ':name' => $name,
            ':slug' => $slug,
            ':mother' => $motherCategoryId,
            ':id' => $id
        ]);
    } catch (PDOException $err) {
        error_log('Failed to update gallery category: ' . $err->getMessage());
        return ['status' => 'error', 'message' => 'Error updating the category.'];
    }
    return ['status' => 'ok', 'message' => 'Category updated successfully.'];
}

function deleteGalleryCategory(PDO $pdo, int $id): array
{
    try {
        $stmt = $pdo->prepare('DELETE FROM `gallery_category` WHERE `id` = :id');
        $stmt->execute([':id' => $id]);
    } catch (PDOException $err) {
        error_log('Failed to delete gallery category: ' . $err->getMessage());
        return ['status' => 'error', 'message' => 'Cannot delete the category; please remove its images first.'];
    }
    return ['status' => 'ok', 'message' => 'Category deleted successfully.'];
}

function ensureUniqueCategorySlug(PDO $pdo, string $base): string
{
    $slug = $base;
    $attempt = 0;
    while (true) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM `gallery_category` WHERE `slug` = :slug');
        $stmt->execute([':slug' => $slug]);
        if ((int)$stmt->fetchColumn() === 0) {
            return $slug;
        }
        $attempt++;
        $slug = $base . '-' . $attempt;
    }
}

function slugify(string $value): string
{
    $value = mb_strtolower($value, 'UTF-8');
    $value = preg_replace('/[^\p{L}\p{N}]+/u', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value;
}

function galleryCategoryExists(PDO $pdo, int $id): bool
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM `gallery_category` WHERE `id` = :id');
        $stmt->execute([':id' => $id]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (PDOException $err) {
        error_log('Failed to check gallery category existence: ' . $err->getMessage());
        return false;
    }
}

function parseMotherCategoryId($value): ?int
{
    $id = (int)($value ?? 0);
    return $id > 0 ? $id : null;
}

function saveGalleryPhoto(PDO $pdo, array $payload, array $file): array
{
    $title = trim((string)($payload['title'] ?? ''));
    $alt = trim((string)($payload['alt_text'] ?? ''));
    $categoryId = (int)($payload['category_id'] ?? 0);
    if ($title === '') {
        return ['status' => 'error', 'message' => 'Image information is incomplete.'];
    }
    if ($categoryId === null || $categoryId <= 0) {
        $categoryId = null;
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['status' => 'error', 'message' => 'No image file was uploaded.'];
    }
    $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
    if (!in_array($extension, $allowed, true)) {
        return ['status' => 'error', 'message' => 'The file format is not supported.'];
    }
    $uploadDir = __DIR__ . '/../uploads/gallery';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        return ['status' => 'error', 'message' => 'Upload path is not writable.'];
    }
    $filename = uniqid('gallery-', true) . '.' . $extension;
    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return ['status' => 'error', 'message' => 'File upload failed.'];
    }
    $relativePath = 'uploads/gallery/' . $filename;
    try {
        $stmt = $pdo->prepare('INSERT INTO `gallery` (`filename`, `category_id`, `title`, `alt_text`) VALUES (:filename, :category_id, :title, :alt_text)');
        $stmt->execute([
            ':filename' => $relativePath,
            ':category_id' => $categoryId,
            ':title' => $title,
            ':alt_text' => $alt
        ]);
    } catch (PDOException $err) {
        error_log('Gallery photo insert failed: ' . $err->getMessage());
        return ['status' => 'error', 'message' => 'Error saving the image.'];
    }
    return ['status' => 'ok'];
}

function loadGalleryPhotoById(PDO $pdo, int $photoId): ?array
{
    if ($photoId <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT `photo_id`, `filename`, `category_id`, `title`, `alt_text` FROM `gallery` WHERE `photo_id` = :photo_id LIMIT 1');
        $stmt->execute([':photo_id' => $photoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $err) {
        error_log('Failed to fetch gallery photo: ' . $err->getMessage());
        return null;
    }
    return is_array($row) ? $row : null;
}

function updateGalleryPhoto(PDO $pdo, int $photoId, string $title, string $alt, ?int $categoryId): array
{
    if ($photoId <= 0 || $title === '') {
        return ['status' => 'error', 'message' => 'Photo information is incomplete.'];
    }
    if ($categoryId === null || $categoryId <= 0) {
        $categoryId = null;
    }
    try {
        $stmt = $pdo->prepare('UPDATE `gallery` SET `title` = :title, `alt_text` = :alt_text, `category_id` = :category_id WHERE `photo_id` = :photo_id');
        $stmt->execute([
            ':title' => $title,
            ':alt_text' => $alt,
            ':category_id' => $categoryId,
            ':photo_id' => $photoId
        ]);
    } catch (PDOException $err) {
        error_log('Failed to update gallery photo: ' . $err->getMessage());
        return ['status' => 'error', 'message' => 'Error updating the photo.'];
    }
    return ['status' => 'ok'];
}

function replaceGalleryPhoto(PDO $pdo, int $photoId, array $file): array
{
    if ($photoId <= 0) {
        return ['status' => 'error', 'message' => 'No photo selected.'];
    }
    $current = loadGalleryPhotoById($pdo, $photoId);
    if (!$current) {
        return ['status' => 'error', 'message' => 'Photo not found.'];
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['status' => 'error', 'message' => 'No image file was uploaded.'];
    }
    $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
    if (!in_array($extension, $allowed, true)) {
        return ['status' => 'error', 'message' => 'The file format is not supported.'];
    }
    $uploadDir = __DIR__ . '/../uploads/gallery';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        return ['status' => 'error', 'message' => 'Upload path is not writable.'];
    }
    $filename = uniqid('gallery-', true) . '.' . $extension;
    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return ['status' => 'error', 'message' => 'File upload failed.'];
    }
    $relativePath = 'uploads/gallery/' . $filename;
    try {
        $stmt = $pdo->prepare('UPDATE `gallery` SET `filename` = :filename, `uploaded_at` = CURRENT_TIMESTAMP WHERE `photo_id` = :photo_id');
        $stmt->execute([
            ':filename' => $relativePath,
            ':photo_id' => $photoId
        ]);
    } catch (PDOException $err) {
        @unlink($targetPath);
        error_log('Gallery photo replace failed: ' . $err->getMessage());
        return ['status' => 'error', 'message' => 'Error replacing the photo.'];
    }
    deleteGalleryPhotoFile((string)$current['filename'], $photoId);
    return ['status' => 'ok'];
}

function deleteGalleryPhoto(PDO $pdo, int $photoId): array
{
    if ($photoId <= 0) {
        return ['status' => 'error', 'message' => 'No photo selected.'];
    }
    $photo = loadGalleryPhotoById($pdo, $photoId);
    if (!$photo) {
        return ['status' => 'error', 'message' => 'Photo not found.'];
    }
    try {
        $stmt = $pdo->prepare('DELETE FROM `gallery` WHERE `photo_id` = :photo_id');
        $stmt->execute([':photo_id' => $photoId]);
    } catch (PDOException $err) {
        error_log('Failed to delete gallery photo: ' . $err->getMessage());
        return ['status' => 'error', 'message' => 'Error deleting the photo.'];
    }
    deleteGalleryPhotoFile((string)$photo['filename'], $photoId);
    return ['status' => 'ok'];
}

function deleteGalleryPhotoFile(string $relativePath, int $photoId): void
{
    $clean = ltrim(str_replace(['..\\', '../'], '', $relativePath), '/\\');
    if ($clean !== '') {
        $base = realpath(__DIR__ . '/../');
        if ($base !== false) {
            $normalizedBase = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $base);
            $fullPath = realpath($base . DIRECTORY_SEPARATOR . $clean);
            if ($fullPath !== false) {
                $normalizedFull = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fullPath);
                if (stripos($normalizedFull, $normalizedBase) === 0) {
                    @unlink($fullPath);
                }
            }
        }
    }
    $thumbPath = __DIR__ . '/../uploads/gallery/thumbs/' . $photoId . '.jpg';
    if (is_file($thumbPath)) {
        @unlink($thumbPath);
    }
}

function buildBackupPayload(array $data, ?PDO $pdo, array $config): array
{
    $payload = $data;
    unset($payload['databaseConnected'], $payload['databaseConfig'], $payload['backups']);
    $payload['exported_at'] = date(DATE_ATOM);
    $payload['databaseConnected'] = $pdo !== null;
    $payload['databaseConfig'] = getDatabaseConfigForResponse($config);
    return $payload;
}

function maybeRunAutomaticBackup(array &$data, ?PDO $pdo, array $config): bool
{
    $backupSettings = $data['settings']['backupSettings'] ?? [];
    $interval = max(0, (int)($backupSettings['autoIntervalMinutes'] ?? 0));
    if ($interval <= 0) {
        return false;
    }
    $last = $backupSettings['lastAutoBackupAt'] ?? null;
    $now = time();
    $lastTs = $last ? strtotime((string)$last) ?: 0 : 0;
    if ($last && ($now - $lastTs) < ($interval * 60)) {
        return false;
    }
    $package = prepareBackupPackage($data, $pdo, $config);
    if ($package['payloadJson'] === null) {
        return false;
    }
    $content = $package['archive'] ?? $package['payloadJson'];
    $extension = $package['archive'] !== null ? 'zip' : 'json';
    $limit = max(0, (int)($backupSettings['autoLimit'] ?? 0));
    $entry = recordBackupPayload($content, 'auto', $limit, $extension);
    if (!$entry) {
        return false;
    }
    if (!isset($data['settings']['backupSettings']) || !is_array($data['settings']['backupSettings'])) {
        $data['settings']['backupSettings'] = [];
    }
    $data['settings']['backupSettings']['lastAutoBackupAt'] = date(DATE_ATOM, $now);
    return true;
}

function prepareBackupPackage(array $data, ?PDO $pdo, array $config): array
{
    $payload = buildBackupPayload($data, $pdo, $config);
    $payloadJson = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $databaseName = trim((string)($config['dbname'] ?? ''));
    $sqlDump = null;
    if ($pdo && $databaseName !== '') {
        $sqlDump = buildSqlDump($pdo, $databaseName);
    }
    $archive = null;
    if ($payloadJson !== false) {
        $archive = buildBackupArchive($payloadJson, $sqlDump, __DIR__ . '/../uploads');
    }
    return [
        'payload' => $payload,
        'payloadJson' => $payloadJson === false ? null : $payloadJson,
        'archive' => $archive
    ];
}

function buildSqlDump(PDO $pdo, string $databaseName): ?string
{
    $databaseName = trim($databaseName);
    if ($databaseName === '') {
        return null;
    }
    $quotedDbName = '`' . str_replace('`', '``', $databaseName) . '`';
    $lines = [
        sprintf('CREATE DATABASE IF NOT EXISTS %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;', $quotedDbName),
        sprintf('USE %s;', $quotedDbName),
        'SET FOREIGN_KEY_CHECKS = 0;'
    ];

    try {
        $tables = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $err) {
        error_log('Failed to list tables for backup: ' . $err->getMessage());
        return null;
    }
    foreach ($tables as $row) {
        $tableName = '';
        $tableType = '';
        foreach ($row as $key => $value) {
            if ($tableName === '') {
                $tableName = (string)$value;
                continue;
            }
            if (strcasecmp($key, 'Table_type') === 0) {
                $tableType = (string)$value;
            }
        }
        $tableName = trim($tableName);
        if ($tableName === '' || strcasecmp($tableType, 'VIEW') === 0) {
            continue;
        }
        $quotedTable = '`' . str_replace('`', '``', $tableName) . '`';

        try {
            $stmt = $pdo->query(sprintf('SHOW CREATE TABLE %s', $quotedTable));
            $createRow = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        } catch (PDOException $err) {
            error_log('Failed to read table definition for ' . $tableName . ': ' . $err->getMessage());
            continue;
        }
        $createSql = '';
        if (is_array($createRow)) {
            $createSql = $createRow['Create Table'] ?? $createRow['Create View'] ?? '';
        }
        if ($createSql !== '') {
            $lines[] = sprintf('DROP TABLE IF EXISTS %s;', $quotedTable);
            $lines[] = $createSql . ';';
        }

        try {
            $dataStmt = $pdo->query(sprintf('SELECT * FROM %s', $quotedTable));
        } catch (PDOException $err) {
            error_log('Failed to dump rows from ' . $tableName . ': ' . $err->getMessage());
            continue;
        }
        $columnClause = '';
        $columnNames = [];
        while ($rowData = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
            if ($columnClause === '') {
                $columnNames = array_keys($rowData);
                $quotedColumns = array_map(static function ($column) {
                    return '`' . str_replace('`', '``', $column) . '`';
                }, $columnNames);
                $columnClause = implode(', ', $quotedColumns);
            }
            $values = [];
            foreach ($columnNames as $column) {
                $value = $rowData[$column] ?? null;
                if ($value === null) {
                    $values[] = 'NULL';
                    continue;
                }
                $values[] = $pdo->quote($value);
            }
            $lines[] = sprintf(
                'INSERT INTO %s (%s) VALUES (%s);',
                $quotedTable,
                $columnClause,
                implode(', ', $values)
            );
        }
    }

    $lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';
    return implode("\n\n", $lines) . "\n";
}

function buildBackupArchive(string $payloadJson, ?string $sqlDump, string $uploadsDir): ?string
{
    if (!class_exists('ZipArchive')) {
        return null;
    }
    $temp = @tempnam(sys_get_temp_dir(), 'gp-backup-');
    if ($temp === false) {
        return null;
    }
    $zip = new ZipArchive();
    if ($zip->open($temp, ZipArchive::OVERWRITE) !== true) {
        @unlink($temp);
        return null;
    }
    $zip->addFromString('payload.json', $payloadJson);
    if ($sqlDump !== null) {
        $zip->addFromString('database.sql', $sqlDump);
    }
    if (is_dir($uploadsDir)) {
        addDirectoryToZip($zip, $uploadsDir, 'uploads');
    }
    $zip->close();
    $content = file_get_contents($temp);
    @unlink($temp);
    return $content === false ? null : $content;
}

function addDirectoryToZip(ZipArchive $zip, string $directory, string $relativePrefix): void
{
    $root = rtrim($directory, '/\\');
    if ($root === '' || !is_dir($root)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }
        $path = $item->getRealPath();
        if ($path === false) {
            continue;
        }
        $relative = substr($path, strlen($root) + 1);
        if ($relative === false) {
            continue;
        }
        $normalized = str_replace(['\\', '/'], '/', $relative);
        $prefix = rtrim($relativePrefix, '/');
        $name = $prefix === '' ? $normalized : $prefix . '/' . $normalized;
        $zip->addFile($path, $name);
    }
}

function isBackupArchiveContent(string $content): bool
{
    return strlen($content) >= 2 && substr($content, 0, 2) === 'PK';
}

function decodeBackupPayloadContent(string $content): ?array
{
    if ($content === '') {
        return null;
    }
    $decoded = json_decode($content, true);
    if (is_array($decoded)) {
        return $decoded;
    }
    if (!isBackupArchiveContent($content) || !class_exists('ZipArchive')) {
        return null;
    }
    $temp = @tempnam(sys_get_temp_dir(), 'gp-backup-zip-');
    if ($temp === false || file_put_contents($temp, $content) === false) {
        @unlink($temp);
        return null;
    }
    $zip = new ZipArchive();
    $payload = null;
    if ($zip->open($temp) === true) {
        $index = $zip->locateName('payload.json', ZipArchive::FL_NOCASE);
        if ($index !== false) {
            $payloadJson = $zip->getFromIndex($index);
            if ($payloadJson !== false) {
                $payload = json_decode($payloadJson, true);
            }
        }
        $zip->close();
    }
    @unlink($temp);
    return is_array($payload) ? $payload : null;
}

function getBackupHistoryForResponse(): array
{
    $history = loadBackupMetadata();
    usort($history, function ($a, $b) {
        $aTime = isset($a['created_at']) ? strtotime($a['created_at']) : 0;
        $bTime = isset($b['created_at']) ? strtotime($b['created_at']) : 0;
        return $bTime <=> $aTime;
    });
    return $history;
}
