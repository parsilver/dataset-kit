<?php

declare(strict_types=1);

namespace Farzai\DatasetKit\Publish;

use Farzai\DatasetKit\Gate\GateReport;
use Farzai\DatasetKit\Json;
use Farzai\DatasetKit\Layout;
use Farzai\DatasetKit\Manifest;
use Farzai\DatasetKit\Snapshots;

/**
 * Uploads one gated snapshot (create-only, files then index then pointer).
 *
 * Before anything is sent:
 * - the gate report must have passed for this exact manifest, and every file
 *   must still match the manifest;
 * - the bucket's v1/snapshots.json must still be the index the build started
 *   from, so two runs cannot both claim to be the latest.
 *
 * Then, in this order, and stopping at the first failure:
 * 1. every snapshot file, the manifest last, create-only (If-None-Match: *),
 *    Cache-Control immutable. An object already there with the same bytes is
 *    a resumed run and is skipped; with other bytes it stops the publish;
 * 2. v1/snapshots.json, only if it is still the version read above (If-Match),
 *    with this snapshot's published_at stamped now rather than at build time;
 * 3. v1/latest.json;
 * 4. a cache purge of both, so the short TTL is not even waited out.
 *
 * Nothing is published until step 2, so a failure in step 1 leaves the
 * snapshot unreachable from the index. Rerunning `publish` on the SAME build
 * directory resumes it. A new build of the same id does not: its
 * manifest.json carries a new generated_at, so it stops at "already exists
 * with other content" and the next run has to use the next id.
 */
final class Publisher
{
    public const IMMUTABLE = 'public, max-age=31536000, immutable';

    public const SHORT = 'public, max-age=300';

    private readonly Bucket $bucket;

    public function __construct(
        private readonly Layout $layout,
        Bucket $bucket,
        private readonly ?Purger $purger,
        private readonly string $publicBaseUrl,
        private readonly \Closure $log,
        private readonly ?\Closure $now = null,
        private readonly int $concurrency = 24,
    ) {
        $this->bucket = new ScopedBucket($bucket, $layout->root);
    }

