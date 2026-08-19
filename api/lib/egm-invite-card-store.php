<?php
declare(strict_types=1);

const EGM_INVITE_CARD_QR_DATA = '[nationalid]';

function egmInviteCardJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function egmInviteCardString($value, int $limit): string
{
    if (!is_scalar($value)) {
        return '';
    }
    $text = trim((string)$value);
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $limit, 'UTF-8');
    }
    return substr($text, 0, $limit);
}

function egmInviteCardSanitizeEditorHtml($value): string
{
    if (!is_scalar($value)) {
        return '';
    }
    $html = substr((string)$value, 0, 50000);
    $html = preg_replace('/<!--[\s\S]*?-->/u', '', $html) ?? '';
    $html = strip_tags($html, '<strong><b><ul><ol><li><br><p><div><span>');
    $html = preg_replace_callback(
        '/<(\/?)(strong|b|ul|ol|li|br|p|div|span)\b([^>]*)>/iu',
        static function (array $matches): string {
            $closing = (string)($matches[1] ?? '') === '/';
            $tag = strtolower((string)($matches[2] ?? ''));
            if ($tag !== 'span' || $closing) {
                return '<' . ($closing ? '/' : '') . $tag . '>';
            }
            $attributes = (string)($matches[3] ?? '');
            $color = '';
            if (preg_match('/color\s*:\s*(#[0-9a-f]{3}|#[0-9a-f]{6})\b/iu', $attributes, $colorMatch) === 1) {
                $color = strtolower((string)$colorMatch[1]);
            }
            if (strlen($color) === 4) {
                $color = '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
            }
            return $color !== '' ? '<span style="color:' . $color . '">' : '<span>';
        },
        $html
    ) ?? '';
    $html = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $html) ?? '';
    return trim($html);
}

