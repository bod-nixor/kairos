<?php
declare(strict_types=1); require_once dirname(__DIR__,2) . '/_common.php'; lms_require_roles(['manager','admin']); $in=lms_json_input(); $id=(int)($in['question_id']??0); if($id<=0){lms_error('validation_error','question_id required',422);} db()->prepare('DELETE FROM lms_questions WHERE question_id=:id')->execute([':id'=>$id]); lms_ok(['deleted'=>true]);
