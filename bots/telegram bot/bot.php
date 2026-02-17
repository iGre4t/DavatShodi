<?php

declare(strict_types=1);

const CONFIG_FILE = __DIR__ . '/bot.json';
const STATE_FILE = __DIR__ . '/state.json';
const LOG_FILE = __DIR__ . '/bot.log';

const DATA_DIR = __DIR__ . '/../../mini apps/Asset Manager/data';
const ASSETS_FILE = DATA_DIR . '/assets.json';
const STORAGES_FILE = DATA_DIR . '/storages.json';
const ANCESTOR_ASSETS_FILE = DATA_DIR . '/ancestor_assets.json';
const LABELS_FILE = DATA_DIR . '/labels.json';
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
        sendMessage(
            $token,
            $chatId,
            "🛑 <b>فرآیند ثبت دارایی متوقف شد.</b>\n\nاگر تمایل داشتید، از منوی زیر دوباره و خیلی سریع شروع کنید."
        );
        sendStartMenu($token, $chatId);
        return;
    }

    $state = getChatState($chatId);
    $step = (string) ($state['step'] ?? '');

    if ($step === 'awaiting_special_name') {
        if ($text === '') {
            logEvent('special_name_empty', ['chat_id' => $chatId]);
            sendMessage(
                $token,
                $chatId,
                "✍️ <b>نام مال خاص دریافت نشد.</b>\n\nلطفا نام مال خاص را به‌صورت یک پیام متنی ارسال کنید تا وارد مرحله بعد شویم."
            );
            return;
        }

        setChatState($chatId, [
            'step' => 'awaiting_asset_code',
            'asset_type' => 'special',
            'special_name' => $text
        ]);

        logEvent('special_name_received', ['chat_id' => $chatId, 'name' => $text]);
        $safeName = htmlEscape($text);
        sendMessage(
            $token,
            $chatId,
            "✅ <b>نام مال خاص با موفقیت ثبت شد.</b>\n\n🧾 عنوان ثبت‌شده: <b>{$safeName}</b>\n\n🔐 لطفا در پیام بعدی، <b>کد مال</b> را ارسال کنید."
        );
        return;
    }

    if ($step === 'awaiting_asset_code') {
        $assetCode = normalizeAssetCode($text);
        if ($assetCode === '') {
            logEvent('asset_code_empty', ['chat_id' => $chatId]);
            sendMessage(
                $token,
                $chatId,
                "⚠️ <b>کد مال معتبر نیست.</b>\n\nلطفا یک کد معتبر و کوتاه برای مال ارسال کنید."
            );
            return;
        }

        $labels = loadLabels();
        $assets = loadAssets(loadAncestors($labels), $labels);
        if (assetCodeExists($assets, $assetCode)) {
            logEvent('asset_code_duplicate', ['chat_id' => $chatId, 'code' => $assetCode]);
            sendMessage(
                $token,
                $chatId,
                "❌ <b>این کد قبلا ثبت شده است.</b>\n\nلطفا یک کد جدید و یکتا برای دارایی ارسال کنید."
            );
            return;
        }

        $state['asset_code'] = $assetCode;
        $state['step'] = 'awaiting_storage';
        setChatState($chatId, $state);

        logEvent('asset_code_received', ['chat_id' => $chatId, 'code' => $assetCode]);
        $safeCode = htmlEscape($assetCode);
        sendStorageMenu(
            $token,
            $chatId,
            null,
            "✅ <b>کد دارایی ثبت شد.</b>\n\n🔐 کد ثبت‌شده: <b>{$safeCode}</b>\n\n🏬 حالا انبار موردنظر را از لیست زیر انتخاب کنید."
        );
        return;
    }

    if (in_array($step, ['choosing_label_parent', 'choosing_label_child'], true)) {
        sendMessage(
            $token,
            $chatId,
            "🏷️ <b>در حال انتخاب برچسب هستید.</b>\n\nلطفا فقط از دکمه‌های زیر همان پیام استفاده کنید، یا گزینه «⏭️ رد کردن برچسب‌ها» را بزنید."
        );
        return;
    }

    logEvent('message_default_to_start_menu', ['chat_id' => $chatId, 'step' => $step, 'text' => $text]);
    sendStartMenu($token, $chatId);
}

