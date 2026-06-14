<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';
require_once dirname(__DIR__) . '/drive_client.php';
require_once __DIR__ . '/_restriction_helpers.php';

const LMS_MAX_TEXT_SUBMISSION_LENGTH = 20000;
const LMS_MAX_SUBMISSION_COMMENT_LENGTH = 2000;

lms_require_feature(['assignments', 'lms_assignments']);
$user = lms_require_roles(['student']);
$assignmentId = (int)($_POST['assignment_id'] ?? 0);
if ($assignmentId <= 0) {
    lms_error('validation_error', 'assignment_id required', 422);
}

$textSubmission = trim((string)($_POST['text_submission'] ?? ''));
if ($textSubmission !== '' && function_exists('mb_strlen') && mb_strlen($textSubmission) > LMS_MAX_TEXT_SUBMISSION_LENGTH) {
    lms_error('validation_error', 'text_submission is too long', 422);
}
if ($textSubmission !== '' && !function_exists('mb_strlen') && strlen($textSubmission) > LMS_MAX_TEXT_SUBMISSION_LENGTH) {
    lms_error('validation_error', 'text_submission is too long', 422);
}
if ($textSubmission === '' && (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) {
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
lms_require_assignment_restriction_schema($pdo);
$aSt = $pdo->prepare('SELECT assignment_id, course_id, due_at, status, late_allowed, allowed_file_extensions, max_file_mb FROM lms_assignments WHERE assignment_id=:id AND deleted_at IS NULL');
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

$uploadMeta = null;
if (!empty($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $allowedExts = lms_assignment_allowed_extensions_for_validation(
        $assignment['allowed_file_extensions'] ?? null
    );
    $maxMb = max(1, (int)($assignment['max_file_mb'] ?? 50));
    $uploadMeta = lms_validate_uploaded_file(
        $_FILES['file'],
        lms_assignment_effective_max_bytes($maxMb),
        $allowedExts
    );
}

$uploadedDriveMeta = null;
$storage = null;
$storageContext = null;
if ($uploadMeta !== null) {
    if (!lms_drive_writes_enabled()) {
        lms_error('storage_unavailable', 'Private file uploads are temporarily disabled. Use a text submission or contact an administrator.', 503);
    }
    $storageContext = [
        'kind' => 'assignment_submission',
        'course_id' => (int)$assignment['course_id'],
        'assignment_id' => $assignmentId,
        'uploader_user_id' => (int)$user['user_id'],
    ];
    try {
        $storage = lms_drive_storage();
        $uploadedDriveMeta = $storage->upload($uploadMeta, $storageContext);
    } catch (DriveStorageException $e) {
        lms_drive_fail($e, 'drive_submission_upload', [
            'assignment_id' => $assignmentId,
            'course_id' => (int)$assignment['course_id'],
            'user_id' => (int)$user['user_id'],
        ]);
    }
}

try {
    $pdo->beginTransaction();
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
        $resourceMetadata = lms_drive_resource_metadata($uploadedDriveMeta, ($storageContext ?? []) + [
            'submission_id' => $submissionId,
        ]);
        $pdo->prepare('INSERT INTO lms_resources (course_id,title,resource_type,drive_file_id,drive_preview_url,mime_type,file_size,checksum_sha256,access_scope,metadata_json,created_by) VALUES (:c,:t,\'file\',:fid,NULL,:m,:size,:chk,\'assignment_submission\',:meta,:u)')->execute([
            ':c' => (int)$assignment['course_id'],
            ':t' => $uploadMeta['name'],
            ':fid' => $uploadedDriveMeta['file_id'],
            ':m' => $uploadedDriveMeta['mime_type'],
            ':size' => $uploadedDriveMeta['size'],
            ':chk' => $uploadedDriveMeta['checksum_sha256'],
            ':meta' => json_encode($resourceMetadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ':u' => (int)$user['user_id'],
        ]);
        $resourceId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO lms_submission_files (submission_id,resource_id,version) VALUES (:s,:r,:v)')->execute([
            ':s' => $submissionId,
            ':r' => $resourceId,
            ':v' => $version,
        ]);
        $storage->updateAppProperties((string)$uploadedDriveMeta['file_id'], [
            'resource_id' => $resourceId,
            'submission_id' => $submissionId,
            'assignment_id' => $assignmentId,
            'course_id' => (int)$assignment['course_id'],
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
        'has_comment' => $submissionComment !== '',
    ];
    lms_emit_event($pdo, 'assignment.submission.created', $event);

    $pdo->commit();
    lms_ok(['submission_id' => $submissionId, 'version' => $version, 'is_late' => $late]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($uploadedDriveMeta !== null && !empty($uploadedDriveMeta['file_id'])) {
        if ($storage instanceof DriveStorageInterface) {
            lms_drive_try_cleanup($storage, (string)$uploadedDriveMeta['file_id'], [
                'assignment_id' => $assignmentId,
                'course_id' => (int)$assignment['course_id'],
                'user_id' => (int)$user['user_id'],
            ]);
        }
    }
    error_log('assignment_submit_failed assignment_id=' . $assignmentId . ' user_id=' . (int)$user['user_id'] . ' exception=' . get_class($e));
    if ($e instanceof DriveStorageException) {
        lms_drive_fail($e, 'drive_submission_finalize', [
            'assignment_id' => $assignmentId,
            'course_id' => (int)$assignment['course_id'],
            'user_id' => (int)$user['user_id'],
        ]);
    }
    lms_error('submission_failed', 'Failed to submit assignment', 500);
}
