<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';

lms_require_roles(['manager','admin']);
$id = (int)(lms_json_input()['assignment_id'] ?? 0);
if ($id <= 0) {
    lms_error('validation_error', 'assignment_id required', 422);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE lms_assignments SET deleted_at=CURRENT_TIMESTAMP, status=\'archived\' WHERE assignment_id=:id')->execute([':id' => $id]);
    $pdo->prepare('DELETE FROM lms_module_items WHERE item_type = \'assignment\' AND entity_id = :id')->execute([':id' => $id]);
    $pdo->commit();
    lms_ok(['deleted' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    lms_error('server_error', 'Failed to delete assignment', 500);
}
