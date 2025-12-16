<?php
declare(strict_types=1);

session_start();

date_default_timezone_set('Asia/Tehran');

header('Content-Type: application/json; charset=UTF-8');

const INVITE_BASE_URL = 'https://davatshodi.ir/mci/inv';

function buildInviteLink(string $code): string
{
    $code = trim($code, '/');
    if ($code === '') {
        return '';
    }
    return INVITE_BASE_URL . '/' . $code;
}

if (empty($_SESSION['authenticated'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'You must be logged in to manage guest lists.']);
    exit;
}

$storePath = __DIR__ . '/../data/guests.json';
$eventsRoot = __DIR__ . '/../events';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $eventName = trim((string)($_POST['event_name'] ?? ''));
    $eventDate = trim((string)($_POST['event_date'] ?? ''));
    $mappingPayload = (string)($_POST['mapping'] ?? '');
    $rowsPayload = (string)($_POST['rows'] ?? '');

    if ($action === 'scan_invite') {
        $nationalId = normalizeNationalId((string)($_POST['national_id'] ?? ''));
        if (strlen($nationalId) !== 10) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'National ID must be 10 digits.']);
            exit;
        }
        $store = loadGuestStore($storePath);
        $now = createNowTime();
        $nowString = $now->format('Y-m-d H:i:s');
        $todaySlugs = getTodayEventSlugs($store['events']);
        $match = findGuestByNationalIdForSlugs($store['events'], $nationalId, $todaySlugs);
        if ($match === null && empty($todaySlugs)) {
            $match = findGuestByNationalIdForSlugs($store['events'], $nationalId, []);
        }
        if ($match === null) {
            $log = appendInviteLog($store, [
                'type' => 'not_found',
                'national_id' => $nationalId,
                'event_slug' => '',
                'event_name' => '',
                'guest_name' => '',
                'invite_code' => '',
                'timestamp' => $nowString,
                'message' => 'National ID not found for today.'
            ]);
            if (!saveGuestStore($storePath, $store)) {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Failed to persist guest data.']);
                exit;
            }
            echo json_encode([
                'status' => 'ok',
                'outcome' => 'not_found',
                'log' => $log,
                'logs' => normalizeInviteLogs($store['logs'])
            ]);
            exit;
        }

        $eventIndex = $match['event_index'];
        $guestIndex = $match['guest_index'];
        $event = &$store['events'][$eventIndex];
        $guest = &$event['guests'][$guestIndex];
        $eventSlug = (string)($event['slug'] ?? '');
        $eventName = (string)($event['name'] ?? '');
        $guest['national_id'] = normalizeNationalId((string)($guest['national_id'] ?? ''));
        $inviteCode = ensureInviteCode($event, $guest);
        $entered = trim((string)($guest['date_entered'] ?? ''));
        $exited = trim((string)($guest['date_exited'] ?? ''));
        $outcome = '';
        $message = '';

        if ($entered === '') {
            $guest['date_entered'] = $nowString;
            $outcome = 'enter';
            $message = 'Guest marked as entered.';
        } elseif ($exited !== '') {
            $outcome = 'more_exit';
            $message = 'Guest already exited earlier.';
        } else {
            $enteredAt = parseDateTimeValue($entered, $now->getTimezone());
            $minutesSinceEnter = $enteredAt
                ? (($now->getTimestamp() - $enteredAt->getTimestamp()) / 60)
                : 10;
            if ($minutesSinceEnter >= 5) {
                $guest['date_exited'] = $nowString;
                $outcome = 'exit';
                $message = 'Guest marked as exited.';
            } else {
                $outcome = 'spam';
                $message = 'Repeated scan too soon after entry.';
            }
        }

        $guestName = trim(
            (string)($guest['firstname'] ?? '') . ' ' . (string)($guest['lastname'] ?? '')
        );
        $log = appendInviteLog($store, [
            'type' => $outcome,
            'national_id' => $nationalId,
            'event_slug' => $eventSlug,
            'event_name' => $eventName,
            'guest_name' => $guestName,
            'invite_code' => $inviteCode,
            'timestamp' => $nowString,
            'date_entered' => (string)($guest['date_entered'] ?? ''),
            'date_exited' => (string)($guest['date_exited'] ?? ''),
            'message' => $message
        ]);

        if (!saveGuestStore($storePath, $store)) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to persist guest data.']);
            exit;
        }

        echo json_encode([
            'status' => 'ok',
            'outcome' => $outcome,
            'guest' => [
                'firstname' => (string)($guest['firstname'] ?? ''),
                'lastname' => (string)($guest['lastname'] ?? ''),
                'full_name' => $guestName,
                'national_id' => (string)($guest['national_id'] ?? ''),
                'invite_code' => $inviteCode,
                'date_entered' => (string)($guest['date_entered'] ?? ''),
                'date_exited' => (string)($guest['date_exited'] ?? ''),
                'event_slug' => $eventSlug,
                'event_name' => $eventName
            ],
            'log' => $log,
            'logs' => normalizeInviteLogs($store['logs'])
        ]);
        exit;
    } elseif ($action === 'add_manual_guest') {
        $eventSlug = trim((string)($_POST['event_slug'] ?? ''));
        if ($eventSlug === '') {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'Please select an event before adding a guest.']);
            exit;
        }
        $firstname = trim((string)($_POST['firstname'] ?? ''));
        $lastname = trim((string)($_POST['lastname'] ?? ''));
        $gender = trim((string)($_POST['gender'] ?? ''));
        $nationalId = normalizeNationalId((string)($_POST['national_id'] ?? ''));
        $phone = trim((string)($_POST['phone_number'] ?? ''));
        $dateEntered = trim((string)($_POST['date_entered'] ?? ''));
        $dateExited = trim((string)($_POST['date_exited'] ?? ''));
        if ($firstname === '' || $lastname === '' || $gender === '' || $nationalId === '' || $phone === '') {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'All guest fields are required.']);
            exit;
        }
        $store = loadGuestStore($storePath);
        $eventIndex = findEventIndexBySlug($store['events'], $eventSlug);
        if ($eventIndex < 0) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Selected event was not found.']);
            exit;
        }
        $eventNameFromPost = trim((string)($_POST['event_name'] ?? ''));
        $eventDateFromPost = trim((string)($_POST['event_date'] ?? ''));
        $storeEvent =& $store['events'][$eventIndex];
        $eventName = (string)($storeEvent['name'] ?? '');
        $eventDate = (string)($storeEvent['date'] ?? '');
        if ($eventName === '' && $eventNameFromPost !== '') {
            $eventName = $eventNameFromPost;
            $storeEvent['name'] = $eventName;
        }
        if ($eventDate === '' && $eventDateFromPost !== '') {
            $eventDate = $eventDateFromPost;
            $storeEvent['date'] = $eventDate;
        }
        if ($eventName === '' || $eventDate === '') {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'Event name and date are required.']);
            exit;
        }
        $nextNumber = getNextGuestNumber($storeEvent);
        $storeEvent['guests'][] = [
            'number' => $nextNumber,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'gender' => $gender,
            'national_id' => $nationalId,
            'phone_number' => $phone,
            'date_entered' => $dateEntered,
            'date_exited' => $dateExited
        ];
        $storeEvent['guest_count'] = count($storeEvent['guests']);
        $storeEvent['updated_at'] = date('c');
        if (!syncEventPurelist($storeEvent, $eventSlug, $eventsRoot)) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to regenerate pure list for the event.']);
            exit;
        }
        if (!saveGuestStore($storePath, $store)) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to persist guest data.']);
            exit;
        }
        createGuestInvitePages($store['events'][$eventIndex]['guests'] ?? []);
        echo json_encode([
            'status' => 'ok',
            'message' => 'Guest added successfully.',
            'events' => normalizeEventsForResponse($store['events']),
            'logs' => normalizeInviteLogs($store['logs'])
        ]);
        exit;
    } elseif ($action === 'update_guest') {
        $slug = trim((string)($_POST['event_slug'] ?? ''));
        $number = (int)($_POST['number'] ?? 0);
        if ($slug === '' || $number <= 0) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'Invalid guest reference.']);
            exit;
        }
        $store = loadGuestStore($storePath);
        $eventIndex = findEventIndexBySlug($store['events'], $slug);
        if ($eventIndex < 0) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Event not found.']);
            exit;
        }
        $guestIndex = findGuestIndexByNumber($store['events'][$eventIndex]['guests'] ?? [], $number);
        if ($guestIndex < 0) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Guest not found.']);
            exit;
        }
        $store['events'][$eventIndex]['guests'][$guestIndex] = array_merge(
            $store['events'][$eventIndex]['guests'][$guestIndex],
            [
                'firstname' => trim((string)($_POST['firstname'] ?? '')),
                'lastname' => trim((string)($_POST['lastname'] ?? '')),
                'gender' => trim((string)($_POST['gender'] ?? '')),
                'national_id' => normalizeNationalId((string)($_POST['national_id'] ?? '')),
                'phone_number' => trim((string)($_POST['phone_number'] ?? '')),
                'date_entered' => trim((string)($_POST['date_entered'] ?? '')),
                'date_exited' => trim((string)($_POST['date_exited'] ?? ''))
            ]
        );
        $store['events'][$eventIndex]['updated_at'] = date('c');
        if (!saveGuestStore($storePath, $store)) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to persist guest data.']);
            exit;
        }
        echo json_encode([
            'status' => 'ok',
            'message' => 'Guest updated successfully.',
            'events' => normalizeEventsForResponse($store['events']),
            'logs' => normalizeInviteLogs($store['logs'])
        ]);
        exit;
    } elseif ($action === 'delete_guest') {
        $slug = trim((string)($_POST['event_slug'] ?? ''));
        $number = (int)($_POST['number'] ?? 0);
        if ($slug === '' || $number <= 0) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'Invalid guest reference.']);
            exit;
        }
        $store = loadGuestStore($storePath);
        $eventIndex = findEventIndexBySlug($store['events'], $slug);
        if ($eventIndex < 0) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Event not found.']);
            exit;
        }
        $guestIndex = findGuestIndexByNumber($store['events'][$eventIndex]['guests'] ?? [], $number);
        if ($guestIndex < 0) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Guest not found.']);
            exit;
        }
        array_splice($store['events'][$eventIndex]['guests'], $guestIndex, 1);
        $store['events'][$eventIndex]['guest_count'] = count($store['events'][$eventIndex]['guests']);
        $store['events'][$eventIndex]['updated_at'] = date('c');
        if (!saveGuestStore($storePath, $store)) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to persist guest data.']);
            exit;
        }
        echo json_encode([
            'status' => 'ok',
            'message' => 'Guest deleted successfully.',
            'events' => normalizeEventsForResponse($store['events']),
            'logs' => normalizeInviteLogs($store['logs'])
        ]);
        exit;
    } elseif ($action !== 'save_guest_purelist') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Unsupported action.']);
        exit;
    }

    if ($eventName === '' || $eventDate === '') {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Event name and date are required.']);
        exit;
    }

    $mapping = json_decode($mappingPayload, true);
    $rows = json_decode($rowsPayload, true);
    if (!is_array($mapping) || !is_array($rows)) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Invalid mapping or rows data.']);
        exit;
    }

    $requiredKeys = ['firstname', 'lastname', 'gender', 'national_id', 'phone_number'];
    foreach ($requiredKeys as $key) {
        if (!isset($mapping[$key]) || trim((string)$mapping[$key]) === '') {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'All guest fields must be mapped to a column.']);
            exit;
        }
    }

    $rows = array_values(array_filter(array_map(static function ($row) {
        return is_array($row) ? $row : null;
    }, $rows)));

    if (empty($rows)) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'No guest rows were provided.']);
        exit;
    }

    $store = loadGuestStore($storePath);
    $slug = ensureUniqueSlug(slugify($eventName), $store['events']);
    $eventDir = $eventsRoot . '/' . $slug;
    if (!is_dir($eventDir) && !mkdir($eventDir, 0755, true) && !is_dir($eventDir)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Unable to create event directory.']);
        exit;
    }

    $uploadedFileInfo = null;
    if (isset($_FILES['guest_file']) && is_array($_FILES['guest_file'])) {
        $file = $_FILES['guest_file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name'])) {
            $ext = strtolower((string)pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
            $targetName = 'source' . ($ext ? '.' . $ext : '');
            $targetPath = $eventDir . '/' . $targetName;
            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Failed to store the uploaded file.']);
                exit;
            }
            $uploadedFileInfo = [
                'filename' => $targetName,
                'path' => 'events/' . $slug . '/' . $targetName
            ];
        }
    }

    $guests = array_map(static function (array $row) use ($mapping, $requiredKeys) {
        $entry = [];
        foreach ($requiredKeys as $key) {
            $column = (string)$mapping[$key];
            $value = isset($row[$column]) ? trim((string)$row[$column]) : '';
            if ($key === 'national_id') {
                $value = normalizeNationalId($value);
            }
            $entry[$key] = $value;
        }
        return $entry;
    }, $rows);

    // Add sequential numbers starting from 1 for this pure list.
    foreach ($guests as $idx => &$guest) {
        $guest['number'] = $idx + 1;
        $guest['date_entered'] = $guest['date_entered'] ?? '';
        $guest['date_exited'] = $guest['date_exited'] ?? '';
    }
    unset($guest);

    $guests = array_values(array_filter($guests, static function ($guest) {
        if (!is_array($guest)) {
            return false;
        }
        foreach ($guest as $value) {
            if (trim((string)$value) !== '') {
                return true;
            }
        }
        return false;
    }));

    foreach ($guests as &$guest) {
        ensureInviteCode(null, $guest);
    }
    unset($guest);

    if (empty($guests)) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Guest data is empty after mapping.']);
        exit;
    }

    $csvGuests = [];
    foreach ($guests as $guest) {
        $guestRow = $guest;
        ensureInviteCode(null, $guestRow);
        $code = (string)($guestRow['invite_code'] ?? '');
        $guestRow['sms_link'] = buildInviteLink($code);
        $csvGuests[] = $guestRow;
    }

    $csvHeaders = array_merge(['number'], $requiredKeys, ['sms_link', 'date_entered', 'date_exited']);
    $csvContent = buildCsv($csvGuests, $csvHeaders);
    $purelistFilename = 'purelist.csv';
    $purelistPath = $eventDir . '/' . $purelistFilename;
    if (file_put_contents($purelistPath, $csvContent) === false) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to save the pure list file.']);
        exit;
    }

    $existingIndex = findEventIndexBySlug($store['events'], $slug);
    $eventRecord = [
        'slug' => $slug,
        'name' => $eventName,
        'date' => $eventDate,
        'mapping' => $mapping,
        'purelist' => 'events/' . $slug . '/' . $purelistFilename,
        'guest_count' => count($guests),
        'guests' => $guests,
        'updated_at' => date('c')
    ];
    if ($uploadedFileInfo) {
        $eventRecord['source'] = $uploadedFileInfo;
    }
    $eventIndex = $existingIndex;
    if ($existingIndex >= 0) {
        $eventRecord['created_at'] = $store['events'][$existingIndex]['created_at'] ?? $eventRecord['updated_at'];
        $store['events'][$existingIndex] = array_merge($store['events'][$existingIndex], $eventRecord);
        $eventIndex = $existingIndex;
    } else {
        $eventRecord['created_at'] = $eventRecord['updated_at'];
        $store['events'][] = $eventRecord;
        $eventIndex = count($store['events']) - 1;
    }

    if (!saveGuestStore($storePath, $store)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to persist guest data.']);
        exit;
    }

    createGuestInvitePages($store['events'][$eventIndex]['guests'] ?? []);

    echo json_encode([
        'status' => 'ok',
        'message' => 'Guest list saved successfully.',
        'events' => normalizeEventsForResponse($store['events']),
        'logs' => normalizeInviteLogs($store['logs'])
    ]);
    exit;
}

