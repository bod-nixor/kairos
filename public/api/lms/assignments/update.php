<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';

$user = lms_require_roles(['manager', 'admin']);
$in = lms_json_input();
$id = (int)($in['assignment_id'] ?? 0);
if ($id <= 0) {
    lms_error('validation_error', 'assignment_id required', 422);
}

$pdo = db();
$existingStmt = $pdo->prepare('SELECT assignment_id, course_id, title, instructions, due_at, late_allowed, max_points, status FROM lms_assignments WHERE assignment_id=:id AND deleted_at IS NULL LIMIT 1');
$existingStmt->execute([':id' => $id]);
$existing = $existingStmt->fetch();
if (!$existing) {
    lms_error('not_found', 'Assignment not found', 404);
}

lms_course_access($user, (int)$existing['course_id']);

$allowedStatus = ['draft', 'published', 'archived'];
if (array_key_exists('status', $in) && !in_array((string)$in['status'], $allowedStatus, true)) {
    lms_error('validation_error', 'status must be draft, published, or archived', 422);
}

$title = array_key_exists('title', $in) ? trim((string)$in['title']) : (string)$existing['title'];
$instructions = array_key_exists('instructions', $in) ? $in['instructions'] : $existing['instructions'];
$dueAt = array_key_exists('due_at', $in) ? $in['due_at'] : $existing['due_at'];
$lateAllowed = array_key_exists('late_allowed', $in) ? (!empty($in['late_allowed']) ? 1 : 0) : (int)$existing['late_allowed'];
$maxPoints = array_key_exists('max_points', $in) ? (float)$in['max_points'] : (float)$existing['max_points'];
$status = array_key_exists('status', $in) ? (string)$in['status'] : (string)$existing['status'];

$pdo->prepare('UPDATE lms_assignments SET title=:t, instructions=:i, due_at=:d, late_allowed=:l, max_points=:m, status=:st, updated_at=CURRENT_TIMESTAMP WHERE assignment_id=:id')->execute([
    ':t' => $title,
    ':i' => $instructions,
    ':d' => $dueAt,
    ':l' => $lateAllowed,
    ':m' => $maxPoints,
    ':st' => $status,
    ':id' => $id,
]);

lms_ok(['updated' => true]);
