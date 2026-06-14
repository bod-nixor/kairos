<?php
declare(strict_types=1);

require_once __DIR__ . '/AuthException.php';
require_once __DIR__ . '/AuthConfig.php';
require_once __DIR__ . '/PasswordManager.php';
require_once __DIR__ . '/AuthRepository.php';
require_once __DIR__ . '/PdoAuthRepository.php';
require_once __DIR__ . '/AuthMailer.php';
require_once __DIR__ . '/NativeAuthMailer.php';
require_once __DIR__ . '/AuthSecurity.php';
require_once __DIR__ . '/AuthService.php';
require_once __DIR__ . '/GoogleIdentityVerifier.php';

use Kairos\Auth\AuthConfig;
use Kairos\Auth\AuthSecurity;
use Kairos\Auth\AuthService;
use Kairos\Auth\NativeAuthMailer;
use Kairos\Auth\PasswordManager;
use Kairos\Auth\PdoAuthRepository;

function kairos_auth_config(): AuthConfig
{
    static $config = null;
    return $config ??= AuthConfig::fromEnvironment();
}

function kairos_auth_service(): AuthService
{
    static $service = null;
    if ($service instanceof AuthService) {
        return $service;
    }
    $config = kairos_auth_config();
    return $service = new AuthService(
        new PdoAuthRepository(db()),
        new NativeAuthMailer(),
        new PasswordManager($config),
        new AuthSecurity($config),
        $config
    );
}

function kairos_auth_context(): array
{
    return [
        'ip' => function_exists('kairos_client_ip') ? kairos_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
        'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1024),
    ];
}

function kairos_record_auth_audit(
    string $eventName,
    string $status,
    ?int $subjectUserId = null,
    string $identifier = '',
    array $metadata = [],
    ?int $actorUserId = null
): void {
    $config = kairos_auth_config();
    $security = new AuthSecurity($config);
    $context = kairos_auth_context();
    (new PdoAuthRepository(db()))->audit([
        'event_name' => $eventName,
        'actor_user_id' => $actorUserId,
        'subject_user_id' => $subjectUserId,
        'identifier_hash' => $security->privacyHash($identifier),
        'ip_hash' => $security->privacyHash((string)$context['ip']),
        'user_agent_hash' => $security->privacyHash((string)$context['user_agent']),
        'status' => $status,
        'metadata' => $metadata ?: null,
    ]);
}

function apply_pending_pre_enrollments(PDO $pdo, int $userId, string $email): void
{
    if ($userId <= 0 || $email === '') {
        return;
    }
    $check = $pdo->prepare(
        "SELECT 1 FROM information_schema.TABLES"
        . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'course_pre_enroll' LIMIT 1"
    );
    $check->execute();
    if (!$check->fetchColumn()) {
        return;
    }
    $select = $pdo->prepare(
        'SELECT id, course_id FROM course_pre_enroll WHERE LOWER(email) = LOWER(:email)'
    );
    $select->execute([':email' => $email]);
    $rows = $select->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) {
        return;
    }
    $enroll = $pdo->prepare(
        'INSERT INTO student_courses (course_id, user_id) VALUES (:cid, :uid)'
        . ' ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)'
    );
    $claim = $pdo->prepare(
        'UPDATE course_pre_enroll SET claimed_user_id = :uid'
        . ' WHERE id = :id AND (claimed_user_id IS NULL OR claimed_user_id = 0)'
    );
    $started = !$pdo->inTransaction();
    if ($started) {
        $pdo->beginTransaction();
    }
    try {
        foreach ($rows as $row) {
            $courseId = (int)($row['course_id'] ?? 0);
            if ($courseId <= 0) {
                continue;
            }
            $enroll->execute([':cid' => $courseId, ':uid' => $userId]);
            if ((int)($row['id'] ?? 0) > 0) {
                $claim->execute([':uid' => $userId, ':id' => (int)$row['id']]);
            }
        }
        if ($started) {
            $pdo->commit();
        }
    } catch (Throwable $error) {
        if ($started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function kairos_csrf_token(): string
{
    if (empty($_SESSION['auth_csrf_token']) || !is_string($_SESSION['auth_csrf_token'])) {
        $_SESSION['auth_csrf_token'] = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
    return $_SESSION['auth_csrf_token'];
}

function kairos_require_auth_csrf(?array $input = null): void
{
    $provided = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_csrf'] ?? ''));
    $expected = kairos_csrf_token();
    if ($provided === '' || !hash_equals($expected, $provided)) {
        json_out(['ok' => false, 'error' => ['code' => 'csrf_rejected', 'message' => 'Request verification failed.']], 403);
    }
}

function kairos_establish_auth_session(array $user): void
{
    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['user'] = [
        'user_id' => (int)$user['user_id'],
        'email' => $user['email'],
        'username' => $user['username'] ?? null,
        'name' => $user['name'],
        'picture_url' => $user['picture_url'] ?? null,
        'role_id' => (int)$user['role_id'],
        'role_name' => $user['role_name'] ?? null,
        'account_status' => $user['account_status'] ?? 'active',
        'has_password' => (bool)($user['has_password'] ?? false),
        'google_linked' => (bool)($user['google_linked'] ?? false),
    ];
    $_SESSION['auth_session_version'] = (int)($user['auth_session_version'] ?? 1);
    kairos_csrf_token();
}

function kairos_auth_json(callable $callback): void
{
    try {
        $callback();
    } catch (\Kairos\Auth\AuthException $error) {
        json_out([
            'ok' => false,
            'error' => [
                'code' => $error->errorCode,
                'message' => $error->publicMessage,
            ],
        ], $error->httpStatus);
    }
}
