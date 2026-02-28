<?php
declare(strict_types=1);

function manager_controls_course(array $managerCourses, int $userId, int $courseId): bool
{
    return in_array($courseId, $managerCourses[$userId] ?? [], true);
}

function simulate_course_access_post(array $actor, array $payload, array &$state): array
{
    $userId = (int)($actor['user_id'] ?? 0);
    $role = strtolower((string)($actor['role_name'] ?? ''));
    $courseId = (int)($payload['course_id'] ?? 0);

    if (!in_array($role, ['manager', 'admin'], true)) {
        return ['status' => 403, 'error' => 'forbidden'];
    }
    if (!manager_controls_course($state['manager_courses'], $userId, $courseId) && $role !== 'admin') {
        return ['status' => 403, 'error' => 'forbidden'];
    }

    if (isset($payload['visibility'])) {
        $state['courses'][$courseId]['visibility'] = strtolower((string)$payload['visibility']) === 'restricted' ? 'restricted' : 'public';
    }

    foreach (($payload['allowlist_add'] ?? []) as $email) {
        $email = strtolower(trim((string)$email));
        if ($email !== '') {
            $state['allowlist'][$courseId][$email] = true;
        }
    }

    foreach (($payload['allowlist_remove'] ?? []) as $email) {
        $email = strtolower(trim((string)$email));
        if ($email !== '') {
            unset($state['allowlist'][$courseId][$email]);
        }
    }

    foreach (($payload['pre_enroll_add'] ?? []) as $email) {
        $email = strtolower(trim((string)$email));
        if ($email === '') {
            continue;
        }
        $state['pre_enroll'][$courseId][$email] = true;
        $existingUserId = $state['users_by_email'][$email] ?? 0;
        if ($existingUserId > 0) {
            $state['student_courses'][$courseId . ':' . $existingUserId] = true;
        }
    }

    return ['status' => 200];
}

$state = [
    'manager_courses' => [
        10 => [100],
        20 => [200],
    ],
    'courses' => [
        100 => ['visibility' => 'public'],
        200 => ['visibility' => 'public'],
    ],
    'allowlist' => [100 => [], 200 => []],
    'pre_enroll' => [100 => [], 200 => []],
    'users_by_email' => ['known@nixorcollege.edu.pk' => 501],
    'student_courses' => [],
];

$failed = [];

$ok = simulate_course_access_post(['user_id' => 10, 'role_name' => 'manager'], [
    'course_id' => 100,
    'visibility' => 'restricted',
    'allowlist_add' => ['s1@nixorcollege.edu.pk'],
    'pre_enroll_add' => ['known@nixorcollege.edu.pk', 'future@nixorcollege.edu.pk'],
], $state);
if ($ok['status'] !== 200) {
    $failed[] = 'manager should be able to modify their course';
}

$deny = simulate_course_access_post(['user_id' => 10, 'role_name' => 'manager'], [
    'course_id' => 200,
    'allowlist_add' => ['x@nixorcollege.edu.pk'],
], $state);
if ($deny['status'] !== 403) {
    $failed[] = 'manager should be denied for other courses';
}

if (!isset($state['allowlist'][100]['s1@nixorcollege.edu.pk']) || isset($state['allowlist'][200]['s1@nixorcollege.edu.pk'])) {
    $failed[] = 'allowlist add/remove should only affect target course';
}

if (!isset($state['student_courses']['100:501'])) {
    $failed[] = 'existing pre_enroll user should be auto-enrolled';
}
if (!isset($state['pre_enroll'][100]['future@nixorcollege.edu.pk'])) {
    $failed[] = 'future user pre_enroll should be stored';
}

if ($failed) {
    fwrite(STDERR, implode(PHP_EOL, $failed) . PHP_EOL);
    exit(1);
}

echo "manager course_access endpoint tests passed" . PHP_EOL;
