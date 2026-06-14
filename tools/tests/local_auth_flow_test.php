<?php
declare(strict_types=1);

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return $default;
    }
}

require_once dirname(__DIR__, 2) . '/src/auth/AuthException.php';
require_once dirname(__DIR__, 2) . '/src/auth/AuthConfig.php';
require_once dirname(__DIR__, 2) . '/src/auth/PasswordManager.php';
require_once dirname(__DIR__, 2) . '/src/auth/AuthRepository.php';
require_once dirname(__DIR__, 2) . '/src/auth/AuthMailer.php';
require_once dirname(__DIR__, 2) . '/src/auth/AuthSecurity.php';
require_once dirname(__DIR__, 2) . '/src/auth/AuthService.php';

use Kairos\Auth\AuthConfig;
use Kairos\Auth\AuthException;
use Kairos\Auth\AuthMailer;
use Kairos\Auth\AuthRepository;
use Kairos\Auth\AuthSecurity;
use Kairos\Auth\AuthService;
use Kairos\Auth\PasswordManager;

final class MemoryAuthRepository implements AuthRepository
{
    public array $users = [];
    public array $tokens = [];
    public array $audits = [];
    public array $assignments = [];
    private int $nextUserId = 1;
    private int $nextTokenId = 1;

    public function begin(): void {}
    public function commit(): void {}
    public function rollBack(): void {}
    public function findUserByIdentifier(string $identifier): ?array {
        foreach ($this->users as $user) {
            if (strtolower((string)$user['email']) === strtolower($identifier)
                || strtolower((string)($user['username'] ?? '')) === strtolower($identifier)) {
                return $user;
            }
        }
        return null;
    }
    public function findUserById(int $userId): ?array { return $this->users[$userId] ?? null; }
    public function findUserByGoogleId(string $googleId): ?array {
        foreach ($this->users as $user) if (($user['google_id'] ?? null) === $googleId) return $user;
        return null;
    }
    public function emailExists(string $email, ?int $exceptUserId = null): bool {
        foreach ($this->users as $id => $user) if ($id !== $exceptUserId && strtolower($user['email']) === strtolower($email)) return true;
        return false;
    }
    public function usernameExists(string $username, ?int $exceptUserId = null): bool {
        foreach ($this->users as $id => $user) if ($id !== $exceptUserId && strtolower((string)$user['username']) === strtolower($username)) return true;
        return false;
    }
    public function googleIdentityExists(string $googleId, ?int $exceptUserId = null): bool {
        foreach ($this->users as $id => $user) if ($id !== $exceptUserId && ($user['google_id'] ?? null) === $googleId) return true;
        return false;
    }
    public function roleId(string $roleName): ?int {
        return ['student' => 1, 'ta' => 2, 'manager' => 3, 'admin' => 4][$roleName] ?? null;
    }
    public function createLocalUser(array $values): int {
        $id = $this->nextUserId++;
        $this->users[$id] = [
            'user_id' => $id, 'google_id' => null, 'username' => $values['username'],
            'email' => $values['email'], 'google_email' => null, 'name' => $values['name'],
            'picture_url' => null, 'password_hash' => null, 'is_active' => 0,
            'account_status' => $values['account_status'], 'role_id' => $values['role_id'],
            'role_name' => array_search($values['role_id'], ['student' => 1, 'ta' => 2, 'manager' => 3, 'admin' => 4], true),
            'failed_login_count' => 0, 'locked_until' => null, 'auth_session_version' => 1,
        ];
        return $id;
    }
    public function createGoogleUser(array $values): int {
        $id = $this->nextUserId++;
        $this->users[$id] = [
            'user_id' => $id, 'google_id' => $values['google_id'], 'username' => null,
            'email' => $values['email'], 'google_email' => $values['google_email'], 'name' => $values['name'],
            'picture_url' => $values['picture_url'], 'password_hash' => null, 'is_active' => 1,
            'account_status' => 'active', 'role_id' => $values['role_id'], 'role_name' => 'student',
            'failed_login_count' => 0, 'locked_until' => null, 'auth_session_version' => 1,
        ];
        return $id;
    }
    public function updateGoogleProfile(int $userId, array $values): void { $this->users[$userId] = array_merge($this->users[$userId], $values); }
    public function markSuccessfulLogin(int $userId, ?string $replacementHash = null): void {
        $this->users[$userId]['failed_login_count'] = 0;
        $this->users[$userId]['locked_until'] = null;
        if ($replacementHash !== null) $this->users[$userId]['password_hash'] = $replacementHash;
    }
    public function recordFailedLogin(int $userId, int $threshold, int $lockSeconds): ?string {
        $count = ++$this->users[$userId]['failed_login_count'];
        if ($count >= $threshold) {
            return $this->users[$userId]['locked_until'] = gmdate('Y-m-d H:i:s', time() + $lockSeconds);
        }
        return null;
    }
    public function setPasswordAndStatus(int $userId, string $passwordHash, string $status, bool $incrementSessionVersion): void {
        $this->users[$userId]['password_hash'] = $passwordHash;
        $this->users[$userId]['account_status'] = $status;
        $this->users[$userId]['is_active'] = $status === 'active' ? 1 : 0;
        if ($incrementSessionVersion) $this->users[$userId]['auth_session_version']++;
    }
    public function createToken(array $values): int {
        $id = $this->nextTokenId++;
        $this->tokens[$id] = ['token_id' => $id, 'used_at' => null, 'revoked_at' => null] + $values;
        return $id;
    }
    public function revokeTokens(int $userId, string $purpose): void {
        foreach ($this->tokens as &$token) {
            if ($token['user_id'] === $userId && $token['purpose'] === $purpose && $token['used_at'] === null) {
                $token['revoked_at'] = gmdate('Y-m-d H:i:s');
            }
        }
    }
    public function findUsableToken(string $purpose, string $tokenHash): ?array {
        foreach ($this->tokens as $token) {
            if ($token['purpose'] === $purpose && $token['token_hash'] === $tokenHash
                && $token['used_at'] === null && $token['revoked_at'] === null
                && strtotime($token['expires_at'] . ' UTC') > time()) {
                return $token + ['account_status' => $this->users[$token['user_id']]['account_status']];
            }
        }
        return null;
    }
    public function consumeToken(int $tokenId): void {
        if (!isset($this->tokens[$tokenId]) || $this->tokens[$tokenId]['used_at'] !== null) {
            throw new AuthException('invalid_token', 422, 'This link is invalid or has already been used.');
        }
        $this->tokens[$tokenId]['used_at'] = gmdate('Y-m-d H:i:s');
    }
    public function consumeRateLimit(string $bucketHash, int $limit, int $windowSeconds, int $blockSeconds): array {
        return ['allowed' => true, 'retry_after' => 0];
    }
    public function linkGoogleIdentity(int $userId, string $googleId, string $googleEmail, string $pictureUrl): void {
        $this->users[$userId]['google_id'] = $googleId;
        $this->users[$userId]['google_email'] = $googleEmail;
        $this->users[$userId]['picture_url'] = $pictureUrl;
    }
    public function addCourseAssignment(int $userId, int $courseId, string $courseRole): void {
        $this->assignments[] = compact('userId', 'courseId', 'courseRole');
    }
    public function listPendingLocalAccounts(): array { return []; }
    public function audit(array $event): void { $this->audits[] = $event; }
}

