<?php
declare(strict_types=1);

namespace Kairos\Auth;

use Throwable;

final class AuthService
{
    private const ROLES = ['student', 'ta', 'manager', 'admin'];
    private const COURSE_ROLES = ['student', 'ta', 'manager'];

    public function __construct(
        private readonly AuthRepository $repository,
        private readonly AuthMailer $mailer,
        private readonly PasswordManager $passwords,
        private readonly AuthSecurity $security,
        private readonly AuthConfig $config,
    ) {
    }

    public function passwordLogin(string $identifier, string $password, array $context = []): array
    {
        $this->assertEnabled();
        $identifier = $this->normalizeIdentifier($identifier);
        if ($identifier === '' || $password === '') {
            throw $this->invalidCredentials();
        }
        $this->enforceLoginRateLimits($identifier, $context);
        $user = $this->repository->findUserByIdentifier($identifier);

        if (!$user) {
            $this->audit('password_login_failed', null, $identifier, 'failed', $context, ['reason' => 'invalid_credentials']);
            throw $this->invalidCredentials();
        }
        $status = strtolower((string)($user['account_status'] ?? 'active'));
        if ($status === 'pending_activation') {
            $this->audit('password_login_denied', (int)$user['user_id'], $identifier, 'denied', $context, ['reason' => 'pending_activation']);
            throw new AuthException(
                'pending_activation',
                403,
                'This account is waiting for activation. Check your email for the activation link.'
            );
        }
        if ($status === 'disabled' || (int)($user['is_active'] ?? 0) !== 1) {
            $this->audit('password_login_denied', (int)$user['user_id'], $identifier, 'denied', $context, ['reason' => 'disabled']);
            throw new AuthException('account_unavailable', 403, 'This account is unavailable. Contact Kairos support.');
        }
        if ($status === 'locked' || $this->isFutureDate($user['locked_until'] ?? null)) {
            $this->audit('password_login_throttled', (int)$user['user_id'], $identifier, 'throttled', $context);
            throw new AuthException('login_throttled', 429, 'Too many attempts. Try again later.');
        }

        $hash = (string)($user['password_hash'] ?? '');
        if (!$this->passwords->verify($password, $hash)) {
            $lockedUntil = $this->repository->recordFailedLogin(
                (int)$user['user_id'],
                $this->config->accountFailureThreshold,
                $this->config->accountLockSeconds
            );
            $this->audit(
                $lockedUntil ? 'password_login_locked' : 'password_login_failed',
                (int)$user['user_id'],
                $identifier,
                $lockedUntil ? 'throttled' : 'failed',
                $context,
                ['reason' => 'invalid_credentials']
            );
            throw $this->invalidCredentials();
        }

        $replacementHash = $this->passwords->needsRehash($hash)
            ? $this->passwords->hash($password)
            : null;
        $this->repository->markSuccessfulLogin((int)$user['user_id'], $replacementHash);
        $fresh = $this->repository->findUserById((int)$user['user_id']) ?? $user;
        $this->audit('password_login_succeeded', (int)$user['user_id'], $identifier, 'succeeded', $context, [
            'password_rehashed' => $replacementHash !== null,
        ]);
        return $this->publicUser($fresh);
    }

