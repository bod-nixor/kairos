<?php
declare(strict_types=1);

function has_resource_course_access(array $actor, int $courseId, array $state): bool
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

function simulate_resources_update(array $actor, array $in, array &$state): array
{
    $role = strtolower((string)($actor['role_name'] ?? ''));
    if (!in_array($role, ['manager', 'admin'], true)) {
        return ['status' => 403, 'error' => 'forbidden'];
    }

    $resourceId = (int)($in['resource_id'] ?? 0);
    $courseId = (int)($in['course_id'] ?? 0);
    if ($resourceId <= 0 || $courseId <= 0) {
        return ['status' => 422, 'error' => 'validation_error'];
    }

    if (!has_resource_course_access($actor, $courseId, $state)) {
        return ['status' => 403, 'error' => 'forbidden'];
    }

    $resourceIndex = null;
    foreach ($state['resources'] as $idx => $row) {
        if ((int)$row['resource_id'] === $resourceId && (int)$row['course_id'] === $courseId && $row['deleted_at'] === null) {
            $resourceIndex = $idx;
            break;
        }
    }
    if ($resourceIndex === null) {
        return ['status' => 404, 'error' => 'not_found'];
    }

    $title = array_key_exists('title', $in) ? trim((string)$in['title']) : null;
    $url = array_key_exists('url', $in) ? trim((string)$in['url']) : null;
    $published = array_key_exists('published', $in) ? $in['published'] : null;

    $updated = false;
    if ($title !== null && $title !== '') {
        $state['resources'][$resourceIndex]['title'] = $title;
        $updated = true;
    }

    if ($url !== null && $url !== '') {
        if (!preg_match('/^https?:\/\//i', $url)) {
            return ['status' => 422, 'error' => 'validation_error'];
        }
        $state['resources'][$resourceIndex]['drive_preview_url'] = $url;
        $meta = $state['resources'][$resourceIndex]['metadata_json'];
        if (!is_array($meta)) {
            $meta = [];
        }
        $meta['url'] = $url;
        $state['resources'][$resourceIndex]['metadata_json'] = $meta;
        $updated = true;
    }

    if ($published !== null) {
        $normalizedPublished = null;
        if ($published === 1 || $published === '1') {
            $normalizedPublished = 1;
        } elseif ($published === 0 || $published === '0') {
            $normalizedPublished = 0;
        } else {
            return ['status' => 422, 'error' => 'validation_error'];
        }
        $state['resources'][$resourceIndex]['published'] = $normalizedPublished;
        $updated = true;
    }

    if (!$updated) {
        return ['status' => 422, 'error' => 'validation_error'];
    }

    if ($title !== null && $title !== '') {
        foreach ($state['module_items'] as $idx => $row) {
            if ((int)$row['course_id'] !== $courseId) {
                continue;
            }
            if ((int)$row['entity_id'] !== $resourceId) {
                continue;
            }
            if (!in_array($row['item_type'], ['file', 'video', 'link'], true)) {
                continue;
            }
            $state['module_items'][$idx]['title'] = $title;
        }
    }

    return ['status' => 200, 'ok' => true];
}

$baseState = [
    'manager_courses' => [
        20 => [301],
    ],
    'resources' => [
        [
            'resource_id' => 900,
            'course_id' => 301,
            'title' => 'Original Resource',
            'drive_preview_url' => 'https://drive.google.com/file/d/abc/view',
            'metadata_json' => ['url' => 'https://drive.google.com/file/d/abc/view'],
            'published' => 1,
            'deleted_at' => null,
        ],
    ],
    'module_items' => [
        [
            'module_item_id' => 1,
            'course_id' => 301,
            'entity_id' => 900,
            'item_type' => 'file',
            'title' => 'Original Resource',
        ],
        [
            'module_item_id' => 2,
            'course_id' => 301,
            'entity_id' => 900,
            'item_type' => 'assignment',
            'title' => 'Should Stay',
        ],
    ],
];

