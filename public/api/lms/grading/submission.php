<?php
/**
 * GET /api/lms/grading/submission.php?submission_id=<id>
 * Full submission detail with files and grade data for grading workspace.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';
require_once dirname(__DIR__) . '/drive_client.php';

$user = lms_require_roles(['ta', 'manager', 'admin']);
$id = (int) ($_GET['submission_id'] ?? 0);
if ($id <= 0) {
    lms_error('validation_error', 'submission_id required', 422);
}
$pdo = db();

$hasPrivateNote = false;
$hasOverride = false;
$hasRubricGrades = false;

try {
    $pdo->query('SELECT staff_private_note FROM lms_grades LIMIT 1');
    $hasPrivateNote = true;
} catch (Throwable $_) {}

try {
    $pdo->query('SELECT grade_override FROM lms_grades LIMIT 1');
    $hasOverride = true;
} catch (Throwable $_) {}

try {
    $pdo->query('SELECT rubric_grades_json FROM lms_grades LIMIT 1');
    $hasRubricGrades = true;
} catch (Throwable $_) {}

$extraCols = '';
if ($hasPrivateNote) {
    $extraCols .= ', g.staff_private_note AS private_note';
}
if ($hasOverride) {
    $extraCols .= ', g.grade_override';
}
if ($hasRubricGrades) {
    $extraCols .= ', g.rubric_grades_json';
}

$st = $pdo->prepare(
    'SELECT s.submission_id AS id, s.assignment_id, s.course_id, s.student_user_id,
            u.name AS student_name,
            s.text_submission AS text_content, s.submission_comment, s.status, s.submitted_at,
            g.grade_id, g.score, g.max_score, g.feedback,
            g.status AS grade_status' . $extraCols . '
     FROM lms_submissions s
     JOIN users u ON u.user_id = s.student_user_id
     JOIN lms_assignments a ON a.assignment_id = s.assignment_id AND a.deleted_at IS NULL
     LEFT JOIN lms_grades g ON g.grade_id = (
         SELECT g2.grade_id FROM lms_grades g2 WHERE g2.submission_id = s.submission_id ORDER BY g2.updated_at DESC, g2.grade_id DESC LIMIT 1
     )
     WHERE s.submission_id = :id
     LIMIT 1'
);
$st->execute([':id' => $id]);
$row = $st->fetch();
if (!$row) {
    lms_error('not_found', 'Submission not found', 404);
}

lms_require_submission_access($pdo, $user, [
    'course_id' => (int)$row['course_id'],
    'assignment_id' => (int)$row['assignment_id'],
    'student_user_id' => (int)$row['student_user_id'],
], false, true);

// Submission files
$files = $pdo->prepare(
    'SELECT sf.submission_file_id, sf.resource_id, r.title AS name,
            r.mime_type
     FROM lms_submission_files sf
     JOIN lms_resources r ON r.resource_id = sf.resource_id
     WHERE sf.submission_id = :id'
);
$files->execute([':id' => $id]);
$fileRows = $files->fetchAll();
foreach ($fileRows as &$fileRow) {
    $resourceId = (int)$fileRow['resource_id'];
    $fileRow['url'] = lms_drive_internal_url($resourceId);
    $fileRow['download_url'] = lms_drive_internal_url($resourceId);
    $fileRow['preview_url'] = lms_drive_inline_allowed((string)($fileRow['mime_type'] ?? ''))
        ? lms_drive_internal_url($resourceId, 'inline')
        : '';
}
unset($fileRow);

// Determine submission type for frontend rendering
if (!empty($fileRows)) {
    $row['type'] = 'file';
    $row['attachments'] = $fileRows;
} else {
    $row['type'] = 'text';
}

if (!isset($row['private_note'])) {
    $row['private_note'] = '';
}
if (!isset($row['grade_override'])) {
    $row['grade_override'] = null;
}
if (!isset($row['rubric_grades_json'])) {
    $row['rubric_grades_json'] = null;
}

$grades = [];
if (!empty($row['rubric_grades_json'])) {
    $decoded = json_decode((string)$row['rubric_grades_json'], true);
    if (is_array($decoded)) {
        $grades = $decoded;
    }
}
$row['grades'] = (object)$grades;
$row['private_note'] = $row['private_note'] !== null ? (string)$row['private_note'] : '';
$row['grade_override'] = $row['grade_override'] !== null ? (float)$row['grade_override'] : null;

// Load rubric
$rubricStmt = $pdo->prepare('SELECT rubric_id FROM lms_rubrics WHERE assignment_id = :assignment_id LIMIT 1');
$rubricStmt->execute([':assignment_id' => (int)$row['assignment_id']]);
$rubric = $rubricStmt->fetch(PDO::FETCH_ASSOC);
$rubricItems = [];
if ($rubric) {
    $itemsStmt = $pdo->prepare('SELECT rubric_item_id AS id, criterion, description, max_points AS max_pts FROM lms_rubric_items WHERE rubric_id = :rubric_id ORDER BY position ASC, rubric_item_id ASC');
    $itemsStmt->execute([':rubric_id' => (int)$rubric['rubric_id']]);
    $rubricItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rubricItems as &$item) {
        $item['max_pts'] = (float)$item['max_pts'];
    }
    unset($item);
}
$row['rubric'] = $rubricItems;

lms_ok($row);
