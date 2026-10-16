<?php

// A router for `php -S`: answers every request 403. CF_MODE=cloudflare looks like
// Cloudflare's bot or WAF block page (the server header, a cf-ray id and the challenge HTML);
// otherwise like a plain origin refusal.
http_response_code(403);
$mode = getenv('CF_MODE');
if ($mode === 'edge') {
    // Cloudflare stamps these on every response, so a refusal from R2 or an outage looks like this.
    header('Server: cloudflare');
    header('CF-RAY: 8d2f0c1b3a4e5f60-SIN');
    header('Content-Type: text/plain');
    echo 'Forbidden';
} elseif ($mode === 'mitigated') {
    header('Server: cloudflare');
    header('CF-RAY: 8d2f0c1b3a4e5f60-SIN');
    header('cf-mitigated: challenge');
    header('Content-Type: text/plain');
    echo 'blocked';
} elseif ($mode === 'cloudflare') {
    header('Server: cloudflare');
    header('CF-RAY: 8d2f0c1b3a4e5f60-SIN');
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><title>Attention Required! | Cloudflare</title>';
} else {
    header('Content-Type: text/plain');
    echo 'Forbidden';
}
