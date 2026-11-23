<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use ListingTool\Columns;
use ListingTool\ListAndGo;

// Blank and sample templates.
$templates = ['blank' => 'listing_template_blank.csv', 'sample' => 'listing_template_sample.csv'];
if (isset($_GET['template'])) {
    $name = $templates[$_GET['template']] ?? null;
    if ($name === null) {
        http_response_code(404);
        exit('Not found');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="listandgo_' . $name . '"');
    readfile(dirname(__DIR__) . '/templates/' . $name);
    exit;
}

$job = jobs()->load((string) ($_GET['job'] ?? ''));
$channel = (string) ($_GET['channel'] ?? '');

// The uploaded products as a List and Go template, so a Shopify or WooCommerce export can be completed and re-uploaded.
if ($job !== null && $channel === 'master') {
    $columns = [...Columns::MASTER, ...ListingTool\Catalog::attrColumns($job['rows'])];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="listandgo_products.csv"');
    echo ListingTool\Csv::write($columns, $job['rows']);
    exit;
}

if ($job === null || !in_array($channel, $job['channels'] ?? [], true)) {
    http_response_code(404);
    exit('This download has expired. Upload your file again.');
}
$blocked = array_filter($job['issues'], static fn ($i) => $i['level'] === 'error' && ($i['channel'] === null || $i['channel'] === $channel));
if ($blocked) {
    http_response_code(409);
    exit('Fix the errors for ' . Columns::CHANNEL_LABELS[$channel] . ' first, then upload again.');
}

$file = ListAndGo::export($job['rows'], $channel);
header('Content-Type: ' . $file->contentType);
header('Content-Disposition: attachment; filename="' . $file->filename . '"');
header('Content-Length: ' . strlen($file->content));
echo $file->content;
