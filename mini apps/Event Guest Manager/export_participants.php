<?php
declare(strict_types=1);


require_once __DIR__ . '/egm-database-runtime.php';
require_once __DIR__ . '/egm-security.php';
require_once __DIR__ . '/../../api/lib/tab-permissions.php';
require_once __DIR__ . '/invitees_csv_safety.php';
$egmExportSessionUser = requireTabPermissionFromSession('event-guest-manager', false);
if (!userHasPermissionId($egmExportSessionUser, 'event-guest-manager:export')) {
  denyPanelAccess(403, 'You do not have permission to access this Event Guest Manager section.', false);
}

$baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'EGM Event';
$inviteesPath = $baseDir . DIRECTORY_SEPARATOR . 'Invitees mapped.csv';
$mapPath = $baseDir . DIRECTORY_SEPARATOR . 'EGM Mapped.json';

function egmParticipantsNormalizeHeader(string $value): string
{
  $withoutBom = str_replace("\xEF\xBB\xBF", '', $value);
  $trimmed = trim($withoutBom);
  if ($trimmed === '') {
    return '';
  }
  $lower = function_exists('mb_strtolower')
    ? mb_strtolower($trimmed, 'UTF-8')
    : strtolower($trimmed);
  $normalized = preg_replace('/\s+/u', ' ', $lower);
  return is_string($normalized) ? trim($normalized) : '';
}

function egmParticipantsFindHeaderIndex(array $header, array $candidates): int
{
  $targets = [];
  foreach ($candidates as $name) {
    $token = egmParticipantsNormalizeHeader((string)$name);
    if ($token !== '') {
      $targets[$token] = true;
    }
  }
  foreach ($header as $index => $label) {
    $token = egmParticipantsNormalizeHeader((string)$label);
    if ($token !== '' && isset($targets[$token])) {
      return (int)$index;
    }
  }
  return -1;
}

function egmParticipantsReadCsvRows(string $path): array
{
  if (egmInviteesCsvIsManagedPath($path)) {
    return egmInviteesCsvReadRowsSnapshot($path);
  }
  if (!egmDbIsFile($path)) {
    return [];
  }
  $handle = egmDbFopen($path, 'r');
  if ($handle === false) {
    return [];
  }
  $rows = [];
  if (!flock($handle, LOCK_SH)) {
    fclose($handle);
    return [];
  }
  while (($row = fgetcsv($handle)) !== false) {
    $rows[] = is_array($row) ? $row : [];
  }
  flock($handle, LOCK_UN);
  fclose($handle);
  return $rows;
}

function egmParticipantsReadMapping(string $path): array
{
  if (!egmDbIsFile($path)) {
    return [];
  }
  $content = egmDbFileGetContents($path);
  if (!is_string($content) || $content === '') {
    return [];
  }
  $decoded = json_decode($content, true);
  return is_array($decoded) ? $decoded : [];
}

function egmParticipantsResolveMappedIndex(array $header, array $mapping, array $mappingKeys, array $fallbackNames): int
{
  foreach ($mappingKeys as $mappingKey) {
    $mappedIndex = $mapping[(string)$mappingKey] ?? null;
    if (!is_numeric($mappedIndex)) {
      continue;
    }
    $index = (int)$mappedIndex;
    if ($index >= 0 && $index < count($header)) {
      return $index;
    }
  }
  return egmParticipantsFindHeaderIndex($header, $fallbackNames);
}

function egmParticipantsCell(array $row, int $index): string
{
  if ($index < 0) {
    return '';
  }
  return trim((string)($row[$index] ?? ''));
}

function egmParticipantsParseInt($value): int
{
  if (!is_scalar($value)) {
    return 0;
  }
  $token = str_replace([',', ' '], '', trim((string)$value));
  if ($token === '' || !is_numeric($token)) {
    return 0;
  }
  return max(0, (int)floor((float)$token));
}