function handleCallbackQuery(string $token, array $callbackQuery): void
{
    $callbackId = trim((string) ($callbackQuery['id'] ?? ''));
    $chatId = trim((string) ($callbackQuery['message']['chat']['id'] ?? ''));
    $messageId = trim((string) ($callbackQuery['message']['message_id'] ?? ''));
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
        sendAssetTypeMenu($token, $chatId, $messageId);
        return;
    }

    if ($data === 'asset_type_common') {
        logEvent('callback_asset_type_common', ['chat_id' => $chatId]);
        $labels = loadLabels();
        $ancestors = loadAncestors($labels);
        if (!$ancestors) {
            logEvent('common_no_ancestors', ['chat_id' => $chatId]);
            sendOrEditMessage(
                $token,
                $chatId,
                $messageId,
                "⚠️ <b>لیست اموال مرسوم خالی است</b>\n\nدر حال حاضر موردی برای انتخاب وجود ندارد.\nابتدا در پنل، اموال مرسوم را ثبت کنید.",
                getStartMenuMarkup()
            );
            return;
        }

        setChatState($chatId, [
            'step' => 'choosing_ancestor',
            'asset_type' => 'common'
        ]);
        sendAncestorMenu($token, $chatId, $ancestors, $messageId);
        return;
    }

    if ($data === 'asset_type_special') {
        logEvent('callback_asset_type_special', ['chat_id' => $chatId]);
        setChatState($chatId, [
            'step' => 'awaiting_special_name',
            'asset_type' => 'special'
        ]);
        sendOrEditMessage(
            $token,
            $chatId,
            $messageId,
            "⭐ <b>ثبت مال خاص</b>\n\nلطفا نام مال خاص را در یک پیام متنی ارسال کنید تا مرحله بعد فعال شود.",
            null
        );
        return;
    }

    if (strpos($data, 'ancestor:') === 0) {
        $ancestorId = trim(substr($data, strlen('ancestor:')));
        $labels = loadLabels();
        $ancestors = loadAncestors($labels);
        $ancestor = findById($ancestors, $ancestorId);

        if (!is_array($ancestor)) {
            logEvent('ancestor_invalid', ['chat_id' => $chatId, 'ancestor_id' => $ancestorId]);
            sendOrEditMessage(
                $token,
                $chatId,
                $messageId,
                "⚠️ <b>انتخاب نامعتبر</b>\n\nمال مرسوم انتخاب‌شده معتبر نیست. دوباره از ابتدا اقدام کنید.",
                getStartMenuMarkup()
            );
            return;
        }

        $ancestorLabelParentIds = sanitizeParentLabelIds($labels, (array) ($ancestor['label_ids'] ?? []));
        setChatState($chatId, [
            'step' => $ancestorLabelParentIds ? 'choosing_label_parent' : 'awaiting_asset_code',
            'asset_type' => 'common',
            'ancestor_id' => (string) $ancestor['id'],
            'ancestor_name' => (string) $ancestor['name'],
            'ancestor_label_parent_ids' => $ancestorLabelParentIds,
            'label_values' => [],
        ]);

        logEvent('ancestor_selected', [
            'chat_id' => $chatId,
            'ancestor_id' => (string) $ancestor['id'],
            'ancestor_name' => (string) $ancestor['name']
        ]);

        $ancestorName = htmlEscape((string) $ancestor['name']);
        if ($ancestorLabelParentIds) {
            sendParentLabelMenu($token, $chatId, getChatState($chatId), $labels, $messageId, $ancestorName);
        } else {
            sendOrEditMessage(
                $token,
                $chatId,
                $messageId,
                "✅ <b>مال مرسوم انتخاب شد</b>\n\nنام انتخاب‌شده: <b>{$ancestorName}</b>\n\n✍️ لطفا حالا <b>کد مال</b> را در یک پیام متنی ارسال کنید.",
                null
            );
        }
        return;
    }

    if ($data === 'label_skip') {
        $state = getChatState($chatId);
        $step = (string) ($state['step'] ?? '');
        if (!in_array($step, ['choosing_label_parent', 'choosing_label_child'], true)) {
            sendOrEditMessage(
                $token,
                $chatId,
                $messageId,
                "⚠️ <b>وضعیت مرحله معتبر نیست</b>\n\nلطفا دوباره از ابتدا اقدام کنید.",
                getStartMenuMarkup()
            );
            return;
        }

        $state['step'] = 'awaiting_asset_code';
        unset($state['active_parent_label_id']);
        setChatState($chatId, $state);
        logEvent('labels_skipped', ['chat_id' => $chatId]);

        sendOrEditMessage(
            $token,
            $chatId,
            $messageId,
            "⏭️ <b>مرحله برچسب رد شد</b>\n\nخیلی خوب، بدون برچسب ادامه می‌دهیم.\n✍️ لطفا الان <b>کد مال</b> را ارسال کنید.",
            null
        );
        return;
    }

    if ($data === 'label_back_parents') {
        $state = getChatState($chatId);
        $step = (string) ($state['step'] ?? '');
        if ($step !== 'choosing_label_child') {
            sendOrEditMessage(
                $token,
                $chatId,
                $messageId,
                "ℹ️ <b>ابتدا یک برچسب والد انتخاب کنید</b>\n\nبعد از ورود به لیست فرزندها، دکمه بازگشت قابل استفاده است.",
                null
            );
            return;
        }

        $labels = loadLabels();
        $state['step'] = 'choosing_label_parent';
        unset($state['active_parent_label_id']);
        setChatState($chatId, $state);
        sendParentLabelMenu($token, $chatId, $state, $labels, $messageId);
        return;
    }

    if (strpos($data, 'label_parent:') === 0) {
        $parentId = trim(substr($data, strlen('label_parent:')));
        $state = getChatState($chatId);
        $step = (string) ($state['step'] ?? '');
        if (!in_array($step, ['choosing_label_parent', 'choosing_label_child'], true)) {
            sendOrEditMessage(
                $token,
                $chatId,
                $messageId,
                "⚠️ <b>مسیر انتخاب برچسب معتبر نیست</b>\n\nلطفا دوباره از ابتدا شروع کنید.",
                getStartMenuMarkup()
            );
            return;
        }

        $labels = loadLabels();
        $parentIds = sanitizeParentLabelIds($labels, (array) ($state['ancestor_label_parent_ids'] ?? []));
        if (!in_array($parentId, $parentIds, true)) {
            sendParentLabelMenu($token, $chatId, $state, $labels, $messageId);
            return;
        }

        $state['step'] = 'choosing_label_child';
        $state['active_parent_label_id'] = $parentId;
        setChatState($chatId, $state);
        sendChildLabelMenu($token, $chatId, $parentId, $labels, $messageId);
        return;
    }

    if (strpos($data, 'label_child:') === 0) {
        $rest = trim(substr($data, strlen('label_child:')));
        $parts = explode(':', $rest, 2);
        $parentId = trim((string) ($parts[0] ?? ''));
        $childId = trim((string) ($parts[1] ?? ''));

        $state = getChatState($chatId);
        $step = (string) ($state['step'] ?? '');
        if (!in_array($step, ['choosing_label_parent', 'choosing_label_child'], true)) {
            sendOrEditMessage(
                $token,
                $chatId,
                $messageId,
                "⚠️ <b>مسیر انتخاب برچسب معتبر نیست</b>\n\nلطفا دوباره از ابتدا شروع کنید.",
                getStartMenuMarkup()
            );
            return;
        }

        $labels = loadLabels();
        $parentIds = sanitizeParentLabelIds($labels, (array) ($state['ancestor_label_parent_ids'] ?? []));
        if (!in_array($parentId, $parentIds, true) || !labelIsDirectChild($labels, $parentId, $childId)) {
            sendParentLabelMenu($token, $chatId, $state, $labels, $messageId);
            return;
        }

        $labelValues = is_array($state['label_values'] ?? null) ? $state['label_values'] : [];
        $labelValues[$parentId] = $childId;
        $state['label_values'] = $labelValues;
        $state['step'] = 'choosing_label_parent';
        unset($state['active_parent_label_id']);
        setChatState($chatId, $state);

        logEvent('label_child_selected', [
            'chat_id' => $chatId,
            'parent_id' => $parentId,
            'child_id' => $childId,
        ]);
        sendParentLabelMenu($token, $chatId, $state, $labels, $messageId);
        return;
    }

    if (strpos($data, 'storage:') === 0) {
        $storageId = trim(substr($data, strlen('storage:')));
        $state = getChatState($chatId);
        $step = (string) ($state['step'] ?? '');

        if ($step !== 'awaiting_storage') {
            logEvent('storage_click_invalid_step', ['chat_id' => $chatId, 'step' => $step, 'storage_id' => $storageId]);
            if ($step === 'awaiting_asset_code') {
                sendOrEditMessage(
                    $token,
                    $chatId,
                    $messageId,
                    "✍️ <b>ابتدا کد مال را ارسال کنید</b>\n\nبعد از ثبت کد، انتخاب انبار فعال می‌شود.",
                    null
                );
                return;
            }
            if (in_array($step, ['choosing_label_parent', 'choosing_label_child'], true)) {
                sendOrEditMessage(
                    $token,
                    $chatId,
                    $messageId,
                    "🏷️ <b>ابتدا مرحله برچسب‌ها را تکمیل کنید</b>\n\nیک برچسب انتخاب کنید یا گزینه رد کردن را بزنید.",
                    null
                );
                return;
            }
            sendOrEditMessage(
                $token,
                $chatId,
                $messageId,
                "ℹ️ <b>این عملیات در وضعیت فعلی قابل انجام نیست</b>\n\nلطفا دوباره از منوی شروع اقدام کنید.",
                getStartMenuMarkup()
            );
            return;
        }

        $result = addAssetFromState($state, $storageId);
        if (!$result['ok']) {
            logEvent('asset_save_failed', ['chat_id' => $chatId, 'storage_id' => $storageId, 'message' => (string) $result['message']]);
            $errorText = htmlEscape((string) $result['message']);
            sendOrEditMessage(
                $token,
                $chatId,
                $messageId,
                "❌ <b>ثبت مال انجام نشد</b>\n\n{$errorText}\n\nمی‌توانید دوباره از ابتدا اقدام کنید.",
                getStartMenuMarkup()
            );
            return;
        }

        logEvent('asset_saved', ['chat_id' => $chatId, 'storage_id' => $storageId]);
        clearChatState($chatId);
        $nameText = htmlEscape((string) ($result['name'] ?? ''));
        $codeText = htmlEscape((string) ($result['code'] ?? ''));
        $storageText = htmlEscape((string) ($result['storage_name'] ?? ''));
        $successText = "✅ <b>مال با موفقیت ثبت شد</b>\n\n"
            . "🧾 نام مال: <b>{$nameText}</b>\n"
            . "🔐 کد مال: <b>{$codeText}</b>\n"
            . "🏬 انبار: <b>{$storageText}</b>\n\n"
            . "اگر می‌خواهید مورد بعدی را ثبت کنید، از دکمه زیر استفاده کنید.";
        sendOrEditMessage($token, $chatId, $messageId, $successText, getStartMenuMarkup());
        return;
    }

    logEvent('callback_unknown_data', ['chat_id' => $chatId, 'data' => $data]);
    sendOrEditMessage(
        $token,
        $chatId,
        $messageId,
        "⚠️ <b>گزینه انتخابی معتبر نیست</b>\n\nلطفا دوباره از منوی شروع انتخاب کنید.",
        getStartMenuMarkup()
    );
}

