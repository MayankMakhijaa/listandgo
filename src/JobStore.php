<?php

declare(strict_types=1);

namespace ListingTool;

/** Keeps uploaded, parsed rows on disk between the upload and the downloads. Jobs expire after a day. */
final class JobStore
{
    private const TTL = 86400;

    public function __construct(private readonly string $dir)
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}");
        }
    }

    public function save(array $job): string
    {
        $this->purge();
        $id = bin2hex(random_bytes(16));
        file_put_contents($this->path($id), json_encode($job, JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $id;
    }

    public function load(string $id): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1 || !is_file($this->path($id))) {
            return null;
        }
        $data = json_decode((string) file_get_contents($this->path($id)), true);
        return is_array($data) ? $data : null;
    }

    private function path(string $id): string
    {
        return $this->dir . '/' . $id . '.json';
    }

    private function purge(): void
    {
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            if (filemtime($file) < time() - self::TTL) {
                @unlink($file);
            }
        }
    }
}
