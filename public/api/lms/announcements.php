<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

$user = lms_require_roles(['student', 'ta', 'manager', 'admin']);
$courseId = (int)($_GET['course_id'] ?? 0);
$limit = max(1, min(200, (int)($_GET['limit'] ?? 200)));
if ($courseId <= 0) {
    lms_error('validation_error', 'course_id required', 422);
}

lms_course_access($user, $courseId);
$pdo = db();
$canManage = rbac_can($pdo, $user, 'manage_course_announcements', $courseId);
$visibilitySql = $canManage ? '' : " AND a.status = 'published'";

$stmt = $pdo->prepare(
    'SELECT a.announcement_id, a.course_id, a.title, a.body, a.status, a.published_at,'
    . ' a.created_by, a.created_at, a.updated_at, u.name AS author_name, nr.seen_at AS read_at'
    . ' FROM lms_announcements a'
    . ' JOIN users u ON u.user_id = a.created_by'
    . ' LEFT JOIN lms_notification_reads nr'
    . ' ON nr.user_id = :user_id'
    . ' AND nr.course_id = a.course_id'
    . " AND nr.event_id = CONCAT('announcement:', a.announcement_id)"
    . ' WHERE a.course_id = :course_id'
    . ' AND a.deleted_at IS NULL'
    . $visibilitySql
    . ' ORDER BY COALESCE(a.published_at, a.created_at) DESC, a.announcement_id DESC'
    . ' LIMIT ' . $limit
);
$stmt->execute([
    ':user_id' => (int)$user['user_id'],
    ':course_id' => $courseId,
]);

lms_ok([
    'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    'can_manage' => $canManage,
]);