function getStartMenuMarkup(): array
{
    return [
        'inline_keyboard' => [
            [
                ['text' => '➕ اضافه کردن اموال', 'callback_data' => 'menu_add_asset']
            ]
        ]
    ];
}

function sendStartMenu(string $token, string $chatId): void
{
    sendMessage(
        $token,
        $chatId,
        "📦 <b>شروع انبار گردانی</b>\n\nبه ربات مدیریت اموال خوش آمدید.\nبرای ثبت یک دارایی جدید، از دکمه زیر استفاده کنید.",
        getStartMenuMarkup()
    );
}

function sendAssetTypeMenu(string $token, string $chatId, ?string $messageId = null): void
{
    $markup = [
        'inline_keyboard' => [
            [
                ['text' => '📁 اموال مرسوم', 'callback_data' => 'asset_type_common']
            ],
            [
                ['text' => '⭐ اموال خاص', 'callback_data' => 'asset_type_special']
            ]
        ]
    ];

    sendOrEditMessage(
        $token,
        $chatId,
        $messageId,
        "🧭 <b>انتخاب نوع دارایی</b>\n\nبرای ادامه فرآیند، لطفا نوع دارایی را از گزینه‌های زیر انتخاب کنید.",
        $markup
    );
}

function sendAncestorMenu(string $token, string $chatId, array $ancestors, ?string $messageId = null): void
{
    $rows = [];
    foreach ($ancestors as $ancestor) {
        $ancestorId = trim((string) ($ancestor['id'] ?? ''));
        $ancestorName = clean((string) ($ancestor['name'] ?? ''));
        if ($ancestorId === '' || $ancestorName === '') {
            continue;
        }
        $rows[] = [
            ['text' => '🔹 ' . $ancestorName, 'callback_data' => 'ancestor:' . $ancestorId]
        ];
    }

    if (!$rows) {
        sendOrEditMessage(
            $token,
            $chatId,
            $messageId,
            "⚠️ <b>مال مرسومی برای انتخاب وجود ندارد.</b>\n\nابتدا از پنل مدیریت، موردهای اموال مرسوم را ثبت کنید.",
            getStartMenuMarkup()
        );
        return;
    }

    sendOrEditMessage(
        $token,
        $chatId,
        $messageId,
        "📁 <b>انتخاب اموال مرسوم</b>\n\nیکی از گزینه‌های زیر را انتخاب کنید تا مرحله بعد فعال شود.",
        ['inline_keyboard' => $rows]
    );
}

