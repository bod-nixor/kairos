<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

$user = require_login();
$in = lms_json_input();
$courseId = (int)($in['course_id'] ?? 0);
$eventIds = isset($in['event_ids']) && is_array($in['event_ids']) ? $in['event_ids'] : [];
if ($courseId <= 0) {
    lms_error('validation_error', 'course_id required', 422);
}

lms_course_access($user, $courseId);
$pdo = db();
$st = $pdo->prepare('INSERT INTO lms_notification_reads (user_id, course_id, event_id) VALUES (:uid, :cid, :event_id) ON DUPLICATE KEY UPDATE seen_at = CURRENT_TIMESTAMP');
foreach ($eventIds as $eventId) {
    $eventId = trim((string)$eventId);
    if ($eventId === '') {
        continue;
    }
    if (strlen($eventId) > 128) {
        lms_error('validation_error', 'event_id length must be <= 128 characters', 422);
    }
    $st->execute([':uid' => (int)$user['user_id'], ':cid' => $courseId, ':event_id' => $eventId]);
}

lms_ok(['seen' => true]);
