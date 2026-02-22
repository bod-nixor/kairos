<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';

lms_require_feature(['assignments', 'lms_assignments']);
$user = lms_require_roles(['student', 'ta', 'manager', 'admin']);
$assignmentId = (int)($_GET['assignment_id'] ?? 0);
$courseId = (int)($_GET['course_id'] ?? 0);

if ($assignmentId <= 0) {
    lms_error('validation_error', 'assignment_id required', 422);
}

$debugMode = isset($_GET['debug']) && (string)$_GET['debug'] === '1' && lms_user_role($user) === 'admin';
$debug = ['steps' => []];

try {
    $pdo = db();
    $sql = 'SELECT assignment_id, course_id, section_id, title, instructions, due_at, late_allowed, max_points, status
            FROM lms_assignments
            WHERE assignment_id = :assignment_id AND deleted_at IS NULL
            LIMIT 1';
    $params = [':assignment_id' => $assignmentId];
    $debug['steps'][] = ['step' => 'load_assignment', 'sql' => $sql, 'params' => $params];

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$assignment) {
        lms_error('not_found', 'Assignment not found', 404, $debugMode ? $debug : null);
    }

    if ($courseId > 0 && (int)$assignment['course_id'] !== $courseId) {
        lms_error('not_found', 'Assignment not found in this course', 404, $debugMode ? $debug : null);
    }

    lms_course_access($user, (int)$assignment['course_id']);
    if (!lms_is_staff_role(lms_user_role($user)) && (string)$assignment['status'] !== 'published') {
        lms_error('forbidden', 'Assignment is not published', 403, $debugMode ? $debug : null);
    }

    $response = $assignment;
    if ($debugMode) {
        $response['debug'] = $debug;
    }
    lms_ok($response);
} catch (Throwable $e) {
    error_log('lms/assignments/get.php failed assignment_id=' . $assignmentId . ' message=' . $e->getMessage());
    $details = $debugMode ? array_merge($debug, ['exception' => $e->getMessage()]) : null;
    lms_error('assignment_fetch_failed', 'Failed to load assignment', 500, $details);
}
