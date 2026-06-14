<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';

lms_require_feature(['assignments', 'lms_assignments']);
$user = lms_require_roles(['student', 'ta', 'manager', 'admin']);
$in = lms_json_input();
$assignmentId = (int)($in['assignment_id'] ?? 0);
$notes = (string)($in['notes'] ?? '');

if ($assignmentId <= 0) {
    lms_error('validation_error', 'assignment_id required', 422);
}

$pdo = null;
try {
    $pdo = db();
    // Verify assignment exists and user belongs to course
    $stmt = $pdo->prepare("SELECT course_id FROM lms_assignments WHERE assignment_id = :assignment_id AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':assignment_id' => $assignmentId]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$assignment) {
        lms_error('not_found', 'Assignment not found', 404);
    }
    
    lms_course_access($user, (int)$assignment['course_id']);
    
    $pdo->beginTransaction();
    
    // Upsert note
    $stmt = $pdo->prepare("
        INSERT INTO lms_assignment_notes (assignment_id, student_user_id, notes)
        VALUES (:assignment_id, :student_user_id, :notes)
        ON DUPLICATE KEY UPDATE notes = :notes_update
    ");
    $stmt->execute([
        ':assignment_id' => $assignmentId,
        ':student_user_id' => (int)$user['user_id'],
        ':notes' => $notes,
        ':notes_update' => $notes,
    ]);
    
    $pdo->commit();
    lms_ok(['success' => true]);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e instanceof PDOException && ($e->getCode() === '42S02' || strpos($e->getMessage(), "doesn't exist") !== false) && strpos($e->getMessage(), 'lms_assignment_notes') !== false) {
        lms_ok(['success' => true]);
    }
    error_log('save_note.php failed assignment_id=' . $assignmentId . ' message=' . $e->getMessage());
    lms_error('db_error', 'Failed to save assignment note', 500);
}
