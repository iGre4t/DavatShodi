<?php
declare(strict_types=1);

require_once __DIR__ . '/../mini apps/Task Club/pot_service.php';

function potSecurityAssert(bool $condition, string $message): void
{
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
}

$potRoot = str_replace('\\', '/', TC_POT_DATA_DIR) . '/';
foreach (['..', '.', '../outside', '..\\outside', 'valid-level_1'] as $levelId) {
  $path = str_replace('\\', '/', tcPotWinnersPath($levelId));
  potSecurityAssert(
    str_starts_with($path, $potRoot),
    "Winner path escaped the Pot data directory for level ID: {$levelId}"
  );
  potSecurityAssert(
    !str_contains(substr($path, strlen($potRoot)), '../'),
    "Winner path retained a parent-directory segment for level ID: {$levelId}"
  );
}

$storedWinners = [['participantKey' => 'EMP-1234']];
$storagePayload = TC_POT_STORAGE_PREFIX . "\n" . json_encode(['winners' => $storedWinners]);
potSecurityAssert(
  tcPotDecodeWinnerStorage($storagePayload) === $storedWinners,
  'Protected winner storage could not be decoded by the application.'
);
potSecurityAssert(
  str_starts_with(TC_POT_STORAGE_PREFIX, '<?php'),
  'Winner storage does not begin with a PHP execution guard.'
);

$public = tcPotPublicParticipants([[
  'code' => '1234',
  'fullName' => 'Test User',
  'workId' => 'EMP-1234',
  'score' => 100,
  'credentials' => ['password' => 'secret', 'phone' => '09120000000']
]]);

potSecurityAssert(count($public) === 1, 'Expected one public participant.');
potSecurityAssert(!array_key_exists('credentials', $public[0]), 'Public participant leaked CSV credentials.');
potSecurityAssert($public[0]['workId'] === 'EMP-1234', 'Expected public Work ID was removed.');

fwrite(STDOUT, "Task Club Pot security tests passed.\n");
