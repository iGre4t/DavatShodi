<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/xlsx-export.php';

function xlsxExportTestAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function xlsxExportTestXml(): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" '
        . 'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
        . '<Worksheet ss:Name="گزارش"><Table><Row><Cell><Data ss:Type="String">نمونه</Data>'
        . '</Cell></Row></Table></Worksheet></Workbook>';
}

if (($argv[1] ?? '') === '--send-child') {
    ob_start();
    echo "CORRUPTION-BEFORE-XLSX\n";
    ob_start();
    echo "NESTED-CORRUPTION\n";
    appXlsxSend(appXlsxFromSpreadsheetXml(xlsxExportTestXml()), 'گزارش.xlsx');
    exit;
}

$expected = appXlsxFromSpreadsheetXml(xlsxExportTestXml());
xlsxExportTestAssert(str_starts_with($expected, "PK\x03\x04"), 'Generated workbook has no ZIP signature.');
xlsxExportTestAssert(str_contains(substr($expected, -22), "PK\x05\x06"), 'Generated workbook has no ZIP directory trailer.');

$pipes = [];
$process = proc_open(
    [PHP_BINARY, __FILE__, '--send-child'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
xlsxExportTestAssert(is_resource($process), 'Could not start XLSX response child process.');
fclose($pipes[0]);
$download = stream_get_contents($pipes[1]);
$errors = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

xlsxExportTestAssert($exitCode === 0, 'XLSX response child failed: ' . trim((string)$errors));
xlsxExportTestAssert($download === $expected, 'Buffered output contaminated or truncated the XLSX response.');
xlsxExportTestAssert(!str_contains($download, 'CORRUPTION'), 'Buffered text leaked into the XLSX response.');

fwrite(STDOUT, "XLSX export test passed.\n");
