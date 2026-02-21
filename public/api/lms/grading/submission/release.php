<?php
declare(strict_types=1); require_once dirname(__DIR__,2) . '/_common.php'; $user=lms_require_roles(['ta','manager','admin']); $in=lms_json_input(); $submissionId=(int)($in['submission_id']??0); if($submissionId<=0){lms_error('validation_error','submission_id required',422);} $pdo=db();
$st=$pdo->prepare('SELECT grade_id,course_id FROM lms_grades WHERE submission_id=:s LIMIT 1'); $st->execute([':s'=>$submissionId]); $g=$st->fetch(); if(!$g){lms_error('not_found','Draft grade not found',404);} 
$pdo->prepare('UPDATE lms_grades SET status=\'released\', released_by=:u, released_at=NOW(), updated_at=CURRENT_TIMESTAMP WHERE grade_id=:id')->execute([':u'=>(int)$user['user_id'],':id'=>(int)$g['grade_id']]);
$event=['event_name'=>'grade.released','event_id'=>lms_uuid_v4(),'occurred_at'=>gmdate('c'),'actor_id'=>(int)$user['user_id'],'entity_type'=>'grade','entity_id'=>(int)$g['grade_id'],'course_id'=>(int)$g['course_id']]; lms_emit_event($pdo,'grade.released',$event);
lms_ok(['released'=>true]);
