<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/egm-instance-storage.php';

/**
 * Database-only compatibility I/O for EGM runtime documents.
 *
 * EGM's legacy application surface still expresses several domain documents
 * as paths. These helpers keep that API stable while storing the bytes in the
 * per-instance InnoDB data table. Non-EGM/code/upload paths always fall back
 * to native filesystem operations.
 */

function &egmDatabaseRuntimeContextCache(): array
{
    static $cache = [];
    return $cache;
}

function egmDatabaseRuntimeLockName(string $scope, string $identity): string
{
    $prefix = strtolower((string)(preg_replace('/[^a-z0-9_-]+/i', '', $scope) ?? ''));
    $prefix = substr(trim($prefix, '-_'), 0, 12);
    if ($prefix === '') $prefix = 'egm';
    $digestLength = max(1, 64 - strlen($prefix) - 1);
    return $prefix . '-' . substr(hash('sha256', $identity), 0, $digestLength);
}

function egmDatabaseRuntimeNormalizeAbsolute(string $path): string
{
    if ($path === '' || str_contains($path, "\0") || preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $path)) {
        return '';
    }
    $value = str_replace('\\', '/', $path);
    if (!preg_match('/^(?:[A-Za-z]:\/|\/)/', $value)) {
        $value = str_replace('\\', '/', getcwd() . DIRECTORY_SEPARATOR . $path);
    }
    $prefix = '';
    if (preg_match('/^[A-Za-z]:\//', $value, $match)) {
        $prefix = strtoupper(substr($value, 0, 2)) . '/';
        $value = substr($value, 3);
    } elseif (str_starts_with($value, '/')) {
        $prefix = '/';
        $value = ltrim($value, '/');
    }
    $parts = [];
    foreach (explode('/', $value) as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }
    return $prefix . implode('/', $parts);
}

/** @return array{pdo:PDO,logs_pdo:PDO,code:string,tables:array<string,string>,root:string,relative:string}|null */
function egmDatabaseRuntimeContextForPath(string $path): ?array
{
    $absolute = egmDatabaseRuntimeNormalizeAbsolute($path);
    if ($absolute === '') return null;
    $cache =& egmDatabaseRuntimeContextCache();
    $casePath = PHP_OS_FAMILY === 'Windows' ? strtolower($absolute) : $absolute;
    foreach ($cache as $rootKey => $cached) {
        if ($casePath === $rootKey || str_starts_with($casePath, $rootKey . '/')) {
            $relative = ltrim(substr($absolute, strlen((string)$cached['root'])), '/');
            $relative = egmInstanceNormalizeRuntimeRelativePath($relative);
            if ($relative !== '' && egmInstanceIsManagedRuntimeFile($relative)) return $cached + ['relative' => $relative];
            return null;
        }
    }

    // Reject non-EGM paths before touching the filesystem. Walking ancestors
    // with realpath() is unusually expensive on Windows drive roots and made
    // ordinary reads such as data/store.json stall the whole request.
    $missionRoot = '';
    if (preg_match('#^(.*/mini apps/Event Guest Manager)(?:/|$)#i', $absolute, $match) === 1) {
        $missionRoot = rtrim((string)$match[1], '/');
    } elseif (preg_match('#^(.*/mini apps/EGMs/[^/]+)(?:/|$)#i', $absolute, $match) === 1) {
        $missionRoot = rtrim((string)$match[1], '/');
    }
    if ($missionRoot === ''
        || !is_dir(str_replace('/', DIRECTORY_SEPARATOR, $missionRoot))
        || egmInstanceRegistryDirectoryForMission(str_replace('/', DIRECTORY_SEPARATOR, $missionRoot)) === '') {
        return null;
    }
    $relative = egmInstanceNormalizeRuntimeRelativePath(ltrim(substr($absolute, strlen($missionRoot)), '/'));
    if ($relative === '' || !egmInstanceIsManagedRuntimeFile($relative)) return null;

    $projectRoot = $missionRoot;
    while (!is_file(str_replace('/', DIRECTORY_SEPARATOR, $projectRoot . '/api/config.php'))) {
        $parent = str_replace('\\', '/', dirname($projectRoot));
        if ($parent === $projectRoot) throw new RuntimeException('Unable to resolve the EGM project root.');
        $projectRoot = $parent;
    }
    $config = loadConfig(str_replace('/', DIRECTORY_SEPARATOR, $projectRoot . '/api/config.php'));
    $pdo = connectDatabase($config);
    if (!$pdo instanceof PDO) throw new RuntimeException('Unable to connect to the EGM database.');
    $registry = egmInstanceRegistryForDirectory($pdo, str_replace('/', DIRECTORY_SEPARATOR, $missionRoot));
    if (!is_array($registry)) throw new RuntimeException('The EGM instance is not registered in the database.');
    $logsPdo = connectActivityLogDatabase($config);
    if (!$logsPdo instanceof PDO) throw new RuntimeException('Unable to connect to the EGM activity logs database.');
    ensureActivityLogTable($logsPdo, 'EGM', (string)$registry['code']);
    $context = [
        'pdo' => $pdo,
        'logs_pdo' => $logsPdo,
        'code' => (string)$registry['code'],
        'tables' => ensureEgmInstanceTables($pdo, (string)$registry['code']),
        'root' => $missionRoot,
    ];
    $rootKey = PHP_OS_FAMILY === 'Windows' ? strtolower($missionRoot) : $missionRoot;
    $cache[$rootKey] = $context;
    return $context + ['relative' => $relative];
}

function egmDatabaseRuntimeVirtualCsvKind(array $context): string
{
    $relative = strtolower(str_replace('\\', '/', (string)($context['relative'] ?? '')));
    if (str_ends_with($relative, '/invitees mapped.csv')) return 'invitees';
    if (str_ends_with($relative, '/answers.csv')) return 'answers';
    return '';
}

