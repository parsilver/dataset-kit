<?php

declare(strict_types=1);

use Farzai\DatasetKit\Gate\Failure;
use Farzai\DatasetKit\Publish\DirectoryBucket;
use Farzai\DatasetKit\Publish\PublishFailed;
use Farzai\DatasetKit\Publish\Publisher;
use Farzai\DatasetKit\Publish\Purger;

require_once dirname(__DIR__) . '/Support/build.php';

beforeEach(function () {
    $this->out = tmpDir('build');
    $this->bucketDir = tmpDir('bucket');
    $this->logged = [];
    $this->purger = new class implements Purger {
        public array $urls = [];

        public function purge(array $urls): void
        {
            $this->urls = [...$this->urls, ...$urls];
        }
    };
    $this->publisher = fn (?Farzai\DatasetKit\Publish\Bucket $bucket = null) => new Publisher(
        demoLayout(),
        $bucket ?? new DirectoryBucket($this->bucketDir),
        $this->purger,
        'https://data.example',
        function (string $line) {
            $this->logged[] = $line;
        },
        fn () => new DateTimeImmutable('2026-10-15T01:02:03Z'),
    );
    $this->plan = fn () => array_map(fn (string $l) => json_decode($l, true), file("{$this->bucketDir}/plan.jsonl", FILE_IGNORE_NEW_LINES));
});

afterEach(function () {
    exec('rm -rf ' . escapeshellarg($this->out) . ' ' . escapeshellarg($this->bucketDir));
});

test('uploads the ordered files, then the derived ones sorted, the manifest, snapshots.json, latest.json, then purges', function () {
    demoBuild($this->out);
    ($this->publisher)()->publish($this->out, '2026-10-14');

    $keys = array_column(($this->plan)(), 'key');
    $this->assertSame([
        'demo/v1/2026-10-14/items.json',
        'demo/v1/2026-10-14/notes.csv',
        'demo/v1/2026-10-14/schema.json',
        'demo/v1/2026-10-14/by/a.json',
        'demo/v1/2026-10-14/by/x.json',
        'demo/v1/2026-10-14/manifest.json',
        'demo/v1/snapshots.json',
        'demo/v1/latest.json',
    ], $keys);
    $this->assertSame(['https://data.example/demo/v1/latest.json', 'https://data.example/demo/v1/snapshots.json'], $this->purger->urls);
});

test('snapshot files are create-only and immutable, the pointers are not', function () {
    demoBuild($this->out);
    ($this->publisher)()->publish($this->out, '2026-10-14');

    $plan = array_column(($this->plan)(), null, 'key');
    $this->assertSame('*', $plan['demo/v1/2026-10-14/items.json']['condition']);
    $this->assertSame('public, max-age=31536000, immutable', $plan['demo/v1/2026-10-14/items.json']['headers']['Cache-Control']);
    $this->assertSame('text/csv; charset=utf-8', $plan['demo/v1/2026-10-14/notes.csv']['headers']['Content-Type']);
    $this->assertSame('*', $plan['demo/v1/snapshots.json']['condition'], 'the first index is create-only');
    $this->assertSame('public, max-age=300', $plan['demo/v1/snapshots.json']['headers']['Cache-Control']);
    $this->assertNull($plan['demo/v1/latest.json']['condition']);
});

test('published_at is stamped when the snapshot becomes reachable', function () {
    demoBuild($this->out);
    ($this->publisher)()->publish($this->out, '2026-10-14');

    $index = json_decode((string) file_get_contents("{$this->bucketDir}/demo/v1/snapshots.json"), true);
    $this->assertSame('2026-10-15T01:02:03Z', $index['snapshots'][0]['published_at']);
});

test('a rerun of the same build resumes: identical objects are skipped, the index still moves', function () {
    demoBuild($this->out);
    $first = new DirectoryBucket($this->bucketDir);
    // The run dies after the manifest: pre-load the snapshot's files only.
    $dir = "{$this->out}/demo/v1/2026-10-14";
    foreach (['items.json', 'notes.csv'] as $f) {
        $first->put("demo/v1/2026-10-14/{$f}", (string) file_get_contents("{$dir}/{$f}"), [], '*');
    }
    ($this->publisher)()->publish($this->out, '2026-10-14');

    $this->assertContains('already there, identical: demo/v1/2026-10-14/items.json', $this->logged);
    $this->assertFileExists("{$this->bucketDir}/demo/v1/latest.json");
});

