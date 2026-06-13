<?php
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$user = lms_require_roles(['manager', 'admin']);
$input = lms_json_input();
$announcementId = (int)($input['announcement_id'] ?? 0);
if ($announcementId <= 0) {
    lms_error('validation_error', 'announcement_id required', 422);
}
$values = lms_announcement_input($input);

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
$action = 'update';
if ((string)$existing['status'] !== $values['status']) {
    $action = $values['status'] === 'published' ? 'publish' : 'unpublish';
}
$publishedAt = $values['status'] === 'published'
    ? ($existing['published_at'] ?: gmdate('Y-m-d H:i:s'))
    : null;

if (
    (string)$existing['title'] === $values['title']
    && (string)$existing['body'] === $values['body']
    && (string)$existing['status'] === $values['status']
) {
    lms_error('validation_error', 'No announcement changes supplied', 422);
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'UPDATE lms_announcements'
        . ' SET title = :title, body = :body, status = :status, published_at = :published_at'
        . ' WHERE announcement_id = :announcement_id AND course_id = :course_id AND deleted_at IS NULL'
    );
    $stmt->execute([
        ':title' => $values['title'],
        ':body' => $values['body'],
        ':status' => $values['status'],
        ':published_at' => $publishedAt,
        ':announcement_id' => $announcementId,
        ':course_id' => $courseId,
    ]);
    if ($stmt->rowCount() !== 1) {
        $pdo->rollBack();
        lms_error('not_found', 'Announcement not found', 404);
    }
    lms_announcement_audit($pdo, $announcementId, $courseId, $actorId, $action, $existing, $values);
    $event = lms_announcement_event(
        $actorId,
        $announcementId,
        $courseId,
        'announcement.updated',
        ['title' => $values['title'], 'status' => $values['status']]
    );
    lms_emit_event($pdo, 'announcement.updated', $event);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('announcement update failed announcement_id=' . $announcementId . ' actor_id=' . $actorId);
    lms_error('announcement_update_failed', 'Unable to update announcement', 500);
}

lms_ok(['announcement_id' => $announcementId]);
