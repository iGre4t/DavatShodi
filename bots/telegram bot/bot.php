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
const ASSET_LOGS_HELPER_FILE = __DIR__ . '/../../mini apps/Asset Manager/asset_logs.php';
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
define('OCR_ENABLED', !array_key_exists('ocr_enabled', $config) || parseBool($config['ocr_enabled']));
define('OCR_TESSERACT_CMD', trim((string) ($config['tesseract_cmd'] ?? 'tesseract')));
define('OCR_SPACE_API_KEY', trim((string) ($config['ocr_space_api_key'] ?? '')));
define('OCR_SPACE_ENDPOINT', trim((string) ($config['ocr_space_endpoint'] ?? 'https://api.ocr.space/parse/image')));
define('OCR_SPACE_LANGUAGE', trim((string) ($config['ocr_space_language'] ?? 'eng')));
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
        'text' => (string)($update['message']['text'] ?? $update['message']['caption'] ?? ''),
        'has_photo' => isset($update['message']['photo']) && is_array($update['message']['photo']) ? '1' : '0'
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

    $text = clean((string) ($message['text'] ?? $message['caption'] ?? ''));
    $hasPhoto = isset($message['photo']) && is_array($message['photo']);
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

    if ($step === 'asset_lookup_delete_pin') {
        handleDeleteAssetPinStep($token, $chatId, $telegramUserId, $text, $state);
        return;
    }

    if ($step === 'asset_lookup_delete_note') {
        handleDeleteAssetNoteStep($token, $chatId, $telegramUserId, $text, $state);
        return;
    }

    if (in_array($step, ['asset_lookup_transfer_select', 'asset_lookup_labels_parent', 'asset_lookup_labels_child'], true)) {
        sendMessage(
            $token,
            $chatId,
            "ℹ️ <b>در حال انجام عملیات روی مال هستید.</b>\n\n"
                . "برای ادامه، از دکمه‌های همین پیام استفاده کنید یا <code>/cancel</code> را بزنید."
        );
        return;
    }

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

    if (!isAssetJourneyStep($step)) {
        if (handlePassiveAssetLookupMessage($token, $chatId, $telegramUserId, $message, $text, $hasPhoto)) {
            return;
        }
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

    if (strpos($data, 'a_t:') === 0) {
        $assetId = trim(substr($data, 4));
        startAssetTransferJourney($token, $chatId, $messageId, $assetId);
        return;
    }

    if (strpos($data, 'a_tt:') === 0) {
        $storageId = trim(substr($data, 5));
        completeAssetTransferJourney($token, $chatId, $messageId, $telegramUserId, $storageId);
        return;
    }

    if (strpos($data, 'a_l:') === 0) {
        $assetId = trim(substr($data, 4));
        startAssetLabelJourney($token, $chatId, $messageId, $assetId);
        return;
    }

    if (strpos($data, 'al_p:') === 0) {
        $parentId = trim(substr($data, 5));
        handleAssetLabelParentCallback($token, $chatId, $messageId, $parentId);
        return;
    }

    if (strpos($data, 'al_c:') === 0) {
        $childId = trim(substr($data, 5));
        handleAssetLabelChildCallback($token, $chatId, $messageId, $childId);
        return;
    }

    if ($data === 'al_b') {
        handleAssetLabelBackCallback($token, $chatId, $messageId);
        return;
    }

    if ($data === 'al_d') {
        finalizeAssetLabelJourney($token, $chatId, $messageId, $telegramUserId);
        return;
    }

    if (strpos($data, 'a_d:') === 0) {
        $assetId = trim(substr($data, 4));
        startAssetDeleteJourney($token, $chatId, $messageId, $assetId);
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

function isAssetJourneyStep(string $step): bool
{
    return in_array($step, [
        'choosing_type',
        'choosing_ancestor',
        'awaiting_special_name',
        'awaiting_asset_code',
        'awaiting_storage',
        'choosing_label_parent',
        'choosing_label_child',
        'asset_lookup_transfer_select',
        'asset_lookup_labels_parent',
        'asset_lookup_labels_child',
        'asset_lookup_delete_pin',
        'asset_lookup_delete_note',
    ], true);
}

function canonicalDigits(string $digits): string
{
    $normalized = ltrim($digits, '0');
    if ($normalized === '') {
        return $digits === '' ? '' : '0';
    }
    return $normalized;
}

function normalizeAssetCodeDigits(string $code): string
{
    $normalized = normalizeDigits($code);
    $digits = preg_replace('/\D+/', '', $normalized) ?? '';
    return canonicalDigits($digits);
}

function extractDigitCandidatesFromText(string $text, int $minLength = 1): array
{
    $normalized = normalizeDigits($text);
    if ($normalized === '') {
        return [];
    }

    preg_match_all('/\d+/', $normalized, $matches);
    $parts = is_array($matches[0] ?? null) ? $matches[0] : [];
    $seen = [];
    $candidates = [];
    foreach ($parts as $part) {
        $digits = trim((string) $part);
        if ($digits === '' || strlen($digits) < $minLength) {
            continue;
        }
        $canonical = canonicalDigits($digits);
        if ($canonical === '' || isset($seen[$canonical])) {
            continue;
        }
        $seen[$canonical] = true;
        $candidates[] = $canonical;
    }

    // OCR often splits codes (e.g. "1 2 3 4"), so also try all detected digits joined.
    $joinedDigits = preg_replace('/\D+/', '', $normalized) ?? '';
    if ($joinedDigits !== '' && strlen($joinedDigits) >= $minLength) {
        $joinedCanonical = canonicalDigits($joinedDigits);
        if ($joinedCanonical !== '' && !isset($seen[$joinedCanonical])) {
            $seen[$joinedCanonical] = true;
            $candidates[] = $joinedCanonical;
        }
    }

    usort($candidates, static function (string $a, string $b): int {
        $len = strlen($b) <=> strlen($a);
        if ($len !== 0) {
            return $len;
        }
        return strcmp($a, $b);
    });
    return $candidates;
}

function findAssetsByCodeDigits(array $assets, string $codeDigits): array
{
    $needle = canonicalDigits($codeDigits);
    if ($needle === '') {
        return [];
    }

    $matches = [];
    foreach ($assets as $asset) {
        if (!is_array($asset)) {
            continue;
        }
        $assetDigits = normalizeAssetCodeDigits((string) ($asset['code'] ?? ''));
        if ($assetDigits === '' || $assetDigits !== $needle) {
            continue;
        }
        $matches[] = $asset;
    }
    return $matches;
}

function resolveAssetByDigitCandidates(array $assets, array $candidates): array
{
    $firstMultiple = null;

    foreach ($candidates as $candidate) {
        $digits = canonicalDigits((string) $candidate);
        if ($digits === '') {
            continue;
        }
        $matches = findAssetsByCodeDigits($assets, $digits);
        if (count($matches) === 1) {
            return [
                'status' => 'single',
                'asset' => $matches[0],
                'candidate' => $digits,
            ];
        }

        if (count($matches) > 1 && $firstMultiple === null) {
            $firstMultiple = [
                'status' => 'multiple',
                'assets' => $matches,
                'candidate' => $digits,
            ];
        }
    }

    if (is_array($firstMultiple)) {
        return $firstMultiple;
    }

    return ['status' => 'none', 'assets' => [], 'candidate' => ''];
}

function findAssetIndexById(array $assets, string $assetId): int
{
    foreach ($assets as $index => $asset) {
        if (!is_array($asset)) {
            continue;
        }
        if (trim((string) ($asset['id'] ?? '')) === $assetId) {
            return (int) $index;
        }
    }
    return -1;
}

function handlePassiveAssetLookupMessage(
    string $token,
    string $chatId,
    string $telegramUserId,
    array $message,
    string $text,
    bool $hasPhoto
): bool {
    $textCandidates = extractDigitCandidatesFromText($text);
    $photoCandidates = [];

    if ($hasPhoto) {
        $photoCandidates = extractPhotoDigitCandidates($token, $message);
    }

    $candidateMap = [];
    foreach (array_merge($textCandidates, $photoCandidates) as $candidate) {
        $normalized = canonicalDigits((string) $candidate);
        if ($normalized === '') {
            continue;
        }
        $candidateMap[$normalized] = true;
    }
    $candidates = array_keys($candidateMap);
    usort($candidates, static function (string $a, string $b): int {
        $len = strlen($b) <=> strlen($a);
        if ($len !== 0) {
            return $len;
        }
        return strcmp($a, $b);
    });

    if (!$candidates) {
        if ($hasPhoto) {
            sendMessage(
                $token,
                $chatId,
                "📷 <b>کد عددی در تصویر تشخیص داده نشد.</b>\n\n"
                    . "لطفا عکس واضح‌تر ارسال کنید یا کد مال را به‌صورت متنی بفرستید."
            );
            return true;
        }
        return false;
    }

    $labels = loadLabels();
    $ancestors = loadAncestors($labels);
    $storages = loadStorages();
    $assets = loadAssets($ancestors, $labels);

    $resolved = resolveAssetByDigitCandidates($assets, $candidates);
    if (($resolved['status'] ?? '') === 'single' && is_array($resolved['asset'] ?? null)) {
        sendAssetLookupCard(
            $token,
            $chatId,
            $resolved['asset'],
            $storages,
            $ancestors,
            $labels,
            null,
            "✅ <b>مال با این کد پیدا شد.</b>"
        );
        return true;
    }

    if (($resolved['status'] ?? '') === 'multiple') {
        $matchedAssets = is_array($resolved['assets'] ?? null) ? $resolved['assets'] : [];
        $codes = [];
        foreach ($matchedAssets as $asset) {
            $code = clean((string) ($asset['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $codes[] = '• <b>' . htmlEscape($code) . '</b>';
            if (count($codes) >= 6) {
                break;
            }
        }
        $codesText = $codes ? implode("\n", $codes) : '• کدهای متعدد یافت شد';
        sendMessage(
            $token,
            $chatId,
            "⚠️ <b>چند مال با این ورودی پیدا شد.</b>\n\n"
                . "برای انتخاب دقیق، لطفا کد کامل را واضح‌تر ارسال کنید.\n\n"
                . $codesText
        );
        return true;
    }

    $safeCandidate = htmlEscape((string) ($candidates[0] ?? ''));
    sendMessage(
        $token,
        $chatId,
        "🔎 <b>مالی با کد ارسال‌شده پیدا نشد.</b>\n\n"
            . "کد تشخیص داده‌شده: <b>{$safeCandidate}</b>\n"
            . "لطفا دوباره بررسی و ارسال کنید."
    );
    return true;
}

function extractPhotoDigitCandidates(string $token, array $message): array
{
    if (!OCR_ENABLED) {
        return [];
    }

    $photos = is_array($message['photo'] ?? null) ? $message['photo'] : [];
    if (!$photos) {
        return [];
    }
    $bestPhoto = $photos[count($photos) - 1];
    $fileId = trim((string) ($bestPhoto['file_id'] ?? ''));
    if ($fileId === '') {
        return [];
    }

    $getFileResult = telegramRequest($token, 'getFile', ['file_id' => $fileId]);
    if (!telegramResponseOk($getFileResult)) {
        logEvent('photo_get_file_failed', [
            'file_id' => $fileId,
            'description' => (string) ($getFileResult['description'] ?? ''),
        ]);
        return [];
    }

    $filePath = trim((string) ($getFileResult['response']['result']['file_path'] ?? ''));
    if ($filePath === '') {
        return [];
    }

    $tempFilePath = downloadTelegramFileToTemp($token, $filePath);
    if ($tempFilePath === null) {
        return [];
    }

    try {
        $ocrText = extractTextFromImage($tempFilePath);
        if ($ocrText === '') {
            logEvent('photo_ocr_no_text', ['file_id' => $fileId]);
            return [];
        }
        $candidates = extractDigitCandidatesFromText($ocrText);
        logEvent('photo_ocr_candidates', [
            'file_id' => $fileId,
            'candidate_count' => count($candidates),
            'ocr_text' => textSnippet($ocrText, 180),
        ]);
        return $candidates;
    } finally {
        deleteFileIfExists($tempFilePath);
    }
}

function downloadTelegramFileToTemp(string $token, string $filePath): ?string
{
    $tmp = tempnam(sys_get_temp_dir(), 'tg_asset_');
    if (!is_string($tmp) || $tmp === '') {
        return null;
    }

    $ext = strtolower(trim((string) pathinfo($filePath, PATHINFO_EXTENSION)));
    $targetPath = $tmp;
    if ($ext !== '' && preg_match('/^[a-z0-9]{1,8}$/', $ext)) {
        $withExt = $tmp . '.' . $ext;
        if (@rename($tmp, $withExt)) {
            $targetPath = $withExt;
        }
    }

    $url = "https://api.telegram.org/file/bot{$token}/{$filePath}";
    if (!httpDownloadToFile($url, $targetPath)) {
        deleteFileIfExists($targetPath);
        return null;
    }

    $size = @filesize($targetPath);
    if (!is_int($size) || $size <= 0) {
        deleteFileIfExists($targetPath);
        return null;
    }
    return $targetPath;
}

function httpDownloadToFile(string $url, string $targetPath): bool
{
    if (function_exists('curl_init')) {
        $fp = @fopen($targetPath, 'wb');
        if ($fp !== false) {
            $ch = curl_init($url);
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_FILE => $fp,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 20,
                ]);
                $ok = curl_exec($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                fclose($fp);
                return $ok !== false && $httpCode >= 200 && $httpCode < 300;
            }
            fclose($fp);
        }
    }

    $binary = @file_get_contents($url);
    if (!is_string($binary) || $binary === '') {
        return false;
    }
    return @file_put_contents($targetPath, $binary, LOCK_EX) !== false;
}

function extractTextFromImage(string $imagePath): string
{
    $local = extractTextWithLocalTesseract($imagePath);
    if ($local !== '') {
        return $local;
    }
    return extractTextWithOcrSpace($imagePath);
}

function extractTextWithLocalTesseract(string $imagePath): string
{
    if (!function_exists('shell_exec')) {
        return '';
    }
    $cmd = trim((string) OCR_TESSERACT_CMD);
    if ($cmd === '') {
        return '';
    }

    $base = escapeshellcmd($cmd) . ' ' . escapeshellarg($imagePath) . ' stdout --psm 6 -l eng 2>/dev/null';
    $output = shell_exec($base);
    if (!is_string($output) || trim($output) === '') {
        $fallback = escapeshellcmd($cmd) . ' ' . escapeshellarg($imagePath) . ' stdout --psm 6 -l eng+fas 2>/dev/null';
        $output = shell_exec($fallback);
    }
    return is_string($output) ? trim($output) : '';
}

function extractTextWithOcrSpace(string $imagePath): string
{
    $apiKey = trim((string) OCR_SPACE_API_KEY);
    if ($apiKey === '' || !function_exists('curl_init') || !class_exists('CURLFile')) {
        return '';
    }

    $endpoint = trim((string) OCR_SPACE_ENDPOINT);
    if ($endpoint === '') {
        $endpoint = 'https://api.ocr.space/parse/image';
    }
    $language = trim((string) OCR_SPACE_LANGUAGE);
    if ($language === '') {
        $language = 'eng';
    }

    $ch = curl_init($endpoint);
    if ($ch === false) {
        return '';
    }

    $payload = [
        'apikey' => $apiKey,
        'language' => $language,
        'isOverlayRequired' => 'false',
        'OCREngine' => '2',
        'scale' => 'true',
        'file' => new CURLFile($imagePath),
    ];

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);

    $raw = curl_exec($ch);
    curl_close($ch);
    if (!is_string($raw) || trim($raw) === '') {
        return '';
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !empty($decoded['IsErroredOnProcessing'])) {
        return '';
    }

    $texts = [];
    $parsedResults = is_array($decoded['ParsedResults'] ?? null) ? $decoded['ParsedResults'] : [];
    foreach ($parsedResults as $parsed) {
        if (!is_array($parsed)) {
            continue;
        }
        $text = trim((string) ($parsed['ParsedText'] ?? ''));
        if ($text !== '') {
            $texts[] = $text;
        }
    }
    return trim(implode("\n", $texts));
}

function deleteFileIfExists(string $path): void
{
    if ($path === '') {
        return;
    }
    if (is_file($path)) {
        @unlink($path);
    }
}

function buildAssetActionMarkup(string $assetId): array
{
    return [
        'inline_keyboard' => [
            [
                ['text' => '🏬 انتقال به انبار دیگر', 'callback_data' => 'a_t:' . $assetId],
            ],
            [
                ['text' => '🏷️ تغییر برچسب ها', 'callback_data' => 'a_l:' . $assetId],
            ],
            [
                ['text' => '🗑️ حذف', 'callback_data' => 'a_d:' . $assetId],
            ],
        ],
    ];
}

function labelPathById(array $labels, string $labelId): string
{
    $currentId = trim($labelId);
    if ($currentId === '') {
        return '';
    }

    $parts = [];
    $seen = [];
    while ($currentId !== '' && !isset($seen[$currentId])) {
        $seen[$currentId] = true;
        $label = findById($labels, $currentId);
        if (!is_array($label)) {
            break;
        }
        $name = clean((string) ($label['name'] ?? ''));
        if ($name !== '') {
            array_unshift($parts, $name);
        }
        $currentId = trim((string) ($label['parent_id'] ?? ''));
    }
    return implode(' / ', $parts);
}

function assetDisplayName(array $asset, array $ancestors): string
{
    $special = parseBool($asset['special_asset'] ?? false);
    if ($special) {
        $name = clean((string) ($asset['name'] ?? ''));
        return $name !== '' ? $name : 'مال خاص';
    }

    $ancestorId = trim((string) ($asset['ancestor_id'] ?? ''));
    $ancestorName = ancestorNameById($ancestors, $ancestorId);
    if ($ancestorName !== '') {
        return $ancestorName;
    }
    $fallback = clean((string) ($asset['name'] ?? ''));
    return $fallback !== '' ? $fallback : 'مال مرسوم';
}

function formatAssetLabelSummaryHtml(array $asset, array $ancestors, array $labels): string
{
    if (parseBool($asset['special_asset'] ?? false)) {
        return 'ندارد';
    }

    $ancestor = findById($ancestors, trim((string) ($asset['ancestor_id'] ?? '')));
    if (!is_array($ancestor)) {
        return 'ندارد';
    }

    $parentIds = sanitizeParentLabelIds($labels, (array) ($ancestor['label_ids'] ?? []));
    if (!$parentIds) {
        return 'ندارد';
    }

    $labelValues = normalizeAssetLabelValues(
        is_array($asset['label_values'] ?? null) ? $asset['label_values'] : [],
        $parentIds,
        $labels
    );

    $lines = [];
    foreach ($parentIds as $parentId) {
        $childId = trim((string) ($labelValues[$parentId] ?? ''));
        if ($childId === '') {
            continue;
        }
        $parent = findById($labels, $parentId);
        $parentName = clean((string) ($parent['name'] ?? ''));
        $childPath = labelPathById($labels, $childId);
        if ($childPath === '') {
            continue;
        }
        $safeParent = htmlEscape($parentName !== '' ? $parentName : 'برچسب');
        $safeChild = htmlEscape($childPath);
        $lines[] = "• {$safeParent}: <b>{$safeChild}</b>";
    }

    return $lines ? implode("\n", $lines) : 'تعیین نشده';
}

function buildAssetLookupText(array $asset, array $storages, array $ancestors, array $labels): string
{
    $special = parseBool($asset['special_asset'] ?? false);
    $name = assetDisplayName($asset, $ancestors);
    $code = clean((string) ($asset['code'] ?? ''));
    $storageName = storageNameById($storages, trim((string) ($asset['storage_id'] ?? '')));
    $ancestorName = $special ? '' : ancestorNameById($ancestors, trim((string) ($asset['ancestor_id'] ?? '')));
    $labelSummary = formatAssetLabelSummaryHtml($asset, $ancestors, $labels);

    $safeName = htmlEscape($name);
    $safeCode = htmlEscape($code !== '' ? $code : 'ندارد');
    $safeStorage = htmlEscape($storageName !== '' ? $storageName : 'نامشخص');
    $safeType = $special ? 'خاص' : 'مرسوم';
    $safeAncestor = htmlEscape($ancestorName !== '' ? $ancestorName : '—');

    return "📦 <b>اطلاعات مال</b>\n\n"
        . "🧾 نام مال: <b>{$safeName}</b>\n"
        . "🔐 کد مال: <b>{$safeCode}</b>\n"
        . "📚 نوع مال: <b>{$safeType}</b>\n"
        . "🧩 مال مرسوم پایه: <b>{$safeAncestor}</b>\n"
        . "🏬 انبار فعلی: <b>{$safeStorage}</b>\n"
        . "🏷️ برچسب ها:\n{$labelSummary}\n\n"
        . "از دکمه‌های زیر عملیات موردنظر را انتخاب کنید.";
}

function sendAssetLookupCard(
    string $token,
    string $chatId,
    array $asset,
    array $storages,
    array $ancestors,
    array $labels,
    ?string $messageId = null,
    string $prefix = ''
): void {
    $assetId = trim((string) ($asset['id'] ?? ''));
    if ($assetId === '') {
        sendMessage($token, $chatId, "⚠️ <b>مال معتبر نیست.</b>");
        return;
    }

    $body = buildAssetLookupText($asset, $storages, $ancestors, $labels);
    $text = $prefix !== '' ? $prefix . "\n\n" . $body : $body;
    $markup = buildAssetActionMarkup($assetId);

    if ($messageId !== null && trim($messageId) !== '') {
        sendOrEditMessage($token, $chatId, $messageId, $text, $markup);
        return;
    }
    sendMessage($token, $chatId, $text, $markup);
}

function buildTransferStorageMarkup(array $storages): array
{
    $rows = [];
    foreach ($storages as $storage) {
        if (!is_array($storage)) {
            continue;
        }
        $storageId = trim((string) ($storage['id'] ?? ''));
        $storageName = clean((string) ($storage['name'] ?? ''));
        if ($storageId === '' || $storageName === '') {
            continue;
        }
        $rows[] = [
            ['text' => '🏬 ' . $storageName, 'callback_data' => 'a_tt:' . $storageId],
        ];
    }
    return ['inline_keyboard' => $rows];
}

function startAssetTransferJourney(string $token, string $chatId, string $messageId, string $assetId): void
{
    $labels = loadLabels();
    $ancestors = loadAncestors($labels);
    $storages = loadStorages();
    $assets = loadAssets($ancestors, $labels);
    $asset = findById($assets, $assetId);
    if (!is_array($asset)) {
        sendOrEditMessage(
            $token,
            $chatId,
            $messageId,
            "⚠️ <b>مال انتخاب‌شده پیدا نشد.</b>\n\nلطفا دوباره کد مال را ارسال کنید.",
            getStartMenuMarkup()
        );
        return;
    }
    if (!$storages) {
        sendOrEditMessage(
            $token,
            $chatId,
            $messageId,
            "⚠️ <b>انباری برای انتقال وجود ندارد.</b>\n\nابتدا انبارها را در پنل ثبت کنید.",
            null
        );
        return;
    }

    setChatState($chatId, [
        'step' => 'asset_lookup_transfer_select',
        'asset_action_asset_id' => $assetId,
    ]);

    $assetName = htmlEscape(assetDisplayName($asset, $ancestors));
    $assetCode = htmlEscape(clean((string) ($asset['code'] ?? '')));
    $currentStorage = htmlEscape(storageNameById($storages, trim((string) ($asset['storage_id'] ?? ''))) ?: 'نامشخص');
    $text = "🏬 <b>انتقال مال به انبار دیگر</b>\n\n"
        . "🧾 مال: <b>{$assetName}</b>\n"
        . "🔐 کد: <b>{$assetCode}</b>\n"
        . "📍 انبار فعلی: <b>{$currentStorage}</b>\n\n"
        . "لطفا انبار جدید را انتخاب کنید.";
    sendOrEditMessage($token, $chatId, $messageId, $text, buildTransferStorageMarkup($storages));
}

function completeAssetTransferJourney(
    string $token,
    string $chatId,
    string $messageId,
    string $telegramUserId,
    string $storageId
): void {
    $state = getChatState($chatId);
    if ((string) ($state['step'] ?? '') !== 'asset_lookup_transfer_select') {
        sendOrEditMessage(
            $token,
            $chatId,
            $messageId,
            "ℹ️ <b>ابتدا از اطلاعات مال، گزینه انتقال را انتخاب کنید.</b>",
            null
        );
        return;
    }

    $assetId = trim((string) ($state['asset_action_asset_id'] ?? ''));
    if ($assetId === '') {
        clearChatState($chatId);
        sendOrEditMessage($token, $chatId, $messageId, "⚠️ <b>وضعیت انتقال معتبر نیست.</b>", getStartMenuMarkup());
        return;
    }

    $labels = loadLabels();
    $ancestors = loadAncestors($labels);
    $storages = loadStorages();
    $assets = loadAssets($ancestors, $labels);

    $assetIndex = findAssetIndexById($assets, $assetId);
    if ($assetIndex < 0) {
        clearChatState($chatId);
        sendOrEditMessage($token, $chatId, $messageId, "⚠️ <b>مال انتخاب‌شده پیدا نشد.</b>", getStartMenuMarkup());
        return;
    }
    if (!storageExists($storages, $storageId)) {
        sendOrEditMessage($token, $chatId, $messageId, "⚠️ <b>انبار انتخاب‌شده معتبر نیست.</b>", buildTransferStorageMarkup($storages));
        return;
    }

    $beforeStorageId = trim((string) ($assets[$assetIndex]['storage_id'] ?? ''));
    if ($beforeStorageId === $storageId) {
        sendOrEditMessage($token, $chatId, $messageId, "ℹ️ <b>این مال از قبل در همین انبار است.</b>", buildTransferStorageMarkup($storages));
        return;
    }

    $assets[$assetIndex]['storage_id'] = $storageId;
    $assets[$assetIndex]['updated_at'] = date('c');
    if (!writeJsonList(ASSETS_FILE, $assets)) {
        sendOrEditMessage($token, $chatId, $messageId, "❌ <b>ذخیره انتقال انجام نشد.</b>", null);
        return;
    }

    $asset = $assets[$assetIndex];
    $assetName = assetDisplayName($asset, $ancestors);
    $assetCode = clean((string) ($asset['code'] ?? ''));
    $fromStorageName = storageNameById($storages, $beforeStorageId) ?: 'نامشخص';
    $toStorageName = storageNameById($storages, $storageId) ?: 'نامشخص';
    $actor = getAuthorizedActorName($telegramUserId);
    appendAssetManagerLog(
        'asset_transferred',
        sprintf(
            'مال %s با کد %s توسط کاربر (%s) از انبار %s به انبار %s منتقل شد',
            $assetName,
            $assetCode !== '' ? $assetCode : 'بدون کد',
            $actor,
            $fromStorageName,
            $toStorageName
        ),
        [
            'asset_id' => (string) ($asset['id'] ?? ''),
            'asset_code' => $assetCode,
            'actor' => $actor,
            'from_storage_id' => $beforeStorageId,
            'to_storage_id' => $storageId,
        ]
    );

    clearChatState($chatId);
    sendAssetLookupCard(
        $token,
        $chatId,
        $asset,
        $storages,
        $ancestors,
        $labels,
        $messageId,
        "✅ <b>انتقال مال با موفقیت انجام شد.</b>"
    );
}

function startAssetLabelJourney(string $token, string $chatId, string $messageId, string $assetId): void
{
    $labels = loadLabels();
    $ancestors = loadAncestors($labels);
    $storages = loadStorages();
    $assets = loadAssets($ancestors, $labels);
    $asset = findById($assets, $assetId);
    if (!is_array($asset)) {
        sendOrEditMessage($token, $chatId, $messageId, "⚠️ <b>مال انتخاب‌شده پیدا نشد.</b>", getStartMenuMarkup());
        return;
    }

    if (parseBool($asset['special_asset'] ?? false)) {
        sendAssetLookupCard(
            $token,
            $chatId,
            $asset,
            $storages,
            $ancestors,
            $labels,
            $messageId,
            "ℹ️ <b>برای مال خاص، تغییر برچسب فعال نیست.</b>"
        );
        return;
    }

    $ancestor = findById($ancestors, trim((string) ($asset['ancestor_id'] ?? '')));
    $parentIds = is_array($ancestor)
        ? sanitizeParentLabelIds($labels, (array) ($ancestor['label_ids'] ?? []))
        : [];
    if (!$parentIds) {
        sendAssetLookupCard(
            $token,
            $chatId,
            $asset,
            $storages,
            $ancestors,
            $labels,
            $messageId,
            "ℹ️ <b>برای این مال، برچسب قابل انتخابی تعریف نشده است.</b>"
        );
        return;
    }

    $currentValues = normalizeAssetLabelValues(
        is_array($asset['label_values'] ?? null) ? $asset['label_values'] : [],
        $parentIds,
        $labels
    );
    setChatState($chatId, [
        'step' => 'asset_lookup_labels_parent',
        'asset_action_asset_id' => $assetId,
        'asset_action_parent_ids' => $parentIds,
        'asset_action_label_values' => $currentValues,
    ]);
    renderAssetLabelParentMenu($token, $chatId, $messageId, getChatState($chatId), $asset, $labels, $ancestors);
}

function renderAssetLabelParentMenu(
    string $token,
    string $chatId,
    string $messageId,
    array $state,
    array $asset,
    array $labels,
    array $ancestors
): void {
    $parentIds = is_array($state['asset_action_parent_ids'] ?? null) ? $state['asset_action_parent_ids'] : [];
    $labelValues = is_array($state['asset_action_label_values'] ?? null) ? $state['asset_action_label_values'] : [];

    $rows = [];
    $summaryLines = [];
    foreach ($parentIds as $parentId) {
        $parent = findById($labels, (string) $parentId);
        if (!is_array($parent)) {
            continue;
        }
        $parentName = clean((string) ($parent['name'] ?? ''));
        if ($parentName === '') {
            continue;
        }
        $buttonText = '🏷️ ' . $parentName;
        $childId = trim((string) ($labelValues[$parentId] ?? ''));
        if ($childId !== '' && labelIsDirectChild($labels, (string) $parentId, $childId)) {
            $childPath = labelPathById($labels, $childId);
            if ($childPath !== '') {
                $buttonText = '✅ ' . $parentName . ': ' . $childPath;
                $summaryLines[] = '• ' . htmlEscape($parentName) . ': <b>' . htmlEscape($childPath) . '</b>';
            }
        }
        $rows[] = [['text' => $buttonText, 'callback_data' => 'al_p:' . (string) $parentId]];
    }
    $rows[] = [['text' => '✅ ثبت تغییرات برچسب ها', 'callback_data' => 'al_d']];

    $assetName = htmlEscape(assetDisplayName($asset, $ancestors));
    $assetCode = htmlEscape(clean((string) ($asset['code'] ?? '')));
    $summary = $summaryLines
        ? implode("\n", $summaryLines)
        : 'هنوز برچسبی انتخاب نشده است.';
    $text = "🏷️ <b>تغییر برچسب های مال</b>\n\n"
        . "🧾 مال: <b>{$assetName}</b>\n"
        . "🔐 کد: <b>{$assetCode}</b>\n\n"
        . "یک برچسب والد را انتخاب کنید:\n\n{$summary}";
    sendOrEditMessage($token, $chatId, $messageId, $text, ['inline_keyboard' => $rows]);
}

function renderAssetLabelChildMenu(
    string $token,
    string $chatId,
    string $messageId,
    string $parentId,
    array $labels
): void {
    $parent = findById($labels, $parentId);
    if (!is_array($parent)) {
        sendOrEditMessage($token, $chatId, $messageId, "⚠️ <b>برچسب والد معتبر نیست.</b>", null);
        return;
    }

    $rows = [];
    foreach ($labels as $label) {
        if (!is_array($label)) {
            continue;
        }
        $id = trim((string) ($label['id'] ?? ''));
        $name = clean((string) ($label['name'] ?? ''));
        if ($id === '' || $name === '' || trim((string) ($label['parent_id'] ?? '')) !== $parentId) {
            continue;
        }
        $rows[] = [['text' => '🔸 ' . $name, 'callback_data' => 'al_c:' . $id]];
    }
    $rows[] = [['text' => '⬅️ بازگشت', 'callback_data' => 'al_b']];

    $parentName = htmlEscape(clean((string) ($parent['name'] ?? '')));
    $text = "🧩 <b>انتخاب زیر‌برچسب</b>\n\n"
        . "والد: <b>{$parentName}</b>\n"
        . "لطفا یک زیر‌برچسب را انتخاب کنید.";
    if (count($rows) === 1) {
        $text .= "\n\n⚠️ برای این والد زیر‌برچسبی ثبت نشده است.";
    }
    sendOrEditMessage($token, $chatId, $messageId, $text, ['inline_keyboard' => $rows]);
}

function handleAssetLabelParentCallback(string $token, string $chatId, string $messageId, string $parentId): void
{
    $state = getChatState($chatId);
    $step = (string) ($state['step'] ?? '');
    if (!in_array($step, ['asset_lookup_labels_parent', 'asset_lookup_labels_child'], true)) {
        sendOrEditMessage($token, $chatId, $messageId, "ℹ️ <b>ابتدا تغییر برچسب را از کارت مال شروع کنید.</b>", null);
        return;
    }

    $parentIds = is_array($state['asset_action_parent_ids'] ?? null) ? $state['asset_action_parent_ids'] : [];
    if (!in_array($parentId, $parentIds, true)) {
        sendOrEditMessage($token, $chatId, $messageId, "⚠️ <b>برچسب انتخابی معتبر نیست.</b>", null);
        return;
    }

    $labels = loadLabels();
    $state['step'] = 'asset_lookup_labels_child';
    $state['asset_action_active_parent_id'] = $parentId;
    setChatState($chatId, $state);
    renderAssetLabelChildMenu($token, $chatId, $messageId, $parentId, $labels);
}

function handleAssetLabelChildCallback(string $token, string $chatId, string $messageId, string $childId): void
{
    $state = getChatState($chatId);
    if ((string) ($state['step'] ?? '') !== 'asset_lookup_labels_child') {
        sendOrEditMessage($token, $chatId, $messageId, "ℹ️ <b>ابتدا یک برچسب والد انتخاب کنید.</b>", null);
        return;
    }

    $parentId = trim((string) ($state['asset_action_active_parent_id'] ?? ''));
    if ($parentId === '') {
        sendOrEditMessage($token, $chatId, $messageId, "⚠️ <b>وضعیت انتخاب برچسب معتبر نیست.</b>", null);
        return;
    }

    $labels = loadLabels();
    if (!labelIsDirectChild($labels, $parentId, $childId)) {
        sendOrEditMessage($token, $chatId, $messageId, "⚠️ <b>زیر‌برچسب انتخابی معتبر نیست.</b>", null);
        return;
    }

    $values = is_array($state['asset_action_label_values'] ?? null) ? $state['asset_action_label_values'] : [];
    $values[$parentId] = $childId;
    $state['asset_action_label_values'] = $values;
    $state['step'] = 'asset_lookup_labels_parent';
    unset($state['asset_action_active_parent_id']);
    setChatState($chatId, $state);

    $assetId = trim((string) ($state['asset_action_asset_id'] ?? ''));
    $ancestors = loadAncestors($labels);
    $assets = loadAssets($ancestors, $labels);
    $asset = findById($assets, $assetId);
    if (!is_array($asset)) {
        clearChatState($chatId);
        sendOrEditMessage($token, $chatId, $messageId, "⚠️ <b>مال پیدا نشد.</b>", getStartMenuMarkup());
        return;
    }
    renderAssetLabelParentMenu($token, $chatId, $messageId, $state, $asset, $labels, $ancestors);
}

function handleAssetLabelBackCallback(string $token, string $chatId, string $messageId): void
{
    $state = getChatState($chatId);
    if ((string) ($state['step'] ?? '') !== 'asset_lookup_labels_child') {
        sendOrEditMessage($token, $chatId, $messageId, "ℹ️ <b>شما در صفحه والدها هستید.</b>", null);
        return;
    }

    $state['step'] = 'asset_lookup_labels_parent';
    unset($state['asset_action_active_parent_id']);
    setChatState($chatId, $state);

    $labels = loadLabels();
    $ancestors = loadAncestors($labels);
    $assets = loadAssets($ancestors, $labels);
    $asset = findById($assets, trim((string) ($state['asset_action_asset_id'] ?? '')));
    if (!is_array($asset)) {
        clearChatState($chatId);
        sendOrEditMessage($token, $chatId, $messageId, "⚠️ <b>مال پیدا نشد.</b>", getStartMenuMarkup());
        return;
    }

    renderAssetLabelParentMenu($token, $chatId, $messageId, $state, $asset, $labels, $ancestors);
}

function finalizeAssetLabelJourney(string $token, string $chatId, string $messageId, string $telegramUserId): void
{
    $state = getChatState($chatId);
    $step = (string) ($state['step'] ?? '');
    if (!in_array($step, ['asset_lookup_labels_parent', 'asset_lookup_labels_child'], true)) {
        sendOrEditMessage($token, $chatId, $messageId, "ℹ️ <b>ابتدا تغییر برچسب را از کارت مال شروع کنید.</b>", null);
        return;
    }

    $assetId = trim((string) ($state['asset_action_asset_id'] ?? ''));
    if ($assetId === '') {
        clearChatState($chatId);
        sendOrEditMessage($token, $chatId, $messageId, "⚠️ <b>وضعیت تغییر برچسب معتبر نیست.</b>", getStartMenuMarkup());
        return;
    }

    $labels = loadLabels();
    $ancestors = loadAncestors($labels);
    $storages = loadStorages();
    $assets = loadAssets($ancestors, $labels);
    $assetIndex = findAssetIndexById($assets, $assetId);
    if ($assetIndex < 0) {
        clearChatState($chatId);
        sendOrEditMessage($token, $chatId, $messageId, "⚠️ <b>مال پیدا نشد.</b>", getStartMenuMarkup());
        return;
    }

    $asset = $assets[$assetIndex];
    if (parseBool($asset['special_asset'] ?? false)) {
        clearChatState($chatId);
        sendAssetLookupCard($token, $chatId, $asset, $storages, $ancestors, $labels, $messageId, "ℹ️ <b>این مال برچسب‌پذیر نیست.</b>");
        return;
    }

    $ancestor = findById($ancestors, trim((string) ($asset['ancestor_id'] ?? '')));
    $parentIds = is_array($ancestor)
        ? sanitizeParentLabelIds($labels, (array) ($ancestor['label_ids'] ?? []))
        : [];
    $newValues = normalizeAssetLabelValues(
        is_array($state['asset_action_label_values'] ?? null) ? $state['asset_action_label_values'] : [],
        $parentIds,
        $labels
    );
    $beforeValues = normalizeAssetLabelValues(
        is_array($asset['label_values'] ?? null) ? $asset['label_values'] : [],
        $parentIds,
        $labels
    );

    $beforeComparable = json_encode($beforeValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $afterComparable = json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $changed = (string) $beforeComparable !== (string) $afterComparable;
    if ($changed) {
        $assets[$assetIndex]['label_values'] = $newValues;
        $assets[$assetIndex]['updated_at'] = date('c');
        if (!writeJsonList(ASSETS_FILE, $assets)) {
            sendOrEditMessage($token, $chatId, $messageId, "❌ <b>ذخیره برچسب ها انجام نشد.</b>", null);
            return;
        }
        $asset = $assets[$assetIndex];
        $actor = getAuthorizedActorName($telegramUserId);
        $assetName = assetDisplayName($asset, $ancestors);
        $assetCode = clean((string) ($asset['code'] ?? ''));
        appendAssetManagerLog(
            'asset_updated',
            sprintf(
                'برچسب های مال %s با کد %s توسط کاربر (%s) ویرایش شد',
                $assetName,
                $assetCode !== '' ? $assetCode : 'بدون کد',
                $actor
            ),
            [
                'asset_id' => $assetId,
                'asset_code' => $assetCode,
                'actor' => $actor,
                'field' => 'label_values',
            ]
        );
    }

    clearChatState($chatId);
    $prefix = $changed ? "✅ <b>برچسب های مال با موفقیت به‌روزرسانی شد.</b>" : "ℹ️ <b>تغییری در برچسب ها اعمال نشد.</b>";
    sendAssetLookupCard($token, $chatId, $asset, $storages, $ancestors, $labels, $messageId, $prefix);
}

function startAssetDeleteJourney(string $token, string $chatId, string $messageId, string $assetId): void
{
    $labels = loadLabels();
    $ancestors = loadAncestors($labels);
    $assets = loadAssets($ancestors, $labels);
    $asset = findById($assets, $assetId);
    if (!is_array($asset)) {
        sendOrEditMessage($token, $chatId, $messageId, "⚠️ <b>مال انتخاب‌شده پیدا نشد.</b>", getStartMenuMarkup());
        return;
    }

    setChatState($chatId, [
        'step' => 'asset_lookup_delete_pin',
        'asset_action_asset_id' => $assetId,
        'asset_action_delete_pin_attempts' => 0,
    ]);

    $assetName = htmlEscape(assetDisplayName($asset, $ancestors));
    $assetCode = htmlEscape(clean((string) ($asset['code'] ?? '')));
    $text = "🗑️ <b>حذف مال</b>\n\n"
        . "🧾 مال: <b>{$assetName}</b>\n"
        . "🔐 کد: <b>{$assetCode}</b>\n\n"
        . "برای ادامه حذف، لطفا <b>پین‌کد ۴ رقمی</b> خود را به‌صورت پیام متنی ارسال کنید.";
    sendOrEditMessage($token, $chatId, $messageId, $text, null);
}

function handleDeleteAssetPinStep(
    string $token,
    string $chatId,
    string $telegramUserId,
    string $text,
    array $state
): void {
    $pinInput = normalizePinInput($text);
    if ($pinInput === '') {
        sendMessage(
            $token,
            $chatId,
            "⚠️ <b>پین‌کد معتبر نیست.</b>\n\nلطفا پین‌کد را به‌صورت دقیق و ۴ رقمی ارسال کنید."
        );
        return;
    }

    $user = findAuthUserByTelegramId($telegramUserId);
    if (!is_array($user)) {
        clearChatState($chatId);
        sendMessage($token, $chatId, "⚠️ <b>اطلاعات کاربر یافت نشد.</b>\n\nلطفا دوباره <code>/start</code> را ارسال کنید.");
        return;
    }
    $storedPin = normalizePinInput((string) ($user['pin_code'] ?? ''));
    if ($storedPin === '') {
        clearChatState($chatId);
        sendMessage($token, $chatId, "⚠️ <b>برای حساب شما پین‌کد تعریف نشده است.</b>");
        return;
    }

    if (!hash_equals($storedPin, $pinInput)) {
        $attempts = (int) ($state['asset_action_delete_pin_attempts'] ?? 0) + 1;
        if ($attempts >= 3) {
            clearChatState($chatId);
            sendMessage(
                $token,
                $chatId,
                "⛔ <b>۳ بار پین‌کد اشتباه وارد شد.</b>\n\nفرآیند حذف لغو شد."
            );
            return;
        }
        $state['asset_action_delete_pin_attempts'] = $attempts;
        setChatState($chatId, $state);
        $remaining = 3 - $attempts;
        sendMessage(
            $token,
            $chatId,
            "❌ <b>پین‌کد اشتباه است.</b>\n\n"
                . "تعداد تلاش باقی‌مانده: <b>{$remaining}</b>"
        );
        return;
    }

    $state['step'] = 'asset_lookup_delete_note';
    $state['asset_action_delete_pin_attempts'] = 0;
    setChatState($chatId, $state);
    sendMessage(
        $token,
        $chatId,
        "✍️ <b>توضیحات حذف مال</b>\n\nلطفا دلیل یا توضیح حذف این مال را در یک پیام متنی ارسال کنید."
    );
}

function handleDeleteAssetNoteStep(
    string $token,
    string $chatId,
    string $telegramUserId,
    string $text,
    array $state
): void {
    $note = clean($text);
    if ($note === '') {
        sendMessage(
            $token,
            $chatId,
            "⚠️ <b>توضیحات حذف نمی‌تواند خالی باشد.</b>\n\nلطفا متن توضیح را ارسال کنید."
        );
        return;
    }

    $assetId = trim((string) ($state['asset_action_asset_id'] ?? ''));
    if ($assetId === '') {
        clearChatState($chatId);
        sendMessage($token, $chatId, "⚠️ <b>وضعیت حذف معتبر نیست.</b>");
        return;
    }

    $labels = loadLabels();
    $ancestors = loadAncestors($labels);
    $assets = loadAssets($ancestors, $labels);
    $assetIndex = findAssetIndexById($assets, $assetId);
    if ($assetIndex < 0) {
        clearChatState($chatId);
        sendMessage($token, $chatId, "⚠️ <b>مال موردنظر پیدا نشد.</b>");
        return;
    }

    $asset = $assets[$assetIndex];
    array_splice($assets, $assetIndex, 1);
    if (!writeJsonList(ASSETS_FILE, $assets)) {
        sendMessage($token, $chatId, "❌ <b>حذف مال انجام نشد.</b>\n\nخطا در ذخیره اطلاعات.");
        return;
    }

    $actor = getAuthorizedActorName($telegramUserId);
    $assetName = assetDisplayName($asset, $ancestors);
    $assetCode = clean((string) ($asset['code'] ?? ''));
    appendAssetManagerLog(
        'asset_removed',
        sprintf(
            'مال %s با کد %s توسط کاربر (%s) حذف شد. توضیحات حذف: %s',
            $assetName,
            $assetCode !== '' ? $assetCode : 'بدون کد',
            $actor,
            $note
        ),
        [
            'asset_id' => $assetId,
            'asset_code' => $assetCode,
            'actor' => $actor,
            'delete_note' => $note,
        ]
    );

    clearChatState($chatId);
    $safeName = htmlEscape($assetName);
    $safeCode = htmlEscape($assetCode !== '' ? $assetCode : 'بدون کد');
    sendMessage(
        $token,
        $chatId,
        "✅ <b>مال با موفقیت حذف شد.</b>\n\n"
            . "🧾 نام مال: <b>{$safeName}</b>\n"
            . "🔐 کد مال: <b>{$safeCode}</b>\n"
            . "📝 توضیحات حذف در لاگ‌ها ثبت شد."
    );
    sendStartMenu($token, $chatId);
}

function getAuthorizedActorName(string $telegramUserId): string
{
    $user = findAuthUserByTelegramId($telegramUserId);
    if (!is_array($user)) {
        return 'کاربر نامشخص';
    }
    foreach (['fullname', 'username', 'phone', 'code'] as $field) {
        $value = clean((string) ($user[$field] ?? ''));
        if ($value !== '' && $value !== '0') {
            return $value;
        }
    }
    return 'کاربر نامشخص';
}

function ensureAssetLogHelpersLoaded(): bool
{
    static $attempted = false;
    static $loaded = false;
    if ($attempted) {
        return $loaded;
    }
    $attempted = true;
    if (!is_file(ASSET_LOGS_HELPER_FILE)) {
        return false;
    }
    require_once ASSET_LOGS_HELPER_FILE;
    $loaded = function_exists('assetLogsAppend');
    return $loaded;
}

function appendAssetManagerLog(string $action, string $message, array $context = []): void
{
    $timestamp = gmdate('c');
    if (!ensureAssetLogHelpersLoaded()) {
        $fallbackPayload = [
            'id' => randomId(),
            'action' => $action,
            'timestamp' => $timestamp,
            'message' => clean($message),
        ];
        foreach ($context as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $fallbackPayload[$key] = (string) $value;
            }
        }

        $fallbackJson = json_encode($fallbackPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $fallbackDir = DATA_DIR . '/logs';
        if (!is_dir($fallbackDir)) {
            @mkdir($fallbackDir, 0755, true);
        }
        $fallbackFile = $fallbackDir . '/' . gmdate('Y-m-d') . '.jsonl';
        if (!is_string($fallbackJson) || $fallbackJson === '' || @file_put_contents($fallbackFile, $fallbackJson . "\n", FILE_APPEND | LOCK_EX) === false) {
            logEvent('asset_log_helper_missing', ['action' => $action, 'message' => $message, 'fallback' => 'failed']);
        } else {
            logEvent('asset_log_helper_missing', ['action' => $action, 'message' => $message, 'fallback' => 'written']);
        }
        return;
    }

    $payload = [
        'action' => $action,
        'timestamp' => $timestamp,
        'message' => $message,
    ];
    foreach ($context as $key => $value) {
        if (!is_string($key) || $key === '') {
            continue;
        }
        if (is_scalar($value) || $value === null) {
            $payload[$key] = (string) $value;
        }
    }
    assetLogsAppend($payload);
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
