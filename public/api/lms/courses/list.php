<?php
declare(strict_types=1);

require_once __DIR__ . '/../_common.php';

$user = require_login();
$pdo = db();
$userId = (int)($user['user_id'] ?? 0);

$stmt = $pdo->prepare('SELECT CAST(c.course_id AS UNSIGNED) AS course_id, c.name, COALESCE(c.code, "") AS code, COALESCE(c.visibility, "public") AS visibility FROM student_courses sc JOIN courses c ON c.course_id = sc.course_id WHERE sc.user_id = :uid ORDER BY c.name ASC');
$stmt->execute([':uid' => $userId]);
$enrolled = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

lms_ok(['courses' => $enrolled]);
