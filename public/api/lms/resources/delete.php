<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';

$user = lms_require_roles(['manager','admin']);
$in = lms_json_input();
$resourceId = (int)($in['resource_id'] ?? 0);
if ($resourceId <= 0) {
    lms_error('validation_error', 'resource_id required', 422);
}

$pdo = db();
$resourceStmt = $pdo->prepare('SELECT resource_id, course_id FROM lms_resources WHERE resource_id = :id AND deleted_at IS NULL LIMIT 1');
$resourceStmt->execute([':id' => $resourceId]);
$resource = $resourceStmt->fetch(PDO::FETCH_ASSOC);
if (!$resource) {
    lms_error('not_found', 'Resource not found', 404);
}

lms_course_access($user, (int)$resource['course_id']);

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE lms_resources SET deleted_at = CURRENT_TIMESTAMP WHERE resource_id = :id')->execute([':id' => $resourceId]);
    $pdo->prepare('DELETE FROM lms_module_items WHERE item_type IN (\'file\',\'video\',\'link\') AND entity_id = :id')->execute([':id' => $resourceId]);
    $pdo->commit();
    lms_ok(['deleted' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    lms_error('server_error', 'Failed to delete resource', 500);
}
