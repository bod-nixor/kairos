<?php
declare(strict_types=1);

function kairos_html_pages(): array
{
    return [
        'admin' => 'admin.html',
        'analytics' => 'analytics.html',
        'assignment' => 'assignment.html',
        'assignments' => 'assignments.html',
        'course' => 'course.html',
        'grading' => 'grading.html',
        'index' => 'index.html',
        'lesson' => 'lesson.html',
        'manager' => 'manager.html',
        'modules' => 'modules.html',
        'projector' => 'projector.html',
        'quiz' => 'quiz.html',
        'quizzes' => 'quizzes.html',
        'resource-viewer' => 'resource-viewer.html',
        'room' => 'room.html',
        'settings' => 'settings.html',
        'ta' => 'ta.html',
    ];
}

function kairos_generate_csp_nonce(): string
{
    return base64_encode(random_bytes(24));
}

function kairos_build_html_csp(string $nonce): string
{
    if (!preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', $nonce)) {
        throw new InvalidArgumentException('Invalid CSP nonce.');
    }

    $scriptSources = implode(' ', [
        "'self'",
        "'nonce-{$nonce}'",
        'https://accounts.google.com',
        'https://apis.google.com',
        'https://www.gstatic.com',
    ]);

    return implode('; ', [
        "default-src 'self'",
        "script-src {$scriptSources}",
        "script-src-elem {$scriptSources}",
        "script-src-attr 'none'",
        "style-src 'self' 'unsafe-inline' https://accounts.google.com https://fonts.googleapis.com",
        "style-src-elem 'self' 'unsafe-inline' https://accounts.google.com https://fonts.googleapis.com",
        "style-src-attr 'unsafe-inline'",
        "img-src 'self' data: blob: https:",
        "font-src 'self' data: https://fonts.gstatic.com",
        "connect-src 'self' https://kairos.nixorcorporate.com wss://kairos.nixorcorporate.com https://accounts.google.com https://oauth2.googleapis.com https://www.googleapis.com",
        "media-src 'self' blob:",
        "frame-src 'self' https://accounts.google.com https://drive.google.com https://docs.google.com https://www.youtube.com https://www.youtube-nocookie.com https://player.vimeo.com https://view.officeapps.live.com",
        "worker-src 'self' blob:",
        "manifest-src 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'self'",
        'upgrade-insecure-requests',
    ]);
}

function kairos_render_html_template(string $page, string $nonce): string
{
    $pages = kairos_html_pages();
    if (!isset($pages[$page])) {
        throw new OutOfBoundsException('Unknown HTML page.');
    }

    $templatePath = dirname(__DIR__) . '/templates/pages/' . $pages[$page];
    $html = file_get_contents($templatePath);
    if ($html === false) {
        throw new RuntimeException('Unable to load HTML template.');
    }

    preg_match_all('/<script\b(?![^>]*\bsrc\s*=)[^>]*>/i', $html, $inlineScripts);
    foreach ($inlineScripts[0] as $scriptTag) {
        if (!str_contains($scriptTag, 'nonce="{{CSP_NONCE}}"')) {
            throw new RuntimeException('Inline scripts must explicitly opt in to the response nonce.');
        }
    }

    $safeNonce = htmlspecialchars($nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = str_replace('{{CSP_NONCE}}', $safeNonce, $html);

    $allowedDomain = env('ALLOWED_DOMAIN', 'example.edu');
    if (is_string($allowedDomain) && $allowedDomain !== '') {
        $safeDomain = htmlspecialchars($allowedDomain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = str_replace('@example.edu', '@' . $safeDomain, $html);
    }

    return $html;
}

function kairos_send_html_security_headers(string $nonce): void
{
    header_remove('Content-Security-Policy');
    header_remove('Cross-Origin-Embedder-Policy');
    header_remove('Cross-Origin-Resource-Policy');

    header('Content-Type: text/html; charset=utf-8');
    header('Content-Security-Policy: ' . kairos_build_html_csp($nonce));
    header('Cache-Control: private, no-store, max-age=0, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin-allow-popups');
}