final class MemoryMailer implements AuthMailer
{
    public array $messages = [];
    public bool $succeeds = true;
    public function send(string $to, string $subject, string $textBody): bool {
        $this->messages[] = compact('to', 'subject', 'textBody');
        return $this->succeeds;
    }
}

$config = new AuthConfig(
    true, 19456, 2, 1, 12, 1024, 86400, 3600, 600, 30, 10, 900, 900, 8, 900,
    '9f2c7b5e1a8046d3c8f0742be6519da7c4e819b2f0635ad87e1c4b9206f3d5a8',
    'https://kairos.example/signoff/',
    'nixorcollege.edu.pk'
);
$repo = new MemoryAuthRepository();
$mailer = new MemoryMailer();
$security = new AuthSecurity($config);
$service = new AuthService($repo, $mailer, new PasswordManager($config), $security, $config);
$context = ['ip' => '192.0.2.1', 'user_agent' => 'Kairos test'];
$failed = [];
$assert = static function (bool $condition, string $message) use (&$failed): void {
    if (!$condition) $failed[] = $message;
};
$expectCode = static function (callable $callback, string $code) use (&$failed): void {
    try {
        $callback();
        $failed[] = "expected {$code}";
    } catch (AuthException $error) {
        if ($error->errorCode !== $code) $failed[] = "expected {$code}, got {$error->errorCode}";
    }
};

