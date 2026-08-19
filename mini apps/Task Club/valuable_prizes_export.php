<?php
declare(strict_types=1);


require_once __DIR__ . '/tc-database-runtime.php';
require_once __DIR__ . '/../../api/lib/tab-permissions.php';
require_once __DIR__ . '/invitees_csv_safety.php';

$user = requireTabPermissionFromSession('task-club', false);
if (!userHasPermissionId($user, 'task-club:export')) {
  denyPanelAccess(403, 'You do not have permission to access this Task Club section.', false);
}

$eventDir = __DIR__ . '/TC Event';
$csvPath = $eventDir . '/Invitees mapped.csv';
$mappingPath = $eventDir . '/TC Mapped.json';

function tcValuableNormalize(string $value): string {
  $value = str_replace("\xEF\xBB\xBF", '', trim($value));
  $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
  return trim((string)preg_replace('/\s+/u', ' ', $value));
}
function tcValuableHeaderIndex(array $header, array $names): int {
  $targets = [];
  foreach ($names as $name) $targets[tcValuableNormalize((string)$name)] = true;
  foreach ($header as $index => $name) {
    if (isset($targets[tcValuableNormalize((string)$name)])) return (int)$index;
  }
  return -1;
}
function tcValuableMappedIndex(array $header, array $mapping, array $keys, array $fallback): int {
  foreach ($keys as $key) {
    if (is_numeric($mapping[$key] ?? null)) {
      $index = (int)$mapping[$key];
      if ($index >= 0 && $index < count($header)) return $index;
    }
  }
  return tcValuableHeaderIndex($header, $fallback);
}
function tcValuableCell(array $row, int $index): string {
  return $index >= 0 ? trim((string)($row[$index] ?? '')) : '';
}
function tcValuableNumber(string $value): float {
  $normalized = str_replace([',', ' ', '٬', '،'], '', $value);
  return is_numeric($normalized) ? max(0, (float)$normalized) : 0.0;
}
function tcValuableXml($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

$rows = tcInviteesCsvReadRowsSnapshot($csvPath);
$header = is_array($rows[0] ?? null) ? $rows[0] : [];
$mapping = [];
if (tcDbIsFile($mappingPath)) {
  $decoded = json_decode((string)tcDbFileGetContents($mappingPath), true);
  if (is_array($decoded)) $mapping = $decoded;
}

$workIdIndex = tcValuableMappedIndex($header, $mapping, ['workId', 'username'], ['work id', 'workid', 'username']);
$fullNameIndex = tcValuableMappedIndex($header, $mapping, ['fullName', 'fullname', 'name'], ['full name', 'fullname']);
$firstNameIndex = tcValuableMappedIndex($header, $mapping, ['firstName', 'first_name'], ['first name', 'firstname', 'name']);
$lastNameIndex = tcValuableMappedIndex($header, $mapping, ['lastName', 'last_name'], ['last name', 'lastname', 'family', 'surname']);
$nationalIdIndex = tcValuableMappedIndex($header, $mapping, ['nationalId', 'national_id'], ['national id', 'nationalid', 'کد ملی', 'شماره ملی']);
$phoneIndex = tcValuableMappedIndex($header, $mapping, ['phoneNumber', 'phone_number', 'phone'], ['phone number', 'phone', 'mobile', 'شماره موبایل']);
$scoreIndex = tcValuableHeaderIndex($header, ['score', 'total score']);
$prizesIndex = tcValuableHeaderIndex($header, ['each level won prize']);
$totalIndex = tcValuableHeaderIndex($header, ['total prize won', 'مجموع جوایز برنده شده']);

$exportRows = [];
for ($index = 1; $index < count($rows); $index++) {
  $row = is_array($rows[$index] ?? null) ? $rows[$index] : [];
  $total = tcValuableNumber(tcValuableCell($row, $totalIndex));
  if ($total <= 0) continue;
  $fullName = tcValuableCell($row, $fullNameIndex);
  if ($fullName === '') $fullName = trim(tcValuableCell($row, $firstNameIndex) . ' ' . tcValuableCell($row, $lastNameIndex));
  $exportRows[] = [
    $fullName,
    tcValuableCell($row, $workIdIndex),
    tcValuableCell($row, $nationalIdIndex),
    tcValuableCell($row, $phoneIndex),
    tcValuableNumber(tcValuableCell($row, $scoreIndex)),
    tcValuableCell($row, $prizesIndex),
    $total
  ];
}

header('Content-Disposition: attachment; filename="valuable-prize-winners.xlsx"');
header('Pragma: no-cache');
header('Expires: 0');
require_once dirname(__DIR__, 2) . '/api/lib/xlsx-export.php';
ob_start();
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
 <Worksheet ss:Name="Valuable Prizes"><Table>
  <Row>
   <?php foreach (['fullname', 'Work ID', 'National ID', 'Phone Number', 'Total Score', 'Valuable Prizes', 'Sum of Valuable Prizes'] as $label): ?>
   <Cell><Data ss:Type="String"><?= tcValuableXml($label) ?></Data></Cell>
   <?php endforeach; ?>
  </Row>
  <?php foreach ($exportRows as $dataRow): ?>
  <Row>
   <?php foreach ($dataRow as $cellIndex => $value): ?>
   <Cell><Data ss:Type="<?= in_array($cellIndex, [4, 6], true) ? 'Number' : 'String' ?>"><?= tcValuableXml($value) ?></Data></Cell>
   <?php endforeach; ?>
  </Row>
  <?php endforeach; ?>
 </Table></Worksheet>
</Workbook>
<?php
$spreadsheetXml = (string)ob_get_clean();
appXlsxSend(appXlsxFromSpreadsheetXml($spreadsheetXml), 'valuable-prize-winners.xlsx');
