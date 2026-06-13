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

function view_announcement(array $actor, int $courseId, int $announcementId, array $state): int
{
    $row = $state['announcements'][$announcementId] ?? null;
    if (!$row || $row['deleted_at'] !== null || (int)$row['course_id'] !== $courseId) {
        return 404;
    }
    $role = strtolower((string)$actor['role']);
    $hasCourseAccess = $role === 'admin'
        || in_array($courseId, $state['course_access'][$actor['user_id']] ?? [], true);
    if (!$hasCourseAccess) {
        return 403;
    }
    $canManage = $role === 'admin'
        || ($role === 'manager' && in_array($courseId, $state['manager_courses'][$actor['user_id']] ?? [], true));
    if ($row['status'] !== 'published' && !$canManage) {
        return 404;
    }
    return 200;
}

$base = [
    'manager_courses' => [30 => [101]],
    'course_access' => [10 => [101], 20 => [101], 30 => [101]],
    'announcements' => [
        1 => ['course_id' => 101, 'title' => 'Original', 'status' => 'published', 'deleted_at' => null],
        2 => ['course_id' => 202, 'title' => 'Foreign', 'status' => 'published', 'deleted_at' => null],
        3 => ['course_id' => 101, 'title' => 'Draft', 'status' => 'draft', 'deleted_at' => null],
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

$viewCases = [
    [['user_id' => 10, 'role' => 'student'], 101, 1, 200],
    [['user_id' => 20, 'role' => 'ta'], 101, 1, 200],
    [['user_id' => 10, 'role' => 'student'], 101, 3, 404],
    [['user_id' => 20, 'role' => 'ta'], 101, 3, 404],
    [['user_id' => 30, 'role' => 'manager'], 101, 3, 200],
    [['user_id' => 10, 'role' => 'student'], 202, 2, 403],
    [['user_id' => 40, 'role' => 'admin'], 202, 2, 200],
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
foreach ($viewCases as $index => [$actor, $courseId, $announcementId, $expected]) {
    $actual = view_announcement($actor, $courseId, $announcementId, $base);
    if ($actual !== $expected) {
        $failed[] = "view case {$index} expected {$expected}, got {$actual}";
    }
}

$root = dirname(__DIR__, 2);
foreach (['create', 'update', 'delete'] as $endpoint) {
    $source = (string)file_get_contents("{$root}/public/api/lms/announcements/{$endpoint}.php");
    $try = strpos($source, 'try {');
    $begin = strpos($source, 'beginTransaction()');
    $event = strpos($source, 'lms_emit_event');
    $commit = strpos($source, '$pdo->commit()');
    if ($begin === false || $event === false || $commit === false || !($begin < $event && $event < $commit)) {
        $failed[] = "{$endpoint} must write its outbox event inside the same transaction before commit";
    }
    if (!str_contains($source, "'manage_course_announcements'")) {
        $failed[] = "{$endpoint} must enforce manage_course_announcements";
    }
    if (in_array($endpoint, ['create', 'update'], true) && ($try === false || $begin === false || $try > $begin)) {
        $failed[] = "{$endpoint} must begin its transaction inside the protected try block";
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
if (!str_contains($read, 'LMS_MAX_ANNOUNCEMENT_READ_IDS') || !str_contains($read, "lms_error('validation_error', 'Too many announcement IDs'")) {
    $failed[] = 'announcement read tracking must reject oversized ID batches';
}
$rawCap = strpos($read, 'if (count($ids) > LMS_MAX_ANNOUNCEMENT_READ_IDS)');
$normalizationLoop = strpos($read, 'foreach ($ids as $rawId)');
if ($rawCap === false || $normalizationLoop === false || $rawCap > $normalizationLoop) {
    $failed[] = 'announcement read tracking must cap the raw ID batch before normalization';
}
if (str_contains($read, "array_map('intval', \$ids)")) {
    $failed[] = 'announcement read tracking must not coerce malformed IDs with intval';
}

$update = (string)file_get_contents("{$root}/public/api/lms/announcements/update.php");
$rowGuard = strpos($update, '$stmt->rowCount() !== 1');
$audit = strpos($update, 'lms_announcement_audit');
if ($rowGuard === false || $audit === false || $rowGuard > $audit) {
    $failed[] = 'announcement updates must verify one changed row before audit and event side effects';
}
if (!str_contains($update, 'No announcement changes supplied')) {
    $failed[] = 'announcement updates must reject no-op writes before starting side effects';
}

$detail = (string)file_get_contents("{$root}/public/api/lms/announcements/detail.php");
foreach (['a.announcement_id = :announcement_id', 'a.course_id = :course_id', "a.status = 'published'", '$canManage', 'lms_announcement_audit'] as $needle) {
    if (!str_contains($detail, $needle)) {
        $failed[] = "announcement detail endpoint is missing {$needle}";
    }
}
foreach (['$rawCourseId', '$rawAnnouncementId', "preg_match('/^[1-9][0-9]*$/D'"] as $needle) {
    if (!str_contains($detail, $needle)) {
        $failed[] = "announcement detail endpoint is missing strict query validation: {$needle}";
    }
}

$helpers = (string)file_get_contents("{$root}/public/api/lms/announcements/_helpers.php");
if (!str_contains($helpers, "'audience' => \$visibleToCourse ? 'course' : 'course_staff'")) {
    $failed[] = 'announcement events must declare course versus course_staff audience';
}
if (!str_contains($helpers, "if (\$status === 'published')")) {
    $failed[] = 'announcement events must omit titles for drafts';
}

$courseJs = (string)file_get_contents("{$root}/public/js/course.js");
foreach (['openAnnouncementDetail', 'View full announcement', 'announcements_read.php', 'k-notification-item--unread', 'Announcement unavailable'] as $needle) {
    if (!str_contains($courseJs, $needle)) {
        $failed[] = "course announcement UI is missing {$needle}";
    }
}
$localReadMutation = strpos($courseJs, 'notifications.forEach(n => { n.read = true; });');
$announcementReadCall = strpos($courseJs, "'./api/lms/announcements_read.php'");
if ($localReadMutation === false || $announcementReadCall === false || $localReadMutation < $announcementReadCall) {
    $failed[] = 'notification UI must update local read state only after persistence calls complete';
}

if ($failed) {
    fwrite(STDERR, implode(PHP_EOL, $failed) . PHP_EOL);
    exit(1);
}

echo "announcement authorization tests passed" . PHP_EOL;