$store = loadGuestStore($storePath);
echo json_encode([
    'status' => 'ok',
    'events' => normalizeEventsForResponse($store['events']),
    'logs' => normalizeInviteLogs($store['logs'])
]);
exit;

function loadGuestStore(string $path): array
{
    if (!is_file($path)) {
        return ['events' => [], 'logs' => []];
    }
    $content = file_get_contents($path);
    if ($content === false) {
        return ['events' => [], 'logs' => []];
    }
    $decoded = json_decode($content, true);
    return normalizeStore(is_array($decoded) ? $decoded : []);
}

function saveGuestStore(string $path, array $data): bool
{
    $data = normalizeStore($data);
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        return false;
    }
    return file_put_contents($path, $encoded) !== false;
}

function slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9\-_\s]+/', '', $value) ?? '';
    $value = preg_replace('/[\s_]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? $value : 'event';
}

function ensureUniqueSlug(string $slug, array $events): string
{
    $existingIndex = findEventIndexBySlug($events, $slug);
    if ($existingIndex >= 0) {
        return $slug;
    }
    $existing = array_fill_keys(array_map(static function ($event) {
        return (string)($event['slug'] ?? '');
    }, $events), true);
    $candidate = $slug;
    $suffix = 1;
    while (isset($existing[$candidate]) && $existing[$candidate] === true) {
        $candidate = $slug . '-' . $suffix;
        $suffix++;
    }
    return $candidate;
}

