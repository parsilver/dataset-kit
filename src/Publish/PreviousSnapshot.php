<?php

declare(strict_types=1);

namespace Farzai\DatasetKit\Publish;

use Farzai\DatasetKit\Layout;
use Farzai\DatasetKit\Snapshots;

/**
 * Reads the published index and the last published snapshot back from the
 * public CDN, so a build anywhere (a GitHub runner included) can diff and gate
 * against what is actually published. Read-only and credential-free.
 */
final class PreviousSnapshot
{
    private readonly string $base;

    /** A build needs the manifest and the layout's `previous` files from the last snapshot. */
    public function __construct(private readonly Layout $layout, string $base)
    {
        Config::secureUrl($base) || throw new \InvalidArgumentException('the CDN base must be https (plain http only for a local test server)');
        $this->base = rtrim($base, '/');
    }

    /**
     * Writes <dir>/snapshots.json and <dir>/<id>/ for the last published
     * snapshot, each file checked against that snapshot's manifest, and the
     * manifest against the sha256 the index records.
     *
     * Also writes <dir>/stranded.json: the ids for $date that have a
     * manifest.json or the layout's first file in the bucket but are not in the
     * index. A publish that died partway leaves one, and a new build of that id
     * would meet other bytes where it must never overwrite, so the build takes
     * the next id. (A rerun of the SAME build does not need a new id: it
     * completes the directory.)
     *
     * @return string|null the id of the last published snapshot, null when nothing is published yet
     */
    public function pull(string $dir, ?string $date = null): ?string
    {
        $indexJson = $this->get($this->layout->prefix() . '/snapshots.json');
        $index = $indexJson === null ? null : json_decode($indexJson, true, flags: JSON_THROW_ON_ERROR);
        is_dir($dir) || mkdir($dir, 0o755, true);
        $stranded = [];
        // An id is taken when its manifest is there (a publish that died before the index) or its
        // claim file is: the Publisher writes the layout's first file alone and first, so a
        // directory with any object has it, and a build that reused the id would meet other bytes.
        $touched = fn (string $id): bool => $this->get($this->layout->prefix() . "/{$id}/manifest.json") !== null
            || $this->get($this->layout->prefix() . "/{$id}/" . $this->layout->order[0]) !== null;
        for ($id = Snapshots::nextId($date ?? gmdate('Y-m-d'), $index); $touched($id); $id = Snapshots::nextId($date ?? gmdate('Y-m-d'), $index, $stranded)) {
            $stranded[] = $id;
        }
        file_put_contents("{$dir}/stranded.json", json_encode($stranded) . "\n");
        if ($indexJson === null) {
            return null;
        }
        $published = array_values(array_filter($index['snapshots'] ?? [], fn (array $e) => ($e['status'] ?? null) === 'published'));
        file_put_contents("{$dir}/snapshots.json", $indexJson);
        if ($published === []) {
            return null;
        }
        $last = end($published);
        $id = (string) $last['id'];
        Snapshots::validId($id) || throw new \RuntimeException("the index names an invalid snapshot id {$id}");

        $files = [];
        foreach (array_values(array_unique(['manifest.json', ...$this->layout->previous])) as $file) {
            $files[$file] = $this->get($this->layout->prefix() . "/{$id}/{$file}") ?? throw new \RuntimeException("{$id}/{$file} is listed as published but is missing from the CDN");
        }
        hash_equals((string) $last['manifest_sha256'], hash('sha256', $files['manifest.json']))
            || throw new \RuntimeException("{$id}/manifest.json does not match the sha256 snapshots.json records");
        $manifest = json_decode($files['manifest.json'], true, flags: JSON_THROW_ON_ERROR);
        foreach ($files as $file => $content) {
            $file === 'manifest.json' || hash_equals((string) $manifest['files'][$file]['sha256'], hash('sha256', $content))
                || throw new \RuntimeException("{$id}/{$file} does not match its manifest");
        }
        is_dir("{$dir}/{$id}") || mkdir("{$dir}/{$id}", 0o755, true);
        foreach ($files as $file => $content) {
            file_put_contents("{$dir}/{$id}/{$file}", $content);
        }

        return $id;
    }

    private function get(string $key): ?string
    {
        $curl = curl_init("{$this->base}/{$key}");
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => ['User-Agent: ' . $this->layout->userAgent]]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($status === 404) {
            return null;
        }
        ($status === 200 && is_string($body)) || throw new \RuntimeException("GET {$this->base}/{$key} answered {$status}");

        return $body;
    }
}