    public function googleLogin(array $claims, array $context = []): array
    {
        $googleId = trim((string)($claims['sub'] ?? ''));
        $email = strtolower(trim((string)($claims['email'] ?? '')));
        $name = trim((string)($claims['name'] ?? ''));
        $picture = trim((string)($claims['picture'] ?? ''));
        if ($googleId === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new AuthException('google_auth_failed', 401, 'Authentication failed.');
        }

        $user = $this->repository->findUserByGoogleId($googleId);
        if ($user) {
            if (($user['account_status'] ?? 'active') !== 'active' || (int)($user['is_active'] ?? 0) !== 1) {
                $this->audit('google_login_denied', (int)$user['user_id'], $email, 'denied', $context, ['reason' => 'disabled']);
                throw new AuthException('account_unavailable', 403, 'This account is unavailable. Contact Kairos support.');
            }
            $this->repository->updateGoogleProfile((int)$user['user_id'], [
                'google_email' => $email,
                'name' => !empty($user['password_hash'])
                    ? (string)$user['name']
                    : ($name !== '' ? $name : (string)$user['name']),
                'picture_url' => $picture,
            ]);
            $this->repository->markSuccessfulLogin((int)$user['user_id']);
            $fresh = $this->repository->findUserById((int)$user['user_id']) ?? $user;
            $this->audit('google_login_succeeded', (int)$user['user_id'], $email, 'succeeded', $context);
            return $this->publicUser($fresh);
        }

        if ($this->repository->emailExists($email)) {
            $this->audit('google_login_link_required', null, $email, 'denied', $context);
            throw new AuthException(
                'google_link_required',
                409,
                'An existing account uses this email. Sign in with your password and link Google from Settings.'
            );
        }
        $roleId = $this->repository->roleId('student');
        if ($roleId === null) {
            throw new \RuntimeException('Default student role is missing.');
        }
        $this->repository->begin();
        try {
            $userId = $this->repository->createGoogleUser([
                'google_id' => $googleId,
                'email' => $email,
                'google_email' => $email,
                'name' => $name !== '' ? $name : strstr($email, '@', true),
                'picture_url' => $picture,
                'role_id' => $roleId,
            ]);
            $this->audit('google_registration_succeeded', $userId, $email, 'succeeded', $context);
            $this->repository->commit();
        } catch (Throwable $error) {
            $this->repository->rollBack();
            throw $error;
        }
        $created = $this->repository->findUserById($userId);
        if (!$created) {
            throw new \RuntimeException('Google user reload failed.');
        }
        return $this->publicUser($created);
    }