function sendStorageMenu(
    string $token,
    string $chatId,
    ?string $messageId = null,
    ?string $customText = null
): void
{
    $storages = loadStorages();
    if (!$storages) {
        sendOrEditMessage(
            $token,
            $chatId,
            $messageId,
            "⚠️ <b>انباری برای انتخاب پیدا نشد.</b>\n\nلطفا ابتدا انبارها را در پنل تعریف کنید.",
            getStartMenuMarkup()
        );
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
            ['text' => '🏬 ' . $storageName, 'callback_data' => 'storage:' . $storageId]
        ];
    }

    if (!$rows) {
        sendOrEditMessage(
            $token,
            $chatId,
            $messageId,
            "⚠️ <b>انباری برای انتخاب پیدا نشد.</b>\n\nلطفا ابتدا انبارها را در پنل تعریف کنید.",
            getStartMenuMarkup()
        );
        return;
    }

    $text = $customText !== null
        ? $customText
        : "🏬 <b>انتخاب انبار</b>\n\nلطفا انبار مقصد این دارایی را انتخاب کنید تا ثبت نهایی انجام شود.";
    sendOrEditMessage($token, $chatId, $messageId, $text, ['inline_keyboard' => $rows]);
}

function sendParentLabelMenu(
    string $token,
    string $chatId,
    array $state,
    ?array $labels = null,
    ?string $messageId = null,
    ?string $ancestorName = null
): void {
    if (!is_array($labels)) {
        $labels = loadLabels();
    }

    $parentIds = sanitizeParentLabelIds($labels, (array) ($state['ancestor_label_parent_ids'] ?? []));
    if (!$parentIds) {
        $state['step'] = 'awaiting_asset_code';
        unset($state['active_parent_label_id']);
        setChatState($chatId, $state);
        sendOrEditMessage(
            $token,
            $chatId,
            $messageId,
            "ℹ️ <b>برای این مال، برچسبی تعریف نشده است.</b>\n\nلطفا حالا <b>کد مال</b> را در یک پیام متنی ارسال کنید.",
            null
        );
        return;
    }

    $labelValues = is_array($state['label_values'] ?? null) ? $state['label_values'] : [];
    $rows = [];
    $selectedLines = [];
    foreach ($parentIds as $parentId) {
        $parent = findById($labels, $parentId);
        if (!is_array($parent)) {
            continue;
        }
        $parentName = clean((string) ($parent['name'] ?? ''));
        if ($parentName === '') {
            continue;
        }

        $buttonText = '🏷️ ' . $parentName;
        $selectedChildId = trim((string) ($labelValues[$parentId] ?? ''));
        if ($selectedChildId !== '' && labelIsDirectChild($labels, $parentId, $selectedChildId)) {
            $child = findById($labels, $selectedChildId);
            $childName = clean((string) ($child['name'] ?? ''));
            if ($childName !== '') {
                $buttonText = '✅ ' . $parentName . ': ' . $childName;
                $selectedLines[] = '• ' . htmlEscape($parentName) . ': <b>' . htmlEscape($childName) . '</b>';
            }
        }

        $rows[] = [
            ['text' => $buttonText, 'callback_data' => 'label_parent:' . $parentId]
        ];
    }

    $rows[] = [
        ['text' => '⏭️ رد کردن برچسب‌ها', 'callback_data' => 'label_skip']
    ];

    $resolvedAncestorName = $ancestorName !== null
        ? $ancestorName
        : htmlEscape(clean((string) ($state['ancestor_name'] ?? '')));
    if ($resolvedAncestorName === '') {
        $resolvedAncestorName = 'نامشخص';
    }
    $selectedSection = $selectedLines
        ? "\n\n✅ <b>برچسب‌های انتخاب‌شده:</b>\n" . implode("\n", $selectedLines)
        : "\n\nهنوز برچسبی انتخاب نشده است.";

    $text = "🏷️ <b>افزودن برچسب به دارایی (اختیاری)</b>\n\n"
        . "📦 مال انتخاب‌شده: <b>{$resolvedAncestorName}</b>\n"
        . "لطفا یک <b>برچسب والد</b> را انتخاب کنید تا زیر‌برچسب‌های آن نمایش داده شود."
        . $selectedSection
        . "\n\nاگر مایل نیستید برچسب اضافه کنید، گزینه «⏭️ رد کردن برچسب‌ها» را بزنید.";

    sendOrEditMessage($token, $chatId, $messageId, $text, [
        'inline_keyboard' => $rows
    ]);
}

