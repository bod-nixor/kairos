<?php
declare(strict_types=1);

namespace Kairos\Auth;

interface AuthRepository
{
    public function begin(): void;
    public function commit(): void;
    public function rollBack(): void;
    public function findUserByIdentifier(string $identifier): ?array;
    public function findUserById(int $userId): ?array;
    public function findUserByGoogleId(string $googleId): ?array;
    public function emailExists(string $email, ?int $exceptUserId = null): bool;
    public function usernameExists(string $username, ?int $exceptUserId = null): bool;
    public function googleIdentityExists(string $googleId, ?int $exceptUserId = null): bool;
    public function roleId(string $roleName): ?int;
    public function createLocalUser(array $values): int;
    public function createGoogleUser(array $values): int;
    public function updateGoogleProfile(int $userId, array $values): void;
    public function markSuccessfulLogin(int $userId, ?string $replacementHash = null): void;
    public function recordFailedLogin(int $userId, int $threshold, int $lockSeconds): ?string;
    public function setPasswordAndStatus(int $userId, string $passwordHash, string $status, bool $incrementSessionVersion): void;
    public function createToken(array $values): int;
    public function revokeTokens(int $userId, string $purpose): void;
    public function findUsableToken(string $purpose, string $tokenHash): ?array;
    public function consumeToken(int $tokenId): void;
    public function consumeRateLimit(string $bucketHash, int $limit, int $windowSeconds, int $blockSeconds): array;
    public function linkGoogleIdentity(int $userId, string $googleId, string $googleEmail, string $pictureUrl): void;
    public function addCourseAssignment(int $userId, int $courseId, string $courseRole): void;
    public function listPendingLocalAccounts(): array;
    public function audit(array $event): void;
}