    public function createLocalAccount(int $actorUserId, array $input, array $context = []): array
    {
        $this->assertEnabled();
        if (array_key_exists('password', $input) || array_key_exists('password_hash', $input)) {
            throw new AuthException('password_not_allowed', 422, 'Administrators cannot set user passwords.');
        }
        $name = trim((string)($input['name'] ?? ''));
        $email = strtolower(trim((string)($input['email'] ?? '')));
        $username = strtolower(trim((string)($input['username'] ?? '')));
        $role = strtolower(trim((string)($input['role'] ?? 'student')));
        $courseId = (int)($input['course_id'] ?? 0);
        $courseRole = strtolower(trim((string)($input['course_role'] ?? '')));

        if ($name === '' || strlen($name) > 255) {
            throw new AuthException('invalid_name', 422, 'Enter a valid full name.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            throw new AuthException('invalid_email', 422, 'Enter a valid email address.');
        }
        if (!preg_match('/^[a-z][a-z0-9._-]{2,31}$/', $username)) {
            throw new AuthException(
                'invalid_username',
                422,
                'Username must be 3-32 characters and use letters, numbers, dots, underscores, or hyphens.'
            );
        }
        if (!in_array($role, self::ROLES, true)) {
            throw new AuthException('invalid_role', 422, 'Select a valid role.');
        }
        if ($courseRole !== '' && (!in_array($courseRole, self::COURSE_ROLES, true) || $courseId <= 0)) {
            throw new AuthException('invalid_course_assignment', 422, 'Select a valid optional course assignment.');
        }
        if ($courseRole !== '' && $this->roleRank($courseRole) > $this->roleRank($role)) {
            throw new AuthException('invalid_course_assignment', 422, 'Course access cannot exceed the account role.');
        }
        if ($this->repository->emailExists($email)) {
            throw new AuthException('duplicate_email', 409, 'That email address is already in use.');
        }
        if ($this->repository->usernameExists($username)) {
            throw new AuthException('duplicate_username', 409, 'That username is already in use.');
        }
        $roleId = $this->repository->roleId($role);
        if ($roleId === null) {
            throw new AuthException('invalid_role', 422, 'Select a valid role.');
        }

        $rawToken = $this->security->randomToken();
        $this->repository->begin();
        try {
            $userId = $this->repository->createLocalUser([
                'username' => $username,
                'email' => $email,
                'name' => $name,
                'account_status' => 'pending_activation',
                'role_id' => $roleId,
            ]);
            if ($courseRole !== '') {
                $this->repository->addCourseAssignment($userId, $courseId, $courseRole);
            }
            $this->repository->createToken($this->tokenValues(
                $userId,
                'activation',
                $rawToken,
                $this->config->activationTtlSeconds,
                $context
            ));
            $this->audit('local_account_created', $userId, $email, 'succeeded', $context, [
                'actor_user_id' => $actorUserId,
                'role' => $role,
                'course_id' => $courseId ?: null,
                'course_role' => $courseRole ?: null,
            ], $actorUserId);
            $this->repository->commit();
        } catch (Throwable $error) {
            $this->repository->rollBack();
            throw $error;
        }

        $sent = $this->sendActivationEmail($email, $name, $rawToken);
        $this->audit(
            $sent ? 'activation_email_sent' : 'activation_email_failed',
            $userId,
            $email,
            $sent ? 'succeeded' : 'failed',
            $context,
            [],
            $actorUserId
        );
        return ['user_id' => $userId, 'email_sent' => $sent, 'account_status' => 'pending_activation'];
    }

    public function resendActivation(int $actorUserId, string $identifier, array $context = []): array
    {
        $this->assertEnabled();
        $user = $this->repository->findUserByIdentifier($this->normalizeIdentifier($identifier));
        if (!$user || ($user['account_status'] ?? '') !== 'pending_activation') {
            throw new AuthException('account_not_pending', 404, 'Pending account not found.');
        }
        $rawToken = $this->security->randomToken();
        $this->repository->begin();
        try {
            $this->repository->revokeTokens((int)$user['user_id'], 'activation');
            $this->repository->createToken($this->tokenValues(
                (int)$user['user_id'],
                'activation',
                $rawToken,
                $this->config->activationTtlSeconds,
                $context
            ));
            $this->audit('activation_token_reissued', (int)$user['user_id'], (string)$user['email'], 'succeeded', $context, [], $actorUserId);
            $this->repository->commit();
        } catch (Throwable $error) {
            $this->repository->rollBack();
            throw $error;
        }
        $sent = $this->sendActivationEmail((string)$user['email'], (string)$user['name'], $rawToken);
        $this->audit(
            $sent ? 'activation_email_sent' : 'activation_email_failed',
            (int)$user['user_id'],
            (string)$user['email'],
            $sent ? 'succeeded' : 'failed',
            $context,
            [],
            $actorUserId
        );
        return ['user_id' => (int)$user['user_id'], 'email_sent' => $sent];
    }

    public function validateToken(string $purpose, string $rawToken): array
    {
        $this->assertEnabled();
        if (!in_array($purpose, ['activation', 'password_reset'], true) || !$this->validRawToken($rawToken)) {
            throw new AuthException('invalid_token', 422, 'This link is invalid or has expired.');
        }
        $this->repository->begin();
        try {
            $token = $this->repository->findUsableToken($purpose, $this->security->tokenHash($rawToken));
            if (!$token) {
                throw new AuthException('invalid_token', 422, 'This link is invalid or has expired.');
            }
            $this->repository->commit();
            return ['valid' => true, 'purpose' => $purpose];
        } catch (Throwable $error) {
            $this->repository->rollBack();
            throw $error;
        }
    }

    public function activate(string $rawToken, string $password, array $context = []): array
    {
        return $this->consumePasswordToken('activation', $rawToken, $password, $context);
    }

    public function requestPasswordReset(string $identifier, array $context = []): array
    {
        $this->assertEnabled();
        $identifier = $this->normalizeIdentifier($identifier);
        $bucket = $this->security->privacyHash('reset:' . ($context['ip'] ?? 'unknown'));
        if ($bucket !== null) {
            $limit = $this->repository->consumeRateLimit(
                $bucket,
                10,
                $this->config->rateLimitWindowSeconds,
                $this->config->rateLimitBlockSeconds
            );
            if (!$limit['allowed']) {
                $this->audit('password_reset_request_throttled', null, $identifier, 'throttled', $context);
                return ['accepted' => true];
            }
        }
        $user = $identifier !== '' ? $this->repository->findUserByIdentifier($identifier) : null;
        if (!$user || ($user['account_status'] ?? '') !== 'active' || empty($user['password_hash'])) {
            $this->audit('password_reset_requested', $user ? (int)$user['user_id'] : null, $identifier, 'accepted', $context, [
                'eligible' => false,
            ]);
            return ['accepted' => true];
        }

        $rawToken = $this->security->randomToken();
        $this->repository->begin();
        try {
            $this->repository->revokeTokens((int)$user['user_id'], 'password_reset');
            $this->repository->createToken($this->tokenValues(
                (int)$user['user_id'],
                'password_reset',
                $rawToken,
                $this->config->resetTtlSeconds,
                $context
            ));
            $this->audit('password_reset_token_issued', (int)$user['user_id'], $identifier, 'succeeded', $context);
            $this->repository->commit();
        } catch (Throwable $error) {
            $this->repository->rollBack();
            throw $error;
        }
        $sent = $this->sendResetEmail((string)$user['email'], (string)$user['name'], $rawToken);
        $this->audit(
            $sent ? 'password_reset_email_sent' : 'password_reset_email_failed',
            (int)$user['user_id'],
            $identifier,
            $sent ? 'succeeded' : 'failed',
            $context
        );
        return ['accepted' => true];
    }

    public function resetPassword(string $rawToken, string $password, array $context = []): array
    {
        return $this->consumePasswordToken('password_reset', $rawToken, $password, $context);
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword, array $context = []): void
    {
        $this->assertEnabled();
        $user = $this->repository->findUserById($userId);
        if (!$user || empty($user['password_hash']) || ($user['account_status'] ?? '') !== 'active') {
            throw new AuthException('password_login_unavailable', 422, 'Password login is not enabled for this account.');
        }
        if (!$this->passwords->verify($currentPassword, (string)$user['password_hash'])) {
            $this->audit('password_change_failed', $userId, (string)$user['email'], 'failed', $context, [
                'reason' => 'invalid_current_password',
            ]);
            throw new AuthException('invalid_current_password', 422, 'Current password is incorrect.');
        }
        $hash = $this->passwords->hash($newPassword);
        $this->repository->begin();
        try {
            $this->repository->setPasswordAndStatus($userId, $hash, 'active', true);
            $this->repository->revokeTokens($userId, 'password_reset');
            $this->audit('password_changed', $userId, (string)$user['email'], 'succeeded', $context);
            $this->repository->commit();
        } catch (Throwable $error) {
            $this->repository->rollBack();
            throw $error;
        }
        $this->sendPasswordChangedEmail((string)$user['email'], (string)$user['name']);
    }

    public function startGoogleLink(int $userId, array $context = []): array
    {
        $this->assertEnabled();
        $user = $this->repository->findUserById($userId);
        if (!$user || ($user['account_status'] ?? '') !== 'active') {
            throw new AuthException('account_unavailable', 403, 'This account is unavailable.');
        }
        $rawState = $this->security->randomToken();
        $this->repository->begin();
        try {
            $this->repository->revokeTokens($userId, 'google_link_state');
            $this->repository->createToken($this->tokenValues(
                $userId,
                'google_link_state',
                $rawState,
                $this->config->googleLinkTtlSeconds,
                $context
            ));
            $this->audit('google_link_started', $userId, (string)$user['email'], 'succeeded', $context);
            $this->repository->commit();
        } catch (Throwable $error) {
            $this->repository->rollBack();
            throw $error;
        }
        return ['state' => $rawState, 'expires_in' => $this->config->googleLinkTtlSeconds];
    }

    public function completeGoogleLink(int $userId, string $rawState, array $claims, array $context = []): void
    {
        $this->assertEnabled();
        $googleId = trim((string)($claims['sub'] ?? ''));
        $googleEmail = strtolower(trim((string)($claims['email'] ?? '')));
        $domain = strtolower(substr(strrchr($googleEmail, '@') ?: '', 1));
        if (
            !$this->validRawToken($rawState)
            || $googleId === ''
            || !filter_var($googleEmail, FILTER_VALIDATE_EMAIL)
            || $domain !== $this->config->allowedDomain
        ) {
            $this->audit('google_link_failed', $userId, $googleEmail, 'failed', $context, ['reason' => 'invalid_identity']);
            throw new AuthException('google_link_failed', 422, 'Google account linking failed.');
        }
        if ($this->repository->googleIdentityExists($googleId, $userId)) {
            $this->audit('google_link_failed', $userId, $googleEmail, 'failed', $context, ['reason' => 'identity_in_use']);
            throw new AuthException('google_identity_in_use', 409, 'That Google account is already linked to another user.');
        }

        $this->repository->begin();
        try {
            $token = $this->repository->findUsableToken(
                'google_link_state',
                $this->security->tokenHash($rawState)
            );
            if (!$token || (int)$token['user_id'] !== $userId) {
                throw new AuthException('invalid_google_link_state', 422, 'Google account linking session expired.');
            }
            $this->repository->linkGoogleIdentity(
                $userId,
                $googleId,
                $googleEmail,
                trim((string)($claims['picture'] ?? ''))
            );
            $this->repository->consumeToken((int)$token['token_id']);
            $this->audit('google_link_succeeded', $userId, $googleEmail, 'succeeded', $context);
            $this->repository->commit();
        } catch (Throwable $error) {
            $this->repository->rollBack();
            throw $error;
        }
        $user = $this->repository->findUserById($userId);
        if ($user) {
            $this->sendGoogleLinkedEmail((string)$user['email'], (string)$user['name'], $googleEmail);
        }
    }

    private function consumePasswordToken(
        string $purpose,
        string $rawToken,
        string $password,
        array $context
    ): array {
        $this->assertEnabled();
        if (!$this->validRawToken($rawToken)) {
            throw new AuthException('invalid_token', 422, 'This link is invalid or has expired.');
        }
        $hash = $this->passwords->hash($password);
        $this->repository->begin();
        try {
            $token = $this->repository->findUsableToken($purpose, $this->security->tokenHash($rawToken));
            if (!$token) {
                throw new AuthException('invalid_token', 422, 'This link is invalid or has expired.');
            }
            $expectedStatus = $purpose === 'activation' ? 'pending_activation' : 'active';
            if (($token['account_status'] ?? '') !== $expectedStatus) {
                throw new AuthException('invalid_token', 422, 'This link is invalid or has expired.');
            }
            $userId = (int)$token['user_id'];
            $this->repository->setPasswordAndStatus($userId, $hash, 'active', true);
            $this->repository->consumeToken((int)$token['token_id']);
            $this->repository->revokeTokens($userId, $purpose);
            $event = $purpose === 'activation' ? 'account_activated' : 'password_reset_completed';
            $this->audit($event, $userId, '', 'succeeded', $context);
            $this->repository->commit();
        } catch (Throwable $error) {
            $this->repository->rollBack();
            throw $error;
        }
        return ['success' => true];
    }

    private function enforceLoginRateLimits(string $identifier, array $context): void
    {
        $buckets = [
            [
                'value' => 'login:ip:' . ($context['ip'] ?? 'unknown'),
                'limit' => $this->config->loginIpLimit,
            ],
            [
                'value' => 'login:identifier:' . $identifier,
                'limit' => $this->config->loginIdentifierLimit,
            ],
        ];
        foreach ($buckets as $bucket) {
            $hash = $this->security->privacyHash($bucket['value']);
            if ($hash === null) {
                throw new \RuntimeException('Local authentication privacy hashing is not configured.');
            }
            $result = $this->repository->consumeRateLimit(
                $hash,
                (int)$bucket['limit'],
                $this->config->rateLimitWindowSeconds,
                $this->config->rateLimitBlockSeconds
            );
            if (!$result['allowed']) {
                $this->audit('password_login_throttled', null, $identifier, 'throttled', $context, [
                    'retry_after' => (int)$result['retry_after'],
                ]);
                throw new AuthException('login_throttled', 429, 'Too many attempts. Try again later.');
            }
        }
    }

    private function tokenValues(
        int $userId,
        string $purpose,
        string $rawToken,
        int $ttlSeconds,
        array $context
    ): array {
        return [
            'user_id' => $userId,
            'purpose' => $purpose,
            'token_hash' => $this->security->tokenHash($rawToken),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttlSeconds),
            'created_ip_hash' => $this->security->privacyHash((string)($context['ip'] ?? '')),
            'user_agent_hash' => $this->security->privacyHash((string)($context['user_agent'] ?? '')),
        ];
    }

