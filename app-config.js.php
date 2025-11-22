<?php
declare(strict_types=1);

header('Content-Type: application/javascript; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
    require_once __DIR__ . '/config/app.php';

    $googleClientId = env('GOOGLE_CLIENT_ID');
    $allowedDomain = env('ALLOWED_DOMAIN', '');
    $wsSocketPath = env('WS_SOCKET_PATH', '/websocket/socket.io');
    $wsBaseUrl = env('WS_PUBLIC_URL', 'wss://regatta.nixorcorporate.com');

    $config = [
        'googleClientId' => is_string($googleClientId) && $googleClientId !== '' ? $googleClientId : null,
        'allowedDomain' => is_string($allowedDomain) ? ltrim($allowedDomain, '@') : '',
        'wsBaseUrl' => is_string($wsBaseUrl) ? rtrim($wsBaseUrl, '/') : '',
        'wsSocketPath' => is_string($wsSocketPath) && $wsSocketPath !== '' ? '/' . ltrim($wsSocketPath, '/') : '/websocket/socket.io',
    ];

    if ($config['wsBaseUrl'] === '') {
        $config['wsBaseUrl'] = 'wss://regatta.nixorcorporate.com';
    }

    echo 'window.SignoffConfig = Object.freeze(';
    echo json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    echo ');';
    echo "\n";
    echo 'window.SIGNOFF_CONFIG = window.SignoffConfig;';
} catch (Throwable $e) {
    echo 'window.SignoffConfig = {"error":"config_load_failed"};';
    echo "\n";
    echo 'window.SIGNOFF_CONFIG = window.SignoffConfig;';
}
