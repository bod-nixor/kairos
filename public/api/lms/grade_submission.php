<?php
/**
 * POST /api/lms/grade_submission.php
 * Compatibility proxy → grading/submission/grade.php
 *
 * grading.js posts grade data here; the handler lives nested.
 */
declare(strict_types=1);
require_once __DIR__ . '/grading/submission/grade.php';
