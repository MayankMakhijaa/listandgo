<?php

declare(strict_types=1);

namespace ListingTool\Exporters;

/** One generated file for a channel. */
final class ExportFile
{
    public function __construct(
        public readonly string $channel,
        public readonly string $filename,
        public readonly string $contentType,
        public readonly string $content,
        public readonly int $rowCount,
    ) {
    }
}
