<?php

declare(strict_types=1);

namespace ListingTool;

use ListingTool\Importers\ShopifyImporter;
use ListingTool\Importers\WooCommerceImporter;

/** Reads an uploaded CSV (master template, Shopify export or WooCommerce export) into master rows. */
final class Loader
{
    public static function detect(array $headers): string
    {
        $h = array_flip($headers);
        if (isset($h['sku'], $h['selling_price_inr'])) {
            return 'master';
        }
        if (isset($h['Handle'], $h['Variant SKU'])) {
            return 'shopify';
        }
        if (isset($h['SKU'], $h['Regular price'], $h['Type'])) {
            return 'woocommerce';
        }
        return 'unknown';
    }

    /**
     * @return array{format: string, rows: list<array>}
     * @throws \InvalidArgumentException for an unrecognised file
     */
    public static function load(string $csvText): array
    {
        $records = Csv::read($csvText);
        $format = self::detect($records ? array_keys($records[0]) : []);
        $rows = match ($format) {
            'master' => $records,
            'shopify' => ShopifyImporter::convert($records),
            'woocommerce' => WooCommerceImporter::convert($records),
            default => throw new \InvalidArgumentException(
                'This file was not recognised. Upload the List and Go template, a Shopify product export or a WooCommerce product export.'
            ),
        };
        return ['format' => $format, 'rows' => array_values(array_map([Catalog::class, 'normalise'], $rows))];
    }
}
