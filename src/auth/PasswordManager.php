<?php
declare(strict_types=1);

namespace Kairos\Auth;

final class PasswordManager
{
    private const DENYLIST = [
        '123456789012',
        'letmeinletmein',
        'password1234',
        'qwertyqwerty',
        'adminadmin123',
        'nixorcollege',
        'changeme1234',
    ];

    public function __construct(private readonly AuthConfig $config)
    {
    }

    public function validate(string $password): array
    {
        $length = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
        $errors = [];
        if ($length < $this->config->passwordMinLength) {
            $errors[] = 'Use at least ' . $this->config->passwordMinLength . ' characters.';
        }
        if ($length > $this->config->passwordMaxLength) {
            $errors[] = 'Password is too long.';
        }
        if (in_array(strtolower(trim($password)), self::DENYLIST, true)) {
            $errors[] = 'Choose a less common password or passphrase.';
        }
        return $errors;
    }

    public function hash(string $password): string
    {
        $errors = $this->validate($password);
        if ($errors) {
            throw new AuthException('weak_password', 422, $errors[0]);
        }
        $hash = password_hash($password, PASSWORD_ARGON2ID, $this->options());
        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('Argon2id password hashing failed.');
        }
        return $hash;
    }

    public function verify(string $password, string $hash): bool
    {
        return $hash !== '' && password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, $this->options());
    }

    public function options(): array
    {
        return [
            'memory_cost' => $this->config->argonMemoryCost,
            'time_cost' => $this->config->argonTimeCost,
            'threads' => $this->config->argonThreads,
        ];
    }
}
