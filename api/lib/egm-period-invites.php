<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/egm-instance-storage.php';
require_once dirname(__DIR__, 2) . '/modules/minor/Organizational Event Userbase/org_users_store.php';

/** @return array{root:string,mission_dir:string,pdo:PDO,registry:?array,tables:?array,code:string} */
function egmPeriodInvitesContext(string $missionDir): array
{
    $resolvedMission = realpath($missionDir);
    if (!is_string($resolvedMission)) {
        throw new RuntimeException('مسیر EGM معتبر نیست.');
    }
    $root = $resolvedMission;
    while (!is_file($root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'config.php')) {
        $parent = dirname($root);
        if ($parent === $root) {
            throw new RuntimeException('تنظیمات پایگاه داده پیدا نشد.');
        }
        $root = $parent;
    }
    $pdo = connectDatabase(loadConfig($root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'config.php'));
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('اتصال به پایگاه داده برقرار نشد.');
    }
    orgUsersEnsureTable($pdo);
    $registry = egmInstanceRegistryForDirectory($pdo, $resolvedMission);
    $tables = null;
    $code = '';
    if (is_array($registry)) {
        $code = normalizeEgmInstanceCode($registry['code'] ?? '');
        $tables = ensureEgmInstanceTables($pdo, $code);
        egmInstanceEnrichUsersFromOeu($pdo, $code);
    }
    return [
        'root' => $root,
        'mission_dir' => $resolvedMission,
        'pdo' => $pdo,
        'registry' => $registry,
        'tables' => $tables,
        'code' => $code,
    ];
}

function egmPeriodInvitesLocalStatePath(array $context): string
{
    return $context['mission_dir'] . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . 'period-invites.json';
}

/** @return array{source:string,periods:array<string,array<int,array<string,mixed>>>} */
function egmPeriodInvitesReadLocalState(array $context): array
{
    $path = egmPeriodInvitesLocalStatePath($context);
    $raw = is_file($path) ? file_get_contents($path) : false;
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return [
        'source' => in_array((string)($decoded['source'] ?? ''), ['oeu', 'custom'], true)
            ? (string)$decoded['source']
            : 'custom',
        'periods' => is_array($decoded['periods'] ?? null) ? $decoded['periods'] : [],
    ];
}

function egmPeriodInvitesWriteLocalState(array $context, array $state): void
{
    $path = egmPeriodInvitesLocalStatePath($context);
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) {
        throw new RuntimeException('فضای ذخیره‌سازی دعوت‌های بازه ساخته نشد.');
    }
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('ذخیره دعوت‌های بازه ناموفق بود.');
    }
}

function egmPeriodInvitesGetSource(array $context): string
{
    if ($context['code'] !== '') {
        $stored = egmInstanceReadData($context['pdo'], $context['code'], 'invitee_source', null);
        $source = is_array($stored) ? strtolower(trim((string)($stored['source'] ?? ''))) : '';
        if (in_array($source, ['oeu', 'custom'], true)) {
            return $source;
        }
        $usersTable = (string)$context['tables']['users'];
        $hasUsers = (int)$context['pdo']->query("SELECT COUNT(*) FROM `{$usersTable}` WHERE `is_active` = 1")->fetchColumn() > 0;
        return $hasUsers ? 'custom' : 'oeu';
    }
    return egmPeriodInvitesReadLocalState($context)['source'];
}

function egmPeriodInvitesSetSource(array $context, string $source): void
{
    $source = strtolower(trim($source));
    if (!in_array($source, ['oeu', 'custom'], true)) {
        throw new InvalidArgumentException('منبع دعوت‌شدگان نامعتبر است.');
    }
    if ($context['code'] !== '') {
        egmInstanceWriteData($context['pdo'], $context['code'], 'invitee_source', [
            'source' => $source,
            'updatedAt' => gmdate('c'),
        ]);
        return;
    }
    $state = egmPeriodInvitesReadLocalState($context);
    $state['source'] = $source;
    egmPeriodInvitesWriteLocalState($context, $state);
}

/** @return array<int,array<string,mixed>> */
function egmPeriodInvitesPeriods(array $context): array
{
    if ($context['code'] !== '') {
        return egmInstanceReadPeriods($context['pdo'], $context['code']);
    }
    return egmInstanceReadPeriodsFile(
        $context['mission_dir'] . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . 'tasks.js'
    );
}

