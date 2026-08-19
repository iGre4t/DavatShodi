<?php
declare(strict_types=1);

const ORG_USERS_ACTIVE_TABLE = 'organizational_event_users';
const ORG_USERS_QUIT_TABLE = 'oeu_quit';

/** @return array<int, string> */
function orgUsersDataFields(): array
{
    return [
        'work_id',
        'first_name',
        'last_name',
        'national_id',
        'phone_number',
        'deputy',
        'general_department',
        'department',
        'gender',
        'postal_level'
    ];
}

function orgUsersEnsureIdentityConstraint(PDO $pdo): bool
{
    try {
        $index = $pdo->query("SHOW INDEX FROM `organizational_event_users` WHERE `Key_name` = 'uq_org_users_national_id'")->fetch();
        if ($index) {
            return true;
        }
        $invalid = (int)$pdo->query(
            "SELECT EXISTS(SELECT 1 FROM `organizational_event_users` WHERE `national_id` = '' LIMIT 1) "
            . "+ EXISTS(SELECT 1 FROM `organizational_event_users` WHERE `national_id` <> '' "
            . "GROUP BY `national_id` HAVING COUNT(*) > 1 LIMIT 1)"
        )->fetchColumn();
        if ($invalid > 0) {
            return false;
        }
        $pdo->exec('ALTER TABLE `organizational_event_users` ADD UNIQUE KEY `uq_org_users_national_id` (`national_id`)');
        return true;
    } catch (PDOException $error) {
        error_log('Failed to enforce organizational user identity constraint: ' . $error->getMessage());
        return false;
    }
}

function orgUsersEnsureTable(PDO $pdo): bool
{
    $activeSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `organizational_event_users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `work_id` VARCHAR(128) NOT NULL DEFAULT '',
  `first_name` VARCHAR(191) NOT NULL DEFAULT '',
  `last_name` VARCHAR(191) NOT NULL DEFAULT '',
  `national_id` VARCHAR(32) NOT NULL DEFAULT '',
  `phone_number` VARCHAR(32) NOT NULL DEFAULT '',
  `deputy` VARCHAR(191) NOT NULL DEFAULT '',
  `general_department` VARCHAR(191) NOT NULL DEFAULT '',
  `department` VARCHAR(191) NOT NULL DEFAULT '',
  `gender` VARCHAR(32) NOT NULL DEFAULT '',
  `postal_level` VARCHAR(64) NOT NULL DEFAULT '',
  `source_row` INT UNSIGNED NOT NULL,
  `imported_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_org_users_national_id` (`national_id`),
  KEY `idx_org_users_work_id` (`work_id`),
  KEY `idx_org_users_phone_number` (`phone_number`),
  KEY `idx_org_users_structure` (`deputy`, `general_department`, `department`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    $quitSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `oeu_quit` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `original_user_id` BIGINT UNSIGNED NULL,
  `work_id` VARCHAR(128) NOT NULL DEFAULT '',
  `first_name` VARCHAR(191) NOT NULL DEFAULT '',
  `last_name` VARCHAR(191) NOT NULL DEFAULT '',
  `national_id` VARCHAR(32) NOT NULL DEFAULT '',
  `phone_number` VARCHAR(32) NOT NULL DEFAULT '',
  `deputy` VARCHAR(191) NOT NULL DEFAULT '',
  `general_department` VARCHAR(191) NOT NULL DEFAULT '',
  `department` VARCHAR(191) NOT NULL DEFAULT '',
  `gender` VARCHAR(32) NOT NULL DEFAULT '',
  `postal_level` VARCHAR(64) NOT NULL DEFAULT '',
  `source_row` INT UNSIGNED NOT NULL,
  `original_imported_at` DATETIME NULL,
  `quit_batch_id` CHAR(32) NOT NULL,
  `quit_reason` VARCHAR(64) NOT NULL DEFAULT 'missing_from_sap_upload',
  `quit_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_oeu_quit_national_id` (`national_id`),
  KEY `idx_oeu_quit_work_id` (`work_id`),
  KEY `idx_oeu_quit_batch` (`quit_batch_id`),
  KEY `idx_oeu_quit_at` (`quit_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    try {
        $pdo->exec($activeSql);
        $column = $pdo->query("SHOW COLUMNS FROM `organizational_event_users` LIKE 'postal_level'")->fetch();
        if (!$column) {
            $pdo->exec("ALTER TABLE `organizational_event_users` ADD COLUMN `postal_level` VARCHAR(64) NOT NULL DEFAULT '' AFTER `gender`");
        }
        $pdo->exec($quitSql);
        orgUsersEnsureIdentityConstraint($pdo);
        return true;
    } catch (PDOException $error) {
        error_log('Failed to ensure organizational users tables: ' . $error->getMessage());
        return false;
    }
}

