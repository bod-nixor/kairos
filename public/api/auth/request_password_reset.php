<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

kairos_auth_json(function (): void {
    $input = auth_require_post();
    kairos_auth_service()->requestPasswordReset(
        (string)($input['identifier'] ?? ''),
        kairos_auth_context()
    );
    auth_success([
        'message' => 'If an eligible account matches, a password reset email will arrive shortly.',
    ]);
});
