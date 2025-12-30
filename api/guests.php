<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../lib/event_entrypoints.php';
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

date_default_timezone_set('Asia/Tehran');

header('Content-Type: application/json; charset=UTF-8');

const INVITE_BASE_URL = 'https://davatshodi.ir/l/inv';
const PURELIST_HEADERS = ['number', 'firstname', 'lastname', 'gender', 'national_id', 'phone_number', 'sms_link', 'join_date', 'join_time', 'left_date', 'left_time'];
const EVENT_CODE_MIN = 10000;
const EVENT_CODE_DIGITS = 5;
if (!defined('EVENTS_ROOT')) {
    define('EVENTS_ROOT', __DIR__ . '/../events');
}

ensureEventStorageReady(EVENTS_ROOT);

function ensureEventStorageReady(string $eventsRoot): void
{
    $subDirs = [
        $eventsRoot,
        $eventsRoot . '/event',
        $eventsRoot . '/eventcard'
    ];
    foreach ($subDirs as $path) {
        if (is_dir($path)) {
            continue;
        }
        if (!@mkdir($path, 0755, true) && !is_dir($path)) {
            error_log('Failed to create events path: ' . $path);
        }
    }
    $purelistPath = $eventsRoot . '/event/purelist.csv';
    if (!is_file($purelistPath)) {
        if (@file_put_contents($purelistPath, implode(',', PURELIST_HEADERS) . "\n") === false) {
            error_log('Failed to initialize purelist header: ' . $purelistPath);
        }
    }
}

function allocateEventCode(array &$store): string
{
    $next = max(EVENT_CODE_MIN, (int)($store['next_event_code'] ?? EVENT_CODE_MIN));
    $store['next_event_code'] = $next + 1;
    return str_pad((string)$next, EVENT_CODE_DIGITS, '0', STR_PAD_LEFT);
}

function allocateGuestNumber(array &$store): int
{
    $next = max(1, (int)($store['next_guest_number'] ?? 1));
    $store['next_guest_number'] = $next + 1;
    return $next;
}

function isEventCodeValid(string $value): bool
{
    return preg_match('/^\d{' . EVENT_CODE_DIGITS . ',}$/', $value) === 1;
}

function getEventDirName(array $event): string
{
    $code = trim((string)($event['code'] ?? ''));
    if ($code !== '') {
        return $code;
    }
    $fallback = trim((string)($event['slug'] ?? ''));
    return $fallback !== '' ? $fallback : 'event';
}

function ensureEventHasCode(array &$event, int &$nextEventCode): void
{
    $code = trim((string)($event['code'] ?? ''));
    if (isEventCodeValid($code)) {
        $numeric = (int)$code;
        if ($numeric >= $nextEventCode) {
            $nextEventCode = $numeric + 1;
        }
        $event['code'] = str_pad((string)$numeric, EVENT_CODE_DIGITS, '0', STR_PAD_LEFT);
        return;
    }
    $event['code'] = str_pad((string)$nextEventCode, EVENT_CODE_DIGITS, '0', STR_PAD_LEFT);
    $nextEventCode++;
}