    public function publish(string $out, string $snapshot): void
    {
        Snapshots::validId($snapshot) || throw new PublishFailed("bad snapshot id {$snapshot}");
        $v1 = "{$out}/" . $this->layout->prefix();
        $dir = "{$v1}/{$snapshot}";
        $manifest = $this->checkGated($out, $dir, $snapshot);

        $index = json_decode((string) self::read("{$v1}/snapshots.json"), true);
        $latest = (string) self::read("{$v1}/latest.json");
        is_array($index) && $latest !== '' || throw new PublishFailed('the build has no snapshots.json or latest.json');
        (end($index['snapshots'])['id'] ?? null) === $snapshot || throw new PublishFailed("the build's snapshots.json does not end with {$snapshot}");
        $base = $index;
        array_pop($base['snapshots']);

        $remote = $this->bucket->get($this->layout->prefix() . '/snapshots.json');
        $remoteIndex = $remote === null ? Snapshots::emptyIndex($this->layout->schemaVersion) : json_decode($remote['body'], true);
        ($remoteIndex['snapshots'] ?? null) === $base['snapshots']
            || throw new PublishFailed('the bucket\'s snapshots.json is not the one this build started from; rebuild against the current index');

        // The snapshot's objects, then its manifest, then the index and latest.
        //
        // The first file of the layout's order is the CLAIM: it is written alone and first, so a
        // directory that holds objects always holds it, and PreviousSnapshot can tell a stranded
        // directory from an untouched id by that one probe. The rest go up in parallel, biggest
        // first, and the manifest, snapshots.json and latest.json only after every one of them has
        // been written or verified. An object that already exists must be byte-identical (a rerun
        // of the same build completes a stranded directory) and is never overwritten.
        $derived = array_values(array_diff(array_keys($manifest['files']), $this->layout->order));
        sort($derived, SORT_STRING);
        $files = [...$this->layout->order, ...$derived];
        $prefix = $this->layout->prefix() . "/{$snapshot}/";
        $claim = array_shift($files);
        $fresh = $this->putCreateOnly($prefix . $claim, $this->verified($dir, $claim, $manifest), self::contentType($manifest, $claim));

        usort($files, fn (string $a, string $b) => $manifest['files'][$b]['bytes'] <=> $manifest['files'][$a]['bytes'] ?: strcmp($a, $b));
        $items = [];
        $bodies = [];
        foreach ($files as $file) {
            $items[] = [
                'key' => $prefix . $file,
                'body' => fn () => $this->verified($dir, $file, $manifest),
                'headers' => ['Content-Type' => self::contentType($manifest, $file), 'Cache-Control' => self::IMMUTABLE],
            ];
            $bodies[$prefix . $file] = $file;
        }
        if (!$fresh) {
            // The claim was already there: this directory was started before (a rerun of the same
            // build, or a publish that died). Ask what is stored first, so the bytes of an object
            // that is already there are not uploaded again just to be refused.
            $heads = $this->bucket->headAll(array_column($items, 'key'), $this->concurrency);
            $there = array_keys(array_filter($heads, fn (?array $head) => $head !== null));
            $this->verifyExisting($there, fn (string $key) => $this->verified($dir, $bodies[$key], $manifest), $heads);
            $items = array_values(array_filter($items, fn (array $item) => !in_array($item['key'], $there, true)));
        }
        $written = $this->bucket->putAll($items, $this->concurrency);
        $existing = array_keys(array_filter($written, fn (bool $created) => !$created));
        ($this->log)(sprintf('uploaded %d objects, %d already there', count($written) - count($existing), count($existing) + (isset($there) ? count($there) : 0)));
        // An object that appeared between the check and the upload (two runs at once) is verified too.
        $this->verifyExisting($existing, fn (string $key) => $this->verified($dir, $bodies[$key], $manifest));

        $manifestKey = $prefix . 'manifest.json';
        $this->putCreateOnly($manifestKey, (string) self::read("{$dir}/manifest.json"), self::contentType($manifest, 'manifest.json'));

        // published_at is when the snapshot becomes reachable, which is now:
        // the build may be hours old after an approve-and-rerun.
        $index['snapshots'][array_key_last($index['snapshots'])]['published_at'] = ($this->now ?? fn () => new \DateTimeImmutable('now'))()
            ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $indexJson = Json::pretty($index);
        file_put_contents("{$v1}/snapshots.json", $indexJson);
        $indexKey = $this->layout->prefix() . '/snapshots.json';
        $this->bucket->put($indexKey, $indexJson, ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => self::SHORT], $remote['etag'] ?? '*')
            || throw new PublishFailed('snapshots.json changed in the bucket during this publish; nothing points at the new snapshot yet');
        ($this->log)("updated {$indexKey}");

