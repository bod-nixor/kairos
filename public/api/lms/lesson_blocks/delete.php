<?php
declare(strict_types=1); require_once dirname(__DIR__) . '/_common.php'; lms_require_roles(['manager','admin']); $in=lms_json_input(); $id=(int)($in['block_id']??0); if($id<=0){lms_error('validation_error','block_id required',422);} db()->prepare('UPDATE lms_lesson_blocks SET deleted_at=CURRENT_TIMESTAMP WHERE block_id=:id')->execute([':id'=>$id]); lms_ok(['deleted'=>true]);
