<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/src/html_response.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    if ($method !== 'HEAD') {
        echo 'Method Not Allowed';
    }
    exit;
}

$page = $_GET['page'] ?? 'index';
if (!is_string($page) || !isset(kairos_html_pages()[$page])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    if ($method !== 'HEAD') {
        echo 'Not Found';
    }
    exit;
}

try {
    $nonce = kairos_generate_csp_nonce();
    $html = kairos_render_html_template($page, $nonce);
    kairos_send_html_security_headers($nonce);

    if ($method !== 'HEAD') {
        echo $html;
    }
} catch (Throwable $error) {
    error_log('[kairos] HTML response failed: ' . get_class($error) . ': ' . $error->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }
    if ($method !== 'HEAD') {
        echo 'Unable to load application shell.';
    }
}