function orgUsersCleanValue($value, int $maxLength): string
{
    $clean = trim(is_scalar($value) ? (string)$value : '');
    if (function_exists('mb_substr')) {
        return mb_substr($clean, 0, $maxLength, 'UTF-8');
    }
    return substr($clean, 0, $maxLength);
}

function orgUsersNormalizeNationalId($value): string
{
    $nationalId = strtr(orgUsersCleanValue($value, 32), [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'
    ]);
    return preg_replace('/[\s_-]+/u', '', $nationalId) ?? '';
}

function orgUsersNormalizePhone($value): string
{
    $phone = strtr(orgUsersCleanValue($value, 32), [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'
    ]);
    return preg_replace('/[\s()+-]+/u', '', $phone) ?? '';
}

/**
 * @return array{rows: array<int, array<string, int|string>>, errors: array<int, string>}
 */
function orgUsersPrepareImportRows($value): array
{
    if (!is_array($value)) {
        return ['rows' => [], 'errors' => ['The spreadsheet rows are invalid.']];
    }
    if (count($value) > 100000) {
        return ['rows' => [], 'errors' => ['The spreadsheet exceeds the 100,000-row limit.']];
    }

    $normalized = [];
    $errors = [];
    $seenNationalIds = [];
    foreach ($value as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $sourceRow = max(2, (int)($row['sourceRow'] ?? ($index + 2)));
        $item = [
            'work_id' => orgUsersCleanValue($row['workId'] ?? '', 128),
            'first_name' => orgUsersCleanValue($row['firstName'] ?? '', 191),
            'last_name' => orgUsersCleanValue($row['lastName'] ?? '', 191),
            'national_id' => orgUsersNormalizeNationalId($row['nationalId'] ?? ''),
            'phone_number' => orgUsersCleanValue($row['phoneNumber'] ?? '', 32),
            'deputy' => orgUsersCleanValue($row['deputy'] ?? '', 191),
            'general_department' => orgUsersCleanValue($row['generalDepartment'] ?? '', 191),
            'department' => orgUsersCleanValue($row['department'] ?? '', 191),
            'gender' => orgUsersCleanValue($row['gender'] ?? '', 32),
            'postal_level' => orgUsersCleanValue($row['postalLevel'] ?? '', 64),
            'source_row' => $sourceRow
        ];
        if (count(array_filter(array_slice($item, 0, 10), static fn($field): bool => $field !== '')) === 0) {
            continue;
        }
        if ($item['national_id'] === '') {
            $errors[] = "National ID is required at spreadsheet row {$sourceRow}.";
            continue;
        }
        if (preg_match('/^[0-9]{10}$/D', $item['national_id']) !== 1) {
            $errors[] = "National ID at spreadsheet row {$sourceRow} must contain exactly 10 digits.";
            continue;
        }
        if (isset($seenNationalIds[$item['national_id']])) {
            $firstRow = $seenNationalIds[$item['national_id']];
            $errors[] = "National ID {$item['national_id']} is duplicated at spreadsheet rows {$firstRow} and {$sourceRow}.";
            continue;
        }
        $seenNationalIds[$item['national_id']] = $sourceRow;
        $normalized[] = $item;
    }
    return ['rows' => $normalized, 'errors' => array_slice($errors, 0, 20)];
}

