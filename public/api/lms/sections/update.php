<?php
declare(strict_types=1); require_once dirname(__DIR__) . '/_common.php';
lms_require_roles(['manager','admin']); $in=lms_json_input(); $id=(int)($in['section_id']??0); if($id<=0){lms_error('validation_error','section_id required',422);} 
$pdo=db(); $st=$pdo->prepare('UPDATE lms_course_sections SET title=:t, description=:d, position=:p, updated_at=CURRENT_TIMESTAMP WHERE section_id=:id');
$st->execute([':t'=>$in['title']??'',':d'=>$in['description']??null,':p'=>(int)($in['position']??0),':id'=>$id]); lms_ok(['updated'=>$st->rowCount()>0]);
