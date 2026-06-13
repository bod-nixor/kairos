# Course Authorization Matrix

**Effective date:** June 13, 2026  
**Policy source:** `src/rbac.php`, consumed by `public/api/lms/_common.php`

## Role Definitions

| Role | Course scope | Intended authority |
|---|---|---|
| Student | Enrolled or legitimate pre-enrollment only | Published content, own attempts/submissions/files, own released grades, ordinary preferences |
| TA | Explicitly assigned courses only | Published content, assigned grading, relevant student progress/sign-off, queue/room operations |
| Manager | Explicitly assigned courses only | Full operational and editorial control for assigned courses |
| Admin | Every course | Manager-equivalent course control plus global course creation and staff assignment |

Global role rank is not a course grant. A manager or TA must also have a recognized course mapping. Admin access is explicit.

## Capability Matrix

| Capability | Student | TA | Manager | Admin |
|---|---:|---:|---:|---:|
| `view_course` | scoped | scoped | scoped | all |
| `manage_course` | no | no | scoped | all |
| `grade_course` | no | scoped | scoped | all |
| `update_student_progress` | no | scoped | scoped | all |
| `manage_course_announcements` | no | no | scoped | all |
| `create_course` | no | no | no | yes |
| `assign_course_staff` | no | no | no | yes |
| View unpublished content | no | no | scoped | all |
| View another student's submission | no | assigned grading only | scoped | all |
| View grade drafts | no | assigned grading only | scoped | all |

For assignment grading, a TA must also appear in `lms_assignment_tas`. Students receive only the latest grade whose status is `released`.

## Page Matrix

| Page/surface | Student | TA | Manager | Admin | Guard |
|---|---:|---:|---:|---:|---|
| Course, modules, lessons, resources, quizzes, assignments | scoped published | scoped published | scoped full | full | `courses.php` capability payload plus protected APIs |
| Grading | no | scoped/assigned | scoped | all | `grade_course` |
| Analytics | no | no | scoped | all | `manage_course` |
| Course settings/edit controls | no | no | scoped | all | `manage_course` |
| Announcement edit/delete controls | no | no | scoped | all | `manage_course_announcements` |
| TA queue/progress surfaces | no | scoped | scoped where supported | all | queue/room/course mappings |
| Manager surface | no | no | assigned courses | all | manager mapping or admin |
| Admin surface | no | no | no | yes | explicit admin role |
| Projector | no | yes | yes | yes | TA-or-higher operational role and scoped data APIs |

The shared course navigation is rendered from `COURSE_NAV_ITEMS` in `public/js/lms-core.js`. Every course page exposes
the same `#kNavCourse` mount and passes the server-returned course capability payload to it. Grading requires
`grade_course`; analytics requires `manage_course`. Page-local role guesses are forbidden. Hidden UI is never an
authorization boundary.

## Object Ownership Chains

| Object | Required stored relationship |
|---|---|
| Section/module | `section_id -> lms_course_sections.course_id` |
| Module item | `module_item_id -> lms_module_items.course_id/section_id` |
| Lesson | `lesson_id -> lms_lessons.course_id` |
| Resource | `resource_id -> lms_resources.course_id`; submission files also require `resource -> submission -> assignment -> course` |
| Quiz | `assessment_id -> lms_assessments.course_id` |
| Quiz attempt | `attempt_id -> assessment_id -> course_id`; student must own the attempt |
| Assignment | `assignment_id -> lms_assignments.course_id` |
| Submission | `submission_id -> assignment_id -> course_id`; student ownership or grader assignment applies |
| Announcement | `announcement_id -> lms_announcements.course_id` |
| Queue | `queue_id -> room_id -> course_id` |
| Room | `room_id -> course_id` |
| Progress detail | `detail_id -> category_id -> course_id`; target student must be enrolled in the same course |

Client-provided `course_id` is context only. It must match the relationship loaded from the stored object.

## Endpoint Matrix

