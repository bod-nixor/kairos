<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$config = [
    'googleClientId' => env('GOOGLE_CLIENT_ID', ''),
    'allowedDomain'  => ltrim((string)env('ALLOWED_DOMAIN', ''), '@'),
];

echo 'window.SIGNOFF_CONFIG = Object.freeze(';
echo json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
echo ');';

echo "\n";