    private function publicUser(array $user): array
    {
        return [
            'user_id' => (int)$user['user_id'],
            'email' => (string)$user['email'],
            'username' => $user['username'] ?? null,
            'name' => (string)$user['name'],
            'picture_url' => $user['picture_url'] ?? null,
            'role_id' => (int)$user['role_id'],
            'role_name' => strtolower((string)($user['role_name'] ?? 'student')),
            'account_status' => (string)($user['account_status'] ?? 'active'),
            'auth_session_version' => (int)($user['auth_session_version'] ?? 1),
            'has_password' => !empty($user['password_hash']),
            'google_linked' => !empty($user['google_id']),
        ];
    }

    private function audit(
        string $eventName,
        ?int $subjectUserId,
        string $identifier,
        string $status,
        array $context,
        array $metadata = [],
        ?int $actorUserId = null
    ): void {
        $this->repository->audit([
            'event_name' => $eventName,
            'actor_user_id' => $actorUserId,
            'subject_user_id' => $subjectUserId,
            'identifier_hash' => $this->security->privacyHash($identifier),
            'ip_hash' => $this->security->privacyHash((string)($context['ip'] ?? '')),
            'user_agent_hash' => $this->security->privacyHash((string)($context['user_agent'] ?? '')),
            'status' => $status,
            'metadata' => $metadata ?: null,
        ]);
    }

