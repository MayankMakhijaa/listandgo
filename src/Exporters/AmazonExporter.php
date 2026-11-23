<?php

declare(strict_types=1);

namespace ListingTool\Exporters;

use ListingTool\Catalog;

/**
 * Amazon India category flat file, apparel first.
 *
 * Column names follow Amazon's clothing flat file attribute row as we know it; they vary by
 * category and change over time, so the seller copies these rows into the template downloaded
 * from Seller Central (Catalog > Add products via upload). See the "Read me" sheet.
 */
final class AmazonExporter
{
    public const COLUMNS = ['feed_product_type', 'item_sku', 'update_delete', 'brand_name', 'item_name', 'external_product_id',
        'external_product_id_type', 'manufacturer', 'part_number', 'product_description', 'recommended_browse_nodes',
        'standard_price', 'maximum_retail_price', 'quantity', 'fulfillment_latency', 'condition_type',
        'main_image_url', 'other_image_url1', 'other_image_url2', 'other_image_url3', 'other_image_url4',
        'other_image_url5', 'other_image_url6', 'other_image_url7',
        'parent_child', 'parent_sku', 'relationship_type', 'variation_theme',
        'bullet_point1', 'bullet_point2', 'bullet_point3', 'bullet_point4', 'bullet_point5', 'generic_keywords',
        'department_name', 'target_gender', 'age_range_description', 'color_name', 'color_map', 'size_name', 'size_map',
        'material_type', 'fabric_type', 'pattern_type', 'sleeve_type', 'neck_style', 'fit_type', 'care_instructions',
        'country_of_origin', 'hsn_code', 'product_tax_code', 'item_weight', 'item_weight_unit_of_measure',
        'item_package_length', 'item_package_width', 'item_package_height', 'item_package_dimensions_unit_of_measure',
        'package_weight', 'package_weight_unit_of_measure', 'unit_count', 'unit_count_type',
        'manufacturer_contact_information', 'packer_contact_information', 'importer_contact_information', 'warranty_description'];

    /** attr:* columns that map onto named Amazon attributes. Others pass through without the prefix. */
    private const ATTR_MAP = ['attr:fit_type' => 'fit_type', 'attr:sleeve_type' => 'sleeve_type', 'attr:neck_type' => 'neck_style',
        'attr:care_instructions' => 'care_instructions', 'attr:fabric_type' => 'fabric_type'];

    public static function variationTheme(string $theme): string
    {
        $fields = array_column(Catalog::options($theme), 'field');
        $hasSize = in_array('size', $fields, true);
        $hasColor = in_array('color', $fields, true);
        return match (true) {
            $hasSize && $hasColor => 'SizeColor',
            $hasSize => 'Size',
            $hasColor => 'Color',
            default => implode('', array_map('ucfirst', $fields)),
        };
    }

    public static function department(string $gender, string $age): string
    {
        $g = strtolower($gender);
        $kid = preg_match('/kid|child|infant|baby|toddler/i', $age) === 1;
        return match (true) {
            $kid && $g === 'men', $kid && $g === 'male' => 'Boys',
            $kid && in_array($g, ['women', 'female'], true) => 'Girls',
            $kid => 'Unisex Kids',
            in_array($g, ['men', 'male'], true) => 'Men',
            in_array($g, ['women', 'female'], true) => 'Women',
            default => 'Unisex',
        };
    }