/** @return array<int, array<string, mixed>> */
function orgUsersReadActiveRows(PDO $pdo, bool $forUpdate = false): array
{
    $sql = 'SELECT `id`, `work_id`, `first_name`, `last_name`, `national_id`, `phone_number`, '
        . '`deputy`, `general_department`, `department`, `gender`, `postal_level`, `source_row`, `imported_at` '
        . 'FROM `organizational_event_users` ORDER BY `id`';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return array<int, array<string, mixed>> */
function orgUsersFindConflicts(array $rows): array
{
    $byId = [];
    $groups = ['national_id' => [], 'work_id' => [], 'phone_number' => []];
    $conflicts = [];
    $peerIds = [];
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $byId[$id] = $row;
        $nationalId = orgUsersNormalizeNationalId($row['national_id'] ?? '');
        $workId = trim((string)($row['work_id'] ?? ''));
        $phone = orgUsersNormalizePhone($row['phone_number'] ?? '');
        if ($nationalId === '' || preg_match('/^[0-9]{10}$/D', $nationalId) !== 1) {
            $conflicts[$id][] = $nationalId === '' ? 'Missing National ID' : 'Invalid National ID';
        } else {
            $groups['national_id'][$nationalId][] = $id;
        }
        if ($workId !== '') {
            $workKey = function_exists('mb_strtolower') ? mb_strtolower($workId, 'UTF-8') : strtolower($workId);
            $groups['work_id'][$workKey][] = $id;
        }
        if ($phone !== '') {
            $groups['phone_number'][$phone][] = $id;
        }
    }

    $labels = ['national_id' => 'Duplicate National ID', 'work_id' => 'Duplicate Work ID', 'phone_number' => 'Duplicate Phone Number'];
    foreach ($groups as $field => $values) {
        foreach ($values as $value => $ids) {
            if (count($ids) < 2) {
                continue;
            }
            foreach ($ids as $id) {
                $conflicts[$id][] = $labels[$field] . ': ' . $value;
                foreach ($ids as $peerId) {
                    if ($peerId !== $id) {
                        $peerIds[$id][$peerId] = true;
                    }
                }
            }
        }
    }

    $result = [];
    foreach ($conflicts as $id => $issues) {
        if (!isset($byId[$id])) {
            continue;
        }
        $row = $byId[$id];
        $row['conflicts'] = array_values(array_unique($issues));
        $row['conflict_peers'] = [];
        foreach (array_keys($peerIds[$id] ?? []) as $peerId) {
            if (!isset($byId[$peerId])) {
                continue;
            }
            $peer = $byId[$peerId];
            $row['conflict_peers'][] = [
                'id' => (int)$peer['id'],
                'work_id' => (string)($peer['work_id'] ?? ''),
                'first_name' => (string)($peer['first_name'] ?? ''),
                'last_name' => (string)($peer['last_name'] ?? ''),
                'national_id' => (string)($peer['national_id'] ?? ''),
                'phone_number' => (string)($peer['phone_number'] ?? '')
            ];
        }
        $row['conflict_group_key'] = min(array_merge([(int)$id], array_map(
            static fn(array $peer): int => (int)$peer['id'],
            $row['conflict_peers']
        )));
        $result[] = $row;
    }
    usort($result, static function (array $left, array $right): int {
        $groupOrder = (int)($left['conflict_group_key'] ?? $left['id']) <=> (int)($right['conflict_group_key'] ?? $right['id']);
        return $groupOrder !== 0 ? $groupOrder : ((int)$left['id'] <=> (int)$right['id']);
    });
    return $result;
}

/** @return array<string, array<int, string>> */
function orgUsersBuildFilterOptions(array $rows, string $type): array
{
    $fields = ['deputy', 'general_department', 'department', 'gender', 'postal_level'];
    if ($type === 'quit') {
        $fields[] = 'quit_reason';
    }
    $sets = array_fill_keys($fields, []);
    foreach ($rows as $row) {
        foreach ($fields as $field) {
            $value = trim((string)($row[$field] ?? ''));
            if ($value !== '') {
                $sets[$field][$value] = true;
            }
        }
    }
    $options = [];
    foreach ($sets as $field => $values) {
        $items = array_map('strval', array_keys($values));
        natcasesort($items);
        $options[$field] = array_values($items);
    }
    return $options;
}

