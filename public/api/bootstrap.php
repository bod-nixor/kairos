<?php
declare(strict_types=1);

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
  'lifetime' => 0,
  'path'     => '/signoff',   // IMPORTANT
  'secure'   => $secure,
  'httponly' => true,
  'samesite' => 'Lax',
]);

session_start();

header_remove('Cross-Origin-Opener-Policy');
header_remove('Cross-Origin-Embedder-Policy');
header_remove('Cross-Origin-Resource-Policy');

// load secrets + PDO
require_once '/home/nixorc5/secrets/regatta/connection.php';

function json_out($data, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data);
  exit;
}

function require_login(): array {
  if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthenticated']);
    exit;
  }
  return $_SESSION['user'];
}