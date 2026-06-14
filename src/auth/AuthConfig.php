<?php
declare(strict_types=1);

namespace Kairos\Auth;

final class AuthConfig
{
    public function __construct(
        public readonly bool $enabled,
        public readonly int $argonMemoryCost,
        public readonly int $argonTimeCost,
        public readonly int $argonThreads,
        public readonly int $passwordMinLength,
        public readonly int $passwordMaxLength,
        public readonly int $activationTtlSeconds,
        public readonly int $resetTtlSeconds,
        public readonly int $googleLinkTtlSeconds,
        public readonly int $loginIpLimit,
        public readonly int $loginIdentifierLimit,
        public readonly int $rateLimitWindowSeconds,
        public readonly int $rateLimitBlockSeconds,
        public readonly int $accountFailureThreshold,
        public readonly int $accountLockSeconds,
        public readonly string $privacyHashSecret,
        public readonly string $appBaseUrl,
        public readonly string $allowedDomain,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $enabled = self::boolEnv('LOCAL_AUTH_ENABLED', false);
        $memory = self::intEnv('ARGON2_MEMORY_COST', 19456);
        $time = self::intEnv('ARGON2_TIME_COST', 2);
        $threads = self::intEnv('ARGON2_THREADS', 1);
        $minLength = self::intEnv('PASSWORD_MIN_LENGTH', 12);
        $maxLength = self::intEnv('PASSWORD_MAX_LENGTH', 1024);
        $privacySecret = trim((string)\env('AUTH_PRIVACY_HASH_SECRET', ''));

        if ($enabled && !defined('PASSWORD_ARGON2ID')) {
            throw new \RuntimeException('Local authentication requires PHP Argon2id support.');
        }
        if ($memory < 19456 || $memory > 1048576) {
            throw new \RuntimeException('ARGON2_MEMORY_COST must be between 19456 and 1048576 KiB.');
        }
        if ($time < 2 || $time > 10) {
            throw new \RuntimeException('ARGON2_TIME_COST must be between 2 and 10.');
        }
        if ($threads < 1 || $threads > 4) {
            throw new \RuntimeException('ARGON2_THREADS must be between 1 and 4.');
        }
        if ($minLength < 12 || $minLength > 128 || $maxLength < $minLength || $maxLength > 4096) {
            throw new \RuntimeException('Password length configuration is invalid.');
        }
        if ($enabled && strlen($privacySecret) < 32) {
            throw new \RuntimeException('AUTH_PRIVACY_HASH_SECRET must contain at least 32 characters.');
        }

        $origin = rtrim((string)\env('PUBLIC_APP_ORIGIN', \env('APP_ORIGIN', '')), '/');
        $basePath = '/' . trim((string)\env('APP_BASE_PATH', '/signoff/'), '/') . '/';
        if ($origin === '' || !filter_var($origin, FILTER_VALIDATE_URL)) {
            if ($enabled) {
                throw new \RuntimeException('PUBLIC_APP_ORIGIN or APP_ORIGIN must be configured for local authentication.');
            }
            $origin = 'https://localhost';
        }

        return new self(
            $enabled,
            $memory,
            $time,
            $threads,
            $minLength,
            $maxLength,
            self::intEnv('AUTH_ACTIVATION_TTL_SECONDS', 86400),
            self::intEnv('AUTH_RESET_TTL_SECONDS', 3600),
            self::intEnv('AUTH_GOOGLE_LINK_TTL_SECONDS', 600),
            self::intEnv('AUTH_LOGIN_IP_LIMIT', 30),
            self::intEnv('AUTH_LOGIN_IDENTIFIER_LIMIT', 10),
            self::intEnv('LOCAL_AUTH_RATE_LIMIT_WINDOW_SECONDS', 900),
            self::intEnv('LOCAL_AUTH_RATE_LIMIT_BLOCK_SECONDS', 900),
            self::intEnv('AUTH_ACCOUNT_FAILURE_THRESHOLD', 8),
            self::intEnv('AUTH_ACCOUNT_LOCK_SECONDS', 900),
            $privacySecret,
            $origin . $basePath,
            ltrim(strtolower((string)\env('ALLOWED_DOMAIN', '')), '@'),
        );
    }

    private static function intEnv(string $key, int $default): int
    {
        $value = \env($key, $default);
        return is_numeric($value) ? (int)$value : $default;
    }

    private static function boolEnv(string $key, bool $default): bool
    {
        $value = \env($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null || $value === '') {
            return $default;
        }
        return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
    }
}
