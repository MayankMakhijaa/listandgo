<?php

declare(strict_types=1);

namespace ListingTool;

use ListingTool\Exporters\AmazonExporter;
use ListingTool\Exporters\ExportFile;
use ListingTool\Exporters\FlipkartExporter;
use ListingTool\Exporters\ShopifyExporter;
use ListingTool\Exporters\WooCommerceExporter;

/** Entry point: load a CSV, validate it and build files for the chosen channels. */
final class ListAndGo
{
    /** @return array{format: string, rows: list<array>, issues: list<array>, summary: array} */
    public static function check(string $csvText, array $channels = Columns::CHANNELS): array
    {
        $loaded = Loader::load($csvText);
        $issues = Validator::validate($loaded['rows'], $channels);
        return $loaded + ['issues' => $issues, 'summary' => Validator::summarise($issues)];
    }

    public static function export(array $rows, string $channel): ExportFile
    {
        return match ($channel) {
            'amazon' => AmazonExporter::export($rows),
            'flipkart' => FlipkartExporter::export($rows),
            'shopify' => ShopifyExporter::export($rows),
            'woocommerce' => WooCommerceExporter::export($rows),
            default => throw new \InvalidArgumentException("Unknown channel {$channel}"),
        };
    }
}
