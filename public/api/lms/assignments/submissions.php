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

    $assignmentSql = 'SELECT assignment_id, course_id, status FROM lms_assignments WHERE assignment_id = :assignment_id AND deleted_at IS NULL LIMIT 1';
    $assignmentParams = [':assignment_id' => $assignmentId];
    $debug['steps'][] = ['step' => 'load_assignment', 'sql' => $assignmentSql, 'params' => $assignmentParams];
    $assignmentStmt = $pdo->prepare($assignmentSql);
    $assignmentStmt->execute($assignmentParams);
    $assignment = $assignmentStmt->fetch(PDO::FETCH_ASSOC);
    if (!$assignment) {
        lms_error('not_found', 'Assignment not found', 404, $debugMode ? $debug : null);
    }

    if ($courseId > 0 && (int)$assignment['course_id'] !== $courseId) {
        lms_error('not_found', 'Assignment not found in this course', 404, $debugMode ? $debug : null);
    }

    lms_course_access($user, (int)$assignment['course_id']);

    $role = lms_user_role($user);
    $canViewAll = in_array($role, ['manager', 'admin'], true);
    if ($role === 'ta') {
        $taSql = 'SELECT 1
                  FROM lms_assignment_tas
                  WHERE assignment_id = :assignment_id AND ta_user_id = :user_id
                  LIMIT 1';
        $taParams = [':assignment_id' => $assignmentId, ':user_id' => (int)$user['user_id']];
        $debug['steps'][] = ['step' => 'check_ta_assignment_access', 'sql' => $taSql, 'params' => $taParams];
        $taStmt = $pdo->prepare($taSql);
        $taStmt->execute($taParams);
        $canViewAll = (bool)$taStmt->fetchColumn();
    }

    if (!$canViewAll && $role === 'student' && (string)$assignment['status'] !== 'published') {
        lms_error('forbidden', 'Assignment is not published', 403, $debugMode ? $debug : null);
    }

    $sql = 'SELECT s.submission_id, s.assignment_id, s.student_user_id, s.version, s.status, s.submitted_at, s.is_late,
                   g.score AS grade,
                   r.resource_id, r.title AS file_name, r.mime_type, r.file_size, r.drive_preview_url
            FROM lms_submissions s
            LEFT JOIN lms_grades g ON g.submission_id = s.submission_id
            LEFT JOIN lms_submission_files sf ON sf.submission_id = s.submission_id
            LEFT JOIN lms_resources r ON r.resource_id = sf.resource_id
            WHERE s.assignment_id = :assignment_id
              AND (:can_view_all = 1 OR s.student_user_id = :user_id)
            ORDER BY s.submitted_at DESC, s.submission_id DESC';
    $params = [
        ':assignment_id' => $assignmentId,
        ':can_view_all' => $canViewAll ? 1 : 0,
        ':user_id' => (int)$user['user_id'],
    ];
    $debug['steps'][] = ['step' => 'load_submissions', 'sql' => $sql, 'params' => $params];

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $itemsBySubmissionId = [];
    foreach ($rows as $row) {
        $submissionId = (int)$row['submission_id'];
        if (!isset($itemsBySubmissionId[$submissionId])) {
            $itemsBySubmissionId[$submissionId] = [
                'submission_id' => $submissionId,
                'assignment_id' => (int)$row['assignment_id'],
                'student_user_id' => (int)$row['student_user_id'],
                'version' => (int)$row['version'],
                'status' => (string)$row['status'],
                'submitted_at' => $row['submitted_at'],
                'is_late' => (int)$row['is_late'],
                'grade' => $row['grade'] === null ? null : (float)$row['grade'],
                'files' => [],
            ];
        }

        if ($row['resource_id'] !== null) {
            $itemsBySubmissionId[$submissionId]['files'][] = [
                'resource_id' => (int)$row['resource_id'],
                'name' => (string)($row['file_name'] ?? ''),
                'mime_type' => (string)($row['mime_type'] ?? ''),
                'file_size' => $row['file_size'] === null ? null : (int)$row['file_size'],
                'preview_url' => (string)($row['drive_preview_url'] ?? ''),
            ];
        }
    }

    $items = array_values($itemsBySubmissionId);

    $response = ['items' => $items];
    if ($debugMode) {
        $response['debug'] = $debug;
    }
    lms_ok($response);
} catch (Throwable $e) {
    error_log('lms/assignments/submissions.php failed assignment_id=' . $assignmentId . ' user_id=' . (int)$user['user_id'] . ' message=' . $e->getMessage());
    $details = $debugMode ? array_merge($debug, ['exception' => $e->getMessage()]) : null;
    lms_error('submissions_fetch_failed', 'Failed to load submissions', 500, $details);
}
