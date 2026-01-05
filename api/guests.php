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

function persistInviteCardTemplatePhoto(string $photoFilename, string $eventDirName): ?string
{
    $clean = trim($photoFilename);
    if ($clean === '') {
        return null;
    }
    $clean = str_replace(['..\\', '../'], '', $clean);
    $clean = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $clean);
    $clean = trim($clean, DIRECTORY_SEPARATOR);
    if ($clean === '') {
        return null;
    }
    $projectRoot = realpath(__DIR__ . '/../');
    if ($projectRoot === false) {
        return null;
    }
    $sourcePath = realpath($projectRoot . DIRECTORY_SEPARATOR . $clean);
    if ($sourcePath === false) {
        return null;
    }
    $normalizedRoot = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $projectRoot);
    $normalizedSource = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sourcePath);
    if (strpos($normalizedSource, $normalizedRoot) !== 0) {
        return null;
    }
    $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
    if ($extension === '') {
        $extension = 'png';
    }
    $targetDir = EVENTS_ROOT . DIRECTORY_SEPARATOR . $eventDirName . DIRECTORY_SEPARATOR . 'invite-card';
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        return null;
    }
    $targetFilename = 'template-photo.' . $extension;
    $targetPath = $targetDir . DIRECTORY_SEPARATOR . $targetFilename;
    if (!copy($sourcePath, $targetPath)) {
        return null;
    }
    $relativePath = 'events/' . $eventDirName . '/invite-card/' . $targetFilename;
    return '/' . ltrim($relativePath, '/');
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
        $eventCodeParam = trim((string)($_POST['event_code'] ?? ''));
        $now = createNowTime();
        $nowString = $now->format('Y-m-d H:i:s');
        $nowShamsiParts = formatPersianDateTimeParts($now);
        $activeEventCandidate = resolveActiveEventForInvite($store['events'] ?? [], $now, $eventCodeParam);
        if ($activeEventCandidate === null) {
            http_response_code(422);
            echo json_encode([
                'status' => 'error',
                'message' => $eventCodeParam === '' ? 'No running event is available right now.' : 'The requested event is not running at the moment.'
            ]);
            exit;
        }
        $activeEventCode = trim((string)($activeEventCandidate['event']['code'] ?? ''));
        $activeEventIndex = $activeEventCandidate['index'];
        if ($activeEventCode === '') {
            http_response_code(422);
            echo json_encode([
                'status' => 'error',
                'message' => 'Unable to determine the running event.'
            ]);
            exit;
        }
        $match = findGuestByNationalIdForCodes($store['events'], $nationalId, [$activeEventCode]);
        if ($match === null) {
            $eventReference = &$store['events'][$activeEventIndex];
            $fallbackIndex = ensureGuestFromPurelist($eventReference, $nationalId, $eventsRoot);
            if (is_int($fallbackIndex)) {
                $match = [
                    'event_index' => $activeEventIndex,
                    'guest_index' => $fallbackIndex
                ];
            }
        }
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
                'event_name' => $activeEventName,
                'outcome' => 'not_found',
                'log' => $log,
                'logs' => normalizeInviteLogs($store['logs']),
                'stats' => computeGuestStats($store['events'][$activeEventIndex] ?? []),
                'active_event' => normalizeEventForResponse($store['events'][$activeEventIndex])
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
                $guest['left_date'] = $nowShamsiParts['date'];
                $guest['left_time'] = $nowShamsiParts['time'];
                $guest['date_exited'] = $nowString;
                $outcome = 'exit';
                $message = 'Guest marked as exited.';
            } else {
                $outcome = 'spam';
                $message = 'Repeated scan too soon after entry.';
            }
        }
        normalizeGuestDateFields($guest);
        $event['updated_at'] = date('c');
        if (!syncEventPurelist($event, $eventsRoot)) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to sync the event pure list.']);
            exit;
        }

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
            'event_name' => $eventName,
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
            'stats' => computeGuestStats($store['events'][$activeEventIndex] ?? []),
            'active_event' => normalizeEventForResponse($event)
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
            $store['events'][$eventIndex] ?? []
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
          $joinLeftTime = trim((string)($_POST['join_left_time'] ?? ''));
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
          $normalizedJoinLeft = $joinLeftTime === '' ? '' : formatEventTimeValue($joinLeftTime);
          if ($joinLeftTime !== '' && $normalizedJoinLeft === '') {
              http_response_code(422);
              echo json_encode(['status' => 'error', 'message' => 'Event left time must be a valid 24-hour time.']);
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
          if ($normalizedJoinEnd !== '' && $normalizedJoinLimit !== '') {
              $limitMinutes = getEventTimeMinutes($normalizedJoinLimit);
              $endMinutes = getEventTimeMinutes($normalizedJoinEnd);
              if ($endMinutes <= $limitMinutes) {
                  http_response_code(422);
                  echo json_encode(['status' => 'error', 'message' => 'Event end time must be after the join limit time.']);
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
          if ($normalizedJoinLimit !== '' && $normalizedJoinLeft !== '') {
              $limitMinutes = getEventTimeMinutes($normalizedJoinLimit);
              $leftMinutes = getEventTimeMinutes($normalizedJoinLeft);
              if ($leftMinutes <= $limitMinutes) {
                  http_response_code(422);
                  echo json_encode(['status' => 'error', 'message' => 'Event left time must be after the join limit time.']);
                  exit;
              }
          }
          if ($normalizedJoinLeft !== '' && $normalizedJoinEnd !== '') {
              $leftMinutes = getEventTimeMinutes($normalizedJoinLeft);
              $endMinutes = getEventTimeMinutes($normalizedJoinEnd);
              if ($endMinutes <= $leftMinutes) {
                  http_response_code(422);
                  echo json_encode(['status' => 'error', 'message' => 'Event left time must be before the end time.']);
                  exit;
              }
          }
          $store['events'][$eventIndex]['join_start_time'] = $normalizedJoinStart;
          $store['events'][$eventIndex]['join_limit_time'] = $normalizedJoinLimit;
          $store['events'][$eventIndex]['join_left_time'] = $normalizedJoinLeft;
          $store['events'][$eventIndex]['join_end_time'] = $normalizedJoinEnd;
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
        } elseif ($action === 'update_event_setting') {
          $eventCode = trim((string)($_POST['event_code'] ?? ''));
          $printEntryPayload = $_POST['print_entry_modal'] ?? null;
          $printEntryModal = filter_var($printEntryPayload, FILTER_VALIDATE_BOOLEAN, ['flags' => FILTER_NULL_ON_FAILURE]);
          if ($eventCode === '' || $printEntryModal === null) {
              http_response_code(422);
              echo json_encode(['status' => 'error', 'message' => 'Event code and a valid print setting are required.']);
              exit;
          }
          $store = loadGuestStore($storePath, $eventsRoot);
          $eventIndex = findEventIndexByCode($store['events'], $eventCode);
          if ($eventIndex < 0) {
              http_response_code(404);
              echo json_encode(['status' => 'error', 'message' => 'Event not found.']);
              exit;
          }
          $store['events'][$eventIndex]['print_entry_modal'] = $printEntryModal;
          $store['events'][$eventIndex]['updated_at'] = date('c');
          if (!saveGuestStore($storePath, $store)) {
              http_response_code(500);
              echo json_encode(['status' => 'error', 'message' => 'Failed to persist event settings.']);
              exit;
          }
          echo json_encode([
              'status' => 'ok',
              'message' => 'Entry print setting saved.',
              'events' => normalizeEventsForResponse($store['events']),
              'active_event' => normalizeEventForResponse($store['events'][$eventIndex])
          ]);
          exit;
        } elseif ($action === 'create_event_entrypoints') {
            $eventCode = trim((string)($_POST['event_code'] ?? ''));
            if ($eventCode === '') {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Event code is required.']);
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
            if (!ensureEventEntryPoints($eventDir, $eventCode)) {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Failed to create event entry points.']);
                exit;
            }
            echo json_encode([
                'status' => 'ok',
                'message' => 'Event invite entry page created successfully.'
            ]);
            exit;
        } elseif ($action === 'save_invite_card_template') {
            $eventCode = trim((string)($_POST['event_code'] ?? ''));
            $templatePayload = (string)($_POST['template'] ?? '');
            if ($eventCode === '' || $templatePayload === '') {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Event code and template data are required.']);
                exit;
            }
            $decoded = json_decode($templatePayload, true);
            if (!is_array($decoded)) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Invalid template payload.']);
                exit;
            }
            $fields = is_array($decoded['fields'] ?? null) ? array_values($decoded['fields']) : [];
            $photoId = trim((string)($decoded['photo_id'] ?? ''));
            $photoTitle = trim((string)($decoded['photo_title'] ?? ''));
            $photoAlt = trim((string)($decoded['photo_alt'] ?? ''));
            $photoFilename = trim((string)($decoded['photo_filename'] ?? ''));
            $previewGender = trim((string)($decoded['preview_gender'] ?? ''));
            $prefixes = [];
            foreach ((array)($decoded['gender_prefixes'] ?? []) as $gender => $value) {
                $normalizedGender = trim((string)$gender);
                if ($normalizedGender === '') {
                    continue;
                }
                $prefixes[$normalizedGender] = trim((string)$value);
            }
            $prefixStyles = [];
            foreach ((array)($decoded['gender_prefix_styles'] ?? []) as $gender => $styleData) {
                $normalizedGender = trim((string)$gender);
                if ($normalizedGender === '' || !is_array($styleData)) {
                    continue;
                }
                $fontFamily = trim((string)($styleData['fontFamily'] ?? $styleData['font_family'] ?? ''));
                $fontWeight = trim((string)($styleData['fontWeight'] ?? $styleData['font_weight'] ?? ''));
                $rawSize = $styleData['fontSize'] ?? $styleData['font_size'] ?? null;
                $fontSizeValue = null;
                if (is_numeric($rawSize)) {
                    $fontSizeValue = (float)$rawSize;
                } elseif (is_string($rawSize) && preg_match('/^\d+(\.\d+)?$/', $rawSize)) {
                    $fontSizeValue = (float)$rawSize;
                }
                $color = trim((string)($styleData['color'] ?? ''));
                $prefixStyles[$normalizedGender] = [
                    'fontFamily' => $fontFamily,
                    'fontWeight' => $fontWeight,
                    'fontSize' => $fontSizeValue,
                    'color' => $color
                ];
            }
            $store = loadGuestStore($storePath, $eventsRoot);
            $eventIndex = findEventIndexByCode($store['events'], $eventCode);
            if ($eventIndex < 0) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Event not found.']);
                exit;
            }
            $eventDirName = getEventDirName($store['events'][$eventIndex]);
            $templateData = [
                'photo_id' => $photoId,
                'photo_title' => $photoTitle,
                'photo_filename' => $photoFilename,
                'photo_alt' => $photoAlt,
                'fields' => $fields,
                'gender_prefixes' => $prefixes,
                'gender_prefix_styles' => $prefixStyles,
                'preview_gender' => $previewGender,
                'updated_at' => date('c')
            ];
            if ($photoFilename !== '') {
                $photoPath = persistInviteCardTemplatePhoto($photoFilename, $eventDirName);
                if ($photoPath !== null) {
                    $templateData['photo_path'] = $photoPath;
                }
            }
            $store['events'][$eventIndex]['invite_card_template'] = $templateData;
            $store['events'][$eventIndex]['updated_at'] = date('c');
            if (!saveGuestStore($storePath, $store)) {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Unable to persist invite card template.']);
                exit;
            }
            echo json_encode([
                'status' => 'ok',
                'message' => 'Invite card template saved.',
                'events' => normalizeEventsForResponse($store['events']),
                'logs' => normalizeInviteLogs($store['logs'])
            ]);
            createGuestInvitePages(
                $store['events'][$eventIndex]['guests'] ?? [],
                $store['events'][$eventIndex] ?? []
            );
            exit;
        } elseif ($action === 'save_generated_invite_card') {
            $inviteCode = normalizeInviteCodeDigits((string)($_POST['invite_code'] ?? ''));
            $imageData = (string)($_POST['image_data'] ?? '');
            if ($inviteCode === '' || $imageData === '') {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Invite code and image data are required.']);
                exit;
            }
            if (!preg_match('/^data:image\\/(png|jpe?g);base64,(.+)$/i', $imageData, $matches)) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Invalid image payload.']);
                exit;
            }
            $decoded = base64_decode($matches[2], true);
            if ($decoded === false) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Unable to decode image data.']);
                exit;
            }
            $invRoot = __DIR__ . '/../inv';
            if (!is_dir($invRoot) && !mkdir($invRoot, 0755, true) && !is_dir($invRoot)) {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Unable to create invite storage.']);
                exit;
            }
            $guestDir = $invRoot . '/' . $inviteCode;
            if (!is_dir($guestDir) && !mkdir($guestDir, 0755, true) && !is_dir($guestDir)) {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Unable to create invite directory.']);
                exit;
            }
            $targetPath = $guestDir . '/InviteCard.jpg';
            if (file_put_contents($targetPath, $decoded) === false) {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Failed to save invite card image.']);
                exit;
            }
            ensureGuestInviteIndexPage($inviteCode);
            echo json_encode(['status' => 'ok']);
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
        'print_entry_modal' => true,
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

    if (!is_file($eventDir . '/invite.php')) {
        ensureEventEntryPoints($eventDir, $eventCode);
    }

    createGuestInvitePages(
        $store['events'][$eventIndex]['guests'] ?? [],
        $store['events'][$eventIndex] ?? []
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
    $eventCodeParam = trim((string)($_GET['event_code'] ?? ''));
    $activeEventCandidate = null;
    if ($eventCodeParam !== '') {
        $eventIndex = findEventIndexByCode($store['events'], $eventCodeParam);
        if ($eventIndex >= 0) {
            $activeEventCandidate = [
                'event' => $store['events'][$eventIndex],
                'index' => $eventIndex,
                'state' => ''
            ];
        }
    }
    if ($activeEventCandidate === null) {
        $now = createNowTime();
        $activeEventCandidate = resolveActiveEventForInvite($store['events'] ?? [], $now);
    }
    $activeEvent = $activeEventCandidate['event'] ?? [];
    $filteredLogs = $store['logs'];
    if ($eventCodeParam !== '') {
        $filteredLogs = array_filter($filteredLogs, static function ($log) use ($eventCodeParam) {
            return trim((string)($log['event_code'] ?? '')) === $eventCodeParam;
        });
    }
    $normalizedActiveEvent = !empty($activeEvent) ? normalizeEventForResponse($activeEvent) : null;
    echo json_encode([
        'status' => 'ok',
        'event_name' => (string)($activeEvent['name'] ?? ''),
        'events' => normalizeEventsForResponse($store['events']),
        'logs' => normalizeInviteLogs(array_values($filteredLogs)),
        'stats' => computeGuestStats($activeEvent),
        'active_event' => $normalizedActiveEvent
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
        'Û°' => '0', 'Û±' => '1', 'Û²' => '2', 'Û³' => '3', 'Û´' => '4',
        'Ûµ' => '5', 'Û¶' => '6', 'Û·' => '7', 'Û¸' => '8', 'Û¹' => '9'
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
        $gregorianDate = [
            'year' => (int)$date->format('Y'),
            'month' => (int)$date->format('n'),
            'day' => (int)$date->format('j')
        ];
        $jalali = convertGregorianToJalali($gregorianDate['year'], $gregorianDate['month'], $gregorianDate['day']);
        return [
            'date' => sprintf('%04d/%02d/%02d', $jalali['year'], $jalali['month'], $jalali['day']),
            'time' => $date->format('H:i:s')
        ];
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
        $gregorianDate = [
            'year' => (int)$date->format('Y'),
            'month' => (int)$date->format('n'),
            'day' => (int)$date->format('j')
        ];
        $jalali = convertGregorianToJalali($gregorianDate['year'], $gregorianDate['month'], $gregorianDate['day']);
        $dateText = sprintf('%04d/%02d/%02d', $jalali['year'], $jalali['month'], $jalali['day']);
    }
    if (!is_string($timeText) || trim($timeText) === '') {
        $timeText = $date->format('H:i:s');
    }
    return [
        'date' => trim($dateText),
        'time' => trim($timeText)
    ];
}

function parseShamsiDateParts(?string $value): ?array
{
    $trimmed = trim((string)$value);
    if ($trimmed === '') {
        return null;
    }
    $normalized = preg_replace('/[^\d\\/\\\\]/', '', $trimmed);
    $parts = preg_split('/[\\/\\\\]+/', $normalized);
    if (!is_array($parts) || count($parts) < 3) {
        return null;
    }
    $year = (int)$parts[0];
    $month = (int)$parts[1];
    $day = (int)$parts[2];
    if ($year <= 0 || $month <= 0 || $day <= 0) {
        return null;
    }
    return ['year' => $year, 'month' => $month, 'day' => $day];
}

function jalaliToJdn(int $jy, int $jm, int $jd): ?int
{
    if ($jm < 1 || $jm > 12 || $jd < 1) {
        return null;
    }
    $epBase = $jy - ($jy >= 0 ? 474 : 473);
    $epYear = 474 + ($epBase % 2820);
    $mdays = $jm <= 7 ? ($jm - 1) * 31 : ($jm - 7) * 30 + 186;
    $days =
        $jd +
        $mdays +
        (int)floor(($epYear * 682 - 110) / 2816) +
        ($epYear - 1) * 365 +
        (int)floor($epBase / 2820) * 1029983;
    return $days + 1948320;
}

function compareShamsiDates(?string $a, ?string $b): ?int
{
    $aParts = parseShamsiDateParts($a);
    $bParts = parseShamsiDateParts($b);
    if ($aParts === null || $bParts === null) {
        return null;
    }
    $aJdn = jalaliToJdn($aParts['year'], $aParts['month'], $aParts['day']);
    $bJdn = jalaliToJdn($bParts['year'], $bParts['month'], $bParts['day']);
    if ($aJdn === null || $bJdn === null) {
        return null;
    }
    if ($aJdn === $bJdn) {
        return 0;
    }
    return $aJdn > $bJdn ? 1 : -1;
}

function parseEventTimeSeconds(?string $value): ?int
{
    $normalized = trim((string)$value);
    if ($normalized === '') {
        return null;
    }
    if (!preg_match('/^([01]?\\d|2[0-3]):([0-5]\\d)(?::([0-5]\\d))?$/', $normalized, $matches)) {
        return null;
    }
    $hours = (int)$matches[1];
    $minutes = (int)$matches[2];
    $seconds = isset($matches[3]) && $matches[3] !== '' ? (int)$matches[3] : 0;
    return $hours * 3600 + $minutes * 60 + $seconds;
}

function describeSameDayEventState(array $event, DateTimeImmutable $now): string
{
    $nowSeconds = (int)$now->format('H') * 3600 + (int)$now->format('i') * 60 + (int)$now->format('s');
    $startSeconds = parseEventTimeSeconds($event['join_start_time'] ?? '');
    $limitSeconds = parseEventTimeSeconds($event['join_limit_time'] ?? '');
    $leftSeconds = parseEventTimeSeconds($event['join_left_time'] ?? '');
    $endSeconds = parseEventTimeSeconds($event['join_end_time'] ?? '');

    if ($endSeconds !== null && $nowSeconds >= $endSeconds) {
        return 'after-end';
    }
    if ($leftSeconds !== null && $nowSeconds >= $leftSeconds) {
        return 'post-limit';
    }
    if ($limitSeconds !== null && $nowSeconds >= $limitSeconds) {
        return $leftSeconds !== null ? 'running' : 'post-limit';
    }
    if ($startSeconds !== null && $nowSeconds >= $startSeconds) {
        return 'entry-open';
    }
    return 'before-start';
}

function computeEventStateForInvite(array $event, DateTimeImmutable $now): array
{
    $todayParts = formatPersianDateTimeParts($now);
    $todayDate = $todayParts['date'] ?? '';
    $relation = compareShamsiDates($event['date'] ?? '', $todayDate);
    $state = '';
    if ($relation === 0) {
        $state = describeSameDayEventState($event, $now);
    }
    return ['relation' => $relation, 'state' => $state];
}

function resolveActiveEventForInvite(array $events, DateTimeImmutable $now, string $eventCode = ''): ?array
{
    $targetCode = trim((string)$eventCode);
    foreach (array_values($events) as $index => $event) {
        if (!is_array($event)) {
            continue;
        }
        $stateInfo = computeEventStateForInvite($event, $now);
        if ($targetCode !== '') {
            $eventCodeValue = trim((string)($event['code'] ?? ''));
            if ($eventCodeValue === $targetCode) {
                return [
                    'event' => $event,
                    'index' => $index,
                    'state' => $stateInfo['state']
                ];
            }
            continue;
        }
        if ($stateInfo['relation'] === 0 && in_array($stateInfo['state'], ['entry-open', 'running', 'post-limit'], true)) {
            return [
                'event' => $event,
                'index' => $index,
                'state' => $stateInfo['state']
            ];
        }
    }
    return null;
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
        return ['date' => '', 'time' => $datePart];
    }
    return ['date' => $datePart, 'time' => $timePart];
}

