<?php
/**
 * GET /api/lms/grading_queue.php?assignment_id=<id>&course_id=<id>
 * Compatibility proxy → grading/queue.php
 *
 * grading.js calls this flat path; the handler lives at grading/queue.php.
 * We include it directly so auth/RBAC runs in the same request.
 */
declare(strict_types=1);
require_once __DIR__ . '/grading/queue.php';
