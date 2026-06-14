<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';

lms_require_feature(['assignments', 'lms_assignments']);
$user = lms_require_roles(['manager', 'admin']);
$in = lms_json_input();
$assignmentId = (int)($in['assignment_id'] ?? 0);
$published = !empty($in['published']) ? 1 : 0;
$newStatus = $published ? 'published' : 'draft';

if ($assignmentId <= 0) {
    lms_error('validation_error', 'assignment_id required', 422);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT assignment_id, course_id, section_id, title, status FROM lms_assignments WHERE assignment_id=:id AND deleted_at IS NULL LIMIT 1');
$stmt->execute([':id' => $assignmentId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    lms_error('not_found', 'Assignment not found', 404);
}

lms_require_course_capability($user, 'manage_course', (int)$row['course_id']);

try {
    $pdo->beginTransaction();

    $pdo->prepare('UPDATE lms_assignments SET status=:status, updated_at=CURRENT_TIMESTAMP WHERE assignment_id=:id')
        ->execute([':status' => $newStatus, ':id' => $assignmentId]);

    $moduleItemStmt = $pdo->prepare("SELECT module_item_id FROM lms_module_items WHERE course_id=:course_id AND item_type='assignment' AND entity_id=:id LIMIT 1 FOR UPDATE");
    $moduleItemStmt->execute([
        ':course_id' => (int)$row['course_id'],
        ':id' => $assignmentId,
    ]);
    $moduleItem = $moduleItemStmt->fetch(PDO::FETCH_ASSOC);

    if ($moduleItem) {
        $pdo->prepare("UPDATE lms_module_items SET published_flag=:published, updated_at=CURRENT_TIMESTAMP WHERE module_item_id=:module_item_id")
            ->execute([
                ':published' => $published,
                ':module_item_id' => (int)$moduleItem['module_item_id'],
            ]);
    }

    $pdo->prepare('INSERT INTO lms_assignment_publish_audit (assignment_id, course_id, actor_id, old_status, new_status, created_at)
        VALUES (:assignment_id, :course_id, :actor_id, :old_status, :new_status, NOW())')
        ->execute([
            ':assignment_id' => $assignmentId,
            ':course_id' => (int)$row['course_id'],
            ':actor_id' => (int)$user['user_id'],
            ':old_status' => (string)$row['status'],
            ':new_status' => $newStatus,
        ]);

    lms_emit_event($pdo, 'assignment.updated', [
        'event_name' => 'assignment.updated',
        'event_id' => lms_uuid_v4(),
        'occurred_at' => gmdate('Y-m-d H:i:s'),
        'actor_id' => (int)$user['user_id'],
        'entity_type' => 'assignment',
        'entity_id' => $assignmentId,
        'course_id' => (int)$row['course_id'],
        'title' => (string)$row['title'],
        'status' => $newStatus,
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log(
        'lms/assignments/publish.php failed assignment_id=' . $assignmentId .
        ' course_id=' . (int)$row['course_id'] .
        ' user_id=' . (int)$user['user_id'] .
        ' target_status=' . $newStatus .
        ' exception=' . get_class($e)
    );
    lms_error('publish_failed', 'Failed to update publish state', 500);
}

lms_ok(['assignment_id' => $assignmentId, 'published_flag' => $published]);
