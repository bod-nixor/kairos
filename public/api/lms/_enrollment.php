<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

class LmsEnrollmentHttpError extends RuntimeException
{
    public string $errorCode;
    public int $status;
    public ?array $details;

    public function __construct(string $errorCode, string $message, int $status, ?array $details = null)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->status = $status;
        $this->details = $details;
    }
}

function lms_enrollment_course_id(array $input): int
{
    $raw = $input['course_id'] ?? null;
    if (is_int($raw)) {
        return $raw > 0 ? $raw : 0;
    }
    if (is_float($raw)) {
        return floor($raw) === $raw && $raw > 0 ? (int)$raw : 0;
    }
    if (is_string($raw) && preg_match('/^[1-9][0-9]*$/', trim($raw)) === 1) {
        return (int)trim($raw);
    }
    return 0;
}

function lms_student_courses_columns(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, EXTRA'
        . ' FROM information_schema.COLUMNS'
        . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
    );
    $stmt->execute([':table' => 'student_courses']);
    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $name = strtolower((string)($row['COLUMN_NAME'] ?? ''));
        if ($name === '') {
            continue;
        }
        $columns[$name] = [
            'nullable' => strtoupper((string)($row['IS_NULLABLE'] ?? 'NO')) === 'YES',
            'default' => $row['COLUMN_DEFAULT'] ?? null,
            'extra' => strtolower((string)($row['EXTRA'] ?? '')),
        ];
    }
    return $cache = $columns;
}

function lms_require_student_courses_schema(PDO $pdo): array
{
    $columns = lms_student_courses_columns($pdo);
    foreach (['course_id', 'user_id'] as $required) {
        if (!isset($columns[$required])) {
            throw new RuntimeException('student_courses is missing required column ' . $required);
        }
    }
    return $columns;
}

function lms_student_enrollment_exists(PDO $pdo, int $courseId, int $userId): bool
{
    lms_require_student_courses_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT 1 FROM student_courses'
        . ' WHERE course_id = :course_id AND user_id = :user_id'
        . ' LIMIT 1'
    );
    $stmt->execute([
        ':course_id' => $courseId,
        ':user_id' => $userId,
    ]);
    return (bool)$stmt->fetchColumn();
}

function lms_insert_student_enrollment(PDO $pdo, int $courseId, int $userId, string $source): bool
{
    $columns = lms_require_student_courses_schema($pdo);
    if (lms_student_enrollment_exists($pdo, $courseId, $userId)) {
        return false;
    }

    $values = [
        'course_id' => ['sql' => ':insert_course_id', 'params' => [':insert_course_id' => $courseId]],
        'user_id' => ['sql' => ':insert_user_id', 'params' => [':insert_user_id' => $userId]],
    ];
    $optional = [
        'role' => 'student',
        'course_role' => 'student',
        'status' => 'active',
        'is_active' => 1,
        'source' => $source,
        'enrollment_source' => $source,
        'created_by' => $userId,
        'created_at' => ['sql' => 'CURRENT_TIMESTAMP'],
        'updated_at' => ['sql' => 'CURRENT_TIMESTAMP'],
        'enrolled_at' => ['sql' => 'CURRENT_TIMESTAMP'],
    ];
    foreach ($optional as $column => $value) {
        if (!isset($columns[$column])) {
            continue;
        }
        if (is_array($value)) {
            $values[$column] = $value;
            continue;
        }
        $param = ':insert_' . $column;
        $values[$column] = ['sql' => $param, 'params' => [$param => $value]];
    }

    $unsupported = [];
    foreach ($columns as $column => $meta) {
        $provided = isset($values[$column]);
        $generated = str_contains((string)$meta['extra'], 'auto_increment')
            || str_contains((string)$meta['extra'], 'generated');
        if (!$provided && !$meta['nullable'] && $meta['default'] === null && !$generated) {
            $unsupported[] = $column;
        }
    }
    if ($unsupported) {
        throw new RuntimeException('student_courses has unsupported required columns: ' . implode(',', $unsupported));
    }

    $insertColumns = [];
    $selectValues = [];
    $params = [
        ':exists_course_id' => $courseId,
        ':exists_user_id' => $userId,
    ];
    foreach ($values as $column => $value) {
        $insertColumns[] = rbac_quote_identifier($column);
        $selectValues[] = $value['sql'];
        foreach (($value['params'] ?? []) as $param => $paramValue) {
            $params[$param] = $paramValue;
        }
    }

    $userColumn = rbac_quote_identifier('user_id');
    $updates = [$userColumn . ' = ' . $userColumn];
    if (isset($columns['updated_at'])) {
        $updates[] = rbac_quote_identifier('updated_at') . ' = CURRENT_TIMESTAMP';
    }

    $sql = 'INSERT INTO student_courses (' . implode(', ', $insertColumns) . ')'
        . ' SELECT ' . implode(', ', $selectValues)
        . ' FROM DUAL WHERE NOT EXISTS ('
        . 'SELECT 1 FROM student_courses WHERE course_id = :exists_course_id AND user_id = :exists_user_id'
        . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    if (!lms_student_enrollment_exists($pdo, $courseId, $userId)) {
        throw new RuntimeException('student_courses insert did not create an enrollment row');
    }
    return true;
}

