<?php
/**
 * Admin-only diagnostic endpoint for LMS schema inspection.
 * Protected by admin RBAC — requires active session with admin role.
 * TEMPORARY: remove after schema issues are resolved.
 *
 * GET /api/admin/diag_lms_schema.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lms/_common.php';

$user = lms_require_roles(['admin']);

$pdo = db();
$result = [];

// 1. Check lms_event_outbox schema
try {
    $stmt = $pdo->query('SHOW CREATE TABLE lms_event_outbox');
    $row = $stmt->fetch();
    $result['lms_event_outbox_schema'] = $row['Create Table'] ?? $row[1] ?? 'could not read';
} catch (Throwable $e) {
    $result['lms_event_outbox_schema'] = 'ERROR: ' . $e->getMessage();
}

// 2. Check lms_feature_flags schema
try {
    $stmt = $pdo->query('SHOW CREATE TABLE lms_feature_flags');
    $row = $stmt->fetch();
    $result['lms_feature_flags_schema'] = $row['Create Table'] ?? $row[1] ?? 'could not read';
} catch (Throwable $e) {
    $result['lms_feature_flags_schema'] = 'ERROR: ' . $e->getMessage();
}

// 3. Feature flag rows
try {
    $stmt = $pdo->query('SELECT * FROM lms_feature_flags ORDER BY feature_flag_id ASC LIMIT 50');
    $result['lms_feature_flags_rows'] = $stmt->fetchAll();
} catch (Throwable $e) {
    $result['lms_feature_flags_rows'] = 'ERROR: ' . $e->getMessage();
}

// 4. Last 5 event outbox rows
try {
    $stmt = $pdo->query('SELECT event_id, event_name, occurred_at, actor_user_id, course_id, entity_type, entity_id, created_at FROM lms_event_outbox ORDER BY created_at DESC LIMIT 5');
    $result['lms_event_outbox_recent'] = $stmt->fetchAll();
} catch (Throwable $e) {
    $result['lms_event_outbox_recent'] = 'ERROR: ' . $e->getMessage();
}

// 5. Check lms_assessments schema (for time_limit column name)
try {
    $stmt = $pdo->query('SHOW CREATE TABLE lms_assessments');
    $row = $stmt->fetch();
    $result['lms_assessments_schema'] = $row['Create Table'] ?? $row[1] ?? 'could not read';
} catch (Throwable $e) {
    $result['lms_assessments_schema'] = 'ERROR: ' . $e->getMessage();
}

// 6. Check lms_assignments schema
try {
    $stmt = $pdo->query('SHOW CREATE TABLE lms_assignments');
    $row = $stmt->fetch();
    $result['lms_assignments_schema'] = $row['Create Table'] ?? $row[1] ?? 'could not read';
} catch (Throwable $e) {
    $result['lms_assignments_schema'] = 'ERROR: ' . $e->getMessage();
}

// 7. Test the exact quizzes query path
try {
    $stmt = $pdo->prepare(
        "SELECT assessment_id AS id, title, description,
                time_limit_min, max_attempts, due_at AS due_date, status
         FROM lms_assessments
         WHERE course_id = :course_id AND deleted_at IS NULL
         ORDER BY due_at ASC, assessment_id ASC"
    );
    $stmt->execute([':course_id' => 3]);
    $result['quizzes_test_query'] = 'OK — returned ' . count($stmt->fetchAll()) . ' rows';
} catch (Throwable $e) {
    $result['quizzes_test_query'] = 'ERROR: ' . $e->getMessage();
}

// 8. Test the exact assignment update event emit path
try {
    $testOccurred = gmdate('c');
    $result['event_occurred_format'] = $testOccurred;
    // Test if lms_event_outbox accepts this format (dry run via PREPARE only)
    $stmt = $pdo->prepare('INSERT INTO lms_event_outbox (event_id, event_name, occurred_at, actor_user_id, course_id, entity_type, entity_id, payload_json) VALUES (:event_id,:event_name,:occurred_at,:actor_user_id,:course_id,:entity_type,:entity_id,:payload_json)');
    $result['event_insert_prepare'] = 'OK';
} catch (Throwable $e) {
    $result['event_insert_prepare'] = 'ERROR: ' . $e->getMessage();
}

// 9. Check lms_questions schema (for deleted_at + is_required)
try {
    $stmt = $pdo->query('SHOW CREATE TABLE lms_questions');
    $row = $stmt->fetch();
    $result['lms_questions_schema'] = $row['Create Table'] ?? $row[1] ?? 'could not read';
} catch (Throwable $e) {
    $result['lms_questions_schema'] = 'ERROR: ' . $e->getMessage();
}

lms_ok($result);
