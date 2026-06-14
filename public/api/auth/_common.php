<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 3) . '/src/auth/bootstrap.php';

function auth_require_post(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_out(['ok' => false, 'error' => ['code' => 'method_not_allowed', 'message' => 'Method not allowed.']], 405);
    }
    $input = kairos_json_input();
    kairos_require_auth_csrf($input);
    return $input;
}

function auth_success(array $data = []): void
{
    json_out(['ok' => true, 'data' => $data]);
}
