<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';
$user = lms_require_roles(['manager','admin']);
$in = lms_json_input();
$courseId=(int)($in['course_id']??0); $title=trim((string)($in['title']??''));
if($courseId<=0||$title===''){lms_error('validation_error','course_id and title required',422);} 
$pdo=db();
$st=$pdo->prepare('INSERT INTO lms_course_sections (course_id,title,description,position,created_by) VALUES (:c,:t,:d,:p,:u)');
$st->execute([':c'=>$courseId,':t'=>$title,':d'=>$in['description']??null,':p'=>(int)($in['position']??0),':u'=>(int)$user['user_id']]);
lms_ok(['section_id'=>(int)$pdo->lastInsertId()]);