function convertGregorianToJalali(int $year, int $month, int $day): array
{
    $g_days_in_month = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $j_days_in_month = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];

    $gy = $year - 1600;
    $gm = $month - 1;
    $gd = $day - 1;

    $g_day_no = 365 * $gy + (int)(($gy + 3) / 4) - (int)(($gy + 99) / 100) + (int)(($gy + 399) / 400);
    for ($i = 0; $i < $gm; $i++) {
        $g_day_no += $g_days_in_month[$i];
    }
    if ($gm > 1 && (($year % 400 === 0) || ($year % 100 !== 0 && $year % 4 === 0))) {
        $g_day_no++;
    }
    $g_day_no += $gd;

    $j_day_no = $g_day_no - 79;
    $j_np = (int)($j_day_no / 12053);
    $j_day_no %= 12053;

    $jy = 979 + 33 * $j_np + 4 * (int)($j_day_no / 1461);
    $j_day_no %= 1461;
    if ($j_day_no >= 366) {
        $jy += (int)(($j_day_no - 1) / 365);
        $j_day_no = ($j_day_no - 1) % 365;
    }

    $jm = 0;
    for ($i = 0; $i < 11 && $j_day_no >= $j_days_in_month[$i]; $i++) {
        $j_day_no -= $j_days_in_month[$i];
        $jm++;
    }
    $jm++;
    $jd = $j_day_no + 1;

    return [
        'year' => $jy,
        'month' => $jm,
        'day' => $jd
    ];
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