/** @return array<string, array<int, string>> */
function orgUsersFilterOptions(PDO $pdo, string $type): array
{
    if ($type === 'active') {
        return orgUsersBuildFilterOptions(orgUsersReadActiveRows($pdo), $type);
    }
    if ($type === 'conflicts') {
        return orgUsersBuildFilterOptions(orgUsersFindConflicts(orgUsersReadActiveRows($pdo)), $type);
    }
    if ($type !== 'quit') {
        throw new InvalidArgumentException('Unsupported organizational users list type.');
    }
    $rows = $pdo->query(
        'SELECT `phone_number`, `deputy`, `general_department`, `department`, `gender`, `postal_level`, `quit_reason` FROM `oeu_quit`'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return orgUsersBuildFilterOptions($rows, $type);
}

function orgUsersSqlLike(string $value): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value) . '%';
}

/**
 * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, page_size: int, pages: int}
 */
function orgUsersListRows(PDO $pdo, string $type, array $filters, int $page, int $pageSize): array
{
    $pageSize = max(10, min(100, $pageSize));
    $page = max(1, $page);
    $dropdownFields = ['phone_number', 'deputy', 'general_department', 'department', 'gender', 'postal_level', 'quit_reason'];
    if ($type === 'conflicts') {
        $rows = orgUsersFindConflicts(orgUsersReadActiveRows($pdo));
        $rows = array_values(array_filter($rows, static function (array $row) use ($filters, $dropdownFields): bool {
            foreach ($filters as $field => $needle) {
                $needle = trim((string)$needle);
                if ($needle === '' || in_array($field, ['quit_from', 'quit_to', 'imported_from', 'imported_to'], true)) {
                    continue;
                }
                $peerText = implode(' ', array_map(static function (array $peer): string {
                    return implode(' ', array_map(static fn($value): string => is_scalar($value) ? (string)$value : '', $peer));
                }, $row['conflict_peers'] ?? []));
                $haystack = $field === 'q'
                    ? implode(' ', array_map(static fn($value): string => is_scalar($value) ? (string)$value : '', $row))
                        . ' ' . implode(' ', $row['conflicts'] ?? []) . ' ' . $peerText
                    : (string)($row[$field] ?? '');
                if ($field === 'phone_number') {
                    $matches = orgUsersNormalizePhone($haystack) === orgUsersNormalizePhone($needle);
                } else {
                    $matches = in_array($field, $dropdownFields, true)
                        ? strcasecmp($haystack, $needle) === 0
                        : stripos($haystack, $needle) !== false;
                }
                if (!$matches) {
                    return false;
                }
            }
            return true;
        }));
        $total = count($rows);
        $pages = max(1, (int)ceil($total / $pageSize));
        $page = min($page, $pages);
        return [
            'rows' => array_slice($rows, ($page - 1) * $pageSize, $pageSize),
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'pages' => $pages
        ];
    }

    if (!in_array($type, ['active', 'quit'], true)) {
        throw new InvalidArgumentException('Unsupported organizational users list type.');
    }
    $table = $type === 'quit' ? 'oeu_quit' : 'organizational_event_users';
    $select = $type === 'quit'
        ? '`id`, `original_user_id`, `work_id`, `first_name`, `last_name`, `national_id`, `phone_number`, `deputy`, `general_department`, `department`, `gender`, `postal_level`, `source_row`, `original_imported_at`, `quit_batch_id`, `quit_reason`, `quit_at`'
        : '`id`, `work_id`, `first_name`, `last_name`, `national_id`, `phone_number`, `deputy`, `general_department`, `department`, `gender`, `postal_level`, `source_row`, `imported_at`';
    $searchable = orgUsersDataFields();
    $searchable[] = 'source_row';
    $searchable[] = $type === 'quit' ? 'original_imported_at' : 'imported_at';
    if ($type === 'quit') {
        array_push($searchable, 'original_user_id', 'quit_batch_id', 'quit_reason', 'quit_at');
    }
    $where = [];
    $parameters = [];
    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') {
        $parts = [];
        foreach ($searchable as $index => $field) {
            $parameter = ':q' . $index;
            $parts[] = "CAST(`{$field}` AS CHAR) LIKE {$parameter} ESCAPE '\\\\'";
            $parameters[$parameter] = orgUsersSqlLike($q);
        }
        $where[] = '(' . implode(' OR ', $parts) . ')';
    }
    foreach (orgUsersDataFields() as $field) {
        $value = trim((string)($filters[$field] ?? ''));
        if ($value === '') {
            continue;
        }
        $parameter = ':f_' . $field;
        if (in_array($field, $dropdownFields, true)) {
            $where[] = "`{$field}` = {$parameter}";
            $parameters[$parameter] = $value;
        } else {
            $where[] = "CAST(`{$field}` AS CHAR) LIKE {$parameter} ESCAPE '\\\\'";
            $parameters[$parameter] = orgUsersSqlLike($value);
        }
    }
    foreach (['source_row', 'quit_reason', 'quit_batch_id', 'original_user_id'] as $field) {
        if (!in_array($field, $searchable, true)) {
            continue;
        }
        $value = trim((string)($filters[$field] ?? ''));
        if ($value === '') {
            continue;
        }
        $parameter = ':f_' . $field;
        if (in_array($field, $dropdownFields, true)) {
            $where[] = "`{$field}` = {$parameter}";
            $parameters[$parameter] = $value;
        } else {
            $where[] = "CAST(`{$field}` AS CHAR) LIKE {$parameter} ESCAPE '\\\\'";
            $parameters[$parameter] = orgUsersSqlLike($value);
        }
    }
    $dateColumn = $type === 'quit' ? 'quit_at' : 'imported_at';
    $fromKey = $type === 'quit' ? 'quit_from' : 'imported_from';
    $toKey = $type === 'quit' ? 'quit_to' : 'imported_to';
    $from = trim((string)($filters[$fromKey] ?? ''));
    $to = trim((string)($filters[$toKey] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from) === 1) {
        $where[] = "`{$dateColumn}` >= :date_from";
        $parameters[':date_from'] = $from . ' 00:00:00';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $to) === 1) {
        $where[] = "`{$dateColumn}` <= :date_to";
        $parameters[':date_to'] = $to . ' 23:59:59';
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $countStatement = $pdo->prepare("SELECT COUNT(*) FROM `{$table}`{$whereSql}");
    $countStatement->execute($parameters);
    $total = (int)$countStatement->fetchColumn();
    $pages = max(1, (int)ceil($total / $pageSize));
    $page = min($page, $pages);
    $offset = ($page - 1) * $pageSize;
    $order = $type === 'quit' ? '`quit_at` DESC, `id` DESC' : '`source_row`, `id`';
    $statement = $pdo->prepare("SELECT {$select} FROM `{$table}`{$whereSql} ORDER BY {$order} LIMIT {$pageSize} OFFSET {$offset}");
    $statement->execute($parameters);
    return [
        'rows' => $statement->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'pages' => $pages
    ];
}

