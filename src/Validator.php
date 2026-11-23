<?php

declare(strict_types=1);

namespace ListingTool;

use ListingTool\Exporters\Amazon\AmazonMapper;

/**
 * Checks master rows against each channel's rules.
 * Each issue: ['sku', 'row' (1-based data row), 'channel' (null = all), 'field', 'level' (error|warning), 'message'].
 */
final class Validator
{
    private const COMMON = ['sku', 'title', 'brand', 'description_html', 'selling_price_inr', 'quantity', 'main_image_url'];

    private const REQUIRED = [
        'amazon' => [...self::COMMON, 'mrp_inr', 'hsn_code', 'gst_rate_pct', 'bullet_1', 'country_of_origin', 'manufacturer', 'product_type'],
        'flipkart' => [...self::COMMON, 'mrp_inr', 'hsn_code', 'gst_rate_pct', 'country_of_origin', 'manufacturer_address',
            'packer_details', 'package_weight_g', 'package_length_cm', 'package_width_cm', 'package_height_cm', 'dispatch_days'],
        'shopify' => ['sku', 'title', 'selling_price_inr'],
        'woocommerce' => ['sku', 'title', 'selling_price_inr'],
    ];

    /** Extra fields Amazon and Flipkart ask for on clothing. */
    private const APPAREL_REQUIRED = ['color', 'size', 'material', 'target_gender', 'age_group'];
    private const APPAREL_RECOMMENDED = ['attr:fit_type', 'attr:sleeve_type', 'attr:neck_type', 'pattern', 'attr:care_instructions', 'attr:closure_type'];

    public static function isApparel(array $row): bool
    {
        return preg_match('/cloth|apparel|shirt|t-shirt|tee|kurta|kurti|dress|jeans|trouser|saree|top|shorts|jacket|hoodie|legging|romper|onesie|jhabla|night ?suit|sleep|pajama|pyjama|frock/i',
            $row['category_path'] . ' ' . $row['product_type']) === 1;
    }

