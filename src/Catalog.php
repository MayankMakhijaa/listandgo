<?php

declare(strict_types=1);

namespace ListingTool;

/** Helpers for working with master rows: normalising, variants and channel selection. */
final class Catalog
{
    /** Keep master columns plus attr:* columns; missing master columns become ''. */
    public static function normalise(array $row): array
    {
        $out = [];
        foreach (Columns::MASTER as $c) {
            $out[$c] = trim((string) ($row[$c] ?? ''));
        }
        foreach ($row as $k => $v) {
            if (Columns::isAttr((string) $k)) {
                $out[$k] = trim((string) $v);
            }
        }
        return $out;
    }

    /** Fill a child's blank fields from its parent. */
    public static function inherit(array $child, ?array $parent): array
    {
        if ($parent === null) {
            return $child;
        }
        foreach ($parent as $k => $v) {
            if ($k === 'sku' || $k === 'parent_sku') {
                continue;
            }
            if (($child[$k] ?? '') === '') {
                $child[$k] = $v;
            }
        }
        return $child;
    }

    /** @return array<string,true> SKUs that other rows name as their parent. */
    public static function parentSkus(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if ($r['parent_sku'] !== '') {
                $out[$r['parent_sku']] = true;
            }
        }
        return $out;
    }

    /** @return array<string,array> rows keyed by SKU (first wins). */
    public static function bySku(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[$r['sku']] ??= $r;
        }
        return $out;
    }

    /**
     * Rows that carry price and stock: simple products and variant children,
     * with children filled in from their parent. Keyed by original row index.
     *
     * @return array<int,array>
     */
    public static function sellable(array $rows): array
    {
        $parents = self::parentSkus($rows);
        $bySku = self::bySku($rows);
        $out = [];
        foreach ($rows as $i => $r) {
            if (isset($parents[$r['sku']])) {
                continue;
            }
            $out[$i] = $r['parent_sku'] !== '' ? self::inherit($r, $bySku[$r['parent_sku']] ?? null) : $r;
        }
        return $out;
    }

    /** A blank list_on_<channel> flag counts as yes. */
    public static function wantsChannel(array $row, string $channel): bool
    {
        $v = trim((string) ($row["list_on_{$channel}"] ?? ''));
        return $v === '' || preg_match('/^(y|yes|true|1)$/i', $v) === 1;
    }

    /**
     * Rows for one channel in file order as ['row' => ..., 'kind' => simple|parent|child, 'parent' => parent row or null].
     * A variant parent is included when at least one of its children is.
     *
     * @return list<array{row: array, kind: string, parent: ?array}>
     */
    public static function forChannel(array $rows, string $channel): array
    {
        $parents = self::parentSkus($rows);
        $bySku = self::bySku($rows);
        $included = [];
        foreach ($rows as $r) {
            if (isset($parents[$r['sku']])) {
                continue;
            }
            $eff = $r['parent_sku'] !== '' ? self::inherit($r, $bySku[$r['parent_sku']] ?? null) : $r;
            if (self::wantsChannel($eff, $channel)) {
                $included[$r['sku']] = true;
                if ($r['parent_sku'] !== '') {
                    $included[$r['parent_sku']] = true;
                }
            }
        }
        $out = [];
        foreach ($rows as $r) {
            if (!isset($included[$r['sku']])) {
                continue;
            }
            if (isset($parents[$r['sku']])) {
                $out[] = ['row' => $r, 'kind' => 'parent', 'parent' => null];
            } elseif ($r['parent_sku'] !== '') {
                $parent = $bySku[$r['parent_sku']] ?? null;
                $out[] = ['row' => self::inherit($r, $parent), 'kind' => 'child', 'parent' => $parent];
            } else {
                $out[] = ['row' => $r, 'kind' => 'simple', 'parent' => null];
            }
        }
        return $out;
    }

    /** @return list<string> image URLs in order. */
    public static function images(array $row): array
    {
        return array_values(array_filter(array_map(static fn ($c) => $row[$c] ?? '', Columns::IMAGES), static fn ($v) => $v !== ''));
    }

    /**
     * Variant options from variant_theme, e.g. "color-size" => [['name'=>'Color','field'=>'color'], ...].
     *
     * @return list<array{name: string, field: string}>
     */
    public static function options(string $theme): array
    {
        $out = [];
        foreach (preg_split('/[-_,\/ ]+/', strtolower($theme), -1, PREG_SPLIT_NO_EMPTY) as $t) {
            $field = $t === 'colour' ? 'color' : $t;
            $out[] = ['name' => ucfirst($field), 'field' => $field];
        }
        return $out;
    }

    /** Value of a variant option: a master column, else an attr:<name> column. */
    public static function optionValue(array $row, string $field): string
    {
        return (string) ($row[$field] ?? $row["attr:{$field}"] ?? '');
    }

    /** @return list<string> all attr:* columns used across rows. */
    public static function attrColumns(array $rows): array
    {
        $cols = [];
        foreach ($rows as $r) {
            foreach (array_keys($r) as $k) {
                if (Columns::isAttr((string) $k)) {
                    $cols[$k] = true;
                }
            }
        }
        return array_keys($cols);
    }

    /** "Name, address" for Legal Metrology fields, without repeating the name when the address already starts with it. */
    public static function contact(string $name, string $address): string
    {
        if ($address === '') {
            return $name;
        }
        if ($name === '' || stripos($address, $name) === 0) {
            return $address;
        }
        return "{$name}, {$address}";
    }

    public static function stripHtml(string $html): string
    {
        $t = preg_replace(['/<br\s*\/?>/i', '/<\/(p|li|div)>/i'], "\n", $html);
        $t = html_entity_decode(strip_tags((string) $t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace("/\n{3,}/", "\n\n", $t));
    }

    public static function gramsToKg(string $g): string
    {
        return $g === '' || !is_numeric($g) ? '' : rtrim(rtrim(number_format((float) $g / 1000, 3, '.', ''), '0'), '.');
    }
}
