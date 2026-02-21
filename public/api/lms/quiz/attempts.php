<?php
declare(strict_types=1); require_once dirname(__DIR__) . '/_common.php'; $user=lms_require_roles(['student','ta','manager','admin']);
$assessmentId=(int)($_GET['assessment_id']??0); if($assessmentId<=0){lms_error('validation_error','assessment_id required',422);} $pdo=db();
$stmt = $pdo->prepare('SELECT attempt_id, assessment_id, user_id, status, score, max_score, started_at, submitted_at, grading_status FROM lms_assessment_attempts WHERE assessment_id=:a AND (:all = 1 OR user_id=:uid) ORDER BY started_at DESC');
$all = in_array($user['role_name'], ['manager','admin','ta'], true) ? 1 : 0;
$stmt->execute([':a'=>$assessmentId, ':all'=>$all, ':uid'=>(int)$user['user_id']]);
lms_ok(['items'=>$stmt->fetchAll()]);