function findEventIndexBySlug(array $events, string $slug): int
{
    foreach ($events as $index => $event) {
        if (!is_array($event)) {
            continue;
        }
        if ((string)($event['slug'] ?? '') === $slug) {
            return (int)$index;
        }
    }
    return -1;
}

function buildCsv(array $guests, array $headers): string
{
    $lines = [];
    $lines[] = implode(',', array_map('escapeCsv', $headers));
    foreach ($guests as $guest) {
        $row = [];
        foreach ($headers as $key) {
            $row[] = escapeCsv($guest[$key] ?? '');
        }
        $lines[] = implode(',', $row);
    }
    return implode("\n", $lines);
}

function escapeCsv($value): string
{
    $value = (string)$value;
    $needsQuotes = strpbrk($value, "\",\n\r") !== false;
    $escaped = str_replace('"', '""', $value);
    return $needsQuotes ? '"' . $escaped . '"' : $escaped;
}

function syncEventPurelist(array &$event, string $slug, string $eventsRoot): bool
{
    $eventDir = $eventsRoot . '/' . $slug;
    if (!is_dir($eventDir) && !mkdir($eventDir, 0755, true) && !is_dir($eventDir)) {
        return false;
    }
    $guests = is_array($event['guests'] ?? null) ? array_values($event['guests']) : [];
    foreach ($guests as &$guest) {
        if (!is_array($guest)) {
            $guest = [];
        }
        $guest['national_id'] = normalizeNationalId((string)($guest['national_id'] ?? ''));
        ensureInviteCode($event, $guest);
        $code = (string)($guest['invite_code'] ?? '');
        $guest['sms_link'] = buildInviteLink($code);
        $guest['date_entered'] = (string)($guest['date_entered'] ?? '');
        $guest['date_exited'] = (string)($guest['date_exited'] ?? '');
    }
    unset($guest);
    $headers = ['number', 'firstname', 'lastname', 'gender', 'national_id', 'phone_number', 'sms_link', 'date_entered', 'date_exited'];
    $csvContent = buildCsv($guests, $headers);
    $purelistPath = $eventDir . '/purelist.csv';
    if (file_put_contents($purelistPath, $csvContent) === false) {
        return false;
    }
    $event['purelist'] = 'events/' . $slug . '/purelist.csv';
    return true;
}

