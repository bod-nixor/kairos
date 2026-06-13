<?php
declare(strict_types=1);

putenv('APP_ENV=local');
putenv('APP_DEBUG=false');
putenv('ALLOWED_DOMAIN=nixorcollege.edu.pk');
putenv('DEFAULT_ROLE_NAME=student');
putenv('GOOGLE_CLIENT_ID=test-client.apps.googleusercontent.com');
putenv('APP_ORIGIN=https://kairos.nixorcorporate.com');
putenv('CORS_ALLOWED_ORIGINS=https://staging.kairos.nixorcorporate.com');
putenv('SESSION_COOKIE_SECURE=false');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'kairos.nixorcorporate.com';
$_SERVER['HTTPS'] = 'on';

require_once dirname(__DIR__, 2) . '/public/api/bootstrap.php';
require_once dirname(__DIR__, 2) . '/public/api/lms/_common.php';
require_once dirname(__DIR__, 2) . '/public/api/lms/drive_client.php';
require_once dirname(__DIR__, 2) . '/src/html_response.php';

$failed = [];

$assert = static function (bool $condition, string $message) use (&$failed): void {
    if (!$condition) {
        $failed[] = $message;
    }
};

$assert(kairos_normalize_origin('https://Kairos.NixorCorporate.com/path') === 'https://kairos.nixorcorporate.com', 'origin normalization should drop path and lowercase host');
$assert(kairos_origin_allowed('https://kairos.nixorcorporate.com'), 'current app origin should be trusted');
$assert(kairos_origin_allowed('https://staging.kairos.nixorcorporate.com'), 'configured staging origin should be trusted');
$assert(!kairos_origin_allowed('https://evil.example.test'), 'unconfigured origin should be rejected');
$assert(kairos_content_type_allowed('application/json; charset=utf-8'), 'JSON content type should be allowed');
$assert(kairos_content_type_allowed('multipart/form-data; boundary=abc'), 'multipart content type should be allowed');
$assert(!kairos_content_type_allowed('text/plain'), 'text/plain content type should be rejected for state changes');
$assert(kairos_sanitize_samesite('Strict') === 'Strict', 'Strict SameSite should be preserved');
$assert(kairos_sanitize_samesite('unexpected') === 'Lax', 'unexpected SameSite should fall back to Lax');

$tmp = tempnam(sys_get_temp_dir(), 'kairos_pdf_');
if ($tmp === false) {
    $failed[] = 'failed to create temp upload file';
} else {
    file_put_contents($tmp, "%PDF-1.4\n% test\n");
    $meta = lms_validate_uploaded_file([
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $tmp,
        'name' => '../../Unsafe Lesson.pdf',
        'size' => filesize($tmp),
    ], 1024 * 1024);
    $assert($meta['name'] === 'Unsafe Lesson.pdf', 'upload filename should be basename-sanitized');
    $assert($meta['extension'] === 'pdf', 'upload extension should be detected');
    $assert($meta['mime_type'] === 'application/pdf', 'PDF MIME should be detected from file contents');
    @unlink($tmp);
}

$rootHtaccess = (string)file_get_contents(dirname(__DIR__, 2) . '/.htaccess');
$publicHtaccess = (string)file_get_contents(dirname(__DIR__, 2) . '/public/.htaccess');
$assert(strpos($rootHtaccess, '^(\\.git|\\.env|config|db|docs|sql|src|templates|tools|storage|logs|vendor)') !== false, 'root .htaccess should block repository internals, templates, and Composer dependencies');
$assert(strpos($publicHtaccess, '^(includes|logs)(/|$)') !== false, 'public .htaccess should block public includes/logs');
$assert(strpos($rootHtaccess, 'Content-Security-Policy') === false, 'Apache should not emit a static HTML CSP');
$assert(strpos($publicHtaccess, 'Content-Security-Policy') === false, 'public Apache rules should not emit a static HTML CSP');
$assert(strpos($rootHtaccess, 'public/html.php?page=') !== false, 'root Apache rules should route known HTML pages through PHP');
$assert(strpos($publicHtaccess, 'html.php?page=') !== false, 'public webroot rules should route known HTML pages through PHP');

$firstNonce = kairos_generate_csp_nonce();
$secondNonce = kairos_generate_csp_nonce();
$assert($firstNonce !== $secondNonce, 'CSP nonces must not be reused');
$policy = kairos_build_html_csp($firstNonce);
$renderedHtml = kairos_render_html_template('index', $firstNonce);
$assert(strpos($policy, "script-src 'self' 'nonce-{$firstNonce}'") !== false, 'script-src should contain the response nonce');
$assert(strpos($policy, "script-src-elem 'self' 'nonce-{$firstNonce}'") !== false, 'script-src-elem should contain the response nonce');
$assert(strpos($policy, "script-src-attr 'none'") !== false, 'inline script attributes should remain prohibited');
$assert(strpos($policy, 'https://accounts.google.com') !== false, 'CSP should preserve Google Sign-In compatibility');
$assert(strpos($policy, 'wss://kairos.nixorcorporate.com') !== false, 'CSP should permit the canonical secure WebSocket origin');
$assert(strpos($policy, 'static.cloudflareinsights.com') === false, 'optional Cloudflare analytics should not weaken CSP');
$assert(strpos($policy, 'play.google.com') === false, 'browser telemetry endpoints should not be allowlisted');
$assert(!preg_match('/script-src(?:-elem)?\s+[^;]*\'unsafe-inline\'/', $policy), 'inline scripts should require the response nonce');
$assert(strpos($renderedHtml, 'nonce="' . $firstNonce . '"') !== false, 'rendered inline scripts should receive the response nonce');
$assert(strpos($renderedHtml, '{{CSP_NONCE}}') === false, 'rendered HTML should not expose the nonce placeholder');

if ($failed) {
    fwrite(STDERR, implode(PHP_EOL, $failed) . PHP_EOL);
    exit(1);
}

echo "security controls tests passed" . PHP_EOL;
