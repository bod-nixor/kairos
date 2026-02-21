<?php
declare(strict_types=1); require_once dirname(__DIR__,2) . '/_common.php'; lms_require_roles(['manager','admin']); $in=lms_json_input();
$assignmentId=(int)($in['assignment_id']??0); $taIds=$in['ta_user_ids']??[]; if($assignmentId<=0||!is_array($taIds)){lms_error('validation_error','assignment_id and ta_user_ids[] required',422);} $pdo=db();
$pdo->beginTransaction(); $pdo->prepare('DELETE FROM lms_assignment_tas WHERE assignment_id=:a')->execute([':a'=>$assignmentId]); $ins=$pdo->prepare('INSERT INTO lms_assignment_tas (assignment_id,ta_user_id) VALUES (:a,:u)'); foreach($taIds as $tid){$ins->execute([':a'=>$assignmentId,':u'=>(int)$tid]);} $pdo->commit(); lms_ok(['assignment_id'=>$assignmentId,'ta_user_ids'=>array_map('intval',$taIds)]);