function normalizeDigitString(string $value): string
{
    $map = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'
    ];
    $normalized = strtr($value, $map);
    return preg_replace('/\D+/', '', $normalized) ?? '';
}

function normalizeNationalId(string $value): string
{
    $digits = normalizeDigitString($value);
    if ($digits === '') {
        return '';
    }
    if (strlen($digits) < 10) {
        $digits = str_pad($digits, 10, '0', STR_PAD_LEFT);
    }
    return $digits;
}

function getNextGuestNumber(array $event): int
{
    $max = 0;
    foreach ($event['guests'] ?? [] as $guest) {
        $num = (int)($guest['number'] ?? 0);
        if ($num > $max) {
            $max = $num;
        }
    }
    return $max + 1;
}

function findGuestIndexByNumber(array $guests, int $number): int
{
    foreach ($guests as $index => $guest) {
        if ((int)($guest['number'] ?? 0) === $number) {
            return (int)$index;
        }
    }
    return -1;
}

function normalizeDateDigits(string $value): string
{
    return normalizeDigitString($value);
}

function createNowTime(): DateTimeImmutable
{
    $tzName = date_default_timezone_get() ?: 'Asia/Tehran';
    return new DateTimeImmutable('now', new DateTimeZone($tzName));
}

function parseDateTimeValue(string $value, DateTimeZone $tz): ?DateTimeImmutable
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $trimmed, $tz);
    if ($dt instanceof DateTimeImmutable) {
        return $dt;
    }
    try {
        return new DateTimeImmutable($trimmed, $tz);
    } catch (\Exception $e) {
        return null;
    }
}

