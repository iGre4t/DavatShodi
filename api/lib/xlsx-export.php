<?php
declare(strict_types=1);

/**
 * Converts the project's legacy Excel 2003 XML exports to a genuine XLSX file.
 * The ZIP container is written directly so deployments do not require ZipArchive.
 */
function appXlsxFromSpreadsheetXml(string $source): string
{
    $document = new DOMDocument();
    if (!@$document->loadXML($source, LIBXML_NONET | LIBXML_COMPACT)) {
        throw new RuntimeException('The generated spreadsheet XML is invalid.');
    }

    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('ss', 'urn:schemas-microsoft-com:office:spreadsheet');
    $worksheets = $xpath->query('//ss:Worksheet');
    if (!$worksheets || $worksheets->length === 0) {
        throw new RuntimeException('The spreadsheet contains no worksheets.');
    }

    $files = [];
    $contentTypes = '';
    $workbookSheets = '';
    $relationships = '';
    foreach ($worksheets as $index => $worksheet) {
        $sheetNumber = $index + 1;
        $name = $worksheet->getAttributeNS('urn:schemas-microsoft-com:office:spreadsheet', 'Name');
        $name = appXlsxSheetName($name !== '' ? $name : 'Sheet ' . $sheetNumber, $sheetNumber);
        $workbookSheets .= '<sheet name="' . appXlsxXml($name) . '" sheetId="' . $sheetNumber . '" r:id="rId' . $sheetNumber . '"/>';
        $relationships .= '<Relationship Id="rId' . $sheetNumber . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sheetNumber . '.xml"/>';
        $contentTypes .= '<Override PartName="/xl/worksheets/sheet' . $sheetNumber . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $files['xl/worksheets/sheet' . $sheetNumber . '.xml'] = appXlsxWorksheetXml($xpath, $worksheet);
    }

    $files['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . $contentTypes . '</Types>';
    $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    $files['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $workbookSheets . '</sheets></workbook>';
    $files['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relationships . '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $files['xl/styles.xml'] = appXlsxStylesXml();

    return appXlsxZip($files);
}

function appXlsxWorksheetXml(DOMXPath $xpath, DOMElement $worksheet): string
{
    $rowsXml = '';
    $rowNumber = 0;
    foreach ($xpath->query('.//ss:Table/ss:Row', $worksheet) ?: [] as $row) {
        $rowNumber++;
        $cellsXml = '';
        $columnNumber = 0;
        $rowStyle = $row->getAttributeNS('urn:schemas-microsoft-com:office:spreadsheet', 'StyleID');
        foreach ($xpath->query('./ss:Cell', $row) ?: [] as $cell) {
            $index = (int)$cell->getAttributeNS('urn:schemas-microsoft-com:office:spreadsheet', 'Index');
            $columnNumber = $index > 0 ? $index : $columnNumber + 1;
            $data = $xpath->query('./ss:Data', $cell)?->item(0);
            $value = $data instanceof DOMNode ? $data->textContent : '';
            $type = $data instanceof DOMElement ? $data->getAttributeNS('urn:schemas-microsoft-com:office:spreadsheet', 'Type') : 'String';
            $styleId = $cell->getAttributeNS('urn:schemas-microsoft-com:office:spreadsheet', 'StyleID') ?: $rowStyle;
            $style = $styleId === 'sHeader' ? 1 : ($styleId === 'sPercent' ? 2 : 0);
            $ref = appXlsxColumnName($columnNumber) . $rowNumber;
            if ($type === 'Number' && is_numeric($value)) {
                $cellsXml .= '<c r="' . $ref . '" s="' . $style . '"><v>' . appXlsxXml($value) . '</v></c>';
            } else {
                $cellsXml .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . appXlsxXml($value) . '</t></is></c>';
            }
        }
        $rowsXml .= '<row r="' . $rowNumber . '">' . $cellsXml . '</row>';
    }
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView rightToLeft="1" workbookViewId="0"/></sheetViews><sheetData>' . $rowsXml . '</sheetData></worksheet>';
}

function appXlsxStylesXml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="0.0%"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Arial"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Arial"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1689E6"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
}

function appXlsxSend(string $content, string $filename): void
{
    $filename = preg_replace('/\.xls$/i', '.xlsx', $filename) ?: 'export.xlsx';
    $ascii = preg_replace('/[^\x20-\x7E]+/', '', $filename);
    $ascii = is_string($ascii) && trim($ascii, '.-_ ') !== '' ? $ascii : 'export.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Length: ' . strlen($content));
    header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $ascii) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $content;
}

function appXlsxXml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function appXlsxSheetName(string $name, int $fallback): string
{
    $name = preg_replace('/[\\\\\\/?*\\[\\]:]+/u', '-', trim($name)) ?: 'Sheet ' . $fallback;
    $characters = preg_split('//u', $name, 32, PREG_SPLIT_NO_EMPTY);
    return is_array($characters) ? implode('', array_slice($characters, 0, 31)) : substr($name, 0, 31);
}

function appXlsxColumnName(int $column): string
{
    $name = '';
    while ($column > 0) {
        $column--;
        $name = chr(65 + ($column % 26)) . $name;
        $column = intdiv($column, 26);
    }
    return $name;
}

function appXlsxZip(array $files): string
{
    $data = '';
    $central = '';
    $offset = 0;
    $count = 0;
    foreach ($files as $name => $content) {
        $name = str_replace('\\', '/', (string)$name);
        $content = (string)$content;
        $crc = crc32($content);
        $size = strlen($content);
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, 0, 0, $crc, $size, $size, strlen($name), 0) . $name;
        $data .= $local . $content;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, 0, 0, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        $offset += strlen($local) + $size;
        $count++;
    }
    return $data . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($data), 0);
}
