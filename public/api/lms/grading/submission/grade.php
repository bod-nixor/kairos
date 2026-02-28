<?php
/**
 * POST /api/lms/grade_submission.php → grading/submission/grade.php
 * Save (and optionally release) a grade for a submission.
 *
 * Frontend payload:
 *   { submission_id, grades: {criterionId: score, ...}, feedback, release: bool }
 * OR direct score form:
 *   { submission_id, score, max_score, feedback }
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_common.php';

$user = lms_require_roles(['ta', 'manager', 'admin']);
$in = lms_json_input();
$submissionId = (int)($in['submission_id'] ?? 0);
if ($submissionId <= 0) {
    lms_error('validation_error', 'submission_id required', 422);
}

// Early validation: payload must provide either score or grades
$hasScore = isset($in['score']);
$hasGrades = isset($in['grades']) && is_array($in['grades']);
if (!$hasScore && !$hasGrades) {
    lms_error('validation_error', 'Missing required grade data: provide either "score" or "grades"', 422);
}

$pdo = db();

// Fetch submission + course context
$sub = $pdo->prepare('SELECT submission_id, assignment_id, course_id, student_user_id FROM lms_submissions WHERE submission_id=:id');
$sub->execute([':id' => $submissionId]);
$s = $sub->fetch();
if (!$s) {
    lms_error('not_found', 'Submission not found', 404);
}

// Enforce course-scoped access
lms_course_access($user, (int)$s['course_id']);

// Feature-gate the grading workflow
if (!lms_feature_enabled('lms_expansion_grading_modes', (int)$s['course_id'])) {
    lms_error('not_found', 'Grading via API is not enabled for this course', 404);
}

if ($user['role_name'] === 'ta') {
    $chk = $pdo->prepare('SELECT 1 FROM lms_assignment_tas WHERE assignment_id=:a AND ta_user_id=:u');
    $chk->execute([':a' => (int)$s['assignment_id'], ':u' => (int)$user['user_id']]);
    if (!$chk->fetchColumn()) {
        lms_error('forbidden', 'TA not assigned', 403);
    }
}

// Resolve authoritative max_points from assignment BEFORE payload processing
$maxStmt = $pdo->prepare('SELECT max_points FROM lms_assignments WHERE assignment_id = :a LIMIT 1');
$maxStmt->execute([':a' => (int)$s['assignment_id']]);
$authMaxPoints = $maxStmt->fetchColumn();
if ($authMaxPoints === false || (float)$authMaxPoints <= 0) {
    lms_error('validation_error', 'Assignment has no valid max_points', 422);
}
$authMax = (float)$authMaxPoints;

// Now resolve payload scores
if ($hasScore) {
    // Direct score mode
    $score = (float)$in['score'];
    $max = $authMax;
} else {
    // Rubric-based: sum criterion scores from the frontend grades object
    $score = 0.0;
    foreach ($in['grades'] as $criterionScore) {
        $score += (float)$criterionScore;
    }
    $max = $authMax;
}

// Validate resolved scores
if ($score < 0 || $score > $max) {
    lms_error('validation_error', "score must be between 0 and {$max}", 422);
}

$release = !empty($in['release']);

$gradeStatus = $release ? 'released' : 'draft';

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
    $existingStmt = $pdo->prepare(
        'SELECT grade_id, status FROM lms_grades
         WHERE course_id=:c AND student_user_id=:stu AND assignment_id=:a AND submission_id=:s
         LIMIT 1 FOR UPDATE'
    );
    $existingStmt->execute([
        ':c' => $params[':c'],
        ':stu' => $params[':stu'],
        ':a' => $params[':a'],
        ':s' => $params[':s'],
    ]);
    $existing = $existingStmt->fetch();
    
    // Check if re-releasing (released → released)
    $isOverride = false;
    if ($existing && (string)$existing['status'] === 'released' && $release) {
        // Only manager+ can override released grades
        if (!in_array($user['role_name'], ['manager', 'admin'])) {
            $pdo->rollBack();
            lms_error('forbidden', 'Only managers can override released grades', 403);
        }
        // Flag as override audit action
        $isOverride = true;
    } elseif ($existing && (string)$existing['status'] === 'released' && !$release) {
        $pdo->rollBack();
        lms_error('conflict', 'Released grades cannot be modified without release flag', 409);
    }

    // Upsert grade
    $pdo->prepare(
        'INSERT INTO lms_grades (course_id, student_user_id, assignment_id, submission_id, status, score, max_score, feedback, graded_by, released_by, released_at)
         VALUES (:c, :stu, :a, :s, :status, :score, :max, :f, :u, :rel_by, :rel_at)
         ON DUPLICATE KEY UPDATE
           score = VALUES(score),
           max_score = VALUES(max_score),
           feedback = VALUES(feedback),
           status = IF(lms_grades.status = \'released\' AND :no_release = 1, lms_grades.status, VALUES(status)),
           graded_by = IF(lms_grades.status = \'released\' AND :no_release2 = 1, lms_grades.graded_by, VALUES(graded_by)),
           released_by = IF(VALUES(status) = \'released\', VALUES(released_by), lms_grades.released_by),
           released_at = IF(VALUES(status) = \'released\', VALUES(released_at), lms_grades.released_at),
           updated_at = CURRENT_TIMESTAMP'
    )->execute([
        ':c' => $params[':c'],
        ':stu' => $params[':stu'],
        ':a' => $params[':a'],
        ':s' => $params[':s'],
        ':status' => $gradeStatus,
        ':score' => $params[':score'],
        ':max' => $params[':max'],
        ':f' => $params[':f'],
        ':u' => $params[':u'],
        ':rel_by' => $release ? (int)$user['user_id'] : null,
        ':rel_at' => $release ? gmdate('Y-m-d H:i:s') : null,
        ':no_release' => $release ? 0 : 1,
        ':no_release2' => $release ? 0 : 1,
    ]);

    // Immutable audit record (action must match ENUM: draft, override, release)
    $auditAction = $isOverride ? 'override' : ($release ? 'release' : 'draft');
    $pdo->prepare(
        'INSERT INTO lms_grade_audit (submission_id, graded_by, score, max_score, feedback, action, created_at)
         VALUES (:submission_id, :graded_by, :score, :max_score, :feedback, :action, NOW())'
    )->execute([
        ':submission_id' => $submissionId,
        ':graded_by' => (int)$user['user_id'],
        ':score' => $score,
        ':max_score' => $max,
        ':feedback' => $in['feedback'] ?? null,
        ':action' => $auditAction,
    ]);

    // Emit event if releasing
    if ($release) {
        $gradeId = $existing ? (int)$existing['grade_id'] : (int)$pdo->lastInsertId();
        lms_emit_event($pdo, 'grade.released', [
            'event_id' => lms_uuid_v4(),
            'event_name' => 'grade.released',
            'occurred_at' => gmdate('c'),
            'actor_id' => (int)$user['user_id'],
            'entity_type' => 'grade',
            'entity_id' => $gradeId,
            'course_id' => (int)$s['course_id'],
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('grade_submission: ' . $e->getMessage());
    lms_error('grade_save_failed', 'Failed to save grade', 500);
}

lms_ok(['saved' => true, 'released' => $release]);
