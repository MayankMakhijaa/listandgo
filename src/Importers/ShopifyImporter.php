<?php

declare(strict_types=1);

namespace ListingTool\Importers;

/**
 * Shopify product export CSV -> master rows.
 * Shopify writes one row per variant plus one row per extra image, all sharing a Handle;
 * only the first row of a handle carries Title, Body, Vendor and so on.
 */
final class ShopifyImporter
{
    public static function convert(array $records): array
    {
        $byHandle = [];
        foreach ($records as $r) {
            if (($r['Handle'] ?? '') !== '') {
                $byHandle[$r['Handle']][] = $r;
            }
        }

        $out = [];
        foreach ($byHandle as $handle => $rows) {
            $head = $rows[0];
            $withImg = array_values(array_filter($rows, static fn ($r) => ($r['Image Src'] ?? '') !== ''));
            usort($withImg, static fn ($a, $b) => (int) ($a['Image Position'] ?? 0) <=> (int) ($b['Image Position'] ?? 0));
            $images = array_slice(array_column($withImg, 'Image Src'), 0, 8);
            $variants = array_values(array_filter($rows, static fn ($r) => ($r['Variant SKU'] ?? '') !== '' || ($r['Variant Price'] ?? '') !== ''));
            $names = [$head['Option1 Name'] ?? '', $head['Option2 Name'] ?? '', $head['Option3 Name'] ?? ''];
            $realNames = array_values(array_filter($names, static fn ($n) => $n !== '' && $n !== 'Title'));
            $hasVariants = count($variants) > 1 || $realNames !== [];

            $status = ($head['Status'] ?? '') !== ''
                ? strtolower($head['Status'])
                : (strcasecmp($head['Published'] ?? '', 'true') === 0 ? 'active' : 'draft');
            $base = [
                'title' => $head['Title'] ?? '',
                'brand' => $head['Vendor'] ?? '',
                'description_html' => $head['Body (HTML)'] ?? '',
                'tags' => $head['Tags'] ?? '',
                'search_keywords' => $head['Tags'] ?? '',
                'category_path' => $head['Product Category'] ?? '',
                'product_type' => $head['Type'] ?? '',
                'seo_title' => $head['SEO Title'] ?? '',
                'seo_description' => $head['SEO Description'] ?? '',
                'status' => $status,
                'country_of_origin' => $head['Country of Origin'] ?? '',
            ];
            $imageCols = [];
            foreach ($images as $i => $src) {
                $imageCols[$i === 0 ? 'main_image_url' : 'image_url_' . ($i + 1)] = $src;
            }

            $variantRow = static function (array $v, string $sku) use ($names): array {
                $opt = static function (string $want) use ($v, $names): string {
                    foreach ($names as $i => $n) {
                        if ($n !== '' && strtolower($n) === $want) {
                            return $v['Option' . ($i + 1) . ' Value'] ?? '';
                        }
                    }
                    return '';
                };
                $barcode = $v['Variant Barcode'] ?? '';
                $row = [
                    'sku' => $sku,
                    'selling_price_inr' => $v['Variant Price'] ?? '',
                    'mrp_inr' => $v['Variant Compare At Price'] ?? '',
                    'cost_price_inr' => $v['Cost per item'] ?? '',
                    'quantity' => $v['Variant Inventory Qty'] ?? '',
                    'gtin' => $barcode,
                    'gtin_type' => $barcode === '' ? '' : (strlen($barcode) === 12 ? 'UPC' : 'EAN'),
                    'hsn_code' => $v['Variant HS Code'] ?? ($v['Harmonized System Code'] ?? ''),
                    'weight_g' => self::grams($v),
                    'color' => $opt('color') ?: $opt('colour'),
                    'size' => $opt('size'),
                ];
                if (($v['Variant Image'] ?? '') !== '') {
                    $row['main_image_url'] = $v['Variant Image'];
                }
                return $row;
            };

            if (!$hasVariants) {
                $v = $variants[0] ?? $head;
                $out[] = $variantRow($v, ($v['Variant SKU'] ?? '') ?: $handle) + $base + $imageCols;
                continue;
            }
            $theme = implode('-', array_map('strtolower', $realNames));
            $out[] = ['sku' => $handle, 'variant_theme' => $theme] + $base + $imageCols;
            foreach ($variants as $i => $v) {
                $out[] = $variantRow($v, ($v['Variant SKU'] ?? '') ?: $handle . '-' . ($i + 1))
                    + ['parent_sku' => $handle, 'variant_theme' => $theme, 'title' => $base['title']];
            }
        }
        return $out;
    }

    private static function grams(array $v): string
    {
        if (($v['Variant Grams'] ?? '') !== '') {
            return $v['Variant Grams'];
        }
        $w = (float) ($v['Variant Weight'] ?? 0);
        if ($w <= 0) {
            return '';
        }
        $factor = match (strtolower($v['Variant Weight Unit'] ?? 'g')) {
            'kg' => 1000, 'lb' => 453.592, 'oz' => 28.3495, default => 1,
        };
        return (string) round($w * $factor);
    }
}
