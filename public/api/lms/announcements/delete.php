<?php
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$user = lms_require_roles(['manager', 'admin']);
$input = lms_json_input();
$announcementId = (int)($input['announcement_id'] ?? 0);
if ($announcementId <= 0) {
    lms_error('validation_error', 'announcement_id required', 422);
}

$pdo = db();
$existing = lms_announcement_scope($pdo, $announcementId);
if (!$existing) {
    lms_error('not_found', 'Announcement not found', 404);
}
$courseId = (int)$existing['course_id'];
if (isset($input['course_id']) && (int)$input['course_id'] !== $courseId) {
    lms_error('not_found', 'Announcement not found in this course', 404);
}
lms_require_course_capability($user, 'manage_course_announcements', $courseId);

$actorId = (int)$user['user_id'];
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'UPDATE lms_announcements SET deleted_at = NOW()'
        . ' WHERE announcement_id = :announcement_id AND course_id = :course_id AND deleted_at IS NULL'
    );
    $stmt->execute([
        ':announcement_id' => $announcementId,
        ':course_id' => $courseId,
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('announcement update did not affect one row');
    }
    lms_announcement_audit($pdo, $announcementId, $courseId, $actorId, 'delete', $existing, null);
    $event = lms_announcement_event($actorId, $announcementId, $courseId, 'announcement.deleted');
    lms_emit_event($pdo, 'announcement.deleted', $event);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('announcement delete failed announcement_id=' . $announcementId . ' actor_id=' . $actorId);
    lms_error('announcement_delete_failed', 'Unable to delete announcement', 500);
}

lms_ok(['announcement_id' => $announcementId, 'deleted' => true]);