test('an object already in the bucket with other bytes stops the publish and nothing points at it', function () {
    demoBuild($this->out);
    (new DirectoryBucket($this->bucketDir))->put('demo/v1/2026-10-14/items.json', 'other', [], '*');

    expect(fn () => ($this->publisher)()->publish($this->out, '2026-10-14'))->toThrow(PublishFailed::class, 'already exists with other content');
    $this->assertFileDoesNotExist("{$this->bucketDir}/demo/v1/latest.json");
    $this->assertFileDoesNotExist("{$this->bucketDir}/demo/v1/snapshots.json");
});

test('a bucket whose snapshots.json is not the one the build started from stops the publish before any upload', function () {
    demoBuild($this->out);
    $bucket = new DirectoryBucket($this->bucketDir, ['demo/v1/snapshots.json' => json_encode(['schema_version' => '1.0.0', 'snapshots' => [['id' => '2026-09-01', 'status' => 'published', 'published_at' => 'x', 'manifest_sha256' => 'y']]])]);

    expect(fn () => ($this->publisher)($bucket)->publish($this->out, '2026-10-14'))->toThrow(PublishFailed::class, 'not the one this build started from');
    $this->assertFileDoesNotExist("{$this->bucketDir}/demo/v1/2026-10-14/items.json");
});

test('a build that did not pass the gates is never uploaded, and a hard failure cannot be approved past', function () {
    demoBuild($this->out, failures: [new Failure('orphans', '3 orphans', overridable: false)]);
    expect(fn () => ($this->publisher)()->publish($this->out, '2026-10-14'))->toThrow(PublishFailed::class, 'did not pass the publish gates');
    $this->assertFileDoesNotExist("{$this->bucketDir}/plan.jsonl");
});

test('a file edited after the gates ran is refused', function () {
    demoBuild($this->out);
    file_put_contents("{$this->out}/demo/v1/2026-10-14/items.json", 'tampered');
    expect(fn () => ($this->publisher)()->publish($this->out, '2026-10-14'))->toThrow(PublishFailed::class, 'changed after the gates ran');
    $this->assertFileDoesNotExist("{$this->bucketDir}/plan.jsonl");
});

test('a manifest edited after the gates ran is refused', function () {
    demoBuild($this->out);
    file_put_contents("{$this->out}/demo/v1/2026-10-14/manifest.json", "{}\n");
    expect(fn () => ($this->publisher)()->publish($this->out, '2026-10-14'))->toThrow(PublishFailed::class, 'manifest.json changed after the gates ran');
});

test('a manifest without a required file is refused', function () {
    demoBuild($this->out);
    $path = "{$this->out}/demo/v1/2026-10-14/manifest.json";
    $manifest = json_decode((string) file_get_contents($path), true);
    unset($manifest['files']['items.json']);
    file_put_contents($path, json_encode($manifest));
    $report = "{$this->out}/gate-2026-10-14.json";
    $r = json_decode((string) file_get_contents($report), true);
    $r['manifest_sha256'] = hash('sha256', (string) file_get_contents($path));
    file_put_contents($report, json_encode($r));

    expect(fn () => ($this->publisher)()->publish($this->out, '2026-10-14'))->toThrow(PublishFailed::class, 'items.json is not in the manifest');
});

test('a snapshot id that is not a date is refused before anything is read', function () {
    expect(fn () => ($this->publisher)()->publish($this->out, '../etc'))->toThrow(PublishFailed::class, 'bad snapshot id');
});

test('the docs go up under the dataset folder with a short cache, then are purged', function () {
    ($this->publisher)()->publishDocs(['llms.txt' => 'a', 'index.html' => '<p>b</p>']);

    $plan = array_column(($this->plan)(), null, 'key');
    $this->assertSame('text/plain; charset=utf-8', $plan['demo/llms.txt']['headers']['Content-Type']);
    $this->assertSame('text/html; charset=utf-8', $plan['demo/index.html']['headers']['Content-Type']);
    $this->assertSame('public, max-age=300', $plan['demo/llms.txt']['headers']['Cache-Control']);
    $this->assertSame(['https://data.example/demo/llms.txt', 'https://data.example/demo/index.html'], $this->purger->urls);
});

