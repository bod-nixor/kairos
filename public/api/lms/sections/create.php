<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';
$user = lms_require_roles(['manager', 'admin']);
$in = lms_json_input();
$courseId = (int) ($in['course_id'] ?? 0);
$title = trim((string) ($in['title'] ?? ''));
if ($courseId <= 0 || $title === '') {
    lms_error('validation_error', 'course_id and title required', 422);
}
$pdo = db();
$pos = (int) ($in['position'] ?? -1);
if ($pos < 0) {
    $stMax = $pdo->prepare('SELECT IFNULL(MAX(position), 0) FROM lms_course_sections WHERE course_id = ? AND deleted_at IS NULL');
    $stMax->execute([$courseId]);
    $pos = (int) $stMax->fetchColumn() + 1;
}
$st = $pdo->prepare('INSERT INTO lms_course_sections (course_id,title,description,position,created_by) VALUES (:c,:t,:d,:p,:u)');
$st->execute([':c' => $courseId, ':t' => $title, ':d' => $in['description'] ?? null, ':p' => $pos, ':u' => (int) $user['user_id']]);
lms_ok(['section_id' => (int) $pdo->lastInsertId()]);
