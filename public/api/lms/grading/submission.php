<?php
/**
 * GET /api/lms/grading/submission.php?submission_id=<id>
 * Full submission detail with files and grade data for grading workspace.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';

$user = lms_require_roles(['ta', 'manager', 'admin']);
$id = (int) ($_GET['submission_id'] ?? 0);
if ($id <= 0) {
    lms_error('validation_error', 'submission_id required', 422);
}
$pdo = db();

$st = $pdo->prepare(
    'SELECT s.submission_id AS id, s.assignment_id, s.course_id, s.student_user_id,
            u.name AS student_name,
            s.text_submission AS text_content, s.status, s.submitted_at,
            g.grade_id, g.score, g.max_score, g.feedback,
            g.status AS grade_status
     FROM lms_submissions s
     JOIN users u ON u.user_id = s.student_user_id
     LEFT JOIN lms_grades g ON g.submission_id = s.submission_id
     WHERE s.submission_id = :id
     LIMIT 1'
);
$st->execute([':id' => $id]);
$row = $st->fetch();
if (!$row) {
    lms_error('not_found', 'Submission not found', 404);
}

// TA restriction: only assigned items
if ($user['role_name'] === 'ta') {
    $chk = $pdo->prepare(
        'SELECT 1 FROM lms_assignment_tas WHERE assignment_id = :a AND ta_user_id = :u LIMIT 1'
    );
    $chk->execute([':a' => (int) $row['assignment_id'], ':u' => (int) $user['user_id']]);
    if (!$chk->fetchColumn()) {
        lms_error('forbidden', 'TA not assigned to this assignment', 403);
    }
}

// Submission files
$files = $pdo->prepare(
    'SELECT sf.submission_file_id, sf.resource_id, r.title AS name,
            r.drive_preview_url AS url, r.mime_type
     FROM lms_submission_files sf
     JOIN lms_resources r ON r.resource_id = sf.resource_id
     WHERE sf.submission_id = :id'
);
$files->execute([':id' => $id]);
$fileRows = $files->fetchAll();

// Determine submission type for frontend rendering
if (!empty($fileRows)) {
    $row['type'] = 'file';
    $row['attachments'] = $fileRows;
} else {
    $row['type'] = 'text';
}

lms_ok($row);
