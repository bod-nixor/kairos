<?php
declare(strict_types=1); require_once dirname(__DIR__) . '/_common.php'; $user=lms_require_roles(['student','ta','manager','admin']);
$assignmentId=(int)($_GET['assignment_id']??0); if($assignmentId<=0){lms_error('validation_error','assignment_id required',422);} $pdo=db();
$all=in_array($user['role_name'],['manager','admin'],true); if($user['role_name']==='ta'){ $chk=$pdo->prepare('SELECT 1 FROM lms_assignment_tas WHERE assignment_id=:a AND ta_user_id=:u LIMIT 1'); $chk->execute([':a'=>$assignmentId,':u'=>(int)$user['user_id']]); $all=(bool)$chk->fetchColumn(); }
$stmt=$pdo->prepare('SELECT submission_id, assignment_id, student_user_id, version, status, submitted_at, is_late FROM lms_submissions WHERE assignment_id=:a AND (:all=1 OR student_user_id=:u) ORDER BY submitted_at DESC');
$stmt->execute([':a'=>$assignmentId,':all'=>$all?1:0,':u'=>(int)$user['user_id']]); lms_ok(['items'=>$stmt->fetchAll()]);
