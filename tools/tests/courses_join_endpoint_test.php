<?php
declare(strict_types=1);

function simulate_courses_join(array $sessionUser, array $payload, array &$studentCourses, array $courses, array $allowlist): array
{
    $role = strtolower((string)($sessionUser['role_name'] ?? ''));
    if ($role !== 'student') {
        return ['status' => 403, 'error' => 'forbidden'];
    }

    $courseId = (int)($payload['course_id'] ?? 0);
    if ($courseId <= 0) {
        return ['status' => 422, 'error' => 'validation_error'];
    }

    $course = $courses[$courseId] ?? null;
    if ($course === null) {
        return ['status' => 404, 'error' => 'not_found'];
    }

    $email = strtolower((string)($sessionUser['email'] ?? ''));
    $visibility = strtolower((string)($course['visibility'] ?? 'public'));

    $canJoin = $visibility === 'public';
    if (!$canJoin) {
        $canJoin = in_array($email, $allowlist[$courseId] ?? [], true);
    }

    if (!$canJoin) {
        return ['status' => 403, 'error' => 'forbidden'];
    }

    $key = $courseId . ':' . (int)$sessionUser['user_id'];
    $studentCourses[$key] = true;

    return ['status' => 200, 'data' => ['joined' => true]];
}

$courses = [
    10 => ['visibility' => 'public'],
    20 => ['visibility' => 'restricted'],
];

$allowlist = [
    20 => ['allowed@nixorcollege.edu.pk'],
];

$studentCourses = [];
$cases = [
    [
        'name' => 'non-student role denied',
        'session' => ['user_id' => 1, 'role_name' => 'manager', 'email' => 'x@nixorcollege.edu.pk'],
        'payload' => ['course_id' => 10],
        'status' => 403,
    ],
    [
        'name' => 'public course enrollment succeeds',
        'session' => ['user_id' => 4, 'role_name' => 'student', 'email' => 'any@nixorcollege.edu.pk'],
        'payload' => ['course_id' => 10],
        'status' => 200,
        'idempotent' => true,
    ],
    [
        'name' => 'restricted course denied when not allowlisted',
        'session' => ['user_id' => 2, 'role_name' => 'student', 'email' => 'blocked@nixorcollege.edu.pk'],
        'payload' => ['course_id' => 20],
        'status' => 403,
    ],
    [
        'name' => 'allowlisted restricted enrollment succeeds and is idempotent',
        'session' => ['user_id' => 3, 'role_name' => 'student', 'email' => 'allowed@nixorcollege.edu.pk'],
        'payload' => ['course_id' => 20],
        'status' => 200,
        'idempotent' => true,
    ],
];

$failed = [];
foreach ($cases as $case) {
    $first = simulate_courses_join($case['session'], $case['payload'], $studentCourses, $courses, $allowlist);
    if ($first['status'] !== $case['status']) {
        $failed[] = "{$case['name']} expected {$case['status']} got {$first['status']}";
        continue;
    }

    if (!empty($case['idempotent'])) {
        $before = count($studentCourses);
        $second = simulate_courses_join($case['session'], $case['payload'], $studentCourses, $courses, $allowlist);
        $after = count($studentCourses);
        if ($second['status'] !== 200 || $before !== $after) {
            $failed[] = "{$case['name']} idempotency failed";
        }
    }
}

if ($failed) {
    fwrite(STDERR, implode(PHP_EOL, $failed) . PHP_EOL);
    exit(1);
}

echo "courses_join endpoint tests passed" . PHP_EOL;
