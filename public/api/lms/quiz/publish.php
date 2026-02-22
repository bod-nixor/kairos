<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';

lms_require_feature(['quiz','quizzes','lms_quizzes']);
$user = lms_require_roles(['manager','admin']);
$in = lms_json_input();
$assessmentId = (int)($in['assessment_id'] ?? 0);
$published = !empty($in['published']) ? 1 : 0;
if ($assessmentId <= 0) lms_error('validation_error', 'assessment_id required', 422);
$pdo = db();
$stmt = $pdo->prepare('SELECT assessment_id, course_id, section_id, title FROM lms_assessments WHERE assessment_id=:id AND deleted_at IS NULL LIMIT 1');
$stmt->execute([':id'=>$assessmentId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) lms_error('not_found', 'Quiz not found', 404);
lms_course_access($user, (int)$row['course_id']);
$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE lms_assessments SET status=:status, updated_at=CURRENT_TIMESTAMP WHERE assessment_id=:id')->execute([':status'=>$published ? 'published':'draft', ':id'=>$assessmentId]);
    $updated = $pdo->prepare("UPDATE lms_module_items SET published_flag=:published, updated_at=CURRENT_TIMESTAMP WHERE item_type='quiz' AND entity_id=:id");
    $updated->execute([':published'=>$published, ':id'=>$assessmentId]);
    if ($updated->rowCount() === 0) {
        $pdo->prepare("INSERT INTO lms_module_items (course_id, section_id, item_type, entity_id, title, position, published_flag, required_flag, created_by)
            VALUES (:course_id,:section_id,'quiz',:entity_id,:title,1,:published,0,:created_by)")
            ->execute([':course_id'=>(int)$row['course_id'], ':section_id'=>$row['section_id'], ':entity_id'=>$assessmentId, ':title'=>$row['title'], ':published'=>$published, ':created_by'=>(int)$user['user_id']]);
    }
    $pdo->commit();
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); lms_error('publish_failed', 'Failed to update publish state', 500); }
lms_ok(['assessment_id'=>$assessmentId, 'published_flag'=>$published]);
