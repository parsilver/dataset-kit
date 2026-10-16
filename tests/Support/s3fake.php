<?php

/*
 * A path-style S3 fake for the publisher tests, run with `php -S` and
 * PHP_CLI_SERVER_WORKERS so requests overlap. State is files under
 * S3FAKE_DIR/objects, so every worker sees the same bucket:
 *
 *   - PUT honours If-None-Match: * (exclusive create, 412 when present) and If-Match;
 *   - GET, HEAD answer with a Content-Length and an md5 ETag, 404 when absent;
 *   - S3FAKE_LATENCY_MS delays every answer;
 *   - S3FAKE_DIR/faults.json maps a key to [status, times]: that many answers carry the status
 *     instead of being served (a transient 503 or a hard 403);
 *   - every request is appended to S3FAKE_DIR/log.jsonl with its start and end time.
 *
 * Signatures are not checked: SigV4Test pins the signer against AWS's own vectors.
 */
$dir = (string) getenv('S3FAKE_DIR');
$latency = (int) getenv('S3FAKE_LATENCY_MS');
$started = microtime(true);
$method = $_SERVER['REQUEST_METHOD'];
$path = ltrim((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
[, $key] = array_pad(explode('/', $path, 2), 2, '');
$file = "{$dir}/objects/" . $key;
$headers = array_change_key_case(function_exists('getallheaders') ? getallheaders() : [], CASE_LOWER);

$respond = function (int $status, string $body = '', array $extra = []) use (&$started, $dir, $method, $key): void {
    http_response_code($status);
    foreach ($extra as $name => $value) {
        header("{$name}: {$value}");
    }
    $line = json_encode(['t0' => $started, 't1' => microtime(true), 'method' => $method, 'key' => $key, 'status' => $status]) . "\n";
    file_put_contents("{$dir}/log.jsonl", $line, FILE_APPEND | LOCK_EX);
    echo $body;
};

if (str_starts_with($path, 'client/v4/zones/')) { // the Cloudflare purge, for a full run through bin/
    $respond(200, '{"success":true}', ['Content-Type' => 'application/json']);

    return;
}

if ($latency > 0) {
    usleep($latency * 1000);
}

$faults = is_file("{$dir}/faults.json") ? json_decode((string) file_get_contents("{$dir}/faults.json"), true) : [];
if (isset($faults[$key])) {
    $counter = "{$dir}/fault-" . md5($key);
    $fh = fopen($counter, 'c+');
    flock($fh, LOCK_EX);
    $used = (int) stream_get_contents($fh);
    if ($used < $faults[$key][1]) {
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) ($used + 1));
        flock($fh, LOCK_UN);
        fclose($fh);
        $respond($faults[$key][0]);

        return;
    }
    flock($fh, LOCK_UN);
    fclose($fh);
}

if ($method === 'PUT') {
    $body = (string) file_get_contents('php://input');
    is_dir(dirname($file)) || @mkdir(dirname($file), 0o755, true);
    if (($headers['if-none-match'] ?? '') === '*') {
        $fh = @fopen($file, 'x');
        if ($fh === false) {
            $respond(412);

            return;
        }
        fwrite($fh, $body);
        fclose($fh);
    } elseif (isset($headers['if-match'])) {
        if (!is_file($file) || '"' . md5_file($file) . '"' !== $headers['if-match']) {
            $respond(412);

            return;
        }
        file_put_contents($file, $body);
    } else {
        file_put_contents($file, $body);
    }
    $respond(200, '', ['ETag' => '"' . md5($body) . '"']);

    return;
}

if (!is_file($file)) {
    $respond(404);

    return;
}
$body = (string) file_get_contents($file);
$respond(200, $method === 'HEAD' ? '' : $body, ['ETag' => '"' . md5($body) . '"', 'Content-Length' => (string) strlen($body)]);
