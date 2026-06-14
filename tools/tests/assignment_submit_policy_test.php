<?php
declare(strict_types=1);

function simulate_assignment_submit(
    array $user,
    array $assignment,
    array $post,
    array $files,
    bool $driveWritesEnabled,
    bool $driveUploadFails,
    array &$studentCourses,
    array $courseRecords,
    array $allowlist = [],
    array $preEnroll = []
): array {
    if (empty($user['user_id'])) {
        return ['status' => 401, 'error' => 'unauthenticated', 'db_written' => false];
    }

    $textSub = trim((string)($post['text_submission'] ?? ''));
    $hasFile = !empty($files['file']) && ($files['file']['error'] ?? 1) === 0;
    if ($textSub === '' && !$hasFile) {
        return ['status' => 422, 'error' => 'validation_error', 'db_written' => false];
    }

    $courseId = (int)$assignment['course_id'];
    $userId = (int)$user['user_id'];
    $email = strtolower((string)($user['email'] ?? ''));

    $course = $courseRecords[$courseId] ?? null;
    $active = $course ? (bool)$course['is_active'] : false;
    $visibility = $course ? strtolower((string)$course['visibility']) : 'restricted';

    $isAdmin = $active && (strtolower($user['role_name'] ?? '') === 'admin');
    $isManager = $active && (strtolower($user['role_name'] ?? '') === 'manager');
    $isTa = $active && (strtolower($user['role_name'] ?? '') === 'ta');

    $key = $courseId . ':' . $userId;
    $isEnrolled = $active && !empty($studentCourses[$key]);

    $isPublic = $active && $visibility === 'public';
    $isAllowlisted = $active && in_array($email, $allowlist[$courseId] ?? [], true);
    $isPreEnrolled = $active && in_array($email, $preEnroll[$courseId] ?? [], true);

    $canSelfEnroll = $active && !$isEnrolled && ($isPublic || $isAllowlisted || $isPreEnrolled);
    $participateAsStudent = $isEnrolled;

    if (!$participateAsStudent && $canSelfEnroll) {
        $studentCourses[$key] = true;
        $participateAsStudent = true;
        $isEnrolled = true;
    }

    $viewCourse = $active && ($isAdmin || $isManager || $isTa || $isEnrolled);
    if (!($viewCourse && $participateAsStudent)) {
        return ['status' => 403, 'error' => 'forbidden', 'db_written' => false];
    }

    if (($assignment['status'] ?? '') !== 'published') {
        return ['status' => 403, 'error' => 'not_allowed', 'db_written' => false];
    }

    $late = !empty($assignment['due_at']) && strtotime((string)$assignment['due_at']) < time();
    if ($late && empty($assignment['late_allowed'])) {
        return ['status' => 422, 'error' => 'late_not_allowed', 'db_written' => false];
    }

    $hasFile = !empty($files['file']) && ($files['file']['error'] ?? 1) === 0;
    $uploadMeta = null;
    if ($hasFile) {
        $ext = strtolower(pathinfo($files['file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['php', 'svg', 'html', 'js'], true)) {
            return ['status' => 422, 'error' => 'validation_error', 'db_written' => false];
        }
        if (!empty($assignment['allowed_file_extensions'])) {
            $allowed = array_map('trim', explode(',', strtolower($assignment['allowed_file_extensions'])));
            if (!in_array($ext, $allowed, true)) {
                return ['status' => 422, 'error' => 'validation_error', 'db_written' => false];
            }
        }
        if (($files['file']['size'] ?? 0) > ($assignment['max_file_mb'] ?? 50) * 1024 * 1024) {
            return ['status' => 422, 'error' => 'validation_error', 'db_written' => false];
        }
        $uploadMeta = $files['file'];
    }

    if ($uploadMeta !== null) {
        if (!$driveWritesEnabled) {
            return ['status' => 503, 'error' => 'storage_unavailable', 'db_written' => false];
        }
        if ($driveUploadFails) {
            return ['status' => 503, 'error' => 'storage_unavailable', 'db_written' => false];
        }
    }

    return ['status' => 200, 'data' => ['success' => true], 'db_written' => true];
}

$failed = [];
$assert = static function (bool $condition, string $message) use (&$failed): void {
    if (!$condition) {
        $failed[] = $message;
    }
};

$courseRecords = [
    101 => ['is_active' => true, 'visibility' => 'restricted'],
    102 => ['is_active' => true, 'visibility' => 'public'],
];

$assignment101 = [
    'assignment_id' => 1,
    'course_id' => 101,
    'status' => 'published',
    'due_at' => null,
    'late_allowed' => true,
    'allowed_file_extensions' => 'pdf,docx',
    'max_file_mb' => 5,
];

// 1. enrolled student can submit text-only
$studentCourses = ['101:10' => true];
$res = simulate_assignment_submit(
    ['user_id' => 10, 'role_name' => 'student'],
    $assignment101,
    ['text_submission' => 'hello'],
    [],
    true,
    false,
    $studentCourses,
    $courseRecords
);
$assert($res['status'] === 200 && $res['db_written'] === true, 'enrolled student can submit text-only');

// 2. enrolled student can submit valid file when Drive mock is available
$studentCourses = ['101:10' => true];
$res = simulate_assignment_submit(
    ['user_id' => 10, 'role_name' => 'student'],
    $assignment101,
    [],
    ['file' => ['error' => 0, 'name' => 'test.pdf', 'size' => 1024 * 1024]],
    true,
    false,
    $studentCourses,
    $courseRecords
);
$assert($res['status'] === 200 && $res['db_written'] === true, 'enrolled student can submit valid file');

// 3. TA assigned elsewhere but enrolled in this course can submit as student
$studentCourses = ['101:20' => true];
$res = simulate_assignment_submit(
    ['user_id' => 20, 'role_name' => 'ta'],
    $assignment101,
    ['text_submission' => 'hello'],
    [],
    true,
    false,
    $studentCourses,
    $courseRecords
);
$assert($res['status'] === 200 && $res['db_written'] === true, 'enrolled TA can submit as student');

// 4. manager assigned elsewhere but enrolled in this course can submit as student
$studentCourses = ['101:30' => true];
$res = simulate_assignment_submit(
    ['user_id' => 30, 'role_name' => 'manager'],
    $assignment101,
    ['text_submission' => 'hello'],
    [],
    true,
    false,
    $studentCourses,
    $courseRecords
);
$assert($res['status'] === 200 && $res['db_written'] === true, 'enrolled manager can submit as student');

// 5. TA/manager not enrolled cannot submit
$studentCourses = [];
$res = simulate_assignment_submit(
    ['user_id' => 20, 'role_name' => 'ta'],
    $assignment101,
    ['text_submission' => 'hello'],
    [],
    true,
    false,
    $studentCourses,
    $courseRecords
);
$assert($res['status'] === 403, 'TA not enrolled cannot submit');

$res = simulate_assignment_submit(
    ['user_id' => 30, 'role_name' => 'manager'],
    $assignment101,
    ['text_submission' => 'hello'],
    [],
    true,
    false,
    $studentCourses,
    $courseRecords
);
$assert($res['status'] === 403, 'manager not enrolled cannot submit');

// 6. admin is not treated as student unless enrolled or explicit policy allows impersonation
$studentCourses = [];
$res = simulate_assignment_submit(
    ['user_id' => 40, 'role_name' => 'admin'],
    $assignment101,
    ['text_submission' => 'hello'],
    [],
    true,
    false,
    $studentCourses,
    $courseRecords
);
$assert($res['status'] === 403, 'admin not enrolled cannot submit');

// 7. unpublished assignment returns 403 not_allowed
$studentCourses = ['101:10' => true];
$unpublished = $assignment101;
$unpublished['status'] = 'draft';
$res = simulate_assignment_submit(
    ['user_id' => 10, 'role_name' => 'student'],
    $unpublished,
    ['text_submission' => 'hello'],
    [],
    true,
    false,
    $studentCourses,
    $courseRecords
);
$assert($res['status'] === 403 && $res['error'] === 'not_allowed', 'unpublished assignment returns 403 not_allowed');

// 7.5. late submission check rejecting submissions when late_allowed is false
$studentCourses = ['101:10' => true];
$lateAssignment = $assignment101;
$lateAssignment['due_at'] = '2020-01-01 00:00:00';
$lateAssignment['late_allowed'] = false;
$res = simulate_assignment_submit(
    ['user_id' => 10, 'role_name' => 'student'],
    $lateAssignment,
    ['text_submission' => 'hello'],
    [],
    true,
    false,
    $studentCourses,
    $courseRecords
);
$assert($res['status'] === 422 && $res['error'] === 'late_not_allowed', 'late submission rejected when late_allowed is false');

// 8. invalid file type returns 422 before Drive
$studentCourses = ['101:10' => true];
$res = simulate_assignment_submit(
    ['user_id' => 10, 'role_name' => 'student'],
    $assignment101,
    [],
    ['file' => ['error' => 0, 'name' => 'test.svg', 'size' => 1024]],
    true,
    false,
    $studentCourses,
    $courseRecords
);
$assert($res['status'] === 422, 'invalid file type returns 422 before Drive');

// 9. valid file with Drive disabled returns 503 after validation
$studentCourses = ['101:10' => true];
$res = simulate_assignment_submit(
    ['user_id' => 10, 'role_name' => 'student'],
    $assignment101,
    [],
    ['file' => ['error' => 0, 'name' => 'test.pdf', 'size' => 1024]],
    false,
    false,
    $studentCourses,
    $courseRecords
);
$assert($res['status'] === 503 && $res['error'] === 'storage_unavailable', 'valid file with Drive disabled returns 503');

// 10. submission creates no DB record on failed upload
$studentCourses = ['101:10' => true];
$res = simulate_assignment_submit(
    ['user_id' => 10, 'role_name' => 'student'],
    $assignment101,
    [],
    ['file' => ['error' => 0, 'name' => 'test.pdf', 'size' => 1024]],
    true,
    true,
    $studentCourses,
    $courseRecords
);
$assert($res['status'] === 503 && $res['db_written'] === false, 'submission creates no DB record on failed upload');

// 11. public course auto-enrolls
$studentCourses = [];
$res = simulate_assignment_submit(
    ['user_id' => 10, 'role_name' => 'student'],
    [
        'assignment_id' => 2,
        'course_id' => 102,
        'status' => 'published',
        'due_at' => null,
        'late_allowed' => true,
        'allowed_file_extensions' => '',
        'max_file_mb' => 5,
    ],
    ['text_submission' => 'hello'],
    [],
    true,
    false,
    $studentCourses,
    $courseRecords
);
$assert($res['status'] === 200 && !empty($studentCourses['102:10']), 'public course auto-enrolls');

// Static analysis of submit.php
$root = dirname(__DIR__, 2);
$submitSource = file_get_contents($root . '/public/api/lms/assignments/submit.php');
if ($submitSource === false) {
    fwrite(STDERR, "assignment_submit_policy_test FAILED: Could not read submit.php" . PHP_EOL);
    exit(1);
}

$assert(strpos($submitSource, "require_login()") !== false, 'submit.php must use require_login()');
$assert(strpos($submitSource, "lms_require_roles(['student'])") === false, 'submit.php must not restrict to global student role only');
$assert(strpos($submitSource, "rbac_course_access_context") !== false, 'submit.php must call rbac_course_access_context');
$assert(strpos($submitSource, "lms_course_access(\$user, (int)\$assignment['course_id'], false)") !== false, 'submit.php must require student course access');

if ($failed !== []) {
    fwrite(STDERR, "assignment_submit_policy_test FAILED:\n" . implode(PHP_EOL, $failed) . PHP_EOL);
    exit(1);
}

echo "assignment_submit_policy_test passed" . PHP_EOL;