function egmInviteCardPlainTextFromHtml(string $html): string
{
    $withLines = preg_replace('/<(?:br|\/p|\/div|\/li)>/iu', "\n", $html) ?? $html;
    $plain = html_entity_decode(strip_tags($withLines), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = preg_replace("/\r\n?|\n{3,}/u", "\n", $plain) ?? $plain;
    return trim($plain);
}

function egmInviteCardRect($value, string $label): array
{
    if (!is_array($value)) {
        throw new InvalidArgumentException("ناحیه {$label} تعیین نشده است.");
    }
    $rect = [];
    foreach (['x', 'y', 'width', 'height'] as $key) {
        if (!isset($value[$key]) || !is_numeric($value[$key])) {
            throw new InvalidArgumentException("مختصات ناحیه {$label} نامعتبر است.");
        }
        $rect[$key] = round((float)$value[$key], 5);
    }
    if ($rect['x'] < 0 || $rect['y'] < 0 || $rect['width'] < 0.5 || $rect['height'] < 0.5
        || ($rect['x'] + $rect['width']) > 100.0001 || ($rect['y'] + $rect['height']) > 100.0001) {
        throw new InvalidArgumentException("ناحیه {$label} خارج از محدوده تصویر است.");
    }
    return $rect;
}

function egmInviteCardOptionalRect($value, string $label): ?array
{
    return $value === null || $value === '' ? null : egmInviteCardRect($value, $label);
}

function egmInviteCardImage($value): array
{
    if (!is_scalar($value)) {
        throw new InvalidArgumentException('تصویر کارت دعوت ارسال نشده است.');
    }
    $dataUri = (string)$value;
    if (preg_match('#^data:(image/(?:png|jpeg|webp));base64,([A-Za-z0-9+/=\r\n]+)$#', $dataUri, $matches) !== 1) {
        throw new InvalidArgumentException('فرمت تصویر باید PNG، JPG یا WebP باشد.');
    }
    $binary = base64_decode(preg_replace('/\s+/', '', $matches[2]), true);
    if (!is_string($binary) || $binary === '') {
        throw new InvalidArgumentException('محتوای تصویر نامعتبر است.');
    }
    if (strlen($binary) > 8 * 1024 * 1024) {
        throw new InvalidArgumentException('حجم تصویر نباید بیشتر از 8 مگابایت باشد.');
    }
    $info = @getimagesizefromstring($binary);
    if (!is_array($info) || (int)($info[0] ?? 0) < 1 || (int)($info[1] ?? 0) < 1) {
        throw new InvalidArgumentException('فایل انتخاب‌شده یک تصویر معتبر نیست.');
    }
    if ((int)$info[0] * (int)$info[1] > 40000000) {
        throw new InvalidArgumentException('ابعاد تصویر نباید بیشتر از 40 میلیون پیکسل باشد.');
    }
    $actualMime = strtolower((string)($info['mime'] ?? ''));
    if (!in_array($actualMime, ['image/png', 'image/jpeg', 'image/webp'], true) || $actualMime !== strtolower($matches[1])) {
        throw new InvalidArgumentException('نوع واقعی فایل تصویر با فرمت اعلام‌شده مطابقت ندارد.');
    }
    return [
        'data' => 'data:' . $actualMime . ';base64,' . base64_encode($binary),
        'mime' => $actualMime,
        'width' => (int)$info[0],
        'height' => (int)$info[1],
        'bytes' => strlen($binary),
    ];
}

function egmInviteCardFont($value, $name = ''): array
{
    if ($value === null || $value === '') {
        return ['data' => '', 'name' => '', 'mime' => '', 'bytes' => 0];
    }
    if (!is_scalar($value)) {
        throw new InvalidArgumentException('فایل فونت نامعتبر است.');
    }
    $dataUri = (string)$value;
    if (preg_match('#^data:([a-z0-9.+/-]+);base64,([A-Za-z0-9+/=\r\n]+)$#i', $dataUri, $matches) !== 1) {
        throw new InvalidArgumentException('فرمت فایل فونت نامعتبر است.');
    }
    $binary = base64_decode(preg_replace('/\s+/', '', $matches[2]), true);
    if (!is_string($binary) || strlen($binary) < 12) {
        throw new InvalidArgumentException('محتوای فایل فونت نامعتبر است.');
    }
    if (strlen($binary) > 6 * 1024 * 1024) {
        throw new InvalidArgumentException('حجم فونت نباید بیشتر از 6 مگابایت باشد.');
    }
    $signature = substr($binary, 0, 4);
    $mime = match ($signature) {
        "\x00\x01\x00\x00", 'true' => 'font/ttf',
        'OTTO' => 'font/otf',
        'wOFF' => 'font/woff',
        'wOF2' => 'font/woff2',
        default => '',
    };
    if ($mime === '') {
        throw new InvalidArgumentException('فقط فونت‌های TTF، OTF، WOFF و WOFF2 مجاز هستند.');
    }
    $safeName = egmInviteCardString($name, 255);
    if ($safeName === '') {
        $safeName = 'invite-card-font.' . substr($mime, 5);
    }
    return [
        'data' => 'data:' . $mime . ';base64,' . base64_encode($binary),
        'name' => $safeName,
        'mime' => $mime,
        'bytes' => strlen($binary),
    ];
}

function egmInviteCardAssetPath(string $asset): string
{
    if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $asset) !== 1) {
        throw new InvalidArgumentException('نوع فایل کارت دعوت نامعتبر است.');
    }
    return 'invite-card/' . $asset;
}

