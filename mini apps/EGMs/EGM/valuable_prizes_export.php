<?php
declare(strict_types=1);


require_once __DIR__ . '/egm-database-runtime.php';
require_once __DIR__ . '/egm-security.php';
require_once __DIR__ . '/../../../api/lib/tab-permissions.php';
require_once __DIR__ . '/../../../api/lib/egm-export-filename.php';
require_once __DIR__ . '/invitees_csv_safety.php';
require_once __DIR__ . '/pot_service.php';

$user = requireTabPermissionFromSession('event-guest-manager', false);
if (!userHasPermissionId($user, 'event-guest-manager:export')) {
  denyPanelAccess(403, 'You do not have permission to access this Event Guest Manager section.', false);
}

$eventDir = __DIR__ . '/EGM Event';
$csvPath = $eventDir . '/Invitees mapped.csv';
$mappingPath = $eventDir . '/EGM Mapped.json';

function egmValuableNormalize(string $value): string {
  $value = str_replace("\xEF\xBB\xBF", '', trim($value));
  $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
  return trim((string)preg_replace('/\s+/u', ' ', $value));
}
function egmValuableHeaderIndex(array $header, array $names): int {
  $targets = [];
  foreach ($names as $name) $targets[egmValuableNormalize((string)$name)] = true;
  foreach ($header as $index => $name) {
    if (isset($targets[egmValuableNormalize((string)$name)])) return (int)$index;
  }
  return -1;
}
function egmValuableMappedIndex(array $header, array $mapping, array $keys, array $fallback): int {
  foreach ($keys as $key) {
    if (is_numeric($mapping[$key] ?? null)) {
      $index = (int)$mapping[$key];
      if ($index >= 0 && $index < count($header)) return $index;
    }
  }
  return egmValuableHeaderIndex($header, $fallback);
}
function egmValuableCell(array $row, int $index): string {
  return $index >= 0 ? trim((string)($row[$index] ?? '')) : '';
}
function egmValuableNumber(string $value): float {
  $normalized = str_replace([',', ' ', '٬', '،'], '', $value);
  return is_numeric($normalized) ? max(0, (float)$normalized) : 0.0;
}
function egmValuableXml($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

$rows = egmInviteesCsvReadRowsSnapshot($csvPath);
$header = is_array($rows[0] ?? null) ? $rows[0] : [];
$mapping = [];
if (egmDbIsFile($mappingPath)) {
  $decoded = json_decode((string)egmDbFileGetContents($mappingPath), true);
  if (is_array($decoded)) $mapping = $decoded;
}

$workIdIndex = egmValuableMappedIndex($header, $mapping, ['workId', 'username'], ['work id', 'workid', 'username']);
$fullNameIndex = egmValuableMappedIndex($header, $mapping, ['fullName', 'fullname', 'name'], ['full name', 'fullname']);
$firstNameIndex = egmValuableMappedIndex($header, $mapping, ['firstName', 'first_name'], ['first name', 'firstname', 'name']);
$lastNameIndex = egmValuableMappedIndex($header, $mapping, ['lastName', 'last_name'], ['last name', 'lastname', 'family', 'surname']);
$nationalIdIndex = egmValuableMappedIndex($header, $mapping, ['nationalId', 'national_id'], ['national id', 'nationalid', 'کد ملی', 'شماره ملی']);
$phoneIndex = egmValuableMappedIndex($header, $mapping, ['phoneNumber', 'phone_number', 'phone'], ['phone number', 'phone', 'mobile', 'شماره موبایل']);
$scoreIndex = egmValuableHeaderIndex($header, ['score', 'total score']);
$prizesIndex = egmValuableHeaderIndex($header, ['each level won prize']);
$totalIndex = egmValuableHeaderIndex($header, ['total prize won', 'مجموع جوایز برنده شده']);
$guestNumberMaps = egmPotGuestNumberMaps();

$exportRows = [];
for ($index = 1; $index < count($rows); $index++) {
  $row = is_array($rows[$index] ?? null) ? $rows[$index] : [];
  $total = egmValuableNumber(egmValuableCell($row, $totalIndex));
  if ($total <= 0) continue;
  $fullName = egmValuableCell($row, $fullNameIndex);
  if ($fullName === '') $fullName = trim(egmValuableCell($row, $firstNameIndex) . ' ' . egmValuableCell($row, $lastNameIndex));
  $workId = egmValuableCell($row, $workIdIndex);
  $nationalId = egmValuableCell($row, $nationalIdIndex);
  $guestNumber = (string)($guestNumberMaps['national'][$nationalId]
    ?? $guestNumberMaps['work'][strtolower($workId)] ?? '');
  $exportRows[] = [
    $guestNumber,
    $fullName,
    $workId,
    $nationalId,
    egmValuableCell($row, $phoneIndex),
    egmValuableNumber(egmValuableCell($row, $scoreIndex)),
    egmValuableCell($row, $prizesIndex),
    $total
  ];
}

$filename = egmExportDatedFilename('برندگان جوایز ارزشمند');
header('Content-Disposition: attachment; filename="valuable-prize-winners.xlsx"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Pragma: no-cache');
header('Expires: 0');
require_once dirname(__DIR__, 3) . '/api/lib/xlsx-export.php';
ob_start();
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
 <Worksheet ss:Name="Valuable Prizes"><Table>
  <Row>
   <?php foreach (['Guest Number', 'fullname', 'Work ID', 'National ID', 'Phone Number', 'Total Score', 'Valuable Prizes', 'Sum of Valuable Prizes'] as $label): ?>
   <Cell><Data ss:Type="String"><?= egmValuableXml($label) ?></Data></Cell>
   <?php endforeach; ?>
  </Row>
  <?php foreach ($exportRows as $dataRow): ?>
  <Row>
   <?php foreach ($dataRow as $cellIndex => $value): ?>
   <Cell><Data ss:Type="<?= in_array($cellIndex, [5, 7], true) ? 'Number' : 'String' ?>"><?= egmValuableXml($value) ?></Data></Cell>
   <?php endforeach; ?>
  </Row>
  <?php endforeach; ?>
 </Table></Worksheet>
</Workbook>
<?php
$spreadsheetXml = (string)ob_get_clean();
appXlsxSend(appXlsxFromSpreadsheetXml($spreadsheetXml), $filename);