/**
 * @param array<int, array<string, mixed>> $existingRows
 * @param array<int, array<string, int|string>> $incomingRows
 * @return array<string, int>
 */
function orgUsersAnalyzeSyncRows(array $existingRows, array $incomingRows): array
{
    $existingByNationalId = [];
    foreach ($existingRows as $row) {
        $nationalId = orgUsersNormalizeNationalId($row['national_id'] ?? '');
        if ($nationalId !== '') {
            $existingByNationalId[$nationalId][] = $row;
        }
    }
    $incomingByNationalId = [];
    foreach ($incomingRows as $row) {
        $incomingByNationalId[(string)$row['national_id']] = $row;
    }

    $added = 0;
    $updated = 0;
    $unchanged = 0;
    $deduplicated = 0;
    foreach ($incomingByNationalId as $nationalId => $incoming) {
        $matches = $existingByNationalId[$nationalId] ?? [];
        if (!$matches) {
            $added++;
            continue;
        }
        $current = $matches[0];
        $changed = false;
        foreach (orgUsersDataFields() as $field) {
            if ((string)($current[$field] ?? '') !== (string)($incoming[$field] ?? '')) {
                $changed = true;
                break;
            }
        }
        $changed ? $updated++ : $unchanged++;
        $deduplicated += max(0, count($matches) - 1);
    }

    $quitRows = 0;
    foreach ($existingRows as $row) {
        $nationalId = orgUsersNormalizeNationalId($row['national_id'] ?? '');
        if ($nationalId === '' || !isset($incomingByNationalId[$nationalId])) {
            $quitRows++;
        }
    }
    return [
        'uploaded' => count($incomingRows),
        'existing' => count($existingRows),
        'added' => $added,
        'updated' => $updated,
        'unchanged' => $unchanged,
        'quit' => $quitRows,
        'deduplicated' => $deduplicated,
        'final_active' => count($incomingRows)
    ];
}

