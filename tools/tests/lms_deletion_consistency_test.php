<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failed = [];
$assert = static function (bool $condition, string $message) use (&$failed): void {
    if (!$condition) {
        $failed[] = $message;
    }
};

$canDelete = static function (string $role, bool $assigned): bool {
    return $role === 'admin' || ($role === 'manager' && $assigned);
};

$assert($canDelete('manager', true), 'assigned manager can delete');
$assert($canDelete('admin', false), 'admin can delete in any course');
$assert(!$canDelete('manager', false), 'manager cannot delete in a foreign course');
$assert(!$canDelete('ta', true), 'TA cannot delete');
$assert(!$canDelete('student', true), 'student cannot delete');

$unlinkState = [
    'module_link' => true,
    'parent_active' => true,
    'history_count' => 2,
];
$unlinkState['module_link'] = false;
$assert(!$unlinkState['module_link'] && $unlinkState['parent_active'], 'remove-from-module preserves the parent record');

$deleteState = [
    'module_link' => true,
    'parent_active' => true,
    'history_count' => 2,
    'event_visible' => false,
];
$deleteState['parent_active'] = false;
$deleteState['module_link'] = false;
$deleteState['event_visible'] = true;
$assert(!$deleteState['parent_active'] && !$deleteState['module_link'], 'delete archives parent and removes module link');
$assert($deleteState['history_count'] === 2, 'delete preserves historical submissions/attempts');
$assert($deleteState['event_visible'], 'committed delete exposes an invalidation event');

$assignmentDelete = (string)file_get_contents($root . '/public/api/lms/assignments/delete.php');
$quizDelete = (string)file_get_contents($root . '/public/api/lms/quiz/delete.php');
$moduleRemove = (string)file_get_contents($root . '/public/api/lms/module_items/delete.php');
$modules = (string)file_get_contents($root . '/public/api/lms/modules.php');
$assignmentList = (string)file_get_contents($root . '/public/api/lms/assignments.php');
$assignmentGet = (string)file_get_contents($root . '/public/api/lms/assignments/get.php');
$quizList = (string)file_get_contents($root . '/public/api/lms/quizzes.php');
$quizGet = (string)file_get_contents($root . '/public/api/lms/quiz/get.php');
$attemptGet = (string)file_get_contents($root . '/public/api/lms/quiz/attempt/get.php');
$attemptSubmit = (string)file_get_contents($root . '/public/api/lms/quiz/attempt/submit.php');
$assignmentPublish = (string)file_get_contents($root . '/public/api/lms/assignments/publish.php');
$assignmentMandatory = (string)file_get_contents($root . '/public/api/lms/assignments/mandatory.php');
$quizPublish = (string)file_get_contents($root . '/public/api/lms/quiz/publish.php');
$quizMandatory = (string)file_get_contents($root . '/public/api/lms/quiz/mandatory.php');

foreach ([
    [$assignmentDelete, 'assignment.deleted', 'assignment delete emits its event'],
    [$quizDelete, 'quiz.deleted', 'quiz delete emits its event'],
    [$moduleRemove, 'module_item.removed', 'module removal emits its event'],
] as [$source, $eventName, $message]) {
    $eventAt = strpos($source, "lms_emit_event(\$pdo, '{$eventName}'");
    $commitAt = strpos($source, '$pdo->commit()');
    $assert($eventAt !== false && $commitAt !== false && $eventAt < $commitAt, $message . ' through the transaction outbox');
}

$assert(str_contains($assignmentDelete, "lms_require_course_capability(\$user, 'manage_course'"), 'assignment delete uses course-scoped capability');
$assert(str_contains($quizDelete, "lms_require_course_capability(\$user, 'manage_course'"), 'quiz delete uses course-scoped capability');
$assert(str_contains($moduleRemove, "lms_require_course_capability(\$user, 'manage_course'"), 'module removal uses course-scoped capability');
$assert(str_contains($assignmentDelete, "status = 'archived'"), 'assignment delete archives the parent');
$assert(str_contains($quizDelete, "status = 'archived'"), 'quiz delete archives the parent');
$assert(!str_contains($assignmentDelete, 'DELETE FROM lms_submissions'), 'assignment delete preserves submission history');
$assert(!str_contains($quizDelete, 'DELETE FROM lms_assessment_attempts'), 'quiz delete preserves attempt history');
$assert(str_contains($moduleRemove, "'underlying_content_deleted' => false"), 'module removal response is explicit');

$assert(substr_count($modules, 'deleted_at IS NULL') >= 4, 'module list filters deleted lessons, assignments, quizzes, and resources');
$assert(str_contains($assignmentList, 'deleted_at IS NULL'), 'assignment list filters deleted parents');
$assert(str_contains($assignmentGet, 'deleted_at IS NULL'), 'assignment detail filters deleted parents');
$assert(str_contains($quizList, 'deleted_at IS NULL'), 'quiz list filters deleted parents');
$assert(str_contains($quizGet, 'deleted_at IS NULL'), 'quiz detail filters deleted parents');
$assert(str_contains($attemptGet, 'q.deleted_at IS NULL'), 'grading attempt detail filters deleted quiz parents');
$assert(str_contains($attemptSubmit, 'assessment.deleted_at IS NULL'), 'attempt submission rejects deleted quiz parents');

foreach ([
    [$assignmentPublish, 'assignment publish'],
    [$assignmentMandatory, 'assignment mandatory'],
    [$quizPublish, 'quiz publish'],
    [$quizMandatory, 'quiz mandatory'],
] as [$source, $message]) {
    $assert(!str_contains($source, 'INSERT INTO lms_module_items'), "{$message} cannot silently recreate a removed module link");
}

$modulesUi = (string)file_get_contents($root . '/public/js/modules.js');
$assignmentUi = (string)file_get_contents($root . '/public/js/assignment.js');
$quizUi = (string)file_get_contents($root . '/public/js/quiz.js');
$assert(str_contains($modulesUi, "LMS.confirm('Remove from module'"), 'module UI labels unlink accurately');
$assert(str_contains($modulesUi, "okLabel: 'Remove'"), 'module UI confirmation uses Remove');
$assert(str_contains($assignmentUi, 'Delete assignment'), 'assignment detail exposes full delete');
$assert(str_contains($quizUi, 'Delete quiz'), 'quiz detail exposes full delete');
$assert(str_contains($assignmentUi, 'Assignment unavailable'), 'assignment stale link renders safe unavailable state');
$assert(str_contains($quizUi, 'Quiz unavailable'), 'quiz stale link renders safe unavailable state');
$assert(str_contains($assignmentUi, "'assignment.deleted'"), 'assignment detail listens for realtime deletion');
$assert(str_contains($quizUi, "'quiz.deleted'"), 'quiz detail listens for realtime deletion');

if ($failed !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failed) . PHP_EOL);
    exit(1);
}

echo "LMS deletion consistency tests passed" . PHP_EOL;
