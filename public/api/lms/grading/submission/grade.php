<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_common.php';

$user = lms_require_roles(['ta', 'manager', 'admin']);
$in = lms_json_input();
$submissionId = (int)($in['submission_id'] ?? 0);
if ($submissionId <= 0) {
    lms_error('validation_error', 'submission_id required', 422);
}

$score = (float)($in['score'] ?? 0);
$max = (float)($in['max_score'] ?? 100);
if ($max <= 0) {
    lms_error('validation_error', 'max_score must be greater than 0', 422);
}
if ($score < 0 || $score > $max) {
    lms_error('validation_error', 'score must be between 0 and max_score', 422);
}

$pdo = db();
$sub = $pdo->prepare('SELECT submission_id, assignment_id, course_id, student_user_id FROM lms_submissions WHERE submission_id=:id');
$sub->execute([':id' => $submissionId]);
$s = $sub->fetch();
if (!$s) {
    lms_error('not_found', 'Submission not found', 404);
}

if ($user['role_name'] === 'ta') {
    $chk = $pdo->prepare('SELECT 1 FROM lms_assignment_tas WHERE assignment_id=:a AND ta_user_id=:u');
    $chk->execute([':a' => (int)$s['assignment_id'], ':u' => (int)$user['user_id']]);
    if (!$chk->fetchColumn()) {
        lms_error('forbidden', 'TA not assigned', 403);
    }
}

$params = [
    ':c' => (int)$s['course_id'],
    ':stu' => (int)$s['student_user_id'],
    ':a' => (int)$s['assignment_id'],
    ':s' => $submissionId,
    ':score' => $score,
    ':max' => $max,
    ':f' => $in['feedback'] ?? null,
    ':u' => (int)$user['user_id'],
];

$pdo->beginTransaction();
try {
    $existingStmt = $pdo->prepare('SELECT grade_id, status FROM lms_grades WHERE course_id=:c AND student_user_id=:stu AND assignment_id=:a AND submission_id=:s LIMIT 1 FOR UPDATE');
    $existingStmt->execute([
        ':c' => $params[':c'],
        ':stu' => $params[':stu'],
        ':a' => $params[':a'],
        ':s' => $params[':s'],
    ]);
    $existing = $existingStmt->fetch();
    if ($existing && (string)$existing['status'] === 'released') {
        $pdo->rollBack();
        lms_error('conflict', 'Released grades cannot be modified', 409);
    }

    $pdo->prepare('INSERT INTO lms_grades (course_id,student_user_id,assignment_id,submission_id,status,score,max_score,feedback,graded_by) VALUES (:c,:stu,:a,:s,\'draft\',:score,:max,:f,:u) ON DUPLICATE KEY UPDATE score=VALUES(score), max_score=VALUES(max_score), feedback=VALUES(feedback), status=IF(lms_grades.status=\'released\', lms_grades.status, VALUES(status)), graded_by=IF(lms_grades.status=\'released\', lms_grades.graded_by, VALUES(graded_by)), updated_at=CURRENT_TIMESTAMP')->execute($params);

    $pdo->prepare('INSERT INTO lms_grade_audit (submission_id, graded_by, score, max_score, feedback, action, created_at) VALUES (:submission_id, :graded_by, :score, :max_score, :feedback, :action, NOW())')->execute([
        ':submission_id' => $submissionId,
        ':graded_by' => (int)$user['user_id'],
        ':score' => $score,
        ':max_score' => $max,
        ':feedback' => $in['feedback'] ?? null,
        ':action' => 'draft_saved',
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    lms_error('grade_save_failed', 'Failed to save grade draft', 500);
}

lms_ok(['saved' => true]);
