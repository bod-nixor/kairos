<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';
require_once __DIR__ . '/_sanitize.php';

$user = lms_require_roles(['manager','admin']);
$in = lms_json_input();

$sectionId = (int)($in['section_id'] ?? 0);
$courseId = (int)($in['course_id'] ?? 0);
$title = trim((string)($in['title'] ?? ''));
$htmlContent = lms_sanitize_lesson_html((string)($in['html_content'] ?? ''));

if ($sectionId <= 0 || $courseId <= 0 || $title === '') {
    lms_error('validation_error','section_id, course_id, title required',422);
}

$pdo = db();
$pdo->prepare('INSERT INTO lms_lessons (section_id,course_id,title,summary,html_content,position,requires_previous,created_by) VALUES (:s,:c,:t,:m,:h,:p,:r,:u)')
    ->execute([
        ':s' => $sectionId,
        ':c' => $courseId,
        ':t' => $title,
        ':m' => $in['summary'] ?? null,
        ':h' => $htmlContent,
        ':p' => (int)($in['position'] ?? 0),
        ':r' => !empty($in['requires_previous']) ? 1 : 0,
        ':u' => (int)$user['user_id']
    ]);

lms_ok(['lesson_id' => (int)$pdo->lastInsertId()]);
