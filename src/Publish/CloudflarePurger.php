<?php

declare(strict_types=1);

namespace Farzai\DatasetKit\Publish;

/** Purges URLs from Cloudflare's cache (zone purge_cache, by file). */
final class CloudflarePurger implements Purger
{
    public function __construct(private readonly Config $config)
    {
    }

    public function purge(array $urls): void
    {
        $curl = curl_init("{$this->config->cloudflareApi}/client/v4/zones/" . rawurlencode($this->config->zoneId) . '/purge_cache');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['files' => $urls], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->config->purgeToken, 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 60,
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $ok = $status === 200 && is_string($response) && (json_decode($response, true)['success'] ?? false) === true;
        $ok || throw new PublishFailed("purging the cache answered {$status}; latest.json may be served stale for up to 5 minutes");
    }
}
