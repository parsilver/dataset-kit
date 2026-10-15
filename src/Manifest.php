<?php

declare(strict_types=1);

namespace Farzai\DatasetKit;

/** The pieces of manifest.json that are the same for every dataset. */
final class Manifest
{
    /** @return array{sha256: string, bytes: int, content_type: string} */
    public static function entry(string $name, string $content): array
    {
        return ['sha256' => hash('sha256', $content), 'bytes' => strlen($content), 'content_type' => self::contentType($name)];
    }

    /** The content type a file is published with when its manifest entry does not say. */
    public static function contentType(string $name): string
    {
        return match (true) {
            str_ends_with($name, '.csv') => 'text/csv; charset=utf-8',
            str_ends_with($name, '.gz') => 'application/gzip',
            str_ends_with($name, '.sqlite') => 'application/vnd.sqlite3',
            default => 'application/json; charset=utf-8',
        };
    }

    /** UTC, second precision, the way every manifest and index stamps time. */
    public static function timestamp(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
