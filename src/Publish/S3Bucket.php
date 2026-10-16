<?php

declare(strict_types=1);

namespace Farzai\DatasetKit\Publish;

/** An S3-compatible bucket (Cloudflare R2), path-style, over curl, signed with SigV4. */
final class S3Bucket implements Bucket
{
    private readonly SigV4 $signer;

    /** Statuses worth another try: no answer (0), request timeout, throttling, and the server errors. */
    private const RETRIABLE = [0, 408, 429, 500, 502, 503, 504];

    private const ATTEMPTS = 5;

    /** No overall deadline for a big object under contention: abort only when it has crawled for a minute. */
    private const CURL_LIMITS = [
        CURLOPT_TIMEOUT => 1200,
        CURLOPT_LOW_SPEED_LIMIT => 1024,
        CURLOPT_LOW_SPEED_TIME => 60,
        CURLOPT_CONNECTTIMEOUT => 30,
    ];

    /** The longest a server's Retry-After is honoured, in seconds. */
    private const RETRY_AFTER_MAX = 30;

    /** @param float $retryBaseSeconds the first backoff; each retry doubles it, with jitter */
    public function __construct(private readonly Config $config, private readonly float $retryBaseSeconds = 0.5)
    {
        $this->signer = new SigV4($config->accessKeyId, $config->secretAccessKey, 'auto', 's3');
    }

    public function put(string $key, string $body, array $headers, ?string $ifMatch): bool
    {
        if ($ifMatch === '*') {
            $headers['If-None-Match'] = '*';
        } elseif ($ifMatch !== null) {
            $headers['If-Match'] = $ifMatch;
        }
        [$status] = $this->request('PUT', $key, $body, $headers);
        if ($status === 412) {
            return false;
        }
        $status === 200 || throw new PublishFailed("PUT {$key} answered {$status}");

        return true;
    }

    public function get(string $key): ?array
    {
        [$status, $body, $etag] = $this->request('GET', $key, '', []);
        if ($status === 404) {
            return null;
        }
        $status === 200 || throw new PublishFailed("GET {$key} answered {$status}");

        return ['body' => $body, 'etag' => $etag];
    }

    public function headAll(array $keys, int $concurrency): array
    {
        $specs = [];
        foreach ($keys as $key) {
            $specs[] = ['key' => $key, 'method' => 'HEAD', 'body' => null, 'headers' => []];
        }
        $out = [];
        foreach ($this->runMany($specs, $concurrency) as $key => $r) {
            if ($r['status'] === 404) {
                $out[$key] = null;
                continue;
            }
            $r['status'] === 200 || throw new PublishFailed("HEAD {$key} answered {$r['status']}");
            $out[$key] = ['etag' => $r['etag'], 'size' => $r['size']];
        }

        return $out;
    }

    public function putAll(array $items, int $concurrency): array
    {
        $specs = [];
        foreach ($items as $item) {
            $specs[] = ['key' => $item['key'], 'method' => 'PUT', 'body' => $item['body'], 'headers' => $item['headers'] + ['If-None-Match' => '*']];
        }
        $out = [];
        foreach ($this->runMany($specs, $concurrency) as $key => $r) {
            if ($r['status'] === 412) {
                $out[$key] = false;
                continue;
            }
            $r['status'] === 200 || throw new PublishFailed("PUT {$key} answered {$r['status']}");
            $out[$key] = true;
        }

        return $out;
    }

    /**
     * Runs the requests with up to $concurrency in flight (curl_multi). A
     * transient failure is retried with a doubling backoff; any other status
     * is returned for the caller to judge. The first request that is still
     * failing after its attempts ends the run.
     *
     * @param list<array{key: string, method: string, body: ?\Closure(): string, headers: array<string, string>}> $specs
     * @return array<string, array{status: int, etag: string, size: int}>
     */
    private function runMany(array $specs, int $concurrency): array
    {
        $concurrency = max(1, $concurrency);
        $multi = curl_multi_init();
        $waiting = $specs;
        $delayed = []; // [readyAt, spec, attempt]
        $inFlight = []; // (int) handle => [handle, spec, attempt, headers-by-ref]
        $results = [];
        $failure = null;
        try {
            while ($waiting !== [] || $delayed !== [] || $inFlight !== []) {
                if ($failure === null) {
                    $now = microtime(true);
                    foreach ($delayed as $i => [$readyAt, $spec, $attempt]) {
                        if (count($inFlight) < $concurrency && $readyAt <= $now) {
                            unset($delayed[$i]);
                            $this->start($multi, $inFlight, $spec, $attempt);
                        }
                    }
                    while (count($inFlight) < $concurrency && $waiting !== []) {
                        $this->start($multi, $inFlight, array_shift($waiting), 1);
                    }
                }
                if ($inFlight === []) {
                    if ($failure !== null) {
                        break;
                    }
                    usleep(10_000);
                    continue;
                }
                curl_multi_exec($multi, $running);
                curl_multi_select($multi, 0.05);
                while (($done = curl_multi_info_read($multi)) !== false) {
                    $id = (int) $done['handle'];
                    [$handle, $spec, $attempt, $meta] = $inFlight[$id];
                    unset($inFlight[$id]);
                    $status = $done['result'] === CURLE_OK ? (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE) : 0;
                    $error = curl_error($handle);
                    curl_multi_remove_handle($multi, $handle);
                    if (in_array($status, self::RETRIABLE, true)) {
                        if ($attempt >= self::ATTEMPTS) {
                            $failure ??= "{$spec['method']} {$spec['key']} failed after {$attempt} attempts (" . ($status === 0 ? $error : "HTTP {$status}") . ')';
                            continue;
                        }
                        $wait = max($this->retryBaseSeconds * (2 ** ($attempt - 1)) * (0.5 + mt_rand(0, 1000) / 1000), min($meta['retryAfter'], self::RETRY_AFTER_MAX));
                        $delayed[] = [microtime(true) + $wait, $spec, $attempt + 1];
                        continue;
                    }
                    if ($status !== 200 && $status !== 404 && $status !== 412) {
                        $failure ??= "{$spec['method']} {$spec['key']} answered {$status}";
                        continue;
                    }
                    $results[$spec['key']] = ['status' => $status, 'etag' => $meta['etag'], 'size' => $meta['size']];
                }
            }
        } finally {
            foreach ($inFlight as [$handle]) {
                curl_multi_remove_handle($multi, $handle);
            }
            curl_multi_close($multi);
        }
        $failure === null || throw new PublishFailed($failure);

        return $results;
    }

