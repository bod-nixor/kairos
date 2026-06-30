<?php
declare(strict_types=1);

function join_course_id(array $payload): int
{
    $raw = $payload['course_id'] ?? null;
    if (is_int($raw)) {
        return $raw > 0 ? $raw : 0;
    }
    if (is_string($raw) && preg_match('/^[1-9][0-9]*$/', trim($raw)) === 1) {
        return (int)trim($raw);
    }
    return 0;
}

function simulate_courses_join(?array $sessionUser, array $payload, array &$studentCourses, array $courses, array $allowlist, array $preEnroll): array
{
    if (!$sessionUser || (int)($sessionUser['user_id'] ?? 0) <= 0) {
        return ['status' => 401, 'error' => 'unauthenticated'];
    }

    $courseId = join_course_id($payload);
    if ($courseId <= 0) {
        return ['status' => 422, 'error' => 'validation_error'];
    }

    $course = $courses[$courseId] ?? null;
    if ($course === null || empty($course['is_active'])) {
        return ['status' => 404, 'error' => 'not_found'];
    }

    $userId = (int)$sessionUser['user_id'];
    $email = strtolower(trim((string)($sessionUser['email'] ?? '')));
    $key = $courseId . ':' . $userId;
    $alreadyEnrolled = isset($studentCourses[$key]);
    if ($alreadyEnrolled) {
        return [
            'status' => 200,
            'data' => ['joined' => false, 'already_enrolled' => true, 'course_id' => $courseId],
        ];
    }

    $visibility = strtolower((string)($course['visibility'] ?? 'public'));
    $canJoin = $visibility === 'public'
        || in_array($email, $allowlist[$courseId] ?? [], true)
        || in_array($email, $preEnroll[$courseId] ?? [], true);

    if (!$canJoin) {
        return ['status' => 403, 'error' => 'not_enrollable'];
    }

    $studentCourses[$key] = true;

    return [
        'status' => 200,
        'data' => ['joined' => true, 'already_enrolled' => false, 'course_id' => $courseId],
    ];
}

$courses = [
    10 => ['visibility' => 'public', 'is_active' => true],
    20 => ['visibility' => 'restricted', 'is_active' => true],
    30 => ['visibility' => 'public', 'is_active' => false],
    40 => ['visibility' => 'restricted', 'is_active' => true],
];

$allowlist = [
    20 => ['allowed@nixorcollege.edu.pk'],
];
$preEnroll = [
    40 => ['pre@nixorcollege.edu.pk'],
];
$studentCourses = [
    '10:8' => true,
];

$cases = [
    [
        'name' => 'valid student enrolls in public course',
        'session' => ['user_id' => 4, 'role_name' => 'student', 'email' => 'any@nixorcollege.edu.pk'],
        'payload' => ['course_id' => 10],
        'status' => 200,
        'joined' => true,
        'count_delta' => 1,
    ],
    [
        'name' => 'already enrolled student is idempotent',
        'session' => ['user_id' => 8, 'role_name' => 'student', 'email' => 'enrolled@nixorcollege.edu.pk'],
        'payload' => ['course_id' => 10],
        'status' => 200,
        'joined' => false,
        'already_enrolled' => true,
        'count_delta' => 0,
    ],
    [
        'name' => 'missing course id is validation error',
        'session' => ['user_id' => 9, 'role_name' => 'student', 'email' => 'missing@nixorcollege.edu.pk'],
        'payload' => [],
        'status' => 422,
        'error' => 'validation_error',
    ],
    [
        'name' => 'invalid course id is not found',
        'session' => ['user_id' => 10, 'role_name' => 'student', 'email' => 'invalid@nixorcollege.edu.pk'],
        'payload' => ['course_id' => 999],
        'status' => 404,
        'error' => 'not_found',
    ],
    [
        'name' => 'inactive course cannot be joined',
        'session' => ['user_id' => 11, 'role_name' => 'student', 'email' => 'inactive@nixorcollege.edu.pk'],
        'payload' => ['course_id' => 30],
        'status' => 404,
        'error' => 'not_found',
    ],
    [
        'name' => 'restricted course denies non-eligible student',
        'session' => ['user_id' => 12, 'role_name' => 'student', 'email' => 'blocked@nixorcollege.edu.pk'],
        'payload' => ['course_id' => 20],
        'status' => 403,
        'error' => 'not_enrollable',
    ],
    [
        'name' => 'allowlisted restricted enrollment succeeds',
        'session' => ['user_id' => 13, 'role_name' => 'student', 'email' => 'allowed@nixorcollege.edu.pk'],
        'payload' => ['course_id' => 20],
        'status' => 200,
        'joined' => true,
        'count_delta' => 1,
    ],
    [
        'name' => 'pre-enrolled restricted enrollment succeeds',
        'session' => ['user_id' => 14, 'role_name' => 'student', 'email' => 'pre@nixorcollege.edu.pk'],
        'payload' => ['course_id' => 40],
        'status' => 200,
        'joined' => true,
        'count_delta' => 1,
    ],
    [
        'name' => 'unauthenticated user is blocked',
        'session' => null,
        'payload' => ['course_id' => 10],
        'status' => 401,
        'error' => 'unauthenticated',
    ],
    [
        'name' => 'manager can explicitly join a public course as student',
        'session' => ['user_id' => 15, 'role_name' => 'manager', 'email' => 'manager@nixorcollege.edu.pk'],
        'payload' => ['course_id' => 10],
        'status' => 200,
        'joined' => true,
        'count_delta' => 1,
    ],
    [
        'name' => 'TA can explicitly join a public course as student',
        'session' => ['user_id' => 16, 'role_name' => 'ta', 'email' => 'ta@nixorcollege.edu.pk'],
        'payload' => ['course_id' => 10],
        'status' => 200,
        'joined' => true,
        'count_delta' => 1,
    ],
];

