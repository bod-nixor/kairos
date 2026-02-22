<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';

lms_require_feature(['quiz', 'quizzes', 'lms_quizzes']);
$user = lms_require_roles(['ta', 'manager', 'admin']);
$assessmentId = (int)($_GET['assessment_id'] ?? 0);
$courseId = (int)($_GET['course_id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = (int)($_GET['limit'] ?? 50);
$limit = min(100, max(1, $limit));
$offset = ($page - 1) * $limit;

if ($assessmentId <= 0) {
    lms_error('validation_error', 'assessment_id required', 422);
}

try {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT assessment_id, course_id FROM lms_assessments WHERE assessment_id=:id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':id' => $assessmentId]);
    $quiz = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$quiz) {
        lms_error('not_found', 'Quiz not found', 404);
    }
    if ($courseId > 0 && (int)$quiz['course_id'] !== $courseId) {
        lms_error('not_found', 'Quiz not found in this course', 404);
    }

    lms_course_access($user, (int)$quiz['course_id']);

    $rows = $pdo->prepare('SELECT a.attempt_id, a.user_id AS student_user_id, a.status, a.grading_status, a.score, a.max_score, a.started_at, a.submitted_at,
           SUM(CASE WHEN r.needs_manual_grading = 1 THEN 1 ELSE 0 END) AS manual_review_count
      FROM lms_assessment_attempts a
      LEFT JOIN lms_assessment_responses r ON r.attempt_id = a.attempt_id
     WHERE a.assessment_id = :id
     GROUP BY a.attempt_id, a.user_id, a.status, a.grading_status, a.score, a.max_score, a.started_at, a.submitted_at
     ORDER BY a.submitted_at DESC, a.attempt_id DESC
     LIMIT :limit OFFSET :offset');
    $rows->bindValue(':id', $assessmentId, PDO::PARAM_INT);
    $rows->bindValue(':limit', $limit, PDO::PARAM_INT);
    $rows->bindValue(':offset', $offset, PDO::PARAM_INT);
    $rows->execute();

    lms_ok([
        'items' => $rows->fetchAll(PDO::FETCH_ASSOC),
        'page' => $page,
        'limit' => $limit,
    ]);
} catch (Throwable $e) {
    error_log('lms/quiz/submissions.php failed assessment_id=' . $assessmentId . ' user_id=' . (int)$user['user_id'] . ' message=' . $e->getMessage() . ' trace=' . $e->getTraceAsString());
    lms_error('internal_error', 'Internal server error', 500);
}