function ensureGuestFromPurelist(array &$event, string $nationalId, string $eventsRoot): ?int
{
    $purelistPath = getEventDir($event, $eventsRoot) . '/purelist.csv';
    if (!is_file($purelistPath)) {
        return null;
    }
    $lines = file($purelistPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false || empty($lines)) {
        return null;
    }
    $headerLine = array_shift($lines);
    $headerLine = preg_replace('/^\xEF\xBB\xBF/', '', $headerLine);
    $headers = str_getcsv($headerLine);
    if (!is_array($headers) || empty($headers)) {
        return null;
    }
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }
        $values = str_getcsv($line);
        if (!is_array($values) || count($values) !== count($headers)) {
            continue;
        }
        $row = array_combine($headers, $values);
        if (!is_array($row)) {
            continue;
        }
        $guestId = normalizeNationalId((string)($row['national_id'] ?? ''));
        if ($guestId === '' || $guestId !== $nationalId) {
            continue;
        }
        $newGuest = [
            'number' => (int)($row['number'] ?? 0),
            'firstname' => (string)($row['firstname'] ?? ''),
            'lastname' => (string)($row['lastname'] ?? ''),
            'gender' => (string)($row['gender'] ?? ''),
            'national_id' => $guestId,
            'phone_number' => (string)($row['phone_number'] ?? ''),
            'join_date' => (string)($row['join_date'] ?? ''),
            'join_time' => (string)($row['join_time'] ?? ''),
            'left_date' => (string)($row['left_date'] ?? ''),
            'left_time' => (string)($row['left_time'] ?? ''),
            'date_entered' => '',
            'date_exited' => ''
        ];
        if ($newGuest['number'] <= 0) {
            $existingCount = count(is_array($event['guests'] ?? []) ? $event['guests'] : []);
            $newGuest['number'] = $existingCount + 1;
        }
        ensureInviteCode($event, $newGuest);
        $newGuest['date_entered'] = composeDateTimeString($newGuest['join_date'], $newGuest['join_time']);
        $newGuest['date_exited'] = composeDateTimeString($newGuest['left_date'], $newGuest['left_time']);
        normalizeGuestDateFields($newGuest);
        if (!is_array($event['guests'] ?? null)) {
            $event['guests'] = [];
        }
        $event['guests'][] = $newGuest;
        $event['guest_count'] = count($event['guests']);
        return count($event['guests']) - 1;
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
        '0' => 'Û°',
        '1' => 'Û±',
        '2' => 'Û²',
        '3' => 'Û³',
        '4' => 'Û´',
        '5' => 'Ûµ',
        '6' => 'Û¶',
        '7' => 'Û·',
        '8' => 'Û¸',
        '9' => 'Û¹'
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
    if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $trimmed, $matches)) {
        return '';
    }
    $hours = (int)$matches[1];
    $minutes = (int)$matches[2];
    $seconds = isset($matches[3]) && $matches[3] !== '' ? (int)$matches[3] : 0;
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
}

