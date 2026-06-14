<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';
require_once __DIR__ . '/_restriction_helpers.php';

$in = lms_json_input();
$id = (int) ($in['assignment_id'] ?? 0);
if ($id <= 0) {
    lms_error('validation_error', 'assignment_id required', 422);
}

$user = lms_require_roles(['manager', 'admin']);
$pdo = db();
$scopeStmt = $pdo->prepare(
    'SELECT assignment_id, course_id
     FROM lms_assignments
     WHERE assignment_id = :id AND deleted_at IS NULL
     LIMIT 1'
);
$scopeStmt->execute([':id' => $id]);
$scope = $scopeStmt->fetch(PDO::FETCH_ASSOC);
if (!$scope) {
    lms_error('not_found', 'Assignment not found', 404);
}
$courseId = (int)$scope['course_id'];
if (isset($in['course_id']) && (int)$in['course_id'] !== $courseId) {
    lms_error('not_found', 'Assignment not found in this course', 404);
}

try {
    lms_require_feature(['lms_assignments', 'assignments'], $courseId);
} catch (Throwable $e) {
    error_log("[kairos] lms_require_feature check failed in update.php (course_id={$courseId}): " . get_class($e));
    lms_error('feature_disabled', 'Feature check failed or disabled', 404);
}

lms_require_course_capability($user, 'manage_course', $courseId);
lms_require_assignment_restriction_schema($pdo);

$existingStmt = $pdo->prepare(
    'SELECT assignment_id, course_id, title, instructions, due_at, late_allowed, max_points,
            allowed_file_extensions, max_file_mb, status
     FROM lms_assignments
     WHERE assignment_id = :id AND course_id = :course_id AND deleted_at IS NULL
     LIMIT 1'
);
$existingStmt->execute([':id' => $id, ':course_id' => $courseId]);
$existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
if (!$existing) {
    lms_error('not_found', 'Assignment not found', 404);
}

if (array_key_exists('status', $in)) {
    $targetStatus = (string) $in['status'];
    $currentStatus = (string) $existing['status'];
    if (!lms_is_valid_assignment_status_transition($currentStatus, $targetStatus)) {
        lms_error('validation_error', 'invalid status transition', 422);
    }
}

$title = array_key_exists('title', $in) ? trim((string) $in['title']) : (string) $existing['title'];
if ($title === '') {
    lms_error('validation_error', 'title cannot be blank', 422);
}

$instructionsRaw = $in['instructions'] ?? $in['description'] ?? $in['description_html'] ?? $existing['instructions'];
if ($instructionsRaw === null) {
    $instructions = null;
} elseif (is_scalar($instructionsRaw)) {
    $instructions = (string) $instructionsRaw;
} else {
    lms_error('validation_error', 'instructions must be a string', 422);
}

$dueAt = $existing['due_at'];
if (array_key_exists('due_at', $in)) {
    if ($in['due_at'] === null || $in['due_at'] === '') {
        $dueAt = null;
    } else {
        $candidate = (string) $in['due_at'];
        if (strtotime($candidate) === false) {
            lms_error('validation_error', 'due_at must be a valid datetime', 422);
        }
        $dueAt = $candidate;
    }
}

$lateAllowed = array_key_exists('late_allowed', $in)
    ? (!empty($in['late_allowed']) ? 1 : 0)
    : (int) $existing['late_allowed'];

$maxPoints = (float) $existing['max_points'];
if (array_key_exists('max_points', $in)) {
    if (!is_numeric($in['max_points']) || (float) $in['max_points'] <= 0) {
        lms_error('validation_error', 'max_points must be a positive number', 422);
    }
    $maxPoints = (float) $in['max_points'];
}

$status = array_key_exists('status', $in) ? (string) $in['status'] : (string) $existing['status'];
$allowedFileExtensions = lms_normalize_allowed_file_extensions(
    array_key_exists('allowed_file_extensions', $in)
        ? $in['allowed_file_extensions']
        : ($existing['allowed_file_extensions'] ?? null)
);
$maxFileMb = lms_clamp_max_file_mb(
    array_key_exists('max_file_mb', $in)
        ? $in['max_file_mb']
        : ($existing['max_file_mb'] ?? 50),
    50
);

$pdo->beginTransaction();
try {
    $pdo->prepare(
        'UPDATE lms_assignments
         SET title = :title,
             instructions = :instructions,
             due_at = :due_at,
             late_allowed = :late_allowed,
             max_points = :max_points,
             allowed_file_extensions = :allowed_file_extensions,
             max_file_mb = :max_file_mb,
             status = :status,
             updated_at = CURRENT_TIMESTAMP
         WHERE assignment_id = :id AND course_id = :course_id AND deleted_at IS NULL'
    )->execute([
        ':title' => $title,
        ':instructions' => $instructions,
        ':due_at' => $dueAt,
        ':late_allowed' => $lateAllowed,
        ':max_points' => $maxPoints,
        ':allowed_file_extensions' => $allowedFileExtensions === '' ? null : $allowedFileExtensions,
        ':max_file_mb' => $maxFileMb,
        ':status' => $status,
        ':id' => $id,
        ':course_id' => $courseId,
    ]);

    $pdo->prepare(
        "UPDATE lms_module_items
         SET title = :title, updated_at = CURRENT_TIMESTAMP
         WHERE course_id = :course_id AND item_type = 'assignment' AND entity_id = :id"
    )->execute([
        ':title' => $title,
        ':course_id' => $courseId,
        ':id' => $id,
    ]);

    lms_emit_event($pdo, 'assignment.updated', [
        'event_name' => 'assignment.updated',
        'event_id' => lms_uuid_v4(),
        'occurred_at' => gmdate('Y-m-d H:i:s'),
        'actor_id' => (int)$user['user_id'],
        'entity_type' => 'assignment',
        'entity_id' => $id,
        'course_id' => $courseId,
        'title' => $title,
        'status' => $status,
        'allowed_file_extensions' => $allowedFileExtensions,
        'max_file_mb' => $maxFileMb,
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[kairos] assignment update failed (id=' . $id . '): ' . $e->getMessage());
    lms_error('update_failed', 'Failed to update assignment.', 500);
}

lms_ok([
    'updated' => true,
    'assignment_id' => $id,
    'course_id' => $courseId,
    'title' => $title,
    'status' => $status,
    'allowed_file_extensions' => $allowedFileExtensions,
    'max_file_mb' => $maxFileMb,
]);
