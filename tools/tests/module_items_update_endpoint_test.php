<?php
declare(strict_types=1);

function has_course_access(array $actor, int $courseId, array $state): bool
{
    $role = strtolower((string)($actor['role_name'] ?? ''));
    if ($role === 'admin') {
        return true;
    }
    if ($role === 'manager') {
        $uid = (int)($actor['user_id'] ?? 0);
        return in_array($courseId, $state['manager_courses'][$uid] ?? [], true);
    }
    return false;
}

function parse_binary_flag(array $in, string $key, array &$error): ?int
{
    if (!array_key_exists($key, $in)) {
        return null;
    }
    $raw = $in[$key];
    if ($raw === 1 || $raw === '1') {
        return 1;
    }
    if ($raw === 0 || $raw === '0') {
        return 0;
    }
    $error = ['status' => 400, 'error' => 'validation_error'];
    return null;
}

function simulate_module_items_update(array $actor, array $in, array &$state): array
{
    $role = strtolower((string)($actor['role_name'] ?? ''));
    if (!in_array($role, ['manager', 'admin'], true)) {
        return ['status' => 403, 'error' => 'forbidden'];
    }

    $moduleItemId = (int)($in['module_item_id'] ?? 0);
    $courseId = (int)($in['course_id'] ?? 0);
    if ($moduleItemId <= 0 || $courseId <= 0) {
        return ['status' => 422, 'error' => 'validation_error'];
    }

    if (!has_course_access($actor, $courseId, $state)) {
        return ['status' => 403, 'error' => 'forbidden'];
    }

    $targetIndex = null;
    foreach ($state['module_items'] as $idx => $row) {
        if ((int)$row['module_item_id'] === $moduleItemId && (int)$row['course_id'] === $courseId) {
            $targetIndex = $idx;
            break;
        }
    }
    if ($targetIndex === null) {
        return ['status' => 404, 'error' => 'not_found'];
    }

    $title = array_key_exists('title', $in) ? trim((string)$in['title']) : null;
    $parseError = [];
    $publishedFlag = parse_binary_flag($in, 'published', $parseError);
    if ($parseError !== []) {
        return $parseError;
    }
    $requiredFlag = parse_binary_flag($in, 'required', $parseError);
    if ($parseError !== []) {
        return $parseError;
    }

    $updated = false;
    if ($title !== null && $title !== '') {
        $state['module_items'][$targetIndex]['title'] = $title;
        $updated = true;
    }
    if ($publishedFlag !== null) {
        $state['module_items'][$targetIndex]['published_flag'] = $publishedFlag;
        $updated = true;
    }
    if ($requiredFlag !== null) {
        $state['module_items'][$targetIndex]['required_flag'] = $requiredFlag;
        $updated = true;
    }

    if (!$updated) {
        return ['status' => 422, 'error' => 'validation_error'];
    }

    return ['status' => 200, 'ok' => true];
}

$baseState = [
    'manager_courses' => [
        10 => [101],
    ],
    'module_items' => [
        [
            'module_item_id' => 501,
            'course_id' => 101,
            'title' => 'Old Title',
            'published_flag' => 1,
            'required_flag' => 0,
        ],
        [
            'module_item_id' => 501,
            'course_id' => 202,
            'title' => 'Other Course Same ID',
            'published_flag' => 1,
            'required_flag' => 1,
        ],
    ],
];

$cases = [
    [
        'name' => 'student denied by RBAC',
        'actor' => ['user_id' => 30, 'role_name' => 'student'],
        'payload' => ['module_item_id' => 501, 'course_id' => 101, 'title' => 'X'],
        'expect_status' => 403,
    ],
    [
        'name' => 'ta denied by RBAC',
        'actor' => ['user_id' => 31, 'role_name' => 'ta'],
        'payload' => ['module_item_id' => 501, 'course_id' => 101, 'title' => 'X'],
        'expect_status' => 403,
    ],
    [
        'name' => 'manager denied when course access missing',
        'actor' => ['user_id' => 10, 'role_name' => 'manager'],
        'payload' => ['module_item_id' => 501, 'course_id' => 202, 'title' => 'X'],
        'expect_status' => 403,
    ],
    [
        'name' => 'manager allowed with valid update',
        'actor' => ['user_id' => 10, 'role_name' => 'manager'],
        'payload' => ['module_item_id' => 501, 'course_id' => 101, 'title' => 'Updated', 'published' => 0, 'required' => 1],
        'expect_status' => 200,
        'assert' => static function (array $state): void {
            if ($state['module_items'][0]['title'] !== 'Updated') {
                throw new RuntimeException('title not updated');
            }
            if ((int)$state['module_items'][0]['published_flag'] !== 0) {
                throw new RuntimeException('published_flag not updated');
            }
            if ((int)$state['module_items'][0]['required_flag'] !== 1) {
                throw new RuntimeException('required_flag not updated');
            }
            if ($state['module_items'][1]['title'] !== 'Other Course Same ID') {
                throw new RuntimeException('cross-course row mutated');
            }
        },
    ],
    [
        'name' => 'admin allowed with valid update',
        'actor' => ['user_id' => 1, 'role_name' => 'admin'],
        'payload' => ['module_item_id' => 501, 'course_id' => 202, 'title' => 'Admin Updated'],
        'expect_status' => 200,
    ],
    [
        'name' => 'missing ids validation',
        'actor' => ['user_id' => 1, 'role_name' => 'admin'],
        'payload' => ['module_item_id' => 0, 'course_id' => 0, 'title' => 'X'],
        'expect_status' => 422,
    ],
    [
        'name' => 'empty title only returns no fields validation',
        'actor' => ['user_id' => 1, 'role_name' => 'admin'],
        'payload' => ['module_item_id' => 501, 'course_id' => 101, 'title' => '   '],
        'expect_status' => 422,
    ],
    [
        'name' => 'no updatable fields validation',
        'actor' => ['user_id' => 1, 'role_name' => 'admin'],
        'payload' => ['module_item_id' => 501, 'course_id' => 101],
        'expect_status' => 422,
    ],
    [
        'name' => 'invalid published value returns 400',
        'actor' => ['user_id' => 1, 'role_name' => 'admin'],
        'payload' => ['module_item_id' => 501, 'course_id' => 101, 'published' => 2],
        'expect_status' => 400,
    ],
    [
        'name' => 'invalid required value returns 400',
        'actor' => ['user_id' => 1, 'role_name' => 'admin'],
        'payload' => ['module_item_id' => 501, 'course_id' => 101, 'required' => 'yes'],
        'expect_status' => 400,
    ],
];

$failed = [];
foreach ($cases as $case) {
    $state = $baseState;
    try {
        $actual = simulate_module_items_update($case['actor'], $case['payload'], $state);
        if ((int)$actual['status'] !== (int)$case['expect_status']) {
            throw new RuntimeException('expected status ' . $case['expect_status'] . ', got ' . $actual['status']);
        }
        if (isset($case['assert']) && is_callable($case['assert'])) {
            $case['assert']($state);
        }
    } catch (Throwable $e) {
        $failed[] = 'FAIL [' . $case['name'] . ']: ' . $e->getMessage();
    }
}

if ($failed !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failed) . PHP_EOL);
    exit(1);
}

echo 'module_items update endpoint tests passed' . PHP_EOL;
