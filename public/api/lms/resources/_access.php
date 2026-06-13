<?php
declare(strict_types=1);

/**
 * @return array<string,mixed>
 */
function lms_resource_access_row(PDO $pdo, int $resourceId): array
{
    $stmt = $pdo->prepare(
        'SELECT r.resource_id, r.course_id, r.title, r.resource_type, r.drive_file_id,
                r.drive_preview_url, r.mime_type, r.file_size, r.checksum_sha256,
                r.access_scope, r.metadata_json, r.created_by,
                COALESCE(mi.published_flag, 1) AS published_flag,
                sf.submission_id, s.assignment_id, s.student_user_id
         FROM lms_resources r
         LEFT JOIN lms_module_items mi
           ON mi.item_type IN (\'file\',\'video\',\'link\',\'resource\')
          AND mi.entity_id = r.resource_id
          AND mi.course_id = r.course_id
         LEFT JOIN lms_submission_files sf ON sf.resource_id = r.resource_id
         LEFT JOIN lms_submissions s ON s.submission_id = sf.submission_id
         WHERE r.resource_id = :resource_id
           AND r.deleted_at IS NULL
         ORDER BY mi.module_item_id DESC, sf.submission_file_id DESC
         LIMIT 1'
    );
    $stmt->execute([':resource_id' => $resourceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        lms_error('not_found', 'Resource not found', 404);
    }
    return $row;
}

function lms_authorize_resource_access(PDO $pdo, array $user, array $resource): void
{
    $role = lms_user_role($user);
    $courseId = (int)$resource['course_id'];
    lms_course_access($user, $courseId);

    $scope = (string)($resource['access_scope'] ?? 'course');
    $taAssigned = false;
    if ($scope === 'assignment_submission') {
        $studentUserId = (int)($resource['student_user_id'] ?? 0);
        $assignmentId = (int)($resource['assignment_id'] ?? 0);
        if ($studentUserId <= 0 || $assignmentId <= 0) {
            lms_error('not_found', 'Submission file association is unavailable', 404);
        }
        if ($role === 'ta') {
            $stmt = $pdo->prepare(
                'SELECT 1
                 FROM lms_assignment_tas
                 WHERE assignment_id = :assignment_id
                   AND ta_user_id = :user_id
                 LIMIT 1'
            );
            $stmt->execute([
                ':assignment_id' => $assignmentId,
                ':user_id' => (int)$user['user_id'],
            ]);
            $taAssigned = (bool)$stmt->fetchColumn();
        }
    }

    $denial = lms_resource_scope_denial(
        $user,
        $resource,
        $taAssigned,
        lms_can_view_unpublished($user, $courseId)
    );
    if ($denial !== null) {
        lms_error($denial['code'], $denial['message'], $denial['status']);
    }
}

/**
 * @return array{code:string,message:string,status:int}|null
 */
function lms_resource_scope_denial(
    array $user,
    array $resource,
    bool $taAssigned = false,
    bool $canViewUnpublished = false
): ?array
{
    $role = strtolower((string)($user['role_name'] ?? 'student'));
    $scope = (string)($resource['access_scope'] ?? 'course');

    if ($scope === 'assignment_submission') {
        if ($role === 'student' && (int)$resource['student_user_id'] !== (int)$user['user_id']) {
            return ['code' => 'forbidden', 'message' => 'You cannot access another student submission', 'status' => 403];
        }
        if ($role === 'ta' && !$taAssigned) {
            return ['code' => 'forbidden', 'message' => 'TA is not assigned to this submission', 'status' => 403];
        }
        return null;
    }

    if ($scope === 'private') {
        if (
            !in_array($role, ['manager', 'admin'], true)
            && (int)$resource['created_by'] !== (int)$user['user_id']
        ) {
            return ['code' => 'forbidden', 'message' => 'Private resource access is required', 'status' => 403];
        }
        return null;
    }

    if (!$canViewUnpublished && (int)$resource['published_flag'] !== 1) {
        return ['code' => 'forbidden', 'message' => 'Resource is not published', 'status' => 403];
    }
    return null;
}
