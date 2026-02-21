<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

$user = lms_require_roles(['student','ta','manager','admin']);
$courseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
if ($courseId <= 0) {
    lms_error('validation_error', 'course_id is required', 422);
}
lms_course_access($user, $courseId);

$pdo = db();
$stmt = $pdo->prepare('SELECT feature_flag_id, course_id, flag_key, enabled, rollout_json, updated_at FROM lms_feature_flags WHERE course_id = :course_id OR course_id IS NULL ORDER BY course_id IS NULL DESC, flag_key ASC');
$stmt->execute([':course_id' => $courseId]);
lms_ok(['items' => $stmt->fetchAll()]);
