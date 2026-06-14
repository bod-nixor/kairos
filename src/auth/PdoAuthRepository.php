<?php
declare(strict_types=1);

namespace Kairos\Auth;

use PDO;
use Throwable;

final class PdoAuthRepository implements AuthRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function begin(): void
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    public function commit(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function findUserByIdentifier(string $identifier): ?array
    {
        $stmt = $this->pdo->prepare(
            $this->userSelect()
            . ' WHERE LOWER(u.email) = LOWER(:identifier)'
            . ' OR LOWER(u.username) = LOWER(:identifier)'
            . ' LIMIT 1'
        );
        $stmt->execute([':identifier' => $identifier]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findUserById(int $userId): ?array
    {
        $stmt = $this->pdo->prepare($this->userSelect() . ' WHERE u.user_id = :uid LIMIT 1');
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findUserByGoogleId(string $googleId): ?array
    {
        $stmt = $this->pdo->prepare($this->userSelect() . ' WHERE u.google_id = :google_id LIMIT 1');
        $stmt->execute([':google_id' => $googleId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function emailExists(string $email, ?int $exceptUserId = null): bool
    {
        return $this->uniqueValueExists('email', $email, $exceptUserId);
    }

    public function usernameExists(string $username, ?int $exceptUserId = null): bool
    {
        return $this->uniqueValueExists('username', $username, $exceptUserId);
    }

    public function googleIdentityExists(string $googleId, ?int $exceptUserId = null): bool
    {
        return $this->uniqueValueExists('google_id', $googleId, $exceptUserId);
    }

    public function roleId(string $roleName): ?int
    {
        $stmt = $this->pdo->prepare('SELECT role_id FROM roles WHERE LOWER(name) = LOWER(:name) LIMIT 1');
        $stmt->execute([':name' => $roleName]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (int)$value;
    }

    public function createLocalUser(array $values): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users'
            . ' (google_id, username, email, google_email, name, picture_url, password_hash,'
            . ' is_active, account_status, role_id, auth_session_version)'
            . ' VALUES'
            . ' (NULL, :username, :email, NULL, :name, NULL, NULL, 0, :status, :role_id, 1)'
        );
        $stmt->execute([
            ':username' => $values['username'],
            ':email' => $values['email'],
            ':name' => $values['name'],
            ':status' => $values['account_status'],
            ':role_id' => $values['role_id'],
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function createGoogleUser(array $values): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users'
            . ' (google_id, username, email, google_email, name, picture_url, password_hash,'
            . ' is_active, account_status, role_id, auth_session_version)'
            . ' VALUES'
            . ' (:google_id, NULL, :email, :google_email, :name, :picture_url, NULL,'
            . " 1, 'active', :role_id, 1)"
        );
        $stmt->execute([
            ':google_id' => $values['google_id'],
            ':email' => $values['email'],
            ':google_email' => $values['google_email'],
            ':name' => $values['name'],
            ':picture_url' => $values['picture_url'],
            ':role_id' => $values['role_id'],
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateGoogleProfile(int $userId, array $values): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET google_email = :google_email, name = :name, picture_url = :picture_url'
            . ' WHERE user_id = :uid'
        );
        $stmt->execute([
            ':google_email' => $values['google_email'],
            ':name' => $values['name'],
            ':picture_url' => $values['picture_url'],
            ':uid' => $userId,
        ]);
    }

    public function markSuccessfulLogin(int $userId, ?string $replacementHash = null): void
    {
        $sql = 'UPDATE users SET failed_login_count = 0, locked_until = NULL, last_login_at = UTC_TIMESTAMP()';
        $params = [':uid' => $userId];
        if ($replacementHash !== null) {
            $sql .= ', password_hash = :password_hash, password_changed_at = UTC_TIMESTAMP()';
            $params[':password_hash'] = $replacementHash;
        }
        $sql .= ' WHERE user_id = :uid';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function recordFailedLogin(int $userId, int $threshold, int $lockSeconds): ?string
    {
        $started = !$this->pdo->inTransaction();
        if ($started) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT failed_login_count FROM users WHERE user_id = :uid FOR UPDATE'
            );
            $stmt->execute([':uid' => $userId]);
            $count = (int)($stmt->fetchColumn() ?: 0) + 1;
            $lockedUntil = $count >= $threshold
                ? gmdate('Y-m-d H:i:s', time() + $lockSeconds)
                : null;
            $update = $this->pdo->prepare(
                'UPDATE users SET failed_login_count = :count, locked_until = :locked_until'
                . ' WHERE user_id = :uid'
            );
            $update->execute([
                ':count' => $count,
                ':locked_until' => $lockedUntil,
                ':uid' => $userId,
            ]);
            if ($started) {
                $this->pdo->commit();
            }
            return $lockedUntil;
        } catch (Throwable $error) {
            if ($started && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function setPasswordAndStatus(int $userId, string $passwordHash, string $status, bool $incrementSessionVersion): void
    {
        $sql = 'UPDATE users SET password_hash = :password_hash, account_status = :status,'
            . ' is_active = :is_active, password_changed_at = UTC_TIMESTAMP(),'
            . ' failed_login_count = 0, locked_until = NULL';
        if ($incrementSessionVersion) {
            $sql .= ', auth_session_version = auth_session_version + 1';
        }
        $sql .= ' WHERE user_id = :uid';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':password_hash' => $passwordHash,
            ':status' => $status,
            ':is_active' => $status === 'active' ? 1 : 0,
            ':uid' => $userId,
        ]);
    }

    public function createToken(array $values): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO auth_tokens'
            . ' (user_id, purpose, token_hash, expires_at, created_ip_hash, user_agent_hash)'
            . ' VALUES (:user_id, :purpose, :token_hash, :expires_at, :ip_hash, :ua_hash)'
        );
        $stmt->execute([
            ':user_id' => $values['user_id'],
            ':purpose' => $values['purpose'],
            ':token_hash' => $values['token_hash'],
            ':expires_at' => $values['expires_at'],
            ':ip_hash' => $values['created_ip_hash'],
            ':ua_hash' => $values['user_agent_hash'],
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function revokeTokens(int $userId, string $purpose): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE auth_tokens SET revoked_at = UTC_TIMESTAMP()'
            . ' WHERE user_id = :uid AND purpose = :purpose'
            . ' AND used_at IS NULL AND revoked_at IS NULL'
        );
        $stmt->execute([':uid' => $userId, ':purpose' => $purpose]);
    }

    public function findUsableToken(string $purpose, string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.token_id, t.user_id, t.purpose, t.expires_at, u.account_status'
            . ' FROM auth_tokens t JOIN users u ON u.user_id = t.user_id'
            . ' WHERE t.purpose = :purpose AND t.token_hash = :token_hash'
            . ' AND t.used_at IS NULL AND t.revoked_at IS NULL AND t.expires_at > UTC_TIMESTAMP()'
            . ' LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':purpose' => $purpose, ':token_hash' => $tokenHash]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function consumeToken(int $tokenId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE auth_tokens SET used_at = UTC_TIMESTAMP()'
            . ' WHERE token_id = :token_id AND used_at IS NULL AND revoked_at IS NULL'
        );
        $stmt->execute([':token_id' => $tokenId]);
        if ($stmt->rowCount() !== 1) {
            throw new AuthException('invalid_token', 422, 'This link is invalid or has already been used.');
        }
    }

    public function consumeRateLimit(string $bucketHash, int $limit, int $windowSeconds, int $blockSeconds): array
    {
        $started = !$this->pdo->inTransaction();
        if ($started) {
            $this->pdo->beginTransaction();
        }
        try {
            $select = $this->pdo->prepare(
                'SELECT window_started_at, attempt_count, blocked_until'
                . ' FROM auth_rate_limits WHERE bucket_hash = :bucket_hash FOR UPDATE'
            );
            $select->execute([':bucket_hash' => $bucketHash]);
            $row = $select->fetch(PDO::FETCH_ASSOC) ?: null;
            $now = time();
            $windowStart = $row ? strtotime((string)$row['window_started_at'] . ' UTC') : false;
            $blockedUntil = $row && $row['blocked_until'] ? strtotime((string)$row['blocked_until'] . ' UTC') : false;

            if ($blockedUntil !== false && $blockedUntil > $now) {
                if ($started) {
                    $this->pdo->commit();
                }
                return ['allowed' => false, 'retry_after' => $blockedUntil - $now];
            }

            $count = 1;
            $windowStartedAt = gmdate('Y-m-d H:i:s', $now);
            if ($row && $windowStart !== false && ($now - $windowStart) < $windowSeconds) {
                $count = (int)$row['attempt_count'] + 1;
                $windowStartedAt = gmdate('Y-m-d H:i:s', $windowStart);
            }
            $newBlockedUntil = $count > $limit
                ? gmdate('Y-m-d H:i:s', $now + $blockSeconds)
                : null;

            $upsert = $this->pdo->prepare(
                'INSERT INTO auth_rate_limits'
                . ' (bucket_hash, window_started_at, attempt_count, blocked_until)'
                . ' VALUES (:bucket_hash, :window_started_at, :attempt_count, :blocked_until)'
                . ' ON DUPLICATE KEY UPDATE window_started_at = VALUES(window_started_at),'
                . ' attempt_count = VALUES(attempt_count), blocked_until = VALUES(blocked_until)'
            );
            $upsert->execute([
                ':bucket_hash' => $bucketHash,
                ':window_started_at' => $windowStartedAt,
                ':attempt_count' => $count,
                ':blocked_until' => $newBlockedUntil,
            ]);
            if ($started) {
                $this->pdo->commit();
            }
            return [
                'allowed' => $newBlockedUntil === null,
                'retry_after' => $newBlockedUntil === null ? 0 : $blockSeconds,
            ];
        } catch (Throwable $error) {
            if ($started && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function linkGoogleIdentity(int $userId, string $googleId, string $googleEmail, string $pictureUrl): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET google_id = :google_id, google_email = :google_email,'
            . ' picture_url = CASE WHEN :picture_url = \'\' THEN picture_url ELSE :picture_url END'
            . ' WHERE user_id = :uid'
        );
        $stmt->execute([
            ':google_id' => $googleId,
            ':google_email' => $googleEmail,
            ':picture_url' => $pictureUrl,
            ':uid' => $userId,
        ]);
    }

    public function addCourseAssignment(int $userId, int $courseId, string $courseRole): void
    {
        if ($courseRole === 'student') {
            $stmt = $this->pdo->prepare(
                'INSERT INTO student_courses (user_id, course_id) VALUES (:uid, :cid)'
                . ' ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)'
            );
        } elseif ($courseRole === 'ta') {
            $stmt = $this->pdo->prepare(
                'INSERT INTO ta_courses (ta_user_id, course_id) VALUES (:uid, :cid)'
                . ' ON DUPLICATE KEY UPDATE ta_user_id = VALUES(ta_user_id)'
            );
        } elseif ($courseRole === 'manager') {
            $stmt = $this->pdo->prepare(
                'INSERT INTO manager_courses (user_id, course_id) VALUES (:uid, :cid)'
                . ' ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)'
            );
        } else {
            throw new AuthException('invalid_course_role', 422, 'Invalid course assignment role.');
        }
        $stmt->execute([':uid' => $userId, ':cid' => $courseId]);
    }

    public function listPendingLocalAccounts(): array
    {
        $stmt = $this->pdo->query(
            'SELECT u.user_id, u.username, u.email, u.name, u.account_status, u.created_at,'
            . ' r.name AS role_name'
            . ' FROM users u JOIN roles r ON r.role_id = u.role_id'
            . " WHERE u.account_status = 'pending_activation' AND u.password_hash IS NULL"
            . ' ORDER BY u.created_at DESC LIMIT 100'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function audit(array $event): void
    {
        $metadata = $event['metadata'] ?? null;
        $stmt = $this->pdo->prepare(
            'INSERT INTO auth_audit_log'
            . ' (event_name, actor_user_id, subject_user_id, identifier_hash, ip_hash,'
            . ' user_agent_hash, status, metadata_json)'
            . ' VALUES (:event_name, :actor_user_id, :subject_user_id, :identifier_hash,'
            . ' :ip_hash, :user_agent_hash, :status, :metadata_json)'
        );
        $stmt->execute([
            ':event_name' => $event['event_name'],
            ':actor_user_id' => $event['actor_user_id'] ?? null,
            ':subject_user_id' => $event['subject_user_id'] ?? null,
            ':identifier_hash' => $event['identifier_hash'] ?? null,
            ':ip_hash' => $event['ip_hash'] ?? null,
            ':user_agent_hash' => $event['user_agent_hash'] ?? null,
            ':status' => $event['status'],
            ':metadata_json' => $metadata === null
                ? null
                : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }

    private function userSelect(): string
    {
        return 'SELECT u.user_id, u.google_id, u.username, u.email, u.google_email, u.name,'
            . ' u.picture_url, u.password_hash, u.is_active, u.account_status, u.role_id,'
            . ' u.failed_login_count, u.locked_until, u.auth_session_version, u.updated_at,'
            . ' LOWER(r.name) AS role_name'
            . ' FROM users u LEFT JOIN roles r ON r.role_id = u.role_id';
    }

    private function uniqueValueExists(string $column, string $value, ?int $exceptUserId): bool
    {
        if (!in_array($column, ['email', 'username', 'google_id'], true)) {
            throw new \InvalidArgumentException('Unsupported unique column.');
        }
        $sql = "SELECT 1 FROM users WHERE LOWER($column) = LOWER(:value)";
        $params = [':value' => $value];
        if ($exceptUserId !== null) {
            $sql .= ' AND user_id <> :uid';
            $params[':uid'] = $exceptUserId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }
}