    private function sendActivationEmail(string $email, string $name, string $rawToken): bool
    {
        $expiryHours = max(1, (int)ceil($this->config->activationTtlSeconds / 3600));
        $link = $this->config->appBaseUrl . 'activate#token=' . rawurlencode($rawToken);
        return $this->mailer->send(
            $email,
            'Activate your Kairos account',
            "Hello {$name},\n\n"
            . "An administrator created a Kairos account for you. Set your own password using this single-use link:\n\n"
            . "{$link}\n\n"
            . "This link expires in {$expiryHours} hours. Kairos administrators cannot see or set your password.\n\n"
            . $this->supportLine()
        );
    }

    private function sendResetEmail(string $email, string $name, string $rawToken): bool
    {
        $expiryMinutes = max(1, (int)ceil($this->config->resetTtlSeconds / 60));
        $link = $this->config->appBaseUrl . 'reset-password#token=' . rawurlencode($rawToken);
        return $this->mailer->send(
            $email,
            'Reset your Kairos password',
            "Hello {$name},\n\n"
            . "Use this single-use link to reset your Kairos password:\n\n"
            . "{$link}\n\n"
            . "This link expires in {$expiryMinutes} minutes. If you did not request this, you can ignore this email.\n\n"
            . $this->supportLine()
        );
    }

