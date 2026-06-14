<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_common.php';

lms_require_feature(['assignments', 'lms_assignments']);
$user = lms_require_roles(['student', 'ta', 'manager', 'admin']);
$assignmentId = (int)($_GET['assignment_id'] ?? 0);

if ($assignmentId <= 0) {
    lms_error('validation_error', 'assignment_id required', 422);
}

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
    
    // Notes are student-specific. Fetch notes for current student user.
    $noteStmt = $pdo->prepare("SELECT notes FROM lms_assignment_notes WHERE assignment_id = :assignment_id AND student_user_id = :student_user_id LIMIT 1");
    $noteStmt->execute([
        ':assignment_id' => $assignmentId,
        ':student_user_id' => (int)$user['user_id']
    ]);
    $note = $noteStmt->fetchColumn();
    
    lms_ok([
        'assignment_id' => $assignmentId,
        'student_user_id' => (int)$user['user_id'],
        'notes' => $note !== false ? (string)$note : ''
    ]);
} catch (Throwable $e) {
    if ($e instanceof PDOException && ($e->getCode() === '42S02' || strpos($e->getMessage(), "doesn't exist") !== false)) {
        lms_ok([
            'assignment_id' => $assignmentId,
            'student_user_id' => (int)$user['user_id'],
            'notes' => ''
        ]);
    }
    error_log('get_note.php failed assignment_id=' . $assignmentId . ' message=' . $e->getMessage());
    lms_error('db_error', 'Failed to load assignment note', 500);
}
