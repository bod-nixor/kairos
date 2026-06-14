<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

kairos_auth_json(function (): void {
    $input = auth_require_post();
    $result = kairos_auth_service()->validateToken(
        (string)($input['purpose'] ?? ''),
        (string)($input['token'] ?? '')
    );
    auth_success($result);
});