function egmDatabaseRuntimeCsvEncode(array $rows): string
{
    $stream = fopen('php://temp/maxmemory:8388608', 'w+b');
    if (!is_resource($stream)) throw new RuntimeException('Unable to prepare the EGM compatibility view.');
    foreach ($rows as $row) {
        if (fputcsv($stream, is_array($row) ? $row : [], ',', '"', '\\') === false) {
            fclose($stream);
            throw new RuntimeException('Unable to encode the EGM compatibility view.');
        }
    }
    rewind($stream);
    $content = stream_get_contents($stream);
    fclose($stream);
    if (!is_string($content)) throw new RuntimeException('Unable to read the EGM compatibility view.');
    return $content;
}

function egmDatabaseRuntimeCsvDecode(string $content): array
{
    $stream = fopen('php://temp/maxmemory:8388608', 'w+b');
    if (!is_resource($stream)) return [];
    fwrite($stream, $content);
    rewind($stream);
    $rows = [];
    while (($row = fgetcsv($stream, null, ',', '"', '\\')) !== false) {
        $rows[] = array_map('strval', is_array($row) ? $row : []);
    }
    fclose($stream);
    return $rows;
}

function egmDatabaseRuntimeNormalizeHeader(string $value): string
{
    $normalized = strtolower(trim(str_replace(['-', '_'], ' ', $value)));
    return preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
}

/** @return list<string> */
function egmDatabaseRuntimeInviteeHeader(): array
{
    return [
        'Work ID', 'First Name', 'Last Name', 'National ID', 'Phone Number',
        'Deputy', 'General Department', 'Department', 'Gender', 'Postal Level',
        'password', 'logins counts', 'logins', 'count of rolls', 'prize won',
        'prize won at', 'wheel angle', 'invitees', 'answers', 'score', 'Answered',
        'task completed ids', 'task score map', 'info tasks', 'Team Task',
        'describe photo task', 'describe photo picks', 'Card Flips Count',
        'Each Level Won Prize', 'Total Prize Won', 'Reward Level Won IDs',
        'Out of Value Rewards', 'Admin', 'Checked as Received Prize',
    ];
}

function egmDatabaseRuntimePeriodCode(array $context): string
{
    $relative = str_replace('\\', '/', (string)$context['relative']);
    if (preg_match('#^tasks/([^/]+)/#i', $relative, $match) === 1) return trim((string)$match[1]);
    return '_event';
}

function egmDatabaseRuntimeDefaultInviteeMapping(): array
{
    return [
        'workId' => 0,
        'firstName' => 1,
        'lastName' => 2,
        'nationalId' => 3,
        'phoneNumber' => 4,
    ];
}

function egmDatabaseRuntimeStructuredDocumentRead(array $context): ?array
{
    $relative = strtolower(str_replace('\\', '/', (string)$context['relative']));
    if ($relative !== 'egm event/egm mapped.json') return null;
    $payload = egmInstanceReadData($context['pdo'], $context['code'], 'invitee_mapping', null);
    if (!is_array($payload)) {
        $payload = egmDatabaseRuntimeDefaultInviteeMapping();
        egmInstanceWriteData($context['pdo'], $context['code'], 'invitee_mapping', $payload);
    }
    $content = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($content)) throw new RuntimeException('Unable to encode the EGM invitee mapping.');
    return ['content' => $content . PHP_EOL, 'size' => strlen($content . PHP_EOL), 'mtime' => time()] + $context;
}

function egmDatabaseRuntimeStructuredDocumentWrite(array $context, string $content): bool
{
    $relative = strtolower(str_replace('\\', '/', (string)$context['relative']));
    if ($relative !== 'egm event/egm mapped.json') return false;
    $decoded = json_decode($content, true);
    if (!is_array($decoded)) throw new RuntimeException('The EGM invitee mapping is invalid.');
    egmInstanceWriteData($context['pdo'], $context['code'], 'invitee_mapping', $decoded);
    egmDatabaseRuntimeRemoveStoredFileRows($context);
    egmDatabaseRuntimeRefreshManifest($context);
    return true;
}

function egmDatabaseRuntimeInviteeRows(array $context): array
{
    $header = egmDatabaseRuntimeInviteeHeader();
    $headerLookup = [];
    foreach ($header as $index => $name) $headerLookup[egmDatabaseRuntimeNormalizeHeader($name)] = $index;
    $periodCode = egmDatabaseRuntimePeriodCode($context);
    $periodJoin = $periodCode === '_event'
        ? ''
        : "LEFT JOIN `{$context['tables']['user_periods']}` p ON p.`user_id`=u.`id` AND p.`period_code`=" . $context['pdo']->quote($periodCode);
    $periodSelect = $periodCode === '_event' ? 'NULL AS period_state' : 'p.`state_json` AS period_state';
    $users = $context['pdo']->query(
        "SELECT u.*, {$periodSelect} FROM `{$context['tables']['users']}` u {$periodJoin} "
        . "WHERE u.`is_active`=1 ORDER BY u.`source_row`,u.`id`"
    )->fetchAll(PDO::FETCH_ASSOC);
    $rows = [$header];
    foreach ($users as $user) {
        $state = json_decode((string)($user['state_json'] ?? ''), true);
        $state = is_array($state) ? $state : [];
        $excel = is_array($state['excel_data'] ?? null) ? $state['excel_data'] : [];
        $periodState = json_decode((string)($user['period_state'] ?? ''), true);
        if (is_array($periodState)) $state = array_replace($state, $periodState);
        $values = array_fill(0, count($header), '');
        $direct = [
            'work id' => $user['work_id'] ?? '', 'first name' => $user['first_name'] ?? '',
            'last name' => $user['last_name'] ?? '', 'national id' => $user['national_id'] ?? '',
            'phone number' => $user['phone_number'] ?? '', 'deputy' => $user['deputy'] ?? '',
            'general department' => $user['general_department'] ?? '', 'department' => $user['department'] ?? '',
            'gender' => $user['gender'] ?? '', 'postal level' => $user['postal_level'] ?? '',
            'logins counts' => $user['login_count'] ?? 0, 'count of rolls' => $user['roll_count'] ?? 0,
            'score' => $user['total_score'] ?? 0, 'admin' => !empty($user['is_admin']) ? '1' : '0',
        ];
        foreach ([$excel, $state, $direct] as $source) {
            foreach ($source as $key => $value) {
                $normalized = egmDatabaseRuntimeNormalizeHeader((string)$key);
                if (!isset($headerLookup[$normalized]) || is_array($value) || is_object($value)) continue;
                $values[$headerLookup[$normalized]] = (string)$value;
            }
        }
        $rows[] = $values;
    }
    return $rows;
}

