<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';

function lms_require_published_assessment(int $assessmentId, array $user): array
{
    if ($assessmentId <= 0) {
        lms_error('validation_error', 'assessment_id required', 422);
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT assessment_id, course_id, section_id, title, instructions, status, max_attempts, time_limit_minutes, available_from, due_at FROM lms_assessments WHERE assessment_id=:id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':id' => $assessmentId]);
    $assessment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$assessment) {
        lms_error('not_found', 'Quiz not found', 404);
    }

    lms_course_access($user, (int)$assessment['course_id']);
    $role = lms_user_role($user);
    if (!lms_is_staff_role($role) && (string)$assessment['status'] !== 'published') {
        lms_error('forbidden', 'Quiz is not published', 403);
    }

    return $assessment;
}
