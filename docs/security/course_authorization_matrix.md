# Course Authorization Matrix

**Effective date:** June 14, 2026
**Policy source:** `src/course_access_policy.php` and `src/rbac.php`, consumed by `public/api/lms/_common.php`

## Role Definitions

| Role | Course scope | Intended authority |
|---|---|---|
| Student | Public preview plus enrolled courses | Published enrolled content, own attempts/submissions/files, own released grades, ordinary preferences |
| TA | Public preview, student enrollment, and explicitly assigned courses | Student participation outside assigned courses; assigned grading/progress/queue authority only where mapped |
| Manager | Public preview, student enrollment, and explicitly assigned courses | Student participation outside assigned courses; full operational/editorial control only where mapped |
| Admin | Every course | Manager-equivalent course control plus global course creation and staff assignment |

### Authorization Dimensions
Authorization in Kairos is evaluated along three distinct axes:
1. **Global Role**: Defined system-wide (via the `roles` table) as `student`, `ta`, `manager`, or `admin`. It dictates baseline application-wide controls but does not grant course-specific access.
2. **Course Staff Capability**: Granted by course-specific staff mappings (e.g. TA or manager assignments). This yields privileges like course editing (`manage_course`) or grading (`grade_course`) in the assigned courses.
3. **Course Student Participation**: Granted strictly by user enrollment in `student_courses` for a given course. This is independent of the global role. For example, a TA or manager may be enrolled as a student participant in a separate course. Staff assignment always wins when deriving display contexts for the same course. Crucially, global admins, TAs, or managers do not possess implicit student-level permissions (such as joining queues or submitting assignments) unless they are enrolled as student participants in that specific course.

Public course self-enrollment grants student-level participation access dynamically when a user attempts to submit an assignment or access enrolled areas, provided course visibility and user status satisfy access policies. Enrolled staff do not obtain any management/grading powers through student participation paths.

## Capability Matrix

| Capability | Student | TA | Manager | Admin |
|---|---:|---:|---:|---:|
| `view_course_public` | public active | public active | public active | public active |
| `view_course` | enrolled | enrolled or assigned | enrolled or assigned | all active |
| `participate_as_student` | enrolled | enrolled | enrolled | no implicit student actions |
| `manage_course` | no | no | scoped | all |
| `grade_course` | no | scoped | scoped | all |
| `update_student_progress` | no | scoped | scoped | all |
| `manage_course_announcements` | no | no | scoped | all |
| `create_course` | no | no | no | yes |
| `assign_course_staff` | no | no | no | yes |
| View unpublished content | no | no | scoped | all |
| View another student's submission | no | assigned grading only | scoped | all |
| View grade drafts | no | assigned grading only | scoped | all |

`view_course_public` grants course metadata and the enroll CTA only. It does not grant dependent LMS objects, rooms, queues, or realtime subscriptions. For assignment grading, a TA must also appear in `lms_assignment_tas`. Students receive only the latest grade whose status is `released`.

## Page Matrix

| Page/surface | Student | TA | Manager | Admin | Guard |
|---|---:|---:|---:|---:|---|
| Course home metadata | public/enrolled | public/enrolled/assigned | public/enrolled/assigned | all active | `view_course_home` |
| Modules, lessons, resources, quizzes, assignments | enrolled published | enrolled published or assigned published | enrolled published or assigned full | full | `view_course` plus object policy |
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

Module-item removal and parent deletion are separate operations. Removing a module item deletes only the link.
Deleting a quiz/assignment requires `manage_course`, soft-deletes the stored parent, removes all links, and preserves
historical submissions/attempts/grades. Active child/grading endpoints first require an undeleted parent.

## Endpoint Matrix

