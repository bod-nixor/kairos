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

function pre_enroll_match(array $preEnroll, int $courseId, string $email, int $userId): bool
{
    if (!array_key_exists($email, $preEnroll[$courseId] ?? [])) {
        return false;
    }
    $claimedUserId = $preEnroll[$courseId][$email];
    if ($claimedUserId === 'race') {
        return true;
    }
    return $claimedUserId === null || (int)$claimedUserId === 0 || (int)$claimedUserId === $userId;
}

function claim_pre_enroll(array &$preEnroll, int $courseId, string $email, int $userId): bool
{
    if (!pre_enroll_match($preEnroll, $courseId, $email, $userId)) {
        return false;
    }
    if (($preEnroll[$courseId][$email] ?? null) === 'race') {
        $preEnroll[$courseId][$email] = 999;
        return false;
    }
    if (($preEnroll[$courseId][$email] ?? null) === $userId) {
        return true;
    }
    $preEnroll[$courseId][$email] = $userId;
    return true;
}

function student_enrollment_needs_activation_sim(array $row, array $columns): bool
{
    foreach (['status', 'enrollment_status'] as $column) {
        if (!isset($columns[$column]) || !array_key_exists($column, $row)) {
            continue;
        }
        $status = strtolower(trim((string)($row[$column] ?? '')));
        if (in_array($status, ['pending', 'invited', 'pre_enrolled', 'pre-enrolled', 'unclaimed', 'inactive'], true)) {
            return true;
        }
    }
    if (isset($columns['is_active']) && array_key_exists('is_active', $row) && (int)$row['is_active'] !== 1) {
        return true;
    }
    return false;
}

function enrollment_status_value(mixed $row): string
{
    if (is_array($row)) {
        return strtolower((string)($row['status'] ?? 'active'));
    }
    return 'active';
}

function simulate_courses_join(?array $sessionUser, array $payload, array &$studentCourses, array $courses, array $allowlist, array &$preEnroll): array
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
    $matchedPreEnroll = pre_enroll_match($preEnroll, $courseId, $email, $userId);
    $visibility = strtolower((string)($course['visibility'] ?? 'public'));
    $allowlisted = in_array($email, $allowlist[$courseId] ?? [], true);
    $requiresPreEnrollmentClaim = $matchedPreEnroll && $visibility !== 'public' && !$allowlisted;
    if ($alreadyEnrolled) {
        $claimed = false;
        if ($requiresPreEnrollmentClaim) {
            $claimed = claim_pre_enroll($preEnroll, $courseId, $email, $userId);
            if (!$claimed) {
                return ['status' => 403, 'error' => 'not_enrollable'];
            }
        }
        $activated = is_array($studentCourses[$key])
            && student_enrollment_needs_activation_sim($studentCourses[$key], ['status' => true, 'enrollment_status' => true, 'is_active' => true]);
        if ($activated) {
            $studentCourses[$key] = ['status' => 'active'];
        }
        if (!$claimed) {
            $claimed = claim_pre_enroll($preEnroll, $courseId, $email, $userId);
        }
        return [
            'status' => 200,
            'data' => [
                'joined' => $activated,
                'already_enrolled' => !$activated,
                'pre_enrollment_matched' => $matchedPreEnroll,
                'pre_enrollment_claimed' => $claimed,
                'course_id' => $courseId,
            ],
        ];
    }

    $canJoin = $visibility === 'public'
        || $allowlisted
        || $matchedPreEnroll;

    if (!$canJoin) {
        return ['status' => 403, 'error' => 'not_enrollable'];
    }

    $claimed = false;
    if ($requiresPreEnrollmentClaim) {
        $claimed = claim_pre_enroll($preEnroll, $courseId, $email, $userId);
        if (!$claimed) {
            return ['status' => 403, 'error' => 'not_enrollable'];
        }
    }
    $studentCourses[$key] = ['status' => 'active'];
    if (!$claimed) {
        $claimed = claim_pre_enroll($preEnroll, $courseId, $email, $userId);
    }

    return [
        'status' => 200,
        'data' => [
            'joined' => true,
            'already_enrolled' => false,
            'pre_enrollment_matched' => $matchedPreEnroll,
            'pre_enrollment_claimed' => $claimed,
            'course_id' => $courseId,
        ],
    ];
}

$courseIdCases = [
    'positive integer course id' => [['course_id' => 10], 10],
    'trimmed numeric string course id' => [['course_id' => ' 20 '], 20],
    'missing course id' => [[], 0],
    'non-numeric string course id' => [['course_id' => 'abc'], 0],
];

