<?php
declare(strict_types=1); require_once dirname(__DIR__) . '/_common.php'; $user=lms_require_roles(['ta','manager','admin']);
$courseId=(int)($_GET['course_id']??0); $assignmentId=(int)($_GET['assignment_id']??0); if($courseId<=0){lms_error('validation_error','course_id required',422);} $pdo=db();
$sql='SELECT s.submission_id, s.assignment_id, s.student_user_id, s.status, s.submitted_at, s.is_late FROM lms_submissions s JOIN lms_assignments a ON a.assignment_id=s.assignment_id WHERE s.course_id=:course_id';
$params=[':course_id'=>$courseId];
if($assignmentId>0){$sql.=' AND s.assignment_id=:assignment_id';$params[':assignment_id']=$assignmentId;}
if($user['role_name']==='ta'){ $sql.=' AND EXISTS (SELECT 1 FROM lms_assignment_tas t WHERE t.assignment_id=s.assignment_id AND t.ta_user_id=:uid)'; $params[':uid']=(int)$user['user_id']; }
$sql.=' ORDER BY s.submitted_at ASC LIMIT 500';
$st=$pdo->prepare($sql); $st->execute($params); lms_ok(['items'=>$st->fetchAll()]);