function sendChildLabelMenu(
    string $token,
    string $chatId,
    string $parentId,
    ?array $labels = null,
    ?string $messageId = null
): void {
    if (!is_array($labels)) {
        $labels = loadLabels();
    }

    $parent = findById($labels, $parentId);
    if (!is_array($parent)) {
        sendOrEditMessage(
            $token,
            $chatId,
            $messageId,
            "⚠️ <b>برچسب والد انتخاب‌شده معتبر نیست.</b>\n\nلطفا دوباره از لیست والدها انتخاب کنید.",
            [
                'inline_keyboard' => [
                    [
                        ['text' => '⬅️ بازگشت به والدها', 'callback_data' => 'label_back_parents']
                    ]
                ]
            ]
        );
        return;
    }

    $rows = [];
    foreach ($labels as $label) {
        if (!is_array($label)) {
            continue;
        }
        $id = trim((string) ($label['id'] ?? ''));
        $name = clean((string) ($label['name'] ?? ''));
        $labelParentId = trim((string) ($label['parent_id'] ?? ''));
        if ($id === '' || $name === '' || $labelParentId !== $parentId) {
            continue;
        }
        $rows[] = [
            ['text' => '🔸 ' . $name, 'callback_data' => 'label_child:' . $parentId . ':' . $id]
        ];
    }

    $rows[] = [
        ['text' => '⬅️ بازگشت به والدها', 'callback_data' => 'label_back_parents']
    ];

    $parentName = htmlEscape(clean((string) ($parent['name'] ?? '')));
    $prefixText = "🧩 <b>انتخاب زیر‌برچسب</b>\n\n"
        . "🏷️ برچسب والد: <b>{$parentName}</b>\n"
        . "لطفا یکی از زیر‌برچسب‌های این گروه را انتخاب کنید.";

    if (count($rows) === 1) {
        sendOrEditMessage(
            $token,
            $chatId,
            $messageId,
            $prefixText . "\n\n⚠️ برای این والد، زیر‌برچسبی ثبت نشده است.",
            ['inline_keyboard' => $rows]
        );
        return;
    }

    sendOrEditMessage($token, $chatId, $messageId, $prefixText, [
        'inline_keyboard' => $rows
    ]);
}

