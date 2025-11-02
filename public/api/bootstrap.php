<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$cookieParams = [
    'lifetime' => 0,
    'path'     => (string)(env('SESSION_COOKIE_PATH', '/')),
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => (string)(env('SESSION_COOKIE_SAMESITE', 'Lax')),
];

$cookieDomain = env('SESSION_COOKIE_DOMAIN');
if (is_string($cookieDomain) && $cookieDomain !== '') {
    $cookieParams['domain'] = $cookieDomain;
}

session_set_cookie_params($cookieParams);

$sessionName = env('SESSION_COOKIE_NAME', 'regatta_session');
if (is_string($sessionName) && $sessionName !== '') {
    session_name($sessionName);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header_remove('Cross-Origin-Opener-Policy');
header_remove('Cross-Origin-Embedder-Policy');
header_remove('Cross-Origin-Resource-Policy');
header('Referrer-Policy: same-origin');

function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    try {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        $fallback = ['error' => 'json_encode_failure'];
        if ($status < 500) {
            $status = 500;
            http_response_code($status);
        }
        $json = json_encode($fallback, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    echo $json;
    exit;
}

function require_login(): array
{
    if (empty($_SESSION['user']) || !is_array($_SESSION['user'])) {
        json_out(['error' => 'unauthenticated'], 401);
    }

    return $_SESSION['user'];
}
