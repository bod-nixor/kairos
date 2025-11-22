<?php
declare(strict_types=1);

if (!function_exists('app_base_path')) {
    /**
     * Resolve a path relative to the project root.
     */
    function app_base_path(string ...$segments): string
    {
        $base = dirname(__DIR__);
        foreach ($segments as $segment) {
            $base .= DIRECTORY_SEPARATOR . ltrim($segment, DIRECTORY_SEPARATOR);
        }
        return $base;
    }
}

if (!function_exists('loadEnv')) {
    /**
     * Load environment variables from a .env file if present. The parser is intentionally simple and
     * supports KEY=VALUE pairs with optional single/double quotes.
     */
    function loadEnv(?string $file = null): void
    {
        static $loaded = [];

        $path = $file ?? app_base_path('.env');
        if (isset($loaded[$path]) || !is_file($path) || !is_readable($path)) {
            $loaded[$path] = true;
            return;
        }

        $contents = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($contents === false) {
            $loaded[$path] = true;
            return;
        }

        foreach ($contents as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (strpos($line, '=') === false) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (stripos($key, 'export ') === 0) {
                $key = trim(substr($key, 7));
            }

            if ($key === '') {
                continue;
            }

            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $quote = $value[0];
                if (substr($value, -1) === $quote) {
                    $value = substr($value, 1, -1);
                }
                $value = str_replace('\\' . $quote, $quote, $value);
            }

            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
            }
            if (!array_key_exists($key, $_SERVER)) {
                $_SERVER[$key] = $value;
            }
            if (getenv($key) === false) {
                putenv($key . '=' . $value);
            }
        }

        $loaded[$path] = true;
    }
}

if (!function_exists('env')) {
    /**
     * Retrieve an environment variable with optional default and casting support.
     *
     * @param mixed $default
     * @return mixed
     */
    function env(string $key, $default = null)
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }

        if (!is_string($value)) {
            return $value;
        }

        $normalized = strtolower($value);
        if (in_array($normalized, ['true', '(true)'], true)) {
            return true;
        }
        if (in_array($normalized, ['false', '(false)'], true)) {
            return false;
        }
        if (in_array($normalized, ['null', '(null)'], true)) {
            return null;
        }

        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float)$value : (int)$value;
        }

        return $value;
    }
}

loadEnv();

$timezone = env('APP_TIMEZONE', 'UTC');
if (is_string($timezone) && $timezone !== '') {
    date_default_timezone_set($timezone);
}

if (function_exists('mb_internal_encoding')) {
    @mb_internal_encoding('UTF-8');
}

error_reporting(E_ALL);
$debug = (bool)env('APP_DEBUG', false);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');

if (!defined('DEFAULT_ROLE_NAME')) {
    $defaultRole = env('DEFAULT_ROLE_NAME', 'student');
    if (!is_string($defaultRole) || $defaultRole === '') {
        $defaultRole = 'student';
    }
    define('DEFAULT_ROLE_NAME', $defaultRole);
}

if (!defined('ALLOWED_DOMAIN')) {
    $allowedDomain = env('ALLOWED_DOMAIN');
    if (!is_string($allowedDomain)) {
        $allowedDomain = '';
    }

    $allowedDomain = ltrim($allowedDomain, '@');
    define('ALLOWED_DOMAIN', $allowedDomain);
}

if (!function_exists('db')) {
    function db(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }

        $dsn = env('DB_DSN');
        $username = env('DB_USERNAME', '');
        $password = env('DB_PASSWORD', '');

        if (!is_string($dsn) || $dsn === '') {
            $driver = env('DB_DRIVER', 'mysql');
            $host = env('DB_HOST', '127.0.0.1');
            $port = env('DB_PORT');
            $database = env('DB_DATABASE');
            $charset = env('DB_CHARSET', 'utf8mb4');

            if (!is_string($database) || $database === '') {
                throw new RuntimeException('Database name must be configured via DB_DATABASE or DB_DSN.');
            }

            $dsn = sprintf(
                '%s:host=%s;%sdbname=%s;charset=%s',
                $driver,
                $host,
                $port ? 'port=' . (int)$port . ';' : '',
                $database,
                $charset
            );
        }

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $persistent = env('DB_PERSISTENT', false);
        if ($persistent !== null) {
            $options[PDO::ATTR_PERSISTENT] = (bool)$persistent;
        }

        if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
            $options[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = false;
        }

        try {
            $pdo = new PDO((string)$dsn, (string)$username, (string)$password, $options);
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to connect to the database.', 0, $e);
        }

        return $pdo;
    }
}
