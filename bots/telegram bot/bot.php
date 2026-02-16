<?php

declare(strict_types=1);

const CONFIG_FILE = __DIR__ . '/bot.json';
const STATE_FILE = __DIR__ . '/state.json';

const DATA_DIR = __DIR__ . '/../../mini apps/Asset Manager/data';
const ASSETS_FILE = DATA_DIR . '/assets.json';
const STORAGES_FILE = DATA_DIR . '/storages.json';
const ANCESTOR_ASSETS_FILE = DATA_DIR . '/ancestor_assets.json';
const LEGACY_DATA_DIRS = [
    __DIR__ . '/../../mini apps/preopreties manager',
    __DIR__ . '/../../mini apps/Asset Manager data'
];

header('Content-Type: text/plain; charset=utf-8');

if (!is_file(CONFIG_FILE)) {
    http_response_code(500);
    echo "Missing bot.json configuration file.";
    exit;
}

$config = json_decode((string) file_get_contents(CONFIG_FILE), true);
if (!is_array($config) || empty($config['bot_token'])) {
    http_response_code(500);
    echo "Invalid bot.json configuration.";
    exit;
}

ensureAssetDataFiles();

if (!empty($config['webhook_secret_token'])) {
    $incomingSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!hash_equals((string) $config['webhook_secret_token'], (string) $incomingSecret)) {
        http_response_code(403);
        echo "Forbidden";
        exit;
    }
}

$rawInput = (string) file_get_contents('php://input');
$update = json_decode($rawInput, true);

if (!is_array($update)) {
    http_response_code(200);
    echo "No update";
    exit;
}

$token = (string) $config['bot_token'];

if (isset($update['callback_query']) && is_array($update['callback_query'])) {
    handleCallbackQuery($token, $update['callback_query']);
    http_response_code(200);
    echo "OK";
    exit;
}

if (isset($update['message']) && is_array($update['message'])) {
    handleMessage($token, $update['message']);
    http_response_code(200);
    echo "OK";
    exit;
}

http_response_code(200);
echo "Ignored";

function handleMessage(string $token, array $message): void
{
    $chatId = trim((string) ($message['chat']['id'] ?? ''));
    if ($chatId === '') {
        return;
    }

    $text = clean((string) ($message['text'] ?? ''));
    if ($text === '/start') {
        clearChatState($chatId);
        sendStartMenu($token, $chatId);
        return;
    }

    if ($text === '/cancel') {
        clearChatState($chatId);
        sendMessage($token, $chatId, 'عملیات لغو شد.');
        sendStartMenu($token, $chatId);
        return;
    }

    $state = getChatState($chatId);
    $step = (string) ($state['step'] ?? '');

    if ($step === 'awaiting_special_name') {
        if ($text === '') {
            sendMessage($token, $chatId, 'نام مال خاص را ارسال کنید.');
            return;
        }

        setChatState($chatId, [
            'step' => 'awaiting_storage',
            'asset_type' => 'special',
            'special_name' => $text
        ]);

        sendMessage($token, $chatId, 'نام ثبت شد: ' . $text);
        sendStorageMenu($token, $chatId);
        return;
    }

    sendStartMenu($token, $chatId);
}

