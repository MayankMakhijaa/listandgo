<?php

declare(strict_types=1);

namespace ListingTool\Exporters\Amazon;

use ListingTool\Catalog;
use ListingTool\Columns;

/**
 * Maps a List and Go row onto the columns of Amazon India's clothing template
 * (product types SHIRT, SWEATSHIRT, NIGHTGOWN_NIGHTSHIRT), using the dropdown
 * values captured from that template in schema_clothing_in.php.
 *
 * map() returns the cell values keyed by attribute key plus a list of problems,
 * so the validator and the exporter agree on what Amazon will accept.
 */
final class AmazonMapper
{
    private static ?array $schema = null;

    /** Plain-word colours to Amazon's Color Map values. */
    private const COLOR_SYNONYMS = [
        'navy' => 'Blue', 'sky' => 'Blue', 'teal' => 'Turquoise', 'aqua' => 'Turquoise', 'maroon' => 'Red',
        'wine' => 'Red', 'burgundy' => 'Red', 'cream' => 'Off White', 'ivory' => 'Off White', 'peach' => 'Orange',
        'coral' => 'Orange', 'mint' => 'Green', 'olive' => 'Green', 'lavender' => 'Purple', 'lilac' => 'Purple',
        'charcoal' => 'Grey', 'gray' => 'Grey', 'khaki' => 'Beige', 'multi' => 'Multicolor', 'mustard' => 'Yellow',
    ];

    /** GST rate to Amazon India product tax code. */
    public const TAX_CODES = ['0' => 'A_GEN_EXEMPT', '5' => 'A_GEN_SUPERREDUCED', '12' => 'A_GEN_REDUCED',
        '18' => 'A_GEN_STANDARD', '28' => 'A_GEN_PEAK', '40' => 'A_GEN_HIGHPEAK'];

    public static function schema(): array
    {
        return self::$schema ??= require __DIR__ . '/schema_clothing_in.php';
    }

    /** Attribute key without marketplace/language qualifiers, e.g. "department#1.value". */
    public static function shortKey(string $key): string
    {
        return (string) preg_replace('/\[(marketplace_id|language_tag)=[^\]]*\]/', '', $key);
    }

    /** Dropdown values for a column label and product type ([] = free text). */
    public static function valid(string $label, string $ptype): array
    {
        $v = self::schema()['valid_values'][$label] ?? [];
        return $v[$ptype] ?? $v['*'] ?? [];
    }

    /** Case-insensitive pick of an allowed value; '' when none matches. Free-text columns take the first candidate. */
    public static function pick(string $label, string $ptype, string ...$candidates): string
    {
        $candidates = array_values(array_filter(array_map('trim', $candidates), static fn ($c) => $c !== ''));
        $allowed = self::valid($label, $ptype);
        if ($allowed === []) {
            return $candidates[0] ?? '';
        }
        foreach ($candidates as $c) {
            foreach ($allowed as $a) {
                if ($c !== '' && strcasecmp($a, $c) === 0) {
                    return $a;
                }
            }
        }
        return '';
    }

    public static function isBaby(array $r): bool
    {
        return preg_match('/baby|infant|newborn|toddler/i', $r['age_group'] . ' ' . $r['category_path']) === 1;
    }

    public static function isKid(array $r): bool
    {
        return !self::isBaby($r) && preg_match('/kid|child|boys|girls/i', $r['age_group'] . ' ' . $r['category_path']) === 1;
    }

    /** male | female | unisex from target_gender, falling back to the category path. */
    public static function gender(array $r): string
    {
        $g = strtolower($r['target_gender'] . ' ' . $r['category_path']);
        return match (true) {
            preg_match('/\b(unisex)\b/', $g) === 1 => 'unisex',
            preg_match('/\b(female|women|womens|girl|girls)\b/', $g) === 1 => 'female',
            preg_match('/\b(male|men|mens|boy|boys)\b/', $g) === 1 => 'male',
            default => 'unisex',
        };
    }

    public static function productType(array $r): string
    {
        $types = self::schema()['product_types'];
        $explicit = strtoupper(trim($r['attr:amazon_product_type'] ?? ''));
        if ($explicit !== '') {
            return in_array($explicit, $types, true) ? $explicit : '';
        }
        $text = strtolower($r['product_type'] . ' ' . $r['title']);
        $guess = match (true) {
            preg_match('/sweat|hood|sweater|cardigan|pullover/', $text) === 1 => 'SWEATSHIRT',
            preg_match('/night|sleep|pajama|pyjama|robe|nightsuit|night suit/', $text) === 1 => 'NIGHTGOWN_NIGHTSHIRT',
            preg_match('/shirt|tee|\btop\b|polo|vest|tank|blouse|jhabla|tunic/', $text) === 1 => 'SHIRT',
            default => '',
        };
        return in_array($guess, $types, true) ? $guess : '';
    }

