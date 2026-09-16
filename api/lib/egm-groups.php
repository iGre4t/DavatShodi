<?php
declare(strict_types=1);

const EGM_GROUPS_STORAGE_KEY = 'guest_groups';

function egmGroupsCleanId($value): string
{
    $id = strtolower(trim((string)$value));
    $id = preg_replace('/[^a-z0-9_-]+/', '-', $id) ?? '';
    return trim(substr($id, 0, 64), '-_');
}

/** @return array<int,array{id:string,title:string,outputs:array<int,string>}> */
function egmGroupsRead(array $context): array
{
    $stored = egmInstanceReadData($context['pdo'], (string)$context['code'], EGM_GROUPS_STORAGE_KEY, []);
    $rows = is_array($stored['groups'] ?? null) ? $stored['groups'] : (is_array($stored) ? $stored : []);
    $result = [];
    $seen = [];
    foreach (array_slice($rows, 0, 100) as $row) {
        if (!is_array($row)) continue;
        $id = egmGroupsCleanId($row['id'] ?? '');
        $title = trim((string)($row['title'] ?? ''));
        if ($id === '' || $title === '' || isset($seen[$id])) continue;
        $outputs = [];
        foreach ((array)($row['outputs'] ?? []) as $output) {
            $output = trim((string)$output);
            if ($output === 'print_card' || preg_match('/^ticket:[a-z0-9_-]{1,64}$/D', $output) === 1) $outputs[$output] = true;
        }
        $result[] = ['id' => $id, 'title' => function_exists('mb_substr') ? mb_substr($title, 0, 100) : substr($title, 0, 100), 'outputs' => array_keys($outputs)];
        $seen[$id] = true;
    }
    return $result;
}

function egmGroupsWrite(array $context, array $groups): void
{
    egmInstanceWriteData($context['pdo'], (string)$context['code'], EGM_GROUPS_STORAGE_KEY, [
        'version' => 1,
        'groups' => array_values($groups),
        'updatedAt' => gmdate('c'),
    ]);
}

function egmGroupsFind(array $context, string $groupId): ?array
{
    $groupId = egmGroupsCleanId($groupId);
    foreach (egmGroupsRead($context) as $group) {
        if ($group['id'] === $groupId) return $group;
    }
    return null;
}

/** @return array<int,array{id:string,title:string}> */
function egmGroupsTicketOptions(array $context): array
{
    $settings = egmInstanceReadData($context['pdo'], (string)$context['code'], 'settings', []);
    $ticketSettings = is_array($settings['customNumberTicketSettings'] ?? null) ? $settings['customNumberTicketSettings'] : [];
    $tickets = function_exists('egmCheckInTicketDefinitions')
        ? egmCheckInTicketDefinitions($ticketSettings)
        : array_values(array_filter(array_map(static function ($row): ?array {
            if (!is_array($row)) return null;
            $id = egmGroupsCleanId($row['id'] ?? '');
            $title = trim((string)($row['title'] ?? ''));
            return $id !== '' && $title !== '' ? ['id' => $id, 'title' => $title] : null;
        }, (array)($ticketSettings['tickets'] ?? []))));
    return $tickets !== [] ? $tickets : [['id' => 'default', 'title' => 'Custom Number Ticket']];
}

function egmGroupsValidateMembership(array $context, $value): string
{
    $id = egmGroupsCleanId($value);
    if ($id === '') return '';
    if (!egmGroupsFind($context, $id)) throw new InvalidArgumentException('گروه انتخاب‌شده وجود ندارد.');
    return $id;
}

function egmGroupsMembershipForGuest(array $context, string $guestCode): ?array
{
    $guestCode = function_exists('egmCheckInNormalizeGuestCode')
        ? egmCheckInNormalizeGuestCode($guestCode)
        : preg_replace('/\D+/', '', $guestCode);
    $period = is_array($context['period'] ?? null) ? $context['period'] : null;
    if ($guestCode === '' || !is_array($period)) return null;
    $periodCode = function_exists('egmCheckInPeriodCode') ? egmCheckInPeriodCode($period) : trim((string)($period['code'] ?? ''));
    if ($periodCode === '') return null;

    $usersTable = (string)($context['tables']['users'] ?? '');
    $periodsTable = (string)($context['tables']['user_periods'] ?? '');
    if ($usersTable === '' || $periodsTable === '') return null;
    $statement = $context['pdo']->prepare(
        "SELECT p.`group_id` FROM `{$periodsTable}` p INNER JOIN `{$usersTable}` u ON u.`id`=p.`user_id` " .
        "WHERE p.`period_code`=:period_code AND (u.`national_id`=:national_id OR u.`work_id`=:work_id OR u.`guest_number`=:guest_number) LIMIT 1"
    );
    $statement->execute([':period_code' => $periodCode, ':national_id' => $guestCode, ':work_id' => $guestCode, ':guest_number' => $guestCode]);
    $groupId = egmGroupsCleanId($statement->fetchColumn());
    return $groupId !== '' ? egmGroupsFind($context, $groupId) : null;
}