/** @return array<string, int> */
function orgUsersPreviewSync(PDO $pdo, array $incomingRows): array
{
    return orgUsersAnalyzeSyncRows(orgUsersReadActiveRows($pdo), $incomingRows);
}

/** @return array<string, int> */
function orgUsersSyncRows(PDO $pdo, array $incomingRows): array
{
    $insertSql = <<<'SQL'
INSERT INTO `organizational_event_users`
  (`work_id`, `first_name`, `last_name`, `national_id`, `phone_number`, `deputy`, `general_department`, `department`, `gender`, `postal_level`, `source_row`)
VALUES
  (:work_id, :first_name, :last_name, :national_id, :phone_number, :deputy, :general_department, :department, :gender, :postal_level, :source_row)
SQL;
    $updateSql = <<<'SQL'
UPDATE `organizational_event_users` SET
  `work_id` = :work_id, `first_name` = :first_name, `last_name` = :last_name,
  `national_id` = :national_id, `phone_number` = :phone_number, `deputy` = :deputy,
  `general_department` = :general_department, `department` = :department, `gender` = :gender,
  `postal_level` = :postal_level, `source_row` = :source_row, `imported_at` = CURRENT_TIMESTAMP
WHERE `id` = :id
SQL;
    $archiveSql = <<<'SQL'
INSERT INTO `oeu_quit`
  (`original_user_id`, `work_id`, `first_name`, `last_name`, `national_id`, `phone_number`, `deputy`, `general_department`, `department`, `gender`, `postal_level`, `source_row`, `original_imported_at`, `quit_batch_id`, `quit_reason`)
VALUES
  (:original_user_id, :work_id, :first_name, :last_name, :national_id, :phone_number, :deputy, :general_department, :department, :gender, :postal_level, :source_row, :original_imported_at, :quit_batch_id, 'missing_from_sap_upload')
SQL;

    try {
        $pdo->beginTransaction();
        $existingRows = orgUsersReadActiveRows($pdo, true);
        $summary = orgUsersAnalyzeSyncRows($existingRows, $incomingRows);
        $existingByNationalId = [];
        foreach ($existingRows as $row) {
            $nationalId = orgUsersNormalizeNationalId($row['national_id'] ?? '');
            if ($nationalId !== '') {
                $existingByNationalId[$nationalId][] = $row;
            }
        }
        $incomingNationalIds = array_fill_keys(array_map(
            static fn(array $row): string => (string)$row['national_id'],
            $incomingRows
        ), true);

        $insert = $pdo->prepare($insertSql);
        $update = $pdo->prepare($updateSql);
        $archive = $pdo->prepare($archiveSql);
        $delete = $pdo->prepare('DELETE FROM `organizational_event_users` WHERE `id` = :id');
        foreach ($incomingRows as $row) {
            $parameters = [];
            foreach (orgUsersDataFields() as $field) {
                $parameters[':' . $field] = $row[$field];
            }
            $parameters[':source_row'] = $row['source_row'];
            $matches = $existingByNationalId[(string)$row['national_id']] ?? [];
            if (!$matches) {
                $insert->execute($parameters);
                continue;
            }
            $canonical = array_shift($matches);
            $update->execute($parameters + [':id' => (int)$canonical['id']]);
            foreach ($matches as $duplicate) {
                $delete->execute([':id' => (int)$duplicate['id']]);
            }
        }

        $batchId = bin2hex(random_bytes(16));
        foreach ($existingRows as $row) {
            $nationalId = orgUsersNormalizeNationalId($row['national_id'] ?? '');
            if ($nationalId !== '' && isset($incomingNationalIds[$nationalId])) {
                continue;
            }
            $archive->execute([
                ':original_user_id' => (int)$row['id'],
                ':work_id' => (string)$row['work_id'],
                ':first_name' => (string)$row['first_name'],
                ':last_name' => (string)$row['last_name'],
                ':national_id' => (string)$row['national_id'],
                ':phone_number' => (string)$row['phone_number'],
                ':deputy' => (string)$row['deputy'],
                ':general_department' => (string)$row['general_department'],
                ':department' => (string)$row['department'],
                ':gender' => (string)$row['gender'],
                ':postal_level' => (string)$row['postal_level'],
                ':source_row' => (int)$row['source_row'],
                ':original_imported_at' => $row['imported_at'] ?: null,
                ':quit_batch_id' => $batchId
            ]);
            $delete->execute([':id' => (int)$row['id']]);
        }
        $pdo->commit();
        orgUsersEnsureIdentityConstraint($pdo);
        return $summary;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new RuntimeException('Failed to synchronize organizational users.', 0, $error);
    }
}