function answerCallbackQuery(string $token, string $callbackQueryId): void
{
    telegramRequest($token, 'answerCallbackQuery', [
        'callback_query_id' => $callbackQueryId
    ]);
}

function sendOrEditMessage(
    string $token,
    string $chatId,
    ?string $messageId,
    string $text,
    ?array $replyMarkup = null
): void {
    $normalizedMessageId = trim((string) $messageId);
    if ($normalizedMessageId !== '' && editMessage($token, $chatId, $normalizedMessageId, $text, $replyMarkup)) {
        return;
    }
    sendMessage($token, $chatId, $text, $replyMarkup);
}

function sendMessage(string $token, string $chatId, string $text, ?array $replyMarkup = null): void
{
    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
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

function editMessage(
    string $token,
    string $chatId,
    string $messageId,
    string $text,
    ?array $replyMarkup = null
): bool {
    $payload = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];
    if (is_array($replyMarkup)) {
        $payload['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
    } else {
        $payload['reply_markup'] = json_encode(['inline_keyboard' => []], JSON_UNESCAPED_UNICODE);
    }

    logEvent('telegram_edit_message', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => textSnippet($text, 180),
        'has_reply_markup' => is_array($replyMarkup)
    ]);
    $result = telegramRequest($token, 'editMessageText', $payload);
    if (telegramResponseOk($result)) {
        return true;
    }
    if (telegramResponseIsNotModified($result)) {
        return true;
    }

    logEvent('telegram_edit_failed', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'http_code' => (int) ($result['http_code'] ?? 0),
        'description' => (string) ($result['description'] ?? ''),
    ]);
    return false;
}

