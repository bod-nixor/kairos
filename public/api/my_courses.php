<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
$user = require_login();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$pdo = db();

/*
  Adjust these to match your schema if needed.
  It looks for the user’s courses in the first table that exists.
*/
$links = [
  ['table' => 'student_courses', 'user_col' => 'user_id', 'course_col' => 'course_id'],
  ['table' => 'user_courses',    'user_col' => 'user_id', 'course_col' => 'course_id'],
  ['table' => 'enrollments',     'user_col' => 'user_id', 'course_col' => 'course_id'],
];

$courses = [];
foreach ($links as $lk) {
  try {
    $chk = $pdo->prepare("SELECT 1
                          FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
                          LIMIT 1");
    $chk->execute([':t' => $lk['table']]);
    if (!$chk->fetch()) continue;

    $sql = "SELECT c.course_id, c.name
            FROM courses c
            JOIN `{$lk['table']}` l
              ON l.`{$lk['course_col']}` = c.course_id
            WHERE l.`{$lk['user_col']}` = :uid
            GROUP BY c.course_id, c.name
            ORDER BY c.name";
    $st = $pdo->prepare($sql);
    $st->execute([':uid' => $user['user_id']]);
    $rows = $st->fetchAll();
    if ($rows && count($rows)) { $courses = $rows; break; }
  } catch (Throwable $e) {
    // try next mapping
  }
}

/* ✅ Strict version: if no enrolled courses found, return empty list */
json_out($courses);