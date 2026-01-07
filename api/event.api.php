<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../lib/event_entrypoints.php';
require_once __DIR__ . '/../lib/event_store.php';
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

date_default_timezone_set('Asia/Tehran');

header('Content-Type: application/json; charset=UTF-8');

if (!defined('EVENTS_ROOT')) {
    define('EVENTS_ROOT', __DIR__ . '/../events');
}

ensureEventStorageReady(EVENTS_ROOT);

$scopedEventCode = defined('EVENT_SCOPED_EVENT_CODE') ? trim((string)EVENT_SCOPED_EVENT_CODE) : '';
if ($scopedEventCode !== '') {
    if (!isset($_POST['event_code']) || trim((string)($_POST['event_code'] ?? '')) === '') {
        $_POST['event_code'] = $scopedEventCode;
    }
    if (!isset($_GET['event_code']) || trim((string)($_GET['event_code'] ?? '')) === '') {
        $_GET['event_code'] = $scopedEventCode;
    }
    if (!isset($_POST['code']) || trim((string)($_POST['code'] ?? '')) === '') {
        $_POST['code'] = $scopedEventCode;
    }
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
            $activeEvent = $store['events'][$activeEventIndex];
            $activeEventName = (string)($activeEvent['name'] ?? '');
            $logPath = getInviteLogPathForEvent($activeEvent, $eventsRoot);
            if (!ensureInviteLogFilePath($logPath)) {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Failed to initialize invite log file.']);
                exit;
            }
            try {
                $log = appendInviteLogToFile($logPath, [
                'type' => 'not_found',
                'national_id' => $nationalId,
                'event_code' => $activeEventCode,
                'event_name' => $activeEventName,
                'guest_name' => '',
                'invite_code' => '',
                'timestamp' => $nowString,
                'message' => 'National ID not found in the active event list.'
                ]);
            } catch (RuntimeException $exception) {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => $exception->getMessage()]);
                exit;
            }
            if (!saveGuestStore($storePath, $store)) {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Failed to persist guest data.']);
                exit;
            }
            $logList = normalizeInviteLogs(loadInviteLogsFromFile($logPath));
            echo json_encode([
                'status' => 'ok',
                'event_name' => $activeEventName,
                'outcome' => 'not_found',
                'log' => $log,
                'logs' => $logList,
                'stats' => computeGuestStats($store['events'][$activeEventIndex] ?? []),
                'active_event' => normalizeEventForResponse($activeEvent)
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
        $logPath = getInviteLogPathForEvent($event, $eventsRoot);
        if (!ensureInviteLogFilePath($logPath)) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to initialize invite log file.']);
            exit;
        }
        try {
            $log = appendInviteLogToFile($logPath, [
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
        } catch (RuntimeException $exception) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $exception->getMessage()]);
            exit;
        }

        if (!saveGuestStore($storePath, $store)) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to persist guest data.']);
            exit;
        }
        $logList = normalizeInviteLogs(loadInviteLogsFromFile($logPath));

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
            'logs' => $logList,
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
            'logs' => getInviteLogsForEventCode($store['events'], $eventsRoot, $eventCode)
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
        $dateEntered = trim((string)($_POST['date_entered'] ?? ''));
        $dateExited = trim((string)($_POST['date_exited'] ?? ''));
        $enteredParts = splitDateTimeValue($dateEntered);
        $exitedParts = splitDateTimeValue($dateExited);

        $store['events'][$eventIndex]['guests'][$guestIndex] = array_merge(
            $store['events'][$eventIndex]['guests'][$guestIndex],
            [
                'firstname' => trim((string)($_POST['firstname'] ?? '')),
                'lastname' => trim((string)($_POST['lastname'] ?? '')),
                'gender' => trim((string)($_POST['gender'] ?? '')),
                'national_id' => normalizeNationalId((string)($_POST['national_id'] ?? '')),
                'phone_number' => trim((string)($_POST['phone_number'] ?? '')),
                'date_entered' => $dateEntered,
                'date_exited' => $dateExited,
                'join_date' => $enteredParts['date'],
                'join_time' => $enteredParts['time'],
                'left_date' => $exitedParts['date'],
                'left_time' => $exitedParts['time']
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
            'logs' => getInviteLogsForEventCode($store['events'], $eventsRoot, $eventCode)
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
              'logs' => getInviteLogsForEventCode($store['events'], $eventsRoot, $eventCode)
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
              'logs' => getInviteLogsForEventCode($store['events'], $eventsRoot, $eventCode)
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
        } elseif ($action === 'refresh_event_purelist') {
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
            $event = &$store['events'][$eventIndex];
            if (!syncEventPurelist($event, $eventsRoot)) {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Failed to refresh the SMS export file.']);
                exit;
            }
            $event['updated_at'] = date('c');
            $event['guest_count'] = count($event['guests'] ?? []);
            if (!saveGuestStore($storePath, $store)) {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Failed to persist event data after refresh.']);
                exit;
            }
            echo json_encode([
                'status' => 'ok',
                'message' => 'SMS export refreshed successfully.',
                'events' => normalizeEventsForResponse($store['events']),
                'active_event' => normalizeEventForResponse($event)
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
                'logs' => getInviteLogsForEventCode($store['events'], $eventsRoot, $eventCode)
            ]);
            createGuestInvitePages(
                $store['events'][$eventIndex]['guests'] ?? [],
                $store['events'][$eventIndex] ?? []
            );
            exit;
        } elseif ($action === 'list_missing_invite_cards') {
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
            $guests = is_array($event['guests'] ?? null) ? $event['guests'] : [];
            $missing = [];
            $invRoot = __DIR__ . '/../inv';
            foreach ($guests as $guest) {
                $inviteCode = normalizeInviteCodeDigits((string)($guest['invite_code'] ?? $guest['code'] ?? ''));
                if ($inviteCode === '') {
                    continue;
                }
                $cardPath = $invRoot . '/' . $inviteCode . '/InviteCard.jpg';
                if (is_file($cardPath)) {
                    continue;
                }
                $normalizedNationalId = normalizeNationalId((string)($guest['national_id'] ?? ''));
                $missing[] = [
                    'inviteCode' => $inviteCode,
                    'invite_code' => $inviteCode,
                    'code' => $inviteCode,
                    'number' => (int)($guest['number'] ?? 0),
                    'firstname' => (string)($guest['firstname'] ?? ''),
                    'lastname' => (string)($guest['lastname'] ?? ''),
                    'gender' => (string)($guest['gender'] ?? ''),
                    'national_id' => $normalizedNationalId,
                    'nationalId' => $normalizedNationalId,
                    'phone_number' => (string)($guest['phone_number'] ?? ''),
                    'sms_link' => (string)($guest['sms_link'] ?? ''),
                    'smsLink' => (string)($guest['sms_link'] ?? '')
                ];
            }
            echo json_encode(['status' => 'ok', 'missing' => $missing]);
            exit;
        } elseif ($action === 'save_generated_invite_card') {
            $inviteCode = normalizeInviteCodeDigits((string)($_POST['invite_code'] ?? ''));
            $imageData = (string)($_POST['image_data'] ?? '');
            $overwrite = filter_var($_POST['overwrite'] ?? '', FILTER_VALIDATE_BOOLEAN);
            if ($inviteCode === '' || $imageData === '') {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Invite code and image data are required.']);
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
            if (!$overwrite && is_file($targetPath)) {
                ensureGuestInviteIndexPage($inviteCode);
                echo json_encode(['status' => 'ok', 'message' => 'Invite card already exists.']);
                exit;
            }
            if (!preg_match('/^data:image\/(png|jpe?g);base64,(.+)$/i', $imageData, $matches)) {
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
              'logs' => []
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
        'logs' => []
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
    $logs = [];
    if ($eventCodeParam !== '') {
        $logs = getInviteLogsForEventCode($store['events'], $eventsRoot, $eventCodeParam);
    } elseif (!empty($activeEvent)) {
        $logs = getInviteLogsForEvent($activeEvent, $eventsRoot);
    }
    $normalizedActiveEvent = !empty($activeEvent) ? normalizeEventForResponse($activeEvent) : null;
    echo json_encode([
        'status' => 'ok',
        'event_name' => (string)($activeEvent['name'] ?? ''),
        'events' => normalizeEventsForResponse($store['events']),
        'logs' => $logs,
        'stats' => computeGuestStats($activeEvent),
        'active_event' => $normalizedActiveEvent
    ]);
exit;
