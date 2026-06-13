<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';
require_once __DIR__ . '/_restriction_helpers.php';

$user = lms_require_roles(['student', 'ta', 'manager', 'admin']);
$courseId = (int)($_GET['course_id'] ?? 0);
if ($courseId <= 0) {
    lms_error('validation_error', 'course_id required', 422);
}
lms_course_access($user, $courseId);
lms_ok(lms_assignment_upload_policy_payload());
