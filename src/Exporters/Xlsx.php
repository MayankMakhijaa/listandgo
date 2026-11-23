<?php

declare(strict_types=1);

namespace ListingTool\Exporters;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

final class Xlsx
{
    /**
     * Build an .xlsx: the listing sheet first (bold, frozen header), then a "Read me" sheet.
     * Every cell is written as text so GTINs, HSN codes and SKUs keep their digits.
     *
     * @param list<string> $columns
     * @param list<array<string,mixed>> $rows
     * @param list<string> $notes
     */
    public static function build(string $sheetName, array $columns, array $rows, array $notes = []): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(substr($sheetName, 0, 31));
        foreach ($columns as $c => $name) {
            $sheet->setCellValueExplicit([$c + 1, 1], $name, DataType::TYPE_STRING);
        }
        foreach ($rows as $r => $row) {
            foreach ($columns as $c => $name) {
                $v = (string) ($row[$name] ?? '');
                if ($v !== '') {
                    $sheet->setCellValueExplicit([$c + 1, $r + 2], $v, DataType::TYPE_STRING);
                }
            }
        }
        $last = Coordinate::stringFromColumnIndex(max(1, count($columns)));
        $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true);
        $sheet->freezePane('A2');
        for ($c = 1; $c <= count($columns); $c++) {
            $sheet->getColumnDimensionByColumn($c)->setWidth(20);
        }

        if ($notes) {
            $readme = $book->createSheet();
            $readme->setTitle('Read me');
            foreach ($notes as $i => $line) {
                $readme->setCellValueExplicit([1, $i + 1], $line, DataType::TYPE_STRING);
            }
            $readme->getColumnDimension('A')->setWidth(120);
        }
        $book->setActiveSheetIndex(0);

        $fh = fopen('php://temp', 'r+');
        (new XlsxWriter($book))->save($fh);
        rewind($fh);
        $out = stream_get_contents($fh);
        fclose($fh);
        $book->disconnectWorksheets();
        return $out;
    }
}
