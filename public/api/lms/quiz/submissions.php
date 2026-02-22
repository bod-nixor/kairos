<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';

lms_require_feature(['quiz','quizzes','lms_quizzes']);
$user = lms_require_roles(['ta','manager','admin']);
$assessmentId = (int)($_GET['assessment_id'] ?? 0);
$courseId = (int)($_GET['course_id'] ?? 0);
if ($assessmentId <= 0) lms_error('validation_error', 'assessment_id required', 422);
$pdo = db();
$stmt = $pdo->prepare('SELECT assessment_id, course_id FROM lms_assessments WHERE assessment_id=:id AND deleted_at IS NULL LIMIT 1');
$stmt->execute([':id'=>$assessmentId]);
$quiz = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$quiz) lms_error('not_found', 'Quiz not found', 404);
if ($courseId > 0 && (int)$quiz['course_id'] !== $courseId) lms_error('not_found', 'Quiz not found in this course', 404);
lms_course_access($user, (int)$quiz['course_id']);
$rows = $pdo->prepare('SELECT a.attempt_id, a.user_id AS student_user_id, a.status, a.grading_status, a.score, a.max_score, a.started_at, a.submitted_at,
       SUM(CASE WHEN r.needs_manual_grading = 1 THEN 1 ELSE 0 END) AS manual_review_count
  FROM lms_assessment_attempts a
  LEFT JOIN lms_assessment_responses r ON r.attempt_id = a.attempt_id
 WHERE a.assessment_id = :id
 GROUP BY a.attempt_id, a.user_id, a.status, a.grading_status, a.score, a.max_score, a.started_at, a.submitted_at
 ORDER BY a.submitted_at DESC, a.attempt_id DESC');
$rows->execute([':id'=>$assessmentId]);
lms_ok(['items'=>$rows->fetchAll(PDO::FETCH_ASSOC)]);
