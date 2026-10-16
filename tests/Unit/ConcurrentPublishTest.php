<?php

declare(strict_types=1);

use Farzai\DatasetKit\Publish\Config;
use Farzai\DatasetKit\Publish\PublishFailed;
use Farzai\DatasetKit\Publish\Publisher;
use Farzai\DatasetKit\Publish\S3Bucket;

require_once dirname(__DIR__) . '/Support/build.php';

/*
 * The first real publish of a 7,152-object snapshot ran one PUT at a time,
 * about 105 a minute, and hit the job's timeout with 6,000 objects uploaded.
 * These tests run the real S3Bucket against a local S3 fake (tests/Support/
 * s3fake.php) that has latency, transient faults and a request log.
 */
beforeEach(function () {
    $this->out = tmpDir('cp-build');
    $this->s3 = tmpDir('cp-s3');
    mkdir("{$this->s3}/objects", 0o755, true);
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name = (string) stream_socket_get_name($socket, false);
    $this->port = (int) substr($name, strrpos($name, ':') + 1);
    fclose($socket);
    $this->latencyMs = 0;
    $this->start = function (int $latencyMs = 0): void {
        $this->latencyMs = $latencyMs;
        $this->server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$this->port}", dirname(__DIR__) . '/Support/s3fake.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['S3FAKE_DIR' => $this->s3, 'S3FAKE_LATENCY_MS' => (string) $latencyMs, 'PHP_CLI_SERVER_WORKERS' => '24', 'PATH' => (string) getenv('PATH')],
        );
        for ($i = 0; $i < 200; $i++) {
            $probe = curl_init("http://127.0.0.1:{$this->port}/");
            curl_setopt_array($probe, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 200]);
            if (curl_exec($probe) !== false) {
                break;
            }
            usleep(20_000);
        }
        @unlink("{$this->s3}/log.jsonl");
    };
    $this->bucket = fn () => new S3Bucket(Config::fromEnv([
        'R2_ENDPOINT' => "http://127.0.0.1:{$this->port}",
        'R2_BUCKET' => 'testbucket',
        'R2_ACCESS_KEY_ID' => 'AKIDEXAMPLE',
        'R2_SECRET_ACCESS_KEY' => 'secret-for-the-fake',
        'CLOUDFLARE_ZONE_ID' => 'zone',
        'CLOUDFLARE_PURGE_TOKEN' => 'token',
    ]), retryBaseSeconds: 0.01);
    $this->publisher = fn (int $concurrency = 16) => new Publisher(
        demoLayout(),
        ($this->bucket)(),
        null,
        'https://data.example',
        function (string $line) {
        },
        fn () => new DateTimeImmutable('2026-10-15T01:02:03Z'),
        $concurrency,
    );
    $this->log = fn () => array_map(fn (string $l) => json_decode($l, true), file("{$this->s3}/log.jsonl", FILE_IGNORE_NEW_LINES) ?: []);
    $this->faults = function (array $faults): void {
        file_put_contents("{$this->s3}/faults.json", json_encode($faults));
    };
    $this->object = fn (string $key) => is_file("{$this->s3}/objects/{$key}") ? file_get_contents("{$this->s3}/objects/{$key}") : null;
});

afterEach(function () {
    if (isset($this->server)) {
        proc_terminate($this->server);
        proc_close($this->server);
    }
    exec('rm -rf ' . escapeshellarg($this->out) . ' ' . escapeshellarg($this->s3));
});

const SNAP = 'demo/v1/2026-10-14/';

test('uploads many objects at once, and much faster than one at a time', function () {
    demoBuild($this->out, extraFiles: 120);
    ($this->start)(40);

    $t = microtime(true);
    ($this->publisher)(16)->publish($this->out, '2026-10-14');
    $elapsed = microtime(true) - $t;

    // 125 files + manifest + index + latest at 40 ms each, one at a time, is over 5 s.
    $this->assertLessThan(2.5, $elapsed, 'the snapshot should upload in parallel');
    $puts = array_values(array_filter(($this->log)(), fn (array $r) => $r['method'] === 'PUT'));
    $peak = 0;
    foreach ($puts as $a) {
        $peak = max($peak, count(array_filter($puts, fn (array $b) => $b['t0'] <= $a['t0'] && $b['t1'] > $a['t0'])));
    }
    $this->assertGreaterThanOrEqual(8, $peak, 'requests overlapped');
    $this->assertLessThanOrEqual(16 + 1, $peak, 'never more than the concurrency asked for');
    $this->assertNotNull(($this->object)('demo/v1/latest.json'));
});

test('the first ordered file is claimed before the rest, and the manifest, index and latest go last, after every object', function () {
    demoBuild($this->out, extraFiles: 40);
    ($this->start)(20);

    ($this->publisher)(16)->publish($this->out, '2026-10-14');

    $puts = array_values(array_filter(($this->log)(), fn (array $r) => $r['method'] === 'PUT' && $r['status'] === 200));
    $byKey = array_column($puts, null, 'key');
    $claim = $byKey[SNAP . 'items.json'];
    foreach ($puts as $r) {
        if ($r['key'] !== SNAP . 'items.json') {
            $this->assertGreaterThanOrEqual($claim['t1'], $r['t0'], "{$r['key']} started before the claim finished");
        }
    }
    $tail = [SNAP . 'manifest.json', 'demo/v1/snapshots.json', 'demo/v1/latest.json'];
    $lastObject = max(array_map(fn (array $r) => $r['t1'], array_filter($puts, fn (array $r) => !in_array($r['key'], $tail, true))));
    $this->assertGreaterThanOrEqual($lastObject, $byKey[SNAP . 'manifest.json']['t0']);
    $this->assertGreaterThanOrEqual($byKey[SNAP . 'manifest.json']['t1'], $byKey['demo/v1/snapshots.json']['t0']);
    $this->assertGreaterThanOrEqual($byKey['demo/v1/snapshots.json']['t1'], $byKey['demo/v1/latest.json']['t0']);
});

