<?php

declare(strict_types=1);

namespace Farzai\DatasetKit;

/** The two JSON encodings every dataset file uses. */
final class Json
{
    /** One line: an envelope row, a plan entry. */
    public static function line(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** Pretty-printed with a trailing newline: manifests, indexes, pointers, reports. */
    public static function pretty(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }
}
