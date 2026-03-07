<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$googleClientId = env('GOOGLE_CLIENT_ID');
$allowedDomain = env('ALLOWED_DOMAIN', '');
$wsSocketPath = env('WS_SOCKET_PATH', '/websocket/socket.io');
$wsBaseUrl = env('WS_PUBLIC_URL', 'wss://kairos.nixorcorporate.com');

$config = [
    'googleClientId' => is_string($googleClientId) && $googleClientId !== '' ? $googleClientId : null,
    'allowedDomain' => is_string($allowedDomain) ? ltrim($allowedDomain, '@') : '',
    'wsBaseUrl' => is_string($wsBaseUrl) ? rtrim($wsBaseUrl, '/') : '',
    'wsSocketPath' => is_string($wsSocketPath) && $wsSocketPath !== ''
        ? '/' . ltrim($wsSocketPath, '/')
        : '/websocket/socket.io',
    'branding' => [
        'productName' => 'Kairos',
        'homeLabel' => 'Kairos home',
        'logoUrl' => './images/logo.png',
        'logoAlt' => 'Kairos',
    ],
];

if ($config['wsBaseUrl'] === '') {
    $config['wsBaseUrl'] = 'wss://kairos.nixorcorporate.com';
}

try {
    $branding = db()->query('SELECT institution_name, logo_url FROM lms_branding_config ORDER BY id DESC LIMIT 1')->fetch();
    if ($branding) {
        if (!empty($branding['logo_url']) && is_string($branding['logo_url'])) {
            $config['branding']['logoUrl'] = $branding['logo_url'];
        }
        if (!empty($branding['institution_name']) && is_string($branding['institution_name'])) {
            $config['branding']['productName'] = $branding['institution_name'];
            $config['branding']['homeLabel'] = $branding['institution_name'] . ' home';
            $config['branding']['logoAlt'] = $branding['institution_name'];
        }
    }
} catch (Throwable $e) {
    error_log(json_encode([
        'context' => 'public_config.branding_query',
        'action' => 'db()->query(lms_branding_config)',
        'status' => 'failed',
        'exception_message' => $e->getMessage(),
        'exception_code' => $e->getCode(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

try {
    echo json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'config_load_failed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
