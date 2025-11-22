<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$googleClientId = env('GOOGLE_CLIENT_ID');
$allowedDomain = env('ALLOWED_DOMAIN', '');
$wsSocketPath = env('WS_SOCKET_PATH', '/websocket/socket.io');
$wsBaseUrl = env('WS_PUBLIC_URL', 'wss://regatta.nixorcorporate.com');

$config = [
    'googleClientId' => is_string($googleClientId) && $googleClientId !== '' ? $googleClientId : null,
    'allowedDomain' => is_string($allowedDomain) ? ltrim($allowedDomain, '@') : '',
    'wsBaseUrl' => is_string($wsBaseUrl) ? rtrim($wsBaseUrl, '/') : '',
    'wsSocketPath' => is_string($wsSocketPath) && $wsSocketPath !== ''
        ? '/' . ltrim($wsSocketPath, '/')
        : '/websocket/socket.io',
];

if ($config['wsBaseUrl'] === '') {
    $config['wsBaseUrl'] = 'wss://regatta.nixorcorporate.com';
}

try {
    echo json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'config_load_failed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
