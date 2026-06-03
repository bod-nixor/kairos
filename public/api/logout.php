<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_out(['error' => 'method_not_allowed'], 405);
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'] ?? '/',
        'domain' => $params['domain'] ?? '',
        'secure' => (bool)($params['secure'] ?? false),
        'httponly' => (bool)($params['httponly'] ?? true),
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}
session_destroy();
json_out(['success'=>true]);
