<?php
/**
 * POST /api/lms/grade_release_all.php
 * Compatibility proxy → grading/submission/release.php
 *
 * grading.js posts here for bulk release; the handler lives nested.
 */
declare(strict_types=1);
require_once __DIR__ . '/grading/submission/release.php';
