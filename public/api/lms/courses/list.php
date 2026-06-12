<?php
/**
 * GET /api/lms/courses/list.php
 * Lists all courses the current user has access to.
 *
 * Includes courses from:
 *  - student_courses (enrolled students)
 *  - course_staff (TAs, managers assigned to a course)
 *  - All courses for administrators
 */
declare(strict_types=1);

require_once __DIR__ . '/../_common.php';

$user = require_login();
$pdo = db();
$role = lms_user_role($user);

if ($role === 'admin') {
    $stmt = $pdo->prepare(
        'SELECT CAST(c.course_id AS UNSIGNED) AS course_id, c.name, COALESCE(c.code, "") AS code,
                COALESCE(c.visibility, "public") AS visibility
         FROM courses c
         WHERE c.is_active = 1
         ORDER BY c.name ASC'
    );
    $stmt->execute();
} else {
    $courseIds = rbac_accessible_course_ids($pdo, $user) ?? [];
    if (!$courseIds) {
        lms_ok(['courses' => []]);
    }
    $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
    $stmt = $pdo->prepare(
        'SELECT CAST(c.course_id AS UNSIGNED) AS course_id, c.name,
                COALESCE(c.code, "") AS code, COALESCE(c.visibility, "public") AS visibility
         FROM courses c
         WHERE c.is_active = 1
           AND c.course_id IN (' . $placeholders . ')
         ORDER BY c.name ASC'
    );
    $stmt->execute(array_values($courseIds));
}

$enrolled = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

lms_ok(['courses' => $enrolled]);
