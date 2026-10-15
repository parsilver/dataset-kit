<?php

declare(strict_types=1);

namespace Farzai\DatasetKit;

/** The `{schema_version, snapshot, data}` envelope every dataset file is wrapped in. */
final class Envelope
{
    /**
     * An envelope with one data row per line: readable, diffable, and still
     * small (Cloudflare compresses JSON on the way out).
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function encode(string $schemaVersion, string $snapshot, array $rows): string
    {
        $head = Json::line(['schema_version' => $schemaVersion, 'snapshot' => $snapshot]);

        return substr($head, 0, -1) . ',"data":[' . ($rows === [] ? '' : "\n" . implode(",\n", array_map(Json::line(...), $rows)) . "\n") . "]}\n";
    }
}
