<?php
declare(strict_types=1);

namespace Kairos\Auth;

final class AuthSecurity
{
    public function __construct(private readonly AuthConfig $config)
    {
    }

    public function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function privacyHash(string $value): ?string
    {
        $value = trim(strtolower($value));
        if ($value === '' || $this->config->privacyHashSecret === '') {
            return null;
        }
        return hash_hmac('sha256', $value, $this->config->privacyHashSecret);
    }

    public function validateReturnUrl(?string $value): ?string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 2048) {
            return null;
        }
        $decoded = rawurldecode($value);
        if (str_contains($value, '\\') || str_contains($decoded, '\\')) {
            return null;
        }
        $configuredPath = (string)(parse_url($this->config->appBaseUrl, PHP_URL_PATH) ?? '');
        $basePath = '/' . trim($configuredPath, '/') . '/';
        if ($basePath === '//') {
            $basePath = '/';
        }
        if (str_starts_with($value, '//') || !str_starts_with($value, $basePath)) {
            return null;
        }
        $parts = parse_url($value);
        if (!is_array($parts) || isset($parts['scheme'], $parts['host']) || isset($parts['host'])) {
            return null;
        }
        return $value;
    }
}