test('only the layout\'s doc files can be published as docs', function () {
    expect(fn () => ($this->publisher)()->publishDocs(['v1/latest.json' => '{}']))->toThrow(InvalidArgumentException::class);
});

test('an object is uploaded with the content type its manifest entry records', function () {
    demoBuild($this->out);
    $path = "{$this->out}/demo/v1/2026-10-14/manifest.json";
    $manifest = json_decode((string) file_get_contents($path), true);
    $manifest['files']['notes.csv']['content_type'] = 'text/plain; charset=utf-8';
    file_put_contents($path, json_encode($manifest));
    $report = "{$this->out}/gate-2026-10-14.json";
    $r = json_decode((string) file_get_contents($report), true);
    $r['manifest_sha256'] = hash('sha256', (string) file_get_contents($path));
    file_put_contents($report, json_encode($r));

    ($this->publisher)()->publish($this->out, '2026-10-14');

    $plan = array_column(($this->plan)(), null, 'key');
    $this->assertSame('text/plain; charset=utf-8', $plan['demo/v1/2026-10-14/notes.csv']['headers']['Content-Type']);
});

test('a content type outside the allow-list stops the publish before any upload', function (string $type) {
    demoBuild($this->out);
    $path = "{$this->out}/demo/v1/2026-10-14/manifest.json";
    $manifest = json_decode((string) file_get_contents($path), true);
    $manifest['files']['notes.csv']['content_type'] = $type;
    file_put_contents($path, json_encode($manifest));
    $report = "{$this->out}/gate-2026-10-14.json";
    $r = json_decode((string) file_get_contents($report), true);
    $r['manifest_sha256'] = hash('sha256', (string) file_get_contents($path));
    file_put_contents($report, json_encode($r));

    expect(fn () => ($this->publisher)()->publish($this->out, '2026-10-14'))->toThrow(PublishFailed::class, 'content type that is not allowed');
    $this->assertFileDoesNotExist("{$this->bucketDir}/demo/v1/latest.json");
})->with(['text/html', 'text/html; charset=utf-8', "text/plain\r\nSet-Cookie: x=1", 'image/svg+xml', '']);


test('every key the publisher touches is under the layout root, docs and pointers included', function () {
    demoBuild($this->out);
    $spy = new class($this->bucketDir) implements Farzai\DatasetKit\Publish\Bucket {
        public array $keys = [];

        private DirectoryBucket $inner;

        public function __construct(string $dir)
        {
            $this->inner = new DirectoryBucket($dir);
        }

        public function put(string $key, string $body, array $headers, ?string $ifMatch): bool
        {
            $this->keys[] = $key;

            return $this->inner->put($key, $body, $headers, $ifMatch);
        }

        public function get(string $key): ?array
        {
            $this->keys[] = $key;

            return $this->inner->get($key);
        }
    };
    $publisher = ($this->publisher)($spy);
    $publisher->publish($this->out, '2026-10-14');
    $publisher->publishDocs(['llms.txt' => 'x']);

    $this->assertNotSame([], $spy->keys);
    foreach ($spy->keys as $key) {
        $this->assertStringStartsWith('demo/', $key);
    }
});

test('the publisher refuses to write outside its root even if a layout or a manifest tries', function () {
    demoBuild($this->out);
    $bucket = new DirectoryBucket($this->bucketDir);
    $layout = new Farzai\DatasetKit\Layout('demo', '1.0.0', ['items.json'], ['items.json'], ['items.json'], [], 'ua', '../thailand-address');
    $publisher = new Publisher($layout, $bucket, null, 'https://data.example', fn (string $l) => null);

    expect(fn () => $publisher->publish($this->out, '2026-10-14'))->toThrow(PublishFailed::class);
    $this->assertFileDoesNotExist("{$this->bucketDir}/plan.jsonl");
});