function egmPeriodInvitesValidatePeriod(array $context, string $periodCode): string
{
    $periodCode = tctPeriodInviteClean($periodCode, 128);
    foreach (egmPeriodInvitesPeriods($context) as $period) {
        $code = trim((string)($period['tagCode'] ?? ($period['code'] ?? '')));
        if ($code !== '' && hash_equals($code, $periodCode)) {
            return $code;
        }
    }
    throw new InvalidArgumentException('بازه انتخاب‌شده پیدا نشد.');
}

function tctPeriodInviteClean($value, int $maxLength = 191): string
{
    $text = trim(is_scalar($value) ? (string)$value : '');
    return function_exists('mb_substr') ? mb_substr($text, 0, $maxLength, 'UTF-8') : substr($text, 0, $maxLength);
}

function egmPeriodInvitesIdentityKey(array $row): string
{
    $nationalId = orgUsersNormalizeNationalId($row['national_id'] ?? '');
    if ($nationalId !== '') {
        return 'n:' . $nationalId;
    }
    $workId = strtolower(tctPeriodInviteClean($row['work_id'] ?? '', 128));
    return $workId !== '' ? 'w:' . $workId : '';
}

/** @return array<string,mixed> */
function egmPeriodInvitesNormalizeCandidate(array $row, string $candidateId, string $source): array
{
    return [
        'candidate_id' => $candidateId,
        'source' => $source,
        'work_id' => tctPeriodInviteClean($row['work_id'] ?? '', 128),
        'first_name' => tctPeriodInviteClean($row['first_name'] ?? '', 191),
        'last_name' => tctPeriodInviteClean($row['last_name'] ?? '', 191),
        'national_id' => orgUsersNormalizeNationalId($row['national_id'] ?? ''),
        'phone_number' => tctPeriodInviteClean($row['phone_number'] ?? '', 32),
        'deputy' => tctPeriodInviteClean($row['deputy'] ?? '', 191),
        'general_department' => tctPeriodInviteClean($row['general_department'] ?? '', 191),
        'department' => tctPeriodInviteClean($row['department'] ?? '', 191),
        'gender' => tctPeriodInviteClean($row['gender'] ?? '', 32),
        'postal_level' => tctPeriodInviteClean($row['postal_level'] ?? '', 64),
        'guest_number' => tctPeriodInviteClean($row['guest_number'] ?? '', 32),
        'source_row' => max(0, (int)($row['source_row'] ?? 0)),
    ];
}

/** @return array<string,mixed> */
function egmPeriodInvitesNormalizeExcelRow(array $row, int $fallbackRow): array
{
    $normalized = egmPeriodInvitesNormalizeCandidate($row, '', 'custom');
    unset($normalized['candidate_id'], $normalized['source']);
    $normalized['excel_id'] = tctPeriodInviteClean($row['excel_id'] ?? ('x:' . $fallbackRow), 128);
    $normalized['source_row'] = max(1, (int)($row['source_row'] ?? $fallbackRow));
    $normalized['can_invite'] = egmPeriodInvitesIdentityKey($normalized) !== '';
    $rawData = [];
    if (is_array($row['raw_data'] ?? null)) {
        foreach (array_slice($row['raw_data'], 0, 50, true) as $label => $value) {
            $cleanLabel = tctPeriodInviteClean($label, 128);
            if ($cleanLabel === '') continue;
            $rawData[$cleanLabel] = tctPeriodInviteClean($value, 500);
        }
    }
    $normalized['raw_data'] = $rawData;
    return $normalized;
}

/** @return array<int,array<string,mixed>> */
function egmPeriodInvitesReadCustomCsv(array $context): array
{
    $eventDir = $context['mission_dir'] . DIRECTORY_SEPARATOR . 'EGM Event';
    $rows = egmInstanceReadCsvRows($eventDir . DIRECTORY_SEPARATOR . 'Invitees mapped.csv');
    if (!$rows) {
        return [];
    }
    $header = $rows[0];
    $mapping = egmInstanceReadMapping($eventDir . DIRECTORY_SEPARATOR . 'EGM Mapped.json');
    $indexes = [
        'work_id' => egmInstanceResolveColumn($header, $mapping, 'workId', ['Work ID', 'work id', 'workid', 'username']),
        'first_name' => egmInstanceResolveColumn($header, $mapping, 'firstName', ['First Name', 'first name', 'name']),
        'last_name' => egmInstanceResolveColumn($header, $mapping, 'lastName', ['Last Name', 'last name', 'family', 'surname']),
        'national_id' => egmInstanceResolveColumn($header, $mapping, 'nationalId', ['National ID', 'national id']),
        'phone_number' => egmInstanceResolveColumn($header, $mapping, 'phoneNumber', ['Phone Number', 'phone number', 'phone', 'mobile']),
    ];
    $result = [];
    foreach (array_slice($rows, 1) as $offset => $row) {
        $item = ['source_row' => $offset + 2];
        foreach ($indexes as $field => $index) {
            $item[$field] = $index >= 0 ? (string)($row[$index] ?? '') : '';
        }
        if (trim((string)$item['work_id']) === '' && trim((string)$item['national_id']) === '') {
            continue;
        }
        $result[] = egmPeriodInvitesNormalizeCandidate($item, 'c:' . ($offset + 2), 'custom');
    }
    return $result;
}