    public static function export(array $rows): ExportFile
    {
        $items = Catalog::forChannel($rows, 'amazon');
        $extra = [];
        foreach (Catalog::attrColumns($rows) as $a) {
            $name = self::ATTR_MAP[$a] ?? substr($a, 5);
            if (!in_array($name, self::COLUMNS, true)) {
                $extra[$a] = $name;
            }
        }
        $columns = [...self::COLUMNS, ...array_values($extra)];

        $out = [];
        foreach ($items as ['row' => $r, 'kind' => $kind]) {
            $rec = [
                'feed_product_type' => strtolower((string) preg_replace('/[^a-z0-9]/i', '', $r['product_type'])),
                'item_sku' => $r['sku'],
                'update_delete' => 'Update',
                'brand_name' => $r['brand'],
                'item_name' => $r['title'],
                'manufacturer' => $r['manufacturer'],
                'part_number' => $r['model_number'],
                'product_description' => Catalog::stripHtml($r['description_html']),
                'generic_keywords' => $r['search_keywords'],
                'department_name' => self::department($r['target_gender'], $r['age_group']),
                'target_gender' => $r['target_gender'],
                'age_range_description' => $r['age_group'],
                'material_type' => $r['material'],
                'fabric_type' => $r['material'],
                'pattern_type' => $r['pattern'],
                'country_of_origin' => $r['country_of_origin'],
                'hsn_code' => $r['hsn_code'],
                'product_tax_code' => $r['gst_rate_pct'] !== '' ? 'A_GEN_GST_' . (0 + $r['gst_rate_pct']) : '',
                'manufacturer_contact_information' => Catalog::contact($r['manufacturer'], $r['manufacturer_address']),
                'packer_contact_information' => $r['packer_details'],
                'importer_contact_information' => $r['importer_details'],
                'warranty_description' => $r['warranty'],
                'main_image_url' => $r['main_image_url'],
            ];
            foreach (array_slice(Catalog::images($r), 1, 7) as $i => $src) {
                $rec['other_image_url' . ($i + 1)] = $src;
            }
            foreach (\ListingTool\Columns::BULLETS as $i => $b) {
                $rec['bullet_point' . ($i + 1)] = $r[$b];
            }
            foreach (self::ATTR_MAP + $extra as $a => $name) {
                if (($r[$a] ?? '') !== '') {
                    $rec[$name] = $r[$a];
                }
            }
            if ($kind === 'parent') {
                $rec += ['parent_child' => 'parent', 'variation_theme' => self::variationTheme($r['variant_theme'])];
                $out[] = $rec;
                continue;
            }
            $rec += [
                'external_product_id' => $r['gtin'],
                'external_product_id_type' => $r['gtin'] !== '' ? ($r['gtin_type'] ?: 'EAN') : '',
                'standard_price' => $r['selling_price_inr'],
                'maximum_retail_price' => $r['mrp_inr'],
                'quantity' => $r['quantity'],
                'fulfillment_latency' => $r['dispatch_days'],
                'condition_type' => $r['condition'] !== '' ? $r['condition'] : 'New',
                'color_name' => $r['color'],
                'color_map' => $r['color'],
                'size_name' => $r['size'],
                'size_map' => $r['size'],
                'item_weight' => $r['weight_g'],
                'item_weight_unit_of_measure' => $r['weight_g'] !== '' ? 'GR' : '',
                'item_package_length' => $r['package_length_cm'],
                'item_package_width' => $r['package_width_cm'],
                'item_package_height' => $r['package_height_cm'],
                'item_package_dimensions_unit_of_measure' => $r['package_length_cm'] !== '' ? 'CM' : '',
                'package_weight' => $r['package_weight_g'],
                'package_weight_unit_of_measure' => $r['package_weight_g'] !== '' ? 'GR' : '',
                'unit_count' => $r['net_quantity'],
                'unit_count_type' => $r['net_quantity_unit'],
            ];
            if ($kind === 'child') {
                $rec += ['parent_child' => 'child', 'parent_sku' => $r['parent_sku'], 'relationship_type' => 'Variation',
                    'variation_theme' => self::variationTheme($r['variant_theme'])];
            }
            $out[] = $rec;
        }

        $notes = [
            'List and Go: Amazon India listing rows',
            '',
            '1. In Seller Central, open Catalog > Add products via upload and download the template for your category (for clothing: Clothing & Accessories, product type such as SHIRT or TSHIRT).',
            '2. Copy the rows from the "Amazon listings" sheet into the template, matching columns by name. Column names here follow the clothing template and may differ slightly in yours.',
            '3. Fill recommended_browse_nodes with the browse node for your category (shown in the template\'s Valid Values sheet).',
            '4. Upload the template. Amazon shows any errors in the processing report.',
            '',
            'Parent rows (parent_child = parent) carry no price or stock. Each size and colour is a child row that points at its parent_sku.',
            'product_tax_code uses Amazon\'s A_GEN_GST_<rate> pattern; check it against the Valid Values sheet of your template.',
        ];
        return new ExportFile('amazon', 'amazon_listings.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            Xlsx::build('Amazon listings', $columns, $out, $notes), count($out));
    }
}