| Endpoint family | Method | Capability/role | Scope and ownership | Unauthorized response |
|---|---|---|---|---|
| `lms/courses.php` | GET | `view_course_home` | active public/invited course, enrollment, staff assignment, or admin | 404 for foreign private/inactive |
| `lms/courses/list.php`, discovery/list reads | GET | authenticated | unified access context per active course | hidden when foreign private |
| `lms/courses/join.php`, `courses_join.php` | POST | authenticated | active public/allowlisted/pre-enrolled course; transactional `student_courses` write only | 403/404/422 |
| `lms/courses/sections.php` and dependent LMS reads | GET | `view_course` | enrolled student, assigned staff, or admin | 403 |
| `lms/courses/visibility.php`, `allowlist.php`, `preenroll.php`, settings endpoints | GET/POST | `manage_course` | assigned course; stored course ID | 403/404 |
| `lms/modules.php`, `lessons.php`, `lessons/get.php`, `resources/get.php`, `quizzes.php`, `quiz/get.php`, `assignments.php`, `assignments/get.php` | GET | `view_course` | object belongs to accessible course; TA/student published-only | 403/404 |
| `lms/sections/{create,update,delete,reorder}.php` | POST | `manage_course` | section and complete reorder set belong to course; stale expected order rejected | 403/404/409/422 |
| `lms/module_items/{create,update,delete,reorder}.php` | POST | `manage_course` | delete means remove link only; item and section belong to course; reorder set is exact | 403/404/409/422 |
| `lms/lessons/{create,save,update,publish,delete}.php`, `lesson_blocks/*` | POST | `manage_course` | lesson/block chain resolves to assigned course | 403/404 |
| `lms/resources/{create,upload,update,delete}.php` | POST | `manage_course` | resource belongs to assigned course | 403/404/422 |
| `lms/resources/download.php` | GET | `view_course` or submission access | local `resource_id`; never accepts arbitrary Drive ID | 403/404 |
| `lms/quiz/{create,update,publish,mandatory,delete}.php`, `quiz/question/{create,update,delete,reorder}.php` | POST | `manage_course` | delete archives quiz and removes links; active question paths require undeleted parent | 403/404/409/422 |
| `lms/quiz/question/list.php` | GET | `view_course` | published-only for student/TA; answer keys only for grading roles | 403/404 |
| `lms/quiz/attempt.php`, `quiz/attempt/submit.php` | POST | student | published quiz in enrolled course; attempt owner only | 403/404/409 |
| `lms/quiz/attempts.php` | GET | owner or `grade_course` | assessment belongs to course | 403/404 |
| `lms/quiz/attempt/get.php`, `quiz/submissions.php` | GET | `grade_course` | attempt/assessment belongs to assigned course | 403/404 |
| `lms/assignments/{create,update,publish,mandatory,delete}.php` | POST | `manage_course` | delete archives assignment/removes links; upload settings persist independently of Drive | 403/404/422/503 |
| `lms/assignments/upload-policy.php` | GET | `view_course` | policy is non-sensitive; caller must still belong to the course | 403/422 |
| `lms/assignments/submit.php` | POST | authenticated | published assignment, student course participation (enrolled or eligible for public-course self-enrollment), self only | 403/404/409/422/503 |
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
4. Socket connection authorization checks current role, `student_courses`, and staff mappings. Public preview, allowlist, and unclaimed pre-enrollment do not grant a course room.
5. Clients refresh authoritative REST state on announcement, module-item, quiz, and assignment mutations.

## Manual Smoke Plan

1. Student: preview a public foreign course, enroll, then verify published content only and own released grade only.
2. TA: verify assigned-course grading/progress; preview and enroll in another public course without receiving foreign staff controls.
3. Manager: verify full assigned-course editing; preview and enroll in another public course without receiving foreign management controls.
4. Admin: verify all-course management, course creation, and staff assign/remove.
5. For each role, directly open grading, analytics, lesson edit, assignment edit, and course settings URLs.
6. Verify queue/room and WebSocket subscriptions reject mismatched or foreign course context.
7. Verify protected downloads accept only local resource IDs and never reveal Drive IDs.
8. Verify assignment upload presets rehydrate, the student `accept` filter matches the stored policy, SVG is rejected,
   and a rejected file creates neither a Drive object nor a submission row.
9. Remove an assignment/quiz from a module and confirm its library record remains; then delete the parent and confirm
   every active surface returns 404/omits it while historical rows remain stored.

## Migration Note

Apply `db/migrations/20260613_1430_add_announcement_publication_audit.sql` before deploying the announcement API/UI. It adds publication state, a lookup index, and `lms_announcement_audit`. The application does not mutate schema at runtime.

Apply `db/migrations/20260614_1327_ensure_assignment_upload_settings.sql` before deploying assignment restriction updates. Missing columns produce a sanitized `503`; the API never reports a successful partial update.

Apply `db/migrations/20260614_1600_create_lms_assignment_notes.sql` before deploying the student private assignment notes feature.

Apply `db/migrations/20260614_1605_add_staff_private_note_to_lms_grades.sql` before deploying the rubric scoring, override grade, and staff private note persistence updates in the grading system.

The June 14 public-course access pass adds no migration. It uses existing `courses.visibility`, `courses.is_active`, `course_allowlist`, `course_pre_enroll`, and `student_courses` structures.

### Navigation and Capability Rules

- **Sitewide Admin**: Admins see Admin, Manager, and TA/Grading global navigation links on all dashboards. They can access any course management/grading workflows directly or through the sidebar.
- **Course Manager-as-TA**: A Course Manager automatically inherits TA capabilities (grading, submissions, queues) for their own managed courses without requiring a separate TA mapping.
- **Login Redirect Security**: Client-side sessionStorage-based `kairos:returnUrl` redirect preserves path and query string parameters. Open-redirect prevention uses an **allowlist approach**: `validateReturnUrl` accepts only relative paths starting with `/signoff/` and rejects all other inputs—including external URLs, protocol-relative hosts, backslashes (`\`, `%5c`, `%5C`), and paths outside the `/signoff/` prefix. This is intentionally not a blocklist.
- **Student Assignment Notes**: Students have independent private notes saved via auto-saving `/api/lms/assignments/save_note.php` without creating new submission records. These notes are separate from submission comments and staff private notes.
- **Staff Grading & Rubric Overrides**: Persistent rubric scoring, grade overrides, and staff private notes are saved in the `lms_grades` table. Staff private notes are strictly hidden from students.
- **Shared Identity Shell**: Authentication status and user attributes are managed through a unified client-side identity renderer `window.KairosIdentity`. Expired sessions (`401` status) trigger a safe redirect preserving the query/hash params in sessionStorage (`kairos:returnUrl`) while applying strict open-redirect filters on redirect endpoints.
