<?php

declare(strict_types=1);

namespace ListingTool;

final class Csv
{
    /**
     * Parse CSV text into a list of associative rows keyed by trimmed header.
     * Handles a UTF-8 BOM, quoted multi-line cells and blank lines.
     *
     * @return list<array<string,string>>
     */
    public static function read(string $text): array
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $text);
        rewind($fh);

        $header = null;
        $rows = [];
        while (($cells = fgetcsv($fh, null, ',', '"', '')) !== false) {
            if ($cells === [null] || (count($cells) === 1 && trim((string) $cells[0]) === '')) {
                continue;
            }
            if ($header === null) {
                $header = array_map(static fn ($h) => trim((string) $h), $cells);
                continue;
            }
            $row = [];
            foreach ($header as $i => $name) {
                if ($name === '') {
                    continue;
                }
                $row[$name] = trim((string) ($cells[$i] ?? ''));
            }
            $rows[] = $row;
        }
        fclose($fh);
        return $rows;
    }

    /**
     * Write rows to CSV text with a UTF-8 BOM so Excel shows ₹ and Hindi correctly.
     *
     * @param list<string> $columns
     * @param list<array<string,mixed>> $rows
     */
    public static function write(array $columns, array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $columns, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($fh, array_map(static fn ($c) => (string) ($row[$c] ?? ''), $columns), ',', '"', '');
        }
        rewind($fh);
        $out = stream_get_contents($fh);
        fclose($fh);
        return $out;
    }
}
