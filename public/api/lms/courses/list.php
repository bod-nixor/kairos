<?php
/**
 * GET /api/lms/courses/list.php
 * Lists all courses the current user has access to.
 *
 * Includes courses from:
 *  - student_courses (enrolled students)
 *  - course_staff (TAs, managers assigned to a course)
 *  - All courses for global admin/manager roles
 */
declare(strict_types=1);

require_once __DIR__ . '/../_common.php';

$user = require_login();
$pdo = db();
$userId = (int)($user['user_id'] ?? 0);
$role = lms_user_role($user);

if (in_array($role, ['admin', 'manager'], true)) {
    // Admins and global managers see all active courses
    $stmt = $pdo->prepare(
        'SELECT CAST(c.course_id AS UNSIGNED) AS course_id, c.name, COALESCE(c.code, "") AS code,
                COALESCE(c.visibility, "public") AS visibility
         FROM courses c
         WHERE c.is_active = 1
         ORDER BY c.name ASC'
    );
    $stmt->execute();
} else {
    // Students and TAs see courses they are enrolled in or assigned as staff
    $stmt = $pdo->prepare(
        'SELECT DISTINCT CAST(c.course_id AS UNSIGNED) AS course_id, c.name,
                COALESCE(c.code, "") AS code, COALESCE(c.visibility, "public") AS visibility
         FROM courses c
         WHERE c.is_active = 1
           AND (
             EXISTS (SELECT 1 FROM student_courses sc WHERE sc.user_id = :uid AND sc.course_id = c.course_id)
             OR EXISTS (SELECT 1 FROM course_staff cs WHERE cs.user_id = :uid2 AND cs.course_id = c.course_id)
           )
         ORDER BY c.name ASC'
    );
    $stmt->execute([':uid' => $userId, ':uid2' => $userId]);
}

$enrolled = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

lms_ok(['courses' => $enrolled]);
