# AGENTS.md — Kairos Engineering Playbook

> **Scope:** Entire `kairos` repository.
>  
> **Purpose:** This document defines how human and AI contributors must design, implement, review, and evolve Kairos as a production academic platform for Nixor College.

---

## 1) Product and Operating Context

Kairos is internal infrastructure for Nixor College and must be treated as **production software for academic operations**, not a prototype.

Current domains:
- Courses
- Rooms
- TA queues
- Signoff sessions
- Progress tracking

Expanding domains:
- LMS content (sections, lessons, resources)
- File resources (PDFs/slides/docs/videos through Google Drive)
- Quizzes (auto-graded and manually graded)
- Assignments, submissions, rubrics
- Manual and automatic grading
- Realtime updates
- Domain-restricted access (`nixorcollege.edu.pk`)

**Primary non-functional priorities (in order):**
1. Security and access correctness
2. Data integrity and auditability
3. Operational reliability
4. Maintainability and clear ownership
5. Performance for expected load

---

## 2) Core Architecture Principles

1. **Single source of truth for business state is MySQL/MariaDB.**
   - Do not treat WebSocket memory or Google Drive metadata as canonical state.

2. **REST API is authoritative for state transitions.**
   - All create/update/delete operations happen through PHP API services.
   - WebSocket publishes events after committed DB changes.

3. **Realtime is additive, not authoritative.**
   - Realtime messages are hints for clients to refresh or patch UI state.
   - Client must tolerate dropped/duplicated/out-of-order events.

4. **Storage abstraction boundary is explicit.**
   - Google Drive integration is behind a dedicated module boundary.
   - API/controllers must never call Google APIs directly.

5. **RBAC is database-driven and enforced server-side everywhere.**
   - Never rely on frontend role checks for security.

6. **Backward compatibility is intentional.**
   - API contract changes require versioning/migration planning.

7. **Manual SQL migrations are first-class artifacts.**
   - Schema changes are reviewed, repeatable, and reversible when practical.

---

## 3) Separation of Responsibilities

### 3.1 PHP API (custom, no framework)

**Owns:**
- Authentication session/token processing
- Authorization (RBAC checks)
- All business rules and validation
- Transaction boundaries
- CRUD and domain workflows (courses, quizzes, assignments, grading, etc.)
- Emission of domain events to realtime layer

**Must not own:**
- Long-lived socket management
- Client subscription fanout logic
- Persistent file content storage

### 3.2 Python WebSocket Server

**Owns:**
- Connection lifecycle (connect/disconnect/heartbeat)
- Authenticated channel/session binding
- Subscription management (who receives which events)
- Broadcasting events produced by API/domain changes
- Lightweight per-connection state only

**Must not own:**
- Authoritative business validation
- Durable storage of grades/content/workflow state
- Permissions decisions without validating trusted claims/source

### 3.3 Google Drive Integration Layer

**Owns:**
- Upload/download/link lifecycle with Drive
- Mapping between Kairos entities and Drive file IDs
- Permission syncing strategy
- Safe handling of service account/user delegated credentials

**Must not own:**
- Academic authorization policy itself (this comes from Kairos RBAC + enrollment)

### 3.4 Database Layer (MySQL/MariaDB)

**Owns:**
- Canonical relational state
- Constraints, indexes, referential integrity
- Audit-critical records (submissions, grades, overrides, role assignments)

**Must not own:**
- External API orchestration logic
- Presentation-level derived formatting

---

## 4) Folder Structure Expectations

Use clear boundary-based organization. Prefer this high-level layout:

- `api/`
  - `public/` (entrypoints)
  - `controllers/` (HTTP handling only)
  - `services/` (business logic)
  - `repositories/` (DB access)
  - `policies/` (RBAC/authorization checks)
  - `integrations/drive/` (Drive client + adapters)
  - `integrations/oauth/` (Google auth verification)
  - `events/` (event contracts + emitters)
  - `config/`
- `realtime/`
  - `server/`
  - `auth/`
  - `subscriptions/`
  - `events/`
- `db/`
  - `migrations/`
  - `seeds/` (non-production only unless explicitly approved)
- `docs/`
  - `architecture/`
  - `runbooks/`
  - `api/`

Rules:
- Keep controllers thin; put logic in services.
- Keep SQL out of controllers; use repositories/query modules.
- Shared contracts (event names/payload schemas) must live in one canonical location.

---

## 5) Coding Conventions

