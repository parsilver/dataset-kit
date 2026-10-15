<?php

declare(strict_types=1);

namespace Farzai\DatasetKit\Publish;

/**
 * A bucket in a local directory, for --dry-run: the same keys, the same
 * conditional writes, and a plan.jsonl beside the tree listing every request
 * in order with its headers.
 */
final class DirectoryBucket implements Bucket
{
    /**
     * @param array<string, string> $seed key => body already in the bucket
     *                                    before the run, the way the real
     *                                    bucket holds what was published. Not
     *                                    written to plan.jsonl.
     */
    public function __construct(private readonly string $root, array $seed = [])
    {
        is_dir($root) || mkdir($root, 0o755, true);
        foreach ($seed as $key => $body) {
            $path = "{$root}/{$key}";
            is_dir(dirname($path)) || mkdir(dirname($path), 0o755, true);
            is_file($path) || file_put_contents($path, $body);
        }
    }

    public function put(string $key, string $body, array $headers, ?string $ifMatch): bool
    {
        $path = "{$this->root}/{$key}";
        $exists = is_file($path);
        $ok = match (true) {
            $ifMatch === '*' => !$exists,
            $ifMatch !== null => $exists && '"' . md5((string) file_get_contents($path)) . '"' === $ifMatch,
            default => true,
        };
        file_put_contents("{$this->root}/plan.jsonl", json_encode(['key' => $key, 'condition' => $ifMatch, 'written' => $ok, 'headers' => $headers], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
        if ($ok) {
            is_dir(dirname($path)) || mkdir(dirname($path), 0o755, true);
            file_put_contents($path, $body);
        }

        return $ok;
    }

    public function get(string $key): ?array
    {
        $path = "{$this->root}/{$key}";
        if (!is_file($path)) {
            return null;
        }
        $body = (string) file_get_contents($path);

        return ['body' => $body, 'etag' => '"' . md5($body) . '"'];
    }
}
