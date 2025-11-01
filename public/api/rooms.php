<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_login();

/* No-cache headers so proxies don’t serve stale JSON */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$pdo = db();

$course_id = (int)($_GET['course_id'] ?? 0);
$sql = "SELECT room_id, course_id, name FROM rooms";
$args = [];

if ($course_id > 0) { 
    $sql .= " WHERE course_id = :cid";
    $args[':cid'] = $course_id;
}

$sql .= " ORDER BY name";
$st = $pdo->prepare($sql);
$st->execute($args);
json_out($st->fetchAll());