<?php

declare(strict_types=1);

$root = dirname(__DIR__);
if (!is_file($root . '/vendor/autoload.php')) {
    http_response_code(500);
    exit('Dependencies are missing. Run "composer install --no-dev" in ' . htmlspecialchars($root) . '.');
}
require $root . '/vendor/autoload.php';

const APP_NAME = 'List and Go';
const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

function jobs(): ListingTool\JobStore
{
    return new ListingTool\JobStore(dirname(__DIR__) . '/storage/jobs');
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
