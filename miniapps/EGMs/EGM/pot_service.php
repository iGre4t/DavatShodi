<?php
declare(strict_types=1);

require_once __DIR__ . '/invitees_csv_safety.php';

const EGM_POT_LEVELS_FILE = __DIR__ . '/EGM Prize Levels.json';
const EGM_POT_INVITEES_FILE = __DIR__ . '/EGM Event/Invitees mapped.csv';
const EGM_POT_MAPPING_FILE = __DIR__ . '/EGM Event/EGM Mapped.json';
const EGM_POT_DATA_DIR = __DIR__ . '/data/pots';
const EGM_POT_STORAGE_PREFIX = '<?php http_response_code(404); exit; ?>';

function egmPotReadJson(string $path, $fallback)
{
  if (!is_file($path)) {
    return $fallback;
  }
  $raw = file_get_contents($path);
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
  if (!is_file($path)) $path = egmPotLegacyWinnersPath($levelId);
  if (!is_file($path)) return [];
  $raw = file_get_contents($path);
  return is_string($raw) ? egmPotDecodeWinnerStorage($raw) : [];
}

function egmPotMutateWinners(string $levelId, callable $callback): array
{
  $path = egmPotWinnersPath($levelId);
  $dir = dirname($path);
  if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
    throw new RuntimeException('Unable to create Pot data directory.');
  }
  $handle = fopen($path, 'c+');
  if ($handle === false || !flock($handle, LOCK_EX)) {
    if (is_resource($handle)) fclose($handle);
    throw new RuntimeException('Unable to lock Pot winner data.');
  }
  rewind($handle);
  $raw = stream_get_contents($handle);
  $newStorageIsEmpty = !is_string($raw) || $raw === '';
  $winners = !$newStorageIsEmpty ? egmPotDecodeWinnerStorage($raw) : [];
  if ($newStorageIsEmpty && is_file(egmPotLegacyWinnersPath($levelId))) {
    $legacyRaw = file_get_contents(egmPotLegacyWinnersPath($levelId));
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

function egmPotParticipants(): array
{
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
    $digits = preg_replace('/\D+/', '', $workId);
    $code = $digits !== '' ? str_pad(substr($digits, -4), 4, '0', STR_PAD_LEFT) : str_pad((string)($rowIndex % 10000), 4, '0', STR_PAD_LEFT);
    $participants[] = [
      'key' => $key,
      'workId' => $workId,
      'code' => $code,
      'fullName' => $fullName,
      'nationalId' => trim((string)($row[$nationalIdIndex] ?? '')),
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
