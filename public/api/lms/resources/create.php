<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';

function lms_drive_preview_url_from_url(string $url): string
{
    if (!preg_match('/^https?:\/\//i', $url)) {
        return $url;
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
        return $url;
    }

    $host = strtolower((string)($parts['host'] ?? ''));
    $host = preg_replace('/^www\./', '', $host);
    if ($host !== 'drive.google.com') {
        return $url;
    }

    $path = (string)($parts['path'] ?? '');
    if (preg_match('#/file/d/([^/]+)#', $path, $m)) {
        return 'https://drive.google.com/file/d/' . $m[1] . '/preview';
    }

    parse_str((string)($parts['query'] ?? ''), $query);
    $fileId = (string)($query['id'] ?? '');
    if ($fileId !== '') {
        return 'https://drive.google.com/file/d/' . rawurlencode($fileId) . '/preview';
    }

    return $url;
}

$user = lms_require_roles(['manager','admin']);
$in = lms_json_input();

$courseId = (int)($in['course_id'] ?? 0);
$title = trim((string)($in['title'] ?? ''));
$type = strtolower(trim((string)($in['type'] ?? 'file')));
$url = trim((string)($in['url'] ?? ''));

if ($courseId <= 0 || $title === '' || $url === '') {
    lms_error('validation_error', 'course_id, title, type, and url are required', 422);
}

if (!preg_match('/^https?:\/\//i', $url)) {
    lms_error('validation_error', 'url must be a valid http(s) URL', 422);
}

$typeMap = [
    'pdf' => 'file',
    'file' => 'file',
    'video' => 'video',
    'link' => 'link',
    'embed' => 'embed',
];

if (!isset($typeMap[$type])) {
    lms_error('validation_error', 'Unsupported resource type', 422);
}

lms_course_access($user, $courseId);

$previewUrl = $type === 'pdf' ? lms_drive_preview_url_from_url($url) : $url;
$shareWarning = null;
if ($type === 'pdf' && str_contains($url, 'drive.google.com') && !str_contains($previewUrl, '/preview')) {
    $shareWarning = 'Drive link could not be normalized to preview URL. Ensure sharing settings allow viewers.';
}

$pdo = db();
$stmt = $pdo->prepare("INSERT INTO lms_resources (course_id,title,resource_type,drive_preview_url,access_scope,metadata_json,created_by) VALUES (:course_id,:title,:resource_type,:url,'course',:metadata,:created_by)");
$stmt->execute([
    ':course_id' => $courseId,
    ':title' => $title,
    ':resource_type' => $typeMap[$type],
    ':url' => $previewUrl,
    ':metadata' => json_encode(['url' => $url, 'preview_url' => $previewUrl, 'share_warning' => $shareWarning], JSON_THROW_ON_ERROR),
    ':created_by' => (int)$user['user_id'],
]);

lms_ok([
    'resource_id' => (int)$pdo->lastInsertId(),
    'course_id' => $courseId,
    'title' => $title,
    'type' => $type,
    'url' => $url,
    'preview_url' => $previewUrl,
    'share_warning' => $shareWarning,
]);
