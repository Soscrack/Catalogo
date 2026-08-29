<?php
/**
 * Writer XLSX mínimo (ZipArchive + inlineStr). Sin PhpSpreadsheet.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Xlsx_Writer {

    /** @var string */
    private $sheet_name;

    /** @var array<int, array<int, mixed>> */
    private $rows = [];

    public function __construct($sheet_name = 'Datos de producto') {
        $this->sheet_name = (string) $sheet_name;
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     */
    public function set_rows(array $rows) {
        $this->rows = $rows;
    }

    /**
     * @return string|false
     */
    public function to_string() {
        if (!class_exists('ZipArchive')) {
            return false;
        }

        $tmp = wp_tempnam('riverso-export.xlsx');
        if (!$tmp) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            return false;
        }

        $sheet_xml = $this->build_sheet_xml();
        $zip->addFromString('[Content_Types].xml', $this->content_types_xml());
        $zip->addFromString('_rels/.rels', $this->root_rels_xml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbook_rels_xml());
        $zip->addFromString('xl/workbook.xml', $this->workbook_xml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet_xml);
        $zip->close();

        $data = file_get_contents($tmp);
        @unlink($tmp);
        return $data === false ? false : $data;
    }

    private function content_types_xml() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>';
    }

    private function root_rels_xml() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbook_rels_xml() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>';
    }

    private function workbook_xml() {
        $name = htmlspecialchars($this->sheet_name, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $name . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function build_sheet_xml() {
        $row_count = count($this->rows);
        $col_count = 0;
        foreach ($this->rows as $row) {
            $col_count = max($col_count, count($row));
        }

        $parts = [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">',
            '<sheetData>',
        ];

        foreach ($this->rows as $r_idx => $row) {
            $r_num = $r_idx + 1;
            $parts[] = '<row r="' . $r_num . '">';
            foreach ($row as $c_idx => $value) {
                $ref = self::cell_ref($c_idx, $r_num);
                $parts[] = self::cell_xml($ref, $value);
            }
            $parts[] = '</row>';
        }

        $parts[] = '</sheetData></worksheet>';
        return implode('', $parts);
    }

    /**
     * @param mixed $value
     */
    private static function cell_xml($ref, $value) {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value) && preg_match('/^-?\d+(\.\d+)?$/', $value))) {
            $num = is_string($value) ? $value : (string) $value;
            return '<c r="' . $ref . '"><v>' . htmlspecialchars($num, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</v></c>';
        }
        $text = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return '<c r="' . $ref . '" t="inlineStr"><is><t>' . $text . '</t></is></c>';
    }

    private static function cell_ref($col_idx, $row_num) {
        return self::col_letter($col_idx) . $row_num;
    }

    private static function col_letter($index) {
        $index = (int) $index;
        $letters = '';
        do {
            $letters = chr(65 + ($index % 26)) . $letters;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);
        return $letters;
    }
}