    /**
     * @param array<int, array{CurlHandle, array<string, mixed>, int, array{etag: string, size: int}}> $inFlight
     * @param array{key: string, method: string, body: ?\Closure(): string, headers: array<string, string>} $spec
     */
    private function start(\CurlMultiHandle $multi, array &$inFlight, array $spec, int $attempt): void
    {
        $key = $spec['key'];
        preg_match('#^[A-Za-z0-9._/-]+$#', $key) && !str_contains($key, '..') || throw new PublishFailed("refusing an unexpected object key {$key}");
        $body = $spec['body'] === null ? '' : ($spec['body'])();
        $url = "{$this->config->endpoint}/{$this->config->bucket}/{$key}";
        $signed = $this->signer->sign($spec['method'], $url, $spec['headers'], hash('sha256', $body), new \DateTimeImmutable('now'));
        $handle = curl_init($url);
        $meta = ['etag' => '', 'size' => 0, 'retryAfter' => 0];
        curl_setopt_array($handle, self::CURL_LIMITS + [
            CURLOPT_CUSTOMREQUEST => $spec['method'],
            // An empty Expect skips the 100-continue round trip before every body.
            CURLOPT_HTTPHEADER => [...array_map(fn (string $k, string $v) => "{$k}: {$v}", array_keys($signed), $signed), 'Expect:'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_NOBODY => $spec['method'] === 'HEAD',
            CURLOPT_HEADERFUNCTION => function ($h, string $line) use (&$meta): int {
                if (stripos($line, 'etag:') === 0) {
                    $meta['etag'] = trim(substr($line, 5));
                } elseif (stripos($line, 'content-length:') === 0) {
                    $meta['size'] = (int) trim(substr($line, 15));
                } elseif (stripos($line, 'retry-after:') === 0 && ctype_digit(trim(substr($line, 12)))) {
                    $meta['retryAfter'] = (int) trim(substr($line, 12));
                }

                return strlen($line);
            },
        ]);
        if ($spec['method'] === 'PUT') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        curl_multi_add_handle($multi, $handle);
        $inFlight[(int) $handle] = [$handle, $spec, $attempt, &$meta];
    }

    /**
     * One request, retried like the batch ones: no answer, 408, 429 and 5xx are tried again with
     * the same backoff, so the claim, the manifest, snapshots.json and latest.json get the policy
     * the objects get. A conditional write that was applied but not acknowledged answers 412 on the
     * retry, which the caller already treats as "check what is there".
     *
     * @param array<string, string> $headers
     * @return array{int, string, string} status, body, ETag
     */
    private function request(string $method, string $key, string $body, array $headers): array
    {
        for ($attempt = 1; ; $attempt++) {
            [$status, $response, $etag, $error] = $this->requestOnce($method, $key, $body, $headers);
            if (!in_array($status, self::RETRIABLE, true)) {
                return [$status, $response, $etag];
            }
            if ($attempt >= self::ATTEMPTS) {
                throw new PublishFailed("{$method} {$key} failed after {$attempt} attempts (" . ($status === 0 ? $error : "HTTP {$status}") . ')');
            }
            usleep((int) (1_000_000 * $this->retryBaseSeconds * (2 ** ($attempt - 1)) * (0.5 + mt_rand(0, 1000) / 1000)));
        }
    }

    /**
     * @param array<string, string> $headers
     * @return array{int, string, string, string} status (0 when nothing answered), body, ETag, curl error
     */
    private function requestOnce(string $method, string $key, string $body, array $headers): array
    {
        preg_match('#^[A-Za-z0-9._/-]+$#', $key) && !str_contains($key, '..') || throw new PublishFailed("refusing an unexpected object key {$key}");
        $url = "{$this->config->endpoint}/{$this->config->bucket}/{$key}";
        $signed = $this->signer->sign($method, $url, $headers, hash('sha256', $body), new \DateTimeImmutable('now'));
        $curl = curl_init($url);
        $etag = '';
        curl_setopt_array($curl, self::CURL_LIMITS + [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [...array_map(fn (string $k, string $v) => "{$k}: {$v}", array_keys($signed), $signed), 'Expect:'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$etag): int {
                if (stripos($line, 'etag:') === 0) {
                    $etag = trim(substr($line, 5));
                }

                return strlen($line);
            },
        ]);
        if ($method === 'PUT') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($curl);
        $error = curl_error($curl);
        if ($response === false) {
            return [0, '', '', $error];
        }

        return [(int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), (string) $response, $etag, ''];
    }
}
