<?php
/**
 * POST /api/lms/announcements/create.php
 * Create a new announcement in a course.
 *
 * Payload: { course_id: int, title: string, body: string }
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';

$user = lms_require_roles(['manager', 'admin']);
$in = lms_json_input();
$courseId = (int)($in['course_id'] ?? 0);

if ($courseId <= 0 || empty($in['title']) || empty($in['body'])) {
    lms_error('validation_error', 'course_id, title, body required', 422);
}

// Enforce course-scoped access (prevents cross-course announcement injection)
lms_course_access($user, $courseId);

// Managers must be explicitly verified as course staff (not just global managers)
if ($user['role_name'] === 'manager') {
    $pdo = db();
    $staffStmt = $pdo->prepare(
        'SELECT 1 FROM course_staff WHERE user_id = :uid AND course_id = :cid LIMIT 1'
    );
    $staffStmt->execute([':uid' => (int)$user['user_id'], ':cid' => $courseId]);
    if (!$staffStmt->fetchColumn()) {
        lms_error('forbidden', 'You are not staff in this course', 403);
    }
}

$pdo = db();

// Insert announcement
$stmt = $pdo->prepare(
    'INSERT INTO lms_announcements (course_id, title, body, created_by)
     VALUES (:c, :t, :b, :u)'
);
$stmt->execute([
    ':c' => $courseId,
    ':t' => $in['title'],
    ':b' => $in['body'],
    ':u' => (int)$user['user_id'],
]);
$id = (int)$pdo->lastInsertId();

// Emit event for realtime subscribers
$event = [
    'event_name' => 'announcement.created',
    'event_id' => lms_uuid_v4(),
    'occurred_at' => gmdate('c'),
    'actor_id' => (int)$user['user_id'],
    'entity_type' => 'announcement',
    'entity_id' => $id,
    'course_id' => $courseId,
    'title' => $in['title'],
];
lms_emit_event($pdo, 'announcement.created', $event);

lms_ok(['announcement_id' => $id]);