function handleCallbackQuery(string $token, array $callbackQuery): void
{
    $callbackId = trim((string) ($callbackQuery['id'] ?? ''));
    $chatId = trim((string) ($callbackQuery['message']['chat']['id'] ?? ''));
    $data = trim((string) ($callbackQuery['data'] ?? ''));

    if ($callbackId !== '') {
        answerCallbackQuery($token, $callbackId);
    }
    if ($chatId === '') {
        return;
    }

    if ($data === 'menu_add_asset') {
        setChatState($chatId, ['step' => 'choosing_type']);
        sendAssetTypeMenu($token, $chatId);
        return;
    }

    if ($data === 'asset_type_common') {
        $ancestors = loadAncestors();
        if (!$ancestors) {
            sendMessage($token, $chatId, 'هیچ مال مرسومی ثبت نشده است.');
            sendStartMenu($token, $chatId);
            return;
        }

        setChatState($chatId, [
            'step' => 'choosing_ancestor',
            'asset_type' => 'common'
        ]);
        sendAncestorMenu($token, $chatId, $ancestors);
        return;
    }

    if ($data === 'asset_type_special') {
        setChatState($chatId, [
            'step' => 'awaiting_special_name',
            'asset_type' => 'special'
        ]);
        sendMessage($token, $chatId, 'نام مال خاص را ارسال کنید.');
        return;
    }

    if (strpos($data, 'ancestor:') === 0) {
        $ancestorId = trim(substr($data, strlen('ancestor:')));
        $ancestors = loadAncestors();
        $ancestor = findById($ancestors, $ancestorId);

        if (!is_array($ancestor)) {
            sendMessage($token, $chatId, 'مال مرسوم انتخاب شده معتبر نیست.');
            sendStartMenu($token, $chatId);
            return;
        }

        setChatState($chatId, [
            'step' => 'awaiting_storage',
            'asset_type' => 'common',
            'ancestor_id' => (string) $ancestor['id'],
            'ancestor_name' => (string) $ancestor['name']
        ]);

        sendMessage($token, $chatId, 'نام مال: ' . (string) $ancestor['name']);
        sendStorageMenu($token, $chatId);
        return;
    }

    if (strpos($data, 'storage:') === 0) {
        $storageId = trim(substr($data, strlen('storage:')));
        $state = getChatState($chatId);
        $step = (string) ($state['step'] ?? '');

        if ($step !== 'awaiting_storage') {
            sendMessage($token, $chatId, 'ابتدا از منوی شروع اقدام کنید.');
            sendStartMenu($token, $chatId);
            return;
        }

        $result = addAssetFromState($state, $storageId);
        if (!$result['ok']) {
            sendMessage($token, $chatId, (string) $result['message']);
            sendStartMenu($token, $chatId);
            return;
        }

        clearChatState($chatId);
        sendMessage($token, $chatId, (string) $result['message']);
        sendStartMenu($token, $chatId);
        return;
    }

    sendMessage($token, $chatId, 'گزینه نامعتبر است.');
    sendStartMenu($token, $chatId);
}

function sendStartMenu(string $token, string $chatId): void
{
    $markup = [
        'inline_keyboard' => [
            [
                ['text' => 'اضافه کردن اموال', 'callback_data' => 'menu_add_asset']
            ]
        ]
    ];

    sendMessage($token, $chatId, 'شروع انبار گردانی', $markup);
}

function sendAssetTypeMenu(string $token, string $chatId): void
{
    $markup = [
        'inline_keyboard' => [
            [
                ['text' => 'اموال مرسوم', 'callback_data' => 'asset_type_common']
            ],
            [
                ['text' => 'اموال خاص', 'callback_data' => 'asset_type_special']
            ]
        ]
    ];

    sendMessage($token, $chatId, 'نوع مال را انتخاب کنید:', $markup);
}

function sendAncestorMenu(string $token, string $chatId, array $ancestors): void
{
    $rows = [];
    foreach ($ancestors as $ancestor) {
        $ancestorId = trim((string) ($ancestor['id'] ?? ''));
        $ancestorName = clean((string) ($ancestor['name'] ?? ''));
        if ($ancestorId === '' || $ancestorName === '') {
            continue;
        }
        $rows[] = [
            ['text' => $ancestorName, 'callback_data' => 'ancestor:' . $ancestorId]
        ];
    }

    if (!$rows) {
        sendMessage($token, $chatId, 'هیچ مال مرسومی ثبت نشده است.');
        return;
    }

    sendMessage($token, $chatId, 'اموال مرسوم را انتخاب کنید:', [
        'inline_keyboard' => $rows
    ]);
}

function sendStorageMenu(string $token, string $chatId): void
{
    $storages = loadStorages();
    if (!$storages) {
        sendMessage($token, $chatId, 'هیچ انباری ثبت نشده است.');
        return;
    }

    $rows = [];
    foreach ($storages as $storage) {
        $storageId = trim((string) ($storage['id'] ?? ''));
        $storageName = clean((string) ($storage['name'] ?? ''));
        if ($storageId === '' || $storageName === '') {
            continue;
        }
        $rows[] = [
            ['text' => $storageName, 'callback_data' => 'storage:' . $storageId]
        ];
    }

    if (!$rows) {
        sendMessage($token, $chatId, 'هیچ انباری ثبت نشده است.');
        return;
    }

    sendMessage($token, $chatId, 'انبار را انتخاب کنید:', [
        'inline_keyboard' => $rows
    ]);
}

