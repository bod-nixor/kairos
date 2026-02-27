<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';

$user = lms_require_roles(['student', 'ta', 'manager', 'admin']);
lms_require_feature(['assignments', 'lms_assignments']);
$assignmentId = (int)($_GET['assignment_id'] ?? 0);
$courseId = (int)($_GET['course_id'] ?? 0);
$role = lms_user_role($user);

if ($assignmentId <= 0) {
    lms_error('validation_error', 'assignment_id required', 422);
}
$debugMode = isset($_GET['debug']) && (string)$_GET['debug'] === '1' && in_array($role, ['admin', 'manager'], true);

try {
    $pdo = db();
    $assignmentStmt = $pdo->prepare('SELECT assignment_id, course_id, status FROM lms_assignments WHERE assignment_id=:id AND deleted_at IS NULL LIMIT 1');
    $assignmentStmt->execute([':id' => $assignmentId]);
    $assignment = $assignmentStmt->fetch(PDO::FETCH_ASSOC);
    if (!$assignment) {
        lms_error('not_found', 'Assignment not found', 404);
    }
    if ($courseId > 0 && (int)$assignment['course_id'] !== $courseId) {
        lms_error('not_found', 'Assignment not found in this course', 404);
    }

    lms_course_access($user, (int)$assignment['course_id']);

    $canViewAll = in_array($role, ['admin', 'manager'], true);
    if ($role === 'ta') {
        $taStmt = $pdo->prepare('SELECT 1 FROM lms_assignment_tas WHERE assignment_id=:assignment_id AND ta_user_id=:user_id LIMIT 1');
        $taStmt->execute([':assignment_id' => $assignmentId, ':user_id' => (int)$user['user_id']]);
        $canViewAll = (bool)$taStmt->fetchColumn();
    }

    if (!$canViewAll && $role === 'student' && (string)$assignment['status'] !== 'published') {
        lms_error('forbidden', 'Assignment is not published', 403);
    }

    $baseSql = 'SELECT s.submission_id, s.assignment_id, s.student_user_id, s.version, s.status, s.submitted_at, s.is_late,
        s.text_submission, s.submission_comment, g.score AS grade, g.feedback,
        r.resource_id, r.title AS file_name, r.mime_type, r.file_size, r.drive_preview_url
        FROM lms_submissions s
        LEFT JOIN lms_grades g ON g.grade_id = (
            SELECT g2.grade_id FROM lms_grades g2 WHERE g2.submission_id = s.submission_id ORDER BY g2.updated_at DESC, g2.grade_id DESC LIMIT 1
        )
        LEFT JOIN lms_submission_files sf ON sf.submission_id = s.submission_id
        LEFT JOIN lms_resources r ON r.resource_id = sf.resource_id
        WHERE s.assignment_id = :assignment_id';

    if ($canViewAll) {
        $stmt = $pdo->prepare($baseSql . ' ORDER BY s.submitted_at DESC, s.submission_id DESC');
        $stmt->execute([':assignment_id' => $assignmentId]);
    } else {
        $stmt = $pdo->prepare($baseSql . ' AND s.student_user_id = :user_id ORDER BY s.submitted_at DESC, s.submission_id DESC');
        $stmt->execute([
            ':assignment_id' => $assignmentId,
            ':user_id' => (int)$user['user_id'],
        ]);
    }

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $submissionId = (int)$row['submission_id'];
        if (!isset($items[$submissionId])) {
            $items[$submissionId] = [
                'submission_id' => $submissionId,
                'assignment_id' => (int)$row['assignment_id'],
                'student_user_id' => (int)$row['student_user_id'],
                'version' => (int)$row['version'],
                'status' => (string)$row['status'],
                'submitted_at' => $row['submitted_at'],
                'is_late' => (int)$row['is_late'],
                'text_submission' => $row['text_submission'],
                'submission_comment' => $row['submission_comment'],
                'grade' => $row['grade'] === null ? null : (float)$row['grade'],
                'feedback' => $row['feedback'] ?? null,
                'files' => [],
            ];
        }
        if ($row['resource_id'] !== null) {
            $items[$submissionId]['files'][] = [
                'resource_id' => (int)$row['resource_id'],
                'name' => (string)($row['file_name'] ?? ''),
                'mime_type' => (string)($row['mime_type'] ?? ''),
                'file_size' => $row['file_size'] === null ? null : (int)$row['file_size'],
                'preview_url' => (string)($row['drive_preview_url'] ?? ''),
            ];
        }
    }

    lms_ok(['items' => array_values($items)]);
} catch (Throwable $e) {
    error_log('lms/assignments/submissions.php failed assignment_id=' . $assignmentId . ' user_id=' . (int)$user['user_id'] . ' message=' . $e->getMessage());
    lms_error('submissions_fetch_failed', 'Failed to load submissions', 500, $debugMode ? ['exception' => $e->getMessage()] : null);
}
