<?php

declare(strict_types=1);

namespace Farzai\DatasetKit\Publish;

interface Bucket
{
    /**
     * Writes an object, conditionally.
     *
     * @param array<string, string> $headers Content-Type, Cache-Control
     * @param string|null $ifMatch "*" for If-None-Match: * (create only), an ETag for If-Match, null for unconditional
     * @return bool true when written, false when the condition failed (HTTP 412)
     */
    public function put(string $key, string $body, array $headers, ?string $ifMatch): bool;

    /** @return array{body: string, etag: string}|null null when absent */
    public function get(string $key): ?array;
}
