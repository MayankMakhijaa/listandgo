<?php

declare(strict_types=1);

namespace ListingTool\Exporters;

use ListingTool\Catalog;
use ListingTool\Exporters\Amazon\AmazonMapper;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Amazon India clothing listings, laid out exactly like the Template sheet of Amazon's
 * SWEATSHIRT / SHIRT / NIGHTGOWN_NIGHTSHIRT category template: same columns in the same
 * order, group row 3, label row 4, attribute row 5, data from row 7. The seller copies
 * rows 7 onward into cell A7 of their own downloaded template and uploads it.
 */
final class AmazonExporter
{
    public static function export(array $rows): ExportFile
    {
        $schema = AmazonMapper::schema();
        $columns = $schema['columns'];
        $index = [];
        foreach ($columns as $i => $c) {
            $index[AmazonMapper::shortKey($c['key'])] = $i;
        }

        $data = [];
        foreach (Catalog::forChannel($rows, 'amazon') as $it) {
            $data[] = AmazonMapper::map($it['row'], $it['kind'], $it['parent'])['values'];
        }

        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Template');
        $sheet->setCellValueExplicit([1, 1], 'List and Go: copy rows ' . $schema['data_row'] . ' and below into cell A'
            . $schema['data_row'] . ' of the Template sheet in your Amazon file. Columns match ' . $schema['source'] . '.', DataType::TYPE_STRING);
        $lastGroup = '';
        foreach ($columns as $i => $c) {
            if ($c['group'] !== $lastGroup) {
                $sheet->setCellValueExplicit([$i + 1, $schema['label_row'] - 1], $c['group'], DataType::TYPE_STRING);
                $lastGroup = $c['group'];
            }
            $sheet->setCellValueExplicit([$i + 1, $schema['label_row']], $c['label'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([$i + 1, $schema['attribute_row']], $c['key'], DataType::TYPE_STRING);
        }
        foreach ($data as $r => $values) {
            foreach ($values as $key => $value) {
                if (isset($index[$key])) {
                    $sheet->setCellValueExplicit([$index[$key] + 1, $schema['data_row'] + $r], $value, DataType::TYPE_STRING);
                }
            }
        }
        $last = Coordinate::stringFromColumnIndex(count($columns));
        $sheet->getStyle("A{$schema['label_row']}:{$last}{$schema['label_row']}")->getFont()->setBold(true);
        $sheet->getRowDimension($schema['attribute_row'])->setVisible(false);
        $sheet->freezePane('B' . $schema['data_row']);
        for ($c = 1; $c <= count($columns); $c++) {
            $sheet->getColumnDimensionByColumn($c)->setWidth(18);
        }

        $notes = $book->createSheet();
        $notes->setTitle('Read me');
        $lines = [
            'List and Go: Amazon India clothing listings',
            '',
            '1. In Seller Central, open Catalog > Add products via upload and download the template for SHIRT, SWEATSHIRT or NIGHTGOWN_NIGHTSHIRT (the same template List and Go was built from: ' . $schema['source'] . ').',
            '2. In this file, select rows ' . $schema['data_row'] . ' and below on the Template sheet, copy, and paste into cell A' . $schema['data_row'] . ' of the Template sheet in your Amazon file. The columns line up one to one.',
            '3. Add Recommended Browse Nodes if Amazon asks for them (see the Browse Data sheet in your Amazon file).',
            '4. Upload the file in Seller Central and check the processing report.',
            '',
            'Parent rows carry the shared details; each size and colour is a Child row pointing at the Parent SKU.',
            'Product Tax Code is set from gst_rate_pct: 0% A_GEN_EXEMPT, 5% A_GEN_SUPERREDUCED, 12% A_GEN_REDUCED, 18% A_GEN_STANDARD, 28% A_GEN_PEAK, 40% A_GEN_HIGHPEAK. Check this against your tax advisor.',
            'Rows without a barcode are marked GTIN Exempt, which needs an approved exemption for your brand.',
        ];
        foreach ($lines as $i => $line) {
            $notes->setCellValueExplicit([1, $i + 1], $line, DataType::TYPE_STRING);
        }
        $notes->getColumnDimension('A')->setWidth(140);
        $book->setActiveSheetIndex(0);

        $fh = fopen('php://temp', 'r+');
        (new XlsxWriter($book))->save($fh);
        rewind($fh);
        $content = (string) stream_get_contents($fh);
        fclose($fh);
        $book->disconnectWorksheets();

        return new ExportFile('amazon', 'amazon_clothing_listings.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $content, count($data));
    }
}