function egmDatabaseRuntimeAnswersRows(array $context): array
{
    $periodCode = egmDatabaseRuntimePeriodCode($context);
    $statement = $context['pdo']->prepare(
        "SELECT u.`work_id`,a.`question_code`,a.`answer_text` FROM `{$context['tables']['answers']}` a "
        . "JOIN `{$context['tables']['users']}` u ON u.`id`=a.`user_id` WHERE a.`period_code`=:period ORDER BY a.`id`"
    );
    $statement->execute([':period' => $periodCode]);
    $answers = $statement->fetchAll(PDO::FETCH_ASSOC);
    $questions = [];
    $byUser = [];
    foreach ($answers as $answer) {
        $question = (string)$answer['question_code'];
        $workId = (string)$answer['work_id'];
        if ($question !== '') $questions[$question] = true;
        if ($workId !== '' && $question !== '') $byUser[$workId][$question] = (string)($answer['answer_text'] ?? '');
    }
    $header = array_merge(['Work ID'], array_keys($questions));
    $rows = [$header];
    foreach ($byUser as $workId => $userAnswers) {
        $row = [$workId];
        foreach (array_keys($questions) as $question) $row[] = (string)($userAnswers[$question] ?? '');
        $rows[] = $row;
    }
    return $rows;
}

function egmDatabaseRuntimeVirtualCsvRead(array $context): ?array
{
    $kind = egmDatabaseRuntimeVirtualCsvKind($context);
    if ($kind === '') return null;
    $rows = $kind === 'invitees' ? egmDatabaseRuntimeInviteeRows($context) : egmDatabaseRuntimeAnswersRows($context);
    $content = egmDatabaseRuntimeCsvEncode($rows);
    $tables = $context['tables'];
    $table = $kind === 'invitees' ? $tables['users'] : $tables['answers'];
    $updatedColumn = $kind === 'invitees' ? 'egm_updated_at' : 'updated_at';
    $mtime = (int)$context['pdo']->query("SELECT COALESCE(UNIX_TIMESTAMP(MAX(`{$updatedColumn}`)),0) FROM `{$table}`")->fetchColumn();
    return ['content' => $content, 'size' => strlen($content), 'mtime' => $mtime ?: time()] + $context;
}

function egmDatabaseRuntimeHeaderIndex(array $header): array
{
    $indexes = [];
    foreach ($header as $index => $name) $indexes[egmDatabaseRuntimeNormalizeHeader((string)$name)] = (int)$index;
    return $indexes;
}

