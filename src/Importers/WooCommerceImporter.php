<?php

declare(strict_types=1);

namespace ListingTool\Importers;

/**
 * WooCommerce built-in product export CSV -> master rows.
 * Type is simple, variable (parent) or variation (child; Parent is "id:123" or the parent SKU).
 */
final class WooCommerceImporter
{
    public static function convert(array $records): array
    {
        $idToSku = [];
        foreach ($records as $r) {
            if (($r['ID'] ?? '') !== '') {
                $idToSku['id:' . $r['ID']] = ($r['SKU'] ?? '') ?: 'woo-' . $r['ID'];
            }
        }

        $out = [];
        foreach ($records as $r) {
            $type = strtolower($r['Type'] ?? '');
            if ($type === '') {
                continue;
            }
            $isVariation = str_contains($type, 'variation');
            $attrs = self::attributes($r);
            $regular = $r['Regular price'] ?? '';
            $sale = $r['Sale price'] ?? '';
            $parentRef = $r['Parent'] ?? '';
            $kg = $r['Weight (kg)'] ?? '';
            $grams = is_numeric($kg) ? (string) round((float) $kg * 1000) : '';
            $categories = explode(',', $r['Categories'] ?? '');

            $row = [
                'sku' => ($r['SKU'] ?? '') ?: 'woo-' . ($r['ID'] ?? ''),
                'parent_sku' => $isVariation ? ($idToSku[$parentRef] ?? $parentRef) : '',
                'variant_theme' => ($isVariation || str_contains($type, 'variable')) ? implode('-', array_keys($attrs)) : '',
                'title' => $r['Name'] ?? '',
                'brand' => $r['Brands'] ?? '',
                'description_html' => $r['Description'] ?? '',
                'bullet_1' => trim(strip_tags($r['Short description'] ?? '')),
                'tags' => $r['Tags'] ?? '',
                'search_keywords' => $r['Tags'] ?? '',
                'category_path' => trim($categories[0]),
                'gtin' => $r['GTIN, UPC, EAN, or ISBN'] ?? '',
                'mrp_inr' => $sale !== '' ? $regular : '',
                'selling_price_inr' => $sale !== '' ? $sale : $regular,
                'quantity' => $r['Stock'] ?? '',
                'color' => $attrs['color'] ?? ($attrs['colour'] ?? ''),
                'size' => $attrs['size'] ?? '',
                'weight_g' => $grams,
                'package_weight_g' => $grams,
                'package_length_cm' => $r['Length (cm)'] ?? '',
                'package_width_cm' => $r['Width (cm)'] ?? '',
                'package_height_cm' => $r['Height (cm)'] ?? '',
                'status' => ($r['Published'] ?? '') === '1' ? 'active' : 'draft',
            ];
            $images = array_slice(array_values(array_filter(array_map('trim', explode(',', $r['Images'] ?? '')))), 0, 8);
            foreach ($images as $i => $src) {
                $row[$i === 0 ? 'main_image_url' : 'image_url_' . ($i + 1)] = $src;
            }
            $out[] = $row;
        }
        return $out;
    }

    /** @return array<string,string> lower-case attribute name => value(s) */
    private static function attributes(array $r): array
    {
        $out = [];
        for ($i = 1; $i <= 6; $i++) {
            $name = $r["Attribute {$i} name"] ?? '';
            if ($name !== '') {
                $out[preg_replace('/^pa_/', '', strtolower($name))] = $r["Attribute {$i} value(s)"] ?? '';
            }
        }
        return $out;
    }
}