### General
- Favor explicit, readable code over clever abstractions.
- Keep functions focused and side effects obvious.
- No silent fallbacks for security-sensitive behavior.
- All external calls (DB, Drive, OAuth, socket broker) must include explicit error paths.

### PHP
- Use strict input validation at boundaries.
- Controllers: parse request, call service, return response.
- Services: enforce invariants, authorization gates, transaction handling.
- Repositories: data access only, no domain branching.
- Do not mix SQL string assembly with untrusted input (always parameterize).

### Python (WebSocket)
- Treat each incoming message as untrusted input.
- Keep handlers non-blocking and predictable.
- Separate transport concerns from authorization and fanout logic.

### SQL
- Use explicit column lists (never rely on `SELECT *` in application-critical queries).
- Use transactions for multi-step write operations.
- Add indexes for all frequent lookup paths and foreign keys.

---

## 6) Security Requirements (Mandatory)

### 6.1 OAuth Token Validation

For Google OAuth-authenticated flows:
- Validate token signature and integrity using trusted Google mechanisms.
- Validate issuer (`iss`), audience (`aud`), expiration (`exp`), and issued-at sanity.
- Enforce hosted domain restriction to `nixorcollege.edu.pk`.
- Reject tokens missing verified email/domain claims where required.
- Never trust client-provided role or permission claims directly.

### 6.2 Role and Permission Checks (RBAC)

- Every mutating endpoint must enforce RBAC.
- Every read endpoint that returns non-public academic data must enforce RBAC.
- Permission checks must be centralized in policy/authorization modules.
- “Owner can always edit” shortcuts are forbidden unless explicitly codified and tested.
- Sensitive actions (grade override, rubric modification post-release, role assignment) require explicit high-privilege roles.

### 6.3 File Permission Enforcement

- Access to Drive-backed files must require both:
  1. Kairos-level authorization (course/enrollment/role policy), and
  2. Correct Drive sharing/permission state.
- Never return raw Drive links that bypass intended authorization controls.
- Use least privilege for service accounts and API scopes.
- On enrollment/role changes, run permission reconciliation jobs/events.
- Log all permission grants/revocations for audit.

### 6.4 General Security Hygiene

- Use parameterized queries only.
- Protect against IDOR by scoping every entity access to authorized context.
- Avoid leaking internal errors, stack traces, SQL details to clients.
- Secrets must come from environment/secret manager, never hardcoded.

---

## 7) Database Migration Standards

All schema changes must be applied through manual SQL migration scripts.

Required rules:
1. File naming convention:
   - `YYYYMMDD_HHMM_<short_description>.sql`
   - Example: `20261102_1430_add_assignment_rubric_tables.sql`
2. One migration = one atomic change set with clear intent.
3. Include:
   - Forward migration SQL
   - Rollback SQL section when feasible
   - Data backfill steps (idempotent)
4. Must be safe for production:
   - Avoid destructive operations without migration plan and backup note.
   - Prefer additive changes first, then code deploy, then cleanup migration.
5. Add indexes with justification for query patterns.
6. Document operational steps for high-risk migrations in `docs/runbooks/`.

---

## 8) Naming Conventions

### Database
- Tables: plural snake_case (`courses`, `assignment_submissions`)
- Columns: snake_case
- Primary keys: `id` (unless strong reason otherwise)
- Foreign keys: `<entity>_id`
- Timestamps: `created_at`, `updated_at`; use `deleted_at` for soft delete if needed

### API
- Endpoints: noun-based, predictable, versioned when needed (`/api/v1/courses/{id}/assignments`)
- JSON keys: snake_case or camelCase must be consistent per API version (do not mix within same payload)

### Realtime events
- Event names: dot-separated, domain-first, past-tense for completed actions
  - `assignment.submission.created`
  - `quiz.attempt.auto_graded`
  - `grade.override.applied`

---

## 9) Realtime Event Conventions

1. Events are emitted **after successful DB commit**.
2. Event payload must include:
   - `event_name`
   - `event_id` (unique)
   - `occurred_at` (UTC ISO8601)
   - `actor_id` (or system)
   - `entity_type`
   - `entity_id`
   - `course_id` where applicable
   - Minimal delta/context needed by subscribers
3. Payloads must avoid sensitive data leakage.
4. Consumers must be idempotent by `event_id`.
5. Version event schemas when shape changes.

---

## 10) Feature Flags and Branding Configuration

