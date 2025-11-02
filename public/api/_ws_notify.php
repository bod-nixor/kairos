<?php
declare(strict_types=1);

/**
 * Notify the local WebSocket bridge about a data change.
 */
function ws_notify(array $event): void {
    $url = getenv('WS_HTTP_EMIT_URL') ?: 'http://127.0.0.1:8090/emit';
    $secret = getenv('WS_SHARED_SECRET') ?: '';
    if ($secret === '') {
        return; // disabled when not configured
    }

    $ch = curl_init($url);
    if (!$ch) {
        return;
    }

    $payload = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        $payload = '{}';
    }

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-WS-SECRET: ' . $secret,
        ],
        CURLOPT_TIMEOUT        => 2,
        CURLOPT_POSTFIELDS     => $payload,
    ]);
    @curl_exec($ch);
    @curl_close($ch);
}
