<?php
declare(strict_types=1); require_once dirname(__DIR__) . '/_common.php'; lms_require_roles(['manager','admin']); $in=lms_json_input(); $id=(int)($in['lesson_id']??0); if($id<=0){lms_error('validation_error','lesson_id required',422);} 
$pdo=db(); $pdo->prepare('UPDATE lms_lessons SET title=:t, summary=:s, position=:p, requires_previous=:r, updated_at=CURRENT_TIMESTAMP WHERE lesson_id=:id')->execute([':t'=>$in['title']??'',':s'=>$in['summary']??null,':p'=>(int)($in['position']??0),':r'=>!empty($in['requires_previous'])?1:0,':id'=>$id]); lms_ok(['updated'=>true]);