function getEventDir(array $event, string $eventsRoot): string
{
    return $eventsRoot . '/' . getEventDirName($event);
}

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
$eventsRoot = EVENTS_ROOT;

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
        $store = loadGuestStore($storePath, $eventsRoot);
        $activeEventCode = trim((string)($store['active_event_code'] ?? ''));
        if ($activeEventCode === '') {
            http_response_code(422);
            echo json_encode([
                'status' => 'error',
            'message' => 'No active event selected. Please set the active event from the Winners tab.'
        ]);
        exit;
        }
        $activeEventIndex = findEventIndexByCode($store['events'], $activeEventCode);
        if ($activeEventIndex < 0) {
            http_response_code(422);
            echo json_encode([
                'status' => 'error',
                'message' => 'The selected active event no longer exists. Please choose another event.'
            ]);
            exit;
        }
        $now = createNowTime();
        $nowString = $now->format('Y-m-d H:i:s');
        $nowShamsiParts = formatPersianDateTimeParts($now);
        $match = findGuestByNationalIdForCodes($store['events'], $nationalId, [$activeEventCode]);
        if ($match === null) {
            $activeEventName = (string)($store['events'][$activeEventIndex]['name'] ?? '');
            $log = appendInviteLog($store, [
                'type' => 'not_found',
                'national_id' => $nationalId,
                'event_code' => $activeEventCode,
                'event_name' => $activeEventName,
                'guest_name' => '',
                'invite_code' => '',
                'timestamp' => $nowString,
                'message' => 'National ID not found in the active event list.'
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
                'logs' => normalizeInviteLogs($store['logs']),
                'stats' => computeGuestStats($store['events'][$activeEventIndex] ?? [])
            ]);
            exit;
        }

        $eventIndex = $match['event_index'];
        $guestIndex = $match['guest_index'];
        $event = &$store['events'][$eventIndex];
        $guest = &$event['guests'][$guestIndex];
        $eventCode = (string)($event['code'] ?? '');
        $eventName = (string)($event['name'] ?? '');
        $guest['national_id'] = normalizeNationalId((string)($guest['national_id'] ?? ''));
        $inviteCode = ensureInviteCode($event, $guest);
        $entered = trim((string)($guest['join_date'] ?? $guest['date_entered'] ?? ''));
        $exited = trim((string)($guest['date_exited'] ?? ''));
        $outcome = '';
        $message = '';

        if ($entered === '') {
            $guest['join_date'] = $nowShamsiParts['date'];
            $guest['join_time'] = $nowShamsiParts['time'];
            $guest['date_entered'] = composeDateTimeString($guest['join_date'], $guest['join_time']);
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
                $guest['left_date'] = $nowShamsiParts['date'];
                $guest['left_time'] = $nowShamsiParts['time'];
                $guest['date_exited'] = composeDateTimeString($guest['left_date'], $guest['left_time']);
                $outcome = 'exit';
                $message = 'Guest marked as exited.';
            } else {
                $outcome = 'spam';
                $message = 'Repeated scan too soon after entry.';
            }
        }
        normalizeGuestDateFields($guest);

        $guestName = trim(
            (string)($guest['firstname'] ?? '') . ' ' . (string)($guest['lastname'] ?? '')
        );
        $log = appendInviteLog($store, [
            'type' => $outcome,
            'national_id' => $nationalId,
            'event_code' => $eventCode,
            'event_name' => $eventName,
            'guest_name' => $guestName,
            'invite_code' => $inviteCode,
            'timestamp' => $nowString,
            'join_date' => (string)($guest['join_date'] ?? ''),
            'join_time' => (string)($guest['join_time'] ?? ''),
            'left_date' => (string)($guest['left_date'] ?? ''),
            'left_time' => (string)($guest['left_time'] ?? ''),
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
                'join_date' => (string)($guest['join_date'] ?? ''),
                'join_time' => (string)($guest['join_time'] ?? ''),
                'left_date' => (string)($guest['left_date'] ?? ''),
                'left_time' => (string)($guest['left_time'] ?? ''),
                'date_entered' => (string)($guest['date_entered'] ?? ''),
                'date_exited' => (string)($guest['date_exited'] ?? ''),
                'event_code' => $eventCode,
                'event_name' => $eventName
            ],
            'log' => $log,
            'logs' => normalizeInviteLogs($store['logs']),
            'stats' => computeGuestStats($store['events'][$activeEventIndex] ?? [])
        ]);
        exit;
    } elseif ($action === 'add_manual_guest') {
        $eventCode = trim((string)($_POST['event_code'] ?? ''));
        if ($eventCode === '') {
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
        $store = loadGuestStore($storePath, $eventsRoot);
        $eventIndex = findEventIndexByCode($store['events'], $eventCode);
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
        $manualGuest = [
            'number' => allocateGuestNumber($store),
            'firstname' => $firstname,
            'lastname' => $lastname,
            'gender' => $gender,
            'national_id' => $nationalId,
            'phone_number' => $phone,
            'date_entered' => $dateEntered,
            'date_exited' => $dateExited
        ];
        normalizeGuestDateFields($manualGuest);
        $storeEvent['guests'][] = $manualGuest;
        $storeEvent['guest_count'] = count($storeEvent['guests']);
        $storeEvent['updated_at'] = date('c');
        if (!syncEventPurelist($storeEvent, $eventsRoot)) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to regenerate pure list for the event.']);
            exit;
        }
        if (!saveGuestStore($storePath, $store)) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to persist guest data.']);
            exit;
        }
        createGuestInvitePages(
            $store['events'][$eventIndex]['guests'] ?? [],
            (string)($store['events'][$eventIndex]['code'] ?? '')
        );
        echo json_encode([
            'status' => 'ok',
            'message' => 'Guest added successfully.',
            'events' => normalizeEventsForResponse($store['events']),
            'logs' => normalizeInviteLogs($store['logs'])
        ]);
        exit;
    } elseif ($action === 'update_guest') {
        $eventCode = trim((string)($_POST['event_code'] ?? ''));
        $number = (int)($_POST['number'] ?? 0);
        if ($eventCode === '' || $number <= 0) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'Invalid guest reference.']);
            exit;
        }
        $store = loadGuestStore($storePath, $eventsRoot);
        $eventIndex = findEventIndexByCode($store['events'], $eventCode);
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
        normalizeGuestDateFields($store['events'][$eventIndex]['guests'][$guestIndex]);
        $store['events'][$eventIndex]['updated_at'] = date('c');
        if (!syncEventPurelist($store['events'][$eventIndex], $eventsRoot)) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to regenerate pure list for the event.']);
            exit;
        }
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
          $eventCode = trim((string)($_POST['event_code'] ?? ''));
          $number = (int)($_POST['number'] ?? 0);
          if ($eventCode === '' || $number <= 0) {
              http_response_code(422);
              echo json_encode(['status' => 'error', 'message' => 'Invalid guest reference.']);
              exit;
          }
          $store = loadGuestStore($storePath, $eventsRoot);
          $eventIndex = findEventIndexByCode($store['events'], $eventCode);
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
          $store['events'][$eventIndex]['join_end_time'] = $normalizedJoinEnd;
          if (!syncEventPurelist($store['events'][$eventIndex], $eventsRoot)) {
              http_response_code(500);
              echo json_encode(['status' => 'error', 'message' => 'Failed to regenerate pure list for the event.']);
              exit;
          }
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
      } elseif ($action === 'update_event') {
          $eventCode = trim((string)($_POST['code'] ?? ''));
          $name = trim((string)($_POST['name'] ?? ''));
          $date = trim((string)($_POST['date'] ?? ''));
          $joinStartTime = trim((string)($_POST['join_start_time'] ?? ''));
          $joinLimitTime = trim((string)($_POST['join_limit_time'] ?? ''));
          $joinEndTime = trim((string)($_POST['join_end_time'] ?? ''));
          if ($eventCode === '' || $name === '' || $date === '') {
              http_response_code(422);
              echo json_encode(['status' => 'error', 'message' => 'Event code, name, and date are required.']);
              exit;
          }
          $normalizedJoinStart = $joinStartTime === '' ? '' : formatEventTimeValue($joinStartTime);
          if ($joinStartTime !== '' && $normalizedJoinStart === '') {
              http_response_code(422);
              echo json_encode(['status' => 'error', 'message' => 'Join start time must be a valid 24-hour time.']);
              exit;
          }
          $normalizedJoinLimit = $joinLimitTime === '' ? '' : formatEventTimeValue($joinLimitTime);
          if ($joinLimitTime !== '' && $normalizedJoinLimit === '') {
              http_response_code(422);
              echo json_encode(['status' => 'error', 'message' => 'Join limit time must be a valid 24-hour time.']);
              exit;
          }
          $normalizedJoinEnd = $joinEndTime === '' ? '' : formatEventTimeValue($joinEndTime);
          if ($joinEndTime !== '' && $normalizedJoinEnd === '') {
              http_response_code(422);
              echo json_encode(['status' => 'error', 'message' => 'Event end time must be a valid 24-hour time.']);
              exit;
          }
          if ($normalizedJoinStart !== '' && $normalizedJoinLimit !== '') {
              $startMinutes = getEventTimeMinutes($normalizedJoinStart);
              $limitMinutes = getEventTimeMinutes($normalizedJoinLimit);
              if ($limitMinutes < $startMinutes) {
                  http_response_code(422);
                  echo json_encode(['status' => 'error', 'message' => 'Join limit time cannot be before the start time.']);
                  exit;
              }
          }
          $store = loadGuestStore($storePath, $eventsRoot);
          $eventIndex = findEventIndexByCode($store['events'], $eventCode);
          if ($eventIndex < 0) {
              http_response_code(404);
              echo json_encode(['status' => 'error', 'message' => 'Event not found.']);
              exit;
          }
          $store['events'][$eventIndex]['name'] = $name;
          $store['events'][$eventIndex]['date'] = $date;
          $store['events'][$eventIndex]['join_start_time'] = $normalizedJoinStart;
          $store['events'][$eventIndex]['join_limit_time'] = $normalizedJoinLimit;
          $store['events'][$eventIndex]['updated_at'] = date('c');
          if (!saveGuestStore($storePath, $store)) {
              http_response_code(500);
              echo json_encode(['status' => 'error', 'message' => 'Failed to persist event data.']);
              exit;
          }
          echo json_encode([
              'status' => 'ok',
              'message' => 'Event updated successfully.',
              'events' => normalizeEventsForResponse($store['events']),
              'logs' => normalizeInviteLogs($store['logs'])
          ]);
          exit;
      } elseif ($action === 'delete_event') {
          $eventCode = trim((string)($_POST['event_code'] ?? ''));
          if ($eventCode === '') {
              http_response_code(422);
              echo json_encode(['status' => 'error', 'message' => 'Event code is required for deletion.']);
              exit;
          }
          $store = loadGuestStore($storePath, $eventsRoot);
          $eventIndex = findEventIndexByCode($store['events'], $eventCode);
          if ($eventIndex < 0) {
              http_response_code(404);
              echo json_encode(['status' => 'error', 'message' => 'Event not found.']);
              exit;
          }
          $event = $store['events'][$eventIndex];
          $eventDir = getEventDir($event, $eventsRoot);
          if (!deleteDirectoryWithinRoot($eventDir, $eventsRoot)) {
              http_response_code(500);
              echo json_encode(['status' => 'error', 'message' => 'Failed to remove event files.']);
              exit;
          }
          $invRoot = __DIR__ . '/../inv';
          $guestCodes = [];
          foreach ($event['guests'] ?? [] as $guest) {
              $code = normalizeInviteCodeDigits((string)($guest['invite_code'] ?? ''));
              if ($code === '') {
                  continue;
              }
              $guestCodes[] = $code;
          }
          $guestCodes = array_values(array_unique($guestCodes));
          foreach ($guestCodes as $code) {
              $codeDir = $invRoot . '/' . $code;
              if (!deleteDirectoryWithinRoot($codeDir, $invRoot)) {
                  http_response_code(500);
                  echo json_encode(['status' => 'error', 'message' => "Failed to remove invite directory for code {$code}."]);
                  exit;
              }
          }
          array_splice($store['events'], $eventIndex, 1);
          if (trim((string)($store['active_event_code'] ?? '')) === $eventCode) {
              $store['active_event_code'] = '';
          }
          if (!saveGuestStore($storePath, $store)) {
              http_response_code(500);
              echo json_encode(['status' => 'error', 'message' => 'Failed to persist event data.']);
              exit;
          }
          echo json_encode([
              'status' => 'ok',
              'message' => 'Event deleted successfully.',
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

    $store = loadGuestStore($storePath, $eventsRoot);
    $eventCode = allocateEventCode($store);
    $eventDir = getEventDir(['code' => $eventCode], $eventsRoot);
    if (!is_dir($eventDir) && !mkdir($eventDir, 0755, true) && !is_dir($eventDir)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Unable to create event directory.']);
        exit;
    }
    if (!ensureEventEntryPoints($eventDir, $eventCode)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Unable to prepare event draw/prize pages.']);
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
                'path' => 'events/' . $eventCode . '/' . $targetName
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

    foreach ($guests as &$guest) {
        $guest['number'] = allocateGuestNumber($store);
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
        normalizeGuestDateFields($guest);
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

    $csvHeaders = array_merge(['number'], $requiredKeys, ['sms_link', 'join_date', 'join_time', 'left_date', 'left_time']);
    $csvContent = buildCsv($csvGuests, $csvHeaders);
    $purelistFilename = 'purelist.csv';
    $purelistPath = $eventDir . '/' . $purelistFilename;
    if (file_put_contents($purelistPath, $csvContent) === false) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to save the pure list file.']);
        exit;
    }

    $eventRecord = [
        'code' => $eventCode,
        'name' => $eventName,
        'date' => $eventDate,
        'join_start_time' => '',
        'join_limit_time' => '',
        'join_end_time' => '',
        'mapping' => $mapping,
        'purelist' => 'events/' . $eventCode . '/' . $purelistFilename,
        'guest_count' => count($guests),
        'guests' => $guests,
        'updated_at' => date('c')
    ];
    if ($uploadedFileInfo) {
        $eventRecord['source'] = $uploadedFileInfo;
    }
    $eventRecord['created_at'] = $eventRecord['updated_at'];
    $store['events'][] = $eventRecord;
    $eventIndex = count($store['events']) - 1;

    if (!saveGuestStore($storePath, $store)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to persist guest data.']);
        exit;
    }

        createGuestInvitePages(
            $store['events'][$eventIndex]['guests'] ?? [],
            (string)($store['events'][$eventIndex]['code'] ?? '')
        );

    echo json_encode([
        'status' => 'ok',
        'message' => 'Guest list saved successfully.',
        'events' => normalizeEventsForResponse($store['events']),
        'logs' => normalizeInviteLogs($store['logs'])
    ]);
    exit;
}

$store = loadGuestStore($storePath, $eventsRoot);
$activeEventCode = trim((string)($store['active_event_code'] ?? ''));
$activeEventIndex = $activeEventCode === '' ? -1 : findEventIndexByCode($store['events'], $activeEventCode);
$activeEvent = $activeEventIndex >= 0 ? $store['events'][$activeEventIndex] : [];
echo json_encode([
    'status' => 'ok',
    'events' => normalizeEventsForResponse($store['events']),
    'logs' => normalizeInviteLogs($store['logs']),
    'stats' => computeGuestStats($activeEvent)
]);
exit;

function loadGuestStore(string $path, string $eventsRoot = ''): array
{
    if (!is_file($path)) {
        return ['events' => [], 'logs' => []];
    }
    $content = file_get_contents($path);
    if ($content === false) {
        return ['events' => [], 'logs' => []];
    }
    $decoded = json_decode($content, true);
    $store = normalizeStore(is_array($decoded) ? $decoded : []);
    if ($eventsRoot !== '') {
        ensureEventPurelistFiles($store['events'], $eventsRoot);
    }
    return $store;
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

function findEventIndexByCode(array $events, string $code): int
{
    $normalizedCode = trim((string)$code);
    if ($normalizedCode === '') {
        return -1;
    }
    foreach ($events as $index => $event) {
        if (!is_array($event)) {
            continue;
        }
        if (trim((string)($event['code'] ?? '')) === $normalizedCode) {
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

function syncEventPurelist(array &$event, string $eventsRoot): bool
{
    $eventDir = getEventDir($event, $eventsRoot);
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
        normalizeGuestDateFields($guest);
    }
    unset($guest);
    $headers = PURELIST_HEADERS;
    $csvContent = buildCsv($guests, $headers);
    $purelistPath = $eventDir . '/purelist.csv';
    if (file_put_contents($purelistPath, $csvContent) === false) {
        return false;
    }
    $event['purelist'] = 'events/' . getEventDirName($event) . '/purelist.csv';
    return true;
}

function ensureEventPurelistFiles(array &$events, string $eventsRoot): void
{
    foreach ($events as &$event) {
        if (!is_array($event)) {
            $event = [];
        }
        $eventDir = getEventDir($event, $eventsRoot);
        if (!is_dir($eventDir) && !mkdir($eventDir, 0755, true) && !is_dir($eventDir)) {
            continue;
        }
        $candidateCode = trim((string)($event['code'] ?? ''));
        if ($candidateCode === '') {
            $candidateCode = getEventDirName($event);
        }
        ensureEventEntryPoints($eventDir, $candidateCode);
        $purelistPath = $eventDir . '/purelist.csv';
        if (is_file($purelistPath)) {
            continue;
        }
        syncEventPurelist($event, $eventsRoot);
    }
    unset($event);
}

function normalizeFilesystemPath(string $path): string
{
    $trimmed = trim($path);
    if ($trimmed === '') {
        return '';
    }
    $real = realpath($trimmed);
    $normalized = $real !== false ? $real : $trimmed;
    $normalized = str_replace('\\', '/', $normalized);
    return rtrim($normalized, '/');
}

function deleteDirectoryRecursive(string $directory): bool
{
    if (!is_dir($directory)) {
        return true;
    }
    $entries = scandir($directory);
    if ($entries === false) {
        return false;
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $target = $directory . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($target)) {
            if (!deleteDirectoryRecursive($target)) {
                return false;
            }
            continue;
        }
        if (is_file($target) && !@unlink($target)) {
            return false;
        }
    }
    return rmdir($directory);
}

function deleteDirectoryWithinRoot(string $directory, string $root): bool
{
    $directory = trim($directory);
    $root = trim($root);
    if ($directory === '' || $root === '') {
        return false;
    }
    if (!is_dir($directory)) {
        return true;
    }
    $normalizedRoot = normalizeFilesystemPath($root);
    $normalizedDirectory = normalizeFilesystemPath($directory);
    if (
        $normalizedRoot === '' ||
        $normalizedDirectory === '' ||
        strpos($normalizedDirectory, $normalizedRoot) !== 0
    ) {
        return false;
    }
    return deleteDirectoryRecursive($directory);
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

function formatPersianDateTimeParts(DateTimeInterface $date): array
{
    $tz = $date->getTimezone();
    if (!class_exists('IntlDateFormatter')) {
        return splitDateTimeValue($date->format('Y/m/d H:i:s'));
    }
    $dateFormatter = new IntlDateFormatter(
        'fa_IR@calendar=persian;numbers=latn',
        IntlDateFormatter::NONE,
        IntlDateFormatter::NONE,
        $tz,
        IntlDateFormatter::TRADITIONAL,
        'yyyy/MM/dd'
    );
    $timeFormatter = new IntlDateFormatter(
        'fa_IR@calendar=persian;numbers=latn',
        IntlDateFormatter::NONE,
        IntlDateFormatter::NONE,
        $tz,
        IntlDateFormatter::TRADITIONAL,
        'HH:mm:ss'
    );
    $dateText = $dateFormatter !== false ? $dateFormatter->format($date) : '';
    $timeText = $timeFormatter !== false ? $timeFormatter->format($date) : '';
    if (!is_string($dateText) || trim($dateText) === '') {
        $dateText = $date->format('Y/m/d');
    }
    if (!is_string($timeText) || trim($timeText) === '') {
        $timeText = $date->format('H:i:s');
    }
    return [
        'date' => trim($dateText),
        'time' => trim($timeText)
    ];
}

function splitDateTimeValue(string $value): array
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return ['date' => '', 'time' => ''];
    }
    if (preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})$/', $trimmed, $matches)) {
        return [
            'date' => "{$matches[1]}/{$matches[2]}/{$matches[3]}",
            'time' => "{$matches[4]}:{$matches[5]}:{$matches[6]}"
        ];
    }
    $normalized = str_replace(['T', 't'], ' ', $trimmed);
    $parts = preg_split('/\s+/', $normalized);
    $datePart = $parts[0] ?? '';
    $timePart = $parts[1] ?? '';
    if ($timePart === '' && strpos($datePart, 'T') !== false) {
        [$datePart, $timePart] = array_pad(explode('T', $datePart, 2), 2, '');
    }
    if ($timePart === '' && strpos($datePart, ':') !== false && strpos($datePart, '/') === false && strpos($datePart, '-') === false) {
        return ['', $datePart];
    }
    return [$datePart, $timePart];
}

function composeDateTimeString(string $date, string $time): string
{
    $date = trim($date);
    $time = trim($time);
    if ($date === '') {
        return '';
    }
    return $time === '' ? $date : "{$date} {$time}";
}

function normalizeGuestDateFields(array &$guest): void
{
    $guest['date_entered'] = (string)($guest['date_entered'] ?? '');
    $guest['date_exited'] = (string)($guest['date_exited'] ?? '');

    $existingJoin = [
        'date' => trim((string)($guest['join_date'] ?? '')),
        'time' => trim((string)($guest['join_time'] ?? ''))
    ];
    $enteredParts = splitDateTimeValue($guest['date_entered']);
    if ($existingJoin['date'] === '' && $enteredParts['date'] !== '') {
        $existingJoin = $enteredParts;
    }
    $guest['join_date'] = $existingJoin['date'];
    $guest['join_time'] = $existingJoin['time'];
    if ($guest['date_entered'] === '' && $guest['join_date'] !== '') {
        $guest['date_entered'] = composeDateTimeString($guest['join_date'], $guest['join_time']);
    }

    $existingLeft = [
        'date' => trim((string)($guest['left_date'] ?? '')),
        'time' => trim((string)($guest['left_time'] ?? ''))
    ];
    $exitedParts = splitDateTimeValue($guest['date_exited']);
    if ($existingLeft['date'] === '' && $exitedParts['date'] !== '') {
        $existingLeft = $exitedParts;
    }
    $guest['left_date'] = $existingLeft['date'];
    $guest['left_time'] = $existingLeft['time'];
    if ($guest['date_exited'] === '' && $guest['left_date'] !== '') {
        $guest['date_exited'] = composeDateTimeString($guest['left_date'], $guest['left_time']);
    }
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

function findGuestByNationalIdForCodes(array $events, string $nationalId, array $allowedCodes = []): ?array
{
    $allowed = array_filter($allowedCodes, static function ($value) {
        return trim((string)$value) !== '';
    });
    foreach ($events as $eventIndex => $event) {
        if (!is_array($event)) {
            continue;
        }
        $code = (string)($event['code'] ?? '');
        if (!empty($allowed) && !in_array($code, $allowed, true)) {
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

function convertDigitsToPersian(string $value): string
{
    $map = [
        '0' => '۰',
        '1' => '۱',
        '2' => '۲',
        '3' => '۳',
        '4' => '۴',
        '5' => '۵',
        '6' => '۶',
        '7' => '۷',
        '8' => '۸',
        '9' => '۹'
    ];
    return strtr($value, $map);
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

function formatEventTimeValue(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }
    if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $trimmed, $matches)) {
        return '';
    }
    return sprintf('%02d:%02d', (int)$matches[1], (int)$matches[2]);
}

function getEventTimeMinutes(string $value): int
{
    [$hours, $minutes] = explode(':', $value);
    return ((int)$hours) * 60 + ((int)$minutes);
}

function normalizeStore(array $store): array
{
    $store['events'] = is_array($store['events'] ?? null) ? array_values($store['events']) : [];
    $nextEventCode = max(EVENT_CODE_MIN, (int)($store['next_event_code'] ?? EVENT_CODE_MIN));
    $nextGuestNumber = max(1, (int)($store['next_guest_number'] ?? 1));
    foreach ($store['events'] as &$event) {
        if (!is_array($event)) {
            $event = [];
        }
        ensureEventHasCode($event, $nextEventCode);
        $event['join_start_time'] = formatEventTimeValue((string)($event['join_start_time'] ?? ''));
        $event['join_limit_time'] = formatEventTimeValue((string)($event['join_limit_time'] ?? ''));
        $event['join_end_time'] = formatEventTimeValue((string)($event['join_end_time'] ?? ''));
        $event['purelist'] = 'events/' . getEventDirName($event) . '/purelist.csv';
        $event['guests'] = is_array($event['guests'] ?? null) ? array_values($event['guests']) : [];
        foreach ($event['guests'] as &$guest) {
            if (!is_array($guest)) {
                $guest = [];
            }
            $number = (int)($guest['number'] ?? 0);
            if ($number <= 0) {
                $number = $nextGuestNumber;
                $nextGuestNumber++;
            } elseif ($number >= $nextGuestNumber) {
                $nextGuestNumber = $number + 1;
            }
            $guest['number'] = $number;
            $guest['national_id'] = normalizeNationalId((string)($guest['national_id'] ?? ''));
            $guest['date_entered'] = (string)($guest['date_entered'] ?? '');
            $guest['date_exited'] = (string)($guest['date_exited'] ?? '');
            $guest['invite_code'] = ensureInviteCode($event, $guest);
            normalizeGuestDateFields($guest);
        }
        unset($guest);
        $event['guest_count'] = count($event['guests']);
    }
    unset($event);
    $store['next_event_code'] = $nextEventCode;
    $store['next_guest_number'] = $nextGuestNumber;
    $store['logs'] = is_array($store['logs'] ?? null) ? array_values($store['logs']) : [];
    $store['active_event_code'] = trim((string)($store['active_event_code'] ?? ''));
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
    $log['event_code'] = (string)($log['event_code'] ?? '');
    $log['event_name'] = (string)($log['event_name'] ?? '');
    $log['guest_name'] = trim((string)($log['guest_name'] ?? ''));
    $log['invite_code'] = normalizeInviteCodeDigits((string)($log['invite_code'] ?? ''));
    $log['timestamp'] = (string)($log['timestamp'] ?? date('Y-m-d H:i:s'));
    $log['date_entered'] = (string)($log['date_entered'] ?? '');
    $log['date_exited'] = (string)($log['date_exited'] ?? '');
    $enteredParts = splitDateTimeValue($log['date_entered']);
    $log['join_date'] = (string)($log['join_date'] ?? $enteredParts['date']);
    $log['join_time'] = (string)($log['join_time'] ?? $enteredParts['time']);
    $exitedParts = splitDateTimeValue($log['date_exited']);
    $log['left_date'] = (string)($log['left_date'] ?? $exitedParts['date']);
    $log['left_time'] = (string)($log['left_time'] ?? $exitedParts['time']);
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
            normalizeGuestDateFields($guest);
        }
        unset($guest);
        return [
            'code' => (string)($event['code'] ?? ''),
            'name' => (string)($event['name'] ?? ''),
            'date' => (string)($event['date'] ?? ''),
            'join_start_time' => (string)($event['join_start_time'] ?? ''),
            'join_limit_time' => (string)($event['join_limit_time'] ?? ''),
            'join_end_time' => (string)($event['join_end_time'] ?? ''),
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

function computeGuestStats(array $event): array
{
    $guests = is_array($event['guests'] ?? null) ? array_values($event['guests']) : [];
    $totalPresent = 0;
    $presentByGender = [];
    $invitedByGender = [];
    foreach ($guests as $guest) {
        if (!is_array($guest)) {
            continue;
        }
        $gender = trim((string)($guest['gender'] ?? ''));
        if ($gender === '') {
            $gender = 'نامشخص';
        }
        $invitedByGender[$gender] = ($invitedByGender[$gender] ?? 0) + 1;
        $entered = trim((string)($guest['date_entered'] ?? ''));
        if ($entered !== '') {
            $totalPresent++;
            $presentByGender[$gender] = ($presentByGender[$gender] ?? 0) + 1;
        }
    }
    return [
        'total_present' => $totalPresent,
        'total_invited' => count($guests),
        'present_by_gender' => $presentByGender,
        'invited_by_gender' => $invitedByGender
    ];
}

function createGuestInvitePages(array $guests, string $eventCode): void
{
    $invRoot = __DIR__ . '/../inv';
    if (!is_dir($invRoot)) {
        if (!mkdir($invRoot, 0755, true) && !is_dir($invRoot)) {
            return;
        }
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
        if (!is_dir($guestDir) && !@mkdir($guestDir, 0755, true) && !is_dir($guestDir)) {
            error_log('Unable to create invite directory for ' . $code);
            continue;
        }
        $fullName = trim((string)($guest['firstname'] ?? '') . ' ' . (string)($guest['lastname'] ?? ''));
        if ($fullName === '') {
            $fullName = 'Guest';
        }
        $safeName = htmlspecialchars($fullName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeCode = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $persianCode = convertDigitsToPersian((string)$safeCode);
        $nationalId = normalizeNationalId((string)($guest['national_id'] ?? ''));
        $safeNationalId = htmlspecialchars($nationalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $qrElement = '';
        if ($nationalId !== '') {
            $qrSrc = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&margin=8&data=' . rawurlencode($nationalId);
            $qrElement = "<img class=\"qr\" src=\"{$qrSrc}\" alt=\"QR ???? {$safeName}\">";
        }
        $page = <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light">
  <title>کارت دعوت رویداد همراه با نامی آشنا</title>
  <link rel="icon" id="site-icon-link" href="data:,">
  <link rel="preload" href="/style/fonts/PeydaWebFaNum-Regular.woff2" as="font" type="font/woff2" crossorigin="anonymous">
  <link rel="preload" href="/style/fonts/PeydaWebFaNum-Bold.woff2" as="font" type="font/woff2" crossorigin="anonymous">
  <link rel="stylesheet" href="/style/invite-card.css">
  <script src="/General%20Setting/general-settings.js" defer></script>
  <script>
    (function () {
      const iconEl = document.getElementById('site-icon-link');
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
  <style>
    @font-face {
      font-family: 'Peyda';
      font-style: normal;
      font-weight: 400;
      src: url('/style/fonts/PeydaWebFaNum-Regular.woff2') format('woff2');
    }

    @font-face {
      font-family: 'Peyda';
      font-style: normal;
      font-weight: 700;
      src: url('/style/fonts/PeydaWebFaNum-Bold.woff2') format('woff2');
    }

    :root {
      color-scheme: only light;
    }

    html,
    body {
      height: 100%;
      margin: 0;
      padding: 0.2rem 0.35rem 0.4rem;
      background: radial-gradient(circle at top, #fff7f1 0%, #f3f4f6 45%, #e2e8f0 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      font-family: 'Peyda', 'Segoe UI', Tahoma, Arial, sans-serif;
      color: #111;
      direction: rtl;
    }

    .device {
      width: min(340px, 92vw);
      aspect-ratio: 9 / 16;
      min-height: 700px;
      max-height: min(96vh, 780px);
      background: linear-gradient(180deg, #ffffff 0%, #fdfdfd 60%, #eef2ff 100%);
      border-radius: 40px;
      box-shadow: 0 35px 60px rgba(15, 23, 42, 0.25);
      overflow: hidden;
      display: flex;
      flex-direction: column;
      position: relative;
      margin: 0 auto;
      padding: 0.6rem 0.5rem 0.65rem;
    }

    .device::after {
      content: '';
      position: absolute;
      inset: 0;
      border-radius: inherit;
      border: 1px solid rgba(15, 23, 42, 0.08);
      pointer-events: none;
    }

    .screen {
      flex: 1;
      margin: 0.05rem 0;
      border-radius: 32px;
      background: linear-gradient(180deg, #ffffff 0%, #f3f5ff 55%, #e3ebff 100%);
      box-shadow: inset 0 2px 12px rgba(15, 23, 42, 0.1), 0 12px 30px rgba(15, 23, 42, 0.15);
      display: flex;
      flex-direction: column;
      gap: 0.45rem;
      overflow: hidden;
    }

    .card-image-shell {
      flex: 1;
      background: #dce6ff;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0.8rem;
    }

    .card-image-shell img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
      border-radius: 18px;
      box-shadow: 0 20px 40px rgba(15, 23, 42, 0.2);
    }

    .message {
      flex: 0 0 auto;
      padding: 1.5rem 1.25rem 1.8rem;
      display: flex;
      flex-direction: column;
      gap: 0.35rem;
      align-items: center;
      justify-content: center;
      text-align: center;
      background: transparent;
    }

    .greeting {
      margin: 0;
      font-size: 1rem;
      color: #52606d;
    }

    .name {
      margin: 0;
      font-size: clamp(1.1rem, 3vw, 1.3rem);
      font-weight: 700;
      color: #0f172a;
    }

    .qr {
      width: 110px;
      height: 110px;
      border-radius: 16px;
      background: #fff;
      padding: 0.4rem;
      box-shadow: 0 18px 35px rgba(15, 23, 42, 0.25);
      margin-top: 0.3rem;
      margin-bottom: 0.8rem;
    }

    .code {
      margin: 0 0 5px;
      font-family: 'Peyda';
      font-size: clamp(1.4rem, 3vw, 1.8rem);
      letter-spacing: 0.35em;
      font-weight: 600;
      color: #0f172a;
      direction: ltr;
      display: block;
      line-height: 1.1;
      width: 100%;
      text-align: center;
      white-space: nowrap;
    }

    @media (max-width: 480px) {
      .device {
        width: min(340px, 95vw);
        border-radius: 28px;
      }
      .message {
        padding: 1.25rem 1rem 1.6rem;
      }
      .code {
        letter-spacing: 0.3em;
        font-size: clamp(1.8rem, 4vw, 2.4rem);
      }
      .name {
        font-size: clamp(1.5rem, 4vw, 1.9rem);
      }
    }
</style>
</head>
  <body>
    <div class="device">
      <div class="screen">
        <div class="card-image-shell">
          <img src="{$imageUrl}" alt="???? ???? ??????">
        </div>
        <div class="message">
          <p class="greeting">مهمان محترم</p>
          <p class="name">{$safeName}</p>
          {$qrElement}
          <p class="code">{$persianCode}</p>
        </div>
      </div>
    </div>
  </body>
  </html>
HTML;
        if (@file_put_contents($guestDir . '/index.php', $page) === false) {
            error_log('Failed to write invite page for ' . $code);
        }
    }
}

