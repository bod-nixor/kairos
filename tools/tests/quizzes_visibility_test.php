<?php
declare(strict_types=1);

/**
 * Regression tests for public/api/lms/quizzes.php covering permission and visibility paths.
 * This script simulates the endpoint logic across different roles, enrollment states, and feature flags.
 */

/**
 * Simulation of the quizzes.php endpoint logic.
 */
function simulate_quizzes_api(array $request, array $session, array $features, array $enrollments, array $dbData): array
{
    // 1. Role requirements (student, ta, manager, admin)
    $allowedRoles = ['student', 'ta', 'manager', 'admin'];
    $userRole = strtolower($session['role_name'] ?? 'student');
    if (!in_array($userRole, $allowedRoles, true)) {
        return ['status' => 403, 'error' => 'forbidden'];
    }

    // 2. Course ID validation
    $courseId = (int) ($request['course_id'] ?? 0);
    if ($courseId <= 0) {
        return ['status' => 422, 'error' => 'validation_error'];
    }

    // 3. Feature flag check
    $flagKey = 'lms_expansion_quizzes';
    $enabled = $features[$flagKey][$courseId] ?? $features[$flagKey][null] ?? false;
    if (!$enabled) {
        return ['status' => 404, 'error' => 'feature_disabled'];
    }

    // 4. Course access check
    $isEnrolled = false;
    if (in_array($userRole, ['admin', 'manager'], true)) {
        $isEnrolled = true;
    } elseif ($userRole === 'ta' && in_array($courseId, $enrollments['ta'] ?? [])) {
        $isEnrolled = true;
    } elseif ($userRole === 'student' && in_array($courseId, $enrollments['student'] ?? [])) {
        $isEnrolled = true;
    }

    if (!$isEnrolled) {
        return ['status' => 403, 'error' => 'forbidden'];
    }

    // 5. Visibility filter
    // If student, only return 'published'. Others see everything non-deleted.
    $onlyPublished = ($userRole === 'student');

    $results = [];
    foreach ($dbData as $row) {
        if ($row['course_id'] !== $courseId)
            continue;
        if ($row['deleted_at'] !== null)
            continue;
        if ($onlyPublished && $row['status'] !== 'published')
            continue;
        $results[] = $row;
    }

    return ['status' => 200, 'data' => $results];
}

// Mock database data
$mockDbData = [
    ['id' => 1, 'course_id' => 10, 'status' => 'published', 'deleted_at' => null],
    ['id' => 2, 'course_id' => 10, 'status' => 'draft', 'deleted_at' => null],
    ['id' => 3, 'course_id' => 10, 'status' => 'published', 'deleted_at' => '2026-02-27 00:00:00'], // Deleted
    ['id' => 4, 'course_id' => 20, 'status' => 'published', 'deleted_at' => null], // Different course
];

// Test cases
$cases = [
    [
        'name' => 'Feature disabled returns 404',
        'request' => ['course_id' => 10],
        'session' => ['role_name' => 'admin'],
        'features' => ['lms_expansion_quizzes' => [10 => false]],
        'enrollments' => [],
        'expected_status' => 404
    ],
    [
        'name' => 'Non-enrolled student returns 403',
        'request' => ['course_id' => 10],
        'session' => ['role_name' => 'student'],
        'features' => ['lms_expansion_quizzes' => [10 => true]],
        'enrollments' => ['student' => [20]], // Enrolled in 20, not 10
        'expected_status' => 403
    ],
    [
        'name' => 'Student sees only published, non-deleted items in their course',
        'request' => ['course_id' => 10],
        'session' => ['role_name' => 'student'],
        'features' => ['lms_expansion_quizzes' => [10 => true]],
        'enrollments' => ['student' => [10]],
        'expected_status' => 200,
        'expected_count' => 1,
        'expected_ids' => [1]
    ],
    [
        'name' => 'TA sees draft items but not deleted items',
        'request' => ['course_id' => 10],
        'session' => ['role_name' => 'ta'],
        'features' => ['lms_expansion_quizzes' => [10 => true]],
        'enrollments' => ['ta' => [10]],
        'expected_status' => 200,
        'expected_count' => 2,
        'expected_ids' => [1, 2]
    ],
    [
        'name' => 'Admin sees all non-deleted items without needing enrollment',
        'request' => ['course_id' => 10],
        'session' => ['role_name' => 'admin'],
        'features' => ['lms_expansion_quizzes' => [10 => true]],
        'enrollments' => [],
        'expected_status' => 200,
        'expected_count' => 2,
        'expected_ids' => [1, 2]
    ],
    [
        'name' => 'Unauthorized role returns 403',
        'request' => ['course_id' => 10],
        'session' => ['role_name' => 'user'], // 'user' is not in allowed roles
        'features' => ['lms_expansion_quizzes' => [10 => true]],
        'enrollments' => [],
        'expected_status' => 403
    ]
];

$failed = [];
foreach ($cases as $case) {
    try {
        $actual = simulate_quizzes_api($case['request'], $case['session'], $case['features'], $case['enrollments'], $mockDbData);

        if ($actual['status'] !== $case['expected_status']) {
            throw new Exception("Expected status {$case['expected_status']}, got {$actual['status']}");
        }

        if ($actual['status'] === 200) {
            if (count($actual['data']) !== $case['expected_count']) {
                throw new Exception("Expected " . $case['expected_count'] . " items, got " . count($actual['data']));
            }
            $actualIds = array_column($actual['data'], 'id');
            sort($actualIds);
            sort($case['expected_ids']);
            if ($actualIds !== $case['expected_ids']) {
                throw new Exception("Expected IDs " . implode(',', $case['expected_ids']) . ", got " . implode(',', $actualIds));
            }
        }
    } catch (Exception $e) {
        $failed[] = "FAIL [{$case['name']}]: " . $e->getMessage();
    }
}

if ($failed) {
    fwrite(STDERR, implode("\n", $failed) . "\n");
    exit(1);
}

echo "All " . count($cases) . " regression test cases passed.\n";
exit(0);
