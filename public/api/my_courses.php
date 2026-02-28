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
    $sql = "SELECT c.course_id, c.name, COALESCE(c.code, '') AS code
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
          'code'      => (string)($row['code'] ?? ''),
        ];
      }
    }
  } catch (Throwable $e) {
    // ignore and continue to next mapping
  }
}

function course_sort_key(array $course): array {
  $code = strtoupper(trim((string)($course['code'] ?? '')));
  if ($code !== '' && preg_match('/^([A-Z]+)\s*([0-9]+)([A-Z]*)$/', $code, $m)) {
    return ['group' => $m[1], 'num' => (int)$m[2], 'suffix' => $m[3], 'fallback' => strtoupper((string)$course['name'])];
  }
  return ['group' => 'ZZZ', 'num' => PHP_INT_MAX, 'suffix' => '', 'fallback' => strtoupper((string)($course['name'] ?? ''))];
}

// Sort by course code intelligently (CS101 before CS120), then name
$courses = array_values($coursesById);
usort($courses, static function ($a, $b): int {
  $ka = course_sort_key((array)$a);
  $kb = course_sort_key((array)$b);
  return [$ka['group'], $ka['num'], $ka['suffix'], $ka['fallback']] <=> [$kb['group'], $kb['num'], $kb['suffix'], $kb['fallback']];
});

json_out($courses);