function egmInviteCardWriteAsset(PDO $pdo, string $code, string $asset, string $dataUri): void
{
    $path = egmInviteCardAssetPath($asset);
    if (preg_match('#^data:([a-z0-9.+/-]+);base64,([A-Za-z0-9+/=\r\n]+)$#i', $dataUri, $matches) !== 1) {
        throw new InvalidArgumentException('محتوای فایل کارت دعوت نامعتبر است.');
    }
    $binary = base64_decode(preg_replace('/\s+/', '', (string)$matches[2]), true);
    if (!is_string($binary) || $binary === '') {
        throw new InvalidArgumentException('محتوای فایل کارت دعوت قابل خواندن نیست.');
    }

    $tables = ensureEgmInstanceTables($pdo, $code);
    $table = (string)$tables['data'];
    $mime = strtolower((string)$matches[1]);
    $hash = hash('sha256', $binary);
    // Stay comfortably below small/default MySQL max_allowed_packet values.
    $chunkSize = 262144;
    $chunkCount = max(1, (int)ceil(strlen($binary) / $chunkSize));
    $metadata = json_encode([
        'asset' => $asset,
        'mime' => $mime,
        'sha256' => $hash,
        'size' => strlen($binary),
        'chunks' => $chunkCount,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($metadata)) {
        throw new RuntimeException('ساخت اطلاعات فایل کارت دعوت ناموفق بود.');
    }

    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $delete = $pdo->prepare("DELETE FROM `{$table}` WHERE `file_path` = :file_path AND `storage_kind` IN ('invite_card_asset', 'invite_card_chunk')");
        $delete->execute([':file_path' => $path]);

        $header = $pdo->prepare(
            "INSERT INTO `{$table}` (`data_key`, `payload`, `storage_kind`, `file_path`, `content_sha256`, `file_size`, `file_chunk`) "
            . "VALUES (:data_key, :payload, 'invite_card_asset', :file_path, :sha256, :file_size, NULL)"
        );
        $header->bindValue(':data_key', 'invite_card_asset:' . $asset);
        $header->bindValue(':payload', $metadata);
        $header->bindValue(':file_path', $path);
        $header->bindValue(':sha256', $hash);
        $header->bindValue(':file_size', strlen($binary), PDO::PARAM_INT);
        $header->execute();

        $chunkStatement = $pdo->prepare(
            "INSERT INTO `{$table}` (`data_key`, `payload`, `storage_kind`, `file_path`, `file_data`, `content_sha256`, `file_size`, `file_chunk`) "
            . "VALUES (:data_key, :payload, 'invite_card_chunk', :file_path, :file_data, :sha256, :file_size, :file_chunk)"
        );
        for ($chunkIndex = 0; $chunkIndex < $chunkCount; $chunkIndex++) {
            $chunk = substr($binary, $chunkIndex * $chunkSize, $chunkSize);
            $chunkStatement->bindValue(':data_key', 'invite_card_chunk:' . $asset . ':' . str_pad((string)$chunkIndex, 8, '0', STR_PAD_LEFT));
            $chunkStatement->bindValue(':payload', '{}');
            $chunkStatement->bindValue(':file_path', $path);
            $chunkStatement->bindValue(':file_data', $chunk, PDO::PARAM_LOB);
            $chunkStatement->bindValue(':sha256', hash('sha256', $chunk));
            $chunkStatement->bindValue(':file_size', strlen($chunk), PDO::PARAM_INT);
            $chunkStatement->bindValue(':file_chunk', $chunkIndex, PDO::PARAM_INT);
            $chunkStatement->execute();
        }
        if ($startedTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $error) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function egmInviteCardDeleteAsset(PDO $pdo, string $code, string $asset): void
{
    $path = egmInviteCardAssetPath($asset);
    $tables = ensureEgmInstanceTables($pdo, $code);
    $table = (string)$tables['data'];
    $statement = $pdo->prepare("DELETE FROM `{$table}` WHERE `file_path` = :file_path AND `storage_kind` IN ('invite_card_asset', 'invite_card_chunk')");
    $statement->execute([':file_path' => $path]);
}

function egmInviteCardHasAsset(PDO $pdo, string $code, string $asset): bool
{
    $path = egmInviteCardAssetPath($asset);
    $tables = ensureEgmInstanceTables($pdo, $code);
    $table = (string)$tables['data'];
    $statement = $pdo->prepare("SELECT 1 FROM `{$table}` WHERE `file_path` = :file_path AND `storage_kind` = 'invite_card_asset' LIMIT 1");
    $statement->execute([':file_path' => $path]);
    return $statement->fetchColumn() !== false;
}

function egmInviteCardReadAsset(PDO $pdo, string $code, string $asset): string
{
    $path = egmInviteCardAssetPath($asset);
    $tables = ensureEgmInstanceTables($pdo, $code);
    $table = (string)$tables['data'];
    $header = $pdo->prepare(
        "SELECT `payload`, `content_sha256`, `file_size` FROM `{$table}` "
        . "WHERE `file_path` = :file_path AND `storage_kind` = 'invite_card_asset' LIMIT 1"
    );
    $header->execute([':file_path' => $path]);
    $row = $header->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return '';
    }
    $metadata = json_decode((string)($row['payload'] ?? ''), true);
    if (!is_array($metadata) || trim((string)($metadata['mime'] ?? '')) === '') {
        throw new RuntimeException('اطلاعات فایل ذخیره‌شده کارت دعوت ناقص است.');
    }

    $chunks = $pdo->prepare(
        "SELECT `file_data`, `content_sha256` FROM `{$table}` "
        . "WHERE `file_path` = :file_path AND `storage_kind` = 'invite_card_chunk' ORDER BY `file_chunk`"
    );
    $chunks->execute([':file_path' => $path]);
    $binary = '';
    while ($chunkRow = $chunks->fetch(PDO::FETCH_ASSOC)) {
        $chunk = $chunkRow['file_data'] ?? null;
        if (!is_string($chunk) || hash('sha256', $chunk) !== trim((string)($chunkRow['content_sha256'] ?? ''))) {
            throw new RuntimeException('یکی از بخش‌های فایل کارت دعوت آسیب دیده است.');
        }
        $binary .= $chunk;
    }
    $expectedSize = (int)($metadata['size'] ?? $row['file_size'] ?? -1);
    $expectedHash = trim((string)($metadata['sha256'] ?? $row['content_sha256'] ?? ''));
    if ($binary === '' || strlen($binary) !== $expectedSize || hash('sha256', $binary) !== $expectedHash) {
        throw new RuntimeException('فایل ذخیره‌شده کارت دعوت با اطلاعات پایگاه داده تطبیق ندارد.');
    }
    return 'data:' . strtolower((string)$metadata['mime']) . ';base64,' . base64_encode($binary);
}

function egmInviteCardHydrateAssets(PDO $pdo, string $code, $configuration): ?array
{
    if (!is_array($configuration)) {
        return null;
    }
    if (trim((string)($configuration['imageData'] ?? '')) === '') {
        $configuration['imageData'] = egmInviteCardReadAsset($pdo, $code, 'image');
    }
    if (trim((string)($configuration['fontData'] ?? '')) === '' && (int)($configuration['fontBytes'] ?? 0) > 0) {
        $configuration['fontData'] = egmInviteCardReadAsset($pdo, $code, 'font');
    }
    return $configuration;
}

function egmInviteCardPersistAssets(PDO $pdo, string $code, array $configuration, array $assets): array
{
    if (in_array('image', $assets, true)) {
        $imageData = (string)($configuration['imageData'] ?? '');
        if ($imageData === '') {
            throw new InvalidArgumentException('تصویر کارت دعوت برای ذخیره ارسال نشده است.');
        }
        egmInviteCardWriteAsset($pdo, $code, 'image', $imageData);
        $configuration['imageData'] = '';
        $configuration['imageStorage'] = 'database_chunks';
    }
    if (in_array('font', $assets, true)) {
        $fontData = (string)($configuration['fontData'] ?? '');
        if ($fontData !== '') {
            egmInviteCardWriteAsset($pdo, $code, 'font', $fontData);
            $configuration['fontStorage'] = 'database_chunks';
        } else {
            egmInviteCardDeleteAsset($pdo, $code, 'font');
            $configuration['fontStorage'] = '';
        }
        $configuration['fontData'] = '';
    }
    return $configuration;
}

function egmInviteCardMergeImageDraft($stored, array $payload): array
{
    $image = egmInviteCardImage($payload['imageData'] ?? null);
    $next = is_array($stored) ? $stored : [];
    $next['version'] = max(3, (int)($next['version'] ?? 0));
    $next['imageData'] = $image['data'];
    $next['imageName'] = egmInviteCardString($payload['imageName'] ?? 'invite-card', 255);
    $next['imageMime'] = $image['mime'];
    $next['imageWidth'] = $image['width'];
    $next['imageHeight'] = $image['height'];
    $next['imageBytes'] = $image['bytes'];
    // Coordinates from the previous artwork cannot safely be reused on a new image.
    $next['qrRect'] = null;
    $next['textRect'] = null;
    $next['updatedAt'] = gmdate('c');
    return $next;
}

function egmInviteCardNormalizeConditionalVariables($value): array
{
    if ($value === null || $value === '') {
        return [];
    }
    if (!is_array($value)) {
        throw new InvalidArgumentException('ساختار متغیرهای شرطی نامعتبر است.');
    }
    if (count($value) > 50) {
        throw new InvalidArgumentException('حداکثر 50 متغیر شرطی قابل تعریف است.');
    }
    $fields = [
        'fullname', 'firstname', 'lastname', 'nationalid', 'workid', 'guestnumber',
        'phonenumber', 'deputy', 'generaldepartment', 'department', 'gender',
        'postallevel', 'score',
    ];
    $operators = ['equals', 'not_equals', 'contains', 'not_contains', 'empty', 'not_empty'];
    $reserved = array_fill_keys($fields, true);
    $normalized = [];
    $seen = [];
    foreach ($value as $definition) {
        if (!is_array($definition)) {
            throw new InvalidArgumentException('یکی از متغیرهای شرطی نامعتبر است.');
        }
        $token = strtolower(egmInviteCardString($definition['token'] ?? '', 32));
        $token = trim($token, "[] \t\n\r\0\x0B");
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $token) !== 1) {
            throw new InvalidArgumentException('نام متغیر شرطی باید با حرف انگلیسی شروع شود و فقط شامل حروف، عدد یا _ باشد.');
        }
        if (isset($reserved[$token])) {
            throw new InvalidArgumentException("متغیر [{$token}] از قبل برای اطلاعات دعوت‌شونده رزرو شده است.");
        }
        if (isset($seen[$token])) {
            throw new InvalidArgumentException("متغیر شرطی [{$token}] تکراری است.");
        }
        $field = strtolower(egmInviteCardString($definition['field'] ?? '', 32));
        if (!in_array($field, $fields, true)) {
            throw new InvalidArgumentException("فیلد متغیر شرطی [{$token}] معتبر نیست.");
        }
        $rules = $definition['rules'] ?? null;
        if (!is_array($rules) || $rules === [] || count($rules) > 20) {
            throw new InvalidArgumentException("متغیر شرطی [{$token}] باید بین 1 تا 20 شرط داشته باشد.");
        }
        $normalizedRules = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                throw new InvalidArgumentException("یکی از شرط‌های [{$token}] نامعتبر است.");
            }
            $operator = strtolower(egmInviteCardString($rule['operator'] ?? '', 20));
            if (!in_array($operator, $operators, true)) {
                throw new InvalidArgumentException("عملگر یکی از شرط‌های [{$token}] معتبر نیست.");
            }
            $expected = in_array($operator, ['empty', 'not_empty'], true)
                ? ''
                : egmInviteCardString($rule['value'] ?? '', 500);
            if ($expected === '' && !in_array($operator, ['empty', 'not_empty'], true)) {
                throw new InvalidArgumentException("مقدار یکی از شرط‌های [{$token}] خالی است.");
            }
            $normalizedRules[] = [
                'operator' => $operator,
                'value' => $expected,
                'text' => egmInviteCardString($rule['text'] ?? '', 500),
            ];
        }
        $normalized[] = [
            'token' => $token,
            'field' => $field,
            'rules' => $normalizedRules,
            'fallback' => egmInviteCardString($definition['fallback'] ?? '', 500),
        ];
        $seen[$token] = true;
    }
    return $normalized;
}