    /**
     * Parse a baby or kid size such as "0-3 Months", "6-12 M", "1-2 Years", "Newborn" or "3M".
     *
     * @return array{class: string, from: string, to: string}|null
     */
    public static function ageSize(string $size): ?array
    {
        $s = strtolower(trim($size));
        if ($s === '') {
            return null;
        }
        if (preg_match('/^(nb|new ?born)$/', $s)) {
            return ['class' => 'Age', 'from' => 'Newborn', 'to' => ''];
        }
        if ($s === 'preemie') {
            return ['class' => 'Age', 'from' => 'Preemie', 'to' => ''];
        }
        if (preg_match('/^(\d+)\s*(?:-|to|–)\s*(\d+)\s*(m|mo|mos|month|months|y|yr|yrs|year|years)$/', $s, $m)
            || preg_match('/^(\d+)\s*(m|mo|mos|month|months|y|yr|yrs|year|years)$/', $s, $m)) {
            $range = count($m) === 4;
            $unit = str_starts_with($range ? $m[3] : $m[2], 'm') ? 'Months' : 'Years';
            $fmt = static fn (string $n) => $n . ' ' . $unit;
            return ['class' => 'Age', 'from' => $fmt($m[1]), 'to' => $range ? $fmt($m[2]) : ''];
        }
        return null;
    }

    /** Match an age size value to the template's spelling ("1 Month" vs "1 Months"). */
    private static function sizeValue(string $label, string $ptype, string $value): string
    {
        if ($value === '') {
            return '';
        }
        $alt = preg_replace('/^1 Months$/', '1 Month', $value);
        $alt2 = preg_replace('/^1 Month$/', '1 Months', $value);
        $alt3 = preg_replace('/^1 Years$/', '1 Year', $value);
        return self::pick($label, $ptype, $value, (string) $alt, (string) $alt2, (string) $alt3);
    }

    public static function department(array $r, string $ptype): string
    {
        $g = self::gender($r);
        if (self::isBaby($r)) {
            return self::pick('Department Name', $ptype, ['male' => 'Baby Boys', 'female' => 'Baby Girls', 'unisex' => 'Unisex Baby'][$g]);
        }
        if (self::isKid($r)) {
            return self::pick('Department Name', $ptype, ['male' => 'Boys', 'female' => 'Girls', 'unisex' => 'Unisex Kids'][$g], 'Unisex Child');
        }
        return self::pick('Department Name', $ptype, ['male' => 'Mens', 'female' => 'Womens', 'unisex' => 'Unisex'][$g], 'Unisex Adult');
    }