        $latestKey = $this->layout->prefix() . '/latest.json';
        $this->bucket->put($latestKey, $latest, ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => self::SHORT], null)
            || throw new PublishFailed('latest.json could not be written');
        ($this->log)("updated {$latestKey}");

        if ($this->purger !== null) {
            $this->purger->purge(["{$this->publicBaseUrl}/{$latestKey}", "{$this->publicBaseUrl}/{$indexKey}"]);
            ($this->log)('purged latest.json and snapshots.json from the cache');
        }
    }

    /** The file's bytes, read from the build and checked against the manifest the gates passed. */
    private function verified(string $dir, string $file, array $manifest): string
    {
        $body = (string) file_get_contents("{$dir}/{$file}");
        if ($file !== 'manifest.json' && !hash_equals($manifest['files'][$file]['sha256'], hash('sha256', $body))) {
            throw new PublishFailed("{$file} changed after the gates ran");
        }

        return $body;
    }

    /** @return bool true when written, false when it was already there and identical */
    private function putCreateOnly(string $key, string $body, string $contentType): bool
    {
        if ($this->bucket->put($key, $body, ['Content-Type' => $contentType, 'Cache-Control' => self::IMMUTABLE], '*')) {
            ($this->log)("uploaded {$key}");

            return true;
        }
        $this->verifyExisting([$key], fn () => $body);

        return false;
    }

    /**
     * Objects a create-only upload found already there must be this build's bytes. The ETag of a
     * plain PUT is the body's md5, so size and md5 settle it without downloading; an ETag that is
     * not an md5 is settled by comparing the downloaded body.
     *
     * @param list<string> $keys
     * @param \Closure(string): string $expected
     * @param array<string, array{etag: string, size: int}|null>|null $heads what the bucket was already asked
     */
    private function verifyExisting(array $keys, \Closure $expected, ?array $heads = null): void
    {
        if ($keys === []) {
            return;
        }
        $heads ??= $this->bucket->headAll($keys, $this->concurrency);
        foreach ($keys as $key) {
            $body = $expected($key);
            $head = $heads[$key] ?? null;
            $same = false;
            if ($head !== null && preg_match('/^"?[0-9a-f]{32}"?$/', $head['etag']) === 1) {
                $same = $head['size'] === strlen($body) && trim($head['etag'], '"') === md5($body);
            } elseif ($head !== null) {
                $remote = $this->bucket->get($key);
                $same = $remote !== null && hash_equals(hash('sha256', $body), hash('sha256', $remote['body']));
            }
            $same || throw new PublishFailed("{$key} already exists with other content; a snapshot is never overwritten");
            ($this->log)("already there, identical: {$key}");
        }
    }

    /**
     * The content type the manifest records for a file, which must be one of
     * Manifest::CONTENT_TYPES: it becomes a response header, so an unlisted
     * value (html, or one with a line break) stops the publish.
     *
     * @param array<string, mixed> $manifest
     */
    private static function contentType(array $manifest, string $file): string
    {
        $type = $manifest['files'][$file]['content_type'] ?? Manifest::contentType($file);
        in_array($type, Manifest::CONTENT_TYPES, true) || throw new PublishFailed("{$file} has a content type that is not allowed");

        return $type;
    }

    /** @return array<string, mixed> the manifest, once the gates are known to have passed for it */
    private function checkGated(string $out, string $dir, string $snapshot): array
    {
        $reportJson = self::read(GateReport::path($out, $snapshot));
        $report = is_string($reportJson) ? json_decode($reportJson, true) : null;
        is_array($report) || throw new PublishFailed("no gate report for {$snapshot}; run build first");
        ($report['passed'] ?? false) === true && $report['snapshot'] === $snapshot
            || throw new PublishFailed("{$snapshot} did not pass the publish gates");
        foreach ($report['failures'] ?? [] as $failure) {
            ($failure['overridable'] && $failure['approved']) || throw new PublishFailed("{$snapshot} has an unapproved gate failure: {$failure['check']}");
        }

        $manifestJson = (string) self::read("{$dir}/manifest.json");
        hash_equals((string) $report['manifest_sha256'], hash('sha256', $manifestJson))
            || throw new PublishFailed('manifest.json changed after the gates ran');
        $manifest = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
        foreach ($this->layout->required as $file) {
            isset($manifest['files'][$file]) || throw new PublishFailed("{$file} is not in the manifest");
        }
        foreach ($manifest['files'] as $file => $entry) {
            is_file("{$dir}/{$file}") && hash_equals($entry['sha256'], (string) hash_file('sha256', "{$dir}/{$file}"))
                || throw new PublishFailed("{$file} changed after the gates ran");
        }

        return $manifest;
    }

    private static function read(string $path): ?string
    {
        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    /**
     * Uploads the docs (the layout's doc files) under the dataset's folder.
     * They are not versioned: each upload replaces the last, with a short
     * cache and a purge.
     *
     * @param array<string, string> $files name => content
     */
    public function publishDocs(array $files): void
    {
        $types = $this->layout->docs;
        foreach (array_keys($files) as $name) {
            isset($types[$name]) || throw new \InvalidArgumentException("{$name} is not a doc file; only " . implode(', ', array_keys($types)));
        }
        $urls = [];
        foreach ($types as $name => $type) {
            if (!isset($files[$name])) {
                continue;
            }
            $key = "{$this->layout->root}/{$name}";
            $this->bucket->put($key, $files[$name], ['Content-Type' => $type, 'Cache-Control' => self::SHORT], null)
                || throw new PublishFailed("{$key} could not be written");
            ($this->log)("uploaded {$key}");
            $urls[] = "{$this->publicBaseUrl}/{$key}";
        }
        $this->purger?->purge($urls);
    }
}