function egmParticipantsSplitTokens(string $value): array
{
  $parts = preg_split('/\s*(?:,|،|;)\s*/u', trim($value));
  if (!is_array($parts)) {
    return [];
  }
  $seen = [];
  $tokens = [];
  foreach ($parts as $part) {
    $token = trim((string)$part);
    if ($token === '' || isset($seen[$token])) {
      continue;
    }
    $seen[$token] = true;
    $tokens[] = $token;
  }
  return $tokens;
}

function egmParticipantsTaskMapKeys(string $value): array
{
  $keys = [];
  foreach (egmParticipantsSplitTokens($value) as $item) {
    $separator = strpos($item, '::');
    if ($separator === false) {
      $separator = strpos($item, ':');
    }
    $taskId = $separator === false
      ? trim($item)
      : trim(substr($item, 0, $separator));
    if ($taskId !== '') {
      $keys[] = $taskId;
    }
  }
  return $keys;
}

function egmParticipantsXmlEscape(string $value): string
{
  return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function egmParticipantsEventTitle(string $fallback): string
{
  $indexPath = __DIR__ . DIRECTORY_SEPARATOR . 'index.php';
  if (egmDbIsFile($indexPath)) {
    $content = egmDbFileGetContents($indexPath);
    if (is_string($content) && preg_match('/<title[^>]*>(.*?)<\/title>/is', $content, $matches)) {
      $title = trim(html_entity_decode(strip_tags((string)$matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
      if ($title !== '') {
        return $title;
      }
    }
  }
  return $fallback;
}

function egmParticipantsFilenameStem(string $title): string
{
  $stem = preg_replace('/[\x00-\x1F<>:"\/\\\\|?*]+/u', '-', trim($title));
  $stem = is_string($stem) ? preg_replace('/\s+/u', '-', $stem) : '';
  $stem = is_string($stem) ? trim($stem, "-. \t\n\r\0\x0B") : '';
  return $stem !== '' ? $stem : 'event';
}

$rows = egmParticipantsReadCsvRows($inviteesPath);
$mapping = egmParticipantsReadMapping($mapPath);
$header = isset($rows[0]) && is_array($rows[0]) ? $rows[0] : [];

$workIdIndex = egmParticipantsResolveMappedIndex($header, $mapping, ['workId', 'username'], ['Work ID', 'work id', 'workid', 'username', 'user name', 'national id']);
$firstNameIndex = egmParticipantsResolveMappedIndex($header, $mapping, ['firstName', 'first_name'], ['First Name', 'first name', 'firstname', 'name']);
$lastNameIndex = egmParticipantsResolveMappedIndex($header, $mapping, ['lastName', 'last_name'], ['Last Name', 'last name', 'lastname', 'family', 'surname']);
$fullNameIndex = egmParticipantsResolveMappedIndex($header, $mapping, ['fullName', 'fullname', 'name', 'full_name'], ['Full Name', 'full name', 'name']);
$nationalIdIndex = egmParticipantsResolveMappedIndex($header, $mapping, ['nationalId', 'national_id'], ['National ID', 'national id', 'nationalid']);
$phoneIndex = egmParticipantsResolveMappedIndex($header, $mapping, ['phoneNumber', 'phone_number', 'phone'], ['Phone Number', 'phone number', 'phone', 'mobile']);
$scoreIndex = egmParticipantsFindHeaderIndex($header, ['score', 'total score', 'Total Score']);
$taskCompletedIndex = egmParticipantsFindHeaderIndex($header, ['task completed ids', 'task completed id', 'task completed']);
$taskScoreMapIndex = egmParticipantsFindHeaderIndex($header, ['task score map']);
$infoTasksIndex = egmParticipantsFindHeaderIndex($header, ['info tasks']);
$teamTasksIndex = egmParticipantsFindHeaderIndex($header, ['team task']);
$describeTasksIndex = egmParticipantsFindHeaderIndex($header, ['describe photo task']);

if ($workIdIndex < 0 && $nationalIdIndex >= 0) {
  $workIdIndex = $nationalIdIndex;
}

$exportRows = [];
for ($i = 1; $i < count($rows); $i += 1) {
  $row = is_array($rows[$i] ?? null) ? $rows[$i] : [];
  $score = egmParticipantsParseInt(egmParticipantsCell($row, $scoreIndex));
  if ($score <= 0) {
    continue;
  }

  $firstName = egmParticipantsCell($row, $firstNameIndex);
  $lastName = egmParticipantsCell($row, $lastNameIndex);
  $fullName = egmParticipantsCell($row, $fullNameIndex);
  if ($fullName === '') {
    $fullName = trim($firstName . ' ' . $lastName);
  }
  if ($fullName === '') {
    $fullName = egmParticipantsCell($row, $workIdIndex);
  }

  $completedLookup = [];
  foreach (egmParticipantsSplitTokens(egmParticipantsCell($row, $taskCompletedIndex)) as $taskId) {
    $completedLookup[$taskId] = true;
  }
  foreach ([
    egmParticipantsCell($row, $taskScoreMapIndex),
    egmParticipantsCell($row, $infoTasksIndex),
    egmParticipantsCell($row, $teamTasksIndex),
    egmParticipantsCell($row, $describeTasksIndex)
  ] as $mapValue) {
    foreach (egmParticipantsTaskMapKeys($mapValue) as $taskId) {
      $completedLookup[$taskId] = true;
    }
  }

  $exportRows[] = [
    $fullName,
    egmParticipantsCell($row, $workIdIndex),
    egmParticipantsCell($row, $phoneIndex),
    egmParticipantsCell($row, $nationalIdIndex),
    count($completedLookup),
    $score
  ];
}

$eventTitle = egmParticipantsEventTitle('Event Guest Manager');
$filename = egmParticipantsFilenameStem($eventTitle) . '-participants.xlsx';
$asciiFilename = preg_replace('/[^\x20-\x7E]+/', '', $filename);
$asciiFilename = is_string($asciiFilename) && trim($asciiFilename) !== '' ? $asciiFilename : 'event-participants.xlsx';
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $asciiFilename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Pragma: no-cache');
header('Expires: 0');

require_once dirname(__DIR__, 2) . '/api/lib/xlsx-export.php';
ob_start();
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
   <Interior ss:Color="#F3F4F6" ss:Pattern="Solid"/>
  </Style>
 </Styles>
 <Worksheet ss:Name="Participants">
  <Table>
   <Row ss:StyleID="sHeader">
    <Cell><Data ss:Type="String">fullname</Data></Cell>
    <Cell><Data ss:Type="String">Work ID</Data></Cell>
    <Cell><Data ss:Type="String">Phone Number</Data></Cell>
    <Cell><Data ss:Type="String">National ID</Data></Cell>
    <Cell><Data ss:Type="String">Number of Tasks completed</Data></Cell>
    <Cell><Data ss:Type="String">Total Score</Data></Cell>
   </Row>
<?php foreach ($exportRows as $dataRow): ?>
   <Row>
    <Cell><Data ss:Type="String"><?= egmParticipantsXmlEscape((string)$dataRow[0]) ?></Data></Cell>
    <Cell><Data ss:Type="String"><?= egmParticipantsXmlEscape((string)$dataRow[1]) ?></Data></Cell>
    <Cell><Data ss:Type="String"><?= egmParticipantsXmlEscape((string)$dataRow[2]) ?></Data></Cell>
    <Cell><Data ss:Type="String"><?= egmParticipantsXmlEscape((string)$dataRow[3]) ?></Data></Cell>
    <Cell><Data ss:Type="Number"><?= (int)$dataRow[4] ?></Data></Cell>
    <Cell><Data ss:Type="Number"><?= (int)$dataRow[5] ?></Data></Cell>
   </Row>
<?php endforeach; ?>
  </Table>
 </Worksheet>
</Workbook>
<?php
$spreadsheetXml = (string)ob_get_clean();
appXlsxSend(appXlsxFromSpreadsheetXml($spreadsheetXml), $filename);
