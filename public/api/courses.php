<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_login();

/* No-cache headers so you never see stale JSON */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$pdo = db();

/* Force numeric course_id (and stable ordering) */
$sql = "SELECT CAST(course_id AS UNSIGNED) AS course_id, name
        FROM courses
        ORDER BY course_id";
$stmt = $pdo->query($sql);
json_out($stmt->fetchAll());