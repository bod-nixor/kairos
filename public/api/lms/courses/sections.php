<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';

$user = lms_require_roles(['student','ta','manager','admin']);
$courseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : (int)basename(dirname($_SERVER['SCRIPT_NAME']));
if ($courseId <= 0) {
    lms_error('validation_error', 'course_id is required', 422);
}
lms_course_access($user, $courseId);
$pdo = db();
$sql = 'SELECT s.section_id, s.title, s.description, s.position, l.lesson_id, l.title AS lesson_title, l.position AS lesson_position, EXISTS(SELECT 1 FROM lms_lesson_completions c WHERE c.lesson_id = l.lesson_id AND c.user_id = :uid) AS completed
        FROM lms_course_sections s
        LEFT JOIN lms_lessons l ON l.section_id = s.section_id AND l.deleted_at IS NULL
        WHERE s.course_id = :course_id AND s.deleted_at IS NULL
        ORDER BY s.position, l.position';
$stmt = $pdo->prepare($sql);
$stmt->execute([':uid' => (int)$user['user_id'], ':course_id' => $courseId]);
$rows = $stmt->fetchAll();
$sections = [];
foreach ($rows as $row) {
    $sid = (int)$row['section_id'];
    if (!isset($sections[$sid])) {
        $sections[$sid] = ['section_id' => $sid, 'title' => $row['title'], 'description' => $row['description'], 'position' => (int)$row['position'], 'lessons' => []];
    }
    if (!empty($row['lesson_id'])) {
        $sections[$sid]['lessons'][] = ['lesson_id' => (int)$row['lesson_id'], 'title' => $row['lesson_title'], 'position' => (int)$row['lesson_position'], 'completed' => (bool)$row['completed']];
    }
}
lms_ok(['items' => array_values($sections)]);
