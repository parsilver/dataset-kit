<?php

declare(strict_types=1);

namespace Farzai\DatasetKit\Publish;

interface Purger
{
    /** @param list<string> $urls */
    public function purge(array $urls): void;
}
