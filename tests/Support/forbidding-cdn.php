<?php

// A router for `php -S`: answers every request 403. With CF_MODE=cloudflare it looks like
// Cloudflare's bot or WAF block page (the server header, a cf-ray id and the challenge HTML);
// otherwise like a plain origin refusal.
http_response_code(403);
if (getenv('CF_MODE') === 'cloudflare') {
    header('Server: cloudflare');
    header('CF-RAY: 8d2f0c1b3a4e5f60-SIN');
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><title>Attention Required! | Cloudflare</title>';
} else {
    header('Content-Type: text/plain');
    echo 'Forbidden';
}
