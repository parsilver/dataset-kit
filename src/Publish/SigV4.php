<?php

declare(strict_types=1);

namespace Farzai\DatasetKit\Publish;

/**
 * AWS Signature Version 4 for S3-compatible APIs (R2: region "auto",
 * service "s3"), header-based, for one request at a time.
 *
 * Kept small on purpose and pinned to AWS's published examples
 * (tests/Unit/SigV4Test). The secret is never returned, never in a message,
 * and hidden from var_dump/print_r.
 */
final class SigV4
{
    public function __construct(
        private readonly string $accessKeyId,
        #[\SensitiveParameter] private readonly string $secretAccessKey,
        private readonly string $region,
        private readonly string $service,
    ) {
    }

    /**
     * The headers to send: the given ones plus x-amz-date,
     * x-amz-content-sha256 and Authorization.
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    public function sign(string $method, string $url, array $headers, string $payloadSha256, \DateTimeImmutable $now): array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('cannot sign a request to an invalid URL');
        }
        $host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $now = $now->setTimezone(new \DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $day = $now->format('Ymd');

        $headers['x-amz-date'] = $amzDate;
        $headers['x-amz-content-sha256'] = $payloadSha256;

        $canonical = ['host' => $host];
        foreach ($headers as $name => $value) {
            $canonical[strtolower(trim($name))] = trim((string) preg_replace('/\s+/', ' ', $value));
        }
        ksort($canonical, SORT_STRING);
        $signedHeaders = implode(';', array_keys($canonical));

        $request = implode("\n", [
            strtoupper($method),
            self::canonicalPath($parts['path'] ?? '/'),
            self::canonicalQuery($parts['query'] ?? ''),
            implode('', array_map(fn (string $k, string $v) => "{$k}:{$v}\n", array_keys($canonical), $canonical)),
            $signedHeaders,
            $payloadSha256,
        ]);
        $scope = "{$day}/{$this->region}/{$this->service}/aws4_request";
        $toSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $request)]);

        $key = hash_hmac('sha256', $day, 'AWS4' . $this->secretAccessKey, true);
        $key = hash_hmac('sha256', $this->region, $key, true);
        $key = hash_hmac('sha256', $this->service, $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);
        $signature = hash_hmac('sha256', $toSign, $key);

        $headers['Authorization'] = "AWS4-HMAC-SHA256 Credential={$this->accessKeyId}/{$scope},SignedHeaders={$signedHeaders},Signature={$signature}";

        return $headers;
    }

    /** S3 encodes each path segment once and keeps the slashes. */
    private static function canonicalPath(string $path): string
    {
        return implode('/', array_map(fn (string $segment) => rawurlencode(rawurldecode($segment)), explode('/', $path === '' ? '/' : $path)));
    }

    private static function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }
        $pairs = [];
        foreach (explode('&', $query) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $pairs[] = [rawurlencode(rawurldecode($k)), rawurlencode(rawurldecode($v))];
        }
        usort($pairs, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return implode('&', array_map(fn (array $p) => "{$p[0]}={$p[1]}", $pairs));
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['accessKeyId' => $this->accessKeyId, 'secretAccessKey' => '[redacted]', 'region' => $this->region, 'service' => $this->service];
    }
}