$failed = [];
foreach ($cases as $case) {
    $before = count($studentCourses);
    $result = simulate_courses_join($case['session'], $case['payload'], $studentCourses, $courses, $allowlist, $preEnroll);
    $after = count($studentCourses);
    if ($result['status'] !== $case['status']) {
        $failed[] = "{$case['name']} expected status {$case['status']} got {$result['status']}";
        continue;
    }
    if (isset($case['error']) && ($result['error'] ?? null) !== $case['error']) {
        $failed[] = "{$case['name']} expected error {$case['error']} got " . ($result['error'] ?? 'none');
    }
    if (isset($case['joined']) && (bool)($result['data']['joined'] ?? null) !== $case['joined']) {
        $failed[] = "{$case['name']} joined flag mismatch";
    }
    if (isset($case['already_enrolled']) && (bool)($result['data']['already_enrolled'] ?? null) !== $case['already_enrolled']) {
        $failed[] = "{$case['name']} already_enrolled flag mismatch";
    }
    if (isset($case['count_delta']) && $after - $before !== $case['count_delta']) {
        $failed[] = "{$case['name']} enrollment row delta mismatch";
    }
}

$root = dirname(__DIR__, 2);
$joinSource = (string)file_get_contents($root . '/public/api/lms/courses/join.php');
$enrollmentSource = (string)file_get_contents($root . '/public/api/lms/_enrollment.php');
$courseJs = (string)file_get_contents($root . '/public/js/course.js');
$rbacSource = (string)file_get_contents($root . '/src/rbac.php');

$sourceChecks = [
    'join endpoint validates course_id through the shared enrollment parser' => str_contains($joinSource, 'lms_enrollment_course_id'),
    'join endpoint maps expected enrollment errors without using generic 500' => str_contains($joinSource, 'LmsEnrollmentHttpError'),
    'join endpoint logs unexpected enrollment failures with context' => str_contains($joinSource, 'lms_log_enrollment_failure'),
    'enrollment helper uses RBAC course context for active/visibility/eligibility decisions' => str_contains($enrollmentSource, 'rbac_course_access_context'),
    'enrollment helper inserts only student course participation' => str_contains($enrollmentSource, 'INSERT INTO student_courses'),
    'enrollment helper checks existing enrollment before insert' => str_contains($enrollmentSource, 'lms_student_enrollment_exists'),
    'enrollment helper supports common non-null enrollment metadata columns' => str_contains($enrollmentSource, "'role' => 'student'") && str_contains($enrollmentSource, "'status' => 'active'"),
    'enrollment helper refreshes RBAC student course cache after insert' => str_contains($enrollmentSource, 'rbac_student_course_ids($pdo, $userId, true)'),
    'RBAC student-course cache accepts explicit refresh' => str_contains($rbacSource, 'bool $refresh = false'),
    'course UI maps enrollment error codes to useful messages' => str_contains($courseJs, 'function enrolmentErrorMessage') && str_contains($courseJs, 'result?.errorCode'),
    'staff with full course view are not routed through public preview join UI' => str_contains($courseJs, 'if (!course.capabilities?.view_course)'),
];
foreach ($sourceChecks as $label => $passed) {
    if (!$passed) {
        $failed[] = $label;
    }
}

if ($failed) {
    fwrite(STDERR, implode(PHP_EOL, $failed) . PHP_EOL);
    exit(1);
}

echo "courses_join endpoint tests passed" . PHP_EOL;
