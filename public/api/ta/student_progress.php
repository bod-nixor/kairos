<?php
declare(strict_types=1);

require_once __DIR__.'/common.php';
[$pdo, $user] = require_ta_user();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$courseId  = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$studentId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if ($courseId <= 0 || $studentId <= 0) {
    json_out(['error' => 'course_id and user_id required'], 400);
}

if (!ta_has_course($pdo, (int)$user['user_id'], $courseId)) {
    json_out(['error' => 'forbidden'], 403);
}

// Fetch categories for the course
$catStmt = $pdo->prepare('SELECT CAST(category_id AS UNSIGNED) AS category_id, name
                           FROM progress_category
                           WHERE course_id = :cid
                           ORDER BY name');
$catStmt->execute([':cid' => $courseId]);
$categories = $catStmt->fetchAll() ?: [];

// Fetch details grouped by category
$detailStmt = $pdo->prepare('SELECT CAST(d.detail_id AS UNSIGNED) AS detail_id,
                                    CAST(d.category_id AS UNSIGNED) AS category_id,
                                    d.name
                             FROM progress_details d
                             JOIN progress_category c ON c.category_id = d.category_id
                             WHERE c.course_id = :cid
                             ORDER BY d.name');
$detailStmt->execute([':cid' => $courseId]);
$detailsRows = $detailStmt->fetchAll() ?: [];
$detailsByCat = [];
foreach ($detailsRows as $row) {
    $detailsByCat[$row['category_id']][] = $row;
}

// Fetch student statuses for these details
$statusStmt = $pdo->prepare('SELECT d.detail_id,
                                    COALESCE(ps.name, \'None\') AS status_name
                             FROM progress_details d
                             JOIN progress_category c ON c.category_id = d.category_id
                             LEFT JOIN progress p ON p.detail_id = d.detail_id AND p.user_id = :uid
                             LEFT JOIN progress_status ps ON ps.progress_status_id = p.status_id
                             WHERE c.course_id = :cid');
$statusStmt->execute([':uid' => $studentId, ':cid' => $courseId]);
$statuses = [];
foreach ($statusStmt->fetchAll() as $row) {
    $statuses[(int)$row['detail_id']] = $row['status_name'] ?? 'None';
}

// Fetch available statuses
$allStatuses = [];
$statRows = $pdo->query('SELECT progress_status_id, name FROM progress_status ORDER BY name')->fetchAll();
foreach ($statRows as $r) {
    $allStatuses[] = [
        'progress_status_id' => (int)$r['progress_status_id'],
        'name'               => $r['name'],
    ];
}

// Fetch comments for this student/course
$comments = [];
if (table_exists($pdo, 'ta_comments')) {
    $commentSql = 'SELECT c.text, c.created_at, c.ta_user_id, tu.name AS ta_name
                   FROM ta_comments c
                   JOIN users tu ON tu.user_id = c.ta_user_id
                   WHERE c.user_id = :uid AND c.course_id = :cid
                   ORDER BY c.created_at DESC';
    $commentStmt = $pdo->prepare($commentSql);
    $commentStmt->execute([':uid' => $studentId, ':cid' => $courseId]);
    foreach ($commentStmt->fetchAll() as $row) {
        $comments[] = [
            'text'       => $row['text'] ?? '',
            'created_at' => $row['created_at'] ?? null,
            'ta_user_id' => (int)($row['ta_user_id'] ?? 0),
            'ta_name'    => $row['ta_name'] ?? '',
        ];
    }
}

json_out([
    'categories'        => $categories,
    'detailsByCategory' => $detailsByCat,
    'userStatuses'      => (object)$statuses,
    'statuses'          => $allStatuses,
    'comments'          => $comments,
]);