$created = $service->createLocalAccount(99, [
    'name' => 'Local Student',
    'email' => 'local@example.net',
    'username' => 'local.student',
    'role' => 'student',
    'course_id' => 12,
    'course_role' => 'student',
], $context);
$userId = $created['user_id'];
$assert($repo->users[$userId]['account_status'] === 'pending_activation', 'admin-created account must be pending');
$assert($repo->users[$userId]['password_hash'] === null, 'admin-created account must not have a password');
$assert(count($mailer->messages) === 1, 'activation email must be requested');
$assert(count($repo->assignments) === 1, 'optional course assignment must be saved');
$expectCode(fn() => $service->createLocalAccount(99, [
    'name' => 'Bad Admin', 'email' => 'bad@example.net', 'username' => 'bad.admin',
    'role' => 'student', 'password' => 'AdminCannotSetThis',
], $context), 'password_not_allowed');
$expectCode(fn() => $service->createLocalAccount(99, [
    'name' => 'Duplicate', 'email' => 'local@example.net', 'username' => 'other.user', 'role' => 'student',
], $context), 'duplicate_email');

preg_match('/activate#token=([A-Za-z0-9_-]+)/', $mailer->messages[0]['textBody'], $activationMatch);
$activationToken = $activationMatch[1] ?? '';
$storedActivation = array_values(array_filter($repo->tokens, fn($token) => $token['purpose'] === 'activation'))[0] ?? null;
$assert($activationToken !== '', 'activation email must contain a token');
$assert($storedActivation && $storedActivation['token_hash'] === hash('sha256', $activationToken), 'activation token must be hashed at rest');
$assert($storedActivation['token_hash'] !== $activationToken, 'raw activation token must not be stored');

$expectCode(
    fn() => $service->passwordLogin('local.student', 'anything at all', $context),
    'pending_activation'
);
$service->activate($activationToken, 'correct horse battery staple', $context);
$assert($repo->users[$userId]['account_status'] === 'active', 'activation must set active status');
$assert($repo->tokens[$storedActivation['token_id']]['used_at'] !== null, 'activation token must be marked used');
$expectCode(fn() => $service->activate($activationToken, 'another secure passphrase', $context), 'invalid_token');
$expectCode(fn() => $service->activate('bad-token', 'another secure passphrase', $context), 'invalid_token');
$expectCode(fn() => $service->activate(str_repeat('a', 43), 'password1234', $context), 'invalid_token');

$byUsername = $service->passwordLogin('local.student', 'correct horse battery staple', $context);
$byEmail = $service->passwordLogin('local@example.net', 'correct horse battery staple', $context);
$assert($byUsername['user_id'] === $userId && $byEmail['user_id'] === $userId, 'username and email login must succeed');
$assert(!array_key_exists('password_hash', $byUsername), 'password hash must never be exposed');
$expectCode(fn() => $service->passwordLogin('local.student', 'wrong password here', $context), 'invalid_credentials');
$expectCode(fn() => $service->passwordLogin('unknown.user', 'wrong password here', $context), 'invalid_credentials');

