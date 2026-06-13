<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/public/api/lms/resources/_embed.php';
require_once dirname(__DIR__, 2) . '/public/api/lms/lessons/_sanitize.php';

$failed = [];

$cases = [
    [
        'name' => 'youtube watch uses privacy enhanced embed and start time',
        'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=1m5s',
        'provider' => 'youtube',
        'embed' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?start=65',
    ],
    [
        'name' => 'youtube short link normalizes',
        'url' => 'https://youtu.be/dQw4w9WgXcQ',
        'provider' => 'youtube',
        'embed' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
    ],
    [
        'name' => 'vimeo normalizes',
        'url' => 'https://vimeo.com/123456789',
        'provider' => 'vimeo',
        'embed' => 'https://player.vimeo.com/video/123456789',
    ],
    [
        'name' => 'drive normalizes',
        'url' => 'https://drive.google.com/file/d/abcDEF_123/view?usp=sharing',
        'provider' => 'google_drive',
        'embed' => 'https://drive.google.com/file/d/abcDEF_123/preview',
    ],
    [
        'name' => 'slides normalize',
        'url' => 'https://docs.google.com/presentation/d/slide_ID/edit',
        'provider' => 'google_slides',
        'embed' => 'https://docs.google.com/presentation/d/slide_ID/embed?start=false&loop=false',
    ],
    [
        'name' => 'office document normalizes',
        'url' => 'https://files.example.edu/course/outline.docx',
        'provider' => 'office',
        'embed' => 'https://view.officeapps.live.com/op/embed.aspx?src=https%3A%2F%2Ffiles.example.edu%2Fcourse%2Foutline.docx',
    ],
];

foreach ($cases as $case) {
    $descriptor = lms_external_embed_descriptor($case['url']);
    if (($descriptor['provider'] ?? null) !== $case['provider']) {
        $failed[] = "FAIL [{$case['name']}]: provider mismatch";
    }
    if (($descriptor['embed_url'] ?? null) !== $case['embed']) {
        $failed[] = "FAIL [{$case['name']}]: embed URL mismatch";
    }
}

foreach ([
    'http://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'https://example.com/arbitrary-page',
    'javascript:alert(1)',
] as $unsafeUrl) {
    if (lms_external_embed_descriptor($unsafeUrl) !== null) {
        $failed[] = "unsupported URL was embeddable: {$unsafeUrl}";
    }
}

if (class_exists(DOMDocument::class)) {
    $sanitized = lms_sanitize_lesson_html(
        '<iframe src="https://www.youtube.com/watch?v=dQw4w9WgXcQ"'
        . ' sandbox="allow-scripts allow-same-origin" allow="autoplay; clipboard-write"></iframe>'
    );
    foreach (['youtube-nocookie.com/embed/dQw4w9WgXcQ', 'loading="lazy"', 'title="YouTube video"'] as $needle) {
        if (!str_contains($sanitized, $needle)) {
            $failed[] = "lesson sanitizer missing {$needle}";
        }
    }
    if (str_contains($sanitized, 'sandbox=') || str_contains($sanitized, 'clipboard-write') || str_contains($sanitized, 'autoplay')) {
        $failed[] = 'lesson sanitizer retained an unsafe or unnecessary iframe permission';
    }
}

$root = dirname(__DIR__, 2);
$core = (string)file_get_contents($root . '/public/js/lms-core.js');
$viewer = (string)file_get_contents($root . '/public/js/resource-viewer.js');
$template = (string)file_get_contents($root . '/templates/pages/resource-viewer.html');

foreach (['youtube-nocookie.com', 'getEmbedDescriptor', "sandbox: 'allow-same-origin'", 'toVimeoEmbedUrl'] as $needle) {
    if (!str_contains($core, $needle)) {
        $failed[] = "frontend embed policy missing {$needle}";
    }
}
if (preg_match('/sandbox[^\\n]+allow-scripts[^\\n]+allow-same-origin|sandbox[^\\n]+allow-same-origin[^\\n]+allow-scripts/', $viewer . $template) === 1) {
    $failed[] = 'resource viewer contains the unsafe allow-scripts plus allow-same-origin sandbox combination';
}
foreach (['previewFallbackLink', 'loading="lazy"', 'referrerpolicy="strict-origin-when-cross-origin"'] as $needle) {
    if (!str_contains($viewer . $template, $needle)) {
        $failed[] = "resource viewer missing {$needle}";
    }
}

if ($failed !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failed) . PHP_EOL);
    exit(1);
}

echo 'resource embed policy tests passed' . PHP_EOL;
