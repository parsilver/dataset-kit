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

    /**
     * What is stored under each key, without its body.
     *
     * @param list<string> $keys
     * @param int $concurrency how many requests may be in flight at once
     * @return array<string, array{etag: string, size: int}|null> null for an absent key
     */
    public function headAll(array $keys, int $concurrency): array;

    /**
     * Create-only uploads (If-None-Match: *) of many objects, up to
     * $concurrency at once. A transient failure (no answer, 408, 429, 5xx) is
     * retried with backoff; any other failure stops the batch.
     *
     * @param list<array{key: string, body: \Closure(): string, headers: array<string, string>}> $items
     *        the body is read when the upload starts, so a big batch never holds every body at once
     * @return array<string, bool> key => true when written, false when it already existed (HTTP 412)
     * @throws PublishFailed when an object cannot be written
     */
    public function putAll(array $items, int $concurrency): array;
}
