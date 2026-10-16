<?php

declare(strict_types=1);

namespace Farzai\DatasetKit;

/**
 * Where one dataset lives in the bucket and what its snapshots contain. The
 * only thing that differs between the pipelines that publish with this kit.
 */
final class Layout
{
    /**
     * @param string $root the dataset's folder in the bucket, e.g. "thailand-address"
     * @param string $schemaVersion the schema_version every file and the index carry
     * @param list<string> $order the snapshot files the layout names; the FIRST is the claim: uploaded alone before everything else, it marks a snapshot directory as taken (see PreviousSnapshot). Every other file, these and the rest the manifest lists, is uploaded in parallel in no set order, and manifest.json goes after all of them
     * @param list<string> $required the files a snapshot must list in its manifest
     * @param list<string> $previous the files a build needs from the last published snapshot
     * @param array<string, string> $docs the un-versioned doc files and their content types
     */
    public function __construct(
        public readonly string $root,
        public readonly string $schemaVersion,
        public readonly array $order,
        public readonly array $required,
        public readonly array $previous,
        public readonly array $docs,
        public readonly string $userAgent,
        public readonly string $version = 'v1',
    ) {
    }

    /** The bucket key prefix of the versioned tree, e.g. "thailand-address/v1". */
    public function prefix(): string
    {
        return "{$this->root}/{$this->version}";
    }
}
