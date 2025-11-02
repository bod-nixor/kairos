<?php
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$user = isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
$isLoggedIn = $user !== null;
$rank = 0;

if ($isLoggedIn) {
    $pdo = db();
    $rank = user_role_rank($pdo, $user);
}

$roles = [
    'student' => $rank >= role_rank('student'),
    'ta'      => $rank >= role_rank('ta'),
    'manager' => $rank >= role_rank('manager'),
    'admin'   => $rank >= role_rank('admin'),
];

json_out([
    'is_logged_in' => $isLoggedIn,
    'roles'        => $roles,
]);
