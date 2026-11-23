<?php

declare(strict_types=1);

namespace ListingTool\Exporters;

use ListingTool\Catalog;
use ListingTool\Columns;
use ListingTool\Csv;

/** WooCommerce product import CSV (Products > Import, built-in importer). */
final class WooCommerceExporter
{
    private const BASE = ['Type', 'SKU', 'Parent', 'Name', 'Published', 'Short description', 'Description', 'Tax status',
        'Tax class', 'In stock?', 'Stock', 'Weight (kg)', 'Length (cm)', 'Width (cm)', 'Height (cm)', 'Sale price',
        'Regular price', 'Categories', 'Tags', 'Images', 'Brands', 'GTIN, UPC, EAN, or ISBN'];

    public static function export(array $rows): ExportFile
    {
        $items = Catalog::forChannel($rows, 'woocommerce');
        $maxOpts = 0;
        $children = [];
        foreach ($items as $it) {
            if ($it['kind'] === 'parent') {
                $maxOpts = max($maxOpts, count(Catalog::options($it['row']['variant_theme'])));
            }
            if ($it['kind'] === 'child') {
                $children[$it['row']['parent_sku']][] = $it['row'];
            }
        }
        $columns = self::BASE;
        for ($n = 1; $n <= $maxOpts; $n++) {
            array_push($columns, "Attribute {$n} name", "Attribute {$n} value(s)", "Attribute {$n} visible", "Attribute {$n} global");
        }

        $out = [];
        foreach ($items as ['row' => $r, 'kind' => $kind]) {
            $rec = [
                'SKU' => $r['sku'],
                'Name' => $r['title'],
                'Published' => $r['status'] === 'draft' ? '0' : '1',
                'Tax status' => 'taxable',
                'Tax class' => $r['gst_rate_pct'] !== '' ? 'GST ' . (0 + $r['gst_rate_pct']) . '%' : '',
                'Brands' => $r['brand'],
                'GTIN, UPC, EAN, or ISBN' => $r['gtin'],
            ];
            $descriptive = [
                'Short description' => self::shortDescription($r),
                'Description' => $r['description_html'],
                // WooCommerce uses ">" for sub-categories, same as category_path.
                'Categories' => $r['category_path'],
                'Tags' => $r['tags'],
                'Images' => implode(', ', Catalog::images($r)),
            ];
            if ($kind === 'parent') {
                $rec += ['Type' => 'variable'] + $descriptive;
                foreach (Catalog::options($r['variant_theme']) as $i => $o) {
                    $vals = array_values(array_unique(array_filter(array_map(
                        static fn ($c) => Catalog::optionValue($c, $o['field']), $children[$r['sku']] ?? []))));
                    $n = $i + 1;
                    $rec += ["Attribute {$n} name" => $o['name'], "Attribute {$n} value(s)" => implode(', ', $vals),
                        "Attribute {$n} visible" => '1', "Attribute {$n} global" => '1'];
                }
                $out[] = $rec;
                continue;
            }
            $rec += self::prices($r) + self::physical($r) + [
                'Stock' => $r['quantity'],
                'In stock?' => (int) $r['quantity'] > 0 ? '1' : '0',
            ];
            if ($kind === 'simple') {
                $out[] = ['Type' => 'simple'] + $rec + $descriptive;
                continue;
            }
            // Variation: Parent takes the parent SKU and the tax class follows the parent.
            $rec = ['Type' => 'variation', 'Parent' => $r['parent_sku'], 'Tax class' => 'parent', 'Images' => $r['main_image_url']] + $rec;
            foreach (Catalog::options($r['variant_theme']) as $i => $o) {
                $n = $i + 1;
                $rec += ["Attribute {$n} name" => $o['name'], "Attribute {$n} value(s)" => Catalog::optionValue($r, $o['field']),
                    "Attribute {$n} global" => '1'];
            }
            $out[] = $rec;
        }
        return new ExportFile('woocommerce', 'woocommerce_products.csv', 'text/csv', Csv::write($columns, $out), count($out));
    }

    private static function prices(array $r): array
    {
        if (is_numeric($r['mrp_inr']) && is_numeric($r['selling_price_inr']) && (float) $r['mrp_inr'] > (float) $r['selling_price_inr']) {
            return ['Regular price' => $r['mrp_inr'], 'Sale price' => $r['selling_price_inr']];
        }
        return ['Regular price' => $r['selling_price_inr'], 'Sale price' => ''];
    }

    private static function physical(array $r): array
    {
        return [
            'Weight (kg)' => Catalog::gramsToKg($r['package_weight_g'] ?: $r['weight_g']),
            'Length (cm)' => $r['package_length_cm'] ?: $r['length_cm'],
            'Width (cm)' => $r['package_width_cm'] ?: $r['width_cm'],
            'Height (cm)' => $r['package_height_cm'] ?: $r['height_cm'],
        ];
    }

    private static function shortDescription(array $r): string
    {
        $bullets = array_filter(array_map(static fn ($b) => $r[$b], Columns::BULLETS));
        if (!$bullets) {
            return '';
        }
        return '<ul>' . implode('', array_map(static fn ($b) => '<li>' . htmlspecialchars($b, ENT_QUOTES) . '</li>', $bullets)) . '</ul>';
    }
}