function getTodayDateCandidates(): array
{
    $tz = new DateTimeZone(date_default_timezone_get() ?: 'Asia/Tehran');
    $now = new DateTimeImmutable('now', $tz);
    $candidates = [$now->format('Ymd')];
    if (class_exists('IntlDateFormatter')) {
        $formatter = new IntlDateFormatter(
            'fa_IR@calendar=persian',
            IntlDateFormatter::SHORT,
            IntlDateFormatter::NONE,
            $tz,
            IntlDateFormatter::TRADITIONAL,
            'yyyy/MM/dd'
        );
        if ($formatter !== false) {
            $formatted = $formatter->format($now);
            if (is_string($formatted)) {
                $candidates[] = normalizeDateDigits($formatted);
            }
        }
    }
    return array_values(array_filter(array_unique($candidates)));
}

function isEventDateToday(string $eventDate): bool
{
    $normalized = normalizeDateDigits($eventDate);
    if ($normalized === '') {
        return true;
    }
    foreach (getTodayDateCandidates() as $candidate) {
        if ($candidate !== '' && $normalized === $candidate) {
            return true;
        }
    }
    return false;
}

function getTodayEventSlugs(array $events): array
{
    $slugs = [];
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        if (isEventDateToday((string)($event['date'] ?? ''))) {
            $slugs[] = (string)($event['slug'] ?? '');
        }
    }
    return array_values(array_filter(array_unique($slugs)));
}

