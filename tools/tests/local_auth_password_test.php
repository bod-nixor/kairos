<?php
declare(strict_types=1);

$GLOBALS['kairos_auth_test_env'] = [];
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $GLOBALS['kairos_auth_test_env'])
            ? $GLOBALS['kairos_auth_test_env'][$key]
            : $default;
    }
}

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
$assert(is_string($olderHash) && password_verify($password, $olderHash), 'older Argon2id hash must still verify');
$assert(is_string($olderHash) && $manager->needsRehash($olderHash), 'weaker Argon2id parameters must trigger rehash');

try {
    $manager->hash('password1234');
    $failed[] = 'known-bad password should be rejected';
} catch (AuthException $error) {
    $assert($error->errorCode === 'weak_password', 'weak password should return stable code');
}

$baseEnvironment = [
    'LOCAL_AUTH_ENABLED' => true,
    'AUTH_PRIVACY_HASH_SECRET' => '8be41c9f0a7352d6e149bc8037f25ad94c708e1b6f325ad907c4e183b5d9a260',
    'PUBLIC_APP_ORIGIN' => 'https://kairos.example',
    'APP_BASE_PATH' => '/signoff/',
    'ALLOWED_DOMAIN' => 'nixorcollege.edu.pk',
];
$GLOBALS['kairos_auth_test_env'] = $baseEnvironment;
$environmentConfig = AuthConfig::fromEnvironment();
$assert($environmentConfig->allowedDomain === 'nixorcollege.edu.pk', 'configured auth domain must load');

$GLOBALS['kairos_auth_test_env'] = $baseEnvironment;
unset($GLOBALS['kairos_auth_test_env']['ALLOWED_DOMAIN']);
try {
    AuthConfig::fromEnvironment();
    $failed[] = 'enabled local auth should reject a missing allowed domain';
} catch (RuntimeException $error) {
    $assert(str_contains($error->getMessage(), 'ALLOWED_DOMAIN'), 'missing domain error must name ALLOWED_DOMAIN');
}

foreach ([
    'AUTH_LOGIN_IP_LIMIT',
    'AUTH_LOGIN_IDENTIFIER_LIMIT',
    'LOCAL_AUTH_RATE_LIMIT_WINDOW_SECONDS',
    'LOCAL_AUTH_RATE_LIMIT_BLOCK_SECONDS',
    'AUTH_ACCOUNT_FAILURE_THRESHOLD',
    'AUTH_ACCOUNT_LOCK_SECONDS',
] as $key) {
    $GLOBALS['kairos_auth_test_env'] = $baseEnvironment;
    $GLOBALS['kairos_auth_test_env'][$key] = 0;
    try {
        AuthConfig::fromEnvironment();
        $failed[] = "{$key} should reject zero";
    } catch (RuntimeException $error) {
        $assert(str_contains($error->getMessage(), $key), "{$key} error must name the invalid setting");
    }
}

if ($failed) {
    fwrite(STDERR, implode(PHP_EOL, $failed) . PHP_EOL);
    exit(1);
}

echo "local auth password tests passed" . PHP_EOL;
