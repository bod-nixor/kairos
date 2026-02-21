<?php
declare(strict_types=1); require_once dirname(__DIR__) . '/_common.php'; $user=lms_require_roles(['manager','admin']); $in=lms_json_input(); $courseId=(int)($in['course_id']??0); if($courseId<=0||empty($in['title'])||empty($in['body'])){lms_error('validation_error','course_id, title, body required',422);} $pdo=db();
$pdo->prepare('INSERT INTO lms_announcements (course_id,title,body,created_by) VALUES (:c,:t,:b,:u)')->execute([':c'=>$courseId,':t'=>$in['title'],':b'=>$in['body'],':u'=>(int)$user['user_id']]); $id=(int)$pdo->lastInsertId();
$event=['event_name'=>'announcement.created','event_id'=>lms_uuid_v4(),'occurred_at'=>gmdate('c'),'actor_id'=>(int)$user['user_id'],'entity_type'=>'announcement','entity_id'=>$id,'course_id'=>$courseId,'title'=>$in['title']]; lms_emit_event($pdo,'announcement.created',$event);
lms_ok(['announcement_id'=>$id]);
