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
    $config = loadConfig($root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'config.php');
    $pdo = connectDatabase($config);
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
    $logsPdo = connectActivityLogDatabase($config);
    if (!$logsPdo instanceof PDO) {
        throw new RuntimeException('اتصال به پایگاه دادهٔ لاگ‌ها برقرار نشد.');
    }
    if ($code !== '') ensureActivityLogTable($logsPdo, 'EGM', $code);
    return [
        'root' => $root,
        'mission_dir' => $resolvedMission,
        'pdo' => $pdo,
        'logs_pdo' => $logsPdo,
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
    $normalized['national_id'] = egmPeriodInvitesValidNationalId($normalized['national_id'] ?? '');
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

function egmPeriodInvitesValidNationalId($value): string
{
    $nationalId = orgUsersNormalizeNationalId($value);
    return preg_match('/^[0-9]{10}$/D', $nationalId) === 1 ? $nationalId : '';
}

/**
 * Fill a missing OEU National ID from an uploaded Excel row matched by Work ID.
 * A different valid National ID or a duplicate Work ID is treated as a conflict
 * so an upload can never silently move an identity to another person.
 */
function egmPeriodInvitesSyncOeuNationalId(PDO $pdo, string $workId, string $nationalId): int
{
    $workId = tctPeriodInviteClean($workId, 128);
    $nationalId = egmPeriodInvitesValidNationalId($nationalId);
    if ($workId === '' || $nationalId === '') return 0;

    $findByWork = $pdo->prepare(
        'SELECT `id`, `national_id` FROM `' . ORG_USERS_ACTIVE_TABLE . '` WHERE `work_id` = :work_id ORDER BY `id` FOR UPDATE'
    );
    $findByWork->execute([':work_id' => $workId]);
    $matches = $findByWork->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$matches) return 0;
    if (count($matches) > 1) {
        throw new InvalidArgumentException("کد پرسنلی {$workId} در OEU تکراری است؛ کد ملی از اکسل به‌روزرسانی نشد.");
    }

    $oeuId = (int)($matches[0]['id'] ?? 0);
    $currentNationalId = egmPeriodInvitesValidNationalId($matches[0]['national_id'] ?? '');
    if ($currentNationalId !== '' && !hash_equals($currentNationalId, $nationalId)) {
        throw new InvalidArgumentException("کد ملی اکسل با کد ملی فعلی کد پرسنلی {$workId} در OEU مغایرت دارد.");
    }

    $findOwner = $pdo->prepare(
        'SELECT `id` FROM `' . ORG_USERS_ACTIVE_TABLE . '` WHERE `national_id` = :national_id AND `id` <> :id LIMIT 1 FOR UPDATE'
    );
    $findOwner->execute([':national_id' => $nationalId, ':id' => $oeuId]);
    if ((int)$findOwner->fetchColumn() > 0) {
        throw new InvalidArgumentException("کد ملی {$nationalId} قبلاً برای کاربر دیگری در OEU ثبت شده است.");
    }
    if (!hash_equals($nationalId, $currentNationalId)) {
        $update = $pdo->prepare(
            'UPDATE `' . ORG_USERS_ACTIVE_TABLE . '` SET `national_id` = :national_id WHERE `id` = :id'
        );
        $update->execute([':national_id' => $nationalId, ':id' => $oeuId]);
    }
    return $oeuId;
}