function egmInviteCardNormalizeConditionalBuilderDraft($value): array
{
    if (!is_array($value)) {
        return [];
    }
    $fields = [
        'fullname', 'firstname', 'lastname', 'nationalid', 'workid', 'guestnumber',
        'phonenumber', 'deputy', 'generaldepartment', 'department', 'gender',
        'postallevel', 'score',
    ];
    $operators = ['equals', 'not_equals', 'contains', 'not_contains', 'empty', 'not_empty'];
    $token = strtolower(egmInviteCardString($value['token'] ?? 'code', 32));
    $token = preg_replace('/[^a-z0-9_]/', '', trim($token, '[]')) ?? 'code';
    if ($token === '' || preg_match('/^[a-z]/', $token) !== 1) {
        $token = 'code';
    }
    $field = strtolower(egmInviteCardString($value['field'] ?? 'gender', 32));
    if (!in_array($field, $fields, true)) {
        $field = 'gender';
    }
    $rules = [];
    if (is_array($value['rules'] ?? null)) {
        foreach (array_slice($value['rules'], 0, 20) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $operator = strtolower(egmInviteCardString($rule['operator'] ?? 'equals', 20));
            if (!in_array($operator, $operators, true)) {
                $operator = 'equals';
            }
            $rules[] = [
                'operator' => $operator,
                'value' => in_array($operator, ['empty', 'not_empty'], true) ? '' : egmInviteCardString($rule['value'] ?? '', 500),
                'text' => egmInviteCardString($rule['text'] ?? '', 500),
            ];
        }
    }
    if ($rules === []) {
        $rules[] = ['operator' => 'equals', 'value' => '', 'text' => ''];
    }
    return [
        'token' => $token,
        'field' => $field,
        'rules' => $rules,
        'fallback' => egmInviteCardString($value['fallback'] ?? '', 500),
        'editingToken' => strtolower(egmInviteCardString($value['editingToken'] ?? '', 32)),
    ];
}

