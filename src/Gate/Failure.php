<?php

declare(strict_types=1);

namespace Farzai\DatasetKit\Gate;

/** One reason a snapshot must not be published. */
final class Failure
{
    public function __construct(
        public readonly string $check,
        public readonly string $message,
        /** Whether the owner may approve publishing anyway (a threshold), or never (a hard gate). */
        public readonly bool $overridable,
    ) {
    }
}
