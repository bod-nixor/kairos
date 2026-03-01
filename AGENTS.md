# AGENTS.md — Kairos Engineering Playbook

> **Scope:** Entire `kairos` repository.
>
> **Purpose:** This document defines how human and AI contributors must design, implement, review, and evolve Kairos as production academic infrastructure for Nixor College.

---

## 1) Product and Operating Context

Kairos is production software for academic operations at Nixor College. It started as queue + room workflows and now operates as a broader LMS under `/signoff/`.

- **Canonical live deployment URL:** `https://kairos.nixorcorporate.com/signoff/`
- **Primary deployment assumptions:** cPanel-hosted PHP app, MariaDB/MySQL as source of truth, Google OAuth with hosted-domain restriction.
- **Institutional domain restriction:** `nixorcollege.edu.pk`

### Active product scope

- Courses home + course pages
- Rooms and TA queues
- Signoff sessions + progress tracking
- LMS modules and lessons (rich content editor)
- Resources (PDF, docs, slides, video, external links)
- Quizzes and quiz attempts
- Assignments, submissions, rubrics
- Grading workflows (manual + automatic)
- Analytics and notifications
- Role-gated navigation for student/TA/manager/admin

**Primary non-functional priorities (in order):**
1. Security and access correctness
2. Data integrity and auditability
3. Operational reliability
4. Maintainability and clear ownership
5. Performance for expected load

---

## 2) Core Architecture Principles

1. **MySQL/MariaDB is the single source of truth for business state.**
   - Do not treat WebSocket memory or Google Drive metadata as canonical state.

2. **REST API is authoritative for state transitions.**
   - All create/update/delete operations happen through PHP API services.
   - WebSocket publishes events after committed DB changes.

3. **Realtime is additive, not authoritative.**
   - Realtime messages are hints for clients to refresh/patch.
   - Clients must tolerate dropped/duplicated/out-of-order events.

4. **Storage abstraction boundary is explicit.**
   - Drive integration stays behind dedicated integration modules.
   - API/controllers must not call Google APIs directly.

5. **RBAC is DB-driven and server-enforced everywhere.**
   - Frontend role checks are convenience only; never security.

6. **Backward compatibility is intentional.**
   - API contract changes need versioning/migration planning.

7. **Manual SQL migrations are first-class artifacts.**
   - Schema changes must be reviewed, repeatable, and reversible when feasible.

---

## 3) Separation of Responsibilities

### 3.1 PHP API (custom, no framework)

**Owns:**
- Auth session/token processing
- Authorization (RBAC checks)
- Business rules + validation
- Transaction boundaries
- Domain workflows (courses, LMS, quizzes, assignments, grading)
- Emission of domain events to realtime

**Must not own:**
- Long-lived socket lifecycle management
- Client fanout subscription infra
- Durable file-content storage

### 3.2 Python WebSocket Server

**Owns:**
- Connection lifecycle (connect/disconnect/heartbeat)
- Authenticated session/channel binding
- Subscription management and fanout
- Broadcasting API-produced events
- Lightweight per-connection state only

**Must not own:**
- Authoritative business validation
- Durable academic state
- Permission decisions without trusted claims/source

### 3.3 Google Drive Integration Layer

**Owns:**
- Upload/download/link lifecycle with Drive
- Entity ↔ Drive ID mapping
- Permission synchronization strategy
- Service-account/delegated credential handling

**Must not own:**
- Academic authorization policy (comes from Kairos RBAC + enrollment)

### 3.4 Database Layer (MySQL/MariaDB)

**Owns:**
- Canonical relational state
- Constraints, indexes, referential integrity
- Audit-critical records (submissions, grades, overrides, role assignments)

**Must not own:**
- External API orchestration
- Presentation formatting concerns

---

## 4) Folder Structure Expectations

Preferred high-level layout:

- `api/`
  - `public/` (entrypoints)
  - `controllers/` (HTTP handling only)
  - `services/` (business logic)
  - `repositories/` (DB access)
  - `policies/` (RBAC/authorization checks)
  - `integrations/drive/`
  - `integrations/oauth/`
  - `events/`
  - `config/`
- `realtime/`
  - `server/`
  - `auth/`
  - `subscriptions/`
  - `events/`
