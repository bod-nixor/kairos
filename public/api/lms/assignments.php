<?php
/**
 * GET /api/lms/assignments.php?course_id=<id>
 * List assignments for a course. Used by grading.js and analytics.js.
 * Proxy that queries lms_assignments directly (no nested handler for list).
 */
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

$user = lms_require_roles(['student', 'ta', 'manager', 'admin']);
lms_require_feature(['assignments', 'lms_assignments']);
$courseId = (int) ($_GET['course_id'] ?? 0);
if ($courseId <= 0) {
    lms_error('validation_error', 'course_id required', 422);
}
lms_course_access($user, $courseId);

$debugMode = isset($_GET['debug']) && (string)$_GET['debug'] === '1' && lms_user_role($user) === 'admin';
$debug = $debugMode ? ['steps' => []] : null;

try {
    $pdo = db();
    $isStaff = lms_is_staff_role(lms_user_role($user));
    $sql = 'SELECT assignment_id AS id, title, instructions AS description,
                   due_at AS due_date, max_points, status
            FROM lms_assignments
            WHERE course_id = :course_id
              AND deleted_at IS NULL
              AND (:is_staff = 1 OR status = \'published\')
            ORDER BY due_at ASC, assignment_id ASC';
    $params = [':course_id' => $courseId, ':is_staff' => $isStaff ? 1 : 0];
    if ($debugMode) {
        $debug['steps'][] = ['step' => 'list_assignments', 'sql' => $sql, 'params' => $params];
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $response = ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    if ($debugMode) {
        $response['debug'] = $debug;
    }
    lms_ok($response);
} catch (Throwable $e) {
    error_log('lms/assignments.php failed course_id=' . $courseId . ' user_id=' . (int)$user['user_id'] . ' message=' . $e->getMessage());
    $details = $debugMode ? array_merge($debug, ['exception' => $e->getMessage()]) : null;
    lms_error('assignments_list_failed', 'Failed to load assignments', 500, $details);
}
