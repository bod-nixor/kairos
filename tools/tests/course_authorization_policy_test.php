<?php
declare(strict_types=1);

function policy_allows(array $actor, string $capability, int $courseId, array $state): bool
{
    $role = strtolower((string)($actor['role'] ?? ''));
    $userId = (int)($actor['user_id'] ?? 0);
    if ($role === 'admin') {
        return in_array($capability, [
            'view_course', 'manage_course', 'grade_course', 'update_student_progress',
            'manage_course_announcements', 'assign_course_staff', 'create_course',
        ], true);
    }
    $assigned = in_array($courseId, $state[$role][$userId] ?? [], true);
    return match ($capability) {
        'view_course' => $assigned,
        'manage_course', 'manage_course_announcements' => $role === 'manager' && $assigned,
        'grade_course', 'update_student_progress' => in_array($role, ['ta', 'manager'], true) && $assigned,
        'assign_course_staff', 'create_course' => false,
        default => false,
    };
}

$state = [
    'student' => [10 => [101]],
    'ta' => [20 => [101]],
    'manager' => [30 => [101]],
];
$student = ['user_id' => 10, 'role' => 'student'];
$ta = ['user_id' => 20, 'role' => 'ta'];
$manager = ['user_id' => 30, 'role' => 'manager'];
$admin = ['user_id' => 40, 'role' => 'admin'];

$checks = [
    [!policy_allows($student, 'manage_course', 101, $state), 'student cannot update course settings'],
    [!policy_allows($student, 'grade_course', 101, $state), 'student cannot access manager grading workflows'],
    [!policy_allows($student, 'update_student_progress', 101, $state), 'student cannot update another student progress'],
    [!policy_allows($student, 'manage_course_announcements', 101, $state), 'student cannot mutate announcements'],
    [!policy_allows($ta, 'manage_course', 101, $state), 'TA cannot edit course settings'],
    [!policy_allows($ta, 'manage_course_announcements', 101, $state), 'TA cannot mutate announcements'],
    [policy_allows($ta, 'grade_course', 101, $state), 'TA can grade in an assigned course'],
    [!policy_allows($ta, 'grade_course', 999, $state), 'TA cannot grade outside assigned courses'],
    [!policy_allows($ta, 'update_student_progress', 999, $state), 'TA cannot update progress outside assigned courses'],
    [policy_allows($manager, 'manage_course', 101, $state), 'manager can manage an assigned course'],
    [!policy_allows($manager, 'manage_course', 999, $state), 'manager cannot access another manager course'],
    [!policy_allows($manager, 'assign_course_staff', 101, $state), 'manager cannot assign course staff'],
    [policy_allows($admin, 'create_course', 999, $state), 'admin can create courses'],
    [policy_allows($admin, 'assign_course_staff', 999, $state), 'admin can assign staff'],
    [policy_allows($admin, 'manage_course', 999, $state), 'admin can manage every course'],
];

$root = dirname(__DIR__, 2);
$sourceChecks = [
    ['public/api/lms/courses/_settings_common.php', "/'manage_course'/", 'course settings use manage_course capability'],
    ['public/api/lms/grading/submission.php', '/lms_require_submission_access/', 'submission detail uses stored submission scope'],
    ['public/api/lms/grading/submission/grade.php', '/lms_require_submission_access/', 'grade mutation uses stored submission scope'],
    ['public/api/lms/grading/submission/release.php', '/lms_require_submission_access/', 'grade release uses stored submission scope'],
    ['public/api/lms/assignments/submissions.php', "/g2\\.status = 'released'/", 'student grade reads filter to released grades'],
    ['public/api/lms/module_items/update.php', '/module_item_id = :id AND course_id = :cid/', 'module item update scopes object to course'],
    ['public/api/lms/assignments/get.php', "/assignment\\['course_id'\\].*courseId/", 'assignment lookup rejects foreign course context'],
    ['public/api/lms/announcements/update.php', '/Announcement not found in this course/', 'announcement update rejects foreign course context'],
    ['public/api/queue_participants.php', '/rbac_can_view_queue/', 'queue participants remain scope checked'],
    ['public/api/queue_eta.php', '/rbac_can_view_queue/', 'queue ETA remains scope checked'],
    ['public/api/lms/resources/download.php', '/lms_authorize_resource_access/', 'protected downloads remain policy checked'],
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
