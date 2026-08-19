<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/xlsx-export.php';

$xml = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
    . '<Worksheet ss:Name="Test"><Table><Row ss:StyleID="sHeader">'
    . '<Cell><Data ss:Type="String">عنوان</Data></Cell>'
    . '<Cell><Data ss:Type="Number">42</Data></Cell>'
    . '</Row></Table></Worksheet></Workbook>';

$xlsx = appXlsxFromSpreadsheetXml($xml);
if (!str_starts_with($xlsx, "PK\x03\x04")) {
    throw new RuntimeException('XLSX output is not a ZIP package.');
}
foreach (['[Content_Types].xml', 'xl/workbook.xml', 'xl/worksheets/sheet1.xml', 'xl/styles.xml'] as $part) {
    if (!str_contains($xlsx, $part)) {
        throw new RuntimeException('Missing XLSX package part: ' . $part);
    }
}
if (!str_contains($xlsx, 'عنوان') || !str_contains($xlsx, '<v>42</v>')) {
    throw new RuntimeException('Worksheet values were not preserved.');
}
$styles = appXlsxStylesXml();
if (!str_contains($styles, '<font><sz val="11"/><name val="Arial"/></font>')
    || !str_contains($styles, '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>')) {
    throw new RuntimeException('XLSX font properties are not in the schema-required order.');
}

$temporaryFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'davatshodi-xlsx-' . bin2hex(random_bytes(6)) . '.zip';
file_put_contents($temporaryFile, $xlsx);
try {
    $archive = new PharData($temporaryFile);
    if (!isset($archive['xl/workbook.xml']) || !isset($archive['xl/worksheets/sheet1.xml'])) {
        throw new RuntimeException('Generated XLSX ZIP cannot be read.');
    }
} finally {
    unset($archive);
    @unlink($temporaryFile);
}

fwrite(STDOUT, "XLSX export test passed.\n");
