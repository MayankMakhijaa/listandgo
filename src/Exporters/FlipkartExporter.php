<?php

declare(strict_types=1);

namespace ListingTool\Exporters;

use ListingTool\Catalog;
use ListingTool\Columns;

/**
 * Flipkart listing sheet, apparel first.
 *
 * Flipkart has no parent rows: each size/colour is its own listing, grouped by Style Code.
 * Column names follow Flipkart's clothing (for example T-Shirt) vertical sheet as we know it;
 * the seller copies these rows into the sheet downloaded from Seller Hub > Listings > Add in bulk.
 */
final class FlipkartExporter
{
    public const COLUMNS = ['Seller SKU ID', 'Style Code', 'Listing Status', 'MRP (INR)', 'Your selling price (INR)',
        'Fullfilment by', 'Procurement type', 'Procurement SLA (DAY)', 'Stock', 'Shipping provider',
        'Length (CM)', 'Breadth (CM)', 'Height (CM)', 'Weight (KG)', 'HSN', 'Tax Code', 'Country Of Origin',
        'Manufacturer Details', 'Packer Details', 'Importer Details', 'Brand', 'Model Name', 'Model Number',
        'Color', 'Brand Color', 'Size', 'Ideal For', 'Fabric', 'Pattern', 'Sleeve', 'Neck', 'Fit', 'Fabric Care',
        'Pack of', 'Description', 'Key Features', 'Search Keywords', 'EAN/UPC',
        'Main Image URL', 'Other Image URL 1', 'Other Image URL 2', 'Other Image URL 3', 'Other Image URL 4'];

    private const ATTR_MAP = ['attr:sleeve_type' => 'Sleeve', 'attr:neck_type' => 'Neck', 'attr:fit_type' => 'Fit',
        'attr:care_instructions' => 'Fabric Care', 'attr:occasion' => 'Occasion'];

    public static function idealFor(string $gender, string $age): string
    {
        $g = strtolower($gender);
        $baby = preg_match('/infant|baby|newborn|toddler/i', $age) === 1;
        $kid = preg_match('/kid|child/i', $age) === 1;
        return match (true) {
            $baby && in_array($g, ['men', 'male', 'boys'], true) => 'Baby Boys',
            $baby && in_array($g, ['women', 'female', 'girls'], true) => 'Baby Girls',
            $baby => 'Baby Boys & Baby Girls',
            $kid && in_array($g, ['men', 'male', 'boys'], true) => 'Boys',
            $kid && in_array($g, ['women', 'female', 'girls'], true) => 'Girls',
            $kid => 'Boys & Girls',
            in_array($g, ['men', 'male'], true) => 'Men',
            in_array($g, ['women', 'female'], true) => 'Women',
            default => 'Men & Women',
        };
    }

    public static function export(array $rows): ExportFile
    {
        $extra = [];
        foreach (Catalog::attrColumns($rows) as $a) {
            $name = self::ATTR_MAP[$a] ?? ucwords(str_replace('_', ' ', substr($a, 5)));
            if (!in_array($name, self::COLUMNS, true)) {
                $extra[$a] = $name;
            }
        }
        $columns = [...self::COLUMNS, ...array_values($extra)];

        $out = [];
        foreach (Catalog::forChannel($rows, 'flipkart') as ['row' => $r, 'kind' => $kind, 'parent' => $parent]) {
            if ($kind === 'parent') {
                continue;
            }
            $bullets = array_filter(array_map(static fn ($b) => $r[$b], Columns::BULLETS));
            $images = Catalog::images($r);
            $rec = [
                'Seller SKU ID' => $r['sku'],
                'Style Code' => $r['parent_sku'] !== '' ? $r['parent_sku'] : $r['sku'],
                'Listing Status' => $r['status'] === 'draft' ? 'INACTIVE' : 'ACTIVE',
                'MRP (INR)' => $r['mrp_inr'],
                'Your selling price (INR)' => $r['selling_price_inr'],
                'Fullfilment by' => 'Seller',
                'Procurement type' => 'Express',
                'Procurement SLA (DAY)' => $r['dispatch_days'],
                'Stock' => $r['quantity'],
                'Shipping provider' => 'Flipkart',
                'Length (CM)' => $r['package_length_cm'],
                'Breadth (CM)' => $r['package_width_cm'],
                'Height (CM)' => $r['package_height_cm'],
                'Weight (KG)' => Catalog::gramsToKg($r['package_weight_g']),
                'HSN' => $r['hsn_code'],
                'Tax Code' => $r['gst_rate_pct'] !== '' ? 'GST_' . (0 + $r['gst_rate_pct']) : '',
                'Country Of Origin' => $r['country_of_origin'],
                'Manufacturer Details' => Catalog::contact($r['manufacturer'], $r['manufacturer_address']),
                'Packer Details' => $r['packer_details'],
                'Importer Details' => $r['importer_details'],
                'Brand' => $r['brand'],
                // Model Name is shared by every size and colour of a style.
                'Model Name' => $parent['title'] ?? $r['title'],
                'Model Number' => $r['model_number'],
                'Color' => $r['color'],
                'Brand Color' => $r['color'],
                'Size' => $r['size'],
                'Ideal For' => self::idealFor($r['target_gender'], $r['age_group']),
                'Fabric' => $r['material'],
                'Pattern' => $r['pattern'],
                'Pack of' => $r['net_quantity'] !== '' ? $r['net_quantity'] : '1',
                'Description' => Catalog::stripHtml($r['description_html']),
                'Key Features' => implode('; ', $bullets),
                'Search Keywords' => $r['search_keywords'],
                'EAN/UPC' => $r['gtin'],
                'Main Image URL' => $images[0] ?? '',
            ];
            foreach (array_slice($images, 1, 4) as $i => $src) {
                $rec['Other Image URL ' . ($i + 1)] = $src;
            }
            foreach (self::ATTR_MAP + $extra as $a => $name) {
                if (($r[$a] ?? '') !== '') {
                    $rec[$name] = $r[$a];
                }
            }
            $out[] = $rec;
        }

        $notes = [
            'List and Go: Flipkart listing rows',
            '',
            '1. In Flipkart Seller Hub, go to Listings > Add new listings > Bulk upload and download the sheet for your vertical (for clothing, for example T-Shirt, Shirt or Kurta).',
            '2. Copy the rows from the "Flipkart listings" sheet into that file, matching columns by name. Flipkart column names vary by vertical; ours follow the clothing sheets.',
            '3. Each size and colour is its own listing. Rows of the same product share a Style Code.',
            '4. Values such as Sleeve, Neck, Fit and Ideal For must match Flipkart\'s dropdown values in your sheet.',
        ];
        return new ExportFile('flipkart', 'flipkart_listings.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            Xlsx::build('Flipkart listings', $columns, $out, $notes), count($out));
    }
}
