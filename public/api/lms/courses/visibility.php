<?php
declare(strict_types=1);

require_once __DIR__ . '/_settings_common.php';

$user = require_login();
$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    lms_error('method_not_allowed', 'Method not allowed', 405);
}

$in = lms_json_input();
$courseId = (int)($in['course_id'] ?? 0);
$visibility = strtolower((string)($in['visibility'] ?? ''));
if ($courseId <= 0 || !in_array($visibility, ['public', 'restricted'], true)) {
    lms_error('validation_error', 'course_id and visibility are required.', 422);
}
if (!lms_course_exists($pdo, $courseId)) {
    lms_error('not_found', 'Course not found.', 404);
}

lms_require_course_manager_or_admin($pdo, $user, $courseId);

$stmt = $pdo->prepare('UPDATE courses SET visibility = :visibility WHERE course_id = :cid LIMIT 1');
$stmt->execute([':visibility' => $visibility, ':cid' => $courseId]);

lms_ok(['course_id' => $courseId, 'visibility' => $visibility]);