test('a transient failure is retried and the publish still completes', function () {
    demoBuild($this->out, extraFiles: 20);
    ($this->start)();
    ($this->faults)([SNAP . 'by/f003.json' => [503, 2], SNAP . 'by/f010.json' => [429, 1]]);

    ($this->publisher)()->publish($this->out, '2026-10-14');

    $tries = array_filter(($this->log)(), fn (array $r) => $r['key'] === SNAP . 'by/f003.json');
    $this->assertCount(3, $tries);
    $this->assertSame(Farzai\DatasetKit\Manifest::entry('by/f003.json', ($this->object)(SNAP . 'by/f003.json'))['sha256'], json_decode(($this->object)(SNAP . 'manifest.json'), true)['files']['by/f003.json']['sha256']);
    $this->assertNotNull(($this->object)('demo/v1/latest.json'));
});

test('an object that cannot be written stops the publish before the manifest, the index and latest', function () {
    demoBuild($this->out, extraFiles: 20);
    ($this->start)();
    ($this->faults)([SNAP . 'by/f005.json' => [403, 100]]);

    expect(fn () => ($this->publisher)()->publish($this->out, '2026-10-14'))->toThrow(PublishFailed::class, 'f005');

    $this->assertNull(($this->object)(SNAP . 'manifest.json'));
    $this->assertNull(($this->object)('demo/v1/snapshots.json'));
    $this->assertNull(($this->object)('demo/v1/latest.json'));
});

test('a rerun of the same build completes a stranded directory and uploads only what is missing', function () {
    demoBuild($this->out, extraFiles: 30);
    ($this->start)();
    // A first run that died: the claim and half the objects are there.
    $dir = "{$this->out}/demo/v1/2026-10-14";
    foreach (['items.json', 'notes.csv', 'schema.json', 'by/f000.json', 'by/f001.json', 'by/f002.json', 'by/f003.json', 'by/f004.json', 'by/f005.json'] as $file) {
        is_dir(dirname("{$this->s3}/objects/" . SNAP . $file)) || mkdir(dirname("{$this->s3}/objects/" . SNAP . $file), 0o755, true);
        copy("{$dir}/{$file}", "{$this->s3}/objects/" . SNAP . $file);
    }

    ($this->publisher)()->publish($this->out, '2026-10-14');

    $putKeys = array_column(array_filter(($this->log)(), fn (array $r) => $r['method'] === 'PUT'), 'key');
    // The claim answered 412 once; every other object that was there is asked about, never uploaded again.
    $this->assertSame(1, count(array_keys($putKeys, SNAP . 'items.json')));
    foreach (['notes.csv', 'schema.json', 'by/f000.json', 'by/f003.json', 'by/f005.json'] as $file) {
        $this->assertNotContains(SNAP . $file, $putKeys, "{$file} is already there, so its bytes are not sent");
    }
    $this->assertContains(SNAP . 'by/f006.json', $putKeys);
    $this->assertContains(SNAP . 'manifest.json', $putKeys);
    $this->assertNotNull(($this->object)('demo/v1/latest.json'));
});

test('an object that exists with other content is never overwritten, and nothing is published', function () {
    demoBuild($this->out, extraFiles: 10);
    ($this->start)();
    // A stranded directory: the claim is this build's, one object is another build's.
    mkdir("{$this->s3}/objects/" . SNAP . 'by', 0o755, true);
    copy("{$this->out}/demo/v1/2026-10-14/items.json", "{$this->s3}/objects/" . SNAP . 'items.json');
    file_put_contents("{$this->s3}/objects/" . SNAP . 'by/f004.json', 'from another build');

    expect(fn () => ($this->publisher)()->publish($this->out, '2026-10-14'))->toThrow(PublishFailed::class, 'never overwritten');

    $this->assertSame('from another build', ($this->object)(SNAP . 'by/f004.json'));
    $this->assertNull(($this->object)(SNAP . 'manifest.json'));
    $this->assertNull(($this->object)('demo/v1/latest.json'));
});

test('a claim file that exists with other content stops the publish at once', function () {
    demoBuild($this->out, extraFiles: 10);
    ($this->start)();
    mkdir("{$this->s3}/objects/" . SNAP, 0o755, true);
    file_put_contents("{$this->s3}/objects/" . SNAP . 'items.json', 'from another build');

    expect(fn () => ($this->publisher)()->publish($this->out, '2026-10-14'))->toThrow(PublishFailed::class, 'never overwritten');

    $puts = array_filter(($this->log)(), fn (array $r) => $r['method'] === 'PUT');
    $this->assertCount(1, $puts, 'nothing else was uploaded');
});
