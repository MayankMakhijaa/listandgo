<?php

declare(strict_types=1);

namespace ListingTool\Exporters;

use ListingTool\Catalog;
use ListingTool\Csv;

/** Shopify product import CSV (Products > Import). */
final class ShopifyExporter
{
    public const COLUMNS = ['Handle', 'Title', 'Body (HTML)', 'Vendor', 'Product Category', 'Type', 'Tags', 'Published',
        'Option1 Name', 'Option1 Value', 'Option2 Name', 'Option2 Value', 'Option3 Name', 'Option3 Value',
        'Variant SKU', 'Variant Grams', 'Variant Inventory Tracker', 'Variant Inventory Qty', 'Variant Inventory Policy',
        'Variant Fulfillment Service', 'Variant Price', 'Variant Compare At Price', 'Variant Requires Shipping',
        'Variant Taxable', 'Variant Barcode', 'Image Src', 'Image Position', 'Image Alt Text', 'SEO Title',
        'SEO Description', 'Variant Image', 'Variant Weight Unit', 'Variant HS Code', 'Country of Origin', 'Cost per item', 'Status'];

    public static function handle(string $s): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
    }

    public static function export(array $rows): ExportFile
    {
        $items = Catalog::forChannel($rows, 'shopify');
        $children = [];
        foreach ($items as $it) {
            if ($it['kind'] === 'child') {
                $children[$it['row']['parent_sku']][] = $it['row'];
            }
        }

        $out = [];
        foreach ($items as $it) {
            if ($it['kind'] === 'child') {
                continue;
            }
            $p = $it['row'];
            $handle = self::handle($p['sku']);
            $lines = [];
            if ($it['kind'] === 'simple') {
                $lines[] = ['Handle' => $handle] + self::product($p) + ['Option1 Name' => 'Title', 'Option1 Value' => 'Default Title'] + self::variant($p);
            } else {
                $opts = array_slice(Catalog::options($p['variant_theme']), 0, 3);
                foreach ($children[$p['sku']] ?? [] as $i => $c) {
                    $line = ['Handle' => $handle] + ($i === 0 ? self::product($p) : []) + self::variant($c);
                    foreach ($opts as $n => $o) {
                        if ($i === 0) {
                            $line['Option' . ($n + 1) . ' Name'] = $o['name'];
                        }
                        $line['Option' . ($n + 1) . ' Value'] = Catalog::optionValue($c, $o['field']);
                    }
                    if ($c['main_image_url'] !== '' && $c['main_image_url'] !== $p['main_image_url']) {
                        $line['Variant Image'] = $c['main_image_url'];
                    }
                    $lines[] = $line;
                }
            }
            // Product images: one per line, extra lines carry only Handle and image fields.
            foreach (Catalog::images($p) as $i => $src) {
                $img = ['Image Src' => $src, 'Image Position' => $i + 1, 'Image Alt Text' => $p['title']];
                if (isset($lines[$i])) {
                    $lines[$i] += $img;
                } else {
                    $lines[] = ['Handle' => $handle] + $img;
                }
            }
            array_push($out, ...$lines);
        }
        return new ExportFile('shopify', 'shopify_products.csv', 'text/csv', Csv::write(self::COLUMNS, $out), count($out));
    }

    private static function product(array $r): array
    {
        return [
            'Title' => $r['title'],
            'Body (HTML)' => $r['description_html'],
            'Vendor' => $r['brand'],
            // Shopify's Product Category must match its own taxonomy, so we leave it blank and use Type.
            'Product Category' => '',
            'Type' => $r['product_type'],
            'Tags' => $r['tags'],
            'Published' => $r['status'] === 'draft' ? 'FALSE' : 'TRUE',
            'SEO Title' => $r['seo_title'],
            'SEO Description' => $r['seo_description'],
            'Status' => $r['status'] !== '' ? $r['status'] : 'active',
        ];
    }

    /** Shopify wants a 2-letter ISO country code. */
    public static function countryCode(string $country): string
    {
        $map = ['india' => 'IN', 'china' => 'CN', 'bangladesh' => 'BD', 'sri lanka' => 'LK', 'vietnam' => 'VN',
            'united states' => 'US', 'usa' => 'US', 'united kingdom' => 'GB', 'uk' => 'GB'];
        $key = strtolower(trim($country));
        return $map[$key] ?? (strlen($key) === 2 ? strtoupper($key) : '');
    }

    private static function variant(array $r): array
    {
        return [
            'Variant SKU' => $r['sku'],
            'Variant Grams' => $r['weight_g'],
            'Variant Inventory Tracker' => 'shopify',
            'Variant Inventory Qty' => $r['quantity'],
            'Variant Inventory Policy' => 'deny',
            'Variant Fulfillment Service' => 'manual',
            'Variant Price' => $r['selling_price_inr'],
            'Variant Compare At Price' => $r['mrp_inr'] !== $r['selling_price_inr'] ? $r['mrp_inr'] : '',
            'Variant Requires Shipping' => 'TRUE',
            'Variant Taxable' => 'TRUE',
            'Variant Barcode' => $r['gtin'],
            'Variant Weight Unit' => 'g',
            'Variant HS Code' => $r['hsn_code'],
            'Country of Origin' => self::countryCode($r['country_of_origin']),
            'Cost per item' => $r['cost_price_inr'],
        ];
    }
}
