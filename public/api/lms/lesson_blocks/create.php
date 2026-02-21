<?php
declare(strict_types=1); require_once dirname(__DIR__) . '/_common.php'; lms_require_roles(['manager','admin']); $in=lms_json_input();
$lessonId=(int)($in['lesson_id']??0); $type=trim((string)($in['block_type']??'')); if($lessonId<=0||$type===''){lms_error('validation_error','lesson_id and block_type required',422);} 
$pdo=db(); $pdo->prepare('INSERT INTO lms_lesson_blocks (lesson_id,position,block_type,content_json,resource_id) VALUES (:l,:p,:t,:c,:r)')->execute([':l'=>$lessonId,':p'=>(int)($in['position']??0),':t'=>$type,':c'=>json_encode($in['content']??new stdClass()),':r'=>isset($in['resource_id'])?(int)$in['resource_id']:null]); lms_ok(['block_id'=>(int)$pdo->lastInsertId()]);
