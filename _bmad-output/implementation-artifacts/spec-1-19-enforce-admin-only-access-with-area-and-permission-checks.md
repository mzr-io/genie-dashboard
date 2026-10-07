---
title: 'Enforce Admin-only access with area and permission checks'
type: 'feature'
created: '2026-10-07'
status: 'done'
baseline_commit: '2b35ab8f9a9b60706109617a5449327b155758a6'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-16-navigate-the-two-area-shell-with-sign-out-and-profile-menu.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-17-switch-the-active-workspace.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-11-record-audit-events-and-publish-outbox-events-in-the-same-transaction.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Admin pages are only hidden by the navigation: a person in the User area, or an Admin without the right permission, can open an Admin URL by guessing it, and nothing audits the attempt (Story 1.19; FR-5, FR-3, NFR-4, AR-6, AR-38, AR-54, UX-DR-255, 272, 282). The Story 1.19 section of `epics.md` holds the acceptance criteria.

**Approach:** One route middleware that requires the Admin area and a named permission on every Admin route and `/api/admin` endpoint, reads the permission fresh on every request, answers with the shared denial (a 403 page or the error envelope) and audits `access.admin.denied`, driven by the single route-to-permission mapping that the shell already uses, plus a generated test matrix over every Admin route.

## Boundaries & Constraints

**Always:** The permission catalogue (`data_sources.manage`, `blocks.edit`, `blocks.publish`, `templates.manage`, `users.manage`, `settings.manage`, `audit.view`, `data.preview_as_user`, `access.manage`) is the closed `Permission` enum over `membership_permissions` (table and enum exist from Story 1.12; verify RLS and the closed set with a test). A new route middleware `admin` takes an optional permission key and allows the request only when the session `area` is `admin` AND the active membership is an active `admin` membership in an active Workspace AND (when a key is given) the membership holds that permission, read from `membership_permissions` through the Access `MembershipPermissions` port on each request and never cached beyond it (a test revokes a permission and the next request is denied). Every Admin page route (the eleven `admin.*` routes registered by Story 1.16) and every `/api/admin/*` route uses it; the permission for each page comes from `ShellNavigation::ADMIN_ITEMS`, so navigation and enforcement share one mapping. A denied page request returns HTTP 403 rendering the `Forbidden` Inertia page with `perm-denied` and no page content or props of the denied page; a denied API request returns the error envelope with code `access.not_authorized` (the existing `Access\Contracts\ErrorCode` case, generated into the TypeScript enum). Every denial records `access.admin.denied` (add to `AuditAction`) through `Audit::recordSecurityEvent` in the active Workspace with route name and actor (membership id), de-duplicated to at most one row per user, route and minute; with no Workspace a log line with the reason only. A state-changing Admin request without a valid `X-XSRF-TOKEN` is rejected with 419 before this middleware runs (a test with a probe route proves the order). Actions the person cannot do render `aria-disabled` and focusable with the inline `perm-publish` reason (Publish without `blocks.publish`) while draft actions stay enabled, through a shared `GatedAction` component fed by a `can` map in the shared `shell` prop. A test generated from the router asserts, for every route using `admin`, the three cases: User area (denied), Admin without the permission (denied) and Admin with it (allowed); adding an Admin route without the middleware fails an architecture test. The middleware never trusts the client area: the area is read from the session and re-checked against the membership each request.

**Never:** Building the Admin screens (later stories); object-level authorization or `access.manage` grants (Story 1.22); changing the permission mapping beyond reuse; caching permissions; relaxing the session or CSRF rules; hiding disabled items.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| User area | Session area `user` requests an Admin page | 403 `Forbidden` page with `perm-denied`; `access.admin.denied` | No page content or props |
| Missing permission | Admin without the route's permission | Same denial and audit | N/A |
| Allowed | Admin area, active admin membership, permission held | Page renders | N/A |
| Area only | Admin permission held but area `user` | Refused (both required) | N/A |
| Demoted or inactive | Area `admin` but membership now `user`, inactive or Workspace inactive | Denied | N/A |
| API | `/api/admin/*` without access | Error envelope `access.not_authorized` | N/A |
| CSRF | State-changing Admin request without `X-XSRF-TOKEN` | 419 before authorization | N/A |
| Revocation | Permission revoked | Next request denied | Not cached |
| Gated action | Publish without `blocks.publish` | `aria-disabled` with `perm-publish`; draft actions enabled | N/A |
| Matrix | Every Admin route | Covered for User, Admin without, Admin with | Missing middleware fails a test |

</frozen-after-approval>

## Code Map

