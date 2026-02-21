<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';
require_once dirname(__DIR__) . '/drive_client.php';

lms_require_feature(['assignments', 'lms_assignments']);
$user = lms_require_roles(['student', 'ta', 'manager', 'admin']);
$assignmentId = (int)($_POST['assignment_id'] ?? 0);
if ($assignmentId <= 0) {
    lms_error('validation_error', 'assignment_id required', 422);
}

$pdo = db();
$aSt = $pdo->prepare('SELECT assignment_id, course_id, due_at, status, late_allowed FROM lms_assignments WHERE assignment_id=:id AND deleted_at IS NULL');
$aSt->execute([':id' => $assignmentId]);
$assignment = $aSt->fetch();
if (!$assignment) {
    lms_error('not_found', 'Assignment not found', 404);
}

lms_course_access($user, (int)$assignment['course_id']);
if ((string)$assignment['status'] !== 'published') {
    lms_error('not_allowed', 'Submissions are only allowed for published assignments', 403);
}

$late = !empty($assignment['due_at']) && strtotime((string)$assignment['due_at']) < time();
if ($late && (int)$assignment['late_allowed'] === 0) {
    lms_error('late_not_allowed', 'Late submissions are not allowed for this assignment', 422);
}

$pdo->beginTransaction();
try {
    $verStmt = $pdo->prepare('SELECT COALESCE(MAX(version),0)+1 FROM lms_submissions WHERE assignment_id=:a AND student_user_id=:u FOR UPDATE');
    $verStmt->execute([':a' => $assignmentId, ':u' => (int)$user['user_id']]);
    $version = (int)$verStmt->fetchColumn();

    $pdo->prepare('INSERT INTO lms_submissions (assignment_id,course_id,student_user_id,version,text_submission,status,is_late) VALUES (:a,:c,:u,:v,:t,:s,:l)')->execute([
        ':a' => $assignmentId,
        ':c' => (int)$assignment['course_id'],
        ':u' => (int)$user['user_id'],
        ':v' => $version,
        ':t' => $_POST['text_submission'] ?? null,
        ':s' => $late ? 'late' : 'submitted',
        ':l' => $late ? 1 : 0,
    ]);
    $submissionId = (int)$pdo->lastInsertId();

    if (!empty($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $meta = lms_drive_upload_stub((string)$_FILES['file']['name'], (string)$_FILES['file']['tmp_name'], (string)($_FILES['file']['type'] ?? 'application/octet-stream'));
        $pdo->prepare('INSERT INTO lms_resources (course_id,title,resource_type,drive_file_id,drive_preview_url,mime_type,file_size,checksum_sha256,access_scope,metadata_json,created_by) VALUES (:c,:t,\'file\',:fid,:url,:m,:size,:chk,\'assignment_submission\',:meta,:u)')->execute([
            ':c' => (int)$assignment['course_id'],
            ':t' => (string)$_FILES['file']['name'],
            ':fid' => $meta['file_id'],
            ':url' => $meta['preview_url'],
            ':m' => $meta['mime_type'],
            ':size' => $meta['size'],
            ':chk' => $meta['checksum'],
            ':meta' => json_encode($meta),
            ':u' => (int)$user['user_id'],
        ]);
        $resourceId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO lms_submission_files (submission_id,resource_id,version) VALUES (:s,:r,:v)')->execute([
            ':s' => $submissionId,
            ':r' => $resourceId,
            ':v' => $version,
        ]);
    }

    $event = [
        'event_name' => 'assignment.submission.created',
        'event_id' => lms_uuid_v4(),
        'occurred_at' => gmdate('c'),
        'actor_id' => (int)$user['user_id'],
        'entity_type' => 'submission',
        'entity_id' => $submissionId,
        'course_id' => (int)$assignment['course_id'],
        'assignment_id' => $assignmentId,
        'is_late' => $late,
    ];
    lms_emit_event($pdo, 'assignment.submission.created', $event);

    $pdo->commit();
    lms_ok(['submission_id' => $submissionId, 'version' => $version, 'is_late' => $late]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    lms_error('submission_failed', 'Failed to submit assignment', 500);
}