    public static function colorMap(string $color): string
    {
        foreach (preg_split('/[\s,\/&-]+/', strtolower($color), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $hit = self::pick('Color Map', '*', $word, self::COLOR_SYNONYMS[$word] ?? '');
            if ($hit !== '') {
                return $hit;
            }
        }
        return str_contains(strtolower($color), 'off white') ? 'Off White' : '';
    }

    public static function careInstructions(string $text): string
    {
        $t = strtolower($text);
        return match (true) {
            $t === '' => '',
            str_contains($t, 'dry clean') => 'Dry Clean Only',
            str_contains($t, 'hand') => 'Hand Wash Only',
            str_contains($t, 'machine') => 'Machine Wash',
            default => '',
        };
    }

    public static function variationTheme(string $theme, string $ptype): string
    {
        $fields = array_column(Catalog::options($theme), 'field');
        $size = in_array('size', $fields, true);
        $color = in_array('color', $fields, true);
        return match (true) {
            $size && $color => self::pick('Variation Theme Name', $ptype, 'SIZE/COLOR', 'COLOR/SIZE'),
            $size => self::pick('Variation Theme Name', $ptype, 'SIZE'),
            $color => self::pick('Variation Theme Name', $ptype, 'COLOR'),
            default => '',
        };
    }

    private static function unit(string $label, string $ptype, string $prefix): string
    {
        foreach (self::valid($label, $ptype) as $v) {
            if (stripos($v, $prefix) === 0) {
                return $v;
            }
        }
        return '';
    }

    private static function itemTypeName(array $r, string $ptype): string
    {
        $explicit = self::pick('Item Type Name', $ptype, $r['attr:item_type_name'] ?? '');
        if ($explicit !== '') {
            return $explicit;
        }
        $baby = self::isBaby($r);
        return match ($ptype) {
            'NIGHTGOWN_NIGHTSHIRT' => preg_match('/pajama|pyjama|night ?suit/i', $r['title'] . ' ' . $r['product_type']) === 1
                ? 'Pajama Set' : ($baby ? 'Baby & Toddler Sleepers' : 'Night Shirt'),
            'SWEATSHIRT' => stripos($r['title'], 'hood') !== false ? 'Hooded Sweatshirt' : 'Sweatshirt',
            'SHIRT' => preg_match('/tank|sleeveless|vest|jhabla/i', $r['title'] . ' ' . ($r['attr:sleeve_type'] ?? '')) && $baby
                ? 'Baby & Toddler Tank Top' : 'T-Shirt',
            default => '',
        };
    }

    /**
     * @param string $kind simple | parent | child
     * @return array{values: array<string,string>, problems: list<array{field: string, level: string, message: string}>}
     */
    public static function map(array $r, string $kind, ?array $parent = null): array
    {
        $v = [];
        $problems = [];
        $need = static function (string $field, string $message, string $level = 'error') use (&$problems): void {
            $problems[] = ['field' => $field, 'level' => $level, 'message' => $message];
        };
        $set = static function (string $key, string $value) use (&$v): void {
            if ($value !== '') {
                $v[$key] = $value;
            }
        };

        $ptype = self::productType($r);
        if ($ptype === '') {
            $need('product_type', 'Amazon product type could not be worked out. Use T-Shirt, Sweatshirt or Night Suit in product_type, or set attr:amazon_product_type to SHIRT, SWEATSHIRT or NIGHTGOWN_NIGHTSHIRT.');
        }
        $baby = self::isBaby($r);

        $set('contribution_sku#1.value', $r['sku']);
        $set('product_type#1.value', $ptype);
        $set('::record_action', 'Create or Replace (Full Update)');
        if ($kind !== 'simple') {
            $set('parentage_level#1.value', $kind === 'parent' ? 'Parent' : 'Child');
            $theme = self::variationTheme($r['variant_theme'], $ptype);
            if ($theme === '' && $ptype !== '') {
                $need('variant_theme', "Amazon {$ptype} has no variation theme for \"{$r['variant_theme']}\". Use color-size, size or color.");
            }
            $set('variation_theme#1.name', $theme);
        }
        if ($kind === 'child') {
            $set('child_parent_sku_relationship#1.parent_sku', $r['parent_sku']);
        }

        // Product identity and details (parent and child both carry them).
        $set('item_name#1.value', $r['title']);
        $set('brand#1.value', $r['brand']);
        $set('model_number#1.value', $r['model_number']);
        $set('model_name#1.value', $parent['title'] ?? $r['title']);
        $set('style#1.value', $parent['title'] ?? $r['title']);
        $set('part_number#1.value', $r['model_number'] !== '' ? $r['model_number'] : $r['sku']);
        $set('manufacturer#1.value', $r['manufacturer']);
        $set('product_description#1.value', Catalog::stripHtml($r['description_html']));
        foreach (Columns::BULLETS as $i => $b) {
            $set('bullet_point#' . ($i + 1) . '.value', $r[$b]);
        }
        $set('generic_keyword#1.value', $r['search_keywords']);
        $images = Catalog::images($r);
        $set('main_product_image_locator#1.media_location', $images[0] ?? '');
        foreach (array_slice($images, 1, 8) as $i => $src) {
            $set('other_product_image_locator_' . ($i + 1) . '#1.media_location', $src);
        }

        if ($ptype !== '') {
            $dept = self::department($r, $ptype);
            if ($dept === '') {
                $need('target_gender', 'Could not work out the Amazon department from target_gender and age_group.');
            }
            $set('department#1.value', $dept);
            $set('target_gender#1.value', ['male' => 'Male', 'female' => 'Female', 'unisex' => 'Unisex'][self::gender($r)]);
            $set('age_range_description#1.value', self::pick('Age Range Description', $ptype, $baby ? 'Baby' : (self::isKid($r) ? 'Kid' : 'Adult')));
            if ($baby) {
                $set('special_size_type#1.value', self::pick('Special Size', $ptype,
                    ['male' => 'Baby Boys', 'female' => 'Baby Girls', 'unisex' => 'Baby'][self::gender($r)]));
            }

            $material = self::pick('Material', $ptype, $r['material'], preg_match('/cotton/i', $r['material']) ? 'Cotton' : '');
            if ($r['material'] !== '' && $material === '') {
                $need('material', "Amazon does not accept material \"{$r['material']}\" for {$ptype}. Use a value such as Cotton, Cotton Blend or Polyester.", 'warning');
            }
            $set('material#1.value', $material);
            $set('fabric_type#1.value', ($r['attr:fabric_composition'] ?? '') !== '' ? $r['attr:fabric_composition'] : $r['material']);
            $set('item_type_name#1.value', self::itemTypeName($r, $ptype));
            $set('pattern#1.value', $r['pattern']);
            $set('fit_type#1.value', self::pick('Fit Type', $ptype, $r['attr:fit_type'] ?? ''));
            $care = self::careInstructions($r['attr:care_instructions'] ?? '');
            $set('care_instructions#1.value', $care);
            $neckRaw = $r['attr:neck_type'] ?? '';
            $neck = $neckRaw === '' ? '' : self::pick('Neck Style', $ptype, $neckRaw,
                (string) preg_replace('/^round( neck)?$/i', 'Crew Neck', $neckRaw), "{$neckRaw} Neck");
            if ($neckRaw !== '' && $neck === '') {
                $need('attr:neck_type', "Amazon does not accept neck style \"{$neckRaw}\" for {$ptype}. Try Crew Neck, V-Neck or Henley Neck.", 'warning');
            }
            $set('neck#1.neck_style#1.value', $neck);
            $sleeve = $r['attr:sleeve_type'] ?? '';
            $set('sleeve#1.length_description#1.value', self::pick('Sleeve Length Description', $ptype, $sleeve,
                preg_replace('/^full sleeve$/i', 'Long Sleeve', $sleeve) ?? ''));
            $set('sleeve#1.type#1.value', self::pick('Sleeve Type', $ptype, $sleeve));
            $set('closure#1.type#1.value', self::pick('Closure Type', $ptype, $r['attr:closure_type'] ?? ''));
            if ($ptype === 'SHIRT') {
                $set('compliance_age_range#1.value', $baby ? 'Baby/Infant'
                    : self::pick('Compliance - Age Range', $ptype, ['male' => self::isKid($r) ? 'Boy' : 'Men', 'female' => self::isKid($r) ? 'Girl' : 'Women', 'unisex' => ''][self::gender($r)]));
                $set('compliance_weave_type#1.value', self::pick('Compliance Weave Type', $ptype, $r['attr:weave_type'] ?? ''));
                $set('compliance_shirt_type#1.value', self::pick('Compliance - Shirt Type', $ptype, $r['attr:compliance_shirt_type'] ?? '', 'T-Shirt'));
            }
        }
        $set('country_of_origin#1.value', $r['country_of_origin']);
        $set('supplier_declared_dg_hz_regulation#1.value', 'Not Applicable');
        $set('rtip_manufacturer_contact_information#1.value', Catalog::contact($r['manufacturer'], $r['manufacturer_address']));
        $set('packer_contact_information#1.value', $r['packer_details']);
        $set('importer_contact_information#1.value', $r['importer_details']);
        if ($r['hsn_code'] !== '') {
            $set('external_product_information#1.entity', 'HSN Code');
            $set('external_product_information#1.value', $r['hsn_code']);
        }

        if ($kind === 'parent') {
            return ['values' => $v, 'problems' => $problems];
        }

        // Size, colour, identifiers, offer and shipping: sellable rows only.
        $color = $r['color'];
        $set('color#1.value', $color);
        $map = self::colorMap($color);
        if ($color !== '' && $map === '') {
            $need('color', "Could not match colour \"{$color}\" to an Amazon colour family. Name a basic colour in it (for example \"Sky Blue\").", 'warning');
        }
        $set('color#1.standardized_values#1', $map);
        $set('size#1.value', $r['size']);
        if ($ptype !== '') {
            $prefix = $ptype === 'SHIRT' ? 'shirt_size' : 'apparel_size';
            $label = $ptype === 'SHIRT' ? 'Shirt Size' : 'Apparel Size';
            $age = self::ageSize($r['size']);
            if ($age !== null) {
                $from = self::sizeValue("{$label} Value", $ptype, $age['from']);
                $to = self::sizeValue($ptype === 'SHIRT' ? 'Shirt Size To Range' : 'Apparel Size To Value', $ptype, $age['to']);
                if ($from === '') {
                    $need('size', "Amazon has no age size \"{$age['from']}\" for {$ptype}.");
                }
                $set("{$prefix}#1.size_system", 'IN');
                $set("{$prefix}#1.size_class", 'Age');
                $set("{$prefix}#1.size", $from);
                $set("{$prefix}#1.size_to", $to);
                $set("{$prefix}#1.body_type", 'Regular');
            } elseif ($baby && $r['size'] !== '') {
                $need('size', "Baby sizes must be ages, such as \"0-3 Months\", \"6-12 Months\" or \"1-2 Years\". Got \"{$r['size']}\".");
            } elseif ($r['size'] !== '') {
                $alpha = self::sizeValue("{$label} Value", $ptype, $r['size']);
                $set("{$prefix}#1.size_system", 'IN');
                $set("{$prefix}#1.size_class", $alpha !== '' ? (is_numeric($r['size']) ? 'Numeric' : 'Alpha') : '');
                $set("{$prefix}#1.size", $alpha);
                $set("{$prefix}#1.body_type", 'Regular');
            }
        }

        if ($r['gtin'] !== '') {
            $set('amzn1.volt.ca.product_id_type', self::pick('Product Id Type', '*', $r['gtin_type'] ?: 'EAN', 'EAN'));
            $set('amzn1.volt.ca.product_id_value', $r['gtin']);
        } else {
            $set('amzn1.volt.ca.product_id_type', 'GTIN Exempt');
            $need('gtin', 'No barcode, so the row is marked GTIN Exempt. Your brand needs an approved GTIN exemption on Amazon.', 'warning');
        }
        $set('number_of_items#1.value', $r['net_quantity'] !== '' ? $r['net_quantity'] : '1');
        $set('unit_count#1.value', $r['net_quantity'] !== '' ? $r['net_quantity'] : '1');
        $set('unit_count#1.type.value', 'count');
        $set('condition_type#1.value', 'New');
        $rate = $r['gst_rate_pct'] !== '' && is_numeric($r['gst_rate_pct']) ? (string) (0 + $r['gst_rate_pct']) : '';
        $set('product_tax_code#1.value', self::TAX_CODES[$rate] ?? '');
        $set('fulfillment_availability#1.fulfillment_channel_code', 'Fulfillment by Merchant (Default)');
        $set('fulfillment_availability#1.quantity', $r['quantity']);
        $set('fulfillment_availability#1.lead_time_to_ship_max_days', $r['dispatch_days']);
        $set('purchasable_offer[audience=ALL]#1.our_price#1.schedule#1.value_with_tax', $r['selling_price_inr']);
        $set('purchasable_offer[audience=ALL]#1.maximum_retail_price#1.schedule#1.value_with_tax', $r['mrp_inr']);

        $cm = $ptype !== '' ? self::unit('Package Length Unit', $ptype, 'Centimet') : 'Centimetres';
        if ($r['package_length_cm'] !== '') {
            $set('item_package_dimensions#1.length.value', $r['package_length_cm']);
            $set('item_package_dimensions#1.length.unit', $cm);
            $set('item_package_dimensions#1.width.value', $r['package_width_cm']);
            $set('item_package_dimensions#1.width.unit', $cm);
            $set('item_package_dimensions#1.height.value', $r['package_height_cm']);
            $set('item_package_dimensions#1.height.unit', $cm);
        }
        if ($r['package_weight_g'] !== '') {
            $set('item_package_weight#1.value', $r['package_weight_g']);
            $set('item_package_weight#1.unit', 'Grams');
        }
        if ($r['weight_g'] !== '') {
            $set('item_weight#1.value', $r['weight_g']);
            $set('item_weight#1.unit', 'Grams');
        }
        foreach (['length' => 'length_cm', 'width' => 'width_cm', 'height' => 'height_cm'] as $dim => $col) {
            if ($r[$col] !== '') {
                $set("item_dimensions#1.{$dim}.value", $r[$col]);
                $set("item_dimensions#1.{$dim}.unit", 'Centimetres');
            }
        }

        return ['values' => $v, 'problems' => $problems];
    }
}