function egmGroupsApplyPrintPolicy(array $context, array $profile, string $guestCode): array
{
    $group = egmGroupsMembershipForGuest($context, $guestCode);
    if (!is_array($group)) {
        $profile['group_policy_applied'] = false;
        return $profile;
    }

    $enabled = array_fill_keys((array)($group['outputs'] ?? []), true);
    $tickets = array_values(array_filter((array)($profile['tickets'] ?? []), static function ($ticket) use ($enabled): bool {
        return is_array($ticket) && isset($enabled['ticket:' . (string)($ticket['id'] ?? '')]);
    }));
    $printCard = isset($enabled['print_card']);
    // Group output choices are authoritative and automatically queued. Global
    // enable/disable and copy-count switches apply only to ungrouped guests.
    $profile['auto_print'] = $printCard || $tickets !== [];
    $profile['double_print'] = false;
    $profile['ticket_active'] = $tickets !== [];
    $profile['ticket_only'] = !$printCard && $tickets !== [];
    $profile['tickets'] = $tickets;
    $profile['group_policy_applied'] = true;
    $profile['group_id'] = (string)$group['id'];
    $profile['group_title'] = (string)$group['title'];
    return $profile;
}

function egmGroupsJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function handleEgmGroupsRequest(string $missionDir, bool $canManage = true): never
{
    try {
        $context = egmPeriodInvitesContext($missionDir);
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $input = $method === 'POST' ? json_decode((string)file_get_contents('php://input'), true) : $_GET;
        if (!is_array($input)) $input = [];
        $action = strtolower(trim((string)($input['action'] ?? 'list')));
        if ($method === 'GET' || $action === 'list') {
            egmGroupsJson(['status' => 'ok', 'groups' => egmGroupsRead($context), 'tickets' => egmGroupsTicketOptions($context)]);
        }
        if (!$canManage) egmGroupsJson(['status' => 'error', 'message' => 'شما اجازه مدیریت گروه‌ها را ندارید.'], 403);
        if (!egmSecurityIsValidCsrfToken(trim((string)($input['csrf'] ?? '')))) {
            egmGroupsJson(['status' => 'error', 'message' => 'توکن امنیتی نامعتبر است.'], 403);
        }
        $groups = egmGroupsRead($context);
        if ($action === 'save') {
            $id = egmGroupsCleanId($input['id'] ?? '');
            $title = trim((string)($input['title'] ?? ''));
            if ($title === '') throw new InvalidArgumentException('عنوان گروه الزامی است.');
            if ($id === '') $id = 'group-' . substr(bin2hex(random_bytes(8)), 0, 12);
            $allowed = ['print_card' => true];
            foreach (egmGroupsTicketOptions($context) as $ticket) $allowed['ticket:' . $ticket['id']] = true;
            $outputs = [];
            foreach ((array)($input['outputs'] ?? []) as $output) {
                $output = trim((string)$output);
                if (isset($allowed[$output])) $outputs[$output] = true;
            }
            $next = ['id' => $id, 'title' => function_exists('mb_substr') ? mb_substr($title, 0, 100) : substr($title, 0, 100), 'outputs' => array_keys($outputs)];
            $found = false;
            foreach ($groups as $index => $group) {
                if ($group['id'] === $id) { $groups[$index] = $next; $found = true; break; }
            }
            if (!$found) $groups[] = $next;
            egmGroupsWrite($context, $groups);
            egmGroupsJson(['status' => 'ok', 'group' => $next, 'groups' => $groups, 'message' => 'گروه ذخیره شد.']);
        }
        if ($action === 'delete') {
            $id = egmGroupsCleanId($input['id'] ?? '');
            if ($id === '') throw new InvalidArgumentException('گروه معتبر نیست.');
            $groups = array_values(array_filter($groups, static fn(array $group): bool => $group['id'] !== $id));
            $table = (string)$context['tables']['user_periods'];
            $statement = $context['pdo']->prepare("UPDATE `{$table}` SET `group_id`=NULL WHERE `group_id`=:group_id");
            $statement->execute([':group_id' => $id]);
            egmGroupsWrite($context, $groups);
            egmGroupsJson(['status' => 'ok', 'groups' => $groups, 'message' => 'گروه حذف و عضویت‌های آن پاک شد.']);
        }
        egmGroupsJson(['status' => 'error', 'message' => 'عملیات گروه پشتیبانی نمی‌شود.'], 400);
    } catch (InvalidArgumentException $error) {
        egmGroupsJson(['status' => 'error', 'message' => $error->getMessage()], 422);
    } catch (Throwable $error) {
        error_log('EGM groups failed: ' . $error->getMessage());
        egmGroupsJson(['status' => 'error', 'message' => 'مدیریت گروه‌ها ناموفق بود.'], 500);
    }
}
