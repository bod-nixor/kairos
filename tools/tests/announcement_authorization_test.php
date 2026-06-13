<?php
declare(strict_types=1);

function mutate_announcement(array $actor, int $requestedCourseId, int $announcementId, array &$state, string $action): int
{
    $row = $state['announcements'][$announcementId] ?? null;
    if (!$row || $row['deleted_at'] !== null) {
        return 404;
    }
    if ((int)$row['course_id'] !== $requestedCourseId) {
        return 404;
    }
    $role = strtolower((string)$actor['role']);
    $assigned = $role === 'admin'
        || ($role === 'manager' && in_array($requestedCourseId, $state['manager_courses'][$actor['user_id']] ?? [], true));
    if (!$assigned) {
        return 403;
    }
    if ($action === 'update') {
        $state['announcements'][$announcementId]['title'] = 'Updated';
    } elseif ($action === 'delete') {
        $state['announcements'][$announcementId]['deleted_at'] = 'now';
    }
    return 200;
}

$base = [
    'manager_courses' => [30 => [101]],
    'announcements' => [
        1 => ['course_id' => 101, 'title' => 'Original', 'deleted_at' => null],
        2 => ['course_id' => 202, 'title' => 'Foreign', 'deleted_at' => null],
    ],
];
$cases = [
    [['user_id' => 10, 'role' => 'student'], 101, 1, 'update', 403],
    [['user_id' => 20, 'role' => 'ta'], 101, 1, 'delete', 403],
    [['user_id' => 30, 'role' => 'manager'], 202, 2, 'update', 403],
    [['user_id' => 30, 'role' => 'manager'], 101, 2, 'update', 404],
    [['user_id' => 30, 'role' => 'manager'], 101, 1, 'update', 200],
    [['user_id' => 40, 'role' => 'admin'], 202, 2, 'delete', 200],
];

$failed = [];
foreach ($cases as $index => [$actor, $courseId, $announcementId, $action, $expected]) {
    $state = $base;
    $actual = mutate_announcement($actor, $courseId, $announcementId, $state, $action);
    if ($actual !== $expected) {
        $failed[] = "case {$index} expected {$expected}, got {$actual}";
    }
    if ($actual === 200 && $action === 'update' && $state['announcements'][$announcementId]['title'] !== 'Updated') {
        $failed[] = "case {$index} did not update the existing record";
    }
    if ($actual === 200 && $action === 'delete' && $state['announcements'][$announcementId]['deleted_at'] === null) {
        $failed[] = "case {$index} did not soft delete the record";
    }
}

$root = dirname(__DIR__, 2);
foreach (['create', 'update', 'delete'] as $endpoint) {
    $source = (string)file_get_contents("{$root}/public/api/lms/announcements/{$endpoint}.php");
    $begin = strpos($source, 'beginTransaction()');
    $event = strpos($source, 'lms_emit_event');
    $commit = strpos($source, '$pdo->commit()');
    if ($begin === false || $event === false || $commit === false || !($begin < $event && $event < $commit)) {
        $failed[] = "{$endpoint} must write its outbox event inside the same transaction before commit";
    }
    if (!str_contains($source, "'manage_course_announcements'")) {
        $failed[] = "{$endpoint} must enforce manage_course_announcements";
    }
}

$list = (string)file_get_contents("{$root}/public/api/lms/announcements.php");
if (!str_contains($list, "a.status = 'published'")) {
    $failed[] = 'announcement list must hide drafts from non-managers';
}
$read = (string)file_get_contents("{$root}/public/api/lms/announcements_read.php");
if (!str_contains($read, 'announcement_id IN')) {
    $failed[] = 'announcement read tracking must validate announcement_id';
}

if ($failed) {
    fwrite(STDERR, implode(PHP_EOL, $failed) . PHP_EOL);
    exit(1);
}

echo "announcement authorization tests passed" . PHP_EOL;
