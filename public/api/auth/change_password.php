<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

kairos_auth_json(function (): void {
    $input = auth_require_post();
    $user = require_login();
    kairos_auth_service()->changePassword(
        (int)$user['user_id'],
        (string)($input['current_password'] ?? ''),
        (string)($input['new_password'] ?? ''),
        kairos_auth_context()
    );
    $stmt = db()->prepare('SELECT auth_session_version FROM users WHERE user_id = :uid');
    $stmt->execute([':uid' => (int)$user['user_id']]);
    $_SESSION['auth_session_version'] = (int)($stmt->fetchColumn() ?: 1);
    auth_success(['message' => 'Password changed successfully. Other sessions have been signed out.']);
});
