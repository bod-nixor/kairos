# Course Navigation Performance

**Decision date:** June 14, 2026

## Chosen Strategy

Kairos keeps ordinary same-origin URLs as the navigation authority. This pass adds:

- browser-managed Speculation Rules prefetch for likely course links;
- a small navigation progress indicator for eligible same-origin course routes;
- short-lived in-memory request deduplication for session, capability, feature, and course metadata reads;
- cache invalidation after mutations and enrollment/role/staff/visibility realtime events;
- native Back, Forward, refresh, modifier-click, external-link, download, and error behavior.

The navigation helper never calls `preventDefault()`. If speculation rules are unsupported or prefetch is declined, the browser performs the same full navigation it did before.

## Why Partial DOM Navigation Is Deferred

Course pages currently load separate non-module controllers that attach document-level listeners during `DOMContentLoaded`. Several pages also own dialogs and page-specific realtime handlers outside `.k-main`. Replacing only the main DOM today would risk:

- duplicate listeners and WebSocket handlers;
- stale role/capability state after a role change;
- dialogs from the previous page surviving navigation;
- scripts executing more than once without a cleanup contract;
- CSP-safe dynamic script loading becoming route-specific and fragile.

A production partial router should start only after page controllers expose explicit `mount(context)` and `unmount()` lifecycles and shared shell services own session, notifications, theme, and realtime connections.

## Staged Router Plan

1. Convert course page controllers to registered lifecycle modules.
2. Move page dialogs inside a replaceable route surface or give them lifecycle ownership.
3. Add an abortable route fetcher for authenticated same-origin HTML or JSON fragments.
4. Swap `.k-page`, title, breadcrumbs, and route metadata while retaining the shell.
5. Add History API state, scroll restoration, route error recovery, and per-route cache keys.
6. Keep REST authoritative and use WebSockets only to invalidate current-user in-memory data.

## Cache Rules

- Cache is in memory only and scoped to the current document/user session.
- Sensitive staff payloads are not written to `localStorage` or the Cache API.
- Mutating REST calls clear access-related cache entries.
- Enrollment, staff assignment, role, and visibility events clear the same cache.
- API authorization is always re-evaluated server-side; cached UI state is never an authorization boundary.

## Rollback

Remove `navigation.js` injection and the speculation-rules block from `src/html_response.php`. Ordinary links continue to work without data or schema rollback.
