<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';
require_once dirname(__DIR__) . '/drive_client.php';

const LMS_MAX_TEXT_SUBMISSION_LENGTH = 20000;
const LMS_MAX_SUBMISSION_COMMENT_LENGTH = 2000;

lms_require_feature(['assignments', 'lms_assignments']);
$user = lms_require_roles(['student']);
$assignmentId = (int)($_POST['assignment_id'] ?? 0);
if ($assignmentId <= 0) {
    lms_error('validation_error', 'assignment_id required', 422);
}

$uploadMeta = null;
if (!empty($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
        lms_error('validation_error', 'file upload failed', 422);
    }

    $size = (int)($file['size'] ?? 0);
    $maxBytes = (int)env('LMS_UPLOAD_MAX_BYTES', 10485760);
    if ($size <= 0 || $size > $maxBytes) {
        lms_error('validation_error', 'file exceeds maximum size', 422);
    }

    $filename = trim((string)($file['name'] ?? ''));
    if ($filename === '') {
        $filename = 'submission_file';
    }
    if (function_exists('mb_substr')) {
        $filename = mb_substr($filename, 0, 255);
    } else {
        $filename = substr($filename, 0, 255);
    }

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $blocked = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'exe', 'js', 'sh'];
    if ($ext !== '' && in_array($ext, $blocked, true)) {
        lms_error('validation_error', 'file type is not allowed', 422);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = (string)$finfo->file((string)$file['tmp_name']);
    $allowedMimes = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'text/plain',
        'application/zip',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/msword',
    ];
    if (!in_array($detectedMime, $allowedMimes, true)) {
        lms_error('validation_error', 'unsupported file content type', 422);
    }

    $uploadMeta = [
        'name' => $filename,
        'tmp_name' => (string)$file['tmp_name'],
        'mime_type' => $detectedMime,
    ];
}

$textSubmission = trim((string)($_POST['text_submission'] ?? ''));
if ($textSubmission !== '' && function_exists('mb_strlen') && mb_strlen($textSubmission) > LMS_MAX_TEXT_SUBMISSION_LENGTH) {
    lms_error('validation_error', 'text_submission is too long', 422);
}
if ($textSubmission !== '' && !function_exists('mb_strlen') && strlen($textSubmission) > LMS_MAX_TEXT_SUBMISSION_LENGTH) {
    lms_error('validation_error', 'text_submission is too long', 422);
}
if ($textSubmission === '' && $uploadMeta === null) {
    lms_error('validation_error', 'Provide text_submission or a file', 422);
}


$submissionComment = trim((string)($_POST['submission_comment'] ?? ''));
if ($submissionComment !== '' && function_exists('mb_strlen') && mb_strlen($submissionComment) > LMS_MAX_SUBMISSION_COMMENT_LENGTH) {
    lms_error('validation_error', 'submission_comment is too long', 422);
}
if ($submissionComment !== '' && !function_exists('mb_strlen') && strlen($submissionComment) > LMS_MAX_SUBMISSION_COMMENT_LENGTH) {
    lms_error('validation_error', 'submission_comment is too long', 422);
}

$pdo = db();
$aSt = $pdo->prepare('SELECT assignment_id, course_id, due_at, status, late_allowed FROM lms_assignments WHERE assignment_id=:id AND deleted_at IS NULL');
$aSt->execute([':id' => $assignmentId]);
$assignment = $aSt->fetch();
if (!$assignment) {
    lms_error('not_found', 'Assignment not found', 404);
}

lms_course_access($user, (int)$assignment['course_id'], false);
if ((string)$assignment['status'] !== 'published') {
    lms_error('not_allowed', 'Submissions are only allowed for published assignments', 403);
}

$late = !empty($assignment['due_at']) && strtotime((string)$assignment['due_at']) < time();
if ($late && (int)$assignment['late_allowed'] === 0) {
    lms_error('late_not_allowed', 'Late submissions are not allowed for this assignment', 422);
}

