<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/public/api/lms/quiz/access_policy.php';

function simulate_quiz_access(array $capabilities, string $action, string $status = 'published'): array
{
    if ($action === 'student_attempt') {
        $decision = lms_quiz_student_attempt_decision($capabilities, $status);
        return [
            'status' => (int)$decision['status'],
            'error' => $decision['code'],
            'attempt_created' => (bool)$decision['allowed'],
        ];
    }

    if ($action === 'staff_preview') {
        $decision = lms_quiz_staff_preview_decision($capabilities);
        return [
            'status' => (int)$decision['status'],
            'error' => $decision['code'],
            'attempt_created' => false,
        ];
    }

    return ['status' => 400, 'error' => 'bad_action', 'attempt_created' => false];
}

$failed = [];
$assert = static function (bool $condition, string $message) use (&$failed): void {
    if (!$condition) {
        $failed[] = $message;
    }
};

$student = ['view_course' => true, 'view_course_home' => true, 'participate_as_student' => true];
$admin = ['view_course' => true, 'view_course_home' => true, 'admin_course' => true, 'manage_course' => true, 'grade_course' => true, 'participate_as_student' => false];
$manager = ['view_course' => true, 'view_course_home' => true, 'manage_course' => true, 'grade_course' => true, 'participate_as_student' => false];
$ta = ['view_course' => true, 'view_course_home' => true, 'grade_course' => true, 'participate_as_student' => false];
$unauthorized = ['view_course' => false, 'view_course_home' => false, 'participate_as_student' => false];
$nonEnrolledPublicStudent = ['view_course' => false, 'view_course_home' => true, 'can_self_enroll' => true, 'participate_as_student' => false];

$res = simulate_quiz_access($student, 'student_attempt');
$assert($res['status'] === 200 && $res['attempt_created'] === true, 'student can start quiz attempt');

$res = simulate_quiz_access($admin, 'staff_preview');
$assert($res['status'] === 200 && $res['attempt_created'] === false, 'admin can preview without student participation');
$res = simulate_quiz_access($admin, 'student_attempt');
$assert($res['status'] === 403 && $res['error'] === 'student_participation_required', 'admin is not forced into student attempt flow');

foreach (['manager' => $manager, 'ta' => $ta] as $role => $caps) {
    $res = simulate_quiz_access($caps, 'staff_preview');
    $assert($res['status'] === 200 && $res['attempt_created'] === false, "$role can preview when course role grants grading/management access");
}

$res = simulate_quiz_access($unauthorized, 'staff_preview');
$assert($res['status'] === 403, 'unauthorized user cannot preview');
$res = simulate_quiz_access($unauthorized, 'student_attempt');
$assert($res['status'] === 403, 'unauthorized user cannot start attempt');

$res = simulate_quiz_access($nonEnrolledPublicStudent, 'student_attempt');
$assert($res['status'] === 403 && $res['attempt_created'] === false, 'non-enrolled student is blocked before attempt creation');

if ($failed !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failed) . PHP_EOL);
    exit(1);
}

echo 'quiz access policy tests passed' . PHP_EOL;
