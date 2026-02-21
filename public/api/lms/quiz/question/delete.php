<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_common.php';

lms_require_roles(['manager', 'admin']);
$in = lms_json_input();
$id = (int)($in['question_id'] ?? 0);
if ($id <= 0) {
    lms_error('validation_error', 'question_id required', 422);
}

$stmt = db()->prepare('UPDATE lms_questions SET deleted_at = CURRENT_TIMESTAMP WHERE question_id=:id AND deleted_at IS NULL');
$stmt->execute([':id' => $id]);
if ($stmt->rowCount() === 0) {
    lms_error('not_found', 'Question not found', 404);
}

lms_ok(['deleted' => true]);
