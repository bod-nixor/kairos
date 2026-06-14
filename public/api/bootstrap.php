<?php
declare(strict_types=1);

// Global exception handler: ensures API always returns structured JSON, never empty 500.
set_exception_handler(function (Throwable $e): void {
    $isApi = (
        stripos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false ||
        (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'json') !== false)
    );
    if ($isApi) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        $logEntry = json_encode([
            'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? uniqid('req_', true),
            'action' => 'uncaught_exception',
            'status' => 'error',
            'user_id' => $_SESSION['user']['user_id'] ?? null,
            'course_id' => $_REQUEST['course_id'] ?? null,
            'exception_class' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        error_log('[kairos] Structured Error Log: ' . $logEntry);
        echo json_encode([
            'ok' => false,
            'error' => [
                'code' => 'internal_error',
                'message' => 'An internal error occurred. Please try again or contact support.',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        $logEntry = json_encode([
            'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? uniqid('req_', true),
            'action' => 'uncaught_exception',
            'status' => 'error',
            'user_id' => $_SESSION['user']['user_id'] ?? null,
            'course_id' => $_REQUEST['course_id'] ?? null,
            'exception_class' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        error_log('[kairos] Structured Error Log: ' . $logEntry);
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo 'Internal Server Error';
    }
    exit(1);
});

require_once dirname(__DIR__, 2) . '/config/app.php';

function kairos_bool_env(string $key, bool $default = false): bool
{
    $value = env($key, null);
    if ($value === null || $value === '') {
        return $default;
    }
    if (is_bool($value)) {
        return $value;
    }
    return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
}

function kairos_is_https_request(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (!kairos_bool_env('TRUST_PROXY_HEADERS', false)) {
        return false;
    }
    $proto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $ssl = strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''));
    return $proto === 'https' || $ssl === 'on';
}

function kairos_request_host_origin(): ?string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || preg_match('/[\s\/\\\\]/', $host)) {
        return null;
    }
    return (kairos_is_https_request() ? 'https://' : 'http://') . strtolower($host);
}

function kairos_allowed_origins(): array
{
    $origins = [];
    foreach (['APP_ORIGIN', 'PUBLIC_APP_ORIGIN'] as $key) {
        $value = env($key, '');
        if (is_string($value) && trim($value) !== '') {
            $origins[] = trim($value);
        }
    }
    $configured = env('CORS_ALLOWED_ORIGINS', '');
    if (is_string($configured) && trim($configured) !== '') {
        foreach (explode(',', $configured) as $origin) {
            $origin = trim($origin);
            if ($origin !== '') {
                $origins[] = $origin;
            }
        }
    }
    $current = kairos_request_host_origin();
    if ($current !== null) {
        $origins[] = $current;
    }
    return array_values(array_unique(array_map('kairos_normalize_origin', $origins)));
}

function kairos_normalize_origin(string $origin): string
{
    $parts = parse_url(trim($origin));
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }
    $scheme = strtolower((string)$parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return '';
    }
    $host = strtolower((string)$parts['host']);
    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    return $scheme . '://' . $host . $port;
}

function kairos_origin_allowed(?string $origin): bool
{
    if ($origin === null || trim($origin) === '') {
        return !kairos_bool_env('CSRF_REQUIRE_ORIGIN', false);
    }
    $normalized = kairos_normalize_origin($origin);
    return $normalized !== '' && in_array($normalized, kairos_allowed_origins(), true);
}

function kairos_request_origin(): ?string
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if (is_string($origin) && $origin !== '') {
        return $origin;
    }
    $referer = $_SERVER['HTTP_REFERER'] ?? null;
    if (is_string($referer) && $referer !== '') {
        return $referer;
    }
    return null;
}

function kairos_sanitize_samesite(string $value): string
{
    $normalized = strtolower(trim($value));
    if ($normalized === 'strict') {
        return 'Strict';
    }
    if ($normalized === 'none') {
        return 'None';
    }
    return 'Lax';
}