function egmDatabaseRuntimeWriteInvitees(array $context, array $rows): void
{
    if (!$rows || !is_array($rows[0] ?? null)) throw new RuntimeException('The EGM participant table is empty.');
    $header = array_map('strval', $rows[0]);
    $indexes = egmDatabaseRuntimeHeaderIndex($header);
    $workIndex = $indexes['work id'] ?? $indexes['workid'] ?? -1;
    if ($workIndex < 0) throw new RuntimeException('The EGM participant table has no Work ID column.');
    $periodCode = egmDatabaseRuntimePeriodCode($context);
    $usersTable = $context['tables']['users'];
    $periodsTable = $context['tables']['user_periods'];
    $pdo = $context['pdo'];
    $seenIds = [];
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $findWork = $pdo->prepare("SELECT * FROM `{$usersTable}` WHERE `work_id`=:work ORDER BY `is_active` DESC,`id` LIMIT 1");
        $findNational = $pdo->prepare("SELECT * FROM `{$usersTable}` WHERE `national_id`=:national LIMIT 1");
        foreach (array_slice($rows, 1) as $offset => $row) {
            $workId = trim((string)($row[$workIndex] ?? ''));
            if ($workId === '') continue;
            $nationalIndex = $indexes['national id'] ?? -1;
            $nationalId = $nationalIndex >= 0 ? trim((string)($row[$nationalIndex] ?? '')) : '';
            $findWork->execute([':work' => $workId]);
            $existing = $findWork->fetch(PDO::FETCH_ASSOC);
            if (!is_array($existing) && $nationalId !== '') {
                $findNational->execute([':national' => $nationalId]);
                $existing = $findNational->fetch(PDO::FETCH_ASSOC);
            }
            $state = is_array($existing) ? json_decode((string)($existing['state_json'] ?? ''), true) : [];
            $state = is_array($state) ? $state : [];
            foreach ($header as $index => $name) {
                $key = egmDatabaseRuntimeNormalizeHeader((string)$name);
                if ($key !== '' && $key !== 'password') $state[$key] = (string)($row[$index] ?? '');
            }
            $value = static function (string $name, string $fallback = '') use ($indexes, $row): string {
                $index = $indexes[$name] ?? -1;
                return $index >= 0 ? trim((string)($row[$index] ?? '')) : $fallback;
            };
            $fields = [
                'work_id' => $workId,
                'first_name' => $value('first name', (string)($existing['first_name'] ?? '')),
                'last_name' => $value('last name', (string)($existing['last_name'] ?? '')),
                'national_id' => $nationalId !== '' ? $nationalId : null,
                'phone_number' => $value('phone number', (string)($existing['phone_number'] ?? '')),
                'deputy' => $value('deputy', (string)($existing['deputy'] ?? '')),
                'general_department' => $value('general department', (string)($existing['general_department'] ?? '')),
                'department' => $value('department', (string)($existing['department'] ?? '')),
                'gender' => $value('gender', (string)($existing['gender'] ?? '')),
                'postal_level' => $value('postal level', (string)($existing['postal_level'] ?? '')),
                'login_count' => max(0, (int)$value('logins counts', (string)($existing['login_count'] ?? 0))),
                'total_score' => (int)$value('score', (string)($existing['total_score'] ?? 0)),
                'roll_count' => max(0, (int)$value('count of rolls', (string)($existing['roll_count'] ?? 0))),
                'is_admin' => in_array(strtolower($value('admin')), ['1','true','yes','on','admin'], true) ? 1 : 0,
                'state_json' => json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            ];
            $password = $value('password');
            if (is_array($existing)) {
                $sets = [];
                $params = [':id' => (int)$existing['id']];
                foreach ($fields as $field => $fieldValue) { $sets[] = "`{$field}`=:{$field}"; $params[':' . $field] = $fieldValue; }
                if ($password !== '') { $sets[] = '`password_hash`=:password_hash'; $params[':password_hash'] = password_hash($password, PASSWORD_DEFAULT); }
                $sets[] = '`is_active`=1';
                $pdo->prepare("UPDATE `{$usersTable}` SET " . implode(',', $sets) . ' WHERE `id`=:id')->execute($params);
                $userId = (int)$existing['id'];
            } else {
                $insert = $pdo->prepare(
                    "INSERT INTO `{$usersTable}` (`work_id`,`first_name`,`last_name`,`national_id`,`phone_number`,`deputy`,`general_department`,`department`,`gender`,`postal_level`,`source_row`,`password_hash`,`is_admin`,`is_active`,`login_count`,`total_score`,`roll_count`,`state_json`,`source_type`) "
                    . "VALUES (:work_id,:first_name,:last_name,:national_id,:phone_number,:deputy,:general_department,:department,:gender,:postal_level,:source_row,:password_hash,:is_admin,1,:login_count,:total_score,:roll_count,:state_json,'custom')"
                );
                $insert->execute($fields + [':source_row' => $offset + 2, ':password_hash' => $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null]);
                $userId = (int)$pdo->lastInsertId();
            }
            $seenIds[] = $userId;
            if ($periodCode !== '_event') {
                $upsertPeriod = $pdo->prepare(
                    "INSERT INTO `{$periodsTable}` (`user_id`,`period_code`,`state_json`,`status`,`score`,`attempt_count`) VALUES (:user,:period,:state,'not_started',:score,0) "
                    . "ON DUPLICATE KEY UPDATE `state_json`=VALUES(`state_json`),`score`=VALUES(`score`)"
                );
                $upsertPeriod->execute([':user' => $userId, ':period' => $periodCode, ':state' => $fields['state_json'], ':score' => $fields['total_score']]);
            }
        }
        if ($periodCode === '_event' && $seenIds) {
            $placeholders = implode(',', array_fill(0, count($seenIds), '?'));
            $pdo->prepare("UPDATE `{$usersTable}` SET `is_active`=0 WHERE `source_type`='custom' AND `id` NOT IN ({$placeholders})")->execute($seenIds);
        }
        egmInstanceAssignGuestNumbers($pdo, $context['code']);
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function egmDatabaseRuntimeWriteAnswers(array $context, array $rows): void
{
    if (!$rows || !is_array($rows[0] ?? null)) throw new RuntimeException('The EGM answers table is empty.');
    $header = array_map('strval', $rows[0]);
    $indexes = egmDatabaseRuntimeHeaderIndex($header);
    $workIndex = $indexes['work id'] ?? $indexes['workid'] ?? -1;
    if ($workIndex < 0) throw new RuntimeException('The EGM answers table has no Work ID column.');
    $pdo = $context['pdo'];
    $periodCode = egmDatabaseRuntimePeriodCode($context);
    $users = egmInstanceUserIdMaps($pdo, $context['tables']['users']);
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM `{$context['tables']['answers']}` WHERE `period_code`=:period")->execute([':period' => $periodCode]);
        $insert = $pdo->prepare(
            "INSERT INTO `{$context['tables']['answers']}` (`user_id`,`period_code`,`question_code`,`attempt_number`,`answer_text`,`answer_json`,`answered_at`) "
            . "VALUES (:user,:period,:question,1,:answer,:json,CURRENT_TIMESTAMP)"
        );
        foreach (array_slice($rows, 1) as $row) {
            $workId = trim((string)($row[$workIndex] ?? ''));
            $userId = egmInstanceResolveUserId($users, $workId);
            if ($userId === null) continue;
            foreach ($header as $index => $label) {
                if ($index === $workIndex) continue;
                $answer = trim((string)($row[$index] ?? ''));
                if ($answer === '') continue;
                $question = trim((string)$label);
                if (strlen($question) > 191) $question = 'q_' . hash('sha256', $question);
                $insert->execute([':user' => $userId, ':period' => $periodCode, ':question' => $question, ':answer' => $answer, ':json' => json_encode(['column' => (string)$label], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}']);
            }
        }
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function egmDatabaseRuntimeRemoveStoredFileRows(array $context): void
{
    $table = $context['tables']['data'];
    $context['pdo']->prepare("DELETE FROM `{$table}` WHERE (`storage_kind`='runtime_chunk' AND `file_path`=:path) OR (`storage_kind`='runtime_file' AND `data_key`=:key)")
        ->execute([':path' => $context['relative'], ':key' => egmInstanceRuntimeFileDataKey($context['relative'])]);
}

function egmDatabaseRuntimeVirtualCsvWrite(array $context, string $content): bool
{
    $kind = egmDatabaseRuntimeVirtualCsvKind($context);
    if ($kind === '') return false;
    $rows = egmDatabaseRuntimeCsvDecode($content);
    if ($kind === 'invitees') egmDatabaseRuntimeWriteInvitees($context, $rows);
    else egmDatabaseRuntimeWriteAnswers($context, $rows);
    egmDatabaseRuntimeRemoveStoredFileRows($context);
    egmDatabaseRuntimeRefreshManifest($context);
    return true;
}

/**
 * Returns true/false when a relational password hash exists, otherwise null so
 * old installations can still be migrated without silently changing policy.
 */
function egmDatabaseRuntimeVerifyUserPassword(string $missionDir, string $workId, string $plainPassword): ?bool
{
    $context = egmDatabaseRuntimeContextForPath(rtrim($missionDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'EGM Event' . DIRECTORY_SEPARATOR . 'Invitees mapped.csv');
    if ($context === null || trim($workId) === '' || $plainPassword === '') return null;
    $statement = $context['pdo']->prepare("SELECT `password_hash` FROM `{$context['tables']['users']}` WHERE `work_id`=:work AND `is_active`=1 ORDER BY `id` LIMIT 1");
    $statement->execute([':work' => trim($workId)]);
    $hash = trim((string)$statement->fetchColumn());
    return $hash === '' ? null : password_verify($plainPassword, $hash);
}

/** @param list<array<string,mixed>> $rows
 *  @return list<array<string,mixed>>
 */
function egmDatabaseRuntimeActivityRowsToEntries(array $rows): array
{
    $entries = [];
    foreach ($rows as $row) {
        $metadata = json_decode((string)($row['metadata_json'] ?? ''), true);
        $entries[] = [
            'timestamp' => (string)($row['occurred_at'] ?? ''),
            'level' => (string)($row['level'] ?? 'info'),
            'user_id' => $row['work_id'] ?? null,
            'session_id' => $row['session_id'] ?? null,
            'action' => (string)($row['action'] ?? 'egm.unspecified'),
            'entity_type' => $row['entity_type'] ?? null,
            'entity_id' => $row['entity_id'] ?? null,
            'ip_address' => $row['ip_address'] ?? null,
            'user_agent' => $row['user_agent'] ?? null,
            'status' => (string)($row['status'] ?? 'success'),
            'message' => $row['message'] ?? null,
            'metadata' => is_array($metadata) ? $metadata : [],
        ];
    }
    return $entries;
}

/** @return list<string> */
function egmDatabaseRuntimeActivityDays(string $missionDir): array
{
    $context = egmDatabaseRuntimeContextForPath(rtrim($missionDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'Setting.json');
    if ($context === null) return [];
    $rows = $context['logs_pdo']->query(
        "SELECT DISTINCT DATE_FORMAT(`occurred_at`, '%Y-%m-%d') AS `day` "
        . "FROM `{$context['tables']['activity_logs']}` ORDER BY `day` DESC"
    )->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_filter(array_map('strval', $rows), static fn(string $day): bool => $day !== ''));
}

/** @return list<array<string,mixed>> */
function egmDatabaseRuntimeActivityEntriesForDay(string $missionDir, string $day, int $limit = 2000): array
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) !== 1) return [];
    $context = egmDatabaseRuntimeContextForPath(rtrim($missionDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'Setting.json');
    if ($context === null) return [];
    $limit = max(1, min(10000, $limit));
    $nextDay = (new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
    $statement = $context['logs_pdo']->prepare(
        "SELECT `work_id`,`session_id`,`level`,`action`,`entity_type`,`entity_id`,`ip_address`,`user_agent`,`status`,`message`,`metadata_json`,`occurred_at` "
        . "FROM `{$context['tables']['activity_logs']}` WHERE `occurred_at` >= :start AND `occurred_at` < :end "
        . "ORDER BY `occurred_at` DESC, `id` DESC LIMIT {$limit}"
    );
    $statement->execute([':start' => $day . ' 00:00:00', ':end' => $nextDay . ' 00:00:00']);
    return egmDatabaseRuntimeActivityRowsToEntries($statement->fetchAll(PDO::FETCH_ASSOC));
}

function egmDatabaseRuntimeAppendActivity(string $missionDir, array $entry): bool
{
    $context = egmDatabaseRuntimeContextForPath(rtrim($missionDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'Setting.json');
    if ($context === null) return false;
    $workId = trim((string)($entry['user_id'] ?? ''));
    $maps = egmInstanceUserIdMaps($context['pdo'], $context['tables']['users']);
    $userId = $workId !== '' ? ($maps['work'][$workId] ?? null) : null;
    $metadata = json_encode(is_array($entry['metadata'] ?? null) ? $entry['metadata'] : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $occurredAt = (new DateTimeImmutable((string)($entry['timestamp'] ?? 'now')))->format('Y-m-d H:i:s');
    $sourceKey = hash('sha256', implode('|', [
        $context['code'], $occurredAt, (string)($entry['action'] ?? ''), $workId,
        (string)($entry['session_id'] ?? ''), bin2hex(random_bytes(12)),
    ]));
    $statement = $context['logs_pdo']->prepare(
        "INSERT INTO `{$context['tables']['activity_logs']}` "
        . "(`source_key`,`user_id`,`work_id`,`session_id`,`level`,`action`,`entity_type`,`entity_id`,`ip_address`,`user_agent`,`status`,`message`,`metadata_json`,`occurred_at`) "
        . "VALUES (:source_key,:user_id,:work_id,:session_id,:level,:action,:entity_type,:entity_id,:ip_address,:user_agent,:status,:message,:metadata_json,:occurred_at)"
    );
    return $statement->execute([
        ':source_key' => $sourceKey,
        ':user_id' => $userId,
        ':work_id' => $workId !== '' ? $workId : null,
        ':session_id' => $entry['session_id'] ?? null,
        ':level' => (string)($entry['level'] ?? 'info'),
        ':action' => (string)($entry['action'] ?? 'egm.unspecified'),
        ':entity_type' => $entry['entity_type'] ?? null,
        ':entity_id' => $entry['entity_id'] ?? null,
        ':ip_address' => $entry['ip_address'] ?? null,
        ':user_agent' => $entry['user_agent'] ?? null,
        ':status' => (string)($entry['status'] ?? 'success'),
        ':message' => $entry['message'] ?? null,
        ':metadata_json' => is_string($metadata) ? $metadata : '{}',
        ':occurred_at' => $occurredAt,
    ]);
}

function egmDatabaseRuntimeRead(string $path): ?array
{
    $context = egmDatabaseRuntimeContextForPath($path);
    if ($context === null) return null;
    $structured = egmDatabaseRuntimeStructuredDocumentRead($context);
    if ($structured !== null) return $structured;
    $virtual = egmDatabaseRuntimeVirtualCsvRead($context);
    if ($virtual !== null) return $virtual;
    $table = $context['tables']['data'];
    $statement = $context['pdo']->prepare(
        "SELECT `file_data`, `content_sha256`, `file_size`, `file_mtime` FROM `{$table}` "
        . "WHERE `data_key` = :key AND `storage_kind` = 'runtime_file' LIMIT 1"
    );
    $statement->execute([':key' => egmInstanceRuntimeFileDataKey($context['relative'])]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return null;
    $content = $row['file_data'] ?? null;
    if (is_resource($content)) $content = stream_get_contents($content);
    if (!is_string($content)) {
        $content = '';
        $chunks = $context['pdo']->prepare(
            "SELECT `file_data`, `content_sha256` FROM `{$table}` "
            . "WHERE `storage_kind` = 'runtime_chunk' AND `file_path` = :path ORDER BY `file_chunk`"
        );
        $chunks->execute([':path' => $context['relative']]);
        while ($chunkRow = $chunks->fetch(PDO::FETCH_ASSOC)) {
            $chunk = $chunkRow['file_data'] ?? null;
            if (is_resource($chunk)) $chunk = stream_get_contents($chunk);
            if (!is_string($chunk) || hash('sha256', $chunk) !== trim((string)($chunkRow['content_sha256'] ?? ''))) {
                throw new RuntimeException('Corrupt EGM database document chunk: ' . $context['relative']);
            }
            $content .= $chunk;
        }
    }
    $expectedSize = (int)($row['file_size'] ?? strlen($content));
    $expectedHash = trim((string)($row['content_sha256'] ?? ''));
    if (strlen($content) !== $expectedSize || ($expectedHash !== '' && hash('sha256', $content) !== $expectedHash)) {
        throw new RuntimeException('EGM database document failed integrity validation: ' . $context['relative']);
    }
    return ['content' => $content, 'size' => $expectedSize, 'mtime' => (int)($row['file_mtime'] ?? 0)] + $context;
}

function egmDatabaseRuntimeWrite(string $path, string $content, ?int $mtime = null): bool
{
    $context = egmDatabaseRuntimeContextForPath($path);
    if ($context === null) return false;
    $extension = strtolower((string)pathinfo($context['relative'], PATHINFO_EXTENSION));
    if (in_array($extension, ['json', 'csv', 'js'], true) && str_starts_with($content, "\xEF\xBB\xBF")) {
        $content = substr($content, 3);
    }
    if (strtolower(str_replace('\\', '/', $context['relative'])) === 'egm event/egm mapped.json') {
        return egmDatabaseRuntimeStructuredDocumentWrite($context, $content);
    }
    if (egmDatabaseRuntimeVirtualCsvKind($context) !== '') {
        return egmDatabaseRuntimeVirtualCsvWrite($context, $content);
    }
    $pdo = $context['pdo'];
    $table = $context['tables']['data'];
    $relative = $context['relative'];
    $mtime = $mtime ?? time();
    $hash = hash('sha256', $content);
    $chunkSize = 393216;
    $chunkCount = max(1, (int)ceil(strlen($content) / $chunkSize));
    $metadata = json_encode(['path' => $relative, 'sha256' => $hash, 'size' => strlen($content), 'mtime' => $mtime, 'chunks' => $chunkCount], JSON_UNESCAPED_SLASHES);
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $header = $pdo->prepare(
            "INSERT INTO `{$table}` (`data_key`,`payload`,`storage_kind`,`file_path`,`file_data`,`content_sha256`,`file_size`,`file_mtime`,`file_chunk`) "
            . "VALUES (:key,:payload,'runtime_file',:path,NULL,:hash,:size,:mtime,NULL) "
            . "ON DUPLICATE KEY UPDATE `payload`=VALUES(`payload`),`storage_kind`='runtime_file',`file_path`=VALUES(`file_path`),"
            . "`file_data`=NULL,`content_sha256`=VALUES(`content_sha256`),`file_size`=VALUES(`file_size`),`file_mtime`=VALUES(`file_mtime`),`file_chunk`=NULL,`updated_at`=CURRENT_TIMESTAMP"
        );
        $header->execute([':key' => egmInstanceRuntimeFileDataKey($relative), ':payload' => $metadata ?: '{}', ':path' => $relative, ':hash' => $hash, ':size' => strlen($content), ':mtime' => $mtime]);
        $pdo->prepare("DELETE FROM `{$table}` WHERE `storage_kind`='runtime_chunk' AND `file_path`=:path")->execute([':path' => $relative]);
        $chunkInsert = $pdo->prepare(
            "INSERT INTO `{$table}` (`data_key`,`payload`,`storage_kind`,`file_path`,`file_data`,`content_sha256`,`file_size`,`file_mtime`,`file_chunk`) "
            . "VALUES (:key,:payload,'runtime_chunk',:path,:data,:hash,:size,:mtime,:chunk)"
        );
        $pathHash = hash('sha256', $relative);
        for ($index = 0; $index < $chunkCount; $index++) {
            $chunk = substr($content, $index * $chunkSize, $chunkSize);
            $chunkInsert->bindValue(':key', 'runtime_chunk:' . $pathHash . ':' . str_pad((string)$index, 8, '0', STR_PAD_LEFT));
            $chunkInsert->bindValue(':payload', json_encode(['path' => $relative, 'chunk' => $index], JSON_UNESCAPED_SLASHES) ?: '{}');
            $chunkInsert->bindValue(':path', $relative);
            $chunkInsert->bindValue(':data', $chunk, PDO::PARAM_LOB);
            $chunkInsert->bindValue(':hash', hash('sha256', $chunk));
            $chunkInsert->bindValue(':size', strlen($chunk), PDO::PARAM_INT);
            $chunkInsert->bindValue(':mtime', $mtime, PDO::PARAM_INT);
            $chunkInsert->bindValue(':chunk', $index, PDO::PARAM_INT);
            $chunkInsert->execute();
        }
        egmDatabaseRuntimeRefreshManifest($context);
        if ($ownsTransaction) $pdo->commit();
        return true;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function egmDatabaseRuntimeRefreshManifest(array $context): void
{
    $table = $context['tables']['data'];
    $rows = $context['pdo']->query(
        "SELECT `file_path`,`content_sha256`,`file_size`,`file_mtime` FROM `{$table}` WHERE `storage_kind`='runtime_file' ORDER BY `file_path`"
    )->fetchAll(PDO::FETCH_ASSOC);
    $manifest = array_map(static fn(array $row): array => [
        'path' => (string)$row['file_path'],
        'sha256' => (string)$row['content_sha256'],
        'size' => (int)$row['file_size'],
        'mtime' => (int)$row['file_mtime'],
    ], $rows);
    egmInstanceWriteData($context['pdo'], $context['code'], 'runtime_file_manifest', $manifest);
}

function egmDatabaseRuntimeDelete(string $path): bool
{
    $context = egmDatabaseRuntimeContextForPath($path);
    if ($context === null) return false;
    $pdo = $context['pdo'];
    $table = $context['tables']['data'];
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $deleteChunks = $pdo->prepare("DELETE FROM `{$table}` WHERE `storage_kind`='runtime_chunk' AND `file_path`=:path");
        $deleteChunks->execute([':path' => $context['relative']]);
        $deletedChunks = $deleteChunks->rowCount();
        $delete = $pdo->prepare("DELETE FROM `{$table}` WHERE `data_key`=:key AND `storage_kind`='runtime_file'");
        $delete->execute([':key' => egmInstanceRuntimeFileDataKey($context['relative'])]);
        $deletedFile = $delete->rowCount();
        if ($deletedFile > 0 || $deletedChunks > 0) {
            egmDatabaseRuntimeRefreshManifest($context);
        }
        if ($ownsTransaction) $pdo->commit();
        return $deletedFile > 0;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function egmDatabaseRuntimePaths(array $context): array
{
    $table = $context['tables']['data'];
    return array_map('strval', $context['pdo']->query(
        "SELECT `file_path` FROM `{$table}` WHERE `storage_kind`='runtime_file' AND `file_path` IS NOT NULL ORDER BY `file_path`"
    )->fetchAll(PDO::FETCH_COLUMN));
}

function egmDbFileGetContents(string $filename, bool $useIncludePath = false, $context = null, int $offset = 0, ?int $length = null)
{
    $row = egmDatabaseRuntimeRead($filename);
    if ($row === null) return file_get_contents($filename, $useIncludePath, $context, $offset, $length);
    return $length === null ? substr($row['content'], $offset) : substr($row['content'], $offset, $length);
}

function egmDbFilePutContents(string $filename, $data, int $flags = 0, $context = null)
{
    if (egmDatabaseRuntimeContextForPath($filename) === null) return file_put_contents($filename, $data, $flags, $context);
    if (is_resource($data)) $data = stream_get_contents($data);
    if (is_array($data)) $data = implode('', array_map('strval', $data));
    $content = (string)$data;
    if (($flags & FILE_APPEND) === FILE_APPEND) {
        $existing = egmDatabaseRuntimeRead($filename);
        $content = (string)($existing['content'] ?? '') . $content;
    }
    egmDatabaseRuntimeWrite($filename, $content);
    return strlen($content);
}

function egmDbIsFile(string $filename): bool
{
    if (egmDatabaseRuntimeContextForPath($filename) !== null) return egmDatabaseRuntimeRead($filename) !== null;
    return is_file($filename);
}

function egmDbFileExists(string $filename): bool
{
    if (egmDatabaseRuntimeContextForPath($filename) !== null) return egmDatabaseRuntimeRead($filename) !== null;
    return file_exists($filename);
}

function egmDbFilemtime(string $filename)
{
    $row = egmDatabaseRuntimeRead($filename);
    return $row === null ? filemtime($filename) : $row['mtime'];
}

function egmDbFilesize(string $filename)
{
    $row = egmDatabaseRuntimeRead($filename);
    return $row === null ? filesize($filename) : $row['size'];
}

function egmDbUnlink(string $filename, $context = null): bool
{
    if (egmDatabaseRuntimeContextForPath($filename) !== null) return egmDatabaseRuntimeDelete($filename);
    return unlink($filename, $context);
}

function egmDbRename(string $from, string $to, $context = null): bool
{
    $source = egmDatabaseRuntimeRead($from);
    $targetManaged = egmDatabaseRuntimeContextForPath($to) !== null;
    if ($source !== null || $targetManaged) {
        $content = $source !== null ? $source['content'] : file_get_contents($from);
        if (!is_string($content)) return false;
        if ($targetManaged) egmDatabaseRuntimeWrite($to, $content, $source['mtime'] ?? time());
        elseif (file_put_contents($to, $content, LOCK_EX) === false) return false;
        if ($source !== null) egmDatabaseRuntimeDelete($from); else @unlink($from);
        return true;
    }
    return rename($from, $to, $context);
}

function egmDbCopy(string $from, string $to, $context = null): bool
{
    $source = egmDatabaseRuntimeRead($from);
    $targetManaged = egmDatabaseRuntimeContextForPath($to) !== null;
    if ($source !== null || $targetManaged) {
        $content = $source !== null ? $source['content'] : file_get_contents($from);
        if (!is_string($content)) return false;
        return $targetManaged
            ? egmDatabaseRuntimeWrite($to, $content, $source['mtime'] ?? time())
            : file_put_contents($to, $content, LOCK_EX) !== false;
    }
    return copy($from, $to, $context);
}

function egmDbGlob(string $pattern, int $flags = 0): array|false
{
    $probe = preg_replace('/[*?\[\]{}].*$/', '', $pattern) ?: $pattern;
    $context = egmDatabaseRuntimeContextForPath(rtrim($probe, "\\/"));
    if ($context === null) return glob($pattern, $flags);
    $normalizedPattern = str_replace('\\', '/', egmDatabaseRuntimeNormalizeAbsolute($pattern));
    $matches = [];
    foreach (egmDatabaseRuntimePaths($context) as $relative) {
        $absolute = rtrim($context['root'], '/') . '/' . $relative;
        if (fnmatch($normalizedPattern, $absolute, PHP_OS_FAMILY === 'Windows' ? FNM_CASEFOLD : 0)) {
            $matches[] = str_replace('/', DIRECTORY_SEPARATOR, $absolute);
        }
    }
    sort($matches, SORT_STRING);
    return $matches;
}

final class EgmDatabaseStreamWrapper
{
    public $context;
    private $buffer;
    private string $path = '';
    private bool $writable = false;
    private bool $dirty = false;
    private ?PDO $lockPdo = null;
    private string $lockName = '';

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $token = parse_url($path, PHP_URL_HOST) ?: ltrim((string)parse_url($path, PHP_URL_PATH), '/');
        $registry =& egmDatabaseStreamRegistry();
        $item = is_array($registry[$token] ?? null) ? $registry[$token] : [];
        $this->path = (string)($item['path'] ?? '');
        unset($registry[$token]);
        if (($item['lockPdo'] ?? null) instanceof PDO) {
            $this->lockPdo = $item['lockPdo'];
            $this->lockName = (string)($item['lockName'] ?? '');
            $statement = $this->lockPdo->prepare('SELECT GET_LOCK(:name, 10)');
            $statement->execute([':name' => $this->lockName]);
            if ((int)$statement->fetchColumn() !== 1) return false;
            $this->buffer = fopen('php://temp/maxmemory:1048576', 'w+b');
            $this->writable = true;
            return is_resource($this->buffer);
        }
        if ($this->path === '') return false;
        $existing = egmDatabaseRuntimeRead($this->path);
        $exclusive = str_starts_with($mode, 'x');
        if ($exclusive && $existing !== null) return false;
        if (str_starts_with($mode, 'r') && $existing === null && !str_contains($mode, '+')) return false;
        $this->writable = strpbrk($mode, 'waxc+') !== false;
        $this->buffer = fopen('php://temp/maxmemory:8388608', 'w+b');
        if (!is_resource($this->buffer)) return false;
        if (!str_starts_with($mode, 'w') && $existing !== null) fwrite($this->buffer, $existing['content']);
        if (str_starts_with($mode, 'a')) fseek($this->buffer, 0, SEEK_END); else rewind($this->buffer);
        $this->dirty = $this->writable && ($existing === null || str_starts_with($mode, 'w') || $exclusive);
        return true;
    }

    public function stream_read(int $count): string|false { return fread($this->buffer, $count); }
    public function stream_write(string $data): int|false { $this->dirty = true; return fwrite($this->buffer, $data); }
    public function stream_tell(): int|false { return ftell($this->buffer); }
    public function stream_eof(): bool { return feof($this->buffer); }
    public function stream_seek(int $offset, int $whence = SEEK_SET): bool { return fseek($this->buffer, $offset, $whence) === 0; }
    public function stream_truncate(int $newSize): bool { $this->dirty = true; return ftruncate($this->buffer, $newSize); }
    public function stream_lock(int $operation): bool { return true; }
    public function stream_set_option(int $option, int $arg1, int $arg2): bool { return true; }
    public function stream_stat(): array|false { return fstat($this->buffer); }
    public function stream_flush(): bool { return $this->persist(); }
    public function stream_close(): void
    {
        $this->persist();
        if (is_resource($this->buffer)) fclose($this->buffer);
        if ($this->lockPdo instanceof PDO && $this->lockName !== '') {
            try {
                $statement = $this->lockPdo->prepare('SELECT RELEASE_LOCK(:name)');
                $statement->execute([':name' => $this->lockName]);
            } catch (Throwable $ignored) {
            }
        }
    }

    private function persist(): bool
    {
        if ($this->lockPdo instanceof PDO) return true;
        if (!$this->writable || !$this->dirty || !is_resource($this->buffer)) return true;
        $position = ftell($this->buffer);
        rewind($this->buffer);
        $content = stream_get_contents($this->buffer);
        if ($position !== false) fseek($this->buffer, $position);
        if (!is_string($content)) return false;
        $ok = egmDatabaseRuntimeWrite($this->path, $content);
        if ($ok) $this->dirty = false;
        return $ok;
    }
}

function &egmDatabaseStreamRegistry(): array
{
    static $registry = [];
    return $registry;
}

function egmDbFopen(string $filename, string $mode, bool $useIncludePath = false, $context = null)
{
    $databaseContext = egmDatabaseRuntimeContextForPath($filename);
    $lockContext = null;
    if ($databaseContext === null && str_ends_with(strtolower($filename), '.lock')) {
        $lockContext = egmDatabaseRuntimeContextForPath(substr($filename, 0, -5));
    }
    if ($databaseContext === null && $lockContext === null) {
        return $context === null ? fopen($filename, $mode, $useIncludePath) : fopen($filename, $mode, $useIncludePath, $context);
    }
    if (!in_array('egmdb', stream_get_wrappers(), true)) stream_wrapper_register('egmdb', EgmDatabaseStreamWrapper::class);
    $token = bin2hex(random_bytes(12));
    $registry =& egmDatabaseStreamRegistry();
    $registry[$token] = $lockContext !== null
        ? [
            'lockPdo' => $lockContext['pdo'],
            'lockName' => egmDatabaseRuntimeLockName(
                'egm-file',
                $lockContext['code'] . '|' . $lockContext['relative']
            ),
        ]
        : ['path' => $filename];
    return fopen('egmdb://' . $token, $mode);
}
