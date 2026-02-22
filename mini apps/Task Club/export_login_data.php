<?php
declare(strict_types=1);

require_once __DIR__ . '/../../api/lib/tab-permissions.php';
requireTabPermissionFromSession('task-club', false);

$baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'TC Event';
$inviteesPath = $baseDir . DIRECTORY_SEPARATOR . 'Invitees mapped.csv';
$mapPath = $baseDir . DIRECTORY_SEPARATOR . 'TC Mapped.json';

function tcExportNormalizeHeader(string $value): string
{
  $trimmed = trim($value);
  if ($trimmed === '') {
    return '';
  }
  $lower = function_exists('mb_strtolower')
    ? mb_strtolower($trimmed, 'UTF-8')
    : strtolower($trimmed);
  $lower = preg_replace('/\s+/u', ' ', $lower);
  return is_string($lower) ? trim($lower) : '';
}

function tcExportFindHeaderIndex(array $header, array $candidates): int
{
  $normalizedCandidates = [];
  foreach ($candidates as $name) {
    $normalized = tcExportNormalizeHeader((string)$name);
    if ($normalized !== '') {
      $normalizedCandidates[$normalized] = true;
    }
  }
  if (!$normalizedCandidates) {
    return -1;
  }
  foreach ($header as $index => $label) {
    $normalizedLabel = tcExportNormalizeHeader((string)$label);
    if ($normalizedLabel !== '' && isset($normalizedCandidates[$normalizedLabel])) {
      return (int)$index;
    }
  }
  return -1;
}

function tcExportReadCsvRows(string $path): array
{
  if (!is_file($path)) {
    return [];
  }
  $rows = [];
  $handle = fopen($path, 'r');
  if ($handle === false) {
    return [];
  }
  while (($row = fgetcsv($handle)) !== false) {
    $rows[] = is_array($row) ? $row : [];
  }
  fclose($handle);
  return $rows;
}

function tcExportReadMapping(string $path): array
{
  if (!is_file($path)) {
    return [];
  }
  $content = file_get_contents($path);
  if (!is_string($content) || $content === '') {
    return [];
  }
  $decoded = json_decode($content, true);
  return is_array($decoded) ? $decoded : [];
}

function tcExportResolveMappedIndex(array $header, array $mapping, string $mappingKey, array $fallbackHeaderNames): int
{
  $mappedIndex = $mapping[$mappingKey] ?? null;
  if (is_numeric($mappedIndex)) {
    $index = (int)$mappedIndex;
    if ($index >= 0 && $index < count($header)) {
      return $index;
    }
  }
  return tcExportFindHeaderIndex($header, $fallbackHeaderNames);
}

function tcExportCellValue(array $row, int $index): string
{
  if ($index < 0) {
    return '';
  }
  return trim((string)($row[$index] ?? ''));
}

function tcExportXmlEscape(string $value): string
{
  return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

$rows = tcExportReadCsvRows($inviteesPath);
$mapping = tcExportReadMapping($mapPath);
$header = isset($rows[0]) && is_array($rows[0]) ? $rows[0] : [];

$workIdIndex = tcExportResolveMappedIndex(
  $header,
  $mapping,
  'workId',
  ['Work ID', 'work id', 'workid', 'username', 'user name', 'نام کاربری', 'کد پرسنلی']
);
$nationalIdIndex = tcExportResolveMappedIndex(
  $header,
  $mapping,
  'nationalId',
  ['National ID', 'national id', 'nationalid', 'کد ملی', 'شماره ملی']
);
$phoneIndex = tcExportResolveMappedIndex(
  $header,
  $mapping,
  'phoneNumber',
  ['Phone Number', 'phone number', 'phone', 'mobile', 'شماره موبایل', 'شماره تلفن']
);
$passwordIndex = tcExportFindHeaderIndex(
  $header,
  ['password', 'pass', 'passwd', 'رمز عبور']
);

if ($workIdIndex < 0 && $nationalIdIndex >= 0) {
  $workIdIndex = $nationalIdIndex;
}

$exportRows = [];
for ($i = 1; $i < count($rows); $i += 1) {
  $row = is_array($rows[$i]) ? $rows[$i] : [];
  $workId = tcExportCellValue($row, $workIdIndex);
  $password = tcExportCellValue($row, $passwordIndex);
  $phone = tcExportCellValue($row, $phoneIndex);
  $nationalId = tcExportCellValue($row, $nationalIdIndex);
  if ($workId === '' && $password === '' && $phone === '' && $nationalId === '') {
    continue;
  }
  $exportRows[] = [$workId, $password, $phone, $nationalId];
}

$filename = 'tc-login-data-' . date('Ymd-His') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
 <Styles>
  <Style ss:ID="sHeader">
   <Font ss:Bold="1"/>
   <Alignment ss:Horizontal="Right" ss:Vertical="Center" ss:ReadingOrder="RightToLeft"/>
   <Interior ss:Color="#F3F4F6" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="sCell">
   <Alignment ss:Horizontal="Right" ss:Vertical="Center" ss:ReadingOrder="RightToLeft"/>
  </Style>
 </Styles>
 <Worksheet ss:Name="داده‌های ورود">
  <Table>
   <Row ss:StyleID="sHeader">
    <Cell><Data ss:Type="String">نام کاربری</Data></Cell>
    <Cell><Data ss:Type="String">رمز عبور</Data></Cell>
    <Cell><Data ss:Type="String">شماره موبایل</Data></Cell>
    <Cell><Data ss:Type="String">کد ملی</Data></Cell>
   </Row>
<?php foreach ($exportRows as $dataRow): ?>
   <Row ss:StyleID="sCell">
    <Cell><Data ss:Type="String"><?= tcExportXmlEscape((string)$dataRow[0]) ?></Data></Cell>
    <Cell><Data ss:Type="String"><?= tcExportXmlEscape((string)$dataRow[1]) ?></Data></Cell>
    <Cell><Data ss:Type="String"><?= tcExportXmlEscape((string)$dataRow[2]) ?></Data></Cell>
    <Cell><Data ss:Type="String"><?= tcExportXmlEscape((string)$dataRow[3]) ?></Data></Cell>
   </Row>
<?php endforeach; ?>
  </Table>
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
   <DisplayRightToLeft/>
  </WorksheetOptions>
 </Worksheet>
</Workbook>
