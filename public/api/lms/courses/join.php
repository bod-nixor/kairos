<?php
declare(strict_types=1);

require_once __DIR__ . '/../_common.php';

$user = require_login();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    lms_error('method_not_allowed', 'POST required', 405);
}
$in = lms_json_input();
$courseId = (int)($in['course_id'] ?? 0);
if ($courseId <= 0) {
    lms_error('validation_error', 'course_id required', 422);
}

$pdo = db();
$userId = (int)($user['user_id'] ?? 0);
$email = strtolower(trim((string)($user['email'] ?? '')));

try {
    $pdo->beginTransaction();
    $courseStmt = $pdo->prepare(
        'SELECT course_id, is_active, COALESCE(visibility, "public") AS visibility'
        . ' FROM courses'
        . ' WHERE course_id = :cid'
        . ' LIMIT 1'
        . ' FOR UPDATE'
    );
    $courseStmt->execute([':cid' => $courseId]);
    $course = $courseStmt->fetch(PDO::FETCH_ASSOC);
    if (!$course || (int)$course['is_active'] !== 1) {
        $pdo->rollBack();
        lms_error('not_found', 'Course not found', 404);
    }

    $existingStmt = $pdo->prepare(
        'SELECT 1 FROM student_courses WHERE course_id = :cid AND user_id = :uid LIMIT 1'
    );
    $existingStmt->execute([':cid' => $courseId, ':uid' => $userId]);
    $alreadyEnrolled = (bool)$existingStmt->fetchColumn();

    $canJoin = ((string)$course['visibility'] === 'public');
    if (!$canJoin && $email !== '') {
        $allowStmt = $pdo->prepare(
            'SELECT 1 FROM course_allowlist'
            . ' WHERE course_id = :cid AND LOWER(email) = :email'
            . ' LIMIT 1'
        );
        $allowStmt->execute([':cid' => $courseId, ':email' => $email]);
        $canJoin = (bool)$allowStmt->fetchColumn();
    }
    if (!$canJoin && $email !== '') {
        $preEnrollStmt = $pdo->prepare(
            'SELECT 1 FROM course_pre_enroll'
            . ' WHERE course_id = :cid AND LOWER(email) = :email'
            . ' LIMIT 1'
        );
        $preEnrollStmt->execute([':cid' => $courseId, ':email' => $email]);
        $canJoin = (bool)$preEnrollStmt->fetchColumn();
    }
    if (!$canJoin && !$alreadyEnrolled) {
        $pdo->rollBack();
        lms_error('forbidden', 'You are not eligible to join this course.', 403);
    }

    $ins = $pdo->prepare(
        'INSERT INTO student_courses (course_id, user_id)'
        . ' VALUES (:cid, :uid)'
        . ' ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)'
    );
    $ins->execute([':cid' => $courseId, ':uid' => $userId]);

    if ($email !== '' && rbac_table_has_columns($pdo, 'course_pre_enroll', ['course_id', 'email', 'claimed_user_id'])) {
        $claimStmt = $pdo->prepare(
            'UPDATE course_pre_enroll'
            . ' SET claimed_user_id = :uid'
            . ' WHERE course_id = :cid AND LOWER(email) = :email'
            . '   AND (claimed_user_id IS NULL OR claimed_user_id = :uid)'
        );
        $claimStmt->execute([':uid' => $userId, ':cid' => $courseId, ':email' => $email]);
    }

    lms_emit_event($pdo, 'course.enrollment.updated', [
        'event_name' => 'course.enrollment.updated',
        'event_id' => bin2hex(random_bytes(16)),
        'occurred_at' => gmdate('Y-m-d H:i:s'),
        'actor_id' => $userId,
        'entity_type' => 'course_enrollment',
        'entity_id' => $userId,
        'course_id' => $courseId,
        'delta' => ['enrolled' => true],
    ]);
    $pdo->commit();

    lms_ok([
        'joined' => !$alreadyEnrolled,
        'already_enrolled' => $alreadyEnrolled,
        'course_id' => $courseId,
        'access_context' => 'student',
    ]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[kairos] course self-enrollment failed: ' . get_class($error));
    lms_error('system_error', 'Unable to enrol in this course right now.', 500);
}