function findGuestByNationalIdForSlugs(array $events, string $nationalId, array $allowedSlugs = []): ?array
{
    $allowed = array_filter($allowedSlugs, static function ($value) {
        return trim((string)$value) !== '';
    });
    foreach ($events as $eventIndex => $event) {
        if (!is_array($event)) {
            continue;
        }
        $slug = (string)($event['slug'] ?? '');
        if (!empty($allowed) && !in_array($slug, $allowed, true)) {
            continue;
        }
        foreach ($event['guests'] ?? [] as $guestIndex => $guest) {
            $guestId = normalizeNationalId((string)($guest['national_id'] ?? ''));
            if ($guestId !== '' && $guestId === $nationalId) {
                return [
                    'event_index' => (int)$eventIndex,
                    'guest_index' => (int)$guestIndex
                ];
            }
        }
    }
    return null;
}

function generateInviteCode(array $guest): string
{
    $number = (int)($guest['number'] ?? 0);
    if ($number <= 0) {
        $number = 1;
    }
    if ($number > 9999) {
        $number = 9999;
    }
    return sprintf('%04d', $number);
}

function ensureInviteCode($event, array &$guest): string
{
    $code = (string)($guest['invite_code'] ?? '');
    if (!preg_match('/^\d{4}$/', $code)) {
        $code = generateInviteCode($guest);
        $guest['invite_code'] = $code;
    }
    return $code;
}