/** @return array<string, mixed> */
function orgUsersUpdateActiveUser(PDO $pdo, int $id, array $input): array
{
    if ($id <= 0) {
        throw new InvalidArgumentException('Invalid organizational user ID.');
    }
    try {
        $pdo->beginTransaction();
        $activeRows = orgUsersReadActiveRows($pdo, true);
        $existing = null;
        foreach ($activeRows as $candidate) {
            if ((int)$candidate['id'] === $id) {
                $existing = $candidate;
                break;
            }
        }
        if (!$existing) {
            throw new RuntimeException('The organizational user no longer exists.');
        }
        $prepared = orgUsersPrepareImportRows([[
            'workId' => $input['work_id'] ?? '',
            'firstName' => $input['first_name'] ?? '',
            'lastName' => $input['last_name'] ?? '',
            'nationalId' => $input['national_id'] ?? '',
            'phoneNumber' => $input['phone_number'] ?? '',
            'deputy' => $input['deputy'] ?? '',
            'generalDepartment' => $input['general_department'] ?? '',
            'department' => $input['department'] ?? '',
            'gender' => $input['gender'] ?? '',
            'postalLevel' => $input['postal_level'] ?? '',
            'sourceRow' => (int)$existing['source_row']
        ]]);
        if ($prepared['errors'] || !$prepared['rows']) {
            throw new InvalidArgumentException($prepared['errors'][0] ?? 'The organizational user data is invalid.');
        }
        $row = $prepared['rows'][0];
        foreach ($activeRows as $candidate) {
            if ((int)$candidate['id'] === $id) {
                continue;
            }
            if (orgUsersNormalizeNationalId($candidate['national_id'] ?? '') === $row['national_id']) {
                throw new InvalidArgumentException('That National ID already belongs to another active employee.');
            }
        }
        $sql = <<<'SQL'
UPDATE `organizational_event_users` SET
  `work_id` = :work_id, `first_name` = :first_name, `last_name` = :last_name,
  `national_id` = :national_id, `phone_number` = :phone_number, `deputy` = :deputy,
  `general_department` = :general_department, `department` = :department, `gender` = :gender,
  `postal_level` = :postal_level, `imported_at` = CURRENT_TIMESTAMP
WHERE `id` = :id
SQL;
        $parameters = [':id' => $id];
        foreach (orgUsersDataFields() as $field) {
            $parameters[':' . $field] = $row[$field];
        }
        $pdo->prepare($sql)->execute($parameters);
        $pdo->commit();
        orgUsersEnsureIdentityConstraint($pdo);
        return $row + ['id' => $id];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($error instanceof InvalidArgumentException) {
            throw $error;
        }
        throw new RuntimeException('Failed to update the organizational user.', 0, $error);
    }
}

