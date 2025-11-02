<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

header_remove('Cross-Origin-Opener-Policy');
header_remove('Cross-Origin-Embedder-Policy');
header_remove('Cross-Origin-Resource-Policy');
header('Content-Type: text/html; charset=utf-8');

$file = __DIR__ . '/index.html';
$html = is_file($file) ? file_get_contents($file) : false;
if ($html === false) {
    http_response_code(500);
    echo 'Unable to load application shell.';
    exit;
}

$allowedDomain = env('ALLOWED_DOMAIN', 'example.edu');
if (is_string($allowedDomain) && $allowedDomain !== '') {
    $safeDomain = htmlspecialchars($allowedDomain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = str_replace('@example.edu', '@' . $safeDomain, $html);
}

echo $html;
