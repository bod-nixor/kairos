<?php
/**
 * POST /api/lms/grading/submission/release.php
 * Release a single draft grade to the student.
 *
 * Payload: { submission_id: int }
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/_common.php';

$user = lms_require_roles(['ta', 'manager', 'admin']);
$in = lms_json_input();
$submissionId = (int)($in['submission_id'] ?? 0);

if ($submissionId <= 0) {
    lms_error('validation_error', 'submission_id required', 422);
}

$pdo = db();

// Fetch submission for course context (for RBAC + feature flag check before transaction)
$subStmt = $pdo->prepare('SELECT course_id FROM lms_submissions WHERE submission_id = :s LIMIT 1');
$subStmt->execute([':s' => $submissionId]);
$sub = $subStmt->fetch(PDO::FETCH_ASSOC);

if (!$sub) {
    lms_error('not_found', 'Submission not found', 404);
}

$courseId = (int)$sub['course_id'];

// Pre-check course access
lms_course_access($user, $courseId);

// Pre-check feature flag before transaction
if (!lms_feature_enabled('lms_expansion_grading_modes', $courseId)) {
    lms_error('not_found', 'Grading via API is not enabled for this course', 404);
}

// TA restriction: only assigned submissions
if ($user['role_name'] === 'ta') {
    $subDetailStmt = $pdo->prepare(
        'SELECT assignment_id FROM lms_submissions WHERE submission_id = :s LIMIT 1'
    );
    $subDetailStmt->execute([':s' => $submissionId]);
    $subDetail = $subDetailStmt->fetch(PDO::FETCH_ASSOC);
    if ($subDetail) {
        $chk = $pdo->prepare(
            'SELECT 1 FROM lms_assignment_tas WHERE assignment_id = :a AND ta_user_id = :u LIMIT 1'
        );
        $chk->execute([':a' => (int)$subDetail['assignment_id'], ':u' => (int)$user['user_id']]);
        if (!$chk->fetchColumn()) {
            lms_error('forbidden', 'TA not assigned to this assignment', 403);
        }
    }
}

$pdo->beginTransaction();
try {
    // Fetch grade with FOR UPDATE lock to ensure consistent read of all fields
    $st = $pdo->prepare(
        'SELECT g.grade_id, g.course_id, g.score, g.max_score, g.feedback, g.status
         FROM lms_grades g
         WHERE g.submission_id = :s
         ORDER BY g.updated_at DESC, g.grade_id DESC
         LIMIT 1 FOR UPDATE'
    );
    $st->execute([':s' => $submissionId]);
    $g = $st->fetch(PDO::FETCH_ASSOC);

    if (!$g) {
        $pdo->rollBack();
        lms_error('not_found', 'Draft grade not found', 404);
    }

    // Check status under lock
    if ((string)$g['status'] === 'released') {
        $pdo->rollBack();
        lms_error('conflict', 'Grade is already released', 409);
    }
    
    $pdo->prepare(
        'UPDATE lms_grades
         SET status = \'released\',
             released_by = :u,
             released_at = NOW(),
             updated_at = CURRENT_TIMESTAMP
         WHERE grade_id = :id'
    )->execute([
        ':u' => (int)$user['user_id'],
        ':id' => (int)$g['grade_id'],
    ]);

    // Immutable audit record
    $pdo->prepare(
        'INSERT INTO lms_grade_audit (submission_id, graded_by, score, max_score, feedback, action, created_at)
         VALUES (:sid, :uid, :score, :max, :fb, \'release\', NOW())'
    )->execute([
        ':sid' => $submissionId,
        ':uid' => (int)$user['user_id'],
        ':score' => (float)$g['score'],
        ':max' => (float)$g['max_score'],
        ':fb' => $g['feedback'],
    ]);

    lms_emit_event($pdo, 'grade.released', [
        'event_id' => lms_uuid_v4(),
        'event_name' => 'grade.released',
        'occurred_at' => gmdate('c'),
        'actor_id' => (int)$user['user_id'],
        'entity_type' => 'grade',
        'entity_id' => (int)$g['grade_id'],
        'course_id' => (int)$g['course_id'],
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('grade_release: ' . $e->getMessage());
    lms_error('release_failed', 'Failed to release grade', 500);
}

lms_ok(['released' => true]);
