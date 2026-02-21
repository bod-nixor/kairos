<?php
declare(strict_types=1); require_once dirname(__DIR__) . '/_common.php'; $user=lms_require_roles(['student','ta','manager','admin']); $in=lms_json_input();
$assessmentId=(int)($in['assessment_id']??0); if($assessmentId<=0){lms_error('validation_error','assessment_id required',422);} $pdo=db();
$assessment=$pdo->prepare('SELECT assessment_id, course_id, max_attempts FROM lms_assessments WHERE assessment_id=:id AND deleted_at IS NULL LIMIT 1'); $assessment->execute([':id'=>$assessmentId]); $a=$assessment->fetch(); if(!$a){lms_error('not_found','Assessment not found',404);} 
lms_course_access($user,(int)$a['course_id']);
$count=$pdo->prepare('SELECT COUNT(*) FROM lms_assessment_attempts WHERE assessment_id=:a AND user_id=:u'); $count->execute([':a'=>$assessmentId,':u'=>(int)$user['user_id']]); if((int)$count->fetchColumn()>=(int)$a['max_attempts']){lms_error('attempt_limit','Attempt limit reached',409);} 
$pdo->prepare('INSERT INTO lms_assessment_attempts (assessment_id,course_id,user_id) VALUES (:a,:c,:u)')->execute([':a'=>$assessmentId,':c'=>(int)$a['course_id'],':u'=>(int)$user['user_id']]); lms_ok(['attempt_id'=>(int)$pdo->lastInsertId()]);
