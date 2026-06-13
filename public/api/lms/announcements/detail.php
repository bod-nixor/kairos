<?php
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$user = lms_require_roles(['student', 'ta', 'manager', 'admin']);
$rawCourseId = $_GET['course_id'] ?? null;
$rawAnnouncementId = $_GET['announcement_id'] ?? null;
$courseId = is_string($rawCourseId) && preg_match('/^[1-9][0-9]*$/D', $rawCourseId) === 1
    ? filter_var($rawCourseId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
$announcementId = is_string($rawAnnouncementId) && preg_match('/^[1-9][0-9]*$/D', $rawAnnouncementId) === 1
    ? filter_var($rawAnnouncementId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
if ($courseId === false || $announcementId === false) {
    lms_error('validation_error', 'course_id and announcement_id required', 422);
}

lms_course_access($user, $courseId);
$pdo = db();
$canManage = rbac_can($pdo, $user, 'manage_course_announcements', $courseId);
$visibilitySql = $canManage ? '' : " AND a.status = 'published'";

$stmt = $pdo->prepare(
    'SELECT a.announcement_id, a.course_id, a.title, a.body, a.status, a.published_at,'
    . ' a.created_at, a.updated_at, a.created_by, u.name AS author_name,'
    . ' c.name AS course_name, c.code AS course_code, nr.seen_at AS read_at'
    . ' FROM lms_announcements a'
    . ' JOIN users u ON u.user_id = a.created_by'
    . ' JOIN courses c ON c.course_id = a.course_id'
    . ' LEFT JOIN lms_notification_reads nr'
    . ' ON nr.user_id = :user_id'
    . ' AND nr.course_id = a.course_id'
    . " AND nr.event_id = CONCAT('announcement:', a.announcement_id)"
    . ' WHERE a.announcement_id = :announcement_id'
    . ' AND a.course_id = :course_id'
    . ' AND a.deleted_at IS NULL'
    . $visibilitySql
    . ' LIMIT 1'
);
$stmt->execute([
    ':user_id' => (int)$user['user_id'],
    ':announcement_id' => $announcementId,
    ':course_id' => $courseId,
]);
$announcement = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$announcement) {
    lms_error('not_found', 'Announcement is unavailable', 404);
}

$audit = [];
if ($canManage) {
    $auditStmt = $pdo->prepare(
        'SELECT aa.action, aa.created_at, u.name AS actor_name'
        . ' FROM lms_announcement_audit aa'
        . ' JOIN users u ON u.user_id = aa.actor_id'
        . ' WHERE aa.announcement_id = :announcement_id AND aa.course_id = :course_id'
        . ' ORDER BY aa.created_at DESC, aa.announcement_audit_id DESC'
        . ' LIMIT 25'
    );
    $auditStmt->execute([
        ':announcement_id' => $announcementId,
        ':course_id' => $courseId,
    ]);
    $audit = $auditStmt->fetchAll(PDO::FETCH_ASSOC);
}

lms_ok([
    'announcement' => $announcement,
    'audit' => $audit,
    'can_manage' => $canManage,
]);
