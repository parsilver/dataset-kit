<?php

declare(strict_types=1);

namespace Farzai\DatasetKit\Publish;

/**
 * Publish settings, from environment variables only, read at run time
 * (decision 1): the token is never committed, never in a shared table, never
 * logged. Error messages name a variable, never its value.
 */
final class Config
{
    public const REQUIRED = ['R2_ENDPOINT', 'R2_BUCKET', 'R2_ACCESS_KEY_ID', 'R2_SECRET_ACCESS_KEY', 'CLOUDFLARE_ZONE_ID', 'CLOUDFLARE_PURGE_TOKEN'];

    private function __construct(
        public readonly string $endpoint,
        public readonly string $bucket,
        public readonly string $accessKeyId,
        #[\SensitiveParameter] public readonly string $secretAccessKey,
        public readonly string $zoneId,
        #[\SensitiveParameter] public readonly string $purgeToken,
        public readonly string $publicBaseUrl,
        public readonly string $cloudflareApi,
    ) {
    }

    /** @param array<string, string|false> $env */
    public static function fromEnv(array $env): self
    {
        foreach (self::REQUIRED as $name) {
            is_string($env[$name] ?? null) && $env[$name] !== '' || throw new \RuntimeException("{$name} is not set; publishing needs " . implode(', ', self::REQUIRED));
        }
        $endpoint = rtrim((string) $env['R2_ENDPOINT'], '/');
        $api = rtrim(is_string($env['CLOUDFLARE_API_BASE'] ?? null) && $env['CLOUDFLARE_API_BASE'] !== '' ? $env['CLOUDFLARE_API_BASE'] : 'https://api.cloudflare.com', '/');
        foreach (['R2_ENDPOINT' => $endpoint, 'CLOUDFLARE_API_BASE' => $api] as $name => $url) {
            self::secureUrl($url) || throw new \RuntimeException("{$name} must be an https URL (plain http only for a local test server)");
        }
        preg_match('/^[a-z0-9][a-z0-9.-]{1,62}$/', (string) $env['R2_BUCKET']) || throw new \RuntimeException('R2_BUCKET is not a valid bucket name');

        return new self(
            $endpoint,
            (string) $env['R2_BUCKET'],
            (string) $env['R2_ACCESS_KEY_ID'],
            (string) $env['R2_SECRET_ACCESS_KEY'],
            (string) $env['CLOUDFLARE_ZONE_ID'],
            (string) $env['CLOUDFLARE_PURGE_TOKEN'],
            rtrim(is_string($env['PUBLIC_BASE_URL'] ?? null) && $env['PUBLIC_BASE_URL'] !== '' ? $env['PUBLIC_BASE_URL'] : 'https://data.farzai.com', '/'),
            $api,
        );
    }

    /** https, or http to the loopback interface only (the local test fake). */
    public static function secureUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $scheme === 'https' || ($scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost', '[::1]', '::1'], true));
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['endpoint' => $this->endpoint, 'bucket' => $this->bucket, 'accessKeyId' => $this->accessKeyId, 'secretAccessKey' => '[redacted]', 'zoneId' => $this->zoneId, 'purgeToken' => '[redacted]', 'publicBaseUrl' => $this->publicBaseUrl];
    }
}
