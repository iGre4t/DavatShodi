<?php
declare(strict_types=1);

require_once __DIR__ . '/egm-period-invites.php';
require_once __DIR__ . '/egm-invite-card-routes.php';
require_once __DIR__ . '/egm-invite-card-store.php';
require_once __DIR__ . '/egm-database-runtime.php';
require_once __DIR__ . '/egm-export-filename.php';

const EGM_PERIOD_INVITE_CARD_BACKGROUNDS_KEY = 'invite_card_period_backgrounds';

function egmPeriodInviteCardsBackgroundAsset(string $periodCode): string
{
    return 'period_bg_' . substr(hash('sha256', $periodCode), 0, 20);
}

function egmPeriodInviteCardsBackgroundMap(array $context): array
{
    $stored = egmInstanceReadData(
        $context['pdo'],
        $context['code'],
        EGM_PERIOD_INVITE_CARD_BACKGROUNDS_KEY,
        []
    );
    return is_array($stored) ? $stored : [];
}

function egmPeriodInviteCardsBackground(array $context, string $periodCode, bool $includeImage = true): array
{
    $configuration = egmInstanceReadData($context['pdo'], $context['code'], 'invite_card', null);
    $map = egmPeriodInviteCardsBackgroundMap($context);
    $metadata = isset($map[$periodCode]) && is_array($map[$periodCode]) ? $map[$periodCode] : [];
    $asset = egmPeriodInviteCardsBackgroundAsset($periodCode);
    $hasOverride = $metadata !== [] && egmInviteCardHasAsset($context['pdo'], $context['code'], $asset);
    $imageData = '';
    $source = 'none';

    if ($hasOverride) {
        $source = 'period';
        if ($includeImage) {
            $imageData = egmInviteCardReadAsset($context['pdo'], $context['code'], $asset);
        }
    } elseif (is_array($configuration)) {
        $storedImage = trim((string)($configuration['imageData'] ?? ''));
        if ($storedImage !== '' || egmInviteCardHasAsset($context['pdo'], $context['code'], 'image')) {
            $source = 'shared';
            if ($includeImage) {
                $imageData = $storedImage !== ''
                    ? $storedImage
                    : egmInviteCardReadAsset($context['pdo'], $context['code'], 'image');
            }
        }
    }

    return [
        'has_override' => $hasOverride,
        'source' => $source,
        'imageData' => $imageData,
        'imageName' => $hasOverride
            ? (string)($metadata['imageName'] ?? '')
            : (is_array($configuration) ? (string)($configuration['imageName'] ?? '') : ''),
        'imageWidth' => $hasOverride
            ? (int)($metadata['imageWidth'] ?? 0)
            : (is_array($configuration) ? (int)($configuration['imageWidth'] ?? 0) : 0),
        'imageHeight' => $hasOverride
            ? (int)($metadata['imageHeight'] ?? 0)
            : (is_array($configuration) ? (int)($configuration['imageHeight'] ?? 0) : 0),
        'updatedAt' => $hasOverride ? (string)($metadata['updatedAt'] ?? '') : '',
    ];
}

