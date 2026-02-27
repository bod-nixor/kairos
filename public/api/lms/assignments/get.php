<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';

lms_require_feature(['assignments', 'lms_assignments']);
$user = lms_require_roles(['student', 'ta', 'manager', 'admin']);
$assignmentId = (int)($_GET['assignment_id'] ?? 0);
$courseId = (int)($_GET['course_id'] ?? 0);
$role = lms_user_role($user);

if ($assignmentId <= 0) {
    lms_error('validation_error', 'assignment_id required', 422);
}

$debugMode = isset($_GET['debug']) && (string)$_GET['debug'] === '1' && in_array($role, ['admin', 'manager'], true);

try {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT assignment_id, course_id, section_id, title, instructions, due_at, late_allowed, max_points, allowed_file_extensions, max_file_mb, status
        FROM lms_assignments WHERE assignment_id = :assignment_id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':assignment_id' => $assignmentId]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$assignment) {
        lms_error('not_found', 'Assignment not found', 404);
    }
    if ($courseId > 0 && (int)$assignment['course_id'] !== $courseId) {
        lms_error('not_found', 'Assignment not found in this course', 404);
    }

    lms_course_access($user, (int)$assignment['course_id']);

    $moduleStmt = $pdo->prepare("SELECT required_flag, published_flag FROM lms_module_items WHERE item_type = 'assignment' AND entity_id = :id LIMIT 1");
    $moduleStmt->execute([':id' => $assignmentId]);
    $module = $moduleStmt->fetch(PDO::FETCH_ASSOC) ?: ['required_flag' => 0, 'published_flag' => ((string)$assignment['status'] === 'published' ? 1 : 0)];

    if (!lms_is_staff_role($role) && ((int)$module['published_flag'] !== 1 || (string)$assignment['status'] !== 'published')) {
        lms_error('forbidden', 'Assignment is not published', 403);
    }

    $payload = [
        'assignment_id' => (int)$assignment['assignment_id'],
        'course_id' => (int)$assignment['course_id'],
        'section_id' => $assignment['section_id'] === null ? null : (int)$assignment['section_id'],
        'title' => (string)$assignment['title'],
        'instructions' => (string)($assignment['instructions'] ?? ''),
        'due_at' => $assignment['due_at'],
        'late_allowed' => (int)$assignment['late_allowed'],
        'max_points' => (float)$assignment['max_points'],
        'allowed_file_extensions' => (string)($assignment['allowed_file_extensions'] ?? ''),
        'max_file_mb' => max(1, (int)($assignment['max_file_mb'] ?? 50)),
        'status' => (string)$assignment['status'],
        'published_flag' => (int)$module['published_flag'],
        'required_flag' => (int)$module['required_flag'],
    ];

    if ($debugMode) {
        $payload['debug'] = ['endpoint' => 'assignments/get'];
    }

    lms_ok($payload);
} catch (Throwable $e) {
    error_log('lms/assignments/get.php failed assignment_id=' . $assignmentId . ' user_id=' . (int)$user['user_id'] . ' message=' . $e->getMessage());
    lms_error('assignment_fetch_failed', 'Failed to load assignment', 500, $debugMode ? ['exception' => $e->getMessage()] : null);
}
