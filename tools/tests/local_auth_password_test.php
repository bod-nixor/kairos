<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/auth/AuthException.php';
require_once dirname(__DIR__, 2) . '/src/auth/AuthConfig.php';
require_once dirname(__DIR__, 2) . '/src/auth/PasswordManager.php';

use Kairos\Auth\AuthConfig;
use Kairos\Auth\AuthException;
use Kairos\Auth\PasswordManager;

$config = new AuthConfig(
    true,
    19456,
    2,
    1,
    12,
    1024,
    86400,
    3600,
    600,
    30,
    10,
    900,
    900,
    8,
    900,
    str_repeat('p', 32),
    'https://kairos.example/signoff/',
    'nixorcollege.edu.pk'
);
$manager = new PasswordManager($config);
$failed = [];
$assert = static function (bool $condition, string $message) use (&$failed): void {
    if (!$condition) {
        $failed[] = $message;
    }
};

$password = 'correct horse battery staple';
$hash = $manager->hash($password);
$assert(str_starts_with($hash, '$argon2id$'), 'password hash must use Argon2id');
$assert($manager->verify($password, $hash), 'correct password must verify');
$assert(!$manager->verify('wrong password value', $hash), 'wrong password must fail');

$olderHash = password_hash($password, PASSWORD_ARGON2ID, [
    'memory_cost' => 8192,
    'time_cost' => 2,
    'threads' => 1,
]);
$assert(is_string($olderHash) && $manager->needsRehash($olderHash), 'weaker Argon2id parameters must trigger rehash');

try {
    $manager->hash('password1234');
    $failed[] = 'known-bad password should be rejected';
} catch (AuthException $error) {
    $assert($error->errorCode === 'weak_password', 'weak password should return stable code');
}

if ($failed) {
    fwrite(STDERR, implode(PHP_EOL, $failed) . PHP_EOL);
    exit(1);
}

echo "local auth password tests passed" . PHP_EOL;