$failed = [];
foreach ($courseIdCases as $name => [$payload, $expected]) {
    $actual = join_course_id($payload);
    if ($actual !== $expected) {
        $failed[] = "{$name} expected parsed course id {$expected} got {$actual}";
    }
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
    40 => [
        'pre@nixorcollege.edu.pk' => null,
        'pending@nixorcollege.edu.pk' => null,
        'claimed-self@nixorcollege.edu.pk' => 23,
        'legacy@nixorcollege.edu.pk' => 0,
        'raced@nixorcollege.edu.pk' => 'race',
        'claimed-other@nixorcollege.edu.pk' => 999,
    ],
];
$studentCourses = [
    '10:8' => true,
    '40:17' => ['status' => 'active', 'enrollment_status' => 'pending', 'is_active' => 1],
    '40:23' => ['status' => 'active'],
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
        'name' => 'non-numeric course id is validation error',
        'session' => ['user_id' => 21, 'role_name' => 'student', 'email' => 'bad-id@nixorcollege.edu.pk'],
        'payload' => ['course_id' => 'abc'],
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
        'pre_enrollment_matched' => true,
        'pre_enrollment_claimed' => true,
        'claimed_email' => 'pre@nixorcollege.edu.pk',
        'claimed_user_id' => 14,
        'count_delta' => 1,
    ],
    [
        'name' => 'pre-enrolled existing account pending row is activated',
        'session' => ['user_id' => 17, 'role_name' => 'student', 'email' => 'pending@nixorcollege.edu.pk'],
        'payload' => ['course_id' => 40],
        'status' => 200,
        'joined' => true,
        'pre_enrollment_matched' => true,
        'pre_enrollment_claimed' => true,
        'claimed_email' => 'pending@nixorcollege.edu.pk',
        'claimed_user_id' => 17,
        'student_key' => '40:17',
        'student_status' => 'active',
        'count_delta' => 0,
    ],
    [
        'name' => 'legacy zero-claimed pre-enroll can be claimed',
        'session' => ['user_id' => 18, 'role_name' => 'student', 'email' => 'legacy@nixorcollege.edu.pk'],
        'payload' => ['course_id' => 40],
        'status' => 200,
        'joined' => true,
        'pre_enrollment_matched' => true,
        'pre_enrollment_claimed' => true,
        'claimed_email' => 'legacy@nixorcollege.edu.pk',
        'claimed_user_id' => 18,
        'count_delta' => 1,
    ],
    [
        'name' => 'pre-enroll claimed by another user is not joinable',
        'session' => ['user_id' => 19, 'role_name' => 'student', 'email' => 'claimed-other@nixorcollege.edu.pk'],
        'payload' => ['course_id' => 40],
        'status' => 403,
        'error' => 'not_enrollable',
    ],
    [
        'name' => 'stale invite match does not create enrollment after failed claim',
        'session' => ['user_id' => 22, 'role_name' => 'student', 'email' => 'raced@nixorcollege.edu.pk'],
        'payload' => ['course_id' => 40],
        'status' => 403,
        'error' => 'not_enrollable',
        'count_delta' => 0,
    ],
    [
        'name' => 'same-user claimed pre-enroll duplicate join is idempotent',
        'session' => ['user_id' => 23, 'role_name' => 'student', 'email' => 'claimed-self@nixorcollege.edu.pk'],
        'payload' => ['course_id' => 40],
        'status' => 200,
        'joined' => false,
        'already_enrolled' => true,
        'pre_enrollment_matched' => true,
        'pre_enrollment_claimed' => true,
        'claimed_email' => 'claimed-self@nixorcollege.edu.pk',
        'claimed_user_id' => 23,
        'count_delta' => 0,
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
    [
        'name' => 'admin can explicitly join a public course as student',
        'session' => ['user_id' => 20, 'role_name' => 'admin', 'email' => 'admin@nixorcollege.edu.pk'],
        'payload' => ['course_id' => 10],
        'status' => 200,
        'joined' => true,
        'count_delta' => 1,
    ],
];

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
    if (isset($case['pre_enrollment_matched']) && (bool)($result['data']['pre_enrollment_matched'] ?? null) !== $case['pre_enrollment_matched']) {
        $failed[] = "{$case['name']} pre_enrollment_matched flag mismatch";
    }
    if (isset($case['pre_enrollment_claimed']) && (bool)($result['data']['pre_enrollment_claimed'] ?? null) !== $case['pre_enrollment_claimed']) {
        $failed[] = "{$case['name']} pre_enrollment_claimed flag mismatch";
    }
    if (isset($case['count_delta']) && $after - $before !== $case['count_delta']) {
        $failed[] = "{$case['name']} enrollment row delta mismatch";
    }
    if (isset($case['claimed_email']) && ($preEnroll[(int)$case['payload']['course_id']][$case['claimed_email']] ?? null) !== $case['claimed_user_id']) {
        $failed[] = "{$case['name']} pre-enroll claim mismatch";
    }
    if (isset($case['student_key'])) {
        if (!array_key_exists($case['student_key'], $studentCourses)) {
            $failed[] = "{$case['name']} expected student enrollment row to exist";
        } elseif (enrollment_status_value($studentCourses[$case['student_key']]) !== $case['student_status']) {
            $failed[] = "{$case['name']} student enrollment status mismatch";
        }
    }
}

