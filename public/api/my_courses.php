<?php
declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';
$user = require_login();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$pdo = db();

$links = [
  ['table' => 'student_courses', 'user_col' => 'user_id', 'course_col' => 'course_id'],
  ['table' => 'user_courses',    'user_col' => 'user_id', 'course_col' => 'course_id'],
  ['table' => 'enrollments',     'user_col' => 'user_id', 'course_col' => 'course_id'],
];

$coursesById = [];

foreach ($links as $lk) {
  try {
    // Check table exists
    $chk = $pdo->prepare(
      "SELECT 1
       FROM information_schema.TABLES
       WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = :t
       LIMIT 1"
    );
    $chk->execute([':t' => $lk['table']]);
    if (!$chk->fetch()) {
      continue;
    }

    // Fetch courses from this table
    $sql = "SELECT c.course_id, c.name
            FROM courses c
            JOIN `{$lk['table']}` l
              ON l.`{$lk['course_col']}` = c.course_id
            WHERE l.`{$lk['user_col']}` = :uid";

    $st = $pdo->prepare($sql);
    $st->execute([':uid' => $user['user_id']]);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $cid = (int)$row['course_id'];
      if ($cid > 0) {
        $coursesById[$cid] = [
          'course_id' => $cid,
          'name'      => (string)$row['name'],
        ];
      }
    }
  } catch (Throwable $e) {
    // ignore and continue to next mapping
  }
}

// Sort alphabetically by course name
$courses = array_values($coursesById);
usort($courses, static fn($a, $b) => strcmp($a['name'], $b['name']));

json_out($courses);