function kairos_content_type_allowed(?string $contentType): bool
{
    if ($contentType === null || trim($contentType) === '') {
        return true;
    }
    $type = strtolower(trim(explode(';', $contentType, 2)[0]));
    return in_array($type, [
        'application/json',
        'application/x-www-form-urlencoded',
        'multipart/form-data',
    ], true);
}

function kairos_apply_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'; base-uri 'none'");
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

function kairos_apply_cors_policy(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if (is_string($origin) && $origin !== '' && kairos_origin_allowed($origin)) {
        $normalized = kairos_normalize_origin($origin);
        header('Access-Control-Allow-Origin: ' . $normalized);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Accept, Content-Type, X-Requested-With, X-CSRF-Token');
        header('Vary: Origin');
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        if (!is_string($origin) || $origin === '' || !kairos_origin_allowed($origin)) {
            http_response_code(403);
            exit;
        }
        http_response_code(204);
        exit;
    }
}

function kairos_enforce_api_request_policy(): void
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return;
    }

    $origin = kairos_request_origin();
    if (!kairos_origin_allowed($origin)) {
        json_out(['error' => 'csrf_rejected', 'message' => 'cross-origin state change rejected'], 403);
    }

    if (!kairos_content_type_allowed($_SERVER['CONTENT_TYPE'] ?? null)) {
        json_out(['error' => 'unsupported_media_type', 'message' => 'unsupported content type'], 415);
    }

    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
    if ($contentLength > 0) {
        $isMultipart = stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') !== false;
        $limit = $isMultipart
            ? (int)env('UPLOAD_MAX_BYTES', 25 * 1024 * 1024)
            : (int)env('API_MAX_BODY_BYTES', 1024 * 1024);
        if ($limit > 0 && $contentLength > $limit) {
            json_out(['error' => 'payload_too_large', 'message' => 'request body exceeds configured limit'], 413);
        }
    }
}

