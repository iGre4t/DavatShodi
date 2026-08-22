<?php
declare(strict_types=1);


require_once __DIR__ . '/egm-database-runtime.php';
require_once __DIR__ . '/../../api/lib/tab-permissions.php';
require_once __DIR__ . '/../../api/lib/egm-export-filename.php';
require_once __DIR__ . '/egm-security.php';
require_once __DIR__ . '/pot_service.php';

egmSecuritySendCommonHeaders();
egmSecurityHardenSessionSettings();
$user = requireTabPermissionFromSession('event-guest-manager', false);
if (!userHasPermissionId($user, 'event-guest-manager:export')) {
  denyPanelAccess(403, 'You do not have permission to export Pot winners.', false);
}
$levelId = trim((string)($_GET['level_id'] ?? ''));
if ($levelId === '' || strlen($levelId) > 128) {
  http_response_code(400);
  exit('Invalid Pot level ID.');
}
$level = egmPotLevel($levelId);
if (!$level) {
  http_response_code(404);
  exit('Pot level was not found.');
}
$winners = egmPotReadWinners($levelId);
$exportType = trim((string)($_GET['type'] ?? 'winners'));
if (!in_array($exportType, ['winners', 'reached_non_winners'], true)) {
  http_response_code(400);
  exit('Invalid Pot export type.');
}
$participants = [];
if ($exportType === 'winners') {
  foreach ($winners as $winner) {
    $participant = is_array($winner['participant'] ?? null) ? $winner['participant'] : [];
    if ($participant) $participants[] = $participant;
  }
} else {
  $participants = egmPotEligibleParticipants($level, $winners);
}
$headers = ['Guest Number', 'fullname', 'Work ID', 'National ID', 'Phone Number', 'Total Score', 'Pot Prize Level'];
$filename = egmExportDatedFilename($exportType === 'winners' ? 'برندگان قرعه‌کشی' : 'واجدان بدون جایزه');
header('Content-Disposition: attachment; filename="pot-winners.xlsx"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Pragma: no-cache');
header('Expires: 0');
require_once dirname(__DIR__, 2) . '/api/lib/xlsx-export.php';
ob_start();
function egmPotXml($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}
function egmPotExportCredential(array $credentials, array $aliases): string {
  $targets = [];
  foreach ($aliases as $alias) $targets[egmPotNormalizeHeader((string)$alias)] = true;
  foreach ($credentials as $header => $value) {
    if (isset($targets[egmPotNormalizeHeader((string)$header)])) {
      return trim((string)$value);
    }
  }
  return '';
}
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
 <Worksheet ss:Name="<?= $exportType === 'winners' ? 'Pot Winners' : 'Reached Non-Winners' ?>"><Table>
  <Row><?php foreach ($headers as $header): ?><Cell><Data ss:Type="String"><?= egmPotXml($header) ?></Data></Cell><?php endforeach; ?></Row>
  <?php foreach ($participants as $participant):
    $credentials = is_array($participant['credentials'] ?? null) ? $participant['credentials'] : [];
    $nationalCode = trim((string)($participant['nationalId'] ?? ''));
    if ($nationalCode === '') {
      $nationalCode = egmPotExportCredential($credentials, ['national id', 'nationalid', 'national code', 'کد ملی', 'شماره ملی']);
    }
    $phoneNumber = trim((string)($participant['phoneNumber'] ?? ''));
    if ($phoneNumber === '') {
      $phoneNumber = egmPotExportCredential($credentials, ['phone number', 'phone', 'mobile', 'mobile number', 'شماره موبایل', 'شماره تلفن']);
    }
    $values = [
      (string)($participant['guestNumber'] ?? ($participant['code'] ?? '')),
      (string)($participant['fullName'] ?? ''),
      (string)($participant['workId'] ?? ''),
      $nationalCode,
      $phoneNumber,
      (string)($participant['score'] ?? ''),
      (string)($level['name'] ?? '')
    ];
  ?>
  <Row><?php foreach ($values as $value): ?><Cell><Data ss:Type="String"><?= egmPotXml($value) ?></Data></Cell><?php endforeach; ?></Row>
  <?php endforeach; ?>
 </Table></Worksheet>
</Workbook>
<?php
$spreadsheetXml = (string)ob_get_clean();
appXlsxSend(appXlsxFromSpreadsheetXml($spreadsheetXml), $filename);