function normalizeInviteCodeDigits(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value);
    if ($digits === null || $digits === '') {
        return '';
    }
    $digits = substr($digits, -4);
    return str_pad($digits, 4, '0', STR_PAD_LEFT);
}

function normalizeStore(array $store): array
{
    $store['events'] = is_array($store['events'] ?? null) ? array_values($store['events']) : [];
    foreach ($store['events'] as &$event) {
        if (!is_array($event)) {
            $event = [];
        }
        $event['slug'] = (string)($event['slug'] ?? '');
        $event['guests'] = is_array($event['guests'] ?? null) ? array_values($event['guests']) : [];
        foreach ($event['guests'] as $idx => &$guest) {
            if (!is_array($guest)) {
                $guest = [];
            }
            if (!isset($guest['number']) || (int)($guest['number'] ?? 0) <= 0) {
                $guest['number'] = $idx + 1;
            }
            $guest['national_id'] = normalizeNationalId((string)($guest['national_id'] ?? ''));
            $guest['date_entered'] = (string)($guest['date_entered'] ?? '');
            $guest['date_exited'] = (string)($guest['date_exited'] ?? '');
            $guest['invite_code'] = ensureInviteCode($event, $guest);
        }
        unset($guest);
        $event['guest_count'] = count($event['guests']);
    }
    unset($event);
    $store['logs'] = is_array($store['logs'] ?? null) ? array_values($store['logs']) : [];
    return $store;
}

function appendInviteLog(array &$store, array $log): array
{
    $log = normalizeInviteLog($log);
    $store['logs'][] = $log;
    if (count($store['logs']) > 200) {
        $store['logs'] = array_slice($store['logs'], -200);
    }
    return $log;
}

function normalizeInviteLog(array $log): array
{
    $log['id'] = (string)($log['id'] ?? uniqid('log_', true));
    $log['type'] = (string)($log['type'] ?? '');
    $log['national_id'] = normalizeNationalId((string)($log['national_id'] ?? ''));
    $log['event_slug'] = (string)($log['event_slug'] ?? '');
    $log['event_name'] = (string)($log['event_name'] ?? '');
    $log['guest_name'] = trim((string)($log['guest_name'] ?? ''));
    $log['invite_code'] = normalizeInviteCodeDigits((string)($log['invite_code'] ?? ''));
    $log['timestamp'] = (string)($log['timestamp'] ?? date('Y-m-d H:i:s'));
    $log['date_entered'] = (string)($log['date_entered'] ?? '');
    $log['date_exited'] = (string)($log['date_exited'] ?? '');
    $log['message'] = (string)($log['message'] ?? '');
    return $log;
}

function normalizeInviteLogs(array $logs): array
{
    $normalized = array_map('normalizeInviteLog', $logs);
    usort($normalized, static function ($a, $b) {
        return strcmp((string)($b['timestamp'] ?? ''), (string)($a['timestamp'] ?? ''));
    });
    return array_values($normalized);
}

function normalizeEventsForResponse(array $events): array
{
    return array_values(array_map(static function ($event) {
        $guests = is_array($event['guests'] ?? null) ? array_values($event['guests']) : [];
        foreach ($guests as &$guest) {
            if (!is_array($guest)) {
                $guest = [];
                continue;
            }
            $guest['national_id'] = normalizeNationalId((string)($guest['national_id'] ?? ''));
            $guest['invite_code'] = ensureInviteCode($event, $guest);
            $guest['date_entered'] = $guest['date_entered'] ?? '';
            $guest['date_exited'] = $guest['date_exited'] ?? '';
        }
        unset($guest);
        return [
            'slug' => (string)($event['slug'] ?? ''),
            'name' => (string)($event['name'] ?? ''),
            'date' => (string)($event['date'] ?? ''),
            'guest_count' => (int)($event['guest_count'] ?? count($guests)),
            'mapping' => is_array($event['mapping'] ?? null) ? $event['mapping'] : [],
            'purelist' => (string)($event['purelist'] ?? ''),
            'source' => is_array($event['source'] ?? null) ? $event['source'] : null,
            'guests' => $guests,
            'created_at' => (string)($event['created_at'] ?? ''),
            'updated_at' => (string)($event['updated_at'] ?? '')
        ];
    }, $events));
}