function telegramResponseOk(array $result): bool
{
    return ($result['ok'] ?? false) === true;
}

function telegramResponseIsNotModified(array $result): bool
{
    $description = strtolower((string) ($result['description'] ?? ''));
    return $description !== '' && strpos($description, 'message is not modified') !== false;
}

function telegramRequest(string $token, string $method, array $payload): array
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

            $decoded = is_string($response) ? json_decode($response, true) : null;
            $responseOk = is_array($decoded) && (($decoded['ok'] ?? false) === true);
            $description = is_array($decoded) ? (string) ($decoded['description'] ?? '') : '';
            logEvent('telegram_request', [
                'method' => $method,
                'http_code' => $httpCode,
                'curl_error' => $error,
                'response' => is_string($response) ? textSnippet($response, 400) : ''
            ]);
            return [
                'ok' => $responseOk,
                'description' => $description,
                'http_code' => $httpCode,
                'curl_error' => $error,
                'response' => $decoded,
                'raw' => is_string($response) ? $response : '',
            ];
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
    $decoded = is_string($response) ? json_decode($response, true) : null;
    $responseOk = is_array($decoded) && (($decoded['ok'] ?? false) === true);
    $description = is_array($decoded) ? (string) ($decoded['description'] ?? '') : '';
    $httpCode = 0;
    if (isset($http_response_header[0]) && is_string($http_response_header[0])) {
        if (preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
            $httpCode = (int) ($matches[1] ?? 0);
        }
    }
    logEvent('telegram_request_fopen', [
        'method' => $method,
        'http_code' => $httpCode,
        'response' => is_string($response) ? textSnippet($response, 400) : ''
    ]);
    return [
        'ok' => $responseOk,
        'description' => $description,
        'http_code' => $httpCode,
        'curl_error' => '',
        'response' => $decoded,
        'raw' => is_string($response) ? $response : '',
    ];
}

