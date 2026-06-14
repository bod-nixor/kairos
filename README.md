# Kairos

Kairos is Nixor College’s production academic operations platform, combining queue workflows with a growing LMS experience under `/signoff/`.

- **Live URL (canonical):** https://kairos.nixorcorporate.com/signoff/
- **Primary stack:** vanilla PHP API + vanilla JS/CSS frontend + Python WebSocket relay + MariaDB/MySQL
- **Identity model:** Google OAuth with hosted-domain restriction plus optional admin-invited local accounts

---

## What is Kairos?

Kairos started as a queue + room management portal and has expanded into a Canvas-like LMS while preserving role-aware operational workflows.

Current/active domains include:
- Course discovery and course home experience
- Rooms, TA queues, and signoff sessions
- Modules and lessons (including rich lesson content)
- Resource management and previews (Drive/docs/slides/video/external links)
- Quizzes, assignments, submissions, rubrics, and grading views
- Analytics and notification surfaces
- Role-gated navigation for students, TAs, managers, and admins

---

## Architecture at a glance

- **PHP API (`public/api/`)**
  - Authoritative for all state transitions.
  - Enforces authentication, RBAC, validation, and DB transactions.
- **Frontend (`public/`)**
  - Vanilla JS/CSS application shell under `/signoff/`.
  - HTML templates live outside the webroot in `templates/pages/` and are rendered by `public/html.php` with a per-response CSP nonce.
  - Consumes REST endpoints and realtime events.
- **Python WebSocket relay (`ws_server.py`)**
  - Handles connection lifecycle, subscriptions, and broadcast fanout.
  - Not authoritative for business state.
- **MariaDB/MySQL (`db/migrations/`)**
  - Canonical business state, constraints, and audit-critical records.

---

## Environment and deployment assumptions

Kairos is currently deployed in a cPanel-style environment and code should be written with those constraints in mind:

- No framework assumptions for core runtime flows. The private Drive integration uses locked Composer dependencies.
- Public entrypoints live under `public/`.
- Relative URLs can break when moving between nested pages; prefer well-defined base paths.
- Session/auth requests rely on same-origin credentials/cookie behavior.
- OAuth callback and allowed origins must match deployed URL shape (`/signoff/`).

---

## Prerequisites

- PHP 8.1+ with `pdo_mysql`, `fileinfo`, `json`, and `openssl`; `curl`, `zip`, and `mbstring` are recommended.
- Composer 2 for installing the locked Google Drive API client.
- MariaDB/MySQL 10.6+.
- Python 3.10+ (`pip install -r requirements.txt`) for websocket relay.
- Google OAuth Client ID configured for Kairos hosts.

---

## Configuration (`.env`)

Create `.env` at repo root (read by both PHP and websocket relay):

```env
APP_DEBUG=true
APP_TIMEZONE=UTC

ALLOWED_DOMAIN=nixorcollege.edu.pk
DEFAULT_ROLE_NAME=student
GOOGLE_CLIENT_ID=your-google-oauth-client-id.apps.googleusercontent.com

LOCAL_AUTH_ENABLED=false
AUTH_PRIVACY_HASH_SECRET=replace-with-at-least-32-random-characters
ARGON2_MEMORY_COST=19456
ARGON2_TIME_COST=2
ARGON2_THREADS=1
PASSWORD_MIN_LENGTH=12
PASSWORD_MAX_LENGTH=1024
MAIL_FROM_ADDRESS=kairos@example.edu
MAIL_FROM_NAME=Kairos
SUPPORT_EMAIL=support@example.edu

GOOGLE_DRIVE_ENABLED=false
GOOGLE_DRIVE_WRITES_ENABLED=false
GOOGLE_DRIVE_AUTH_MODE=service_account
GOOGLE_DRIVE_CREDENTIALS_PATH=/absolute/path/outside/webroot/service-account.json
GOOGLE_DRIVE_SHARED_DRIVE_ID=
GOOGLE_DRIVE_ROOT_FOLDER_ID=

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=kairos
DB_USERNAME=kairos_app
DB_PASSWORD=replace-with-strong-database-password
DB_CHARSET=utf8mb4
# Optional: DB_DSN=mysql:host=127.0.0.1;port=3306;dbname=kairos;charset=utf8mb4

WS_SHARED_SECRET=replace-with-random-hex
WS_SOCKET_PATH=/websocket/socket.io
WS_PUBLIC_URL=wss://your-host.example.edu

SESSION_COOKIE_NAME=kairos_session
SESSION_COOKIE_PATH=/
```

Install PHP dependencies:

```bash
composer install --no-dev --classmap-authoritative --no-interaction
```

See `docs/runbooks/google_drive_storage.md` before enabling private file storage.

### Google OAuth and local password accounts

- Google remains the primary login and the only public self-registration path.
- Password accounts are created only by administrators and begin in `pending_activation`.
- Administrators never enter, receive, or view a password. The user sets it through a single-use activation email.
- Passwords use PHP Argon2id with validated OWASP-aligned defaults and automatic rehash on successful login.
- Reset and activation tokens are hashed at rest, single-use, expiring, and delivered in URL fragments so raw tokens
  are not sent in HTTP query strings.
