<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';

lms_require_feature(['lms_assignments', 'assignments']);
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
$allowedTransitions = [
    'draft' => ['published', 'archived'],
    'published' => ['archived'],
    'archived' => [],
];
if (array_key_exists('status', $in)) {
    $targetStatus = (string)$in['status'];
    if (!in_array($targetStatus, $allowedStatus, true)) {
        lms_error('validation_error', 'status must be draft, published, or archived', 422);
    }
    $currentStatus = (string)$existing['status'];
    if ($targetStatus !== $currentStatus && !in_array($targetStatus, $allowedTransitions[$currentStatus] ?? [], true)) {
        lms_error('validation_error', 'invalid status transition', 422);
    }
}

$title = array_key_exists('title', $in) ? trim((string)$in['title']) : (string)$existing['title'];
if ($title === '') {
    lms_error('validation_error', 'title cannot be blank', 422);
}

$instructionsRaw = $in['instructions'] ?? $in['description'] ?? $in['description_html'] ?? $existing['instructions'];
if ($instructionsRaw === null) {
    $instructions = null;
} elseif (is_scalar($instructionsRaw)) {
    $instructions = (string)$instructionsRaw;
} else {
    lms_error('validation_error', 'instructions must be a string', 422);
}

$dueAt = $existing['due_at'];
if (array_key_exists('due_at', $in)) {
    if ($in['due_at'] === null || $in['due_at'] === '') {
        $dueAt = null;
    } else {
        $candidate = (string)$in['due_at'];
        if (strtotime($candidate) === false) {
            lms_error('validation_error', 'due_at must be a valid datetime', 422);
        }
        $dueAt = $candidate;
    }
}

$lateAllowed = array_key_exists('late_allowed', $in) ? (!empty($in['late_allowed']) ? 1 : 0) : (int)$existing['late_allowed'];

$maxPoints = (float)$existing['max_points'];
if (array_key_exists('max_points', $in)) {
    if (!is_numeric($in['max_points']) || (float)$in['max_points'] <= 0) {
        lms_error('validation_error', 'max_points must be a positive number', 422);
    }
    $maxPoints = (float)$in['max_points'];
}

$status = array_key_exists('status', $in) ? (string)$in['status'] : (string)$existing['status'];

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE lms_assignments SET title=:t, instructions=:i, due_at=:d, late_allowed=:l, max_points=:m, status=:st, updated_at=CURRENT_TIMESTAMP WHERE assignment_id=:id')->execute([
        ':t' => $title,
        ':i' => $instructions,
        ':d' => $dueAt,
        ':l' => $lateAllowed,
        ':m' => $maxPoints,
        ':st' => $status,
        ':id' => $id,
    ]);

    lms_emit_event($pdo, 'assignment.updated', [
        'event_id' => lms_uuid_v4(),
        'occurred_at' => gmdate('c'),
        'actor_id' => (int)$user['user_id'],
        'entity_type' => 'assignment',
        'entity_id' => $id,
        'course_id' => (int)$existing['course_id'],
        'title' => $title,
        'status' => $status,
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    lms_error('update_failed', 'Failed to update assignment', 500);
}

lms_ok(['updated' => true]);
