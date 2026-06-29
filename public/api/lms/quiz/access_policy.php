<?php
declare(strict_types=1);


function lms_quiz_staff_preview_decision(array $access): array
{
    $viewCourse = (bool)($access['view_course'] ?? false);
    $canPreview = (bool)($access['manage_course'] ?? false) || (bool)($access['grade_course'] ?? false);

    if (!$viewCourse || !$canPreview) {
        return [
            'allowed' => false,
            'code' => 'forbidden',
            'message' => 'You do not have permission to preview this quiz.',
            'status' => 403,
        ];
    }

    return [
        'allowed' => true,
        'code' => null,
        'message' => null,
        'status' => 200,
    ];
}

function lms_quiz_student_attempt_decision(array $access, string $quizStatus): array
{
    $viewCourse = (bool)($access['view_course'] ?? false);
    $viewCourseHome = (bool)($access['view_course_home'] ?? false);
    $canSelfEnroll = (bool)($access['can_self_enroll'] ?? false);
    $participateAsStudent = (bool)($access['participate_as_student'] ?? false);

    if ($viewCourse && !$participateAsStudent) {
        return [
            'allowed' => false,
            'code' => 'student_participation_required',
            'message' => 'Starting a quiz attempt requires student participation. Use quiz preview or management tools for staff access.',
            'status' => 403,
        ];
    }

    if ($viewCourseHome && $canSelfEnroll) {
        return [
            'allowed' => false,
            'code' => 'forbidden',
            'message' => 'You need to enrol before starting this quiz.',
            'status' => 403,
        ];
    }

    if (!$viewCourse || !$participateAsStudent) {
        return [
            'allowed' => false,
            'code' => 'forbidden',
            'message' => 'You do not have permission to start this quiz.',
            'status' => 403,
        ];
    }

    if ($quizStatus !== 'published') {
        return [
            'allowed' => false,
            'code' => 'forbidden',
            'message' => 'Quiz is not published',
            'status' => 403,
        ];
    }

    return [
        'allowed' => true,
        'code' => null,
        'message' => null,
        'status' => 200,
    ];
}
