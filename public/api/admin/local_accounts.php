<?php
declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once dirname(__DIR__) . '/auth/_common.php';

$user = require_login();
$pdo = db();
require_role_or_higher($pdo, $user, 'admin');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    auth_success(['accounts' => (new \Kairos\Auth\PdoAuthRepository($pdo))->listPendingLocalAccounts()]);
}

kairos_auth_json(function () use ($user): void {
    $input = auth_require_post();
    $action = strtolower(trim((string)($input['action'] ?? 'create')));
    if ($action === 'create') {
        $result = kairos_auth_service()->createLocalAccount(
            (int)$user['user_id'],
            $input,
            kairos_auth_context()
        );
        if (!$result['email_sent']) {
            json_out([
                'ok' => false,
                'error' => [
                    'code' => 'activation_email_failed',
                    'message' => 'The pending account was created, but the activation email could not be sent. Retry from Pending accounts.',
                ],
                'data' => $result,
            ], 502);
        }
        auth_success($result);
    }
    if ($action === 'resend_activation') {
        $result = kairos_auth_service()->resendActivation(
            (int)$user['user_id'],
            (string)($input['identifier'] ?? ''),
            kairos_auth_context()
        );
        if (!$result['email_sent']) {
            json_out([
                'ok' => false,
                'error' => [
                    'code' => 'activation_email_failed',
                    'message' => 'A new activation link was created, but the email could not be sent.',
                ],
                'data' => $result,
            ], 502);
        }
        auth_success($result);
    }
    json_out(['ok' => false, 'error' => ['code' => 'unknown_action', 'message' => 'Unknown action.']], 422);
});
