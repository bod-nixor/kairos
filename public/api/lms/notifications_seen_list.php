<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

$user = require_login();
$courseId = (int)($_GET['course_id'] ?? 0);
if ($courseId <= 0) {
    lms_error('validation_error', 'course_id required', 422);
}
lms_course_access($user, $courseId);

$pdo = db();
$st = $pdo->prepare('SELECT event_id FROM lms_notification_reads WHERE user_id = :uid AND course_id = :cid ORDER BY seen_at DESC LIMIT 1000');
$st->execute([':uid' => (int)$user['user_id'], ':cid' => $courseId]);
lms_ok(['event_ids' => $st->fetchAll(PDO::FETCH_COLUMN)]);