/** @return array<string,mixed> */
function egmPeriodInvitesSyncMatchedEgmNationalId(
    array $context,
    array $candidate,
    string $nationalId,
    int $oeuUserId
): array {
    $nationalId = egmPeriodInvitesValidNationalId($nationalId);
    if ($nationalId === '') return $candidate;

    $currentNationalId = egmPeriodInvitesValidNationalId($candidate['national_id'] ?? '');
    $workId = tctPeriodInviteClean($candidate['work_id'] ?? '', 128);
    if ($currentNationalId !== '' && !hash_equals($currentNationalId, $nationalId)) {
        throw new InvalidArgumentException("کد ملی اکسل با کد ملی فعلی کد پرسنلی {$workId} در EGM مغایرت دارد.");
    }

    if ($context['code'] !== '' && (string)($candidate['source'] ?? '') === 'custom') {
        $userId = (int)substr((string)($candidate['candidate_id'] ?? ''), 2);
        if ($userId > 0) {
            $usersTable = (string)$context['tables']['users'];
            $findOwner = $context['pdo']->prepare(
                "SELECT `id` FROM `{$usersTable}` WHERE `national_id` = :national_id AND `id` <> :id LIMIT 1 FOR UPDATE"
            );
            $findOwner->execute([':national_id' => $nationalId, ':id' => $userId]);
            if ((int)$findOwner->fetchColumn() > 0) {
                throw new InvalidArgumentException("کد ملی {$nationalId} قبلاً برای کاربر دیگری در EGM ثبت شده است.");
            }
            $update = $context['pdo']->prepare(
                "UPDATE `{$usersTable}` SET `national_id` = :national_id, "
                . "`source_user_id` = COALESCE(:source_user_id, `source_user_id`) WHERE `id` = :id"
            );
            $update->execute([
                ':national_id' => $nationalId,
                ':source_user_id' => $oeuUserId > 0 ? $oeuUserId : null,
                ':id' => $userId,
            ]);
        }
    }
    $candidate['national_id'] = $nationalId;
    return $candidate;
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
            $insertUser = $pdo->prepare(<<<SQL
INSERT INTO `{$usersTable}`
(`work_id`,`first_name`,`last_name`,`national_id`,`phone_number`,`deputy`,`general_department`,`department`,`gender`,`postal_level`,`source_row`,`imported_at`,`is_active`,`source_type`,`source_user_id`)
VALUES
(:work_id,:first_name,:last_name,:national_id,:phone_number,:deputy,:general_department,:department,:gender,:postal_level,:source_row,NOW(),1,'oeu',:source_user_id)
SQL);
            $updateUser = $pdo->prepare(<<<SQL
UPDATE `{$usersTable}` SET
`work_id`=:work_id,`first_name`=:first_name,`last_name`=:last_name,
`national_id`=COALESCE(:national_id,`national_id`),`phone_number`=:phone_number,
`deputy`=:deputy,`general_department`=:general_department,`department`=:department,
`gender`=:gender,`postal_level`=:postal_level,`source_row`=:source_row,
`imported_at`=NOW(),`is_active`=1,`source_type`='oeu',`source_user_id`=:source_user_id
WHERE `id`=:id
SQL);
            $findSource = $pdo->prepare(
                "SELECT `id`, `national_id` FROM `{$usersTable}` WHERE `source_type` = 'oeu' AND `source_user_id` = :source_user_id LIMIT 1 FOR UPDATE"
            );
            $findNational = $pdo->prepare(
                "SELECT `id`, `national_id` FROM `{$usersTable}` WHERE `national_id` = :national_id LIMIT 1 FOR UPDATE"
            );
            $findWork = $pdo->prepare(
                "SELECT `id`, `national_id` FROM `{$usersTable}` WHERE `work_id` = :work_id ORDER BY `is_active` DESC, `id` DESC LIMIT 1 FOR UPDATE"
            );
            foreach ($candidateRows as $row) {
                $sourceId = (int)substr((string)$row['candidate_id'], 2);
                $nationalId = egmPeriodInvitesValidNationalId($row['national_id'] ?? '');
                $params = [
                    ':work_id' => $row['work_id'], ':first_name' => $row['first_name'], ':last_name' => $row['last_name'],
                    ':national_id' => $nationalId !== '' ? $nationalId : null,
                    ':phone_number' => $row['phone_number'], ':deputy' => $row['deputy'],
                    ':general_department' => $row['general_department'], ':department' => $row['department'], ':gender' => $row['gender'],
                    ':postal_level' => $row['postal_level'], ':source_row' => $row['source_row'], ':source_user_id' => $sourceId,
                ];
                $findSource->execute([':source_user_id' => $sourceId]);
                $existing = $findSource->fetch(PDO::FETCH_ASSOC);
                if (!is_array($existing) && $nationalId !== '') {
                    $findNational->execute([':national_id' => $nationalId]);
                    $existing = $findNational->fetch(PDO::FETCH_ASSOC);
                }
                if (!is_array($existing) && trim((string)$row['work_id']) !== '') {
                    $findWork->execute([':work_id' => $row['work_id']]);
                    $existing = $findWork->fetch(PDO::FETCH_ASSOC);
                }
                $userId = is_array($existing) ? (int)($existing['id'] ?? 0) : 0;
                if ($userId > 0) {
                    $existingNationalId = egmPeriodInvitesValidNationalId($existing['national_id'] ?? '');
                    if ($nationalId !== '' && $existingNationalId !== '' && !hash_equals($existingNationalId, $nationalId)) {
                        throw new InvalidArgumentException("کد ملی OEU با کاربر فعلی کد پرسنلی {$row['work_id']} در EGM مغایرت دارد.");
                    }
                    $updateUser->execute($params + [':id' => $userId]);
                } else {
                    $insertUser->execute($params);
                    $userId = (int)$pdo->lastInsertId();
                }
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
    $findExistingNational = $pdo->prepare("SELECT `national_id` FROM `{$usersTable}` WHERE `id` = :id LIMIT 1 FOR UPDATE");
    $update = $pdo->prepare(<<<SQL
UPDATE `{$usersTable}` SET
`work_id`=COALESCE(NULLIF(:work_id,''),`work_id`),`first_name`=COALESCE(NULLIF(:first_name,''),`first_name`),
`last_name`=COALESCE(NULLIF(:last_name,''),`last_name`),`national_id`=COALESCE(NULLIF(:national_id,''),`national_id`),
`phone_number`=COALESCE(NULLIF(:phone_number,''),`phone_number`),
`deputy`=COALESCE(NULLIF(:deputy,''),`deputy`),`general_department`=COALESCE(NULLIF(:general_department,''),`general_department`),
`department`=COALESCE(NULLIF(:department,''),`department`),`gender`=COALESCE(NULLIF(:gender,''),`gender`),
`postal_level`=COALESCE(NULLIF(:postal_level,''),`postal_level`),`source_row`=:source_row,`imported_at`=NOW(),
`state_json`=:state_json,`source_type`='period_excel',
`source_user_id`=COALESCE(:source_user_id,`source_user_id`),`is_active`=1
WHERE `id`=:id
SQL);
    $insertUser = $pdo->prepare(<<<SQL
INSERT INTO `{$usersTable}`
(`work_id`,`first_name`,`last_name`,`national_id`,`phone_number`,`deputy`,`general_department`,`department`,`gender`,`postal_level`,`source_row`,`imported_at`,`is_active`,`state_json`,`source_type`,`source_user_id`)
VALUES (:work_id,:first_name,:last_name,:national_id,:phone_number,:deputy,:general_department,:department,:gender,:postal_level,:source_row,NOW(),1,:state_json,'period_excel',:source_user_id)
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
            $nationalId = egmPeriodInvitesValidNationalId($row['national_id'] ?? '');
            $workId = tctPeriodInviteClean($row['work_id'] ?? '', 128);
            $oeuUserId = egmPeriodInvitesSyncOeuNationalId($pdo, $workId, $nationalId);
            $userId = 0;
            if ($nationalId !== '') {
                $findNational->execute([':national_id' => $nationalId]);
                $userId = (int)$findNational->fetchColumn();
            }
            if ($userId <= 0 && $workId !== '') {
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
                ':national_id' => $nationalId,
                ':phone_number' => $row['phone_number'],
                ':deputy' => $row['deputy'],
                ':general_department' => $row['general_department'],
                ':department' => $row['department'],
                ':gender' => $row['gender'],
                ':postal_level' => $row['postal_level'],
                ':source_row' => (int)$row['source_row'],
                ':state_json' => is_string($stateJson) ? $stateJson : '{}',
                ':source_user_id' => $oeuUserId > 0 ? $oeuUserId : null,
            ];
            if ($userId > 0) {
                $findExistingNational->execute([':id' => $userId]);
                $existingNationalId = egmPeriodInvitesValidNationalId($findExistingNational->fetchColumn());
                if ($nationalId !== '' && $existingNationalId !== '' && !hash_equals($existingNationalId, $nationalId)) {
                    throw new InvalidArgumentException("کد ملی اکسل با کد ملی فعلی کد پرسنلی {$workId} در EGM مغایرت دارد.");
                }
                $update->execute($params + [':id' => $userId]);
            } else {
                $insertParams = $params;
                $insertParams[':national_id'] = $nationalId !== '' ? $nationalId : null;
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
p.`correct_presence`,p.`fake_presence`,p.`entered_date`,p.`entered_time`,p.`quit_date`,p.`quit_time`,
p.`attendance_state`,p.`last_control_condition`,p.`last_control_action`,p.`last_control_message`,p.`last_control_at`,
p.`is_uninvited_guest` AS `period_is_uninvited_guest`,
u.`id` AS `user_id`,u.`work_id`,u.`first_name`,u.`last_name`,u.`national_id`,u.`phone_number`,u.`deputy`,u.`general_department`,u.`department`,u.`gender`,u.`postal_level`,u.`guest_number`,u.`source_row`,
u.`source_type`,u.`source_user_id`,u.`is_active`,u.`is_uninvited_guest`,u.`outside_organization`
FROM `{$periodsTable}` p JOIN `{$usersTable}` u ON u.`id`=p.`user_id`
WHERE p.`period_code`=:period_code ORDER BY p.`invited_at` DESC,p.`id` DESC
SQL);
    $statement->execute([':period_code' => $periodCode]);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function egmPeriodInvitesInputBool($value): bool
{
    if (is_bool($value)) return $value;
    if (is_int($value) || is_float($value)) return (int)$value === 1;
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function egmPeriodInvitesOptionalDate($value, string $label): ?string
{
    $date = trim((string)$value);
    if ($date === '') return null;
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException("{$label} معتبر نیست.");
    }
    return $date;
}

function egmPeriodInvitesOptionalTime($value, string $label): ?string
{
    $time = trim((string)$value);
    if ($time === '') return null;
    if (preg_match('/^([0-9]{2}):([0-9]{2})(?::([0-9]{2}))?$/D', $time, $matches) !== 1) {
        throw new InvalidArgumentException("{$label} معتبر نیست.");
    }
    $hour = (int)$matches[1];
    $minute = (int)$matches[2];
    $second = isset($matches[3]) ? (int)$matches[3] : 0;
    if ($hour > 23 || $minute > 59 || $second > 59) {
        throw new InvalidArgumentException("{$label} معتبر نیست.");
    }
    return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
}

/** @return array<string,mixed> */
function egmPeriodInvitesUpdateInvitedRow(
    array $context,
    string $periodCode,
    string $inviteId,
    array $input,
    string $actor
): array {
    if ($context['code'] === '') {
        throw new InvalidArgumentException('ویرایش دعوت‌شونده فقط برای EGM متصل به پایگاه داده در دسترس است.');
    }
    $inviteIdNumber = (int)$inviteId;
    if ($inviteIdNumber < 1) {
        throw new InvalidArgumentException('دعوت‌شونده انتخاب‌شده معتبر نیست.');
    }

    $pdo = $context['pdo'];
    $usersTable = (string)$context['tables']['users'];
    $periodsTable = (string)$context['tables']['user_periods'];
    $profile = [
        'work_id' => tctPeriodInviteClean($input['work_id'] ?? '', 128),
        'first_name' => tctPeriodInviteClean($input['first_name'] ?? '', 191),
        'last_name' => tctPeriodInviteClean($input['last_name'] ?? '', 191),
        'phone_number' => tctPeriodInviteClean($input['phone_number'] ?? '', 32),
        'deputy' => tctPeriodInviteClean($input['deputy'] ?? '', 191),
        'general_department' => tctPeriodInviteClean($input['general_department'] ?? '', 191),
        'department' => tctPeriodInviteClean($input['department'] ?? '', 191),
        'gender' => tctPeriodInviteClean($input['gender'] ?? '', 32),
        'postal_level' => tctPeriodInviteClean($input['postal_level'] ?? '', 64),
        'guest_number' => tctPeriodInviteClean($input['guest_number'] ?? '', 32),
    ];
    $nationalInput = orgUsersNormalizeNationalId($input['national_id'] ?? '');
    if ($nationalInput !== '' && preg_match('/^[0-9]{10}$/D', $nationalInput) !== 1) {
        throw new InvalidArgumentException('کد ملی باید دقیقاً ۱۰ رقم باشد یا خالی بماند.');
    }
    $profile['national_id'] = $nationalInput !== '' ? $nationalInput : null;

    $attendanceState = strtolower(trim((string)($input['attendance_state'] ?? 'not_entered')));
    if (!in_array($attendanceState, ['not_entered', 'entered', 'quit_completed'], true)) {
        throw new InvalidArgumentException('وضعیت حضور انتخاب‌شده معتبر نیست.');
    }
    $enteredDate = egmPeriodInvitesOptionalDate($input['entered_date'] ?? '', 'تاریخ ورود');
    $enteredTime = egmPeriodInvitesOptionalTime($input['entered_time'] ?? '', 'ساعت ورود');
    $quitDate = egmPeriodInvitesOptionalDate($input['quit_date'] ?? '', 'تاریخ خروج');
    $quitTime = egmPeriodInvitesOptionalTime($input['quit_time'] ?? '', 'ساعت خروج');
    if ($attendanceState === 'not_entered') {
        $enteredDate = $enteredTime = $quitDate = $quitTime = null;
    } elseif ($enteredDate === null || $enteredTime === null) {
        throw new InvalidArgumentException('برای حضور ثبت‌شده، تاریخ و ساعت ورود الزامی است.');
    } elseif ($attendanceState === 'entered') {
        $quitDate = $quitTime = null;
    } elseif ($quitDate === null || $quitTime === null) {
        throw new InvalidArgumentException('برای خروج ثبت‌شده، تاریخ و ساعت خروج الزامی است.');
    } elseif (($quitDate . ' ' . $quitTime) < ($enteredDate . ' ' . $enteredTime)) {
        throw new InvalidArgumentException('زمان خروج نمی‌تواند قبل از زمان ورود باشد.');
    }

    $presence = strtolower(trim((string)($input['presence_classification'] ?? 'none')));
    if (!in_array($presence, ['none', 'correct_presence', 'fake_presence'], true)) {
        throw new InvalidArgumentException('نوع حضور انتخاب‌شده معتبر نیست.');
    }
    if ($attendanceState === 'not_entered') $presence = 'none';
    $correctPresence = $presence === 'correct_presence' ? 1 : 0;
    $fakePresence = $presence === 'fake_presence' ? 1 : 0;
    $isActive = egmPeriodInvitesInputBool($input['is_active'] ?? false) ? 1 : 0;
    $isUninvited = egmPeriodInvitesInputBool($input['is_uninvited_guest'] ?? false) ? 1 : 0;
    $outsideOrganization = egmPeriodInvitesInputBool($input['outside_organization'] ?? false) ? 1 : 0;
    $actor = tctPeriodInviteClean($actor, 191) ?: 'admin';

    $pdo->beginTransaction();
    try {
        $find = $pdo->prepare(
            "SELECT p.`id` AS `invite_id`,p.`user_id`,p.`period_code`,p.`entered_date`,p.`entered_time`,p.`quit_date`,p.`quit_time`,"
            . "p.`attendance_state`,p.`correct_presence`,p.`fake_presence`,p.`is_uninvited_guest` AS `period_is_uninvited_guest`,"
            . "u.`work_id`,u.`first_name`,u.`last_name`,u.`national_id`,u.`phone_number`,u.`deputy`,u.`general_department`,u.`department`,"
            . "u.`gender`,u.`postal_level`,u.`guest_number`,u.`source_type`,u.`source_user_id`,u.`is_active`,u.`is_uninvited_guest`,u.`outside_organization` "
            . "FROM `{$periodsTable}` p JOIN `{$usersTable}` u ON u.`id`=p.`user_id` "
            . "WHERE p.`id`=:invite_id AND p.`period_code`=:period_code LIMIT 1 FOR UPDATE"
        );
        $find->execute([':invite_id' => $inviteIdNumber, ':period_code' => $periodCode]);
        $before = $find->fetch(PDO::FETCH_ASSOC);
        if (!is_array($before)) {
            throw new InvalidArgumentException('این دعوت در بازه انتخاب‌شده پیدا نشد.');
        }
        $userId = (int)$before['user_id'];

        if ($profile['national_id'] !== null) {
            $duplicateNational = $pdo->prepare(
                "SELECT `id` FROM `{$usersTable}` WHERE `national_id`=:national_id AND `id`<>:user_id LIMIT 1 FOR UPDATE"
            );
            $duplicateNational->execute([':national_id' => $profile['national_id'], ':user_id' => $userId]);
            if ((int)$duplicateNational->fetchColumn() > 0) {
                throw new InvalidArgumentException('این کد ملی قبلاً برای مهمان دیگری در همین EGM ثبت شده است.');
            }
        }
        if ($profile['guest_number'] !== '') {
            $duplicateGuest = $pdo->prepare(
                "SELECT `id` FROM `{$usersTable}` WHERE `guest_number`=:guest_number AND `id`<>:user_id LIMIT 1 FOR UPDATE"
            );
            $duplicateGuest->execute([':guest_number' => $profile['guest_number'], ':user_id' => $userId]);
            if ((int)$duplicateGuest->fetchColumn() > 0) {
                throw new InvalidArgumentException('این شماره مهمان قبلاً برای شخص دیگری ثبت شده است.');
            }
        }

        $oeuUpdated = false;
        $sourceType = strtolower(trim((string)($before['source_type'] ?? '')));
        $sourceUserId = (int)($before['source_user_id'] ?? 0);
        if ($sourceType === 'oeu' && $sourceUserId > 0) {
            $findOeu = $pdo->prepare(
                'SELECT `id` FROM `' . ORG_USERS_ACTIVE_TABLE . '` WHERE `id`=:id LIMIT 1 FOR UPDATE'
            );
            $findOeu->execute([':id' => $sourceUserId]);
            if ((int)$findOeu->fetchColumn() > 0) {
                if ($profile['national_id'] !== null) {
                    $duplicateOeuNational = $pdo->prepare(
                        'SELECT `id` FROM `' . ORG_USERS_ACTIVE_TABLE . '` WHERE `national_id`=:national_id AND `id`<>:id LIMIT 1 FOR UPDATE'
                    );
                    $duplicateOeuNational->execute([':national_id' => $profile['national_id'], ':id' => $sourceUserId]);
                    if ((int)$duplicateOeuNational->fetchColumn() > 0) {
                        throw new InvalidArgumentException('این کد ملی قبلاً برای کاربر دیگری در OEU ثبت شده است.');
                    }
                }
                $updateOeu = $pdo->prepare(
                    'UPDATE `' . ORG_USERS_ACTIVE_TABLE . '` SET `work_id`=:work_id,`first_name`=:first_name,`last_name`=:last_name,'
                    . '`national_id`=:national_id,`phone_number`=:phone_number,`deputy`=:deputy,`general_department`=:general_department,'
                    . '`department`=:department,`gender`=:gender,`postal_level`=:postal_level WHERE `id`=:id'
                );
                $updateOeu->execute([
                    ':work_id' => $profile['work_id'], ':first_name' => $profile['first_name'], ':last_name' => $profile['last_name'],
                    ':national_id' => $profile['national_id'] ?? '', ':phone_number' => $profile['phone_number'], ':deputy' => $profile['deputy'],
                    ':general_department' => $profile['general_department'], ':department' => $profile['department'],
                    ':gender' => $profile['gender'], ':postal_level' => $profile['postal_level'], ':id' => $sourceUserId,
                ]);
                $oeuUpdated = true;
            }
        }

        $updateUser = $pdo->prepare(
            "UPDATE `{$usersTable}` SET `work_id`=:work_id,`first_name`=:first_name,`last_name`=:last_name,"
            . "`national_id`=:national_id,`phone_number`=:phone_number,`deputy`=:deputy,`general_department`=:general_department,"
            . "`department`=:department,`gender`=:gender,`postal_level`=:postal_level,`guest_number`=:guest_number,"
            . "`is_active`=:is_active,`is_uninvited_guest`=:is_uninvited_guest,`outside_organization`=:outside_organization WHERE `id`=:user_id"
        );
        $updateUser->execute([
            ':work_id' => $profile['work_id'], ':first_name' => $profile['first_name'], ':last_name' => $profile['last_name'],
            ':national_id' => $profile['national_id'], ':phone_number' => $profile['phone_number'], ':deputy' => $profile['deputy'],
            ':general_department' => $profile['general_department'], ':department' => $profile['department'], ':gender' => $profile['gender'],
            ':postal_level' => $profile['postal_level'], ':guest_number' => $profile['guest_number'] !== '' ? $profile['guest_number'] : null,
            ':is_active' => $isActive, ':is_uninvited_guest' => $isUninvited, ':outside_organization' => $outsideOrganization,
            ':user_id' => $userId,
        ]);

        $message = $attendanceState === 'not_entered'
            ? 'سوابق حضور این مهمان به‌صورت دستی پاک شد.'
            : 'مشخصات و سوابق حضور این مهمان به‌صورت دستی ویرایش شد.';
        $updatePeriod = $pdo->prepare(
            "UPDATE `{$periodsTable}` SET `entered_date`=:entered_date,`entered_time`=:entered_time,"
            . "`quit_date`=:quit_date,`quit_time`=:quit_time,`attendance_state`=:attendance_state,"
            . "`correct_presence`=:correct_presence,`fake_presence`=:fake_presence,`is_uninvited_guest`=:is_uninvited_guest,"
            . "`last_control_condition`='manual_edit',`last_control_action`='manual',`last_control_message`=:message,`last_control_at`=NOW() "
            . "WHERE `id`=:invite_id AND `period_code`=:period_code"
        );
        $updatePeriod->execute([
            ':entered_date' => $enteredDate, ':entered_time' => $enteredTime, ':quit_date' => $quitDate, ':quit_time' => $quitTime,
            ':attendance_state' => $attendanceState, ':correct_presence' => $correctPresence, ':fake_presence' => $fakePresence,
            ':is_uninvited_guest' => $isUninvited, ':message' => $message,
            ':invite_id' => $inviteIdNumber, ':period_code' => $periodCode,
        ]);
        $pdo->commit();

        try {
            $metadata = json_encode([
                'period_code' => $periodCode,
                'invite_id' => $inviteIdNumber,
                'actor' => $actor,
                'oeu_updated' => $oeuUpdated,
                'previous_attendance_state' => (string)($before['attendance_state'] ?? ''),
                'attendance_state' => $attendanceState,
                'presence_classification' => $presence,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $logTable = (string)$context['tables']['activity_logs'];
            $log = $context['logs_pdo']->prepare(
                "INSERT INTO `{$logTable}` (`source_key`,`user_id`,`work_id`,`session_id`,`level`,`action`,`entity_type`,`entity_id`,`ip_address`,`user_agent`,`status`,`message`,`metadata_json`,`occurred_at`) "
                . "VALUES (:source_key,:user_id,:work_id,:session_id,'info','egm.period_invitee_manual_edit','period_invite',:entity_id,:ip_address,:user_agent,'success',:message,:metadata_json,NOW())"
            );
            $log->execute([
                ':source_key' => hash('sha256', implode('|', [$context['code'], $inviteIdNumber, microtime(true), bin2hex(random_bytes(8))])),
                ':user_id' => $userId,
                ':work_id' => $profile['work_id'] !== '' ? $profile['work_id'] : null,
                ':session_id' => session_id() !== '' ? session_id() : null,
                ':entity_id' => $periodCode . ':' . $inviteIdNumber,
                ':ip_address' => tctPeriodInviteClean($_SERVER['REMOTE_ADDR'] ?? '', 45) ?: null,
                ':user_agent' => tctPeriodInviteClean($_SERVER['HTTP_USER_AGENT'] ?? '', 512) ?: null,
                ':message' => $message,
                ':metadata_json' => is_string($metadata) ? $metadata : '{}',
            ]);
        } catch (Throwable $logError) {
            error_log('EGM period invitee manual-edit audit log failed: ' . $logError->getMessage());
        }

        return [
            'updated' => true,
            'invite_id' => $inviteIdNumber,
            'user_id' => $userId,
            'attendance_state' => $attendanceState,
            'presence_classification' => $presence,
            'oeu_updated' => $oeuUpdated,
            'message' => $message,
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
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
    if (count($inputRows) > 1000) {
        throw new InvalidArgumentException('The Excel match request is too large. Refresh the panel so it can process the file in smaller batches.');
    }
    $source = egmPeriodInvitesGetSource($context);
    $candidates = egmPeriodInvitesCandidateRows($context, $source);
    $byNational = [];
    $byWork = [];
    foreach ($candidates as $candidate) {
        $national = egmPeriodInvitesValidNationalId($candidate['national_id'] ?? '');
        $work = strtolower(trim((string)($candidate['work_id'] ?? '')));
        if ($national !== '') $byNational[$national] = $candidate;
        if ($work !== '' && !isset($byWork[$work])) $byWork[$work] = $candidate;
    }
    $matched = [];
    $unmatchedRows = [];
    $conflicts = 0;
    $pdo = $context['pdo'];
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        foreach ($inputRows as $offset => $inputRow) {
            if (!is_array($inputRow)) continue;
            $excelRow = egmPeriodInvitesNormalizeExcelRow($inputRow, $offset + 2);
            $pdo->exec('SAVEPOINT egm_excel_match_row');
            try {
                $national = egmPeriodInvitesValidNationalId($excelRow['national_id'] ?? '');
                $workId = tctPeriodInviteClean($excelRow['work_id'] ?? '', 128);
                $work = strtolower($workId);

                // An uploaded National ID is also authoritative for an unambiguous
                // OEU Work-ID match, even when this EGM currently uses custom users.
                $oeuUserId = egmPeriodInvitesSyncOeuNationalId($pdo, $workId, $national);
                $candidate = ($national !== '' ? ($byNational[$national] ?? null) : null)
                    ?? ($work !== '' ? ($byWork[$work] ?? null) : null);
                if (!is_array($candidate)) {
                    $unmatchedRows[] = $excelRow;
                    $pdo->exec('RELEASE SAVEPOINT egm_excel_match_row');
                    continue;
                }
                $candidate = egmPeriodInvitesSyncMatchedEgmNationalId(
                    $context,
                    $candidate,
                    $national,
                    $oeuUserId
                );
                if ($national !== '') $byNational[$national] = $candidate;
                if ($work !== '') $byWork[$work] = $candidate;
                $matched[(string)$candidate['candidate_id']] = $candidate;
                $pdo->exec('RELEASE SAVEPOINT egm_excel_match_row');
            } catch (InvalidArgumentException $error) {
                $pdo->exec('ROLLBACK TO SAVEPOINT egm_excel_match_row');
                $pdo->exec('RELEASE SAVEPOINT egm_excel_match_row');
                $excelRow['can_invite'] = false;
                $excelRow['match_error'] = tctPeriodInviteClean($error->getMessage(), 500);
                $unmatchedRows[] = $excelRow;
                $conflicts++;
            }
        }
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    $invited = egmPeriodInvitesInvitedIdentitySet($context, $periodCode);
    $rows = array_values($matched);
    foreach ($rows as &$row) $row['invited'] = isset($invited[egmPeriodInvitesIdentityKey($row)]);
    unset($row);
    return [
        'source' => $source,
        'rows' => $rows,
        'matched' => count($rows),
        'unmatched' => count($unmatchedRows),
        'conflicts' => $conflicts,
        'unmatched_rows' => $unmatchedRows,
    ];
}

function egmPeriodInvitesJson(array $payload, int $status = 200): void
{
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
    );
    echo is_string($json) ? $json : '{"status":"error","message":"Unable to encode the server response."}';
    exit;
}

function handleEgmPeriodInvitesRequest(string $missionDir, array $sessionUser): void
{
    ob_start();
    try {
        $context = egmPeriodInvitesContext($missionDir);
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $input = $_REQUEST;
        if ($method === 'POST' && str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
            $contentLength = max(0, (int)($_SERVER['CONTENT_LENGTH'] ?? 0));
            if ($contentLength > 4 * 1024 * 1024) {
                throw new InvalidArgumentException('The request is too large. Please retry so the Excel file can be processed in smaller batches.');
            }
            $rawInput = file_get_contents('php://input');
            if (!is_string($rawInput) || trim($rawInput) === '') {
                throw new InvalidArgumentException('The server received an empty request. Please retry the Excel match.');
            }
            try {
                $decoded = json_decode($rawInput, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new InvalidArgumentException('The server received invalid JSON. Please retry the Excel match.', 0, $error);
            }
            if (!is_array($decoded)) {
                throw new InvalidArgumentException('The request JSON must contain an object.');
            }
            $input = $decoded;
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
        if ($action === 'update_invitee' && $method === 'POST') {
            $actor = tctPeriodInviteClean($sessionUser['code'] ?? ($sessionUser['username'] ?? ''), 191);
            $result = egmPeriodInvitesUpdateInvitedRow(
                $context,
                $periodCode,
                trim((string)($input['invite_id'] ?? '')),
                $input,
                $actor
            );
            egmPeriodInvitesJson(['status' => 'ok'] + $result);
        }
        if ($action === 'remove' && $method === 'POST') {
            $removed = egmPeriodInvitesRemove($context, $periodCode, trim((string)($input['invite_id'] ?? '')));
            egmPeriodInvitesJson(['status' => 'ok', 'removed' => $removed, 'message' => $removed ? 'دعوت از این بازه حذف شد.' : 'دعوت پیدا نشد.']);
        }
        egmPeriodInvitesJson(['status' => 'error', 'message' => 'عملیات پشتیبانی نمی‌شود.'], 400);
    } catch (InvalidArgumentException $error) {
        // Some shared cPanel/Apache configurations replace 422 response bodies
        // with an HTML error page. Keep validation errors as readable JSON and
        // carry the semantic status in the payload for the panel.
        egmPeriodInvitesJson([
            'status' => 'error',
            'error_code' => 'validation_error',
            'http_status' => 422,
            'message' => $error->getMessage(),
        ]);
    } catch (Throwable $error) {
        error_log('EGM period invites failed: ' . $error->getMessage());
        egmPeriodInvitesJson(['status' => 'error', 'message' => 'عملیات دعوت بازه ناموفق بود.'], 500);
    }
}
