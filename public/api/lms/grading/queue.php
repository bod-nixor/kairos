<?php
/**
 * GET /api/lms/grading/queue.php?assignment_id=<id>&course_id=<id>
 * Returns submission queue with student names and grade status for grading UI.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';

$user = lms_require_roles(['ta', 'manager', 'admin']);
$courseId = (int) ($_GET['course_id'] ?? 0);
$assignmentId = (int) ($_GET['assignment_id'] ?? 0);
if ($courseId <= 0) {
    lms_error('validation_error', 'course_id required', 422);
}

// Enforce course-scoped access (prevents IDOR across courses)
lms_course_access($user, $courseId);

$pdo = db();

$sql = 'SELECT s.submission_id AS id, s.assignment_id, s.student_user_id,
               u.name AS student_name, s.status, s.submitted_at, s.is_late,
               LEFT(s.text_submission, 200) AS text_preview,
               s.submission_comment,
               COALESCE(g.status, \'ungraded\') AS grade_status,
               g.feedback, g.score
        FROM lms_submissions s
        JOIN users u ON u.user_id = s.student_user_id
        JOIN lms_assignments a ON a.assignment_id = s.assignment_id AND a.deleted_at IS NULL
        LEFT JOIN lms_grades g ON g.grade_id = (
            SELECT g2.grade_id FROM lms_grades g2 WHERE g2.submission_id = s.submission_id ORDER BY g2.updated_at DESC, g2.grade_id DESC LIMIT 1
        )
        WHERE s.course_id = :course_id';
$params = [':course_id' => $courseId];

if ($assignmentId > 0) {
    $sql .= ' AND s.assignment_id = :assignment_id';
    $params[':assignment_id'] = $assignmentId;
}

// TA restriction: only see items assigned to them
if ($user['role_name'] === 'ta') {
    $sql .= ' AND EXISTS (SELECT 1 FROM lms_assignment_tas t WHERE t.assignment_id = s.assignment_id AND t.ta_user_id = :uid)';
    $params[':uid'] = (int) $user['user_id'];
}

$sql .= ' ORDER BY s.submitted_at ASC LIMIT 500';
$st = $pdo->prepare($sql);
$st->execute($params);
lms_ok($st->fetchAll());