function kairos_client_ip(): string
{
    if (kairos_bool_env('TRUST_PROXY_HEADERS', false)) {
        $forwarded = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded, 2)[0]);
            if ($first !== '') {
                return $first;
            }
        }
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function kairos_rate_limit(string $bucket, int $limit, int $windowSeconds): bool
{
    if ($limit <= 0 || $windowSeconds <= 0) {
        return true;
    }
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kairos-rate-limits';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return true;
    }
    $key = hash('sha256', $bucket);
    $file = $dir . DIRECTORY_SEPARATOR . $key . '.json';
    $now = time();
    $fp = @fopen($file, 'c+');
    if (!is_resource($fp)) {
        return true;
    }

    $locked = false;
    try {
        if (!flock($fp, LOCK_EX)) {
            return true;
        }
        $locked = true;

        rewind($fp);
        $raw = stream_get_contents($fp);
        $decoded = is_string($raw) && trim($raw) !== ''
            ? json_decode($raw, true)
            : null;

        $state = ['window_start' => $now, 'count' => 0];
        if (is_array($decoded)) {
            $state = [
                'window_start' => isset($decoded['window_start']) ? (int)$decoded['window_start'] : $now,
                'count' => isset($decoded['count']) ? max(0, (int)$decoded['count']) : 0,
            ];
        }

        if (($now - (int)$state['window_start']) >= $windowSeconds) {
            $state = ['window_start' => $now, 'count' => 0];
        }
        $state['count'] = (int)$state['count'] + 1;

        $encoded = json_encode($state);
        if (is_string($encoded)) {
            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, $encoded);
            fflush($fp);
        }

        return (int)$state['count'] <= $limit;
    } finally {
        if ($locked) {
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
}

$secure = kairos_is_https_request();
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
if (PHP_VERSION_ID < 80400) {
    ini_set('session.sid_length', '48');
    ini_set('session.sid_bits_per_character', '6');
}

$secureCookieSetting = env('SESSION_COOKIE_SECURE', null);
if ($secureCookieSetting === null || $secureCookieSetting === '') {
    $secureCookie = $secure || strtolower((string)env('APP_ENV', 'production')) === 'production';
} else {
    $secureCookie = (bool)$secureCookieSetting;
}

$sameSite = kairos_sanitize_samesite((string)env('SESSION_COOKIE_SAMESITE', 'Lax'));
if ($sameSite === 'None' && !$secureCookie) {
    $sameSite = 'Lax';
}

$cookieParams = [
    'lifetime' => 0,
    'path' => (string) (env('SESSION_COOKIE_PATH', '/')),
    'secure' => $secureCookie,
    'httponly' => true,
    'samesite' => $sameSite,
];

$cookieDomain = env('SESSION_COOKIE_DOMAIN');
if (is_string($cookieDomain) && $cookieDomain !== '') {
    $cookieParams['domain'] = $cookieDomain;
}

session_set_cookie_params($cookieParams);

$sessionName = env('SESSION_COOKIE_NAME', 'kairos_session');
if (is_string($sessionName) && $sessionName !== '') {
    session_name($sessionName);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header_remove('Cross-Origin-Opener-Policy');
header_remove('Cross-Origin-Embedder-Policy');
header_remove('Cross-Origin-Resource-Policy');
kairos_apply_security_headers();

function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    try {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        $fallback = ['error' => 'json_encode_failure'];
        if ($status < 500) {
            $status = 500;
            http_response_code($status);
        }
        $json = json_encode($fallback, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    echo $json;
    exit;
}

function kairos_json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    try {
        $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        json_out(['error' => 'invalid_json', 'message' => 'Malformed JSON request body'], 400);
    }
    if (!$decoded instanceof stdClass) {
        json_out(['error' => 'invalid_json', 'message' => 'JSON request body must be an object'], 400);
    }
    $decodedArray = json_decode($raw, true);
    return is_array($decodedArray) ? $decodedArray : [];
}

kairos_apply_cors_policy();
kairos_enforce_api_request_policy();

function require_login(): array
{
    if (empty($_SESSION['user']) || !is_array($_SESSION['user'])) {
        json_out(['error' => 'unauthenticated'], 401);
    }

    $sessionUser = $_SESSION['user'];
    $userId = isset($sessionUser['user_id']) ? (int)$sessionUser['user_id'] : 0;
    if ($userId <= 0) {
        json_out(['error' => 'unauthenticated'], 401);
    }

    static $refreshed = [];
    if (!isset($refreshed[$userId])) {
        $stmt = db()->prepare(
            'SELECT u.user_id, u.username, u.email, u.name, u.picture_url, u.role_id, u.updated_at,
                    COALESCE(r.name, :fallback_role) AS role_name, u.is_active,
                    u.account_status, u.auth_session_version,
                    CASE WHEN u.password_hash IS NULL THEN 0 ELSE 1 END AS has_password,
                    CASE WHEN u.google_id IS NULL THEN 0 ELSE 1 END AS google_linked
             FROM users u
             LEFT JOIN roles r ON r.role_id = u.role_id
             WHERE u.user_id = :uid
             LIMIT 1'
        );
        $stmt->execute([
            ':uid' => $userId,
            ':fallback_role' => DEFAULT_ROLE_NAME,
        ]);
        $fresh = $stmt->fetch(PDO::FETCH_ASSOC);
        $storedSessionVersion = (int)($_SESSION['auth_session_version'] ?? 1);
        $currentSessionVersion = is_array($fresh)
            ? (int)($fresh['auth_session_version'] ?? 1)
            : 0;
        if (
            !$fresh
            || (isset($fresh['is_active']) && (int)$fresh['is_active'] !== 1)
            || strtolower((string)($fresh['account_status'] ?? 'active')) !== 'active'
            || $storedSessionVersion !== $currentSessionVersion
        ) {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
            json_out(['error' => 'unauthenticated'], 401);
        }
        unset($fresh['is_active'], $fresh['auth_session_version']);
        $fresh['has_password'] = (bool)$fresh['has_password'];
        $fresh['google_linked'] = (bool)$fresh['google_linked'];
        $_SESSION['user'] = $fresh;
        $_SESSION['auth_session_version'] = $currentSessionVersion;
        $refreshed[$userId] = true;
    }

    return $_SESSION['user'];
}
