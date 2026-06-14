<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

kairos_auth_json(function (): void {
    $input = auth_require_post();
    $user = kairos_auth_service()->passwordLogin(
        (string)($input['identifier'] ?? ''),
        (string)($input['password'] ?? ''),
        kairos_auth_context()
    );
    $returnUrl = (new \Kairos\Auth\AuthSecurity(kairos_auth_config()))
        ->validateReturnUrl(isset($input['return_url']) ? (string)$input['return_url'] : null);
    kairos_establish_auth_session($user);
    auth_success(['user' => $user, 'return_url' => $returnUrl]);
});