function getEventTimeMinutes(string $value): int
{
    $parts = explode(':', $value);
    $hours = isset($parts[0]) ? (int)$parts[0] : 0;
    $minutes = isset($parts[1]) ? (int)$parts[1] : 0;
    return ($hours * 60) + $minutes;
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
        $event['print_entry_modal'] = isset($event['print_entry_modal']) ? (bool)$event['print_entry_modal'] : true;
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
    return array_values(array_map('normalizeEventForResponse', $events));
}

function normalizeEventForResponse(array $event): array
{
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
        'join_left_time' => (string)($event['join_left_time'] ?? ''),
        'join_end_time' => (string)($event['join_end_time'] ?? ''),
        'print_entry_modal' => isset($event['print_entry_modal']) ? (bool)$event['print_entry_modal'] : true,
        'guest_count' => (int)($event['guest_count'] ?? count($guests)),
        'mapping' => is_array($event['mapping'] ?? null) ? $event['mapping'] : [],
        'purelist' => (string)($event['purelist'] ?? ''),
        'source' => is_array($event['source'] ?? null) ? $event['source'] : null,
        'guests' => $guests,
        'created_at' => (string)($event['created_at'] ?? ''),
        'invite_card_template' => is_array($event['invite_card_template'] ?? null) ? $event['invite_card_template'] : [],
        'updated_at' => (string)($event['updated_at'] ?? '')
    ];
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
            $gender = 'Ù†Ø§Ù…Ø´Ø®Øµ';
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

