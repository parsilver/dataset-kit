<?php

declare(strict_types=1);

namespace Farzai\DatasetKit\Publish;

/**
 * A bucket that can only touch keys under one folder.
 *
 * Several datasets share one bucket and one write token, so the token cannot
 * tell them apart: this does. A key outside the dataset's root, or one that
 * could reach outside it ("..", "." or empty segments, a backslash, a control
 * character), is refused before the inner bucket sees it. Every Publisher
 * wraps its bucket in one, with the layout's root.
 */
final class ScopedBucket implements Bucket
{
    public function __construct(private readonly Bucket $inner, private readonly string $root)
    {
        preg_match('/^[a-z0-9][a-z0-9._-]*$/', $root) === 1 && $root !== '.' && $root !== '..' && !str_contains($root, '..')
            || throw new \InvalidArgumentException("the bucket root \"{$root}\" is not one plain folder name");
    }

    public function put(string $key, string $body, array $headers, ?string $ifMatch): bool
    {
        return $this->inner->put($this->checked($key), $body, $headers, $ifMatch);
    }

    public function get(string $key): ?array
    {
        return $this->inner->get($this->checked($key));
    }

    public function headAll(array $keys, int $concurrency): array
    {
        return $this->inner->headAll(array_map($this->checked(...), $keys), $concurrency);
    }

    public function putAll(array $items, int $concurrency): array
    {
        foreach ($items as $item) {
            $this->checked($item['key']);
        }

        return $this->inner->putAll($items, $concurrency);
    }

    private function checked(string $key): string
    {
        $inside = str_starts_with($key, "{$this->root}/")
            && preg_match('/[\x00-\x1f\x7f\\\\]/', $key) !== 1
            && !array_intersect(explode('/', $key), ['', '.', '..']);
        $inside || throw new PublishFailed("refusing {$this->describe($key)}: outside the bucket folder \"{$this->root}/\"");

        return $key;
    }

    private function describe(string $key): string
    {
        return json_encode($key, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: 'a key';
    }
}
