<?php
declare(strict_types=1);

function kairos_course_access_decision(array $facts): array
{
    $authenticated = (bool)($facts['authenticated'] ?? false);
    $active = (bool)($facts['course_active'] ?? false);
    $visibility = strtolower((string)($facts['visibility'] ?? 'restricted'));
    $isAdmin = $active && $authenticated && (bool)($facts['is_admin'] ?? false);
    $isManager = $active && $authenticated && (bool)($facts['assigned_manager'] ?? false);
    $isTa = $active && $authenticated && (bool)($facts['assigned_ta'] ?? false);
    $isEnrolled = $active && $authenticated && (bool)($facts['enrolled_student'] ?? false);
    $isPublic = $active && $authenticated && $visibility === 'public';
    $isAllowlisted = $active && $authenticated && (bool)($facts['allowlisted'] ?? false);
    $isPreEnrolled = $active && $authenticated && (bool)($facts['pre_enrolled'] ?? false);
    $canSelfEnroll = $active
        && $authenticated
        && !$isEnrolled
        && ($isPublic || $isAllowlisted || $isPreEnrolled);
    $viewCourse = $active && ($isAdmin || $isManager || $isTa || $isEnrolled);
    $viewCourseHome = $active && ($viewCourse || $isPublic || $isAllowlisted || $isPreEnrolled);

    $courseRole = null;
    if ($isAdmin) {
        $courseRole = 'admin';
    } elseif ($isManager) {
        $courseRole = 'manager';
    } elseif ($isTa) {
        $courseRole = 'ta';
    } elseif ($isEnrolled) {
        $courseRole = 'student';
    } elseif ($viewCourseHome) {
        $courseRole = 'public';
    }

    return [
        'view_course_public' => $isPublic,
        'view_course_home' => $viewCourseHome,
        'view_course' => $viewCourse,
        'view_course_enrolled' => $isEnrolled,
        'participate_as_student' => $isEnrolled,
        'assigned_ta' => $isTa,
        'assigned_manager' => $isManager,
        'admin_course' => $isAdmin,
        'manage_course' => $isAdmin || $isManager,
        'grade_course' => $isAdmin || $isManager || $isTa,
        'allowlisted' => $isAllowlisted,
        'pre_enrolled' => $isPreEnrolled,
        'can_self_enroll' => $canSelfEnroll,
        'course_role' => $courseRole,
    ];
}
