<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/public/api/lms/_common.php';

function test_module_reorder()
{
    $pdo = db();
    $out = [];

    // Setup: Get or create a course
    $pdo->exec("INSERT IGNORE INTO lms_courses (course_id, code, name, description, instructor_id, credits) VALUES (99999, 'TEST-MODS', 'Test Modules', 'Test', 1, 3)");

    // Create test user (manager)
    $pdo->exec("INSERT IGNORE INTO lms_users (user_id, name, email, role, pw_hash) VALUES (99999, 'Test Admin', 'admin@test.com', 'manager', 'hash')");
    $pdo->exec("INSERT IGNORE INTO lms_course_enrollments (course_id, user_id, role) VALUES (99999, 99999, 'manager')");

    // Create test sections
    $pdo->exec("DELETE FROM lms_course_sections WHERE course_id = 99999");
    $pdo->exec("INSERT INTO lms_course_sections (section_id, course_id, title, position) VALUES 
        (999901, 99999, 'Mod A', 1),
        (999902, 99999, 'Mod B', 2),
        (999903, 99999, 'Mod C', 3)");

    // Mock user session
    $_SESSION['lms_user'] = [
        'user_id' => 99999,
        'role' => 'manager'
    ];

    // Test 1: Successful Reorder
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $validPayload = json_encode(['course_id' => 99999, 'section_ids' => [999903, 999901, 999902]]);
    $res = run_endpoint_test($validPayload);

    $out[] = "Test 1 (Valid): " . ($res['http_code'] === 200 ? "PASS" : "FAIL ({$res['http_code']})");

    // Verify DB states for Test 1
    $stmt = $pdo->query("SELECT section_id, position FROM lms_course_sections WHERE course_id = 99999 ORDER BY position ASC");
    $order = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $out[] = "Test 1 Order Verification: " . ($order[999903] == 1 && $order[999901] == 2 && $order[999902] == 3 ? "PASS" : "FAIL");

    // Test 2: Partial Content Reorder (Missing Sec 2)
    $partialPayload = json_encode(['course_id' => 99999, 'section_ids' => [999903, 999901]]);
    $res2 = run_endpoint_test($partialPayload);
    $out[] = "Test 2 (Partial/Missing IDs): " . (($res2['http_code'] === 422 || $res2['http_code'] === 400) ? "PASS" : "FAIL ({$res2['http_code']})");

    // Test 3: Duplicates
    $duplicatePayload = json_encode(['course_id' => 99999, 'section_ids' => [999903, 999901, 999901]]);
    $res3 = run_endpoint_test($duplicatePayload);
    $out[] = "Test 3 (Duplicates): " . (($res3['http_code'] === 422 || $res3['http_code'] === 400) ? "PASS" : "FAIL ({$res3['http_code']})");

    // Test 4: Invalid/0 IDs
    $invalidIdPayload = json_encode(['course_id' => 99999, 'section_ids' => [999903, 999901, 0]]);
    $res4 = run_endpoint_test($invalidIdPayload);
    $out[] = "Test 4 (Invalid IDs <= 0): " . (($res4['http_code'] === 422 || $res4['http_code'] === 400) ? "PASS" : "FAIL ({$res4['http_code']})");

    // Test 5: Role check (Student)
    $pdo->exec("UPDATE lms_users SET role = 'student' WHERE user_id = 99999");
    $pdo->exec("UPDATE lms_course_enrollments SET role = 'student' WHERE course_id = 99999 AND user_id = 99999");
    $_SESSION['lms_user']['role'] = 'student';
    $res5 = run_endpoint_test($validPayload);
    $out[] = "Test 5 (Student Role 403): " . ($res5['http_code'] === 403 ? "PASS" : "FAIL ({$res5['http_code']})");

    // Cleanup
    $pdo->exec("DELETE FROM lms_course_sections WHERE course_id = 99999");
    $pdo->exec("DELETE FROM lms_course_enrollments WHERE course_id = 99999 or user_id = 99999");
    $pdo->exec("DELETE FROM lms_courses WHERE course_id = 99999");
    $pdo->exec("DELETE FROM lms_users WHERE user_id = 99999");

    echo implode("\n", $out) . "\n";
}

function run_endpoint_test($payload)
{
    $script = dirname(__DIR__, 2) . '/public/api/lms/sections/reorder.php';

    // Mock the input stream
    $tmpFile = tempnam(sys_get_temp_dir(), 'reorder_test');
    file_put_contents($tmpFile, $payload);

    // We override php://input temporarily by mocking lms_json_input inside a customized environment if we can,
    // but the easiest robust way is sub-shell execution or overriding globally if we could.
    // Instead we will just use output buffering and file_get_contents wrapper hack for testing...
    // But since we can't easily mock php://input natively in a single process without runkit,
    // let's do a fast sub-process request:

    // Write a temporary proxy script
    $proxySrc = "<?php
    \$_SESSION = " . var_export($_SESSION, true) . ";
    \$_SERVER['REQUEST_METHOD'] = 'POST';
    function lms_json_input() { return json_decode('$payload', true); }
    // intercept header/http_response_code
    \$HTTP_CODE = 200;
    function http_response_code(\$code = NULL) { global \$HTTP_CODE; if (\$code !== NULL) \$HTTP_CODE = \$code; return \$HTTP_CODE; }
    ob_start();
    try {
        require '$script';
    } catch (Throwable \$e) {
        // catch die() or exceptions
    }
    ob_end_clean();
    echo json_encode(['http_code' => \$HTTP_CODE]);
    ";

    $proxyFile = tempnam(sys_get_temp_dir(), 'reorder_proxy') . '.php';
    file_put_contents($proxyFile, $proxySrc);

    $output = shell_exec("php " . escapeshellarg($proxyFile));
    unlink($proxyFile);
    unlink($tmpFile);

    $res = json_decode(trim($output), true);
    return $res ?: ['http_code' => 500];
}

test_module_reorder();