- Active local users can link an approved Nixor Google account from Settings and then use either login method.
- Auth mutations use session CSRF tokens, exact-origin enforcement, database rate limits, account lockouts, session
  regeneration/versioning, and durable hashed audit events.

Apply `db/migrations/20260614_2100_add_local_authentication.sql`, configure mail and auth secrets, complete staging
smoke tests, then enable `LOCAL_AUTH_ENABLED=true`. See `docs/security/local_authentication.md` and
`docs/runbooks/local_auth_operations.md`.

### LMS rich text and assignment uploads

- Assignment list cards derive plain excerpts from centrally sanitized rich text; they never print stored markup.
- Assignment detail renders the full sanitized instruction document. Scripts, event attributes, forms, active content,
  unsafe links, and iframes are removed.
- Lesson content uses the same sanitizer with an explicit provider-embed mode. Only URLs accepted by the documented
  embed policy can become iframes.
- Assignment staff choose upload presets or supported custom extensions. The resolved lowercase, deduplicated list is
  stored in `lms_assignments.allowed_file_extensions` and reflected in the student file picker.
- PHP validates the assignment restriction, server-detected MIME, container signatures where applicable, and the
  effective assignment/Drive size limit before any Drive upload or submission DB transaction.
- SVG, HTML, JavaScript, PHP, XML, and executable/script formats are not assignment-upload formats.
- An empty allowed-extension list means any Kairos-supported safe type. `max_file_mb` is stored in MiB and converted
  to bytes only for upload validation.
- Saving assignment metadata never calls Drive. With Drive writes disabled, restriction updates still succeed;
  a valid file upload returns a storage `503` only after local type/size validation passes.

### LMS removal and deletion semantics

- **Remove from module** deletes only the `lms_module_items` link. The assignment or quiz remains in its course-level
  library and can be linked again later.
- **Delete assignment/quiz** soft-deletes the parent (`deleted_at`, `archived`), removes every module link, hides it
  from active lists/detail/student/grading paths, and emits a course-scoped realtime invalidation event.
- Historical submissions, attempts, grades, audit rows, and managed files are retained for academic traceability.
  They are unavailable through active LMS/grading endpoints after the parent is deleted.
- Only a manager assigned to the course or an admin may remove/delete. Students and TAs cannot invoke these actions.

### OAuth + local dev caveat

If your Google OAuth app is locked down to production domains and `nixorcollege.edu.pk`, localhost sign-in may not fully work. In that case:
- Use staging/live for full auth verification, or
- Temporarily configure a permitted dev OAuth client/domain in a safe environment.

---

## Database + migrations

All schema changes must be applied via manual SQL migrations in `db/migrations/`.

### Migration rules

1. File name format: `YYYYMMDD_HHMM_desc.sql`
2. Use guarded MySQL/MariaDB-compatible patterns where possible (`IF NOT EXISTS`, `IF EXISTS`).
3. Include forward migration SQL.
4. Include rollback SQL section when feasible.
5. Include idempotent backfill steps when required.
6. Add indexes for new lookup paths and foreign keys.

### Applying migrations

Use your MariaDB/MySQL client to apply scripts in order:

```bash
mariadb -u <user> -p < db/migrations/<migration_file>.sql
```

For first-time setup, run baseline schema/bootstrap script(s) first, then remaining migrations.

The assignment upload settings, student private notes, and grading override contracts require:

```text
db/migrations/20260614_1327_ensure_assignment_upload_settings.sql
db/migrations/20260614_1600_create_lms_assignment_notes.sql
db/migrations/20260614_1605_add_staff_private_note_to_lms_grades.sql
db/migrations/20260614_2100_add_local_authentication.sql
```

The migrations are guarded and idempotent. During staging deployment, the student notes endpoints (get_note.php, save_note.php, delete_note.php) and grading/submission endpoints (submission.php, grade.php) degrade gracefully by falling back to empty states or default values if the new lms_assignment_notes table or grade override columns are missing; executing the migrations is required for full feature functionality.

---

## Running locally

### 1) Start PHP app (web root = `public/`)

```bash
php -S 0.0.0.0:8000 -t public
```

Visit:
- App home: `http://localhost:8000/signoff/`

### 2) Start WebSocket relay

```bash
pip install -r requirements.txt
WS_SHARED_SECRET=replace-with-random-hex python ws_server.py
```

### 3) Verify DB + OAuth

- Confirm database connectivity.
- Confirm OAuth client and allowed domain config.
- Confirm browser cookie/session behavior with same-origin requests.

---

## Python WS relay expectations

`ws_server.py` expects environment configuration that mirrors API auth assumptions:
- Shared secret/token validation config (`WS_SHARED_SECRET`)
- Socket path/public URL alignment with frontend config
- Events emitted by API after successful DB transactions

Realtime consumers should treat events as advisory (idempotent and potentially out of order).

Course authorization is capability-based and course-scoped. The authoritative role, page, endpoint, and object-chain policy is documented in `docs/security/course_authorization_matrix.md`.

