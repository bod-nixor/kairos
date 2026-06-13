<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';

function lms_announcement_input(array $input): array
{
    $title = trim((string)($input['title'] ?? ''));
    $body = trim((string)($input['body'] ?? ''));
    $status = strtolower(trim((string)($input['status'] ?? 'published')));

    if ($title === '' || $body === '') {
        lms_error('validation_error', 'title and body required', 422);
    }
    $titleLength = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
    if ($titleLength > 255) {
        lms_error('validation_error', 'title must be 255 characters or fewer', 422);
    }
    if (!in_array($status, ['draft', 'published'], true)) {
        lms_error('validation_error', 'status must be draft or published', 422);
    }

    return ['title' => $title, 'body' => $body, 'status' => $status];
}

function lms_announcement_scope(PDO $pdo, int $announcementId): ?array
{
    if ($announcementId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT announcement_id, course_id, title, body, status, published_at, created_by, created_at, updated_at'
        . ' FROM lms_announcements'
        . ' WHERE announcement_id = :announcement_id AND deleted_at IS NULL'
        . ' LIMIT 1'
    );
    $stmt->execute([':announcement_id' => $announcementId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function lms_announcement_audit(
    PDO $pdo,
    int $announcementId,
    int $courseId,
    int $actorId,
    string $action,
    ?array $oldValues,
    ?array $newValues
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO lms_announcement_audit'
        . ' (announcement_id, course_id, actor_id, action, old_values_json, new_values_json, created_at)'
        . ' VALUES (:announcement_id, :course_id, :actor_id, :action, :old_values, :new_values, NOW())'
    );
    $stmt->execute([
        ':announcement_id' => $announcementId,
        ':course_id' => $courseId,
        ':actor_id' => $actorId,
        ':action' => $action,
        ':old_values' => $oldValues === null ? null : json_encode($oldValues, JSON_UNESCAPED_SLASHES),
        ':new_values' => $newValues === null ? null : json_encode($newValues, JSON_UNESCAPED_SLASHES),
    ]);
}

function lms_announcement_event(
    int $actorId,
    int $announcementId,
    int $courseId,
    string $eventName,
    array $delta = []
): array {
    return array_merge([
        'event_name' => $eventName,
        'event_id' => lms_uuid_v4(),
        'occurred_at' => gmdate('c'),
        'actor_id' => $actorId,
        'entity_type' => 'announcement',
        'entity_id' => $announcementId,
        'announcement_id' => $announcementId,
        'course_id' => $courseId,
    ], $delta);
}

function lms_announcement_event_delta(array $values, ?string $previousStatus = null): array
{
    $status = (string)($values['status'] ?? 'draft');
    $visibleToCourse = $status === 'published' || $previousStatus === 'published';
    $delta = [
        'status' => $status,
        'audience' => $visibleToCourse ? 'course' : 'course_staff',
    ];
    if ($status === 'published') {
        $delta['title'] = (string)($values['title'] ?? '');
    }
    return $delta;
}