function egmPeriodInviteCardsSaveBackground(
    array $context,
    string $periodCode,
    $imageData,
    $imageName = ''
): array {
    $configuration = egmInstanceReadData($context['pdo'], $context['code'], 'invite_card', null);
    if (!is_array($configuration)) {
        throw new InvalidArgumentException('ابتدا تنظیمات اصلی کارت دعوت رویداد را ذخیره کنید.');
    }
    $image = egmInviteCardImage($imageData);
    $expectedWidth = (int)($configuration['imageWidth'] ?? 0);
    $expectedHeight = (int)($configuration['imageHeight'] ?? 0);
    if ($expectedWidth < 1 || $expectedHeight < 1) {
        throw new InvalidArgumentException('ابعاد تصویر اصلی کارت دعوت مشخص نیست؛ ابتدا تصویر اصلی را دوباره ذخیره کنید.');
    }
    if ($image['width'] !== $expectedWidth || $image['height'] !== $expectedHeight) {
        throw new InvalidArgumentException(
            "ابعاد تصویر این بازه باید دقیقاً {$expectedWidth}×{$expectedHeight} پیکسل و برابر تصویر اصلی باشد."
        );
    }

    $asset = egmPeriodInviteCardsBackgroundAsset($periodCode);
    egmInviteCardWriteAsset($context['pdo'], $context['code'], $asset, $image['data']);
    $map = egmPeriodInviteCardsBackgroundMap($context);
    $map[$periodCode] = [
        'asset' => $asset,
        'imageName' => egmInviteCardString($imageName !== '' ? $imageName : 'period-background', 255),
        'imageMime' => $image['mime'],
        'imageWidth' => $image['width'],
        'imageHeight' => $image['height'],
        'imageBytes' => $image['bytes'],
        'updatedAt' => gmdate('c'),
    ];
    egmInstanceWriteData(
        $context['pdo'],
        $context['code'],
        EGM_PERIOD_INVITE_CARD_BACKGROUNDS_KEY,
        $map
    );
    return egmPeriodInviteCardsBackground($context, $periodCode, true);
}

function egmPeriodInviteCardsDeleteBackground(array $context, string $periodCode): array
{
    egmInviteCardDeleteAsset(
        $context['pdo'],
        $context['code'],
        egmPeriodInviteCardsBackgroundAsset($periodCode)
    );
    $map = egmPeriodInviteCardsBackgroundMap($context);
    unset($map[$periodCode]);
    egmInstanceWriteData(
        $context['pdo'],
        $context['code'],
        EGM_PERIOD_INVITE_CARD_BACKGROUNDS_KEY,
        $map
    );
    return egmPeriodInviteCardsBackground($context, $periodCode, true);
}

function egmPeriodInviteCardsConfiguration(array $context, string $periodCode): ?array
{
    $configuration = egmInviteCardHydrateAssets(
        $context['pdo'],
        $context['code'],
        egmInstanceReadData($context['pdo'], $context['code'], 'invite_card', null)
    );
    if (!is_array($configuration)) {
        return null;
    }
    $background = egmPeriodInviteCardsBackground($context, $periodCode, true);
    if (trim((string)$background['imageData']) !== '') {
        $configuration['imageData'] = $background['imageData'];
        $configuration['imageName'] = $background['imageName'];
        $configuration['imageWidth'] = $background['imageWidth'];
        $configuration['imageHeight'] = $background['imageHeight'];
    }
    $configuration['periodBackgroundSource'] = $background['source'];
    return $configuration;
}

