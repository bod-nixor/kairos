<?php
declare(strict_types=1); require_once dirname(__DIR__) . '/_common.php';
lms_require_roles(['manager','admin']); $in=lms_json_input(); $id=(int)($in['section_id']??0); if($id<=0){lms_error('validation_error','section_id required',422);} 
$pdo=db(); $pdo->prepare('UPDATE lms_course_sections SET deleted_at=CURRENT_TIMESTAMP WHERE section_id=:id')->execute([':id'=>$id]); lms_ok(['deleted'=>true]);
