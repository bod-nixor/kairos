<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function role_rank(string $name): int
{
    static $map = [
        'student' => 1,
        'ta'      => 2,
        'manager' => 3,
        'admin'   => 4,
    ];

    $normalized = strtolower(trim($name));
    return $map[$normalized] ?? 0;
}

function user_role_rank(PDO $pdo, array $user): int
{
    $roleId = isset($user['role_id']) ? (int)$user['role_id'] : 0;
    if ($roleId <= 0) {
        return 0;
    }

    static $cache = [];
    if (array_key_exists($roleId, $cache)) {
        return $cache[$roleId];
    }

    $stmt = $pdo->prepare('SELECT LOWER(name) FROM roles WHERE role_id = :rid');
    $stmt->execute([':rid' => $roleId]);
    $roleName = (string)$stmt->fetchColumn();

    $cache[$roleId] = role_rank($roleName);
    return $cache[$roleId];
}

function require_role_or_higher(PDO $pdo, array $user, string $minRole): void
{
    if (user_role_rank($pdo, $user) < role_rank($minRole)) {
        json_out(['error' => 'forbidden', 'message' => 'insufficient role'], 403);
    }
}

function has_role_or_higher(PDO $pdo, array $user, string $minRole): bool
{
    return user_role_rank($pdo, $user) >= role_rank($minRole);
}
