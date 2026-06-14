<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/auth/bootstrap.php';

use Kairos\Auth\AuthException;
use Kairos\Auth\GoogleIdentityVerifier;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_out(['success' => false, 'error' => 'method_not_allowed'], 405);
}

$input = kairos_json_input();
kairos_require_auth_csrf($input);
$credential = trim((string)($input['credential'] ?? ''));
if ($credential === '') {
    json_out(['success' => false, 'error' => 'missing_credential'], 400);
}
if (!kairos_rate_limit(
    'auth:google:' . kairos_client_ip(),
    (int)env('AUTH_RATE_LIMIT_ATTEMPTS', 12),
    (int)env('AUTH_RATE_LIMIT_WINDOW_SECONDS', 300)
)) {
    json_out(['success' => false, 'error' => 'too_many_attempts'], 429);
}

try {
    $clientId = trim((string)env('GOOGLE_CLIENT_ID', ''));
    if ($clientId === '') {
        throw new RuntimeException('Google client ID is not configured.');
    }
    $claims = (new GoogleIdentityVerifier())->verify($credential, $clientId, ALLOWED_DOMAIN);
    $user = kairos_auth_service()->googleLogin($claims, kairos_auth_context());
    apply_pending_pre_enrollments(db(), (int)$user['user_id'], (string)$user['email']);
    kairos_establish_auth_session($user);
    json_out(['success' => true, 'user' => $user]);
} catch (AuthException $error) {
    try {
        kairos_record_auth_audit('google_login_failed', 'failed', null, '', ['reason' => $error->errorCode]);
    } catch (Throwable $auditError) {
        error_log('[kairos] google auth audit failed: ' . get_class($auditError));
    }
    error_log(json_encode([
        'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? uniqid('req_', true),
        'action' => 'google_auth',
        'status' => 'failed',
        'reason_code' => $error->errorCode,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    json_out(['success' => false, 'error' => $error->publicMessage, 'code' => $error->errorCode], $error->httpStatus);
} catch (Throwable $error) {
    error_log(json_encode([
        'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? uniqid('req_', true),
        'action' => 'google_auth',
        'status' => 'failed',
        'reason_class' => get_class($error),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    json_out(['success' => false, 'error' => 'Authentication failed.'], 401);
}