    private function sendPasswordChangedEmail(string $email, string $name): void
    {
        $this->mailer->send(
            $email,
            'Your Kairos password was changed',
            "Hello {$name},\n\nYour Kairos password was changed. If this was not you, contact support immediately.\n\n"
            . $this->supportLine()
        );
    }

    private function sendGoogleLinkedEmail(string $email, string $name, string $googleEmail): void
    {
        $this->mailer->send(
            $email,
            'Google account linked to Kairos',
            "Hello {$name},\n\nThe Google account {$googleEmail} was linked to your Kairos account.\n"
            . "You can now use either login method. If this was not you, contact support immediately.\n\n"
            . $this->supportLine()
        );
    }

    private function supportLine(): string
    {
        $support = trim((string)\env('SUPPORT_EMAIL', ''));
        return $support !== ''
            ? 'Need help? Contact ' . $support . '.'
            : 'Need help? Contact your Kairos administrator.';
    }

    private function normalizeIdentifier(string $identifier): string
    {
        $identifier = strtolower(trim($identifier));
        return strlen($identifier) <= 255 ? $identifier : '';
    }

    private function validRawToken(string $token): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9_-]{40,64}$/', $token);
    }

    private function invalidCredentials(): AuthException
    {
        return new AuthException('invalid_credentials', 401, 'Invalid username/email or password.');
    }

    private function isFutureDate(mixed $value): bool
    {
        if (!is_string($value) || $value === '') {
            return false;
        }
        $timestamp = strtotime($value . ' UTC');
        return $timestamp !== false && $timestamp > time();
    }

    private function roleRank(string $role): int
    {
        return match ($role) {
            'student' => 10,
            'ta' => 20,
            'manager' => 30,
            'admin' => 40,
            default => 0,
        };
    }

    private function assertEnabled(): void
    {
        if (!$this->config->enabled) {
            throw new AuthException('local_auth_disabled', 503, 'Password login is temporarily unavailable.');
        }
    }
}