function egmInviteCardMergeDraft($stored, array $payload): array
{
    $sections = $payload['sections'] ?? [];
    if (!is_array($sections)) {
        throw new InvalidArgumentException('بخش‌های ذخیره خودکار نامعتبر است.');
    }
    $sections = array_values(array_unique(array_map('strval', $sections)));
    $next = is_array($stored) ? $stored : [];
    $next['version'] = max(3, (int)($next['version'] ?? 0));
    $changed = false;

    if (in_array('content', $sections, true)) {
        $textHtml = egmInviteCardSanitizeEditorHtml($payload['textHtml'] ?? '');
        $next['textHtml'] = $textHtml;
        $next['text'] = egmInviteCardString(egmInviteCardPlainTextFromHtml($textHtml), 10000);
        $next['qrData'] = EGM_INVITE_CARD_QR_DATA;
        $next['conditionalVariables'] = egmInviteCardNormalizeConditionalVariables($payload['conditionalVariables'] ?? []);
        $next['conditionalBuilderDraft'] = egmInviteCardNormalizeConditionalBuilderDraft($payload['conditionalBuilderDraft'] ?? []);
        $changed = true;
    }
    if (in_array('font', $sections, true)) {
        $font = egmInviteCardFont($payload['fontData'] ?? null, $payload['fontName'] ?? '');
        $next['fontData'] = $font['data'];
        $next['fontName'] = $font['name'];
        $next['fontMime'] = $font['mime'];
        $next['fontBytes'] = $font['bytes'];
        $changed = true;
    }
    if (in_array('layout', $sections, true)) {
        $next['qrRect'] = egmInviteCardOptionalRect($payload['qrRect'] ?? null, 'QR Code');
        $next['textRect'] = egmInviteCardOptionalRect($payload['textRect'] ?? null, 'Invite Text Area');
        $changed = true;
    }
    if (!$changed) {
        throw new InvalidArgumentException('هیچ بخش معتبری برای ذخیره خودکار ارسال نشده است.');
    }
    $next['updatedAt'] = gmdate('c');
    return $next;
}

