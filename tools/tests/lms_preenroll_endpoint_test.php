<?php
declare(strict_types=1);

function preenroll_post(array &$entries, int $courseId, string $email, int $createdBy): array
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['status' => 422];
    }
    foreach ($entries as &$entry) {
        if ($entry['course_id'] === $courseId && $entry['email'] === $email) {
            $entry['created_by'] = $createdBy;
            return ['status' => 200, 'entry' => $entry];
        }
    }
    $new = [
        'id' => count($entries) + 1,
        'course_id' => $courseId,
        'email' => $email,
        'created_by' => $createdBy,
        'claimed_user_id' => null,
    ];
    $entries[] = $new;
    return ['status' => 200, 'entry' => $new];
}

function preenroll_get(array $entries, int $courseId): array
{
    $rows = [];
    foreach ($entries as $e) {
        if ($e['course_id'] !== $courseId) continue;
        $rows[] = [
            'id' => (int)$e['id'],
            'created_by' => isset($e['created_by']) ? (int)$e['created_by'] : null,
            'claimed_user_id' => $e['claimed_user_id'] !== null ? (int)$e['claimed_user_id'] : null,
            'status' => $e['claimed_user_id'] !== null ? 'claimed' : 'unclaimed',
        ];
    }
    return ['status' => 200, 'rows' => $rows];
}

function preenroll_delete(array &$entries, int $courseId, ?int $id, ?string $email): array
{
    $before = count($entries);
    $email = $email !== null ? strtolower(trim($email)) : null;
    $entries = array_values(array_filter($entries, function ($e) use ($courseId, $id, $email) {
        if ($e['course_id'] !== $courseId) return true;
        if ($id !== null && $id > 0) {
            return (int)$e['id'] !== $id;
        }
        if ($email !== null && $email !== '') {
            return $e['email'] !== $email;
        }
        return true;
    }));
    return ['status' => 200, 'deleted' => count($entries) < $before];
}

$entries = [
    ['id' => 1, 'course_id' => 10, 'email' => 'claimed@example.com', 'created_by' => 5, 'claimed_user_id' => 88],
    ['id' => 2, 'course_id' => 10, 'email' => 'open@example.com', 'created_by' => 5, 'claimed_user_id' => null],
];

$failed = [];
$res = preenroll_post($entries, 10, ' Open@Example.com ', 11);
if ($res['status'] !== 200) $failed[] = 'post should succeed for valid email';
$get = preenroll_get($entries, 10);
if (count($get['rows']) < 2) $failed[] = 'get should return course entries';
$statuses = array_column($get['rows'], 'status');
if (!in_array('claimed', $statuses, true) || !in_array('unclaimed', $statuses, true)) {
    $failed[] = 'get should expose claimed and unclaimed statuses';
}
$delById = preenroll_delete($entries, 10, 2, null);
if ($delById['deleted'] !== true) $failed[] = 'delete by id should report deleted=true';
$delByEmail = preenroll_delete($entries, 10, null, 'claimed@example.com');
if ($delByEmail['deleted'] !== true) $failed[] = 'delete by email should report deleted=true';
$bad = preenroll_post($entries, 10, 'not-an-email', 1);
if ($bad['status'] !== 422) $failed[] = 'invalid email should be 422';

if ($failed) {
    fwrite(STDERR, implode(PHP_EOL, $failed) . PHP_EOL);
    exit(1);
}

echo "lms preenroll endpoint tests passed" . PHP_EOL;