- `db/`
  - `migrations/`
  - `seeds/` (non-production unless approved)
- `docs/`
  - `architecture/`
  - `runbooks/`
  - `api/`

Rules:
- Keep controllers thin; put business logic in services.
- Keep SQL out of controllers; use repositories/query modules.
- Keep shared event contracts in one canonical location.

---

## 5) Coding Conventions

### General
- Prefer explicit readable code over clever abstractions.
- Keep side effects obvious.
- No silent fallbacks for security-sensitive behavior.
- All external calls (DB/Drive/OAuth/socket broker) must have explicit error paths.

### PHP
- Strict input validation at boundaries.
- Controllers parse request, call service, return response.
- Services enforce invariants, auth gates, and transaction control.
- Repositories do data access only.
- Always parameterize SQL; never interpolate untrusted input.

### Python (WebSocket)
- Treat incoming messages as untrusted input.
- Keep handlers non-blocking and predictable.
- Separate transport concerns from authorization and fanout.

### SQL
- Use explicit column lists (no `SELECT *` in critical app queries).
- Use transactions for multi-step writes.
- Add indexes for frequent lookup paths and all relevant FKs.

---

## 6) Security Requirements (Mandatory)

### 6.1 OAuth Validation

For Google OAuth flows:
- Validate token signature/integrity via trusted Google mechanisms.
- Validate `iss`, `aud`, `exp`, and issued-at sanity.
- Enforce hosted domain `nixorcollege.edu.pk`.
- Reject tokens missing verified email/domain claims.
- Never trust client-provided role/permission claims.

### 6.2 RBAC Checks

- Every mutating endpoint must enforce RBAC.
- Every protected read endpoint must enforce RBAC.
- Keep checks centralized in policy/authorization modules.
- “Owner can always edit” shortcuts are forbidden unless explicitly codified + tested.
- Sensitive actions (grade override, rubric post-release edits, role assignment) require explicit high-privilege roles.

### 6.3 File Permission Enforcement

Drive-backed access requires both:
1. Kairos-level authorization, and
2. Correct Drive permission state.

Also:
- Never return raw Drive links that bypass policy.
- Use least-privilege scopes/service accounts.
- Trigger permission reconciliation on enrollment/role changes.
- Audit grants/revocations.

### 6.4 Security Hygiene

- Parameterized queries only.
- Protect against IDOR by context-scoping access.
- Do not leak stack traces/SQL internals to clients.
- Secrets only via environment/secret manager.

---

## 7) DB + Migration Standards (Mandatory)

All schema changes must be manual SQL migrations under **`db/migrations/`**.

Required rules:
1. **File naming:** `YYYYMMDD_HHMM_desc.sql`
   - Example: `20261102_1430_add_assignment_rubric_tables.sql`
2. **MySQL/MariaDB-safe guarded DDL:** use `IF NOT EXISTS` / `IF EXISTS` patterns where supported.
3. **One migration = one atomic intent** with clear scope.
4. **Forward + rollback sections** when feasible.
5. **Include backfill steps** and keep them idempotent.
6. **Document required indexes** for all new tables and frequent lookup paths.
7. **Production safety first:** additive-first strategy, then deploy code, then cleanup migration.
8. **High-risk ops notes** belong in `docs/runbooks/`.

Never silently mutate schema from runtime application code.

---

## 8) Working with AI Agents (Required Workflow)

Use this checklist on every task:

1. **Read `AGENTS.md` + `README.md` first** before edits.
2. **Find existing endpoints before adding new ones.**
   - If frontend expects flat endpoints but backend is nested, either:
     - update frontend to canonical backend paths, or
     - add documented proxy/compat endpoints.
   - Never leave path inconsistencies undocumented.
3. **Enforce RBAC on server-side for all protected data/actions.**
   - Frontend gating is UX convenience only.
4. **Create SQL migrations for schema changes.**
   - No implicit schema mutation in PHP/Python runtime.
5. **Commit every logical change set** with clear commit messages (small logical commits preferred).
6. **Provide a short manual QA checklist** with each change.
7. **Preserve UI polish and consistency** (Apple-like / Canvas-like visual quality; dark/light mode parity).
8. **Confirm and document resource embedding strategy:**
   - Google Drive `/preview` for supported docs,
   - YouTube watch URL → embed URL conversion,
   - Office viewer fallback when direct embedding fails.