function answerCallbackQuery(string $token, string $callbackQueryId): void
{
    telegramRequest($token, 'answerCallbackQuery', [
        'callback_query_id' => $callbackQueryId
    ]);
}

function sendMessage(string $token, string $chatId, string $text, ?array $replyMarkup = null): void
{
    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
    ];
    if (is_array($replyMarkup)) {
        $payload['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
    }

    telegramRequest($token, 'sendMessage', $payload);
}

function telegramRequest(string $token, string $method, array $payload): void
{
    $url = "https://api.telegram.org/bot{$token}/{$method}";
    $postData = http_build_query($payload);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postData,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
            ]);
            curl_exec($ch);
            curl_close($ch);
            return;
        }
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'content' => $postData,
            'timeout' => 15,
        ],
    ]);
    @file_get_contents($url, false, $context);
}

function addAssetFromState(array $state, string $storageId): array
{
    $storageId = trim($storageId);
    if ($storageId === '') {
        return ['ok' => false, 'message' => 'انبار انتخاب شده معتبر نیست.'];
    }

    $storages = loadStorages();
    $ancestors = loadAncestors();
    $assets = loadAssets($ancestors);

    if (!storageExists($storages, $storageId)) {
        return ['ok' => false, 'message' => 'انبار انتخاب شده معتبر نیست.'];
    }

    $assetType = trim((string) ($state['asset_type'] ?? ''));
    $name = '';
    $ancestorId = '';
    $specialAsset = false;

    if ($assetType === 'common') {
        $ancestorId = trim((string) ($state['ancestor_id'] ?? ''));
        if ($ancestorId === '' || !ancestorExists($ancestors, $ancestorId)) {
            return ['ok' => false, 'message' => 'مال مرسوم انتخاب شده معتبر نیست.'];
        }
        $name = ancestorNameById($ancestors, $ancestorId);
        if ($name === '') {
            return ['ok' => false, 'message' => 'مال مرسوم انتخاب شده معتبر نیست.'];
        }
    } elseif ($assetType === 'special') {
        $specialAsset = true;
        $name = clean((string) ($state['special_name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'message' => 'نام مال خاص معتبر نیست.'];
        }
    } else {
        return ['ok' => false, 'message' => 'وضعیت عملیات نامعتبر است.'];
    }

    $code = generateUniqueAssetCode($assets);
    $now = date('c');
    $assets[] = [
        'id' => randomId(),
        'special_asset' => $specialAsset,
        'ancestor_id' => $specialAsset ? '' : $ancestorId,
        'name' => $name,
        'code' => $code,
        'storage_id' => $storageId,
        'created_at' => $now,
        'updated_at' => $now,
    ];

    if (!writeJsonList(ASSETS_FILE, $assets)) {
        return ['ok' => false, 'message' => 'ذخیره مال انجام نشد.'];
    }

    $storageName = storageNameById($storages, $storageId);
    $summary = "مال ثبت شد.\nنام: {$name}\nانبار: {$storageName}\nکد: {$code}";
    return ['ok' => true, 'message' => $summary];
}

function ensureAssetDataFiles(): void
{
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0755, true);
    }

    ensureFileInitialized(ASSETS_FILE, legacyPathCandidates('assets.json'));
    ensureFileInitialized(STORAGES_FILE, legacyPathCandidates('storages.json'));
    ensureFileInitialized(ANCESTOR_ASSETS_FILE, legacyPathCandidates('ancestor_assets.json'));
}

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

function clean(string $value): string
{
    $trimmed = trim($value);
    return preg_replace('/\s+/', ' ', $trimmed) ?? $trimmed;
}

function parseBool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function readJsonList(string $path): array
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? array_values($decoded) : [];
}

function writeJsonList(string $path, array $items): bool
{
    $json = json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    return @file_put_contents($path, $json . "\n", LOCK_EX) !== false;
}

function loadStorages(): array
{
    $items = [];
    foreach (readJsonList(STORAGES_FILE) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? ''));
        $name = clean((string) ($row['name'] ?? ''));
        if ($id === '' || $name === '') {
            continue;
        }
        $items[] = [
            'id' => $id,
            'name' => $name,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
    return $items;
}

