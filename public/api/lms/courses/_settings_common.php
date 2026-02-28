<?php
declare(strict_types=1);

require_once __DIR__ . '/../_common.php';

function lms_course_exists(PDO $pdo, int $courseId): bool
{
    if ($courseId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM courses WHERE course_id = :cid LIMIT 1');
    $stmt->execute([':cid' => $courseId]);
    return (bool)$stmt->fetchColumn();
}

function lms_require_course_manager_or_admin(PDO $pdo, array $user, int $courseId): void
{
    $role = lms_user_role($user);
    if ($role === 'admin') {
        return;
    }

    if ($role !== 'manager') {
        lms_error('forbidden', 'Insufficient permissions.', 403);
    }

    $stmt = $pdo->prepare('SELECT 1 FROM course_staff WHERE user_id = :uid AND course_id = :cid AND role = :role LIMIT 1');
    $stmt->execute([
        ':uid' => (int)($user['user_id'] ?? 0),
        ':cid' => $courseId,
        ':role' => 'manager',
    ]);
    if (!$stmt->fetchColumn()) {
        lms_error('forbidden', 'Manager access to this course is required.', 403);
    }
}

function lms_normalize_email(string $email): string
{
    return strtolower(trim($email));
}
