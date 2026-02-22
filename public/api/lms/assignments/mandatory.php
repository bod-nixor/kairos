<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';

lms_require_feature(['assignments','lms_assignments']);
$user = lms_require_roles(['manager','admin']);
$in = lms_json_input();
$assignmentId = (int)($in['assignment_id'] ?? 0);
$required = !empty($in['required']) ? 1 : 0;
if ($assignmentId <= 0) lms_error('validation_error', 'assignment_id required', 422);
$pdo = db();
$stmt = $pdo->prepare('SELECT assignment_id, course_id, section_id, title FROM lms_assignments WHERE assignment_id=:id AND deleted_at IS NULL LIMIT 1');
$stmt->execute([':id'=>$assignmentId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) lms_error('not_found', 'Assignment not found', 404);
lms_course_access($user, (int)$row['course_id']);
$updated = $pdo->prepare("UPDATE lms_module_items SET required_flag=:required, updated_at=CURRENT_TIMESTAMP WHERE item_type='assignment' AND entity_id=:id");
$updated->execute([':required'=>$required, ':id'=>$assignmentId]);
if ($updated->rowCount() === 0) {
    $pdo->prepare("INSERT INTO lms_module_items (course_id, section_id, item_type, entity_id, title, position, published_flag, required_flag, created_by)
    VALUES (:course_id,:section_id,'assignment',:entity_id,:title,1,0,:required,:created_by)")
    ->execute([':course_id'=>(int)$row['course_id'], ':section_id'=>$row['section_id'], ':entity_id'=>$assignmentId, ':title'=>$row['title'], ':required'=>$required, ':created_by'=>(int)$user['user_id']]);
}
lms_ok(['assignment_id'=>$assignmentId, 'required_flag'=>$required]);
