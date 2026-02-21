<?php
/**
 * GET /api/lms/submission.php?id=<submission_id>
 * Compatibility proxy → grading/submission.php
 *
 * grading.js sends ?id=<submission_id>; the nested handler expects ?submission_id=.
 * Map the parameter, then include.
 */
declare(strict_types=1);

// grading.js sends ?id=..., nested handler expects ?submission_id=...
if (isset($_GET['id']) && !isset($_GET['submission_id'])) {
    $_GET['submission_id'] = $_GET['id'];
}

require_once __DIR__ . '/grading/submission.php';
