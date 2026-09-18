<?php

declare(strict_types=1);

function xlsxColumnName(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }
    return $name;
}

function xlsxCell(string $reference, mixed $value, int $style = 0): string
{
    $styleAttribute = $style > 0 ? ' s="' . $style . '"' : '';
    if (is_int($value) || is_float($value)) {
        return '<c r="' . $reference . '"' . $styleAttribute . '><v>' . $value . '</v></c>';
    }
    $escaped = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    return '<c r="' . $reference . '" t="inlineStr"' . $styleAttribute . '><is><t xml:space="preserve">'
        . $escaped . '</t></is></c>';
}

function xlsxWorksheet(array $rows, array $widths = [], array $merges = []): string
{
    $sheetRows = '';
    foreach ($rows as $rowIndex => $row) {
        $number = $rowIndex + 1;
        $cells = '';
        foreach ($row as $columnIndex => $cell) {
            $value = is_array($cell) ? ($cell['value'] ?? '') : $cell;
            $style = is_array($cell) ? (int) ($cell['style'] ?? 0) : 0;
            $cells .= xlsxCell(xlsxColumnName($columnIndex + 1) . $number, $value, $style);
        }
        $sheetRows .= '<row r="' . $number . '">' . $cells . '</row>';
    }

    $columns = '';
    foreach ($widths as $index => $width) {
        $column = $index + 1;
        $columns .= '<col min="' . $column . '" max="' . $column . '" width="' . $width . '" customWidth="1"/>';
    }
    $columns = $columns !== '' ? '<cols>' . $columns . '</cols>' : '';

    $mergeXml = '';
    if ($merges) {
        foreach ($merges as $merge) {
            $mergeXml .= '<mergeCell ref="' . htmlspecialchars($merge, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"/>';
        }
        $mergeXml = '<mergeCells count="' . count($merges) . '">' . $mergeXml . '</mergeCells>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . $columns . '<sheetData>' . $sheetRows . '</sheetData>' . $mergeXml . '</worksheet>';
}

function createXlsxFile(array $sheets, string $path): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Excel dosyası oluşturulamadı.');
    }

    $sheetOverrides = '';
    $workbookSheets = '';
    $relationships = '';
    foreach ($sheets as $index => $sheet) {
        $sheetNumber = $index + 1;
        $sheetOverrides .= '<Override PartName="/xl/worksheets/sheet' . $sheetNumber
            . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $workbookSheets .= '<sheet name="' . htmlspecialchars($sheet['name'], ENT_XML1 | ENT_QUOTES, 'UTF-8')
            . '" sheetId="' . $sheetNumber . '" r:id="rId' . $sheetNumber . '"/>';
        $relationships .= '<Relationship Id="rId' . $sheetNumber
            . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
            . ' Target="worksheets/sheet' . $sheetNumber . '.xml"/>';
        $zip->addFromString('xl/worksheets/sheet' . $sheetNumber . '.xml', $sheet['xml']);
    }

    $styleRelationshipId = count($sheets) + 1;
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . $sheetOverrides . '</Types>';
    $rootRelationships = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
        . $workbookSheets . '</sheets></workbook>';
    $workbookRelationships = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . $relationships . '<Relationship Id="rId' . $styleRelationshipId
        . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="3"><font><sz val="11"/><name val="Aptos"/></font>'
        . '<font><b/><sz val="16"/><color rgb="FFFFFFFF"/><name val="Aptos Display"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Aptos"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF2563EB"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border/><border><left style="thin"><color rgb="FFDCE4EF"/></left>'
        . '<right style="thin"><color rgb="FFDCE4EF"/></right><top style="thin"><color rgb="FFDCE4EF"/></top>'
        . '<bottom style="thin"><color rgb="FFDCE4EF"/></bottom></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRelationships);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelationships);
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->close();
}