$uploadedDriveMeta = null;
if ($uploadMeta !== null) {
    $uploadedDriveMeta = lms_drive_upload_stub($uploadMeta['name'], $uploadMeta['tmp_name'], $uploadMeta['mime_type']);
}

$pdo->beginTransaction();
try {
    $verStmt = $pdo->prepare('SELECT COALESCE(MAX(version),0)+1 FROM lms_submissions WHERE assignment_id=:a AND student_user_id=:u FOR UPDATE');
    $verStmt->execute([':a' => $assignmentId, ':u' => (int)$user['user_id']]);
    $version = (int)$verStmt->fetchColumn();

    $status = $late ? 'late' : 'submitted';
    $pdo->prepare('INSERT INTO lms_submissions (assignment_id,course_id,student_user_id,version,text_submission,submission_comment,status,is_late) VALUES (:a,:c,:u,:v,:t,:comment,:s,:l)')->execute([
        ':a' => $assignmentId,
        ':c' => (int)$assignment['course_id'],
        ':u' => (int)$user['user_id'],
        ':v' => $version,
        ':t' => $textSubmission === '' ? null : $textSubmission,
        ':comment' => $submissionComment === '' ? null : $submissionComment,
        ':s' => $status,
        ':l' => $late ? 1 : 0,
    ]);
    $submissionId = (int)$pdo->lastInsertId();

    if ($uploadedDriveMeta !== null && $uploadMeta !== null) {
        $pdo->prepare('INSERT INTO lms_resources (course_id,title,resource_type,drive_file_id,drive_preview_url,mime_type,file_size,checksum_sha256,access_scope,metadata_json,created_by) VALUES (:c,:t,\'file\',:fid,:url,:m,:size,:chk,\'assignment_submission\',:meta,:u)')->execute([
            ':c' => (int)$assignment['course_id'],
            ':t' => $uploadMeta['name'],
            ':fid' => $uploadedDriveMeta['file_id'],
            ':url' => $uploadedDriveMeta['preview_url'],
            ':m' => $uploadedDriveMeta['mime_type'],
            ':size' => $uploadedDriveMeta['size'],
            ':chk' => $uploadedDriveMeta['checksum'],
            ':meta' => json_encode($uploadedDriveMeta),
            ':u' => (int)$user['user_id'],
        ]);
        $resourceId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO lms_submission_files (submission_id,resource_id,version) VALUES (:s,:r,:v)')->execute([
            ':s' => $submissionId,
            ':r' => $resourceId,
            ':v' => $version,
        ]);
    }

    $pdo->prepare('INSERT INTO lms_submission_audit (submission_id, course_id, assignment_id, actor_id, new_status, occurred_at, version, metadata_json) VALUES (:submission_id, :course_id, :assignment_id, :actor_id, :new_status, :occurred_at, :version, :metadata_json)')->execute([
        ':submission_id' => $submissionId,
        ':course_id' => (int)$assignment['course_id'],
        ':assignment_id' => $assignmentId,
        ':actor_id' => (int)$user['user_id'],
        ':new_status' => $status,
        ':occurred_at' => gmdate('Y-m-d H:i:s'),
        ':version' => $version,
        ':metadata_json' => json_encode(['is_late' => $late, 'has_file' => $uploadMeta !== null, 'has_comment' => $submissionComment !== ''], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $event = [
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
    if ($uploadedDriveMeta !== null && !empty($uploadedDriveMeta['file_id'])) {
        lms_drive_delete_stub((string)$uploadedDriveMeta['file_id']);
    }
    error_log('assignment_submit_failed assignment_id=' . $assignmentId . ' user_id=' . (int)$user['user_id'] . ' message=' . $e->getMessage() . ' trace=' . $e->getTraceAsString());
    lms_error('submission_failed', 'Failed to submit assignment', 500);
}
