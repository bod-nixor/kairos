<?php
declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../manager/_helpers.php';

$user = require_login();
$pdo  = db();

require_role_or_higher($pdo, $user, 'admin');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_out(['error' => 'method_not_allowed'], 405);
}

$courseId = (int)($_GET['course_id'] ?? 0);
$q        = trim((string)($_GET['q'] ?? ''));

if ($courseId <= 0) {
    json_out(['error' => 'invalid_course', 'message' => 'course_id required'], 400);
}

if (!course_exists($pdo, $courseId)) {
    json_out(['error' => 'course_not_found'], 404);
}

if ($q === '') {
    json_out([]);
}

$map = resolve_enrollment_mapping($pdo);
if (!$map || empty($map['table']) || empty($map['course_col']) || empty($map['user_col'])) {
    json_out(['error' => 'unsupported', 'message' => 'enrollment mapping not available'], 500);
}

$enrollmentTable = db_quote_identifier((string)$map['table']);
$courseCol       = db_quote_identifier((string)$map['course_col']);
$userCol         = db_quote_identifier((string)$map['user_col']);

$isEmail = filter_var($q, FILTER_VALIDATE_EMAIL) !== false;
if (!$isEmail && strlen($q) < 2) {
    json_out([]);
}

$params = [':cid' => $courseId];
$limit  = 25;
$sql    = "SELECT DISTINCT CAST(u.user_id AS UNSIGNED) AS user_id, u.name, u.email
            FROM {$enrollmentTable} e
            JOIN users u ON u.user_id = e.$userCol
            WHERE e.$courseCol = CAST(:cid AS UNSIGNED)";

if ($isEmail) {
    $sql .= ' AND LOWER(u.email) = LOWER(:email)';
    $params[':email'] = $q;
} else {
    $sql .= ' AND LOWER(u.name) LIKE :term';
    $params[':term'] = '%' . strtolower($q) . '%';
}

$sql .= ' ORDER BY u.name LIMIT ' . (int)$limit;

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$out = [];
foreach ($rows as $row) {
    $out[] = [
        'user_id' => isset($row['user_id']) ? (int)$row['user_id'] : null,
        'name'    => $row['name'] ?? '',
        'email'   => $row['email'] ?? '',
    ];
}

json_out($out);

function db_quote_identifier(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}
