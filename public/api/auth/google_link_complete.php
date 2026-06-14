<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

use Kairos\Auth\GoogleIdentityVerifier;

kairos_auth_json(function (): void {
    $input = auth_require_post();
    $user = require_login();
    $credential = trim((string)($input['credential'] ?? ''));
    $state = trim((string)($input['state'] ?? ''));
    if ($credential === '' || $state === '') {
        json_out(['ok' => false, 'error' => ['code' => 'validation_error', 'message' => 'Missing Google link data.']], 422);
    }
    $clientId = trim((string)env('GOOGLE_CLIENT_ID', ''));
    if ($clientId === '') {
        throw new RuntimeException('Google client ID is not configured.');
    }
    try {
        $claims = (new GoogleIdentityVerifier())->verify($credential, $clientId, ALLOWED_DOMAIN);
    } catch (\Kairos\Auth\AuthException $error) {
        kairos_record_auth_audit(
            'google_link_failed',
            'failed',
            (int)$user['user_id'],
            '',
            ['reason' => $error->errorCode],
            (int)$user['user_id']
        );
        throw $error;
    }
    kairos_auth_service()->completeGoogleLink(
        (int)$user['user_id'],
        $state,
        $claims,
        kairos_auth_context()
    );
    $_SESSION['user']['google_linked'] = true;
    auth_success(['message' => 'Google account linked successfully.']);
});
