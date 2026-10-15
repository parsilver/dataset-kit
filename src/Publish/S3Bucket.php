<?php

declare(strict_types=1);

namespace Farzai\DatasetKit\Publish;

/** An S3-compatible bucket (Cloudflare R2), path-style, over curl, signed with SigV4. */
final class S3Bucket implements Bucket
{
    private readonly SigV4 $signer;

    public function __construct(private readonly Config $config)
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

    /**
     * @param array<string, string> $headers
     * @return array{int, string, string} status, body, ETag
     */
    private function request(string $method, string $key, string $body, array $headers): array
    {
        preg_match('#^[A-Za-z0-9._/-]+$#', $key) && !str_contains($key, '..') || throw new PublishFailed("refusing an unexpected object key {$key}");
        $url = "{$this->config->endpoint}/{$this->config->bucket}/{$key}";
        $signed = $this->signer->sign($method, $url, $headers, hash('sha256', $body), new \DateTimeImmutable('now'));
        $curl = curl_init($url);
        $etag = '';
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_map(fn (string $k, string $v) => "{$k}: {$v}", array_keys($signed), $signed),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_TIMEOUT => 120,
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
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        $response !== false || throw new PublishFailed("{$method} {$key} failed: {$error}");

        return [$status, (string) $response, $etag];
    }
}