function egmInviteCardNormalizeConfig(array $payload): array
{
    $image = egmInviteCardImage($payload['imageData'] ?? null);
    $font = egmInviteCardFont($payload['fontData'] ?? null, $payload['fontName'] ?? '');
    $rawHtml = $payload['textHtml'] ?? null;
    if (!is_scalar($rawHtml) || trim((string)$rawHtml) === '') {
        $legacyText = egmInviteCardString($payload['text'] ?? '', 10000);
        $rawHtml = nl2br(htmlspecialchars($legacyText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    }
    $textHtml = egmInviteCardSanitizeEditorHtml($rawHtml);
    $text = egmInviteCardString(egmInviteCardPlainTextFromHtml($textHtml), 10000);
    $qrData = EGM_INVITE_CARD_QR_DATA;
    if ($text === '') {
        throw new InvalidArgumentException('متن کارت دعوت نمی‌تواند خالی باشد.');
    }
    return [
        'version' => 3,
        'imageData' => $image['data'],
        'imageName' => egmInviteCardString($payload['imageName'] ?? 'invite-card', 255),
        'imageMime' => $image['mime'],
        'imageWidth' => $image['width'],
        'imageHeight' => $image['height'],
        'imageBytes' => $image['bytes'],
        'fontData' => $font['data'],
        'fontName' => $font['name'],
        'fontMime' => $font['mime'],
        'fontBytes' => $font['bytes'],
        'qrRect' => egmInviteCardRect($payload['qrRect'] ?? null, 'QR Code'),
        'textRect' => egmInviteCardRect($payload['textRect'] ?? null, 'Invite Text Area'),
        'text' => $text,
        'textHtml' => $textHtml,
        'conditionalVariables' => egmInviteCardNormalizeConditionalVariables($payload['conditionalVariables'] ?? []),
        'conditionalBuilderDraft' => egmInviteCardNormalizeConditionalBuilderDraft($payload['conditionalBuilderDraft'] ?? []),
        'qrData' => $qrData,
        'updatedAt' => gmdate('c'),
    ];
}

function handleEgmInviteCardStore(string $projectRoot, string $missionDir, array $user): never
{
    if (!userHasPermissionId($user, 'event-guest-manager:main')) {
        denyPanelAccess(403, 'You do not have permission to access this Event Guest Manager section.', true);
    }
    try {
        $config = loadConfig(rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'config.php');
        $pdo = connectDatabase($config);
        if (!$pdo instanceof PDO) {
            egmInviteCardJson(['status' => 'error', 'message' => 'اتصال پایگاه داده EGM برقرار نشد.'], 503);
        }
        $registry = egmInstanceRegistryForDirectory($pdo, $missionDir);
        if (!is_array($registry) || trim((string)($registry['code'] ?? '')) === '') {
            egmInviteCardJson(['status' => 'error', 'message' => 'این EGM هنوز در پایگاه داده ثبت نشده است.'], 409);
        }
        $code = (string)$registry['code'];
        egmInstanceEnrichUsersFromOeu($pdo, $code);
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $action = strtolower(trim((string)($_GET['action'] ?? '')));
        if ($method === 'GET' && $action === 'conditional_values') {
            $tables = ensureEgmInstanceTables($pdo, $code);
            $usersTable = (string)$tables['users'];
            $field = strtolower(egmInviteCardString($_GET['field'] ?? '', 32));
            $expressions = [
                'fullname' => "CONCAT_WS(' ', `first_name`, `last_name`)",
                'firstname' => '`first_name`',
                'lastname' => '`last_name`',
                'nationalid' => '`national_id`',
                'workid' => '`work_id`',
                'guestnumber' => '`guest_number`',
                'phonenumber' => '`phone_number`',
                'deputy' => '`deputy`',
                'generaldepartment' => '`general_department`',
                'department' => '`department`',
                'gender' => '`gender`',
                'postallevel' => '`postal_level`',
                'score' => '`total_score`',
            ];
            if (!isset($expressions[$field])) {
                egmInviteCardJson(['status' => 'error', 'message' => 'ستون انتخاب‌شده معتبر نیست.'], 422);
            }
            $valueExpression = 'TRIM(CAST(' . $expressions[$field] . ' AS CHAR))';
            $statement = $pdo->query(
                "SELECT DISTINCT {$valueExpression} AS `value` FROM `{$usersTable}` "
                . "WHERE `is_active` = 1 AND {$valueExpression} <> '' "
                . "ORDER BY `value` LIMIT 2001"
            );
            $valueRows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $hasMore = count($valueRows) > 2000;
            $values = array_values(array_map(
                static fn(array $row): string => (string)($row['value'] ?? ''),
                array_slice($valueRows, 0, 2000)
            ));
            egmInviteCardJson([
                'status' => 'ok',
                'rows' => $values,
                'hasMore' => $hasMore,
                'field' => $field,
                'egmCode' => $code,
            ]);
        }
        if ($method === 'GET' && $action === 'invitees') {
            $tables = ensureEgmInstanceTables($pdo, $code);
            $usersTable = (string)$tables['users'];
            $query = egmInviteCardString($_GET['q'] ?? '', 100);
            $where = '`is_active` = 1';
            $params = [];
            if ($query !== '') {
                $where .= ' AND (`first_name` LIKE :q0 OR `last_name` LIKE :q1 OR `work_id` LIKE :q2 OR `national_id` LIKE :q3 OR `phone_number` LIKE :q4 OR `guest_number` LIKE :q5)';
                for ($index = 0; $index < 6; $index++) {
                    $params[':q' . $index] = '%' . $query . '%';
                }
            }
            $statement = $pdo->prepare(
                "SELECT `id`, `work_id`, `first_name`, `last_name`, `national_id`, `phone_number`, "
                . "`deputy`, `general_department`, `department`, `gender`, `postal_level`, `guest_number`, `total_score` "
                . "FROM `{$usersTable}` WHERE {$where} "
                . "ORDER BY `last_name`, `first_name`, `work_id` LIMIT 101"
            );
            $statement->execute($params);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $hasMore = count($rows) > 100;
            $rows = array_slice($rows, 0, 100);
            $invitees = array_map(static function (array $row): array {
                return [
                    'id' => (string)($row['id'] ?? ''),
                    'workId' => (string)($row['work_id'] ?? ''),
                    'firstName' => (string)($row['first_name'] ?? ''),
                    'lastName' => (string)($row['last_name'] ?? ''),
                    'nationalId' => (string)($row['national_id'] ?? ''),
                    'phoneNumber' => (string)($row['phone_number'] ?? ''),
                    'deputy' => (string)($row['deputy'] ?? ''),
                    'generalDepartment' => (string)($row['general_department'] ?? ''),
                    'department' => (string)($row['department'] ?? ''),
                    'gender' => (string)($row['gender'] ?? ''),
                    'postalLevel' => (string)($row['postal_level'] ?? ''),
                    'guestNumber' => (string)($row['guest_number'] ?? ''),
                    'score' => (string)($row['total_score'] ?? '0'),
                ];
            }, $rows);
            egmInviteCardJson(['status' => 'ok', 'rows' => $invitees, 'hasMore' => $hasMore, 'egmCode' => $code]);
        }
        if ($method === 'GET') {
            $stored = egmInstanceReadData($pdo, $code, 'invite_card', null);
            $stored = egmInviteCardHydrateAssets($pdo, $code, $stored);
            egmInviteCardJson(['status' => 'ok', 'data' => $stored, 'egmCode' => $code]);
        }
        if ($method !== 'POST') {
            header('Allow: GET, POST');
            egmInviteCardJson(['status' => 'error', 'message' => 'Method not allowed.'], 405);
        }
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || $raw === '' || strlen($raw) > 24 * 1024 * 1024) {
            egmInviteCardJson(['status' => 'error', 'message' => 'درخواست ذخیره‌سازی نامعتبر یا بیش از حد بزرگ است.'], 413);
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            egmInviteCardJson(['status' => 'error', 'message' => 'بدنه درخواست JSON معتبر نیست.'], 400);
        }
        $csrf = egmSecurityReadCsrfFromRequest($payload, 'csrf');
        if (!egmSecurityIsValidCsrfToken($csrf)) {
            egmInviteCardJson(['status' => 'error', 'message' => 'توکن امنیتی نامعتبر است.'], 403);
        }
        if ($action === 'image') {
            $stored = egmInstanceReadData($pdo, $code, 'invite_card', null);
            $next = egmInviteCardMergeImageDraft($stored, $payload);
            $next = egmInviteCardPersistAssets($pdo, $code, $next, ['image']);
            egmInstanceWriteData($pdo, $code, 'invite_card', $next);
            egmInviteCardJson([
                'status' => 'ok',
                'message' => 'تصویر کارت دعوت بلافاصله در پایگاه داده ذخیره شد.',
                'data' => $next,
                'egmCode' => $code,
            ]);
        }
        if ($action === 'draft') {
            $stored = egmInstanceReadData($pdo, $code, 'invite_card', null);
            $next = egmInviteCardMergeDraft($stored, $payload);
            if (in_array('font', is_array($payload['sections'] ?? null) ? $payload['sections'] : [], true)) {
                $next = egmInviteCardPersistAssets($pdo, $code, $next, ['font']);
            }
            egmInstanceWriteData($pdo, $code, 'invite_card', $next);
            egmInviteCardJson([
                'status' => 'ok',
                'message' => 'تغییرات کارت دعوت به‌صورت خودکار ذخیره شد.',
                'data' => $next,
                'egmCode' => $code,
            ]);
        }
        $normalized = egmInviteCardNormalizeConfig($payload);
        $normalized = egmInviteCardPersistAssets($pdo, $code, $normalized, ['image', 'font']);
        egmInstanceWriteData($pdo, $code, 'invite_card', $normalized);
        egmInviteCardJson(['status' => 'ok', 'message' => 'تنظیمات کارت دعوت در پایگاه داده ذخیره شد.', 'data' => $normalized, 'egmCode' => $code]);
    } catch (InvalidArgumentException $error) {
        egmInviteCardJson(['status' => 'error', 'message' => $error->getMessage()], 422);
    } catch (Throwable $error) {
        error_log('EGM invite card store failed: ' . $error->getMessage());
        egmInviteCardJson(['status' => 'error', 'message' => 'ذخیره تنظیمات کارت دعوت ناموفق بود.'], 500);
    }
}