function loadAncestors(): array
{
    $items = [];
    foreach (readJsonList(ANCESTOR_ASSETS_FILE) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? ''));
        $name = clean((string) ($row['name'] ?? ''));
        if ($id === '' || $name === '') {
            continue;
        }
        $items[] = [
            'id' => $id,
            'name' => $name,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
    return $items;
}

function loadAssets(array $ancestors = []): array
{
    $items = [];
    foreach (readJsonList(ASSETS_FILE) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? ''));
        $ancestorId = trim((string) ($row['ancestor_id'] ?? ''));
        $name = clean((string) ($row['name'] ?? ''));
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
        $code = clean((string) ($row['code'] ?? ''));
        $storageId = trim((string) ($row['storage_id'] ?? ''));
        if ($id === '' || $code === '' || ($specialAsset && $name === '')) {
            continue;
        }
        $items[] = [
            'id' => $id,
            'special_asset' => $specialAsset,
            'ancestor_id' => $ancestorId,
            'name' => $name,
            'code' => $code,
            'storage_id' => $storageId,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
    return $items;
}

function storageExists(array $storages, string $storageId): bool
{
    return findById($storages, $storageId) !== null;
}

function ancestorExists(array $ancestors, string $ancestorId): bool
{
    return findById($ancestors, $ancestorId) !== null;
}

function ancestorNameById(array $ancestors, string $ancestorId): string
{
    $ancestor = findById($ancestors, $ancestorId);
    if (!is_array($ancestor)) {
        return '';
    }
    return clean((string) ($ancestor['name'] ?? ''));
}

function ancestorIdByName(array $ancestors, string $name): string
{
    $needle = strtolower(clean($name));
    if ($needle === '') {
        return '';
    }
    foreach ($ancestors as $ancestor) {
        $ancestorName = strtolower(clean((string) ($ancestor['name'] ?? '')));
        if ($ancestorName === $needle) {
            return trim((string) ($ancestor['id'] ?? ''));
        }
    }
    return '';
}

function storageNameById(array $storages, string $storageId): string
{
    $storage = findById($storages, $storageId);
    if (!is_array($storage)) {
        return '';
    }
    return clean((string) ($storage['name'] ?? ''));
}

function findById(array $items, string $id): ?array
{
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        if (trim((string) ($item['id'] ?? '')) === $id) {
            return $item;
        }
    }
    return null;
}

function assetCodeExists(array $assets, string $code): bool
{
    $needle = strtolower(clean($code));
    if ($needle === '') {
        return false;
    }
    foreach ($assets as $asset) {
        $assetCode = strtolower(clean((string) ($asset['code'] ?? '')));
        if ($assetCode === $needle) {
            return true;
        }
    }
    return false;
}

function randomId(): string
{
    try {
        return bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        return substr(hash('sha256', uniqid('', true) . microtime(true)), 0, 16);
    }
}

function generateUniqueAssetCode(array $assets): string
{
    for ($i = 0; $i < 20; $i++) {
        $candidate = 'TG-' . date('Ymd-His') . '-' . strtoupper(substr(randomId(), 0, 4));
        if (!assetCodeExists($assets, $candidate)) {
            return $candidate;
        }
        usleep(25000);
    }

    do {
        $fallback = 'TG-' . strtoupper(substr(randomId(), 0, 12));
    } while (assetCodeExists($assets, $fallback));

    return $fallback;
}

function loadStates(): array
{
    if (!is_file(STATE_FILE)) {
        return [];
    }
    $raw = @file_get_contents(STATE_FILE);
    if ($raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function saveStates(array $states): void
{
    $json = json_encode($states, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return;
    }
    @file_put_contents(STATE_FILE, $json . "\n", LOCK_EX);
}

function getChatState(string $chatId): array
{
    $states = loadStates();
    $state = $states[$chatId] ?? [];
    return is_array($state) ? $state : [];
}

function setChatState(string $chatId, array $state): void
{
    $states = loadStates();
    $state['updated_at'] = date('c');
    $states[$chatId] = $state;
    saveStates($states);
}

function clearChatState(string $chatId): void
{
    $states = loadStates();
    if (array_key_exists($chatId, $states)) {
        unset($states[$chatId]);
        saveStates($states);
    }
}
