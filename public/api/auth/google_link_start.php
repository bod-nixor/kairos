<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

kairos_auth_json(function (): void {
    auth_require_post();
    $user = require_login();
    auth_success(kairos_auth_service()->startGoogleLink((int)$user['user_id'], kairos_auth_context()));
});
