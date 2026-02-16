<?php

declare(strict_types=1);

const CONFIG_FILE = __DIR__ . '/bot.json';
const STATE_FILE = __DIR__ . '/state.json';
const LOG_FILE = __DIR__ . '/bot.log';

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

define('LOG_ENABLED', !array_key_exists('log_enabled', $config) || parseBool($config['log_enabled']));
logEvent('request_received', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
]);

ensureAssetDataFiles();

if (!empty($config['webhook_secret_token'])) {
    $incomingSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!hash_equals((string) $config['webhook_secret_token'], (string) $incomingSecret)) {
        logEvent('forbidden_bad_secret');
        http_response_code(403);
        echo "Forbidden";
        exit;
    }
}

$rawInput = (string) file_get_contents('php://input');
$update = json_decode($rawInput, true);

if (!is_array($update)) {
    logEvent('ignored_invalid_update_json');
    http_response_code(200);
    echo "No update";
    exit;
}

$token = (string) $config['bot_token'];

if (isset($update['callback_query']) && is_array($update['callback_query'])) {
    logEvent('incoming_callback_query', [
        'chat_id' => (string)($update['callback_query']['message']['chat']['id'] ?? ''),
        'from_id' => (string)($update['callback_query']['from']['id'] ?? ''),
        'data' => (string)($update['callback_query']['data'] ?? '')
    ]);
    handleCallbackQuery($token, $update['callback_query']);
    http_response_code(200);
    echo "OK";
    exit;
}

if (isset($update['message']) && is_array($update['message'])) {
    logEvent('incoming_message', [
        'chat_id' => (string)($update['message']['chat']['id'] ?? ''),
        'from_id' => (string)($update['message']['from']['id'] ?? ''),
        'text' => (string)($update['message']['text'] ?? '')
    ]);
    handleMessage($token, $update['message']);
    http_response_code(200);
    echo "OK";
    exit;
}

logEvent('ignored_unsupported_update', ['keys' => array_keys($update)]);
http_response_code(200);
echo "Ignored";

function handleMessage(string $token, array $message): void
{
    $chatId = trim((string) ($message['chat']['id'] ?? ''));
    if ($chatId === '') {
        logEvent('message_missing_chat_id');
        return;
    }

    $text = clean((string) ($message['text'] ?? ''));
    if ($text === '/start') {
        logEvent('command_start', ['chat_id' => $chatId]);
        clearChatState($chatId);
        sendStartMenu($token, $chatId);
        return;
    }

    if ($text === '/cancel') {
        logEvent('command_cancel', ['chat_id' => $chatId]);
        clearChatState($chatId);
        sendMessage($token, $chatId, 'عملیات لغو شد.');
        sendStartMenu($token, $chatId);
        return;
    }

    $state = getChatState($chatId);
    $step = (string) ($state['step'] ?? '');

    if ($step === 'awaiting_special_name') {
        if ($text === '') {
            logEvent('special_name_empty', ['chat_id' => $chatId]);
            sendMessage($token, $chatId, 'نام مال خاص را ارسال کنید.');
            return;
        }

        setChatState($chatId, [
            'step' => 'awaiting_storage',
            'asset_type' => 'special',
            'special_name' => $text
        ]);

        logEvent('special_name_received', ['chat_id' => $chatId, 'name' => $text]);
        sendMessage($token, $chatId, 'نام ثبت شد: ' . $text);
        sendStorageMenu($token, $chatId);
        return;
    }

    logEvent('message_default_to_start_menu', ['chat_id' => $chatId, 'step' => $step, 'text' => $text]);
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
        logEvent('callback_missing_chat_id', ['data' => $data]);
        return;
    }

    if ($data === 'menu_add_asset') {
        logEvent('callback_menu_add_asset', ['chat_id' => $chatId]);
        setChatState($chatId, ['step' => 'choosing_type']);
        sendAssetTypeMenu($token, $chatId);
        return;
    }

    if ($data === 'asset_type_common') {
        logEvent('callback_asset_type_common', ['chat_id' => $chatId]);
        $ancestors = loadAncestors();
        if (!$ancestors) {
            logEvent('common_no_ancestors', ['chat_id' => $chatId]);
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
        logEvent('callback_asset_type_special', ['chat_id' => $chatId]);
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
            logEvent('ancestor_invalid', ['chat_id' => $chatId, 'ancestor_id' => $ancestorId]);
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

        logEvent('ancestor_selected', [
            'chat_id' => $chatId,
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
            logEvent('storage_click_invalid_step', ['chat_id' => $chatId, 'step' => $step, 'storage_id' => $storageId]);
            sendMessage($token, $chatId, 'ابتدا از منوی شروع اقدام کنید.');
            sendStartMenu($token, $chatId);
            return;
        }

        $result = addAssetFromState($state, $storageId);
        if (!$result['ok']) {
            logEvent('asset_save_failed', ['chat_id' => $chatId, 'storage_id' => $storageId, 'message' => (string) $result['message']]);
            sendMessage($token, $chatId, (string) $result['message']);
            sendStartMenu($token, $chatId);
            return;
        }

        logEvent('asset_saved', ['chat_id' => $chatId, 'storage_id' => $storageId]);
        clearChatState($chatId);
        sendMessage($token, $chatId, (string) $result['message']);
        sendStartMenu($token, $chatId);
        return;
    }

    logEvent('callback_unknown_data', ['chat_id' => $chatId, 'data' => $data]);
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

    logEvent('telegram_send_message', [
        'chat_id' => $chatId,
        'text' => textSnippet($text, 180),
        'has_reply_markup' => is_array($replyMarkup)
    ]);
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
            $response = curl_exec($ch);
            $error = curl_error($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            logEvent('telegram_request', [
                'method' => $method,
                'http_code' => $httpCode,
                'curl_error' => $error,
                'response' => is_string($response) ? textSnippet($response, 400) : ''
            ]);
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
    $response = @file_get_contents($url, false, $context);
    logEvent('telegram_request_fopen', [
        'method' => $method,
        'response' => is_string($response) ? textSnippet($response, 400) : ''
    ]);
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
        if (!@mkdir(DATA_DIR, 0755, true) && !is_dir(DATA_DIR)) {
            logEvent('mkdir_failed', ['path' => DATA_DIR]);
        }
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
            logEvent('file_initialized_from_legacy', ['target' => $targetPath, 'source' => $candidate]);
            return;
        }
    }

    if (@file_put_contents($targetPath, "[]\n", LOCK_EX) === false) {
        logEvent('file_init_failed', ['target' => $targetPath]);
    } else {
        logEvent('file_initialized_empty', ['target' => $targetPath]);
    }
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
    logEvent('state_set', ['chat_id' => $chatId, 'step' => (string)($state['step'] ?? '')]);
    saveStates($states);
}

function clearChatState(string $chatId): void
{
    $states = loadStates();
    if (array_key_exists($chatId, $states)) {
        unset($states[$chatId]);
        logEvent('state_cleared', ['chat_id' => $chatId]);
        saveStates($states);
    }
}

function textSnippet(string $value, int $length): string
{
    if (function_exists('mb_substr')) {
        return (string) mb_substr($value, 0, $length);
    }
    return (string) substr($value, 0, $length);
}

function logEvent(string $event, array $context = []): void
{
    if (defined('LOG_ENABLED') && LOG_ENABLED === false) {
        return;
    }

    $line = json_encode([
        'time' => date('c'),
        'event' => $event,
        'context' => $context
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (!is_string($line)) {
        return;
    }

    @file_put_contents(LOG_FILE, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}
