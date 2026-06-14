<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/course_access_policy.php';

function decision(array $overrides = []): array
{
    return kairos_course_access_decision($overrides + [
        'authenticated' => true,
        'course_active' => true,
        'visibility' => 'restricted',
        'is_admin' => false,
        'assigned_manager' => false,
        'assigned_ta' => false,
        'enrolled_student' => false,
        'allowlisted' => false,
        'pre_enrolled' => false,
    ]);
}

$studentEnrolled = decision(['enrolled_student' => true]);
$studentPublic = decision(['visibility' => 'public']);
$studentPrivate = decision();
$taAssigned = decision(['assigned_ta' => true]);
$taPublicForeign = decision(['visibility' => 'public']);
$taEnrolledForeign = decision(['visibility' => 'public', 'enrolled_student' => true]);
$managerAssigned = decision(['assigned_manager' => true]);
$managerPublicForeign = decision(['visibility' => 'public']);
$admin = decision(['is_admin' => true]);
$downgradedTa = decision(['assigned_ta' => true, 'is_admin' => false]);
$downgradedTaForeign = decision(['visibility' => 'public', 'is_admin' => false]);
$archived = decision(['course_active' => false, 'visibility' => 'public', 'is_admin' => true]);
$invited = decision(['allowlisted' => true]);
$unauthenticated = decision(['authenticated' => false, 'visibility' => 'public']);

$checks = [
    [$studentEnrolled['view_course'], 'student can view enrolled course content'],
    [$studentEnrolled['participate_as_student'], 'student participation derives from enrollment'],
    [$studentPublic['view_course_public'] && $studentPublic['view_course_home'], 'student can preview public course'],
    [$studentPublic['can_self_enroll'] && !$studentPublic['view_course'], 'public preview requires enrollment for content'],
    [!$studentPrivate['view_course_home'], 'student cannot discover private foreign course'],
    [$taAssigned['grade_course'] && !$taAssigned['manage_course'], 'assigned TA receives TA capability only'],
    [$taPublicForeign['view_course_public'] && $taPublicForeign['view_course_home'], 'TA can preview public foreign course'],
    [!$taPublicForeign['grade_course'] && !$taPublicForeign['manage_course'], 'TA cannot manage or grade foreign public course'],
    [$taEnrolledForeign['participate_as_student'], 'TA can participate as student in another course'],
    [$managerAssigned['manage_course'] && $managerAssigned['grade_course'], 'assigned manager can manage course'],
    [$managerPublicForeign['view_course_public'] && !$managerPublicForeign['manage_course'], 'manager can preview but not manage public foreign course'],
    [$admin['admin_course'] && $admin['manage_course'] && $admin['view_course'], 'admin retains global active-course authority'],
    [$downgradedTa['course_role'] === 'ta' && !$downgradedTa['admin_course'], 'downgraded former admin uses current TA context'],
    [$downgradedTaForeign['course_role'] === 'public' && !$downgradedTaForeign['grade_course'], 'downgraded TA has public-only foreign context'],
    [!$archived['view_course_home'] && !$archived['admin_course'], 'inactive courses remain inaccessible'],
    [$invited['view_course_home'] && $invited['can_self_enroll'], 'allowlisted restricted course can be joined'],
    [!$unauthenticated['view_course_home'], 'public course still requires authentication'],
];

$root = dirname(__DIR__, 2);
$sourceChecks = [
    ['public/api/bootstrap.php', '/FROM users u[\s\S]*LEFT JOIN roles/', 'each request refreshes the current DB role'],
    ['src/rbac.php', '/\\$courses = array_merge\\(\\$courses, rbac_student_course_ids/', 'student enrollment is independent of global role'],
    ['src/rbac.php', '/LOWER\\(\\$roleIdentifier\\) = \'student\'/', 'legacy role mappings cannot turn staff rows into student participation'],
    ['public/api/lms/courses.php', '/lms_course_home_access/', 'course detail supports public home access'],
    ['public/api/lms/courses.php', "/lms_error\\('validation_error', 'Missing or invalid course id\\.', 422\\)/", 'invalid course IDs return a stable validation error'],
    ['public/api/lms/modules.php', '/lms_course_access/', 'modules still require enrolled or assigned content access'],
    ['public/api/lms/courses/join.php', '/beginTransaction\\(\\)/', 'self-enrollment is transactional'],
    ['public/api/lms/courses/join.php', '/INSERT INTO student_courses/', 'self-enrollment writes only student participation'],
    ['public/api/lms/courses/join.php', '/course\\.enrollment\\.updated/', 'self-enrollment emits post-write invalidation context'],
    ['public/api/rooms.php', '/rbac_can_access_course/', 'rooms require enrolled or assigned course access'],
    ['public/api/queue_participants.php', '/rbac_can_view_queue/', 'queue participant reads remain course scoped'],
    ['public/api/queue_eta.php', '/rbac_can_view_queue/', 'queue ETA remains course scoped'],
    ['ws_server.py', '/mappings\\s*=\\s*\\[\\s*\\(\\s*"student_courses"\\s*,\\s*None\\s*\\)\\s*\\]/', 'realtime accepts student enrollment regardless of global role'],
    ['ws_server.py', '/course_id is not None and not _user_can_access_course/', 'realtime rejects unauthorized course rooms'],
    ['public/api/lms/grading/queue.php', "/'grade_course'/", 'grading requires course-scoped grade capability'],
    ['public/api/lms/analytics_metrics.php', "/'manage_course'/", 'analytics requires course-scoped management'],
];
foreach ($sourceChecks as [$file, $pattern, $message]) {
    $source = (string)file_get_contents($root . '/' . $file);
    $checks[] = [(bool)preg_match($pattern, $source), $message];
}

$failed = array_map(
    static fn(array $check): string => $check[1],
    array_filter($checks, static fn(array $check): bool => !$check[0])
);
if ($failed) {
    fwrite(STDERR, implode(PHP_EOL, $failed) . PHP_EOL);
    exit(1);
}

echo "course authorization policy tests passed" . PHP_EOL;