    /** @param list<string> $channels */
    public static function validate(array $rows, array $channels = Columns::CHANNELS): array
    {
        $issues = [];
        $add = static function (int $i, array $r, ?string $ch, string $field, string $level, string $msg) use (&$issues): void {
            $issues[] = ['sku' => $r['sku'] !== '' ? $r['sku'] : '(blank)', 'row' => $i + 1, 'channel' => $ch,
                'field' => $field, 'level' => $level, 'message' => $msg];
        };

        // File-level checks.
        $seen = [];
        $skus = array_flip(array_column($rows, 'sku'));
        foreach ($rows as $i => $r) {
            if ($r['sku'] === '') {
                $add($i, $r, null, 'sku', 'error', 'SKU is blank.');
            } elseif (isset($seen[$r['sku']])) {
                $add($i, $r, null, 'sku', 'error', "Duplicate SKU, also on row {$seen[$r['sku']]}.");
            } else {
                $seen[$r['sku']] = $i + 1;
            }
            if ($r['parent_sku'] !== '' && !isset($skus[$r['parent_sku']])) {
                $add($i, $r, null, 'parent_sku', 'error', "Parent SKU {$r['parent_sku']} is not in the file.");
            }
            if ($r['parent_sku'] !== '' && $r['variant_theme'] === '') {
                $add($i, $r, null, 'variant_theme', 'warning', 'Variant row has no variant_theme (for example color-size).');
            }
        }

        // Row checks on sellable rows (children inherit parent fields).
        foreach (Catalog::sellable($rows) as $i => $s) {
            foreach (Columns::NUMERIC as $f) {
                if (($s[$f] ?? '') !== '' && !is_numeric($s[$f])) {
                    $add($i, $s, null, $f, 'error', "{$f} must be a number, got \"{$s[$f]}\".");
                }
            }
            if ($s['gst_rate_pct'] !== '' && is_numeric($s['gst_rate_pct'])) {
                $rate = (string) (0 + $s['gst_rate_pct']);
                if (!in_array($rate, Columns::GST_RATES, true)) {
                    $add($i, $s, null, 'gst_rate_pct', 'error', "GST {$s['gst_rate_pct']}% is not an Indian GST rate.");
                } elseif (in_array($rate, ['12', '28'], true)) {
                    $add($i, $s, null, 'gst_rate_pct', 'warning', "The {$rate}% GST slab was largely removed in Sept 2025. Check the current rate for HSN {$s['hsn_code']}.");
                }
            }
            if ($s['hsn_code'] !== '' && preg_match('/^\d{4}(\d{2}){0,2}$/', $s['hsn_code']) !== 1) {
                $add($i, $s, null, 'hsn_code', 'error', 'HSN code must be 4, 6 or 8 digits.');
            }
            if ($s['gtin'] !== '' && preg_match('/^\d{8,14}$/', $s['gtin']) !== 1) {
                $add($i, $s, null, 'gtin', 'error', 'GTIN must be 8 to 14 digits.');
            }
            if (is_numeric($s['mrp_inr']) && is_numeric($s['selling_price_inr']) && (float) $s['selling_price_inr'] > (float) $s['mrp_inr']) {
                $add($i, $s, null, 'selling_price_inr', 'error', "Selling price ₹{$s['selling_price_inr']} is above MRP ₹{$s['mrp_inr']}.");
            }
            foreach (Columns::IMAGES as $c) {
                if ($s[$c] !== '' && preg_match('#^https://#i', $s[$c]) !== 1) {
                    $add($i, $s, null, $c, 'error', 'Image URL must be a public https:// link.');
                }
            }

            $apparel = self::isApparel($s);
            // Apparel GST after the Sept 2025 revision, as we understand it: 5% up to ₹2,500 a piece, 18% above.
            if ($apparel && is_numeric($s['selling_price_inr']) && is_numeric($s['gst_rate_pct'])) {
                $expected = (float) $s['selling_price_inr'] <= 2500 ? 5 : 18;
                if ((float) $s['gst_rate_pct'] !== (float) $expected) {
                    $add($i, $s, null, 'gst_rate_pct', 'warning', "Clothing at ₹{$s['selling_price_inr']} is usually taxed at {$expected}% GST, not {$s['gst_rate_pct']}%. Check with your tax advisor.");
                }
            }
            foreach ($channels as $ch) {
                if (!Catalog::wantsChannel($s, $ch)) {
                    continue;
                }
                foreach (self::REQUIRED[$ch] as $f) {
                    if ($s[$f] === '') {
                        $add($i, $s, $ch, $f, 'error', "{$f} is required for " . Columns::CHANNEL_LABELS[$ch] . '.');
                    }
                }
                if ($apparel && in_array($ch, ['amazon', 'flipkart'], true)) {
                    foreach (self::APPAREL_REQUIRED as $f) {
                        if ($s[$f] === '') {
                            $add($i, $s, $ch, $f, 'error', "{$f} is required for clothing on " . Columns::CHANNEL_LABELS[$ch] . '.');
                        }
                    }
                    foreach (self::APPAREL_RECOMMENDED as $f) {
                        if (($s[$f] ?? '') === '') {
                            $add($i, $s, $ch, $f, 'warning', "Add {$f} for clothing; " . Columns::CHANNEL_LABELS[$ch] . ' usually asks for it.');
                        }
                    }
                }
                if ($ch === 'amazon' && $apparel) {
                    $kind = $s['parent_sku'] !== '' ? 'child' : 'simple';
                    foreach (AmazonMapper::map($s, $kind)['problems'] as $p) {
                        $add($i, $s, $ch, $p['field'], $p['level'], $p['message']);
                    }
                } elseif ($ch === 'amazon' && $s['gtin'] === '') {
                    $add($i, $s, $ch, 'gtin', 'warning', 'No GTIN. Amazon needs one unless your brand has a GTIN exemption.');
                }
                if ($ch === 'flipkart' && $apparel && AmazonMapper::isBaby($s) && $s['size'] !== '' && AmazonMapper::ageSize($s['size']) === null) {
                    $add($i, $s, $ch, 'size', 'error', "Baby sizes must be ages, such as \"0-3 Months\" or \"1-2 Years\". Got \"{$s['size']}\".");
                }
                if ($ch === 'amazon' && mb_strlen($s['title']) > 200) {
                    $add($i, $s, $ch, 'title', 'warning', 'Amazon usually suppresses titles over 200 characters.');
                }
            }
        }
        usort($issues, static fn ($a, $b) => [$a['row'], $a['level'] === 'error' ? 0 : 1] <=> [$b['row'], $b['level'] === 'error' ? 0 : 1]);
        return $issues;
    }

    /** @return array{errors: int, warnings: int} */
    public static function summarise(array $issues): array
    {
        $errors = count(array_filter($issues, static fn ($i) => $i['level'] === 'error'));
        return ['errors' => $errors, 'warnings' => count($issues) - $errors];
    }
}
