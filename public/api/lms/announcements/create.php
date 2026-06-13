<?php
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$user = lms_require_roles(['manager', 'admin']);
$input = lms_json_input();
$courseId = (int)($input['course_id'] ?? 0);
if ($courseId <= 0) {
    lms_error('validation_error', 'course_id required', 422);
}
$values = lms_announcement_input($input);
lms_require_course_capability($user, 'manage_course_announcements', $courseId);

$pdo = db();
$actorId = (int)$user['user_id'];
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'INSERT INTO lms_announcements'
        . ' (course_id, title, body, status, published_at, created_by)'
        . ' VALUES (:course_id, :title, :body, :status, :published_at, :created_by)'
    );
    $stmt->execute([
        ':course_id' => $courseId,
        ':title' => $values['title'],
        ':body' => $values['body'],
        ':status' => $values['status'],
        ':published_at' => $values['status'] === 'published' ? gmdate('Y-m-d H:i:s') : null,
        ':created_by' => $actorId,
    ]);
    $announcementId = (int)$pdo->lastInsertId();
    lms_announcement_audit(
        $pdo,
        $announcementId,
        $courseId,
        $actorId,
        'create',
        null,
        $values
    );
    $event = lms_announcement_event(
        $actorId,
        $announcementId,
        $courseId,
        'announcement.created',
        ['title' => $values['title'], 'status' => $values['status']]
    );
    lms_emit_event($pdo, 'announcement.created', $event);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('announcement create failed course_id=' . $courseId . ' actor_id=' . $actorId);
    lms_error('announcement_create_failed', 'Unable to create announcement', 500);
}

lms_ok(['announcement_id' => $announcementId]);