| Endpoint family | Method | Capability/role | Scope and ownership | Unauthorized response |
|---|---|---|---|---|
| `lms/courses.php`, `courses/list.php`, `courses/sections.php`, discovery/list reads | GET | `view_course` or accessible-course list | DB enrollment/staff/pre-enrollment mappings | 403 |
| `lms/courses/join.php`, `courses_join.php` | POST | student | public/allowlisted course, authenticated email | 403/404/422 |
| `lms/courses/visibility.php`, `allowlist.php`, `preenroll.php`, settings endpoints | GET/POST | `manage_course` | assigned course; stored course ID | 403/404 |
| `lms/modules.php`, `lessons.php`, `lessons/get.php`, `resources/get.php`, `quizzes.php`, `quiz/get.php`, `assignments.php`, `assignments/get.php` | GET | `view_course` | object belongs to accessible course; TA/student published-only | 403/404 |
| `lms/sections/{create,update,delete,reorder}.php` | POST | `manage_course` | section and complete reorder set belong to course; stale expected order rejected | 403/404/409/422 |
| `lms/module_items/{create,update,delete,reorder}.php` | POST | `manage_course` | item and section belong to course; reorder set is exact; rows locked before write | 403/404/409/422 |
| `lms/lessons/{create,save,update,publish,delete}.php`, `lesson_blocks/*` | POST | `manage_course` | lesson/block chain resolves to assigned course | 403/404 |
| `lms/resources/{create,upload,update,delete}.php` | POST | `manage_course` | resource belongs to assigned course | 403/404/422 |
| `lms/resources/download.php` | GET | `view_course` or submission access | local `resource_id`; never accepts arbitrary Drive ID | 403/404 |
| `lms/quiz/{create,update,publish,mandatory,delete}.php`, `quiz/question/{create,update,delete,reorder}.php` | POST | `manage_course` | quiz/question resolves to assigned course | 403/404/422 |
| `lms/quiz/question/list.php` | GET | `view_course` | published-only for student/TA; answer keys only for grading roles | 403/404 |
| `lms/quiz/attempt.php`, `quiz/attempt/submit.php` | POST | student | published quiz in enrolled course; attempt owner only | 403/404/409 |
| `lms/quiz/attempts.php` | GET | owner or `grade_course` | assessment belongs to course | 403/404 |
| `lms/quiz/attempt/get.php`, `quiz/submissions.php` | GET | `grade_course` | attempt/assessment belongs to assigned course | 403/404 |
| `lms/assignments/{create,update,publish,mandatory,delete}.php` | POST | `manage_course` | assignment resolves to assigned course | 403/404/422 |
| `lms/assignments/upload-policy.php` | GET | `view_course` | policy is non-sensitive; caller must still belong to the course | 403/422 |
| `lms/assignments/submit.php` | POST | student | published assignment, enrolled course, self only | 403/404/409 |
| `lms/assignments/submissions.php` | GET | owner or assigned grader | assignment stored course; students receive own released grade only | 403/404 |
| `lms/assignments/tas/set.php` | POST | `manage_course` | assignment belongs to assigned course; assignment-level grader assignment only | 403/404 |
| `lms/grading/queue.php`, `grading/submission.php` | GET | `grade_course` | submission -> assignment -> course; TA assignment required | 403/404 |
| `lms/grading/submission/{grade,release}.php`, `grade_submission.php`, `grade_release_all.php` | POST | `grade_course` | stored submission chain; TA assignment; audit required | 403/404/422 |
| `lms/analytics_*.php`, `analytics/course/get.php` | GET | `manage_course` | assigned course; assignment filters must match course | 403/404 |
| `lms/announcements.php` | GET | `view_course` | student/TA published-only; manager/admin include drafts | 403 |
| `lms/announcements/detail.php` | GET | `view_course` | stored announcement and requested course must match; student/TA published-only; audit summaries manager/admin only | 403/404 |
| `lms/announcements/{create,update,delete}.php` | POST | `manage_course_announcements` | stored announcement course; soft delete and audit | 403/404/422 |
| `lms/announcements_read.php`, `notifications_seen*.php` | GET/POST | `view_course` | visible event/announcement belongs to course and current user | 403/422 |
| `ta/student_progress.php`, `ta/comment.php`, `ta/update_progress.php` | GET/POST | `update_student_progress` | TA course assignment plus target student enrollment | 403/404 |
| `ta/courses.php`, `ta/rooms.php`, `ta/queues.php`, `ta/current.php`, `ta/{accept,call_again,stop}.php` | GET/POST | TA operational access | assigned course; queue -> room -> course | 403/404 |
| `manager/courses.php`, `manager/course_access.php`, `manager/{rooms,queues,progress,users_search,enroll,unenroll}.php` | GET/POST | manager or admin | manager assigned course; admins all courses | 403/404 |
| `admin/courses.php`, `admin/assign.php`, `admin/users_search.php`, diagnostics | GET/POST | admin | global; staff assignment remains admin-only | 403/404 |
| `queue_participants.php`, `queue_eta.php`, queue/room operational endpoints | GET/POST | queue capability | queue -> room -> course plus role/ownership rule | 403/404 |
| `session_capabilities.php`, `me.php`, user settings | GET/POST | authenticated | current user only; ordinary theme/preferences | 401/403 |
| `/websocket/socket.io/` connection | CONNECT | authenticated course access | requested room resolves to course; course mapping checked in DB | connection rejected |

Helpers such as `_common.php`, `_helpers.php`, `_access.php`, and compatibility proxies are not standalone authorization surfaces; their canonical target is covered above.

## Stable Failure Responses

- `401`: no valid authenticated session.
- `403`: authenticated but capability/course assignment is absent.
- `404`: stored foreign object is not visible in the requested course context.
- `422`: malformed ID, invalid state, or inconsistent object set.

Responses are sanitized. SQL details, filesystem paths, OAuth values, session IDs, and Drive IDs are not returned.
Assignment upload rejection uses `422 validation_error` for unsupported/disallowed extensions, MIME/content mismatch,
and oversized files. Validation completes before Drive upload and before the submission transaction begins.

## Realtime Rules

1. Domain writes and their `lms_event_outbox` row share one DB transaction.
2. A rollback removes both; the worker can only observe committed events.
3. Course events require `course_id` and publish to `course:<id>`.
4. Socket connection authorization checks the same enrollment/staff/pre-enrollment sources.
5. Clients refresh authoritative REST state on announcement create/update/delete.

## Manual Smoke Plan

1. Student: verify published content only, own released grade only, no grading/analytics/settings/edit controls, and foreign IDs return 403/404.
2. TA: verify assigned-course published content, assigned grading/progress, no course/announcement editing, and foreign course/student IDs fail.
3. Manager: verify full assigned-course editing, announcement create/edit/unpublish/delete, and foreign course failure.
4. Admin: verify all-course management, course creation, and staff assign/remove.
5. For each role, directly open grading, analytics, lesson edit, assignment edit, and course settings URLs.
6. Verify queue/room and WebSocket subscriptions reject mismatched or foreign course context.
7. Verify protected downloads accept only local resource IDs and never reveal Drive IDs.
8. Verify assignment upload presets rehydrate, the student `accept` filter matches the stored policy, SVG is rejected,
   and a rejected file creates neither a Drive object nor a submission row.

## Migration Note

Apply `db/migrations/20260613_1430_add_announcement_publication_audit.sql` before deploying the announcement API/UI. It adds publication state, a lookup index, and `lms_announcement_audit`. The application does not mutate schema at runtime.