function createGuestInvitePages(array $guests, array $event): void
{
    $invRoot = __DIR__ . '/../inv';
    if (!is_dir($invRoot)) {
        if (!mkdir($invRoot, 0755, true) && !is_dir($invRoot)) {
            return;
        }
    }

    foreach ($guests as $guest) {
        $code = normalizeInviteCodeDigits((string)($guest['invite_code'] ?? $guest['code'] ?? ''));
        if ($code === '') {
            continue;
        }
        $guestDir = $invRoot . '/' . $code;
        if (!is_dir($guestDir) && !mkdir($guestDir, 0755, true) && !is_dir($guestDir)) {
            error_log('Unable to create invite directory for ' . $code);
            continue;
        }
        ensureGuestInviteIndexPage($code);
    }
}

function ensureGuestInviteIndexPage(string $code): void
{
    $normalized = normalizeInviteCodeDigits($code);
    if ($normalized === '') {
        return;
    }
    $invRoot = __DIR__ . '/../inv';
    if (!is_dir($invRoot)) {
        if (!mkdir($invRoot, 0755, true) && !is_dir($invRoot)) {
            return;
        }
    }
    $guestDir = $invRoot . '/' . $normalized;
    if (!is_dir($guestDir) && !mkdir($guestDir, 0755, true) && !is_dir($guestDir)) {
        return;
    }
    $indexPath = $guestDir . '/index.php';
    $page = <<<'HTML'
<!doctype html>
<html lang="fa" dir="rtl">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Card Invite</title>
    <style>
      :root {
        background: #ffffff;
      }
      * {
        box-sizing: border-box;
      }
      body {
        margin: 0;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #ffffff;
      }
      img {
        max-width: 100%;
        max-height: 100vh;
        height: auto;
        display: block;
      }
    </style>
  </head>
  <body>
    <img src="InviteCard.jpg" alt="Invite Card" loading="eager" />
  </body>
</html>
HTML;
    @file_put_contents($indexPath, $page);
}

