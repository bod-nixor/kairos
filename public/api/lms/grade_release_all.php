<?php
/**
 * POST /api/lms/grade_release_all.php
 * Bulk-release all draft grades for a course.
 *
 * Payload: { course_id: int }
 * Requires manager or admin role.
 */
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

$user = lms_require_roles(['manager', 'admin']);
$in = lms_json_input();
$courseId = (int)($in['course_id'] ?? 0);

if ($courseId <= 0) {
    lms_error('validation_error', 'course_id required', 422);
}

lms_course_access($user, $courseId);

$pdo = db();
$userId = (int)$user['user_id'];
$now = gmdate('Y-m-d H:i:s');

$pdo->beginTransaction();
try {
    // Find all draft grades for this course
    $stmt = $pdo->prepare(
        'SELECT grade_id, submission_id, score, max_score, feedback
         FROM lms_grades
         WHERE course_id = :cid AND status = \'draft\'
         FOR UPDATE'
    );
    $stmt->execute([':cid' => $courseId]);
    $drafts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($drafts)) {
        $pdo->commit();
        lms_ok(['released' => 0]);
        return;
    }

    // Bulk update all draft grades to released
    $pdo->prepare(
        'UPDATE lms_grades
         SET status = \'released\',
             released_by = :uid,
             released_at = :now,
             updated_at = CURRENT_TIMESTAMP
         WHERE course_id = :cid AND status = \'draft\''
    )->execute([
        ':uid' => $userId,
        ':now' => $now,
        ':cid' => $courseId,
    ]);

    // Write audit records for each released grade
    $auditStmt = $pdo->prepare(
        'INSERT INTO lms_grade_audit (submission_id, graded_by, score, max_score, feedback, action, created_at)
         VALUES (:sid, :uid, :score, :max, :fb, \'release\', NOW())'
    );
    foreach ($drafts as $draft) {
        $auditStmt->execute([
            ':sid' => (int)$draft['submission_id'],
            ':uid' => $userId,
            ':score' => (float)$draft['score'],
            ':max' => (float)$draft['max_score'],
            ':fb' => $draft['feedback'],
        ]);
    }

    // Emit a single bulk event
    lms_emit_event($pdo, 'grade.bulk_released', [
        'event_id' => lms_uuid_v4(),
        'event_name' => 'grade.bulk_released',
        'occurred_at' => gmdate('c'),
        'actor_id' => $userId,
        'entity_type' => 'grade',
        'entity_id' => null,
        'course_id' => $courseId,
        'count' => count($drafts),
    ]);

    $pdo->commit();
    lms_ok(['released' => count($drafts)]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('grade_release_all: ' . $e->getMessage());
    lms_error('release_failed', 'Failed to release grades', 500);
}