/** @return array<int,array<string,mixed>> */
function egmPeriodInvitesCandidateRows(array $context, string $source): array
{
    if ($source === 'oeu') {
        $rows = $context['pdo']->query(
            'SELECT `id`, `work_id`, `first_name`, `last_name`, `national_id`, `phone_number`, `deputy`, '
            . '`general_department`, `department`, `gender`, `postal_level`, `source_row` '
            . 'FROM `organizational_event_users` ORDER BY `source_row`, `id`'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(
            static fn(array $row): array => egmPeriodInvitesNormalizeCandidate($row, 'o:' . (int)$row['id'], 'oeu'),
            $rows
        );
    }
    if ($context['code'] === '') {
        return egmPeriodInvitesReadCustomCsv($context);
    }
    $usersTable = (string)$context['tables']['users'];
    $rows = $context['pdo']->query(
        "SELECT `id`, `work_id`, `first_name`, `last_name`, `national_id`, `phone_number`, `deputy`, "
        . "`general_department`, `department`, `gender`, `postal_level`, `guest_number`, `source_row` FROM `{$usersTable}` "
        . "WHERE `is_active` = 1 AND `source_type` IN ('custom','period_excel') ORDER BY `source_row`, `id`"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_map(
        static fn(array $row): array => egmPeriodInvitesNormalizeCandidate($row, 'c:' . (int)$row['id'], 'custom'),
        $rows
    );
}

/** @return array<string,bool> */
function egmPeriodInvitesInvitedIdentitySet(array $context, string $periodCode): array
{
    $set = [];
    if ($context['code'] !== '') {
        $usersTable = (string)$context['tables']['users'];
        $periodsTable = (string)$context['tables']['user_periods'];
        $statement = $context['pdo']->prepare(
            "SELECT u.`national_id`, u.`work_id` FROM `{$periodsTable}` p JOIN `{$usersTable}` u ON u.`id` = p.`user_id` WHERE p.`period_code` = :period_code"
        );
        $statement->execute([':period_code' => $periodCode]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = egmPeriodInvitesIdentityKey($row);
            if ($key !== '') $set[$key] = true;
        }
        return $set;
    }
    $state = egmPeriodInvitesReadLocalState($context);
    foreach ($state['periods'][$periodCode] ?? [] as $row) {
        if (is_array($row)) {
            $key = egmPeriodInvitesIdentityKey($row);
            if ($key !== '') $set[$key] = true;
        }
    }
    return $set;
}

/** @return array<string,array<int,string>> */
function egmPeriodInvitesFilterOptions(array $rows): array
{
    $fields = ['deputy', 'general_department', 'department', 'gender', 'postal_level'];
    $sets = array_fill_keys($fields, []);
    foreach ($rows as $row) {
        foreach ($fields as $field) {
            $value = trim((string)($row[$field] ?? ''));
            if ($value !== '') $sets[$field][$value] = true;
        }
    }
    $result = [];
    foreach ($sets as $field => $values) {
        $list = array_keys($values);
        natcasesort($list);
        $result[$field] = array_values($list);
    }
    return $result;
}

/** @return array<int,array<string,mixed>> */
function egmPeriodInvitesApplyFilters(array $rows, array $filters): array
{
    $q = strtolower(trim((string)($filters['q'] ?? '')));
    $dropdowns = ['deputy', 'general_department', 'department', 'gender', 'postal_level'];
    return array_values(array_filter($rows, static function (array $row) use ($q, $dropdowns, $filters): bool {
        if ($q !== '') {
            $haystack = strtolower(implode(' ', array_map('strval', [
                $row['first_name'] ?? '', $row['last_name'] ?? '', $row['work_id'] ?? '',
                $row['national_id'] ?? '', $row['department'] ?? '', $row['general_department'] ?? '',
                $row['deputy'] ?? '', $row['gender'] ?? '', $row['postal_level'] ?? '',
            ])));
            if (strpos($haystack, $q) === false) return false;
        }
        foreach ($dropdowns as $field) {
            $needle = trim((string)($filters[$field] ?? ''));
            if ($needle !== '' && strcasecmp((string)($row[$field] ?? ''), $needle) !== 0) return false;
        }
        return true;
    }));
}

/** @return array<string,mixed> */
function egmPeriodInvitesListCandidates(array $context, string $periodCode, array $filters, int $page, int $pageSize): array
{
    $source = egmPeriodInvitesGetSource($context);
    $rows = egmPeriodInvitesApplyFilters(egmPeriodInvitesCandidateRows($context, $source), $filters);
    $invited = egmPeriodInvitesInvitedIdentitySet($context, $periodCode);
    foreach ($rows as &$row) {
        $row['invited'] = isset($invited[egmPeriodInvitesIdentityKey($row)]);
    }
    unset($row);
    $total = count($rows);
    $pageSize = max(10, min(100, $pageSize));
    $pages = max(1, (int)ceil($total / $pageSize));
    $page = max(1, min($pages, $page));
    return [
        'source' => $source,
        'rows' => array_slice($rows, ($page - 1) * $pageSize, $pageSize),
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'pages' => $pages,
    ];
}

/** @return array<int,array<string,mixed>> */
function egmPeriodInvitesRowsByCandidateIds(array $rows, array $candidateIds): array
{
    $wanted = [];
    foreach ($candidateIds as $candidateId) {
        $candidateId = trim((string)$candidateId);
        if ($candidateId !== '') $wanted[$candidateId] = true;
    }
    return array_values(array_filter($rows, static fn(array $row): bool => isset($wanted[(string)($row['candidate_id'] ?? '')])));
}

function egmPeriodInvitesInsertRegistered(array $context, string $periodCode, string $source, array $candidateIds, string $actor): int
{
    $pdo = $context['pdo'];
    $usersTable = (string)$context['tables']['users'];
    $periodsTable = (string)$context['tables']['user_periods'];
    $candidateRows = egmPeriodInvitesRowsByCandidateIds(egmPeriodInvitesCandidateRows($context, $source), $candidateIds);
    if (!$candidateRows) return 0;
    $pdo->beginTransaction();
    try {
        $userIds = [];
        if ($source === 'oeu') {
            $upsert = $pdo->prepare(<<<SQL
INSERT INTO `{$usersTable}`
(`work_id`,`first_name`,`last_name`,`national_id`,`phone_number`,`deputy`,`general_department`,`department`,`gender`,`postal_level`,`source_row`,`imported_at`,`is_active`,`source_type`,`source_user_id`)
VALUES
(:work_id,:first_name,:last_name,:national_id,:phone_number,:deputy,:general_department,:department,:gender,:postal_level,:source_row,NOW(),1,'oeu',:source_user_id)
ON DUPLICATE KEY UPDATE
`work_id`=VALUES(`work_id`),`first_name`=VALUES(`first_name`),`last_name`=VALUES(`last_name`),`phone_number`=VALUES(`phone_number`),
`deputy`=VALUES(`deputy`),`general_department`=VALUES(`general_department`),`department`=VALUES(`department`),`gender`=VALUES(`gender`),
`postal_level`=VALUES(`postal_level`),`source_row`=VALUES(`source_row`),`is_active`=1,`source_user_id`=VALUES(`source_user_id`)
SQL);
            $find = $pdo->prepare("SELECT `id` FROM `{$usersTable}` WHERE `national_id` = :national_id LIMIT 1");
            foreach ($candidateRows as $row) {
                $sourceId = (int)substr((string)$row['candidate_id'], 2);
                $upsert->execute([
                    ':work_id' => $row['work_id'], ':first_name' => $row['first_name'], ':last_name' => $row['last_name'],
                    ':national_id' => $row['national_id'], ':phone_number' => $row['phone_number'], ':deputy' => $row['deputy'],
                    ':general_department' => $row['general_department'], ':department' => $row['department'], ':gender' => $row['gender'],
                    ':postal_level' => $row['postal_level'], ':source_row' => $row['source_row'], ':source_user_id' => $sourceId,
                ]);
                $find->execute([':national_id' => $row['national_id']]);
                $userId = (int)$find->fetchColumn();
                if ($userId > 0) $userIds[] = $userId;
            }
        } else {
            foreach ($candidateRows as $row) {
                $userId = (int)substr((string)$row['candidate_id'], 2);
                if ($userId > 0) $userIds[] = $userId;
            }
        }
        $userIds = array_values(array_unique($userIds));
        egmInstanceAssignGuestNumbers($pdo, (string)$context['code'], $userIds);
        $insert = $pdo->prepare(<<<SQL
INSERT INTO `{$periodsTable}` (`user_id`,`period_code`,`status`,`invitation_source`,`invited_by`,`invited_at`)
VALUES (:user_id,:period_code,'invited',:source,:invited_by,NOW())
ON DUPLICATE KEY UPDATE `invitation_source`=VALUES(`invitation_source`),`invited_by`=VALUES(`invited_by`),`invited_at`=COALESCE(`invited_at`,VALUES(`invited_at`))
SQL);
        $count = 0;
        foreach ($userIds as $userId) {
            $insert->execute([':user_id' => $userId, ':period_code' => $periodCode, ':source' => $source, ':invited_by' => $actor]);
            $count += 1;
        }
        $pdo->commit();
        return $count;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** @return array{added_users:int,invited:int,skipped:int} */
function egmPeriodInvitesInsertUnmatchedRegistered(array $context, string $periodCode, array $inputRows, string $actor): array
{
    if ($context['code'] === '') {
        throw new InvalidArgumentException('افزودن کاربران بدون تطبیق فقط داخل یک EGM ثبت‌شده امکان‌پذیر است.');
    }
    if (count($inputRows) > 10000) {
        throw new InvalidArgumentException('در هر بار حداکثر ۱۰٬۰۰۰ کاربر قابل افزودن است.');
    }
    $pdo = $context['pdo'];
    $usersTable = (string)$context['tables']['users'];
    $periodsTable = (string)$context['tables']['user_periods'];
    $rows = [];
    foreach ($inputRows as $offset => $inputRow) {
        if (!is_array($inputRow)) continue;
        $row = egmPeriodInvitesNormalizeExcelRow($inputRow, $offset + 2);
        $identity = egmPeriodInvitesIdentityKey($row);
        if ($identity !== '') $rows[$identity] = $row;
    }
    if (!$rows) return ['added_users' => 0, 'invited' => 0, 'skipped' => count($inputRows)];

    $findNational = $pdo->prepare("SELECT `id` FROM `{$usersTable}` WHERE `national_id` = :national_id LIMIT 1");
    $findWork = $pdo->prepare("SELECT `id` FROM `{$usersTable}` WHERE `work_id` = :work_id ORDER BY `is_active` DESC, `id` DESC LIMIT 1");
    $update = $pdo->prepare(<<<SQL
UPDATE `{$usersTable}` SET
`work_id`=COALESCE(NULLIF(:work_id,''),`work_id`),`first_name`=COALESCE(NULLIF(:first_name,''),`first_name`),
`last_name`=COALESCE(NULLIF(:last_name,''),`last_name`),`phone_number`=COALESCE(NULLIF(:phone_number,''),`phone_number`),
`deputy`=COALESCE(NULLIF(:deputy,''),`deputy`),`general_department`=COALESCE(NULLIF(:general_department,''),`general_department`),
`department`=COALESCE(NULLIF(:department,''),`department`),`gender`=COALESCE(NULLIF(:gender,''),`gender`),
`postal_level`=COALESCE(NULLIF(:postal_level,''),`postal_level`),`source_row`=:source_row,`imported_at`=NOW(),
`state_json`=:state_json,`source_type`='period_excel',`source_user_id`=NULL,`is_active`=1
WHERE `id`=:id
SQL);
    $insertUser = $pdo->prepare(<<<SQL
INSERT INTO `{$usersTable}`
(`work_id`,`first_name`,`last_name`,`national_id`,`phone_number`,`deputy`,`general_department`,`department`,`gender`,`postal_level`,`source_row`,`imported_at`,`is_active`,`state_json`,`source_type`,`source_user_id`)
VALUES (:work_id,:first_name,:last_name,:national_id,:phone_number,:deputy,:general_department,:department,:gender,:postal_level,:source_row,NOW(),1,:state_json,'period_excel',NULL)
SQL);
    $insertPeriod = $pdo->prepare(<<<SQL
INSERT IGNORE INTO `{$periodsTable}` (`user_id`,`period_code`,`status`,`invitation_source`,`invited_by`,`invited_at`)
VALUES (:user_id,:period_code,'invited','period_excel',:invited_by,NOW())
SQL);

    $addedUsers = 0;
    $invited = 0;
    $invitedUserIds = [];
    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            $nationalId = orgUsersNormalizeNationalId($row['national_id'] ?? '');
            $workId = tctPeriodInviteClean($row['work_id'] ?? '', 128);
            $userId = 0;
            if ($nationalId !== '') {
                $findNational->execute([':national_id' => $nationalId]);
                $userId = (int)$findNational->fetchColumn();
            } elseif ($workId !== '') {
                $findWork->execute([':work_id' => $workId]);
                $userId = (int)$findWork->fetchColumn();
            }
            $stateJson = json_encode([
                'period_excel_import' => true,
                'excel_row' => (int)$row['source_row'],
                'excel_data' => $row['raw_data'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $params = [
                ':work_id' => $workId,
                ':first_name' => $row['first_name'],
                ':last_name' => $row['last_name'],
                ':phone_number' => $row['phone_number'],
                ':deputy' => $row['deputy'],
                ':general_department' => $row['general_department'],
                ':department' => $row['department'],
                ':gender' => $row['gender'],
                ':postal_level' => $row['postal_level'],
                ':source_row' => (int)$row['source_row'],
                ':state_json' => is_string($stateJson) ? $stateJson : '{}',
            ];
            if ($userId > 0) {
                $update->execute($params + [':id' => $userId]);
            } else {
                $insertParams = $params + [':national_id' => $nationalId !== '' ? $nationalId : null];
                $insertUser->execute($insertParams);
                $userId = (int)$pdo->lastInsertId();
                $addedUsers++;
            }
            if ($userId > 0) {
                $invitedUserIds[] = $userId;
                $insertPeriod->execute([':user_id' => $userId, ':period_code' => $periodCode, ':invited_by' => $actor]);
                $invited += $insertPeriod->rowCount() > 0 ? 1 : 0;
            }
        }
        egmInstanceAssignGuestNumbers($pdo, (string)$context['code'], $invitedUserIds);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return ['added_users' => $addedUsers, 'invited' => $invited, 'skipped' => max(0, count($inputRows) - count($rows))];
}

function egmPeriodInvitesInsertLocal(array $context, string $periodCode, string $source, array $candidateIds, string $actor): int
{
    $rows = egmPeriodInvitesRowsByCandidateIds(egmPeriodInvitesCandidateRows($context, $source), $candidateIds);
    $state = egmPeriodInvitesReadLocalState($context);
    $existing = [];
    foreach ($state['periods'][$periodCode] ?? [] as $row) {
        if (is_array($row)) $existing[egmPeriodInvitesIdentityKey($row)] = $row;
    }
    $count = 0;
    foreach ($rows as $row) {
        $key = egmPeriodInvitesIdentityKey($row);
        if ($key === '') continue;
        if (!isset($existing[$key])) $count++;
        $row['invitation_source'] = $source;
        $row['invited_by'] = $actor;
        $row['invited_at'] = date('Y-m-d H:i:s');
        $row['invite_id'] = hash('sha256', $periodCode . '|' . $key);
        $existing[$key] = $row;
    }
    $state['periods'][$periodCode] = array_values($existing);
    egmPeriodInvitesWriteLocalState($context, $state);
    return $count;
}

/** @return array<int,array<string,mixed>> */
function egmPeriodInvitesListInvitedRows(array $context, string $periodCode): array
{
    if ($context['code'] === '') {
        return array_values(array_filter(egmPeriodInvitesReadLocalState($context)['periods'][$periodCode] ?? [], 'is_array'));
    }
    $usersTable = (string)$context['tables']['users'];
    $periodsTable = (string)$context['tables']['user_periods'];
    $statement = $context['pdo']->prepare(<<<SQL
SELECT p.`id` AS `invite_id`, p.`status`, p.`invitation_source`, p.`invited_by`, p.`invited_at`,
u.`work_id`,u.`first_name`,u.`last_name`,u.`national_id`,u.`phone_number`,u.`deputy`,u.`general_department`,u.`department`,u.`gender`,u.`postal_level`,u.`guest_number`,u.`source_row`
FROM `{$periodsTable}` p JOIN `{$usersTable}` u ON u.`id`=p.`user_id`
WHERE p.`period_code`=:period_code ORDER BY p.`invited_at` DESC,p.`id` DESC
SQL);
    $statement->execute([':period_code' => $periodCode]);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function egmPeriodInvitesRemove(array $context, string $periodCode, string $inviteId): bool
{
    if ($context['code'] !== '') {
        $table = (string)$context['tables']['user_periods'];
        $statement = $context['pdo']->prepare("DELETE FROM `{$table}` WHERE `id`=:id AND `period_code`=:period_code");
        $statement->execute([':id' => (int)$inviteId, ':period_code' => $periodCode]);
        return $statement->rowCount() > 0;
    }
    $state = egmPeriodInvitesReadLocalState($context);
    $before = count($state['periods'][$periodCode] ?? []);
    $state['periods'][$periodCode] = array_values(array_filter(
        $state['periods'][$periodCode] ?? [],
        static fn($row): bool => !is_array($row) || !hash_equals((string)($row['invite_id'] ?? ''), $inviteId)
    ));
    egmPeriodInvitesWriteLocalState($context, $state);
    return count($state['periods'][$periodCode]) < $before;
}

/** @return array<string,mixed> */
function egmPeriodInvitesMatchExcel(array $context, string $periodCode, array $inputRows): array
{
    if (count($inputRows) > 100000) throw new InvalidArgumentException('فایل بیش از ۱۰۰٬۰۰۰ ردیف دارد.');
    $source = egmPeriodInvitesGetSource($context);
    $candidates = egmPeriodInvitesCandidateRows($context, $source);
    $byNational = [];
    $byWork = [];
    foreach ($candidates as $candidate) {
        $national = orgUsersNormalizeNationalId($candidate['national_id'] ?? '');
        $work = strtolower(trim((string)($candidate['work_id'] ?? '')));
        if ($national !== '') $byNational[$national] = $candidate;
        if ($work !== '' && !isset($byWork[$work])) $byWork[$work] = $candidate;
    }
    $matched = [];
    $unmatchedRows = [];
    foreach ($inputRows as $offset => $inputRow) {
        if (!is_array($inputRow)) continue;
        $excelRow = egmPeriodInvitesNormalizeExcelRow($inputRow, $offset + 2);
        $national = orgUsersNormalizeNationalId($excelRow['national_id'] ?? '');
        $work = strtolower(trim((string)($excelRow['work_id'] ?? '')));
        $candidate = ($national !== '' ? ($byNational[$national] ?? null) : null)
            ?? ($work !== '' ? ($byWork[$work] ?? null) : null);
        if (!is_array($candidate)) {
            $unmatchedRows[] = $excelRow;
            continue;
        }
        $matched[(string)$candidate['candidate_id']] = $candidate;
    }
    $invited = egmPeriodInvitesInvitedIdentitySet($context, $periodCode);
    $rows = array_values($matched);
    foreach ($rows as &$row) $row['invited'] = isset($invited[egmPeriodInvitesIdentityKey($row)]);
    unset($row);
    return ['source' => $source, 'rows' => $rows, 'matched' => count($rows), 'unmatched' => count($unmatchedRows), 'unmatched_rows' => $unmatchedRows];
}

function egmPeriodInvitesJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function handleEgmPeriodInvitesRequest(string $missionDir, array $sessionUser): void
{
    try {
        $context = egmPeriodInvitesContext($missionDir);
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $input = $_REQUEST;
        if ($method === 'POST' && str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
            $decoded = json_decode((string)file_get_contents('php://input'), true);
            if (is_array($decoded)) $input = $decoded;
        }
        $action = strtolower(trim((string)($input['action'] ?? 'state')));
        if ($method === 'POST') {
            $csrf = trim((string)($input['csrf'] ?? ''));
            if (!function_exists('egmSecurityIsValidCsrfToken') || !egmSecurityIsValidCsrfToken($csrf)) {
                egmPeriodInvitesJson(['status' => 'error', 'message' => 'توکن امنیتی نامعتبر است.'], 403);
            }
        }
        if ($action === 'state') {
            egmPeriodInvitesJson(['status' => 'ok', 'source' => egmPeriodInvitesGetSource($context), 'database_backed' => $context['code'] !== '']);
        }
        if ($action === 'set_source' && $method === 'POST') {
            $source = strtolower(trim((string)($input['source'] ?? '')));
            egmPeriodInvitesSetSource($context, $source);
            egmPeriodInvitesJson(['status' => 'ok', 'source' => $source, 'message' => 'منبع دعوت‌شدگان ذخیره شد.']);
        }
        $periodCode = egmPeriodInvitesValidatePeriod($context, (string)($input['period_code'] ?? ''));
        if ($action === 'filter_options') {
            $source = egmPeriodInvitesGetSource($context);
            egmPeriodInvitesJson(['status' => 'ok', 'source' => $source, 'options' => egmPeriodInvitesFilterOptions(egmPeriodInvitesCandidateRows($context, $source))]);
        }
        if ($action === 'list_candidates') {
            $filters = [];
            foreach (['q','deputy','general_department','department','gender','postal_level'] as $key) $filters[$key] = (string)($input[$key] ?? '');
            $result = egmPeriodInvitesListCandidates($context, $periodCode, $filters, (int)($input['page'] ?? 1), (int)($input['page_size'] ?? 50));
            egmPeriodInvitesJson(['status' => 'ok'] + $result);
        }
        if ($action === 'match_excel' && $method === 'POST') {
            $rows = is_array($input['rows'] ?? null) ? $input['rows'] : [];
            egmPeriodInvitesJson(['status' => 'ok'] + egmPeriodInvitesMatchExcel($context, $periodCode, $rows));
        }
        if ($action === 'invite_unmatched' && $method === 'POST') {
            $rows = is_array($input['rows'] ?? null) ? $input['rows'] : [];
            $actor = tctPeriodInviteClean($sessionUser['code'] ?? '', 191);
            $result = egmPeriodInvitesInsertUnmatchedRegistered($context, $periodCode, $rows, $actor);
            egmPeriodInvitesJson(['status' => 'ok'] + $result + [
                'message' => "{$result['invited']} کاربر بدون تطبیق به EGM افزوده و به بازه دعوت شدند.",
            ]);
        }
        if ($action === 'invite' && $method === 'POST') {
            $source = egmPeriodInvitesGetSource($context);
            $candidateIds = is_array($input['candidate_ids'] ?? null) ? $input['candidate_ids'] : [];
            $actor = tctPeriodInviteClean($sessionUser['code'] ?? '', 191);
            $count = $context['code'] !== ''
                ? egmPeriodInvitesInsertRegistered($context, $periodCode, $source, $candidateIds, $actor)
                : egmPeriodInvitesInsertLocal($context, $periodCode, $source, $candidateIds, $actor);
            egmPeriodInvitesJson(['status' => 'ok', 'invited' => $count, 'message' => "{$count} نفر به بازه دعوت شدند."]);
        }
        if ($action === 'list_invitees') {
            $filters = ['q' => (string)($input['q'] ?? '')];
            $rows = egmPeriodInvitesApplyFilters(egmPeriodInvitesListInvitedRows($context, $periodCode), $filters);
            $total = count($rows);
            $pageSize = max(10, min(100, (int)($input['page_size'] ?? 50)));
            $pages = max(1, (int)ceil($total / $pageSize));
            $page = max(1, min($pages, (int)($input['page'] ?? 1)));
            egmPeriodInvitesJson(['status' => 'ok', 'rows' => array_slice($rows, ($page - 1) * $pageSize, $pageSize), 'total' => $total, 'page' => $page, 'pages' => $pages]);
        }
        if ($action === 'remove' && $method === 'POST') {
            $removed = egmPeriodInvitesRemove($context, $periodCode, trim((string)($input['invite_id'] ?? '')));
            egmPeriodInvitesJson(['status' => 'ok', 'removed' => $removed, 'message' => $removed ? 'دعوت از این بازه حذف شد.' : 'دعوت پیدا نشد.']);
        }
        egmPeriodInvitesJson(['status' => 'error', 'message' => 'عملیات پشتیبانی نمی‌شود.'], 400);
    } catch (InvalidArgumentException $error) {
        egmPeriodInvitesJson(['status' => 'error', 'message' => $error->getMessage()], 422);
    } catch (Throwable $error) {
        error_log('EGM period invites failed: ' . $error->getMessage());
        egmPeriodInvitesJson(['status' => 'error', 'message' => 'عملیات دعوت بازه ناموفق بود.'], 500);
    }
}