function createGuestInvitePages(array $guests): void
{
    $invRoot = __DIR__ . '/../inv';
    if (!is_dir($invRoot)) {
        if (!mkdir($invRoot, 0755, true) && !is_dir($invRoot)) {
            return;
        }
    } else {
        clearGuestInviteDirectories($invRoot);
    }
    $imageName = 'Invite Card Picture.jpg';
    $cardImagePath = __DIR__ . '/../events/eventcard/' . $imageName;
    $imageUrl = '/events/eventcard/' . rawurlencode($imageName);
    if (is_file($cardImagePath)) {
        $content = @file_get_contents($cardImagePath);
        if ($content !== false) {
            $mime = mime_content_type($cardImagePath) ?: 'image/jpeg';
            $imageUrl = 'data:' . $mime . ';base64,' . base64_encode($content);
        }
    }
    foreach ($guests as $guest) {
        $code = trim((string)($guest['invite_code'] ?? ''));
        if ($code === '') {
            continue;
        }
        $guestDir = $invRoot . '/' . $code;
        if (!is_dir($guestDir) && !mkdir($guestDir, 0755, true) && !is_dir($guestDir)) {
            continue;
        }
        $fullName = trim((string)($guest['firstname'] ?? '') . ' ' . (string)($guest['lastname'] ?? ''));
        if ($fullName === '') {
            $fullName = 'Guest';
        }
        $safeName = htmlspecialchars($fullName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeCode = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $nationalId = normalizeNationalId((string)($guest['national_id'] ?? ''));
        $safeNationalId = htmlspecialchars($nationalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $qrElement = '';
        if ($nationalId !== '') {
            $qrSrc = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&margin=8&data=' . rawurlencode($nationalId);
            $qrElement = "<img class="qr" src="{$qrSrc}" alt="QR ???? {$safeName}">";
        }
        $page = <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light">
  <title>???? ???? ?????? ????? ?? ???? ????</title>
  <link rel="icon" id="site-icon" href="data:,">
  <link rel="preload" href="/style/fonts/PeydaWebFaNum-Regular.woff2" as="font" type="font/woff2" crossorigin="anonymous">
  <link rel="preload" href="/style/fonts/PeydaWebFaNum-Bold.woff2" as="font" type="font/woff2" crossorigin="anonymous">
  <link rel="stylesheet" href="/style/invite-card.css">
  <script src="/General%20Setting/general-settings.js" defer></script>
  <script>
    (function () {
      const iconEl = document.getElementById('site-icon');
      const applyIcon = () => {
        if (!iconEl) {
          return;
        }
        const iconUrl = window.GENERAL_SETTINGS?.siteIcon;
        if (iconUrl) {
          iconEl.href = iconUrl;
        }
      };
      if (window.GENERAL_SETTINGS) {
        applyIcon();
      } else {
        window.addEventListener('load', applyIcon);
      }
    })();
  </script>
</head>
<body>
  <div class="device">
    <div class="card-image-shell">
      <img src="{$imageUrl}" alt="???? ???? ??????">
    </div>
    <div class="message">
      <p class="greeting">???? ???? ?????? ????? ?? ???? ????</p>
      <p class="name">{$safeName}</p>
      {$qrElement}
      <p class="code">{$safeCode}</p>
    </div>
  </div>
</body>
</html>
HTML;
    }
}

function clearGuestInviteDirectories(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            removeDirectoryTree($path);
        } else {
            @unlink($path);
        }
    }
}

function removeDirectoryTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = scandir($path);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $path . '/' . $item;
        if (is_dir($child)) {
            removeDirectoryTree($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
}
