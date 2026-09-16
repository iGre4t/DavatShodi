<?php
declare(strict_types=1);


require_once __DIR__ . '/tc-database-runtime.php';
require_once __DIR__ . '/../../../api/lib/tab-permissions.php';
require_once __DIR__ . '/invitees_csv_safety.php';
require_once __DIR__ . '/tc-security.php';

$user = requireTabPermissionFromSession('task-club', false);
if (!userHasPermissionId($user, 'task-club:export')) {
  denyPanelAccess(403, 'You do not have permission to access this Task Club section.', false);
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST'
  || !tcSecurityIsValidCsrfToken(tcSecurityReadCsrfFromRequest())) {
  http_response_code(403);
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode(['status' => 'error', 'message' => 'درخواست معتبر نیست.'], JSON_UNESCAPED_UNICODE);
  exit;
}

const TC_PRIZE_RECEIVED_COLUMN = 'Checked as Received Prize';

function tcPrizeTotalsNormalizeHeader(string $value): string {
  $value = str_replace("\xEF\xBB\xBF", '', trim($value));
  $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
  $value = preg_replace('/\s+/u', ' ', $value);
  return is_string($value) ? trim($value) : '';
}
function tcPrizeTotalsFindHeaderIndex(array $header, array $candidates): int {
  $targets = [];
  foreach ($candidates as $candidate) $targets[tcPrizeTotalsNormalizeHeader((string)$candidate)] = true;
  foreach ($header as $index => $label) {
    if (isset($targets[tcPrizeTotalsNormalizeHeader((string)$label)])) return (int)$index;
  }
  return -1;
}
function tcPrizeTotalsMappedIndex(array $header, array $mapping, array $keys, array $fallbacks): int {
  foreach ($keys as $key) {
    if (is_numeric($mapping[$key] ?? null)) {
      $index = (int)$mapping[$key];
      if ($index >= 0 && $index < count($header)) return $index;
    }
  }
  return tcPrizeTotalsFindHeaderIndex($header, $fallbacks);
}
function tcPrizeTotalsCell(array $row, int $index): string {
  return $index >= 0 ? trim((string)($row[$index] ?? '')) : '';
}
function tcPrizeTotalsNumber(string $value): float {
  $value = str_replace(['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'], ['0','1','2','3','4','5','6','7','8','9'], $value);
  $value = str_replace(['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'], ['0','1','2','3','4','5','6','7','8','9'], $value);
  $value = str_replace([',', '٬', '،', ' '], '', trim($value));
  return is_numeric($value) && is_finite((float)$value) ? max(0, (float)$value) : 0.0;
}
function tcPrizeTotalsIsReceived(string $value): bool {
  return strtoupper(trim($value)) === 'TRUE';
}
function tcPrizeTotalsXml(string $value): string {
  return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

$csvPath = __DIR__ . DIRECTORY_SEPARATOR . 'TC Event' . DIRECTORY_SEPARATOR . 'Invitees mapped.csv';
$action = strtolower(trim((string)($_POST['action'] ?? '')));
if (!in_array($action, ['prepare', 'remaining', 'all'], true)) {
  http_response_code(400);
  echo json_encode(['status' => 'error', 'message' => 'نوع خروجی معتبر نیست.'], JSON_UNESCAPED_UNICODE);
  exit;
}

$rows = tcInviteesCsvReadRowsForUpdate($csvPath);
if (!$rows || !is_array($rows[0] ?? null) || count($rows[0]) === 0) {
  tcInviteesCsvEndTransaction($csvPath);
  http_response_code(500);
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode(['status' => 'error', 'message' => 'فایل دعوت‌شدگان در دسترس نیست.'], JSON_UNESCAPED_UNICODE);
  exit;
}

$header = $rows[0];
$receivedIndex = tcPrizeTotalsFindHeaderIndex($header, [TC_PRIZE_RECEIVED_COLUMN]);
$columnAdded = false;
if ($receivedIndex < 0) {
  $headerWidth = count($header);
  foreach ($rows as $row) {
    if (!is_array($row) || count($row) > $headerWidth) {
      tcInviteesCsvEndTransaction($csvPath);
      http_response_code(409);
      header('Content-Type: application/json; charset=UTF-8');
      echo json_encode(['status' => 'error', 'message' => 'ساختار CSV نامنظم است؛ برای جلوگیری از جابه‌جایی داده هیچ تغییری انجام نشد.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
  }
  if ($action === 'prepare') {
    tcInviteesCsvEndTransaction($csvPath);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
      'status' => 'ok',
      'columnAdded' => false,
      'columnRequired' => true
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $receivedIndex = $headerWidth;
  $rows[0][] = TC_PRIZE_RECEIVED_COLUMN;
  for ($index = 1; $index < count($rows); $index++) {
    $rows[$index] = array_pad($rows[$index], $headerWidth, '');
    $rows[$index][] = 'FALSE';
  }
  if ($action === 'remaining') {
    if (!tcInviteesCsvCommitRows($csvPath, $rows)) {
      http_response_code(500);
      header('Content-Type: application/json; charset=UTF-8');
      echo json_encode(['status' => 'error', 'message' => 'افزودن ستون ایمن انجام نشد؛ فایل قبلی حفظ شد.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $columnAdded = true;
    $rows = tcInviteesCsvReadRowsForUpdate($csvPath);
  }
}

if ($action === 'prepare') {
  tcInviteesCsvEndTransaction($csvPath);
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode([
    'status' => 'ok',
    'columnAdded' => $columnAdded,
    'columnRequired' => false
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

$mapping = [];
$mappingPath = dirname($csvPath) . DIRECTORY_SEPARATOR . 'TC Mapped.json';
if (tcDbIsFile($mappingPath)) {
  $decoded = json_decode((string)tcDbFileGetContents($mappingPath), true);
  if (is_array($decoded)) $mapping = $decoded;
}
$header = $rows[0];
$fullNameIndex = tcPrizeTotalsMappedIndex($header, $mapping, ['fullName','fullname','full_name','name'], ['full name','fullname']);
$firstNameIndex = tcPrizeTotalsMappedIndex($header, $mapping, ['firstName','first_name'], ['first name','firstname','name']);
$lastNameIndex = tcPrizeTotalsMappedIndex($header, $mapping, ['lastName','last_name'], ['last name','lastname','family','surname']);
$nationalIdIndex = tcPrizeTotalsMappedIndex($header, $mapping, ['nationalId','national_id'], ['national id','nationalid','کد ملی','شماره ملی']);
$workIdIndex = tcPrizeTotalsMappedIndex($header, $mapping, ['workId','username'], ['work id','workid','username']);
$phoneIndex = tcPrizeTotalsMappedIndex($header, $mapping, ['phoneNumber','phone_number','phone'], ['phone number','phone','mobile','شماره موبایل']);
$totalIndex = tcPrizeTotalsFindHeaderIndex($header, ['total prize won','مجموع جوایز برنده شده']);
$receivedIndex = tcPrizeTotalsFindHeaderIndex($header, [TC_PRIZE_RECEIVED_COLUMN]);
if ($totalIndex < 0 || $receivedIndex < 0) {
  tcInviteesCsvEndTransaction($csvPath);
  http_response_code(409);
  echo 'Required prize columns are unavailable.';
  exit;
}

$exportRows = [];
for ($index = 1; $index < count($rows); $index++) {
  $row = is_array($rows[$index] ?? null) ? $rows[$index] : [];
  $toman = tcPrizeTotalsNumber(tcPrizeTotalsCell($row, $totalIndex));
  $received = tcPrizeTotalsIsReceived(tcPrizeTotalsCell($row, $receivedIndex));
  if ($toman <= 0 || ($action === 'remaining' && $received)) continue;
  $fullName = tcPrizeTotalsCell($row, $fullNameIndex);
  if ($fullName === '') $fullName = trim(tcPrizeTotalsCell($row, $firstNameIndex) . ' ' . tcPrizeTotalsCell($row, $lastNameIndex));
  $exportRows[] = [$fullName, tcPrizeTotalsCell($row, $nationalIdIndex), tcPrizeTotalsCell($row, $workIdIndex), tcPrizeTotalsCell($row, $phoneIndex), $toman, $toman * 10, ($received || $action === 'remaining') ? 'TRUE' : 'FALSE'];
  if ($action === 'remaining') $rows[$index][$receivedIndex] = 'TRUE';
}

if ($action === 'remaining') {
  if (!tcInviteesCsvCommitRows($csvPath, $rows)) {
    http_response_code(500);
    echo 'The CSV update failed; no export was issued and the previous file was preserved.';
    exit;
  }
} else {
  tcInviteesCsvEndTransaction($csvPath);
}

require_once dirname(__DIR__, 3) . '/api/lib/xlsx-export.php';
ob_start();
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
 <Styles><Style ss:ID="sHeader"><Font ss:Bold="1"/><Interior ss:Color="#F3F4F6" ss:Pattern="Solid"/></Style></Styles>
 <Worksheet ss:Name="Invitee Prize Totals"><Table>
  <Row ss:StyleID="sHeader"><?php foreach (['Full Name','National ID','Work ID','Phone Number','Total Prize Won (Toman)','Total Prize Won (Rial)','Checked as Received Prize'] as $label): ?><Cell><Data ss:Type="String"><?= tcPrizeTotalsXml($label) ?></Data></Cell><?php endforeach; ?></Row>
  <?php foreach ($exportRows as $dataRow): ?><Row>
   <?php for ($index = 0; $index < 4; $index++): ?><Cell><Data ss:Type="String"><?= tcPrizeTotalsXml((string)$dataRow[$index]) ?></Data></Cell><?php endfor; ?>
   <Cell><Data ss:Type="Number"><?= tcPrizeTotalsXml((string)$dataRow[4]) ?></Data></Cell><Cell><Data ss:Type="Number"><?= tcPrizeTotalsXml((string)$dataRow[5]) ?></Data></Cell><Cell><Data ss:Type="String"><?= tcPrizeTotalsXml((string)$dataRow[6]) ?></Data></Cell>
  </Row><?php endforeach; ?>
 </Table></Worksheet>
</Workbook>
<?php
$xml = (string)ob_get_clean();
$filename = $action === 'remaining' ? 'task-club-unreceived-prize-winners.xlsx' : 'task-club-all-prize-winners.xlsx';
appXlsxSend(appXlsxFromSpreadsheetXml($xml), $filename);
