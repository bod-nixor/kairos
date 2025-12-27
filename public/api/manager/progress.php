<?php
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$user = require_login();
$pdo = db();

require_role_or_higher($pdo, $user, 'manager');

$userId = isset($user['user_id']) ? (int)$user['user_id'] : 0;
if ($userId <= 0) {
    json_out(['error' => 'forbidden', 'message' => 'missing user id'], 403);
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_out(['error' => 'method_not_allowed'], 405);
}

$courseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
if ($courseId <= 0) {
    json_out(['error' => 'course_id is required'], 400);
}

if (!user_role_at_least($pdo, $user, 'admin')) {
    assert_manager_controls_course($pdo, $userId, $courseId);
}

$studentId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if ($studentId > 0) {
    $enrolledIds = course_enrollment_user_ids($pdo, $courseId);
    if (!in_array($studentId, $enrolledIds, true)) {
        json_out(['error' => 'not_enrolled', 'message' => 'student not enrolled'], 404);
    }
    json_out(fetch_student_progress($pdo, $courseId, $studentId));
}

json_out(fetch_course_progress_summary($pdo, $courseId));

function fetch_course_progress_summary(PDO $pdo, int $courseId): array
{
    $students = users_for_course($pdo, $courseId);
    if (!$students) {
        return ['students' => []];
    }

    $totalDetails = 0;
    if (table_exists($pdo, 'progress_details') && table_exists($pdo, 'progress_category')) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM progress_details d
                               JOIN progress_category c ON c.category_id = d.category_id
                               WHERE c.course_id = :cid');
        $stmt->execute([':cid' => $courseId]);
        $totalDetails = (int)$stmt->fetchColumn();
    }

    $progressByUser = [];
    if (table_exists($pdo, 'progress') && table_exists($pdo, 'progress_status') && $totalDetails > 0) {
        // Summarize completion so managers can see progress without loading per-student details.
        $sql = 'SELECT p.user_id,
                       SUM(CASE WHEN LOWER(ps.name) = \'completed\' THEN 1 ELSE 0 END) AS completed_count,
                       MAX(p.updated_at) AS last_updated
                FROM progress p
                JOIN progress_details d ON d.detail_id = p.detail_id
                JOIN progress_category c ON c.category_id = d.category_id
                LEFT JOIN progress_status ps ON ps.progress_status_id = p.status_id
                WHERE c.course_id = :cid
                GROUP BY p.user_id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':cid' => $courseId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $uid = (int)($row['user_id'] ?? 0);
            $progressByUser[$uid] = [
                'completed' => (int)($row['completed_count'] ?? 0),
                'last_updated' => $row['last_updated'] ?? null,
            ];
        }
    }

    $out = [];
    foreach ($students as $student) {
        $uid = isset($student['user_id']) ? (int)$student['user_id'] : 0;
        $progress = $progressByUser[$uid] ?? ['completed' => 0, 'last_updated' => null];
        $summary = $totalDetails > 0
            ? sprintf('%d / %d completed', $progress['completed'], $totalDetails)
            : 'No progress details configured';

        $out[] = [
            'user_id' => $uid,
            'name' => $student['name'] ?? '',
            'email' => $student['email'] ?? '',
            'progress_summary' => $summary,
            'last_updated' => $progress['last_updated'],
        ];
    }

    return ['students' => $out];
}

function fetch_student_progress(PDO $pdo, int $courseId, int $studentId): array
{
    $student = null;
    $stmt = $pdo->prepare('SELECT user_id, name, email FROM users WHERE user_id = :uid LIMIT 1');
    $stmt->execute([':uid' => $studentId]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $student = [
            'user_id' => (int)($row['user_id'] ?? 0),
            'name' => $row['name'] ?? '',
            'email' => $row['email'] ?? '',
        ];
    }

    if (
        !table_exists($pdo, 'progress_category') ||
        !table_exists($pdo, 'progress_details') ||
        !table_exists($pdo, 'progress_status')
    ) {
        return [
            'student' => $student,
            'categories' => [],
            'detailsByCategory' => [],
            'userStatuses' => (object)[],
            'statuses' => [],
            'comments' => [],
        ];
    }

    $catStmt = $pdo->prepare('SELECT CAST(category_id AS UNSIGNED) AS category_id, name
                              FROM progress_category
                              WHERE course_id = :cid
                              ORDER BY name');
    $catStmt->execute([':cid' => $courseId]);
    $categories = $catStmt->fetchAll() ?: [];

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

    $statuses = [];
    if (table_exists($pdo, 'progress')) {
        $statusStmt = $pdo->prepare('SELECT d.detail_id,
                                            COALESCE(ps.name, \'None\') AS status_name
                                     FROM progress_details d
                                     JOIN progress_category c ON c.category_id = d.category_id
                                     LEFT JOIN progress p ON p.detail_id = d.detail_id AND p.user_id = :uid
                                     LEFT JOIN progress_status ps ON ps.progress_status_id = p.status_id
                                     WHERE c.course_id = :cid');
        $statusStmt->execute([':uid' => $studentId, ':cid' => $courseId]);
        foreach ($statusStmt->fetchAll() as $row) {
            $statuses[(int)$row['detail_id']] = $row['status_name'] ?? 'None';
        }
    }

    $allStatuses = [];
    $statRows = $pdo->query('SELECT progress_status_id, name FROM progress_status ORDER BY name')->fetchAll();
    foreach ($statRows as $r) {
        $allStatuses[] = [
            'progress_status_id' => (int)$r['progress_status_id'],
            'name' => $r['name'],
        ];
    }

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
                'text' => $row['text'] ?? '',
                'created_at' => $row['created_at'] ?? null,
                'ta_user_id' => (int)($row['ta_user_id'] ?? 0),
                'ta_name' => $row['ta_name'] ?? '',
            ];
        }
    }

    return [
        'student' => $student,
        'categories' => $categories,
        'detailsByCategory' => $detailsByCat,
        'userStatuses' => (object)$statuses,
        'statuses' => $allStatuses,
        'comments' => $comments,
    ];
}