### Feature flags
- All LMS expansion modules (quizzes, assignments, rubrics, grading modes) must be flaggable.
- Flags must be server-evaluated for security-relevant behavior.
- Prefer explicit allowlists (course/program/role scoped) for staged rollout.
- Document each flag with owner, purpose, default, and retirement criteria.

### Branding/configuration
- Branding and institution-specific text/assets must come from config, not hardcoded strings.
- Domain restrictions and OAuth settings are environment-configurable with safe defaults.
- Environment-specific settings must be centralized and documented.

---

## 11) Logging, Auditing, and Error Handling

### Logging
- Use structured logs with consistent keys (`request_id`, `user_id`, `course_id`, `action`, `status`).
- Never log tokens, secrets, raw OAuth credentials, or sensitive student content.
- Log security-relevant decisions (auth failure reason category, permission denied category).

### Auditing
- Maintain durable audit records for:
  - Grade changes and overrides
  - Rubric updates post-release
  - Role assignment changes
  - Submission status transitions
  - File permission grant/revoke actions

### Error handling
- Return stable, sanitized error responses to clients.
- Internal logs may include diagnostic context but no secrets.
- Distinguish user errors (4xx) from system errors (5xx).
- Realtime handlers must fail gracefully without crashing server process.

---

## 12) Performance and Capacity Assumptions

Target workload: **< 200 concurrent users** with mixed REST + realtime usage.

Guidelines:
- Optimize for reliability and simplicity first; avoid premature micro-optimizations.
- Ensure common dashboards/pages use indexed queries and bounded pagination.
- Keep WebSocket fanout efficient; avoid per-message heavy DB work when possible.
- Add caching only where measurable bottlenecks exist and invalidation rules are clear.
- Define SLO-minded expectations:
  - Typical API reads/writes should remain responsive under expected peak class usage.

---

## 13) Google Drive Permissioning Model (Required)

1. Every Drive file linked in Kairos must have a corresponding DB mapping record.
2. Access model is “Kairos policy first, Drive sharing second”.
3. Allowed patterns (choose per feature, document choice):
   - Proxy/stream via API after authz checks, or
   - Time-limited signed/access-checked links mediated by backend.
4. Forbidden patterns:
   - Public “anyone with link” for restricted academic materials.
   - Permanent broad sharing as shortcut for role complexity.
5. On role/enrollment changes, trigger reconciliation to add/remove Drive permissions promptly.
6. On course archival, apply archival permission policy and preserve audit trace.

---

## 14) Deployment and Operations Expectations

- Use environment-based configuration (`dev`, `staging`, `production`).
- Production deploys require:
  - Migration review and sequencing plan
  - Rollback plan
  - Smoke validation checklist (auth, core course flows, grading path, realtime events)
- Keep API and WebSocket versions compatible during rolling deploy windows.
- Run background reconciliation jobs (Drive permissions, stale sessions, etc.) with observability.
- Maintain operational runbooks for incidents involving auth, grading, and file access.

---

## 15) Long-Term Maintainability Rules

1. Prefer explicit module boundaries over shared utility sprawl.
2. Every new domain feature must define:
   - Data model
   - Authorization rules
   - Event emissions
   - Audit requirements
   - Migration impact
3. Avoid hidden coupling between API and realtime payload internals.
4. Deprecations must be documented with timelines and migration paths.
5. Keep documentation current with architecture changes in the same PR.
6. Tests should prioritize high-risk academic correctness paths:
   - Permission checks
   - Grading calculations
   - Submission state transitions
   - Realtime event authorization fanout

---

## 16) Contribution Checklist (for humans and agents)

Before merging changes, verify:
- [ ] Domain logic is in correct layer (controller/service/repository separation)
- [ ] RBAC checks exist for all protected actions
- [ ] OAuth/domain restrictions are enforced where applicable
- [ ] Drive file access follows Kairos + Drive permission model
- [ ] Migration scripts are safe, named correctly, and reviewed
- [ ] Realtime events follow naming/payload/idempotency conventions
- [ ] Logs are structured and sanitized
- [ ] Feature flags/config updates documented
- [ ] Docs/runbooks updated for operationally significant changes

---

## 17) Non-Negotiables

- No direct production data mutation outside reviewed migrations/scripts.
- No bypassing authorization for “internal tool” convenience.
- No shipping features without auditability for grading-related actions.
- No exposing restricted course resources via weak Drive sharing.
- No undocumented schema changes.

Kairos handles academically sensitive workflows. Correctness, traceability, and least-privilege access are mandatory.
