<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';

$user = lms_require_roles(['student','ta','manager','admin']);
$courseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$lessonId = isset($_GET['lesson_id']) ? (int)$_GET['lesson_id'] : 0;

if ($courseId <= 0 || $lessonId <= 0) {
    lms_error('validation_error', 'course_id and lesson_id are required', 422);
}

lms_course_access($user, $courseId);
$role = lms_user_role($user);
$isStaff = lms_is_staff_role($role);

$pdo = db();
$lessonStmt = $pdo->prepare(
    'SELECT l.lesson_id, l.section_id, l.course_id, l.title, l.summary, l.html_content, l.position, l.requires_previous,
            COALESCE(mi.published_flag, 0) AS published_flag
     FROM lms_lessons l
     LEFT JOIN lms_module_items mi
       ON mi.item_type = \'lesson\' AND mi.entity_id = l.lesson_id AND mi.course_id = l.course_id
     WHERE l.lesson_id = :lesson_id
       AND l.course_id = :course_id
       AND l.deleted_at IS NULL
     ORDER BY mi.module_item_id DESC
     LIMIT 1'
);
$lessonStmt->execute([':lesson_id' => $lessonId, ':course_id' => $courseId]);
$lesson = $lessonStmt->fetch(PDO::FETCH_ASSOC);

if (!$lesson) {
    lms_error('not_found', 'Lesson not found', 404);
}

if (!$isStaff && (int)$lesson['published_flag'] !== 1) {
    lms_error('forbidden', 'Lesson is not published', 403);
}

lms_ok($lesson);
