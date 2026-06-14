<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_out(['ok' => false, 'error' => ['code' => 'method_not_allowed', 'message' => 'Method not allowed.']], 405);
}

$config = kairos_auth_config();
auth_success([
    'csrf_token' => kairos_csrf_token(),
    'local_auth_enabled' => $config->enabled,
    'password_policy' => [
        'min_length' => $config->passwordMinLength,
        'max_length' => $config->passwordMaxLength,
        'guidance' => 'Use a long, unique passphrase. No special-character formula is required.',
    ],
]);
