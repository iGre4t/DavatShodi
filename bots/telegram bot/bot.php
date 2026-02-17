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
const API_CONFIG_FILE = __DIR__ . '/../../api/config.php';
const API_COMMON_FILE = __DIR__ . '/../../api/lib/common.php';
const API_USERS_FILE = __DIR__ . '/../../api/lib/users.php';
const AUTH_MAX_FAILED_ATTEMPTS = 3;
const AUTH_BLOCK_DURATION_SECONDS = 600;
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

    $telegramUserId = normalizeTelegramUserId((string) ($message['from']['id'] ?? ''));
    if ($telegramUserId === '') {
        logEvent('message_missing_from_id', ['chat_id' => $chatId]);
        return;
    }

    if (!authDatabaseAvailable()) {
        sendMessage(
            $token,
            $chatId,
            "⚠️ <b>ارتباط با دیتابیس برقرار نیست.</b>\n\nلطفا چند دقیقه دیگر دوباره تلاش کنید."
        );
        return;
    }

    $text = clean((string) ($message['text'] ?? ''));
    $state = refreshAuthSecurityState($chatId, getChatState($chatId));
    $authBlockRemaining = getAuthBlockRemainingSeconds($state);

    if ($text === '/start') {
        logEvent('command_start', ['chat_id' => $chatId, 'from_id' => $telegramUserId]);
        if ($authBlockRemaining > 0) {
            sendMessage($token, $chatId, buildAuthBlockedText($authBlockRemaining));
            return;
        }

        if (isTelegramUserAuthorized($telegramUserId)) {
            clearChatState($chatId);
            sendStartMenu($token, $chatId);
            return;
        }

        $authState = [
            'step' => 'awaiting_auth_identifier',
            'auth_fail_count' => (int) ($state['auth_fail_count'] ?? 0),
        ];
        if (!empty($state['auth_block_until'])) {
            $authState['auth_block_until'] = (string) $state['auth_block_until'];
        }
        setChatState($chatId, $authState);
        sendMessage(
            $token,
            $chatId,
            "🔐 <b>احراز هویت لازم است</b>\n\n"
                . "برای استفاده از امکانات ربات، ابتدا یکی از موارد زیر را ارسال کنید:\n"
                . "• <b>نام کاربری</b>\n"
                . "• <b>شماره موبایل</b>\n\n"
                . "بعد از شناسایی حساب، از شما <b>پین‌کد</b> خواسته می‌شود."
        );
        return;
    }

    if ($authBlockRemaining > 0) {
        sendMessage($token, $chatId, buildAuthBlockedText($authBlockRemaining));
        return;
    }

    if (!isTelegramUserAuthorized($telegramUserId)) {
        handleUnauthorizedMessage($token, $chatId, $telegramUserId, $text, $state);
        return;
    }

    if (isAuthStep((string) ($state['step'] ?? ''))) {
        clearChatState($chatId);
        $state = [];
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
    $telegramUserId = normalizeTelegramUserId((string) ($callbackQuery['from']['id'] ?? ''));
    $data = trim((string) ($callbackQuery['data'] ?? ''));

    if ($callbackId !== '') {
        answerCallbackQuery($token, $callbackId);
    }
    if ($chatId === '') {
        logEvent('callback_missing_chat_id', ['data' => $data]);
        return;
    }

    if ($telegramUserId === '') {
        logEvent('callback_missing_from_id', ['chat_id' => $chatId, 'data' => $data]);
        sendOrEditMessage(
            $token,
            $chatId,
            $messageId,
            "⚠️ <b>شناسه کاربر تلگرام معتبر نیست.</b>\n\nلطفا دوباره <code>/start</code> را ارسال کنید.",
            null
        );
        return;
    }

    if (!authDatabaseAvailable()) {
        sendOrEditMessage(
            $token,
            $chatId,
            $messageId,
            "⚠️ <b>ارتباط با دیتابیس برقرار نیست.</b>\n\nلطفا چند دقیقه دیگر دوباره تلاش کنید.",
            null
        );
        return;
    }

    $state = refreshAuthSecurityState($chatId, getChatState($chatId));
    $authBlockRemaining = getAuthBlockRemainingSeconds($state);
    if ($authBlockRemaining > 0) {
        sendOrEditMessage($token, $chatId, $messageId, buildAuthBlockedText($authBlockRemaining), null);
        return;
    }

    if (!isTelegramUserAuthorized($telegramUserId)) {
        $step = (string) ($state['step'] ?? '');
        if (!isAuthStep($step)) {
            setChatState($chatId, [
                'step' => 'awaiting_auth_identifier',
                'auth_fail_count' => (int) ($state['auth_fail_count'] ?? 0),
            ]);
        }
        $authText = $step === 'awaiting_auth_pin'
            ? "🔐 <b>ابتدا احراز هویت را کامل کنید.</b>\n\nلطفا <b>پین‌کد ۴ رقمی</b> خود را به‌صورت پیام متنی ارسال کنید."
            : "🔐 <b>برای استفاده از دکمه‌های ربات باید وارد شوید.</b>\n\n"
                . "لطفا همین حالا <b>نام کاربری</b> یا <b>شماره موبایل</b> خود را به‌صورت پیام متنی ارسال کنید.";
        sendOrEditMessage($token, $chatId, $messageId, $authText, null);
        return;
    }

    if (isAuthStep((string) ($state['step'] ?? ''))) {
        clearChatState($chatId);
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

function isAuthStep(string $step): bool
{
    return in_array($step, ['awaiting_auth_identifier', 'awaiting_auth_pin', 'auth_blocked'], true);
}

function normalizeTelegramUserId(string $value): string
{
    $normalized = normalizeDigits($value);
    $digits = preg_replace('/\D+/', '', $normalized) ?? '';
    return trim($digits);
}

function normalizeDigits(string $value): string
{
    return strtr($value, [
        '۰' => '0',
        '۱' => '1',
        '۲' => '2',
        '۳' => '3',
        '۴' => '4',
        '۵' => '5',
        '۶' => '6',
        '۷' => '7',
        '۸' => '8',
        '۹' => '9',
        '٠' => '0',
        '١' => '1',
        '٢' => '2',
        '٣' => '3',
        '٤' => '4',
        '٥' => '5',
        '٦' => '6',
        '٧' => '7',
        '٨' => '8',
        '٩' => '9',
    ]);
}

function normalizePhoneForLookup(string $value): string
{
    $digits = preg_replace('/\D+/', '', normalizeDigits($value)) ?? '';
    if ($digits === '') {
        return '';
    }
    if (strpos($digits, '0098') === 0) {
        $digits = substr($digits, 4);
    } elseif (strpos($digits, '98') === 0) {
        $digits = substr($digits, 2);
    }
    if (strlen($digits) === 10 && strpos($digits, '9') === 0) {
        $digits = '0' . $digits;
    }
    if (strlen($digits) !== 11 || strpos($digits, '09') !== 0) {
        return '';
    }
    return $digits;
}

function normalizeUsernameForLookup(string $value): string
{
    $cleaned = clean($value);
    $withoutAt = ltrim($cleaned, '@');
    return trim($withoutAt);
}

function normalizePinInput(string $value): string
{
    $digits = preg_replace('/\D+/', '', normalizeDigits($value)) ?? '';
    return strlen($digits) === 4 ? $digits : '';
}

function authBlockUntilTimestamp(array $state): int
{
    $raw = trim((string) ($state['auth_block_until'] ?? ''));
    if ($raw === '') {
        return 0;
    }
    $timestamp = strtotime($raw);
    return is_int($timestamp) && $timestamp > 0 ? $timestamp : 0;
}

function getAuthBlockRemainingSeconds(array $state): int
{
    $blockUntil = authBlockUntilTimestamp($state);
    if ($blockUntil <= 0) {
        return 0;
    }
    $remaining = $blockUntil - time();
    return $remaining > 0 ? $remaining : 0;
}

function refreshAuthSecurityState(string $chatId, array $state): array
{
    $remaining = getAuthBlockRemainingSeconds($state);
    if ($remaining > 0) {
        return $state;
    }

    if (authBlockUntilTimestamp($state) <= 0) {
        return $state;
    }

    unset($state['auth_block_until'], $state['auth_fail_count'], $state['auth_user_code'], $state['auth_user_label']);
    if ((string) ($state['step'] ?? '') === 'auth_blocked') {
        $state['step'] = 'awaiting_auth_identifier';
    }
    setChatState($chatId, $state);
    return $state;
}

function formatDurationText(int $seconds): string
{
    $seconds = max(0, $seconds);
    $minutes = (int) ceil($seconds / 60);
    if ($minutes <= 1) {
        return 'کمتر از ۱ دقیقه';
    }
    return $minutes . ' دقیقه';
}

function buildAuthBlockedText(int $remainingSeconds): string
{
    $duration = htmlEscape(formatDurationText($remainingSeconds));
    return "⛔ <b>دسترسی شما موقتاً مسدود شده است.</b>\n\n"
        . "به‌دلیل ۳ تلاش ناموفق، برای جلوگیری از سوءاستفاده تا <b>{$duration}</b> دیگر امکان ورود ندارید.\n"
        . "بعد از اتمام زمان، دوباره تلاش کنید.";
}

function registerAuthFailure(string $chatId, array $state, string $stepForRetry): array
{
    $failedCount = (int) ($state['auth_fail_count'] ?? 0) + 1;
    logEvent('auth_failed_attempt', [
        'chat_id' => $chatId,
        'step' => $stepForRetry,
        'failed_count' => $failedCount,
    ]);
    if ($failedCount >= AUTH_MAX_FAILED_ATTEMPTS) {
        $state['auth_fail_count'] = 0;
        $state['auth_block_until'] = date('c', time() + AUTH_BLOCK_DURATION_SECONDS);
        $state['step'] = 'auth_blocked';
        unset($state['auth_user_code'], $state['auth_user_label']);
        setChatState($chatId, $state);
        logEvent('auth_blocked', ['chat_id' => $chatId, 'duration_seconds' => AUTH_BLOCK_DURATION_SECONDS]);
        return [
            'blocked' => true,
            'remaining_attempts' => 0,
            'remaining_seconds' => AUTH_BLOCK_DURATION_SECONDS,
        ];
    }

    $state['auth_fail_count'] = $failedCount;
    $state['step'] = $stepForRetry;
    if ($stepForRetry !== 'awaiting_auth_pin') {
        unset($state['auth_user_code'], $state['auth_user_label']);
    }
    setChatState($chatId, $state);
    return [
        'blocked' => false,
        'remaining_attempts' => AUTH_MAX_FAILED_ATTEMPTS - $failedCount,
        'remaining_seconds' => 0,
    ];
}

function handleUnauthorizedMessage(
    string $token,
    string $chatId,
    string $telegramUserId,
    string $text,
    array $state
): void {
    if ($text === '/cancel') {
        sendMessage(
            $token,
            $chatId,
            "ℹ️ <b>هنوز احراز هویت نشده‌اید.</b>\n\n"
                . "برای ورود به ربات، لطفا <b>نام کاربری</b> یا <b>شماره موبایل</b> خود را ارسال کنید."
        );
        return;
    }

    $step = (string) ($state['step'] ?? '');
    if (!isAuthStep($step)) {
        $state = [
            'step' => 'awaiting_auth_identifier',
            'auth_fail_count' => (int) ($state['auth_fail_count'] ?? 0),
            'auth_block_until' => (string) ($state['auth_block_until'] ?? ''),
        ];
        setChatState($chatId, $state);
        $step = 'awaiting_auth_identifier';
    }

    if ($step === 'awaiting_auth_identifier') {
        if ($text === '') {
            sendMessage(
                $token,
                $chatId,
                "🔎 <b>شناسه ورود دریافت نشد.</b>\n\n"
                    . "لطفا <b>نام کاربری</b> یا <b>شماره موبایل</b> خود را ارسال کنید."
            );
            return;
        }

        $user = findAuthUserByIdentifier($text);
        if (!is_array($user)) {
            $result = registerAuthFailure($chatId, $state, 'awaiting_auth_identifier');
            if ($result['blocked']) {
                sendMessage($token, $chatId, buildAuthBlockedText((int) $result['remaining_seconds']));
                return;
            }
            $remainingAttempts = (int) $result['remaining_attempts'];
            sendMessage(
                $token,
                $chatId,
                "❌ <b>کاربری با این اطلاعات پیدا نشد.</b>\n\n"
                    . "لطفا دوباره <b>نام کاربری</b> یا <b>شماره موبایل</b> صحیح را ارسال کنید.\n"
                    . "تعداد تلاش باقی‌مانده: <b>{$remainingAttempts}</b>"
            );
            return;
        }

        $storedPin = normalizePinInput((string) ($user['pin_code'] ?? ''));
        if ($storedPin === '') {
            $state['step'] = 'awaiting_auth_identifier';
            unset($state['auth_user_code'], $state['auth_user_label']);
            setChatState($chatId, $state);
            sendMessage(
                $token,
                $chatId,
                "⚠️ <b>برای این حساب پین‌کد تعریف نشده است.</b>\n\n"
                    . "لطفا با مدیر سیستم تماس بگیرید تا پین‌کد شما در پنل ثبت شود."
            );
            return;
        }

        $displayName = clean((string) ($user['fullname'] ?? ''));
        if ($displayName === '' || $displayName === '0') {
            $displayName = clean((string) ($user['username'] ?? ''));
        }
        if ($displayName === '') {
            $displayName = clean((string) ($user['phone'] ?? ''));
        }

        $state['step'] = 'awaiting_auth_pin';
        $state['auth_user_code'] = (string) ($user['code'] ?? '');
        $state['auth_user_label'] = $displayName;
        setChatState($chatId, $state);
        logEvent('auth_identifier_matched', [
            'chat_id' => $chatId,
            'user_code' => (string) ($user['code'] ?? ''),
        ]);

        $safeLabel = htmlEscape($displayName);
        sendMessage(
            $token,
            $chatId,
            "✅ <b>حساب شما شناسایی شد.</b>\n\n"
                . "کاربر: <b>{$safeLabel}</b>\n"
                . "لطفا حالا <b>پین‌کد ۴ رقمی</b> را ارسال کنید."
        );
        return;
    }

    if ($step === 'awaiting_auth_pin') {
        $userCode = trim((string) ($state['auth_user_code'] ?? ''));
        if ($userCode === '') {
            $state['step'] = 'awaiting_auth_identifier';
            unset($state['auth_user_label']);
            setChatState($chatId, $state);
            sendMessage(
                $token,
                $chatId,
                "ℹ️ <b>جلسه ورود شما منقضی شد.</b>\n\n"
                    . "لطفا دوباره <b>نام کاربری</b> یا <b>شماره موبایل</b> خود را ارسال کنید."
            );
            return;
        }

        $pinInput = normalizePinInput($text);
        if ($pinInput === '') {
            $result = registerAuthFailure($chatId, $state, 'awaiting_auth_pin');
            if ($result['blocked']) {
                sendMessage($token, $chatId, buildAuthBlockedText((int) $result['remaining_seconds']));
                return;
            }
            $remainingAttempts = (int) $result['remaining_attempts'];
            sendMessage(
                $token,
                $chatId,
                "❌ <b>پین‌کد معتبر نیست.</b>\n\n"
                    . "پین‌کد باید دقیقا <b>۴ رقم</b> باشد.\n"
                    . "تعداد تلاش باقی‌مانده: <b>{$remainingAttempts}</b>"
            );
            return;
        }

        $user = findAuthUserByCode($userCode);
        if (!is_array($user)) {
            $result = registerAuthFailure($chatId, $state, 'awaiting_auth_identifier');
            if ($result['blocked']) {
                sendMessage($token, $chatId, buildAuthBlockedText((int) $result['remaining_seconds']));
                return;
            }
            sendMessage(
                $token,
                $chatId,
                "⚠️ <b>حساب کاربری پیدا نشد.</b>\n\n"
                    . "لطفا دوباره <b>نام کاربری</b> یا <b>شماره موبایل</b> خود را ارسال کنید."
            );
            return;
        }

        $storedPin = normalizePinInput((string) ($user['pin_code'] ?? ''));
        if ($storedPin === '' || !hash_equals($storedPin, $pinInput)) {
            $result = registerAuthFailure($chatId, $state, 'awaiting_auth_pin');
            if ($result['blocked']) {
                sendMessage($token, $chatId, buildAuthBlockedText((int) $result['remaining_seconds']));
                return;
            }
            $remainingAttempts = (int) $result['remaining_attempts'];
            sendMessage(
                $token,
                $chatId,
                "❌ <b>پین‌کد اشتباه است.</b>\n\n"
                    . "لطفا دوباره پین‌کد صحیح را ارسال کنید.\n"
                    . "تعداد تلاش باقی‌مانده: <b>{$remainingAttempts}</b>"
            );
            return;
        }

        $linkResult = linkTelegramUserToProfile($userCode, $telegramUserId);
        if (!$linkResult['ok']) {
            sendMessage(
                $token,
                $chatId,
                "⚠️ <b>ورود انجام نشد.</b>\n\n"
                    . htmlEscape((string) $linkResult['message'])
            );
            return;
        }

        clearChatState($chatId);
        logEvent('auth_verified', ['chat_id' => $chatId, 'user_code' => $userCode, 'telegram_id' => $telegramUserId]);
        $displayName = clean((string) ($user['fullname'] ?? ''));
        if ($displayName === '' || $displayName === '0') {
            $displayName = clean((string) ($user['username'] ?? ''));
        }
        $safeName = htmlEscape($displayName);
        sendMessage(
            $token,
            $chatId,
            "🎉 <b>احراز هویت با موفقیت انجام شد.</b>\n\n"
                . "کاربر تایید شده: <b>{$safeName}</b>\n"
                . "اکنون به امکانات ربات دسترسی دارید."
        );
        sendStartMenu($token, $chatId);
        return;
    }

    sendMessage(
        $token,
        $chatId,
        "🔐 <b>برای استفاده از ربات باید وارد شوید.</b>\n\n"
            . "لطفا <b>نام کاربری</b> یا <b>شماره موبایل</b> خود را ارسال کنید."
    );
}

function authDatabaseAvailable(): bool
{
    return getUsersPdoForBot() instanceof PDO;
}

function getUsersPdoForBot(): ?PDO
{
    static $resolved = false;
    static $cachedPdo = null;

    if ($resolved) {
        return $cachedPdo instanceof PDO ? $cachedPdo : null;
    }
    $resolved = true;

    if (!is_file(API_CONFIG_FILE) || !is_file(API_COMMON_FILE) || !is_file(API_USERS_FILE)) {
        logEvent('auth_db_files_missing', [
            'config_exists' => is_file(API_CONFIG_FILE),
            'common_exists' => is_file(API_COMMON_FILE),
            'users_exists' => is_file(API_USERS_FILE),
        ]);
        return null;
    }

    require_once API_COMMON_FILE;
    require_once API_USERS_FILE;

    if (!function_exists('loadConfig') || !function_exists('connectDatabase')) {
        logEvent('auth_db_functions_missing');
        return null;
    }

    try {
        $config = loadConfig(API_CONFIG_FILE);
        $pdo = connectDatabase($config);
        if (!$pdo instanceof PDO) {
            logEvent('auth_db_connect_failed');
            return null;
        }
        if (function_exists('ensureUsersExtendedColumns')) {
            ensureUsersExtendedColumns($pdo);
        }
        $cachedPdo = $pdo;
        return $cachedPdo;
    } catch (Throwable $error) {
        logEvent('auth_db_exception', ['message' => $error->getMessage()]);
        return null;
    }
}

function buildAuthUserSelectColumns(PDO $pdo): string
{
    $columns = ['`code`', '`username`', '`fullname`', '`phone`'];
    if (function_exists('usersTableHasColumn') && usersTableHasColumn($pdo, 'telegram_id')) {
        $columns[] = '`telegram_id`';
    }
    if (function_exists('usersTableHasColumn') && usersTableHasColumn($pdo, 'pin_code')) {
        $columns[] = '`pin_code`';
    }
    return implode(', ', $columns);
}

function findAuthUserByIdentifier(string $identifier): ?array
{
    $pdo = getUsersPdoForBot();
    if (!$pdo instanceof PDO) {
        return null;
    }

    $phone = normalizePhoneForLookup($identifier);
    if ($phone !== '') {
        try {
            $sql = 'SELECT ' . buildAuthUserSelectColumns($pdo) . ' FROM `users` WHERE `phone` = :phone LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':phone' => $phone]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return $row;
            }
        } catch (PDOException $error) {
            logEvent('auth_lookup_phone_failed', ['message' => $error->getMessage()]);
            return null;
        }
    }

    $username = normalizeUsernameForLookup($identifier);
    if ($username === '') {
        return null;
    }
    try {
        $sql = 'SELECT ' . buildAuthUserSelectColumns($pdo) . ' FROM `users` WHERE LOWER(`username`) = LOWER(:username) LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':username' => $username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (PDOException $error) {
        logEvent('auth_lookup_username_failed', ['message' => $error->getMessage()]);
        return null;
    }
}

function findAuthUserByCode(string $code): ?array
{
    $normalizedCode = clean($code);
    if ($normalizedCode === '') {
        return null;
    }

    $pdo = getUsersPdoForBot();
    if (!$pdo instanceof PDO) {
        return null;
    }

    try {
        $sql = 'SELECT ' . buildAuthUserSelectColumns($pdo) . ' FROM `users` WHERE `code` = :code LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':code' => $normalizedCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (PDOException $error) {
        logEvent('auth_lookup_code_failed', ['message' => $error->getMessage()]);
        return null;
    }
}

function findAuthUserByTelegramId(string $telegramUserId): ?array
{
    $normalizedTelegramId = normalizeTelegramUserId($telegramUserId);
    if ($normalizedTelegramId === '') {
        return null;
    }

    $pdo = getUsersPdoForBot();
    if (!$pdo instanceof PDO) {
        return null;
    }
    if (function_exists('usersTableHasColumn') && !usersTableHasColumn($pdo, 'telegram_id')) {
        return null;
    }

    try {
        $sql = 'SELECT ' . buildAuthUserSelectColumns($pdo) . ' FROM `users` WHERE `telegram_id` = :telegram_id LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':telegram_id' => $normalizedTelegramId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (PDOException $error) {
        logEvent('auth_lookup_telegram_failed', ['message' => $error->getMessage()]);
        return null;
    }
}

function isTelegramUserAuthorized(string $telegramUserId): bool
{
    return is_array(findAuthUserByTelegramId($telegramUserId));
}

function linkTelegramUserToProfile(string $userCode, string $telegramUserId): array
{
    $normalizedCode = clean($userCode);
    $normalizedTelegramId = normalizeTelegramUserId($telegramUserId);
    if ($normalizedCode === '' || $normalizedTelegramId === '') {
        return ['ok' => false, 'message' => 'اطلاعات ورود معتبر نیست.'];
    }

    $pdo = getUsersPdoForBot();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'message' => 'اتصال به دیتابیس برقرار نشد.'];
    }
    if (function_exists('usersTableHasColumn') && !usersTableHasColumn($pdo, 'telegram_id')) {
        return ['ok' => false, 'message' => 'فیلد telegram_id در دیتابیس موجود نیست.'];
    }

    $existing = findAuthUserByTelegramId($normalizedTelegramId);
    if (is_array($existing) && trim((string) ($existing['code'] ?? '')) !== $normalizedCode) {
        return ['ok' => false, 'message' => 'این حساب تلگرام قبلاً به کاربر دیگری متصل شده است.'];
    }

    try {
        $stmt = $pdo->prepare('UPDATE `users` SET `telegram_id` = :telegram_id WHERE `code` = :code');
        $ok = $stmt->execute([
            ':telegram_id' => $normalizedTelegramId,
            ':code' => $normalizedCode,
        ]);
        if (!$ok) {
            return ['ok' => false, 'message' => 'ذخیره شناسه تلگرام انجام نشد.'];
        }
        return ['ok' => true, 'message' => 'Telegram ID linked'];
    } catch (PDOException $error) {
        logEvent('auth_link_telegram_failed', ['message' => $error->getMessage(), 'code' => $normalizedCode]);
        return ['ok' => false, 'message' => 'خطا در ذخیره شناسه تلگرام.'];
    }
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