$root = dirname(__DIR__, 2);
$joinSource = (string)file_get_contents($root . '/public/api/lms/courses/join.php');
$enrollmentSource = (string)file_get_contents($root . '/public/api/lms/_enrollment.php');
$courseJs = (string)file_get_contents($root . '/public/js/course.js');
$dashboardJs = (string)file_get_contents($root . '/public/script.js');
$rbacSource = (string)file_get_contents($root . '/src/rbac.php');
$claimPosition = strpos($enrollmentSource, '$claimedPreEnrollment = lms_claim_course_pre_enrollment');
$insertPosition = strpos($enrollmentSource, '$joined = lms_insert_student_enrollment');

$sourceChecks = [
    'join endpoint delegates enrollment decisions to the shared enrollment helper' => str_contains($joinSource, 'lms_self_enroll_user_in_course') && str_contains($enrollmentSource, 'function lms_enrollment_course_id'),
    'join endpoint maps expected enrollment errors without using generic 500' => str_contains($joinSource, 'LmsEnrollmentHttpError'),
    'join endpoint logs unexpected enrollment failures with context' => str_contains($joinSource, 'lms_log_enrollment_failure'),
    'enrollment helper uses RBAC course context for active/visibility/eligibility decisions' => str_contains($enrollmentSource, 'rbac_course_access_context'),
    'enrollment helper inserts only student course participation' => str_contains($enrollmentSource, 'INSERT INTO student_courses'),
    'enrollment helper checks existing enrollment before insert' => str_contains($enrollmentSource, '$hasExistingEnrollment'),
    'enrollment helper returns false for duplicate insert no-ops' => str_contains($enrollmentSource, 'ON DUPLICATE KEY UPDATE') && str_contains($enrollmentSource, 'return false;'),
    'enrollment helper activates pending existing enrollment rows' => str_contains($enrollmentSource, 'lms_activate_student_enrollment') && str_contains($enrollmentSource, 'lms_student_enrollment_needs_activation'),
    'enrollment helper matches and claims email pre-enrollments' => str_contains($enrollmentSource, 'lms_matching_course_pre_enrollment') && str_contains($enrollmentSource, 'lms_claim_course_pre_enrollment'),
    'invite-only pre-enrollment must be claimed before enrollment write' => $claimPosition !== false && $insertPosition !== false && $claimPosition < $insertPosition,
    'legacy pre-enroll schemas without claim tracking remain eligible' => str_contains($enrollmentSource, 'has_claimed_user_id') && str_contains($enrollmentSource, 'lms_pre_enrollment_supports_claim'),
    'fallback pre-enrollment claim is skipped when there is no matching claimable row' => str_contains($enrollmentSource, '!$claimedPreEnrollment && $canClaimPreEnrollment'),
    'legacy zero claimed pre-enroll rows are treated as unclaimed' => str_contains($enrollmentSource, 'claimed_user_id = 0') && str_contains($rbacSource, 'claimed_user_id = 0'),
    'enrollment failure logger redacts raw account ids' => !str_contains($enrollmentSource, "'user_id' => \$context['user_id']") && str_contains($enrollmentSource, "'user_present'"),
    'enrollment failure logger redacts raw exception messages' => !str_contains($enrollmentSource, "'exception_message'") && str_contains($enrollmentSource, "'message_hash'"),
    'enrollment failure logger includes backend exception diagnostics' => str_contains($enrollmentSource, "'sql_state'") && str_contains($enrollmentSource, "'driver_error_code'"),
    'enrollment helper supports common non-null enrollment metadata columns' => str_contains($enrollmentSource, "'role' => 'student'") && str_contains($enrollmentSource, "'status' => lms_student_enrollment_active_status"),
    'enrollment helper refreshes RBAC student course cache after insert' => str_contains($enrollmentSource, 'rbac_student_course_ids($pdo, $userId, true)'),
    'RBAC student-course cache accepts explicit refresh' => str_contains($rbacSource, 'bool $refresh = false'),
    'course UI maps enrollment error codes to useful messages' => str_contains($courseJs, 'function enrolmentErrorMessage') && str_contains($courseJs, 'result?.errorCode'),
    'staff with full course view are not routed through public preview join UI' => str_contains($courseJs, 'if (!course.capabilities?.view_course)'),
    'dashboard join UI enables invited pre-enrolled courses' => str_contains($dashboardJs, 'c.can_self_enroll || invited') && str_contains($dashboardJs, 'c.allowlisted || c.pre_enrolled'),
    'dashboard join UI opens the course after idempotent success' => str_contains($dashboardJs, 'window.location.assign(`./course.html?course_id='),
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
