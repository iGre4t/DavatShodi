<?php
declare(strict_types=1);


require_once __DIR__ . '/egm-database-runtime.php';
require_once __DIR__ . '/invitees_csv_safety.php';

const EGM_POT_LEVELS_FILE = __DIR__ . '/EGM Prize Levels.json';
const EGM_POT_INVITEES_FILE = __DIR__ . '/EGM Event/Invitees mapped.csv';
const EGM_POT_MAPPING_FILE = __DIR__ . '/EGM Event/EGM Mapped.json';
const EGM_POT_DATA_DIR = __DIR__ . '/data/pots';
const EGM_POT_STORAGE_PREFIX = '<?php http_response_code(404); exit; ?>';

function egmPotReadJson(string $path, $fallback)
{
  if (!egmDbIsFile($path)) {
    return $fallback;
  }
  $raw = egmDbFileGetContents($path);
  $decoded = is_string($raw) ? json_decode($raw, true) : null;
  return is_array($decoded) ? $decoded : $fallback;
}

function egmPotLevel(string $levelId): ?array
{
  foreach (egmPotReadJson(EGM_POT_LEVELS_FILE, []) as $level) {
    if (!is_array($level) || trim((string)($level['id'] ?? '')) !== $levelId) {
      continue;
    }
    if (strtolower(trim((string)($level['type'] ?? ''))) !== 'pot') {
      return null;
    }
    $settings = is_array($level['potSettings'] ?? null) ? $level['potSettings'] : [];
    $name = trim((string)($level['name'] ?? 'Pot'));
    $prizeName = trim((string)($settings['prizeName'] ?? ($settings['prize_name'] ?? '')));
    return [
      'id' => $levelId,
      'name' => $name !== '' ? $name : 'Pot',
      'type' => 'pot',
      'score' => max(1, (int)($level['score'] ?? 1)),
      'potSettings' => [
        'title' => trim((string)($settings['title'] ?? '')) ?: $name,
        'winnerLimit' => max(1, min(1000, (int)($settings['winnerLimit'] ?? 1))),
        'prizeName' => function_exists('mb_substr') ? mb_substr($prizeName, 0, 160, 'UTF-8') : substr($prizeName, 0, 160),
        'locked' => !empty($settings['locked'])
      ]
    ];
  }
  return null;
}

function egmPotSafeLevelId(string $levelId): string
{
  $safe = trim((string)preg_replace('/[^A-Za-z0-9_.-]+/', '_', $levelId), '._-');
  return $safe !== '' ? $safe : 'pot-' . substr(hash('sha256', $levelId), 0, 16);
}

function egmPotWinnersPath(string $levelId): string
{
  return EGM_POT_DATA_DIR . '/' . egmPotSafeLevelId($levelId) . '/winners.php';
}

function egmPotLegacyWinnersPath(string $levelId): string
{
  return EGM_POT_DATA_DIR . '/' . egmPotSafeLevelId($levelId) . '/winners.json';
}

function egmPotDecodeWinnerStorage(string $raw): array
{
  if (str_starts_with($raw, EGM_POT_STORAGE_PREFIX)) {
    $raw = ltrim(substr($raw, strlen(EGM_POT_STORAGE_PREFIX)));
  }
  $decoded = json_decode($raw, true);
  $winners = is_array($decoded['winners'] ?? null) ? $decoded['winners'] : $decoded;
  return is_array($winners) ? array_values(array_filter($winners, 'is_array')) : [];
}

function egmPotReadWinners(string $levelId): array
{
  $path = egmPotWinnersPath($levelId);
  if (!egmDbIsFile($path)) $path = egmPotLegacyWinnersPath($levelId);
  if (!egmDbIsFile($path)) return [];
  $raw = egmDbFileGetContents($path);
  $winners = is_string($raw) ? egmPotDecodeWinnerStorage($raw) : [];
  $maps = egmPotGuestNumberMaps();
  foreach ($winners as &$winner) {
    $participant = is_array($winner['participant'] ?? null) ? $winner['participant'] : [];
    $nationalId = trim((string)($participant['nationalId'] ?? ''));
    $workId = strtolower(trim((string)($participant['workId'] ?? ($winner['participantKey'] ?? ''))));
    $number = (string)($maps['national'][$nationalId] ?? $maps['work'][$workId] ?? '');
    if ($number !== '') {
      $participant['guestNumber'] = $number;
      $participant['code'] = $number;
      $winner['participant'] = $participant;
    }
  }
  unset($winner);
  return $winners;
}

