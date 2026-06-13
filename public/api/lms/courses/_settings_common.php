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
    lms_require_course_capability($user, 'manage_course', $courseId);
}

function lms_normalize_email(string $email): string
{
    return strtolower(trim($email));
}