$repo->users[$userId]['account_status'] = 'disabled';
$expectCode(fn() => $service->passwordLogin('local.student', 'correct horse battery staple', $context), 'account_unavailable');
$repo->users[$userId]['account_status'] = 'active';

$resetResponse = $service->requestPasswordReset('unknown.user', $context);
$assert($resetResponse === ['accepted' => true], 'unknown reset request must return generic success');
$service->requestPasswordReset('local.student', $context);
$resetMessage = end($mailer->messages);
preg_match('/reset-password#token=([A-Za-z0-9_-]+)/', $resetMessage['textBody'], $resetMatch);
$resetToken = $resetMatch[1] ?? '';
$service->resetPassword($resetToken, 'new unique password phrase', $context);
$expectCode(fn() => $service->passwordLogin('local.student', 'correct horse battery staple', $context), 'invalid_credentials');
$assert($service->passwordLogin('local.student', 'new unique password phrase', $context)['user_id'] === $userId, 'new password must work');
$expectCode(fn() => $service->resetPassword($resetToken, 'third unique password phrase', $context), 'invalid_token');

$state = $service->startGoogleLink($userId, $context);
$service->completeGoogleLink($userId, $state['state'], [
    'sub' => 'google-sub-1',
    'email' => 'student@nixorcollege.edu.pk',
    'picture' => 'https://example.test/avatar.png',
], $context);
$assert($repo->users[$userId]['google_id'] === 'google-sub-1', 'allowed Google account must link');
$assert($repo->users[$userId]['google_email'] === 'student@nixorcollege.edu.pk', 'linked Google email must persist');
$assert($repo->users[$userId]['picture_url'] === 'https://example.test/avatar.png', 'linked Google picture must persist');

$alternateConfig = new AuthConfig(
    true, 19456, 2, 1, 12, 1024, 86400, 3600, 600, 30, 10, 900, 900, 8, 900,
    '5d8a1f4c7e2b9063d4a8c1f75e9b620df3a76c904e2b158d6f0c7a93b1e84526',
    'https://kairos.example/campus/',
    'nixorcollege.edu.pk'
);
$alternateSecurity = new AuthSecurity($alternateConfig);
$assert($alternateSecurity->validateReturnUrl('/campus/course?course_id=12') !== null, 'configured base path must be accepted');
$assert($alternateSecurity->validateReturnUrl('/signoff/course?course_id=12') === null, 'unconfigured base path must be rejected');

$second = $service->createLocalAccount(99, [
    'name' => 'Second Student', 'email' => 'second@example.net', 'username' => 'second.student', 'role' => 'student',
], $context);
$secondMessage = end($mailer->messages);
preg_match('/activate#token=([A-Za-z0-9_-]+)/', $secondMessage['textBody'], $secondActivationMatch);
$service->activate($secondActivationMatch[1] ?? '', 'second unique password phrase', $context);
$secondState = $service->startGoogleLink($second['user_id'], $context);
$expectCode(fn() => $service->completeGoogleLink($second['user_id'], $secondState['state'], [
    'sub' => 'google-sub-1', 'email' => 'student@nixorcollege.edu.pk',
], $context), 'google_identity_in_use');
$thirdState = $service->startGoogleLink($second['user_id'], $context);
$expectCode(fn() => $service->completeGoogleLink($second['user_id'], $thirdState['state'], [
    'sub' => 'google-sub-2', 'email' => 'person@example.com',
], $context), 'google_link_failed');

if ($failed) {
    fwrite(STDERR, implode(PHP_EOL, $failed) . PHP_EOL);
    exit(1);
}

echo "local auth flow tests passed" . PHP_EOL;