9. **Respect cPanel constraints:**
   - no framework/composer assumptions,
   - entrypoints under `public/`,
   - careful relative URL handling,
   - same-origin credential/session behavior.

---

## 9) Common Platform Pitfalls

These are recurring issues; check them proactively:

- Theme toggle desync during viewport resize/reflow.
- Modal visibility blocked by z-index/pointer-events mistakes.
- Iframe preview failures without robust fallback logic.
- Endpoint path mismatches and JSON shape mismatches.
- Role detection/capability caching causing stale permissions.
- Course context navigation disappearing on some pages.
- Notifications “mark seen” not persisting server-side.
- Grade UI regressions from CSS grid/stacking changes.

---

## 10) API and Event Conventions

### API naming
- Prefer noun-based endpoints; version when needed.
- Keep payload casing consistent within an API version.

### Realtime events
1. Emit events **after successful DB commit**.
2. Include required fields:
   - `event_name`, `event_id`, `occurred_at`, `actor_id`,
   - `entity_type`, `entity_id`,
   - `course_id` when applicable,
   - minimal safe delta/context.
3. Avoid sensitive data leakage.
4. Consumers must be idempotent by `event_id`.
5. Version event schema on shape changes.

---

## 11) Feature Flags and Branding

- LMS modules (quizzes, assignments, rubrics, grading modes) must be flaggable.
- Server evaluates security-relevant flags.
- Prefer explicit allowlists for staged rollout.
- Document each flag with owner/purpose/default/retirement criteria.
- Branding/institution text/assets must be config-driven, not hardcoded.

---

## 12) Logging, Auditing, and Error Handling

### Logging
- Structured logs with keys such as `request_id`, `user_id`, `course_id`, `action`, `status`.
- Never log tokens/secrets/raw OAuth creds/sensitive student content.
- Log authz failures with reason category.

### Auditing
Durable audit records are required for:
- Grade changes/overrides
- Rubric updates post-release
- Role assignment changes
- Submission status transitions
- File permission grant/revoke actions

### Error handling
- Stable sanitized error responses to clients.
- Internal logs may carry diagnostics without secrets.
- Distinguish 4xx user errors from 5xx system errors.
- Realtime handlers must fail gracefully.

---

## 13) Deployment and Operations Expectations

- Environment-based config (`dev`, `staging`, `production`).
- Production deploys require migration sequencing, rollback plan, and smoke checklist.
- Keep API and WS versions compatible during rolling deploys.
- Run reconciliation jobs (Drive permissions, stale sessions) with observability.
- Maintain runbooks for auth, grading, file-access incidents.

---

## 14) Long-Term Maintainability Rules

1. Prefer explicit module boundaries over utility sprawl.
2. New domain features must define data model, auth rules, events, audit, and migration impact.
3. Avoid hidden coupling between API and realtime payload internals.
4. Document deprecations with timelines and migration paths.
5. Keep docs current in the same PR as architecture changes.
6. Prioritize tests for permission checks, grading correctness, submission transitions, and realtime authorization fanout.

---

## 15) Contribution Checklist

Before merge:
- [ ] Logic in correct layer (controller/service/repository)
- [ ] RBAC checks on all protected actions and reads
- [ ] OAuth + domain restrictions enforced where needed
- [ ] Drive access follows Kairos + Drive permission model
- [ ] Migration scripts are safe, named correctly, and reviewed
- [ ] Realtime events follow naming/payload/idempotency rules
- [ ] Logs are structured and sanitized
- [ ] Feature flags/config updates documented
- [ ] Docs/runbooks updated when operations are impacted
- [ ] Manual QA checklist included in PR/task handoff

---

## 16) Non-Negotiables

- No direct production data mutation outside reviewed migrations/scripts.
- No authorization bypass for internal convenience.
- No grading-related feature without auditability.
- No exposing restricted resources through weak Drive sharing.
- No undocumented schema changes.

Kairos handles academically sensitive workflows. Correctness, traceability, and least-privilege access are mandatory.