/** @return array<string, mixed> */
function orgUsersArchiveActiveUser(PDO $pdo, int $id, string $reason = 'conflict_resolution'): array
{
    if ($id <= 0) {
        throw new InvalidArgumentException('Invalid organizational user ID.');
    }
    try {
        $pdo->beginTransaction();
        $statement = $pdo->prepare('SELECT * FROM `organizational_event_users` WHERE `id` = :id FOR UPDATE');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('The organizational user no longer exists.');
        }
        $archive = $pdo->prepare(
            'INSERT INTO `oeu_quit` '
            . '(`original_user_id`, `work_id`, `first_name`, `last_name`, `national_id`, `phone_number`, `deputy`, `general_department`, `department`, `gender`, `postal_level`, `source_row`, `original_imported_at`, `quit_batch_id`, `quit_reason`) '
            . 'VALUES (:original_user_id, :work_id, :first_name, :last_name, :national_id, :phone_number, :deputy, :general_department, :department, :gender, :postal_level, :source_row, :original_imported_at, :quit_batch_id, :quit_reason)'
        );
        $archive->execute([
            ':original_user_id' => (int)$row['id'],
            ':work_id' => (string)$row['work_id'],
            ':first_name' => (string)$row['first_name'],
            ':last_name' => (string)$row['last_name'],
            ':national_id' => (string)$row['national_id'],
            ':phone_number' => (string)$row['phone_number'],
            ':deputy' => (string)$row['deputy'],
            ':general_department' => (string)$row['general_department'],
            ':department' => (string)$row['department'],
            ':gender' => (string)$row['gender'],
            ':postal_level' => (string)$row['postal_level'],
            ':source_row' => (int)$row['source_row'],
            ':original_imported_at' => $row['imported_at'] ?: null,
            ':quit_batch_id' => bin2hex(random_bytes(16)),
            ':quit_reason' => orgUsersCleanValue($reason, 64) ?: 'conflict_resolution'
        ]);
        $pdo->prepare('DELETE FROM `organizational_event_users` WHERE `id` = :id')->execute([':id' => $id]);
        $pdo->commit();
        orgUsersEnsureIdentityConstraint($pdo);
        return $row;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($error instanceof InvalidArgumentException) {
            throw $error;
        }
        throw new RuntimeException('Failed to move the organizational user to OEU Quit.', 0, $error);
    }
}

/** @return array{count: int, quit_count: int, updated_at: string, database_ready: bool} */
function orgUsersStats(?PDO $pdo, bool $tableReady): array
{
    if (!$pdo instanceof PDO || !$tableReady) {
        return ['count' => 0, 'quit_count' => 0, 'updated_at' => '', 'database_ready' => false];
    }
    try {
        $row = $pdo->query('SELECT COUNT(*) AS `count`, MAX(`imported_at`) AS `updated_at` FROM `organizational_event_users`')->fetch();
        $quitCount = (int)$pdo->query('SELECT COUNT(*) FROM `oeu_quit`')->fetchColumn();
    } catch (PDOException $error) {
        error_log('Failed to read organizational users stats: ' . $error->getMessage());
        return ['count' => 0, 'quit_count' => 0, 'updated_at' => '', 'database_ready' => false];
    }
    return [
        'count' => (int)($row['count'] ?? 0),
        'quit_count' => $quitCount,
        'updated_at' => trim((string)($row['updated_at'] ?? '')),
        'database_ready' => true
    ];
}