function lms_claim_course_pre_enrollment(PDO $pdo, int $courseId, int $userId, string $email): void
{
    if ($email === '' || !rbac_table_has_columns($pdo, 'course_pre_enroll', ['course_id', 'email', 'claimed_user_id'])) {
        return;
    }
    $claimStmt = $pdo->prepare(
        'UPDATE course_pre_enroll'
        . ' SET claimed_user_id = :user_id'
        . ' WHERE course_id = :course_id AND LOWER(email) = :email'
        . '   AND (claimed_user_id IS NULL OR claimed_user_id = :user_id)'
    );
    $claimStmt->execute([
        ':user_id' => $userId,
        ':course_id' => $courseId,
        ':email' => $email,
    ]);
}

function lms_emit_course_enrollment_event(PDO $pdo, int $courseId, int $userId): void
{
    lms_emit_event($pdo, 'course.enrollment.updated', [
        'event_name' => 'course.enrollment.updated',
        'event_id' => lms_uuid_v4(),
        'occurred_at' => gmdate('Y-m-d H:i:s'),
        'actor_id' => $userId,
        'entity_type' => 'course_enrollment',
        'entity_id' => $userId,
        'course_id' => $courseId,
        'delta' => ['enrolled' => true],
    ]);
}

function lms_self_enroll_user_in_course(PDO $pdo, array $user, int $courseId, string $source = 'self_enrollment'): array
{
    $userId = (int)($user['user_id'] ?? 0);
    if ($userId <= 0) {
        throw new LmsEnrollmentHttpError('unauthenticated', 'You need to sign in before enrolling.', 401);
    }

    $context = rbac_course_access_context($pdo, $user, $courseId);
    if (!$context['course_exists'] || !$context['course_active']) {
        throw new LmsEnrollmentHttpError('not_found', 'Course not found.', 404);
    }
    if ($context['participate_as_student']) {
        return ['joined' => false, 'already_enrolled' => true, 'course_id' => $courseId];
    }
    if (!$context['can_self_enroll']) {
        throw new LmsEnrollmentHttpError('not_enrollable', 'You are not eligible to join this course.', 403);
    }
    if (lms_student_enrollment_exists($pdo, $courseId, $userId)) {
        return ['joined' => false, 'already_enrolled' => true, 'course_id' => $courseId];
    }

    $email = strtolower(trim((string)($user['email'] ?? '')));
    $started = !$pdo->inTransaction();
    if ($started) {
        $pdo->beginTransaction();
    }
    try {
        $joined = lms_insert_student_enrollment($pdo, $courseId, $userId, $source);
        lms_claim_course_pre_enrollment($pdo, $courseId, $userId, $email);
        if ($joined) {
            lms_emit_course_enrollment_event($pdo, $courseId, $userId);
        }
        if ($started) {
            $pdo->commit();
        }
    } catch (Throwable $error) {
        if ($started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    rbac_student_course_ids($pdo, $userId, true);

    return [
        'joined' => $joined,
        'already_enrolled' => !$joined,
        'course_id' => $courseId,
    ];
}

function lms_log_enrollment_failure(Throwable $error, array $context): void
{
    $payload = [
        'event' => 'course_self_enrollment_failed',
        'exception' => get_class($error),
        'message' => $error->getMessage(),
        'course_id' => $context['course_id'] ?? null,
        'user_id' => $context['user_id'] ?? null,
        'source' => $context['source'] ?? null,
    ];
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    error_log('[kairos] ' . ($encoded ?: 'course_self_enrollment_failed'));
}