### Public courses and dual course context

- Any authenticated active Kairos user may preview an active public course, regardless of their sitewide role.
- Public preview is metadata-only. Modules, quizzes, assignments, announcements, rooms, queues, attempts, and submissions still require student enrollment or an explicit staff/admin grant.
- Self-enrollment writes only `student_courses`; it never creates TA, manager, or admin authority.
- A TA or manager may therefore be assigned staff in Course A and participate as a student in Course B.
- Course capability responses distinguish `view_course_public`, `view_course`, `participate_as_student`, `grade_course`, `manage_course`, and `admin_course`.
- Realtime subscriptions continue to require enrolled or assigned course access. Public preview alone never grants a course event room.

Course navigation uses ordinary shareable URLs. Supported browsers may speculatively prefetch same-origin course pages, while all browsers retain normal navigation as the fallback. A full partial-navigation router is intentionally deferred until every page controller has explicit mount/unmount lifecycle hooks; see `docs/architecture/navigation_performance.md`.

---

## Developer workflows

### Add a new LMS page safely

1. Define page scope and RBAC access matrix (student/TA/manager/admin).
2. Reuse existing UI shell/nav components to preserve theme + layout consistency.
3. Identify required API reads/writes and expected response contracts.
4. Ensure backend endpoints enforce RBAC server-side.
5. Emit/consume realtime events only after DB commit where applicable.
6. Validate dark/light mode and responsive behavior.
7. Provide manual QA checklist in PR/hand-off.

### Add a new API endpoint safely

1. Search for existing endpoints/contracts before creating a new one.
2. If frontend/backend path shapes differ (flat vs nested), either:
   - update frontend to canonical backend endpoint, or
   - add a documented compatibility/proxy endpoint.
3. Keep response shape consistent for the API area (including `lms_ok` wrappers where used).
4. Return sanitized, stable errors (`4xx` for user issues, `5xx` for system faults).
5. Enforce RBAC and entity context scoping (IDOR-safe).
6. Add/update migrations if schema changes are needed.
7. Use named course capabilities from `src/rbac.php`; do not add endpoint-local role shortcuts.

### Test locally vs staging/live

- **Local:** layout/UI behavior, non-OAuth flows, endpoint wiring, DB migrations, websocket connectivity.
- **Staging/live:** full OAuth domain restrictions, production-like role/capability behavior, integration with Drive/file previews.

---

## Troubleshooting (recurring issues)

- Theme toggle mismatches on resize.
- Modal open but non-clickable (z-index / pointer-events).
- Resource iframe preview failures; ensure fallback strategy:
  - Drive `/preview` where applicable
  - YouTube watch URL to embed conversion
  - Office viewer fallback for office docs.
- Endpoint path mismatch or JSON shape mismatch between UI and API.
- Role/capability caching leading to stale nav or permissions.
- Course context nav disappearing on some pages.
- Assignment/quiz management dialogs use `public/js/lms-management-ui.js`; keep create and edit flows on the shared
  component so preset rehydration, loading states, validation, and mobile behavior do not drift.
- Notifications “mark seen” not persisting server-side.
- Grade UI regressions due to CSS grid/stacking updates.

---

## Contributing

Humans and AI agents follow the same baseline process:

1. Read `AGENTS.md` and this `README.md` before implementing changes.
2. Check for existing APIs/components before adding new ones.
3. Enforce RBAC server-side on every protected action/read.
4. Use SQL migrations for schema changes; never mutate schema silently in runtime code.
5. Keep commits small and logical with clear commit messages.
6. Include a short manual QA checklist in your hand-off/PR.
7. Preserve visual quality and dark/light mode consistency.
8. Document endpoint compatibility choices and resource-embedding decisions.
9. Respect cPanel deployment constraints.

---

## Additional references

- Engineering guardrails and architecture policy: `AGENTS.md`
- Deployment/migration notes: `CHANGES_KAIROS.md` and `docs/runbooks/` (add when introducing operationally significant changes)

---

## Shared Identity Shell Architecture

Kairos enforces a unified, authoritative shared identity renderer (`window.KairosIdentity`) defined in `public/js/theme.js` and loaded on every page.

### Features
1. **Single Source of Truth**: Resolves the user session and capabilities once from `./api/me.php` and `./api/session_capabilities.php` and caches them in memory.
2. **Skeleton Shimmer Loading**: On boot, displays a polished skeleton animation inside the avatar and user info blocks (driven by `.k-sidebar__user.is-loading` CSS rules) to prevent layout shifts.
3. **Initials Fallback**: If the user's Google profile avatar fails to load or is missing, generates a high-contrast SVG containing their initials based on their name or email prefix.
4. **Redirection Security**: Expired sessions (`401` status) trigger a safe redirect to `/signoff/` while preserving the return URL in sessionStorage, preventing open-redirect vulnerabilities.
5. **DOM ID Normalization**: Unified across Settings, Admin, Manager, TA, Course, and Dashboard pages by mapping standard IDs (`kSidebarAvatar`, `kSidebarName`, `kSidebarRole`, `kLogoutBtn`).
