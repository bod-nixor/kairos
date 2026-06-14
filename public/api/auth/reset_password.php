<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

kairos_auth_json(function (): void {
    $input = auth_require_post();
    $result = kairos_auth_service()->resetPassword(
        (string)($input['token'] ?? ''),
        (string)($input['password'] ?? ''),
        kairos_auth_context()
    );
    auth_success($result + ['message' => 'Your password has been reset. You can now sign in.']);
});
