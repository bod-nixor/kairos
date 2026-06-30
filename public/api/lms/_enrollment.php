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
        'SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_TYPE'
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
            'type' => (string)($row['COLUMN_TYPE'] ?? ''),
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
    return lms_student_enrollment_row($pdo, $courseId, $userId) !== null;
}

function lms_student_enrollment_row(PDO $pdo, int $courseId, int $userId): ?array
{
    $columns = lms_require_student_courses_schema($pdo);
    $selectColumns = array_map(
        static fn(string $column): string => rbac_quote_identifier($column),
        array_keys($columns)
    );
    $stmt = $pdo->prepare(
        'SELECT ' . implode(', ', $selectColumns)
        . ' FROM student_courses'
        . ' WHERE course_id = :course_id AND user_id = :user_id'
        . ' LIMIT 1'
    );
    $stmt->execute([
        ':course_id' => $courseId,
        ':user_id' => $userId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function lms_column_enum_values(array $column): array
{
    $type = (string)($column['type'] ?? '');
    if (stripos($type, 'enum(') !== 0) {
        return [];
    }
    if (preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $type, $matches) < 1) {
        return [];
    }
    return array_map(
        static fn(string $value): string => stripcslashes($value),
        $matches[1] ?? []
    );
}

function lms_student_enrollment_active_status(array $columns, ?string $columnName = null): string
{
    $statusColumn = null;
    if ($columnName !== null && isset($columns[$columnName])) {
        $statusColumn = $columns[$columnName];
    } else {
        $statusColumn = $columns['status'] ?? ($columns['enrollment_status'] ?? null);
    }
    if (!is_array($statusColumn)) {
        return 'active';
    }
    $allowed = lms_column_enum_values($statusColumn);
    if (!$allowed) {
        return 'active';
    }
    foreach (['active', 'enrolled', 'accepted', 'completed'] as $preferred) {
        foreach ($allowed as $value) {
            if (strtolower($value) === $preferred) {
                return $value;
            }
        }
    }
    return $allowed[0];
}

function lms_student_enrollment_needs_activation(array $row, array $columns): bool
{
    foreach (['status', 'enrollment_status'] as $column) {
        if (!isset($columns[$column]) || !array_key_exists($column, $row)) {
            continue;
        }
        $status = strtolower(trim((string)($row[$column] ?? '')));
        if (in_array($status, ['pending', 'invited', 'pre_enrolled', 'pre-enrolled', 'unclaimed', 'inactive'], true)) {
            return true;
        }
    }
    if (isset($columns['is_active']) && array_key_exists('is_active', $row) && (int)$row['is_active'] !== 1) {
        return true;
    }
    return false;
}

function lms_student_enrollment_duplicate_updates(array $columns): array
{
    $updates = [];
    foreach (['status', 'enrollment_status', 'is_active'] as $column) {
        if (isset($columns[$column])) {
            $quoted = rbac_quote_identifier($column);
            $updates[] = $quoted . ' = VALUES(' . $quoted . ')';
        }
    }
    foreach (['enrolled_at', 'accepted_at'] as $column) {
        if (isset($columns[$column])) {
            $quoted = rbac_quote_identifier($column);
            $updates[] = $quoted . ' = COALESCE(' . $quoted . ', VALUES(' . $quoted . '))';
        }
    }
    if (!$updates) {
        $userColumn = rbac_quote_identifier('user_id');
        $updates[] = $userColumn . ' = ' . $userColumn;
    }
    return $updates;
}

function lms_activate_student_enrollment(PDO $pdo, int $courseId, int $userId): bool
{
    $columns = lms_require_student_courses_schema($pdo);
    $row = lms_student_enrollment_row($pdo, $courseId, $userId);
    if ($row === null || !lms_student_enrollment_needs_activation($row, $columns)) {
        return false;
    }

    $sets = [];
    $params = [
        ':course_id' => $courseId,
        ':user_id' => $userId,
    ];
    foreach (['status', 'enrollment_status'] as $column) {
        if (isset($columns[$column])) {
            $param = ':activate_' . $column;
            $sets[] = rbac_quote_identifier($column) . ' = ' . $param;
            $params[$param] = lms_student_enrollment_active_status($columns, $column);
        }
    }
    if (isset($columns['is_active'])) {
        $sets[] = rbac_quote_identifier('is_active') . ' = 1';
    }
    foreach (['enrolled_at', 'accepted_at'] as $column) {
        if (isset($columns[$column])) {
            $quoted = rbac_quote_identifier($column);
            $sets[] = $quoted . ' = COALESCE(' . $quoted . ', CURRENT_TIMESTAMP)';
        }
    }
    if (isset($columns['updated_at'])) {
        $sets[] = rbac_quote_identifier('updated_at') . ' = CURRENT_TIMESTAMP';
    }
    if (!$sets) {
        return false;
    }

    $stmt = $pdo->prepare(
        'UPDATE student_courses'
        . ' SET ' . implode(', ', $sets)
        . ' WHERE course_id = :course_id AND user_id = :user_id'
        . ' LIMIT 1'
    );
    $stmt->execute($params);
    return $stmt->rowCount() > 0;
}

function lms_insert_student_enrollment(PDO $pdo, int $courseId, int $userId, string $source): bool
{
    $columns = lms_require_student_courses_schema($pdo);

    $values = [
        'course_id' => ['sql' => ':insert_course_id', 'params' => [':insert_course_id' => $courseId]],
        'user_id' => ['sql' => ':insert_user_id', 'params' => [':insert_user_id' => $userId]],
    ];
    $optional = [
        'role' => 'student',
        'course_role' => 'student',
        'status' => lms_student_enrollment_active_status($columns, 'status'),
        'enrollment_status' => lms_student_enrollment_active_status($columns, 'enrollment_status'),
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
    $placeholders = [];
    $params = [];
    foreach ($values as $column => $value) {
        $insertColumns[] = rbac_quote_identifier($column);
        $placeholders[] = $value['sql'];
        foreach (($value['params'] ?? []) as $param => $paramValue) {
            $params[$param] = $paramValue;
        }
    }

    $sql = 'INSERT INTO student_courses (' . implode(', ', $insertColumns) . ')'
        . ' VALUES (' . implode(', ', $placeholders) . ')'
        . ' ON DUPLICATE KEY UPDATE ' . implode(', ', lms_student_enrollment_duplicate_updates($columns));
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    if ($stmt->rowCount() > 0) {
        return true;
    }
    if (lms_student_enrollment_exists($pdo, $courseId, $userId)) {
        return false;
    }
    throw new RuntimeException('student_courses insert did not create an enrollment row');
}

function lms_matching_course_pre_enrollment(PDO $pdo, int $courseId, int $userId, string $email): ?array
{
    if ($email === '' || !rbac_table_has_columns($pdo, 'course_pre_enroll', ['course_id', 'email'])) {
        return null;
    }
    $hasClaimedUser = rbac_table_has_columns($pdo, 'course_pre_enroll', ['claimed_user_id']);
    $claimedSelect = $hasClaimedUser ? ', claimed_user_id' : ', NULL AS claimed_user_id';
    $claimedClause = $hasClaimedUser
        ? ' AND (claimed_user_id IS NULL OR claimed_user_id = 0 OR claimed_user_id = :user_id)'
        : '';
    $stmt = $pdo->prepare(
        'SELECT id, course_id, email' . $claimedSelect
        . ' FROM course_pre_enroll'
        . ' WHERE course_id = :course_id AND LOWER(email) = :email'
        . $claimedClause
        . ' LIMIT 1'
    );
    $params = [
        ':course_id' => $courseId,
        ':email' => $email,
    ];
    if ($hasClaimedUser) {
        $params[':user_id'] = $userId;
    }
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function lms_claim_course_pre_enrollment(PDO $pdo, int $courseId, int $userId, string $email): bool
{
    if ($email === '' || !rbac_table_has_columns($pdo, 'course_pre_enroll', ['course_id', 'email', 'claimed_user_id'])) {
        return false;
    }
    $claimStmt = $pdo->prepare(
        'UPDATE course_pre_enroll'
        . ' SET claimed_user_id = :user_id'
        . ' WHERE course_id = :course_id AND LOWER(email) = :email'
        . '   AND (claimed_user_id IS NULL OR claimed_user_id = 0 OR claimed_user_id = :user_id)'
    );
    $claimStmt->execute([
        ':user_id' => $userId,
        ':course_id' => $courseId,
        ':email' => $email,
    ]);
    if ($claimStmt->rowCount() > 0) {
        return true;
    }

    $checkStmt = $pdo->prepare(
        'SELECT 1 FROM course_pre_enroll'
        . ' WHERE course_id = :course_id AND LOWER(email) = :email'
        . '   AND claimed_user_id = :user_id'
        . ' LIMIT 1'
    );
    $checkStmt->execute([
        ':user_id' => $userId,
        ':course_id' => $courseId,
        ':email' => $email,
    ]);
    return (bool)$checkStmt->fetchColumn();
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
    $email = strtolower(trim((string)($user['email'] ?? '')));
    $preEnrollment = lms_matching_course_pre_enrollment($pdo, $courseId, $userId, $email);
    $hasExistingEnrollment = $context['participate_as_student']
        || lms_student_enrollment_exists($pdo, $courseId, $userId);
    $requiresPreEnrollmentClaim = $preEnrollment !== null
        && !$context['view_course_public']
        && !$context['allowlisted'];

    if (!$hasExistingEnrollment && !$context['can_self_enroll'] && $preEnrollment === null) {
        throw new LmsEnrollmentHttpError('not_enrollable', 'You are not eligible to join this course.', 403);
    }

    $started = !$pdo->inTransaction();
    if ($started) {
        $pdo->beginTransaction();
    }
    try {
        $claimedPreEnrollment = false;
        if ($requiresPreEnrollmentClaim) {
            $claimedPreEnrollment = lms_claim_course_pre_enrollment($pdo, $courseId, $userId, $email);
            if (!$claimedPreEnrollment) {
                throw new LmsEnrollmentHttpError('not_enrollable', 'You are not eligible to join this course.', 403);
            }
        }
        $activatedExisting = false;
        if ($hasExistingEnrollment) {
            $joined = false;
            $activatedExisting = lms_activate_student_enrollment($pdo, $courseId, $userId);
        } else {
            $joined = lms_insert_student_enrollment($pdo, $courseId, $userId, $source);
        }
        if (!$claimedPreEnrollment) {
            $claimedPreEnrollment = lms_claim_course_pre_enrollment($pdo, $courseId, $userId, $email);
        }
        if ($joined || $activatedExisting || $claimedPreEnrollment) {
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
        'joined' => $joined || $activatedExisting,
        'already_enrolled' => !$joined && !$activatedExisting,
        'pre_enrollment_matched' => $preEnrollment !== null,
        'pre_enrollment_claimed' => $claimedPreEnrollment,
        'course_id' => $courseId,
    ];
}

function lms_log_enrollment_failure(Throwable $error, array $context): void
{
    $message = $error->getMessage();
    $userId = isset($context['user_id']) ? (int)$context['user_id'] : 0;
    $secret = (string)getenv('AUTH_PRIVACY_HASH_SECRET');
    $userRef = $userId > 0 && $secret !== ''
        ? substr(hash_hmac('sha256', 'enrollment:' . $userId, $secret), 0, 20)
        : null;
    $payload = [
        'event' => 'course_self_enrollment_failed',
        'exception' => get_class($error),
        'exception_code' => $error->getCode(),
        'message_hash' => $message !== '' ? substr(hash('sha256', $message), 0, 20) : null,
        'course_id' => $context['course_id'] ?? null,
        'user_present' => $userId > 0,
        'user_ref' => $userRef,
        'source' => $context['source'] ?? null,
    ];
    if ($error instanceof PDOException) {
        $payload['sql_state'] = (string)$error->getCode();
        $payload['driver_error_code'] = $error->errorInfo[1] ?? null;
    }
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    error_log('[kairos] ' . ($encoded ?: 'course_self_enrollment_failed'));
}
