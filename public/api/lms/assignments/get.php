<?php
declare(strict_types=1); require_once dirname(__DIR__) . '/_common.php'; $user=lms_require_roles(['student','ta','manager','admin']);
$id=(int)($_GET['assignment_id']??0); if($id<=0){lms_error('validation_error','assignment_id required',422);} $pdo=db();
$st=$pdo->prepare('SELECT assignment_id, course_id, section_id, title, instructions, due_at, late_allowed, max_points, status FROM lms_assignments WHERE assignment_id=:id AND deleted_at IS NULL LIMIT 1'); $st->execute([':id'=>$id]); $a=$st->fetch(); if(!$a){lms_error('not_found','Assignment not found',404);} lms_course_access($user,(int)$a['course_id']); lms_ok($a);