function egmPeriodInviteCardsNormalizeNationalId($value): string
{
    $nationalId = strtr(trim((string)$value), [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
    $nationalId = preg_replace('/[\s-]+/u', '', $nationalId) ?? '';
    return preg_match('/^[0-9]{10}$/D', $nationalId) === 1 ? $nationalId : '';
}

function egmPeriodInviteCardsNormalizeWorkId($value): string
{
    $workId = strtr(trim((string)$value), [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
    return preg_match('/^[0-9]{4,9}$/D', $workId) === 1 ? $workId : '';
}

function egmPeriodInviteCardsAssertQrIdentifiers(array $context, string $periodCode): void
{
    $periodsTable = (string)$context['tables']['user_periods'];
    $usersTable = (string)$context['tables']['users'];
    $statement = $context['pdo']->prepare(
        "SELECT u.`id`, u.`first_name`, u.`last_name`, u.`national_id`, u.`source_user_id`, u.`work_id` "
        . "FROM `{$periodsTable}` p "
        . "INNER JOIN `{$usersTable}` u ON u.`id` = p.`user_id` WHERE p.`period_code` = :period_code"
    );
    $statement->execute([':period_code' => $periodCode]);
    $invitees = $statement->fetchAll(PDO::FETCH_ASSOC);

    $oeuById = [];
    $oeuByWorkId = [];
    $ambiguousWorkIds = [];
    $oeuRows = $context['pdo']->query(
        'SELECT `id`, `work_id`, `national_id` FROM `' . ORG_USERS_ACTIVE_TABLE . '`'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($oeuRows as $oeuRow) {
        $nationalId = egmPeriodInviteCardsNormalizeNationalId($oeuRow['national_id'] ?? '');
        if ($nationalId === '') {
            continue;
        }
        $oeuById[(int)($oeuRow['id'] ?? 0)] = $nationalId;
        $workId = trim((string)($oeuRow['work_id'] ?? ''));
        if ($workId === '' || isset($ambiguousWorkIds[$workId])) {
            continue;
        }
        if (isset($oeuByWorkId[$workId]) && $oeuByWorkId[$workId] !== $nationalId) {
            unset($oeuByWorkId[$workId]);
            $ambiguousWorkIds[$workId] = true;
            continue;
        }
        $oeuByWorkId[$workId] = $nationalId;
    }

    $update = $context['pdo']->prepare("UPDATE `{$usersTable}` SET `national_id` = :national_id WHERE `id` = :id");
    $invalid = 0;
    $invalidLabels = [];
    foreach ($invitees as $invitee) {
        if (egmPeriodInviteCardsNormalizeNationalId($invitee['national_id'] ?? '') !== '') {
            continue;
        }
        $sourceUserId = (int)($invitee['source_user_id'] ?? 0);
        $workId = trim((string)($invitee['work_id'] ?? ''));
        $recovered = $oeuById[$sourceUserId] ?? ($oeuByWorkId[$workId] ?? '');
        if ($recovered !== '') {
            try {
                $update->execute([':national_id' => $recovered, ':id' => (int)$invitee['id']]);
                continue;
            } catch (PDOException $error) {
                // A conflicting EGM user already owns this National ID; keep
                // this invitee invalid so the conflict can be resolved safely.
            }
        }
        if (egmPeriodInviteCardsNormalizeWorkId($workId) !== '') {
            continue;
        }
        $invalid++;
        $name = trim(preg_replace(
            '/\s+/u',
            ' ',
            trim((string)($invitee['first_name'] ?? '')) . ' ' . trim((string)($invitee['last_name'] ?? ''))
        ) ?? '');
        $invalidLabels[] = $name !== ''
            ? $name . ($workId !== '' ? " (کد پرسنلی {$workId})" : '')
            : ($workId !== '' ? "کد پرسنلی {$workId}" : "کاربر #" . (int)$invitee['id']);
    }
    if ($invalid > 0) {
        $examples = implode('، ', array_slice($invalidLabels, 0, 10));
        throw new InvalidArgumentException(
            "برای {$invalid} دعوت‌شونده نه کد ملی ۱۰ رقمی و نه کد پرسنلی عددی ۴ تا ۹ رقمی ثبت شده است؛ ساخت QR ممکن نیست."
            . ($examples !== '' ? " موارد نیازمند اصلاح: {$examples}" : '')
        );
    }
}

function egmPeriodInviteCardsSummary(array $context, string $periodCode): array
{
    $table = (string)$context['tables']['user_periods'];
    $statement = $context['pdo']->prepare(
        "SELECT COUNT(*) AS `total`, "
        . "SUM(CASE WHEN `invite_card_generated_at` IS NOT NULL AND `invite_card_file` IS NOT NULL THEN 1 ELSE 0 END) AS `generated` "
        . "FROM `{$table}` WHERE `period_code` = :period_code"
    );
    $statement->execute([':period_code' => $periodCode]);
    $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    $total = max(0, (int)($row['total'] ?? 0));
    $generated = max(0, min($total, (int)($row['generated'] ?? 0)));
    $configuration = egmInstanceReadData($context['pdo'], $context['code'], 'invite_card', null);
    $periodBackground = egmPeriodInviteCardsBackground($context, $periodCode, false);
    return [
        'total' => $total,
        'generated' => $generated,
        'pending' => max(0, $total - $generated),
        'percent' => $total > 0 ? (int)floor(($generated * 100) / $total) : 0,
        'configuration_ready' => is_array($configuration)
            && $periodBackground['source'] !== 'none'
            && is_array($configuration['qrRect'] ?? null)
            && is_array($configuration['textRect'] ?? null),
        'background_source' => $periodBackground['source'],
        'has_period_background' => $periodBackground['has_override'],
    ];
}

function egmPeriodInviteCardsPrepare(array $context, string $periodCode, bool $regenerate): array
{
    $configuration = egmPeriodInviteCardsConfiguration($context, $periodCode);
    if (!is_array($configuration) || trim((string)($configuration['imageData'] ?? '')) === '') {
        throw new InvalidArgumentException('ابتدا تنظیمات کارت دعوت EGM و تصویر پس‌زمینه را ذخیره کنید.');
    }
    egmPeriodInviteCardsAssertQrIdentifiers($context, $periodCode);
    $pdo = $context['pdo'];
    $periodsTable = (string)$context['tables']['user_periods'];
    ensureEgmInviteCardRoutesTable($pdo);
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            "SELECT `id`, `user_id`, `invite_card_code`, `invite_card_file`, `invite_card_generated_at` "
            . "FROM `{$periodsTable}` WHERE `period_code` = :period_code ORDER BY `id` FOR UPDATE"
        );
        $statement->execute([':period_code' => $periodCode]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            throw new InvalidArgumentException('هیچ دعوت‌شونده‌ای برای این بازه ثبت نشده است.');
        }
        $updateCode = $pdo->prepare(
            "UPDATE `{$periodsTable}` SET `invite_card_code` = :code WHERE `id` = :id"
        );
        $reset = $pdo->prepare(
            "UPDATE `{$periodsTable}` SET `invite_card_file` = NULL, `invite_card_generated_at` = NULL "
            . "WHERE `period_code` = :period_code"
        );
        if ($regenerate) {
            $reset->execute([':period_code' => $periodCode]);
        }
        foreach ($rows as $row) {
            $inviteCode = trim((string)($row['invite_card_code'] ?? ''));
            if ($inviteCode === '') {
                $inviteCode = egmInviteCardAllocateCode($pdo, $context['code'], $periodCode);
                $updateCode->execute([':code' => $inviteCode, ':id' => (int)$row['id']]);
            }
            $generated = !$regenerate
                && trim((string)($row['invite_card_file'] ?? '')) !== ''
                && trim((string)($row['invite_card_generated_at'] ?? '')) !== '';
            egmInviteCardUpsertRoute(
                $pdo,
                $inviteCode,
                $context['code'],
                $periodCode,
                (int)$row['user_id'],
                $generated ? (string)$row['invite_card_file'] : null,
                $generated ? 'generated' : 'pending'
            );
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    return ['configuration' => $configuration] + egmPeriodInviteCardsSummary($context, $periodCode);
}

function egmPeriodInviteCardsNextBatch(array $context, string $periodCode, int $limit): array
{
    $limit = max(1, min(10, $limit));
    $periodsTable = (string)$context['tables']['user_periods'];
    $usersTable = (string)$context['tables']['users'];
    $statement = $context['pdo']->prepare(
        "SELECT p.`user_id`, p.`invite_card_code`, u.`work_id`, u.`first_name`, u.`last_name`, "
        . "u.`national_id`, u.`phone_number`, u.`deputy`, u.`general_department`, u.`department`, "
        . "u.`gender`, u.`postal_level`, u.`guest_number`, u.`total_score` "
        . "FROM `{$periodsTable}` p INNER JOIN `{$usersTable}` u ON u.`id` = p.`user_id` "
        . "WHERE p.`period_code` = :period_code AND p.`invite_card_code` IS NOT NULL "
        . "AND p.`invite_card_generated_at` IS NULL ORDER BY p.`id` LIMIT {$limit}"
    );
    $statement->execute([':period_code' => $periodCode]);
    $rows = array_map(static function (array $row): array {
        return [
            'id' => (string)($row['user_id'] ?? ''),
            'inviteCode' => (string)($row['invite_card_code'] ?? ''),
            'workId' => (string)($row['work_id'] ?? ''),
            'firstName' => (string)($row['first_name'] ?? ''),
            'lastName' => (string)($row['last_name'] ?? ''),
            'nationalId' => egmPeriodInviteCardsNormalizeNationalId($row['national_id'] ?? ''),
            'phoneNumber' => (string)($row['phone_number'] ?? ''),
            'deputy' => (string)($row['deputy'] ?? ''),
            'generalDepartment' => (string)($row['general_department'] ?? ''),
            'department' => (string)($row['department'] ?? ''),
            'gender' => (string)($row['gender'] ?? ''),
            'postalLevel' => (string)($row['postal_level'] ?? ''),
            'guestNumber' => (string)($row['guest_number'] ?? ''),
            'score' => (string)($row['total_score'] ?? '0'),
        ];
    }, $statement->fetchAll(PDO::FETCH_ASSOC));
    return ['rows' => $rows] + egmPeriodInviteCardsSummary($context, $periodCode);
}

function egmPeriodInviteCardsReplaceGeneratedFile(string $staging, string $destination): void
{
    $backup = '';
    if (is_file($destination)) {
        $backup = $destination . '.previous-' . bin2hex(random_bytes(6));
        if (!@rename($destination, $backup)) {
            @unlink($staging);
            throw new RuntimeException('نسخه قبلی کارت دعوت برای جایگزینی آماده نشد.');
        }
    }
    if (!@rename($staging, $destination)) {
        @unlink($staging);
        if ($backup !== '') {
            @rename($backup, $destination);
        }
        throw new RuntimeException('ثبت نهایی فایل کارت دعوت ناموفق بود.');
    }
    if ($backup !== '') {
        @unlink($backup);
    }
}

function egmPeriodInviteCardsStoreUpload(array $context, string $periodCode, string $inviteCode): array
{
    if (preg_match('/^[A-Za-z0-9]+$/D', $inviteCode) !== 1 || strlen($inviteCode) > 191) {
        throw new InvalidArgumentException('کد کارت دعوت نامعتبر است.');
    }
    $upload = $_FILES['card'] ?? null;
    if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('فایل JPG کارت دعوت دریافت نشد.');
    }
    $size = (int)($upload['size'] ?? 0);
    $temporary = (string)($upload['tmp_name'] ?? '');
    if ($size < 1 || $size > 20 * 1024 * 1024 || !is_uploaded_file($temporary)) {
        throw new InvalidArgumentException('حجم یا فایل کارت دعوت نامعتبر است.');
    }
    $info = @getimagesize($temporary);
    $width = (int)($info[0] ?? 0);
    $height = (int)($info[1] ?? 0);
    if (!is_array($info) || strtolower((string)($info['mime'] ?? '')) !== 'image/jpeg'
        || $width < 1 || $height < 1 || $width * $height > 40000000) {
        throw new InvalidArgumentException('خروجی کارت باید یک تصویر JPG معتبر با حداکثر ۴۰ میلیون پیکسل باشد.');
    }
    $periodsTable = (string)$context['tables']['user_periods'];
    $lookup = $context['pdo']->prepare(
        "SELECT `id`, `user_id` FROM `{$periodsTable}` "
        . "WHERE `period_code` = :period_code AND `invite_card_code` = :invite_code LIMIT 1"
    );
    $lookup->execute([':period_code' => $periodCode, ':invite_code' => $inviteCode]);
    $row = $lookup->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new InvalidArgumentException('این کد به دعوت‌شونده بازه تعلق ندارد.');
    }
    $directory = $context['mission_dir'] . DIRECTORY_SEPARATOR . 'InviteCards';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('پوشه ذخیره کارت‌های دعوت ساخته نشد.');
    }
    $destination = $directory . DIRECTORY_SEPARATOR . $inviteCode . '.jpg';
    $staging = $destination . '.upload-' . bin2hex(random_bytes(6));
    if (!move_uploaded_file($temporary, $staging)) {
        throw new RuntimeException('انتقال فایل کارت دعوت ناموفق بود.');
    }
    egmPeriodInviteCardsReplaceGeneratedFile($staging, $destination);
    if (!is_file($destination) || (int)filesize($destination) < 1) {
        throw new RuntimeException('Failed to store the invite card file.');
    }
    // Invite-card images intentionally live on disk. Remove a legacy database
    // blob for the same path after the physical replacement succeeds.
    egmDatabaseRuntimeDelete($destination);
    $webPath = trim(str_replace('\\', '/', (string)$context['registry']['directory']), '/')
        . '/InviteCards/' . $inviteCode . '.jpg';
    $update = $context['pdo']->prepare(
        "UPDATE `{$periodsTable}` SET `invite_card_file` = :file, `invite_card_generated_at` = CURRENT_TIMESTAMP "
        . "WHERE `id` = :id"
    );
    $update->execute([':file' => $webPath, ':id' => (int)$row['id']]);
    egmInviteCardUpsertRoute(
        $context['pdo'], $inviteCode, $context['code'], $periodCode, (int)$row['user_id'], $webPath, 'generated'
    );
    return ['invite_code' => $inviteCode, 'image_path' => $webPath, 'width' => $width, 'height' => $height]
        + egmPeriodInviteCardsSummary($context, $periodCode);
}

/** @return array<int,array<string,string>> */
function egmPeriodInviteCardsGeneratedExportRows(array $context, string $periodCode): array
{
    egmInstanceEnrichUsersFromOeu($context['pdo'], (string)$context['code']);
    $periodsTable = (string)$context['tables']['user_periods'];
    $usersTable = (string)$context['tables']['users'];
    $statement = $context['pdo']->prepare(
        "SELECT u.`first_name`, u.`last_name`, u.`national_id`, u.`work_id`, u.`phone_number`, p.`invite_card_code` "
        . "FROM `{$periodsTable}` p INNER JOIN `{$usersTable}` u ON u.`id` = p.`user_id` "
        . "WHERE p.`period_code` = :period_code AND p.`invite_card_code` IS NOT NULL "
        . "AND p.`invite_card_file` IS NOT NULL AND p.`invite_card_generated_at` IS NOT NULL "
        . "ORDER BY p.`id`"
    );
    $statement->execute([':period_code' => $periodCode]);
    return array_map(static fn(array $row): array => [
        'first_name' => (string)($row['first_name'] ?? ''),
        'last_name' => (string)($row['last_name'] ?? ''),
        'national_id' => egmPeriodInviteCardsNormalizeNationalId($row['national_id'] ?? ''),
        'work_id' => (string)($row['work_id'] ?? ''),
        'phone_number' => (string)($row['phone_number'] ?? ''),
        'invite_card_code' => (string)($row['invite_card_code'] ?? ''),
    ], $statement->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

function egmPeriodInviteCardsPublicBaseUrl(array $context): string
{
    $https = strtolower(trim((string)($_SERVER['HTTPS'] ?? '')));
    $scheme = $https !== '' && $https !== 'off' && $https !== '0' ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));
    if (preg_match('/^[A-Za-z0-9.\-\[\]:]+$/D', $host) !== 1) {
        $host = 'localhost';
    }

    $scriptPath = str_replace('\\', '/', rawurldecode((string)($_SERVER['SCRIPT_NAME'] ?? '')));
    $missionPath = '/' . trim(str_replace('\\', '/', (string)($context['registry']['directory'] ?? '')), '/');
    $suffix = $missionPath . '/period_invite_cards.php';
    $basePath = str_ends_with($scriptPath, $suffix)
        ? substr($scriptPath, 0, -strlen($suffix))
        : '';
    $encodedPath = implode('/', array_map('rawurlencode', array_values(array_filter(
        explode('/', trim($basePath, '/')),
        static fn(string $part): bool => $part !== ''
    ))));
    return $scheme . '://' . $host . ($encodedPath !== '' ? '/' . $encodedPath : '');
}

/** @return array{rows:array<int,array<string,string>>,filename:string,count:int,period_date:string} */
function egmPeriodInviteCardsBuildExportData(
    array $rows,
    string $egmCode,
    string $periodCode,
    string $publicBaseUrl,
    ?string $periodDate = null
): array {
    $resolvedPeriodDate = egmExportNormalizeGregorianDate($periodDate);
    if ($resolvedPeriodDate === null) {
        throw new InvalidArgumentException('تاریخ این بازه مشخص نیست؛ خروجی با تاریخ امروز نام‌گذاری نمی‌شود.');
    }
    $exportRows = [];
    $base = rtrim($publicBaseUrl, '/');
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $inviteCode = trim((string)($row['invite_card_code'] ?? ''));
        if ($inviteCode === '') {
            continue;
        }
        $fullName = trim(preg_replace('/\s+/u', ' ', trim((string)($row['first_name'] ?? '')) . ' ' . trim((string)($row['last_name'] ?? ''))) ?? '');
        $exportRows[] = [
            'full_name' => $fullName,
            'national_id' => (string)($row['national_id'] ?? ''),
            'work_id' => (string)($row['work_id'] ?? ''),
            'phone_number' => (string)($row['phone_number'] ?? ''),
            'invite_url' => $base . '/Invited/' . rawurlencode($inviteCode),
        ];
    }

    return [
        'rows' => $exportRows,
        'filename' => egmExportPeriodDatedFilename('لینک کارت‌های دعوت', $resolvedPeriodDate),
        'count' => count($exportRows),
        'period_date' => $resolvedPeriodDate,
    ];
}

function handleEgmPeriodInviteCardsRequest(string $missionDir): void
{
    ob_start();
    try {
        $context = egmPeriodInvitesContext($missionDir);
        if ($context['code'] === '' || !is_array($context['tables']) || !is_array($context['registry'])) {
            egmPeriodInvitesJson(['status' => 'error', 'message' => 'این EGM هنوز در پایگاه داده ثبت نشده است.'], 409);
        }
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $input = $_REQUEST;
        if ($method === 'POST' && str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
            $decoded = json_decode((string)file_get_contents('php://input'), true);
            if (is_array($decoded)) {
                $input = $decoded;
            }
        }
        $action = strtolower(trim((string)($input['action'] ?? 'status')));
        if ($method === 'POST') {
            $csrf = egmSecurityReadCsrfFromRequest($input, 'csrf');
            if (!egmSecurityIsValidCsrfToken($csrf)) {
                egmPeriodInvitesJson(['status' => 'error', 'message' => 'توکن امنیتی نامعتبر است.'], 403);
            }
        }
        $periodCode = egmPeriodInvitesValidatePeriod($context, (string)($input['period_code'] ?? ''));
        if ($action === 'status' && $method === 'GET') {
            egmPeriodInvitesJson(['status' => 'ok'] + egmPeriodInviteCardsSummary($context, $periodCode));
        }
        if ($action === 'period_background' && $method === 'GET') {
            egmPeriodInvitesJson([
                'status' => 'ok',
                'background' => egmPeriodInviteCardsBackground($context, $periodCode, true),
            ]);
        }
        if ($action === 'export_data' && $method === 'GET') {
            $periodDate = null;
            foreach (egmPeriodInvitesPeriods($context) as $period) {
                if (!is_array($period)) continue;
                if (trim((string)($period['tagCode'] ?? ($period['code'] ?? ''))) !== $periodCode) continue;
                $periodDate = egmExportPeriodDate($period);
                break;
            }
            if ($periodDate === null) {
                $periodDate = egmExportNormalizeGregorianDate((string)($input['period_date'] ?? ''));
            }
            $export = egmPeriodInviteCardsBuildExportData(
                egmPeriodInviteCardsGeneratedExportRows($context, $periodCode),
                (string)$context['code'],
                $periodCode,
                egmPeriodInviteCardsPublicBaseUrl($context),
                $periodDate
            );
            if ($export['count'] < 1) {
                throw new InvalidArgumentException('هنوز هیچ کارت دعوتی برای این بازه ساخته نشده است.');
            }
            egmPeriodInvitesJson(['status' => 'ok'] + $export);
        }
        if ($action === 'prepare' && $method === 'POST') {
            egmPeriodInvitesJson(['status' => 'ok'] + egmPeriodInviteCardsPrepare(
                $context, $periodCode, filter_var($input['regenerate'] ?? false, FILTER_VALIDATE_BOOLEAN)
            ));
        }
        if ($action === 'save_period_background' && $method === 'POST') {
            $imageData = $input['imageData'] ?? null;
            if (isset($_FILES['background']) && is_array($_FILES['background'])) {
                $upload = $_FILES['background'];
                $uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($uploadError !== UPLOAD_ERR_OK) {
                    throw new InvalidArgumentException('بارگذاری تصویر پس‌زمینه این بازه ناموفق بود.');
                }
                $temporaryPath = (string)($upload['tmp_name'] ?? '');
                $binary = $temporaryPath !== '' ? @file_get_contents($temporaryPath) : false;
                if (!is_string($binary) || $binary === '') {
                    throw new InvalidArgumentException('تصویر بارگذاری‌شده قابل خواندن نیست.');
                }
                $info = @getimagesizefromstring($binary);
                $mime = is_array($info) ? strtolower((string)($info['mime'] ?? '')) : '';
                if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                    throw new InvalidArgumentException('فرمت تصویر باید PNG، JPG یا WebP باشد.');
                }
                $imageData = 'data:' . $mime . ';base64,' . base64_encode($binary);
                if (trim((string)($input['imageName'] ?? '')) === '') {
                    $input['imageName'] = (string)($upload['name'] ?? 'period-background');
                }
            }
            egmPeriodInvitesJson([
                'status' => 'ok',
                'background' => egmPeriodInviteCardsSaveBackground(
                    $context,
                    $periodCode,
                    $imageData,
                    $input['imageName'] ?? ''
                ),
            ]);
        }
        if ($action === 'remove_period_background' && $method === 'POST') {
            egmPeriodInvitesJson([
                'status' => 'ok',
                'background' => egmPeriodInviteCardsDeleteBackground($context, $periodCode),
            ]);
        }
        if ($action === 'next_batch' && $method === 'GET') {
            egmPeriodInvitesJson(['status' => 'ok'] + egmPeriodInviteCardsNextBatch(
                $context, $periodCode, (int)($input['limit'] ?? 3)
            ));
        }
        if ($action === 'upload' && $method === 'POST') {
            egmPeriodInvitesJson(['status' => 'ok'] + egmPeriodInviteCardsStoreUpload(
                $context, $periodCode, trim((string)($input['invite_code'] ?? ''))
            ));
        }
        egmPeriodInvitesJson(['status' => 'error', 'message' => 'عملیات پشتیبانی نمی‌شود.'], 400);
    } catch (InvalidArgumentException $error) {
        egmPeriodInvitesJson(['status' => 'error', 'message' => $error->getMessage()], 422);
    } catch (Throwable $error) {
        error_log('EGM period invite cards failed: ' . $error->getMessage());
        egmPeriodInvitesJson(['status' => 'error', 'message' => 'ساخت کارت‌های دعوت ناموفق بود.'], 500);
    }
}
