<?php
declare(strict_types=1);

require_once __DIR__ . '/../_enrollment.php';

$user = require_login();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    lms_error('method_not_allowed', 'POST required', 405);
}

$in = lms_json_input();
$courseId = lms_enrollment_course_id($in);
if ($courseId <= 0) {
    lms_error('validation_error', 'course_id required', 422);
}

$pdo = db();
$userId = (int)($user['user_id'] ?? 0);

try {
    $result = lms_self_enroll_user_in_course($pdo, $user, $courseId, 'course_join');
    lms_ok($result + [
        'access_context' => 'student',
    ]);
} catch (LmsEnrollmentHttpError $error) {
    lms_error($error->errorCode, $error->getMessage(), $error->status, $error->details);
} catch (Throwable $error) {
    lms_log_enrollment_failure($error, [
        'course_id' => $courseId,
        'user_id' => $userId,
        'source' => 'course_join',
    ]);
    lms_error('system_error', 'Unable to enrol in this course right now.', 500);
}