- `routes/web.php` -- Story 1.16 `admin.*` placeholder routes (eleven, one `Route::prefix` group), `routes/api.php` -- add the `/api/admin` group (Sanctum stateful) with a probe; `bootstrap/app.php` -- middleware alias registration beside `WorkspaceTransaction` and `IdleTimeout`
- `app/Http/Navigation/ShellNavigation.php` -- `ADMIN_ITEMS` (route name, permission), the active-membership and permission reads to reuse; `app/Modules/Access/Contracts/MembershipPermissions.php`, `Permission.php`, `ErrorCode.php`; `app/Modules/Access/Infrastructure/EloquentMembershipPermissions.php`
- `app/Platform/Audit/AuditAction.php`, `app/Modules/Identity/Http/SessionController.php`, `Application/SwitchWorkspace.php` -- `Audit::recordSecurityEvent` and de-duplication patterns; `app/Support/Observability/ApiErrorRenderer.php` -- error envelope
- `resources/js/pages/`, `resources/js/components/ui/button`, `useBlockedAction`, `locales/en.ts` (`perm-denied`, `perm-publish`), `labels.ts` -- add `Forbidden` page and `GatedAction`; `tests/Architecture/`, `tests/Feature`, `tests/Database`, `tests/js` -- existing suites

## Tasks & Acceptance

**Execution:**
- [x] `app/Http` (middleware), `bootstrap/app.php`, `routes/web.php`, `routes/api.php`, `AuditAction` -- the `admin` middleware, denial responses, audit, `/api/admin` group
- [x] `ShellNavigation`, `HandleInertiaRequests`, `resources/js` (`Forbidden` page, `GatedAction`, `can` map) -- shared mapping and gated actions
- [x] `tests/Architecture`, `tests/Feature`, `tests/Database`, `tests/js`, `README.md` -- generated matrix, every matrix row

**Acceptance Criteria:**
- Given a User-area session, when an Admin URL is requested, then the response is 403 `perm-denied` with `access.admin.denied` audited and no page content.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including the Docker-backed `Database` suite.

## Implementation Notes

## Spec Change Log

- **Implementation clarification (frozen block untouched).** Admin API routes are served under the existing `/api/v1` prefix (`/api/v1/admin/*`, Sanctum stateful), like every other API route in `routes/api.php`; "`/api/admin`" in the acceptance criteria means that group. Token-only (no session) API clients have no `area` or Workspace, so the gate denies them with the same envelope; resolving a Workspace from a token is out of scope.
- **KEEP:** the single `ADMIN_ITEMS` mapping, per-request permission reads, fail-closed lookups, the shared denial and the generated route matrix.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| An `admin` route with no key and no entry in `ADMIN_ITEMS` (unnamed, misnamed, or a mistyped `admin:key`) is gated on the Admin area only, or 500s at request time | high | patch | `required()` returns null for an unmapped route; the architecture test only checks the middleware is present and hardcodes 11 routes. |
| The generated matrix cannot detect a route gated on the wrong permission (an Admin holding other permissions), sends page routes as JSON (never reaching the `Forbidden` page), and does not check audit rows or per-route demotion | high | patch | "Admin with" holds every permission; `getJson` forces the envelope branch; no row-count assertion. |
| The fail-closed lookup path and the Inertia-visit denial branch have no test; the 419-before-authorization order is proven only for the 419 side | medium | patch | Nothing makes a lookup throw or sends `X-Inertia` to an Admin route. |
| The dedupe key is claimed before the audit write, so a failed write silences 60 seconds of denials, and a throwing cache store skips the audit | medium | patch | `Cache::add` runs before `recordSecurityEvent`. |
| Denials with no membership log without route, user id or request id; `Forbidden` back link hard-codes `/dashboard`; `GatedAction` keeps an empty reason and may render two reasons; the architecture test misses the class-name form and a fixed route count | low | patch | Cheap hardening named by the layers. |
| A forged or stale session Workspace is logged but not audited in that Workspace | medium | rejected | The person has no membership there, so there is no actor and recording into a Workspace they do not belong to would cross the tenant boundary; the log line is the control (context improved above). |
| 403 JSON message is "Forbidden", not the catalogue text; shared `shell` props on the 403 page; per-request memoisation; bearer-token clients; probe routes ship; no throttle on the Admin API; `route` as a free enum | low | rejected | The server owns no catalogue; shared props are the same as any page; token access is out of scope (Spec Change Log). |

## Design Notes

The shell already derived `allowed` from the same mapping, so reusing `ADMIN_ITEMS` keeps the menu and the gate from drifting. The area is checked twice on purpose: the session records the chosen area, the membership proves the right to it, and demotion takes effect on the next request.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools npm run build` -- expected: exit 0