function addAssetFromState(array $state, string $storageId): array
{
    $storageId = trim($storageId);
    if ($storageId === '') {
        return ['ok' => false, 'message' => 'انبار انتخاب‌شده معتبر نیست.'];
    }

    $storages = loadStorages();
    $labels = loadLabels();
    $ancestors = loadAncestors($labels);
    $assets = loadAssets($ancestors, $labels);

    if (!storageExists($storages, $storageId)) {
        return ['ok' => false, 'message' => 'انبار انتخاب‌شده معتبر نیست.'];
    }

    $assetType = trim((string) ($state['asset_type'] ?? ''));
    $name = '';
    $ancestorId = '';
    $specialAsset = false;

    if ($assetType === 'common') {
        $ancestorId = trim((string) ($state['ancestor_id'] ?? ''));
        if ($ancestorId === '' || !ancestorExists($ancestors, $ancestorId)) {
            return ['ok' => false, 'message' => 'مال مرسوم انتخاب‌شده معتبر نیست.'];
        }
        $name = ancestorNameById($ancestors, $ancestorId);
        if ($name === '') {
            return ['ok' => false, 'message' => 'مال مرسوم انتخاب‌شده معتبر نیست.'];
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

    $code = normalizeAssetCode((string) ($state['asset_code'] ?? ''));
    if ($code === '') {
        return ['ok' => false, 'message' => 'کد مال معتبر نیست.'];
    }
    if (assetCodeExists($assets, $code)) {
        return ['ok' => false, 'message' => 'کد مال تکراری است.'];
    }

    $labelValues = [];
    if (!$specialAsset) {
        $ancestor = findById($ancestors, $ancestorId);
        $ancestorLabelParentIds = is_array($ancestor)
            ? sanitizeParentLabelIds($labels, (array) ($ancestor['label_ids'] ?? []))
            : [];
        $rawLabelValues = is_array($state['label_values'] ?? null) ? $state['label_values'] : [];
        $labelValues = normalizeAssetLabelValues($rawLabelValues, $ancestorLabelParentIds, $labels);
    }

    $now = date('c');
    $assets[] = [
        'id' => randomId(),
        'special_asset' => $specialAsset,
        'ancestor_id' => $specialAsset ? '' : $ancestorId,
        'name' => $name,
        'code' => $code,
        'storage_id' => $storageId,
        'label_values' => $labelValues,
        'created_at' => $now,
        'updated_at' => $now,
    ];

    if (!writeJsonList(ASSETS_FILE, $assets)) {
        return ['ok' => false, 'message' => 'ذخیره مال انجام نشد.'];
    }

    $storageName = storageNameById($storages, $storageId);
    $summary = "مال ثبت شد.\nنام: {$name}\nانبار: {$storageName}\nکد: {$code}";
    return [
        'ok' => true,
        'message' => $summary,
        'name' => $name,
        'code' => $code,
        'storage_name' => $storageName,
    ];
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
    ensureFileInitialized(LABELS_FILE, legacyPathCandidates('labels.json'));
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

function htmlEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalizeAssetCode(string $code): string
{
    $normalized = clean($code);
    if ($normalized === '') {
        return '';
    }

    $length = function_exists('mb_strlen')
        ? (int) mb_strlen($normalized)
        : (int) strlen($normalized);

    if ($length > 100) {
        return '';
    }

    return $normalized;
}

function parseBool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function uniqueNonEmptyStrings(array $values): array
{
    $out = [];
    $seen = [];
    foreach ($values as $value) {
        $candidate = trim((string) $value);
        if ($candidate === '' || isset($seen[$candidate])) {
            continue;
        }
        $seen[$candidate] = true;
        $out[] = $candidate;
    }
    return $out;
}

function labelExists(array $labels, string $labelId): bool
{
    return findById($labels, $labelId) !== null;
}

function labelHasChildren(array $labels, string $labelId): bool
{
    foreach ($labels as $label) {
        if (!is_array($label)) {
            continue;
        }
        if (trim((string) ($label['parent_id'] ?? '')) === $labelId) {
            return true;
        }
    }
    return false;
}

function labelIsDirectChild(array $labels, string $parentId, string $childId): bool
{
    $label = findById($labels, $childId);
    if (!is_array($label)) {
        return false;
    }
    return trim((string) ($label['parent_id'] ?? '')) === $parentId;
}

function sanitizeLabelIds(array $labels, array $labelIds): array
{
    $result = [];
    $seen = [];
    foreach ($labelIds as $labelId) {
        $id = trim((string) $labelId);
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
        $value = trim((string) ($labelValues[$labelId] ?? ''));
        if ($value !== '' && (!labelExists($labels, $value) || !labelIsDirectChild($labels, $labelId, $value))) {
            $value = '';
        }
        $normalized[$labelId] = $value;
    }
    return $normalized;
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

function loadLabels(): array
{
    $items = [];
    foreach (readJsonList(LABELS_FILE) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? ''));
        $name = clean((string) ($row['name'] ?? ''));
        $parentId = trim((string) ($row['parent_id'] ?? ''));
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
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    $validIds = [];
    foreach ($items as $label) {
        $validIds[(string) ($label['id'] ?? '')] = true;
    }
    foreach ($items as &$label) {
        $parentId = (string) ($label['parent_id'] ?? '');
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
    foreach (readJsonList(ANCESTOR_ASSETS_FILE) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? ''));
        $name = clean((string) ($row['name'] ?? ''));
        if ($id === '' || $name === '') {
            continue;
        }
        $labelIds = uniqueNonEmptyStrings((array) ($row['label_ids'] ?? []));
        if ($labels) {
            $labelIds = sanitizeParentLabelIds($labels, $labelIds);
        }
        $items[] = [
            'id' => $id,
            'name' => $name,
            'label_ids' => $labelIds,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
    return $items;
}

function loadAssets(array $ancestors = [], array $labels = []): array
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
        $ancestor = !$specialAsset ? findById($ancestors, $ancestorId) : null;
        $ancestorLabelIds = (!$specialAsset && is_array($ancestor))
            ? sanitizeParentLabelIds($labels, (array) ($ancestor['label_ids'] ?? []))
            : [];
        $rawLabelValues = is_array($row['label_values'] ?? null)
            ? $row['label_values']
            : [];
        $labelValues = $specialAsset
            ? []
            : normalizeAssetLabelValues($rawLabelValues, $ancestorLabelIds, $labels);
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
            'label_values' => $labelValues,
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
