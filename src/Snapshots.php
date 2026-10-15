<?php

declare(strict_types=1);

namespace Farzai\DatasetKit;

/**
 * snapshots.json and latest.json: the index of every snapshot ever built and
 * the pointer at the current one. Ids are `YYYY-MM-DD`, with a `.n` suffix for
 * a second build of a day, and are never reused.
 */
final class Snapshots
{
    public static function validId(string $id): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}(\.[1-9]\d*)?$/', $id) === 1;
    }

    /**
     * The id for a new snapshot built on $date: the date, or the next free
     * ".n" when the index already has it (withdrawn ones included; ids are
     * never reused) or it is stranded in the bucket.
     *
     * @param array{snapshots: list<array<string, mixed>>}|null $index
     * @param list<string> $stranded ids with files in the bucket but not in the index
     */
    public static function nextId(string $date, ?array $index, array $stranded = []): string
    {
        $taken = [...array_column($index['snapshots'] ?? [], 'id'), ...$stranded];
        for ($n = 0; ; $n++) {
            $id = $n === 0 ? $date : "{$date}.{$n}";
            if (!in_array($id, $taken, true)) {
                return $id;
            }
        }
    }

    /** @return array{schema_version: string, snapshots: list<array<string, mixed>>} */
    public static function emptyIndex(string $schemaVersion): array
    {
        return ['schema_version' => $schemaVersion, 'snapshots' => []];
    }

    /** The id of the last published snapshot in the index, null before the first. */
    public static function lastPublished(array $index): ?string
    {
        $previous = null;
        foreach ($index['snapshots'] as $entry) {
            if ($entry['status'] === 'published') {
                $previous = $entry['id'];
            }
        }

        return $previous;
    }

    /**
     * The index with this snapshot appended as published. Refuses an id that is
     * already in it.
     *
     * @param array{schema_version: string, snapshots: list<array<string, mixed>>} $index
     * @return array{schema_version: string, snapshots: list<array<string, mixed>>}
     */
    public static function append(array $index, string $id, string $publishedAt, string $manifestSha256): array
    {
        self::validId($id) || throw new \InvalidArgumentException("bad snapshot id {$id}");
        in_array($id, array_column($index['snapshots'], 'id'), true)
            && throw new \RuntimeException("snapshot {$id} already exists in snapshots.json; ids are never reused, use the next .n suffix");
        $index['snapshots'][] = ['id' => $id, 'status' => 'published', 'published_at' => $publishedAt, 'manifest_sha256' => $manifestSha256];

        return $index;
    }

    /** @return array{schema_version: string, snapshot: string, manifest_sha256: string} */
    public static function latest(string $schemaVersion, string $id, string $manifestSha256): array
    {
        return ['schema_version' => $schemaVersion, 'snapshot' => $id, 'manifest_sha256' => $manifestSha256];
    }
}