$cases = [
    [
        'name' => 'student denied by RBAC',
        'actor' => ['user_id' => 50, 'role_name' => 'student'],
        'payload' => ['resource_id' => 900, 'course_id' => 301, 'title' => 'X'],
        'expect_status' => 403,
    ],
    [
        'name' => 'ta denied by RBAC',
        'actor' => ['user_id' => 51, 'role_name' => 'ta'],
        'payload' => ['resource_id' => 900, 'course_id' => 301, 'title' => 'X'],
        'expect_status' => 403,
    ],
    [
        'name' => 'manager denied without course access',
        'actor' => ['user_id' => 20, 'role_name' => 'manager'],
        'payload' => ['resource_id' => 900, 'course_id' => 999, 'title' => 'X'],
        'expect_status' => 403,
    ],
    [
        'name' => 'manager can update title and url and cascades module item title',
        'actor' => ['user_id' => 20, 'role_name' => 'manager'],
        'payload' => ['resource_id' => 900, 'course_id' => 301, 'title' => 'New Resource Title', 'url' => 'https://example.com/slides.pdf'],
        'expect_status' => 200,
        'assert' => static function (array $state): void {
            if ($state['resources'][0]['title'] !== 'New Resource Title') {
                throw new RuntimeException('resource title not updated');
            }
            if ($state['resources'][0]['drive_preview_url'] !== 'https://example.com/slides.pdf') {
                throw new RuntimeException('resource url not updated');
            }
            if (($state['resources'][0]['metadata_json']['url'] ?? '') !== 'https://example.com/slides.pdf') {
                throw new RuntimeException('metadata_json url not updated');
            }
            if ($state['module_items'][0]['title'] !== 'New Resource Title') {
                throw new RuntimeException('module item title did not cascade');
            }
            if ($state['module_items'][1]['title'] !== 'Should Stay') {
                throw new RuntimeException('non-resource module item title changed unexpectedly');
            }
        },
    ],
    [
        'name' => 'admin can update title/url',
        'actor' => ['user_id' => 1, 'role_name' => 'admin'],
        'payload' => ['resource_id' => 900, 'course_id' => 301, 'title' => 'Admin Title', 'url' => 'https://example.com/doc.pdf'],
        'expect_status' => 200,
    ],
    [
        'name' => 'missing ids returns validation error',
        'actor' => ['user_id' => 1, 'role_name' => 'admin'],
        'payload' => ['resource_id' => 0, 'course_id' => 0],
        'expect_status' => 422,
    ],
    [
        'name' => 'bad url returns validation error',
        'actor' => ['user_id' => 1, 'role_name' => 'admin'],
        'payload' => ['resource_id' => 900, 'course_id' => 301, 'url' => 'ftp://bad-url'],
        'expect_status' => 422,
    ],
    [
        'name' => 'no fields to update returns validation error',
        'actor' => ['user_id' => 1, 'role_name' => 'admin'],
        'payload' => ['resource_id' => 900, 'course_id' => 301],
        'expect_status' => 422,
    ],
    [
        'name' => 'manager can update published to draft (numeric 0)',
        'actor' => ['user_id' => 20, 'role_name' => 'manager'],
        'payload' => ['resource_id' => 900, 'course_id' => 301, 'published' => 0],
        'expect_status' => 200,
        'assert' => static function (array $state): void {
            if ((int)$state['resources'][0]['published'] !== 0) {
                throw new RuntimeException('published not updated to 0');
            }
        },
    ],
    [
        'name' => 'manager can update published to published (string "1")',
        'actor' => ['user_id' => 20, 'role_name' => 'manager'],
        'payload' => ['resource_id' => 900, 'course_id' => 301, 'published' => '1'],
        'expect_status' => 200,
        'assert' => static function (array $state): void {
            if ((int)$state['resources'][0]['published'] !== 1) {
                throw new RuntimeException('published not updated to 1');
            }
        },
    ],
    [
        'name' => 'invalid published value returns validation error',
        'actor' => ['user_id' => 1, 'role_name' => 'admin'],
        'payload' => ['resource_id' => 900, 'course_id' => 301, 'published' => 'invalid'],
        'expect_status' => 422,
    ],
];

$failed = [];
foreach ($cases as $case) {
    $state = $baseState;
    try {
        $actual = simulate_resources_update($case['actor'], $case['payload'], $state);
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

echo 'resources update endpoint tests passed' . PHP_EOL;