function egmPotMutateWinners(string $levelId, callable $callback): array
{
  $path = egmPotWinnersPath($levelId);
  $dir = dirname($path);
  if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
    throw new RuntimeException('Unable to create Pot data directory.');
  }
  $handle = egmDbFopen($path, 'c+');
  if ($handle === false || !flock($handle, LOCK_EX)) {
    if (is_resource($handle)) fclose($handle);
    throw new RuntimeException('Unable to lock Pot winner data.');
  }
  rewind($handle);
  $raw = stream_get_contents($handle);
  $newStorageIsEmpty = !is_string($raw) || $raw === '';
  $winners = !$newStorageIsEmpty ? egmPotDecodeWinnerStorage($raw) : [];
  if ($newStorageIsEmpty && egmDbIsFile(egmPotLegacyWinnersPath($levelId))) {
    $legacyRaw = egmDbFileGetContents(egmPotLegacyWinnersPath($levelId));
    if (is_string($legacyRaw)) $winners = egmPotDecodeWinnerStorage($legacyRaw);
  }
  $next = $callback(array_values(array_filter($winners, 'is_array')));
  if (!is_array($next)) {
    flock($handle, LOCK_UN);
    fclose($handle);
    throw new RuntimeException('Invalid Pot winner update.');
  }
  $json = json_encode([
    'levelId' => $levelId,
    'updatedAt' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format(DATE_ATOM),
    'winners' => array_values($next)
  ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $encoded = is_string($json) ? EGM_POT_STORAGE_PREFIX . "\n" . $json : false;
  rewind($handle);
  ftruncate($handle, 0);
  $written = false;
  if (is_string($encoded)) {
    $offset = 0;
    $length = strlen($encoded);
    $written = true;
    while ($offset < $length) {
      $count = fwrite($handle, substr($encoded, $offset));
      if (!is_int($count) || $count <= 0) {
        $written = false;
        break;
      }
      $offset += $count;
    }
  }
  if ($written) {
    $written = fflush($handle);
    if ($written && function_exists('fsync')) $written = fsync($handle);
  }
  @chmod($path, 0600);
  flock($handle, LOCK_UN);
  fclose($handle);
  if (!$written) {
    throw new RuntimeException('Unable to save Pot winner data.');
  }
  return array_values($next);
}

function egmPotNormalizeHeader(string $value): string
{
  $value = str_replace("\xEF\xBB\xBF", '', trim($value));
  $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
  return trim((string)preg_replace('/\s+/u', ' ', $value));
}

function egmPotHeaderIndex(array $header, array $names): int
{
  $targets = [];
  foreach ($names as $name) $targets[egmPotNormalizeHeader((string)$name)] = true;
  foreach ($header as $index => $name) {
    if (isset($targets[egmPotNormalizeHeader((string)$name)])) return (int)$index;
  }
  return -1;
}

function egmPotMappedIndex(array $header, array $mapping, array $keys, array $fallback): int
{
  foreach ($keys as $key) {
    if (is_numeric($mapping[$key] ?? null)) {
      $index = (int)$mapping[$key];
      if ($index >= 0 && $index < count($header)) return $index;
    }
  }
  return egmPotHeaderIndex($header, $fallback);
}

function egmPotGuestNumberMaps(): array
{
  try {
    if (!function_exists('connectDatabase') || !function_exists('egmInstanceRegistryForDirectory')) {
      return ['national' => [], 'work' => []];
    }
    $root = __DIR__;
    while (!egmDbIsFile($root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'config.php')) {
      $parent = dirname($root);
      if ($parent === $root) return ['national' => [], 'work' => []];
      $root = $parent;
    }
    $pdo = connectDatabase(loadConfig($root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'config.php'));
    $registry = $pdo instanceof PDO ? egmInstanceRegistryForDirectory($pdo, __DIR__) : null;
    if (!$pdo instanceof PDO || !is_array($registry)) return ['national' => [], 'work' => []];
    $tables = ensureEgmInstanceTables($pdo, (string)$registry['code']);
    $rows = $pdo->query("SELECT `national_id`, `work_id`, `guest_number` FROM `{$tables['users']}` WHERE `guest_number` IS NOT NULL")?->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $maps = ['national' => [], 'work' => []];
    foreach ($rows as $row) {
      $number = trim((string)($row['guest_number'] ?? ''));
      $nationalId = trim((string)($row['national_id'] ?? ''));
      $workId = strtolower(trim((string)($row['work_id'] ?? '')));
      if ($number === '') continue;
      if ($nationalId !== '') $maps['national'][$nationalId] = $number;
      if ($workId !== '') $maps['work'][$workId] = $number;
    }
    return $maps;
  } catch (Throwable $error) {
    error_log('EGM Pot guest-number lookup failed: ' . $error->getMessage());
    return ['national' => [], 'work' => []];
  }
}

/** @return array<int,array<string,mixed>>|null Null means database unavailable. */
function egmPotDatabaseParticipants(): ?array
{
  try {
    if (!function_exists('connectDatabase') || !function_exists('egmInstanceRegistryForDirectory')) return null;
    $root = __DIR__;
    while (!egmDbIsFile($root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'config.php')) {
      $parent = dirname($root);
      if ($parent === $root) return null;
      $root = $parent;
    }
    $pdo = connectDatabase(loadConfig($root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'config.php'));
    $registry = $pdo instanceof PDO ? egmInstanceRegistryForDirectory($pdo, __DIR__) : null;
    if (!$pdo instanceof PDO || !is_array($registry)) return null;
    $tables = ensureEgmInstanceTables($pdo, (string)$registry['code']);
    $rows = $pdo->query(
      "SELECT `id`, `guest_number`, `work_id`, `first_name`, `last_name`, `national_id`, `phone_number`, `total_score`, `state_json` "
      . "FROM `{$tables['users']}` WHERE `is_active` = 1 ORDER BY CAST(`guest_number` AS UNSIGNED), `id`"
    )?->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_map(static function (array $row): array {
      $workId = trim((string)($row['work_id'] ?? ''));
      $nationalId = trim((string)($row['national_id'] ?? ''));
      $guestNumber = trim((string)($row['guest_number'] ?? ''));
      $fullName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
      if ($fullName === '') $fullName = $workId !== '' ? $workId : 'Participant ' . (int)$row['id'];
      $credentials = json_decode((string)($row['state_json'] ?? ''), true);
      if (!is_array($credentials)) $credentials = [];
      $credentials += [
        'Guest Number' => $guestNumber, 'Work ID' => $workId, 'National ID' => $nationalId,
        'Phone Number' => (string)($row['phone_number'] ?? ''), 'Full Name' => $fullName,
      ];
      return [
        'key' => $workId !== '' ? $workId : ('egm-user:' . (int)$row['id']),
        'workId' => $workId,
        'code' => $guestNumber,
        'guestNumber' => $guestNumber,
        'fullName' => $fullName,
        'nationalId' => $nationalId,
        'phoneNumber' => (string)($row['phone_number'] ?? ''),
        'score' => max(0, (int)($row['total_score'] ?? 0)),
        'credentials' => $credentials,
      ];
    }, $rows);
  } catch (Throwable $error) {
    error_log('EGM Pot database participant lookup failed: ' . $error->getMessage());
    return null;
  }
}

function egmPotParticipants(): array
{
  $databaseParticipants = egmPotDatabaseParticipants();
  if (is_array($databaseParticipants)) return $databaseParticipants;
  $rows = egmInviteesCsvReadRowsSnapshot(EGM_POT_INVITEES_FILE);
  if (!$rows || !is_array($rows[0] ?? null)) return [];
  $header = $rows[0];
  $mapping = egmPotReadJson(EGM_POT_MAPPING_FILE, []);
  $workIdIndex = egmPotMappedIndex($header, $mapping, ['workId', 'username'], ['work id', 'workid', 'username', 'user name', 'national id']);
  $fullNameIndex = egmPotMappedIndex($header, $mapping, ['fullName', 'fullname', 'name'], ['full name', 'fullname']);
  $firstNameIndex = egmPotMappedIndex($header, $mapping, ['firstName', 'first_name'], ['first name', 'firstname', 'name']);
  $lastNameIndex = egmPotMappedIndex($header, $mapping, ['lastName', 'last_name'], ['last name', 'lastname', 'family', 'surname']);
  $nationalIdIndex = egmPotMappedIndex($header, $mapping, ['nationalId', 'national_id'], ['national id', 'nationalid', 'کد ملی', 'شماره ملی']);
  $phoneIndex = egmPotMappedIndex($header, $mapping, ['phoneNumber', 'phone_number', 'phone'], ['phone number', 'phone', 'mobile', 'شماره موبایل', 'شماره تلفن']);
  $scoreIndex = egmPotHeaderIndex($header, ['score', 'total score']);
  $guestNumberMaps = egmPotGuestNumberMaps();
  $participants = [];
  for ($rowIndex = 1; $rowIndex < count($rows); $rowIndex++) {
    $row = is_array($rows[$rowIndex] ?? null) ? $rows[$rowIndex] : [];
    $credentials = [];
    foreach ($header as $index => $label) {
      $key = trim((string)$label);
      if ($key !== '') $credentials[$key] = (string)($row[$index] ?? '');
    }
    $workId = trim((string)($row[$workIdIndex] ?? ''));
    $firstName = trim((string)($row[$firstNameIndex] ?? ''));
    $lastName = trim((string)($row[$lastNameIndex] ?? ''));
    $fullName = trim((string)($row[$fullNameIndex] ?? ''));
    if ($fullName === '') $fullName = trim($firstName . ' ' . $lastName);
    if ($fullName === '') $fullName = $workId !== '' ? $workId : 'Participant ' . $rowIndex;
    $key = $workId !== '' ? $workId : hash('sha256', json_encode($row));
    $nationalId = trim((string)($row[$nationalIdIndex] ?? ''));
    $guestNumber = (string)($guestNumberMaps['national'][$nationalId]
      ?? $guestNumberMaps['work'][strtolower($workId)] ?? '');
    $code = $guestNumber !== '' ? $guestNumber : str_pad((string)$rowIndex, 4, '0', STR_PAD_LEFT);
    $participants[] = [
      'key' => $key,
      'workId' => $workId,
      'code' => $code,
      'guestNumber' => $guestNumber,
      'fullName' => $fullName,
      'nationalId' => $nationalId,
      'phoneNumber' => trim((string)($row[$phoneIndex] ?? '')),
      'score' => max(0, (int)str_replace([',', ' '], '', (string)($row[$scoreIndex] ?? 0))),
      'credentials' => $credentials
    ];
  }
  return $participants;
}

function egmPotEligibleParticipants(array $level, array $winners): array
{
  $won = [];
  foreach ($winners as $winner) $won[(string)($winner['participantKey'] ?? '')] = true;
  return array_values(array_filter(egmPotParticipants(), static function (array $participant) use ($level, $won): bool {
    return $participant['score'] >= $level['score'] && !isset($won[$participant['key']]);
  }));
}

function egmPotPublicParticipants(array $participants): array
{
  return array_map(static fn (array $participant): array => [
    'code' => (string)($participant['code'] ?? ''),
    'guestNumber' => (string)($participant['guestNumber'] ?? ($participant['code'] ?? '')),
    'fullName' => (string)($participant['fullName'] ?? ''),
    'workId' => (string)($participant['workId'] ?? ''),
    'score' => (int)($participant['score'] ?? 0)
  ], $participants);
}

function egmPotPublicWinner(array $winner): array
{
  $participant = is_array($winner['participant'] ?? null) ? $winner['participant'] : [];
  return [
    'participantKey' => (string)($winner['participantKey'] ?? ''),
    'code' => (string)($participant['code'] ?? ''),
    'guestNumber' => (string)($participant['guestNumber'] ?? ($participant['code'] ?? '')),
    'fullName' => (string)($participant['fullName'] ?? ''),
    'workId' => (string)($participant['workId'] ?? ''),
    'score' => (int)($participant['score'] ?? 0),
    'selectedAt' => (string)($winner['selectedAt'] ?? ''),
    'selectedBy' => (string)($winner['selectedBy'] ?? '')
  ];
}

function egmPotPublicWinners(array $winners): array
{
  return array_map('egmPotPublicWinner', $winners);
}
