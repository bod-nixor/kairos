<?php
/**
 * POST /api/lms/announcements_read.php
 * Marks announcements as read for the current user.
 *
 * Payload: { course_id: int, ids: int[] }
 *
 * Uses lms_notification_reads for persistent read-tracking
 * since announcements don't have their own read-tracking table.
 */
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

$user = require_login();
$in = lms_json_input();
$courseId = (int)($in['course_id'] ?? 0);
$ids = $in['ids'] ?? [];

if ($courseId <= 0) {
    lms_error('validation_error', 'course_id required', 422);
}

if (!is_array($ids) || empty($ids)) {
    lms_error('validation_error', 'ids must be a non-empty array', 422);
}

lms_course_access($user, $courseId);

// Deduplicate and normalize IDs
$ids = array_values(array_unique(array_map('intval', $ids)));

$userId = (int)$user['user_id'];
$pdo = db();

// Validate that all announcement IDs belong to this course
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$validateStmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM lms_announcements WHERE id IN ($placeholders) AND course_id = ?");
$validateIds = array_merge($ids, [$courseId]);
$validateStmt->execute($validateIds);
$result = $validateStmt->fetch(PDO::FETCH_ASSOC);
if ((int)$result['cnt'] !== count($ids)) {
    lms_error('validation_error', 'One or more announcement IDs do not belong to this course', 422);
}

// Mark each announcement id as seen using the notification reads table
$sql = 'INSERT IGNORE INTO lms_notification_reads (user_id, course_id, event_id, seen_at)
        VALUES (:user_id, :course_id, :event_id, CURRENT_TIMESTAMP)';
$stmt = $pdo->prepare($sql);

$marked = 0;
foreach ($ids as $id) {
    $eventId = 'announcement:' . $id;
    $stmt->execute([
        ':user_id' => $userId,
        ':course_id' => $courseId,
        ':event_id' => $eventId,
    ]);
    if ($stmt->rowCount() > 0) {
        $marked++;
    }
}

lms_ok(['marked' => $marked]);
