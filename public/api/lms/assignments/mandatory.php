<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';

lms_require_feature(['assignments', 'lms_assignments']);
$user = lms_require_roles(['manager', 'admin']);
$in = lms_json_input();
$assignmentId = (int)($in['assignment_id'] ?? 0);
$required = !empty($in['required']) ? 1 : 0;

if ($assignmentId <= 0) {
    lms_error('validation_error', 'assignment_id required', 422);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT assignment_id, course_id, section_id, title FROM lms_assignments WHERE assignment_id=:id AND deleted_at IS NULL LIMIT 1');
$stmt->execute([':id' => $assignmentId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    lms_error('not_found', 'Assignment not found', 404);
}

lms_course_access($user, (int)$row['course_id']);

try {
    $pdo->beginTransaction();

    $existsStmt = $pdo->prepare("SELECT module_item_id FROM lms_module_items WHERE item_type='assignment' AND entity_id=:id LIMIT 1 FOR UPDATE");
    $existsStmt->execute([':id' => $assignmentId]);
    $existing = $existsStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $pdo->prepare("UPDATE lms_module_items SET required_flag=:required, updated_at=CURRENT_TIMESTAMP WHERE module_item_id=:module_item_id")
            ->execute([
                ':required' => $required,
                ':module_item_id' => (int)$existing['module_item_id'],
            ]);
    } else {
        $pdo->prepare("INSERT INTO lms_module_items (course_id, section_id, item_type, entity_id, title, position, published_flag, required_flag, created_by)
            VALUES (:course_id,:section_id,'assignment',:entity_id,:title,1,0,:required,:created_by)")
            ->execute([
                ':course_id' => (int)$row['course_id'],
                ':section_id' => $row['section_id'] === null ? null : (int)$row['section_id'],
                ':entity_id' => $assignmentId,
                ':title' => (string)$row['title'],
                ':required' => $required,
                ':created_by' => (int)$user['user_id'],
            ]);
    }

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log(
        'lms/assignments/mandatory.php failed assignment_id=' . $assignmentId .
        ' course_id=' . (int)$row['course_id'] .
        ' user_id=' . (int)$user['user_id'] .
        ' required_flag=' . $required .
        ' message=' . $e->getMessage() .
        ' trace=' . $e->getTraceAsString()
    );
    lms_error('mandatory_failed', 'Failed to update mandatory state', 500);
}

lms_ok(['assignment_id' => $assignmentId, 'required_flag' => $required]);
