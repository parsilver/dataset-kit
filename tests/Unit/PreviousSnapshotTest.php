<?php

declare(strict_types=1);

use Farzai\DatasetKit\Publish\PreviousSnapshot;

require_once dirname(__DIR__) . '/Support/build.php';

/*
 * The workflow reads the published index and the last published snapshot back
 * from the public CDN before it builds. Here the CDN is a directory served by
 * `php -S`.
 */
beforeEach(function () {
    $this->cdn = tmpDir('cdn');
    demoBuild($this->cdn);
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name = (string) stream_socket_get_name($socket, false);
    $this->port = (int) substr($name, strrpos($name, ':') + 1);
    fclose($socket);
    $this->server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$this->port}", '-t', $this->cdn], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 200; $i++) {
        $probe = curl_init("http://127.0.0.1:{$this->port}/");
        curl_setopt_array($probe, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 200]);
        if (curl_exec($probe) !== false) {
            break;
        }
        usleep(20_000);
    }
    $this->into = tmpDir('prev');
    $this->previous = fn (string $path = '') => new PreviousSnapshot(demoLayout(), "http://127.0.0.1:{$this->port}{$path}");
});

afterEach(function () {
    proc_terminate($this->server);
    proc_close($this->server);
    exec('rm -rf ' . escapeshellarg($this->cdn) . ' ' . escapeshellarg($this->into));
});

test('pulls the index and the manifest and listed files of the last published snapshot', function () {
    $id = ($this->previous)()->pull($this->into);

    $this->assertSame('2026-10-14', $id);
    $this->assertFileEquals("{$this->cdn}/demo/v1/snapshots.json", "{$this->into}/snapshots.json");
    foreach (['items.json', 'manifest.json'] as $file) {
        $this->assertFileEquals("{$this->cdn}/demo/v1/2026-10-14/{$file}", "{$this->into}/2026-10-14/{$file}");
    }
    $this->assertFileDoesNotExist("{$this->into}/2026-10-14/notes.csv", 'only the files the layout asks for');
});

test('nothing published yet is not an error', function () {
    $this->assertNull(($this->previous)('/missing')->pull($this->into));
    $this->assertFileDoesNotExist("{$this->into}/snapshots.json");
});

test('a file that does not match the published manifest is refused', function () {
    file_put_contents("{$this->cdn}/demo/v1/2026-10-14/items.json", 'tampered');
    expect(fn () => ($this->previous)()->pull($this->into))->toThrow(RuntimeException::class, 'items.json');
});

test('a manifest that does not match the sha256 the index records is refused', function () {
    file_put_contents("{$this->cdn}/demo/v1/2026-10-14/manifest.json", "{}\n");
    expect(fn () => ($this->previous)()->pull($this->into))->toThrow(RuntimeException::class, 'manifest.json does not match');
});

test('only https, or http to a local test server', function () {
    expect(fn () => new PreviousSnapshot(demoLayout(), 'http://data.farzai.com'))->toThrow(InvalidArgumentException::class);
});

test('an id whose files are in the bucket but not in the index is stranded, and the next build skips it', function () {
    // A run that died after uploading 2026-11-01's manifest, before snapshots.json.
    mkdir("{$this->cdn}/demo/v1/2026-11-01", 0o755, true);
    file_put_contents("{$this->cdn}/demo/v1/2026-11-01/manifest.json", '{}');

    ($this->previous)()->pull($this->into, '2026-11-01');

    $this->assertSame(['2026-11-01'], json_decode((string) file_get_contents("{$this->into}/stranded.json"), true));
});

test('nothing stranded writes an empty list', function () {
    ($this->previous)()->pull($this->into, '2026-11-01');
    $this->assertSame([], json_decode((string) file_get_contents("{$this->into}/stranded.json"), true));
});

test('a snapshot directory the layout claimed but never finished is stranded, so the next build takes the next id', function () {
    // A publish that died after its first object: no manifest, so nothing points at it.
    mkdir("{$this->cdn}/demo/v1/2026-10-16", 0o755, true);
    file_put_contents("{$this->cdn}/demo/v1/2026-10-16/items.json", '{}');

    ($this->previous)()->pull($this->into, '2026-10-16');

    $this->assertSame(['2026-10-16'], json_decode((string) file_get_contents("{$this->into}/stranded.json"), true));
});

test('an id nobody has touched is not stranded', function () {
    ($this->previous)()->pull($this->into, '2026-10-16');

    $this->assertSame([], json_decode((string) file_get_contents("{$this->into}/stranded.json"), true));
});

/** Serve every request with tests/Support/forbidding-cdn.php in the given mode. */
function forbiddingCdn(string $mode): array
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name = (string) stream_socket_get_name($socket, false);
    $port = (int) substr($name, strrpos($name, ':') + 1);
    fclose($socket);
    $proc = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", dirname(__DIR__) . '/Support/forbidding-cdn.php'],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        null,
        ['CF_MODE' => $mode] + getenv(),
    );
    for ($i = 0; $i < 200; $i++) {
        $probe = curl_init("http://127.0.0.1:{$port}/");
        curl_setopt_array($probe, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 200]);
        if (curl_exec($probe) !== false) {
            break;
        }
        usleep(20_000);
    }

    return [$proc, "http://127.0.0.1:{$port}", $port];
}

test('a 403 from Cloudflare says it is a block, not a missing object, with the ray id', function () {
    [$proc, $base, $port] = forbiddingCdn('cloudflare');
    try {
        try {
            (new PreviousSnapshot(demoLayout(), $base))->pull($this->into);
            $this->fail('expected a refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('blocked by Cloudflare', $e->getMessage());
            $this->assertStringContainsString('cf-ray 8d2f0c1b3a4e5f60-SIN', $e->getMessage());
            $this->assertStringContainsString('not a missing object', $e->getMessage());
            $this->assertStringContainsString('challenge page', $e->getMessage());
        }
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        exec('pkill -f ' . escapeshellarg("php -S 127.0.0.1:{$port}") . ' 2>/dev/null');
    }
});

test('a plain 403 is reported as refused, without blaming Cloudflare', function () {
    [$proc, $base, $port] = forbiddingCdn('origin');
    try {
        try {
            (new PreviousSnapshot(demoLayout(), $base))->pull($this->into);
            $this->fail('expected a refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('answered 403', $e->getMessage());
            $this->assertStringNotContainsString('Cloudflare', $e->getMessage());
        }
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        exec('pkill -f ' . escapeshellarg("php -S 127.0.0.1:{$port}") . ' 2>/dev/null');
    }
});

test('a request that gets no response carries curl\'s error', function () {
    // Nothing listens on port 1.
    try {
        (new PreviousSnapshot(demoLayout(), 'http://127.0.0.1:1'))->pull($this->into);
        $this->fail('expected a failure');
    } catch (RuntimeException $e) {
        $this->assertStringContainsString('no response', $e->getMessage());
    }
});
