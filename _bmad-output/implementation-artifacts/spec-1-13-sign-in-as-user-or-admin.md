---
title: 'Sign in as User or Admin'
type: 'feature'
created: '2026-10-06'
status: 'done'
baseline_commit: 'a10f789a35ef2b086102dbfd7d1ee2529f6efedb'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-12-provision-a-workspace-and-let-its-first-admin-accept-an-invitation.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-rmg-dashboard-platform-2026-10-05/DESIGN.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-rmg-dashboard-platform-2026-10-05/EXPERIENCE.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The starter kit's login page has no role choice, no Workspace or area in the session, no audit of sign-in outcomes and none of the Dashflow design (Story 1.13; FR-1, FR-2, NFR-4, AR-38, AD-31, UX-DR-4, 23, 32, 33, 90, 91, 158, 159, 212, 274, 282). The Story 1.13 section of `epics.md` holds the acceptance criteria and the UX-DR lines the layout.

**Approach:** Replace the Fortify login with a role-aware sign-in: a split-screen page with the dark hero and role cards, a server flow that authenticates, checks the chosen area against the user's role, stores the active Workspace and area in the rotated session, audits every outcome, throttles by configurable limits and lands on the right Overview.

## Boundaries & Constraints

**Always:** The page follows UX-DR-90, 91, 158 and 212 with Story 1.6 tokens, Story 1.8 controls and the catalogue (`signin-subtitle`, `signin-role-denied`, `signin-failed`, `throttled`; other strings in `labels.ts`): hero about 55% wide on the left above 1024 px with logo, "Dashboard Management System", headline, tagline, two trust lines, illustration, footer; below 1024 px the hero is hidden and the form is centred under the logo; at 320 px no horizontal scroll. The form has eyebrow, title, subtitle, a User or Admin role-card radiogroup with visible radios, email and password with leading icons, show/hide password, Remember me, Forgot password, a "Contact your workspace administrator" line linking to Help & support, and one full-width primary button relabelled "Sign in as User" or "Sign in as Admin". No SSO. Fields have labels and `autocomplete="username"` and `"current-password"`; a failed submit focuses an error summary. Authentication keeps Fortify's hashing, session regeneration on success and CSRF. Active Workspace is the membership with the latest `last_active_at`, else the first by name (read through the Access `SECURITY DEFINER` lookup); it and the area (`user` or `admin`) are stored in the session keys `workspace_id` and `area` (the keys `WorkspaceTransaction` and later stories read), and `last_active_at` is updated. User role lands on the route `overview` (the existing dashboard page, name kept as an alias); Admin with an `admin` membership lands on `admin.overview`; Admin without it gets `signin-role-denied` with the User card offered, no session, and `identity.area.denied`. Unknown and known emails with a wrong password return the same `signin-failed` with HTTP 422 and `identity.signin.failed`; throttling returns HTTP 429 with `throttled` and `identity.signin.throttled`. Throttle limits (`sign_in_max_attempts`, `sign_in_decay_seconds`) and the Remember-me maximum (`remember_me_duration`, already a tunable) are `pending_input` settings in `config/dashflow.php`; while unset, Fortify's shipped 5 attempts per minute applies and Remember me adds nothing beyond the normal session lifetime. Sign-in outcomes are recorded through `Audit::recordSecurityEvent` in the user's active Workspace; when no Workspace exists (unknown email, user with no membership) a structured log line with the reason only is written instead. No outcome, log, audit row or span contains the password, the raw email or the token. Routes exist and are named for Sign in, Forgot password and Reset password, and minimal placeholder pages exist for `admin.overview` and Help & support (`help`) until Stories 1.16, 1.18 and 1.19 replace them.

**Never:** The app shell, sidebar or navigation (Story 1.16); Admin area gating beyond the redirect (Story 1.19); the Workspace switcher (Story 1.17); password reset behaviour (Story 1.14); session-timeout dialog (Story 1.15); registration; SSO; MFA; invented limit or duration defaults.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| User sign-in | Valid credentials, User card | Session ID rotated; `workspace_id` and `area=user` stored; lands on `overview`; `identity.signin.succeeded` | N/A |
| Admin sign-in | Valid credentials, Admin card, admin membership | Lands on `admin.overview` with `area=admin` | N/A |
| Admin denied | Valid credentials, Admin card, no admin role | `signin-role-denied`, User card offered, no session; `identity.area.denied` | N/A |
| Wrong credentials | Unknown email or wrong password | Identical `signin-failed`, HTTP 422; `identity.signin.failed` | No enumeration |
| Throttled | Attempts above the limit | HTTP 429 with `throttled`; `identity.signin.throttled` | Fortify default while unset |
| Remember me | Ticked, duration set or unset | Extended up to the maximum, or no extension while unset | N/A |
| Several Workspaces | User with several memberships | Last used, else first by name | N/A |
| No membership | Valid user, no Workspace | Cannot sign in as User or Admin; neutral failure; logged | N/A |
| Keyboard and screen reader | Failed submit | Error summary focused; fields labelled | N/A |
| Layout | 1280, 1023 and 320 px | Split with 55% hero; hero hidden and form centred; no horizontal scroll | N/A |
| Routes | Router inspected | Sign in, Forgot password, Reset password named | N/A |

</frozen-after-approval>

## Code Map

- `app/Providers/FortifyServiceProvider.php`, `config/fortify.php` -- existing login view and the `login` rate limiter (5 per minute, Story's fallback); `app/Actions/` -- Fortify actions
- `resources/js/pages/auth/Login.vue`, `layouts/auth/*`, `AuthLayout.vue` -- starter-kit login and auth layouts; `Welcome.vue` is separate; add the hero and role-card components under `resources/js/components/`
- `app/Modules/Identity/` (Story 1.12: `Application`, `Http`, `Contracts`, `Infrastructure`), `app/Modules/Access/` (`MembershipLookup`, `WorkspaceMembership`) -- sign-in flow lives in Identity, membership reads through the Access port as in Story 1.12
- `app/Platform/Audit/` -- `Audit::recordSecurityEvent`, `AuditAction` (`identity.signin.*` and `identity.area.denied` exist or are added), `AuditSerializers`; `app/Platform/Tenancy/WorkspaceTransaction.php` -- reads `workspace_id` from the session
- `routes/web.php`, `routes/settings.php` -- existing routes, `dashboard` name; `config/dashflow.php` -- tunables, `tests/Feature/DashflowTunablesTest.php` closed list
- `resources/js/locales/en.ts`, `labels.ts`, `components/ui`, `FormField.vue`, `FormErrorSummary.vue`, `SegmentedControl`, `RadioGroup` -- Stories 1.7 and 1.8
- `tests/Feature/Auth/SignInTest.php` -- existing; `tests/Database/` -- Docker-backed suite; `tests/js/` -- DOM tests

## Tasks & Acceptance

**Execution:**
- [x] `config/dashflow.php`, `app/Modules/Identity`, `app/Providers/FortifyServiceProvider.php`, `routes/web.php` -- sign-in flow, area check, session keys, throttle tunables, audit, routes and placeholder pages
- [x] `resources/js/pages/auth/Login.vue`, hero and role-card components, `labels.ts` -- split-screen page, role cards, error summary
- [x] `tests/Feature`, `tests/Database`, `tests/js` -- every matrix row; README

**Acceptance Criteria:**
- Given valid credentials and a role card, when the form is submitted, then the user lands on the matching Overview with the Workspace and area in the rotated session.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including the Docker-backed `Database` suite.

## Implementation Notes

## Spec Change Log

- **Review iteration 1 (clarification, frozen block untouched).** For the Admin card, the active Workspace is the most recently used Workspace in which the person holds a usable admin membership; refusal (`signin-role-denied`) applies only when they hold none. Reason: the frozen text says the person "holds the Admin role in their Workspace", and without the Workspace switcher (Story 1.17) a literal active-only reading would lock a genuine admin out. The User card keeps "last used, else first by name".

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| Sign-in does not clear a prior user's `workspace_id` and `area` when a different person signs in on the same session | high | patch | `regenerate()` keeps session data, so stale keys survive. |
| Authenticated before `markActive` or the audit write; a failure leaves a logged-in user with no area or Workspace and a 500; a request with no session logs in with no keys | high | patch | `guard->login()` runs first; `hasSession()` false skips the keys but login already happened. |
| `/help` sits behind `auth`, so the "Contact your workspace administrator" link is dead for signed-out visitors | medium | patch | `routes/web.php` puts `help` in the `auth` group. |
| Workspace status is not verified in the usable rule | medium | patch | No Database test uses a non-active Workspace; removing the check keeps tests green. |
| Admin card only checks the last-used Workspace, so a genuine admin elsewhere is refused | medium | patch | Spec says "holds the Admin role in their Workspace"; until the switcher (1.17) exists the person cannot reach their admin Workspace. Pick the most recently used usable admin membership for the Admin card. |
| Successful sign-ins count toward the limit; every 429 runs a lookup and an audit write | medium | patch | The bucket is not cleared on success; the throttled response does a user lookup and an audit insert per request. |
| `role` input silently coerced to User for any non-`admin` value | medium | patch | Tampered or mistyped values sign the person into the User area without notice. |
| No upper bound on email or password length before hashing | low | patch | Every hash comparison is paid before the throttle limits the attempt. |
| Login page: raw server string shown when the key is not in the catalogue; password cleared after a role denial; hero `<h2>` precedes the page `<h1>`; the role error-summary target is not focusable | medium | patch | Login.vue and SignInHero.vue. |
| `lower(email)` lookup has no functional index | low | patch | Sequential scan on every sign-in. |
| `RateLimiter::clear('x')` clears nothing | low | patch | Test isolation. |
| Two-factor challenge bypassed by replacing Fortify's pipeline | false | rejected | Fortify's two-factor feature is not enabled and no columns exist; the MVP has no MFA (AR-38). |
| Admin placeholder reachable by any signed-in user | low | rejected | Gating is Story 1.19; the page holds no data. |
| Intended URL ignored; Fortify rehash and `Failed` event dropped; timing differences between known and unknown users; case-folding duplicates | low | rejected | The spec lands on the area Overview; hash parity is the stated guarantee; the rest are polish. |
| Audit write failure for `succeeded` is swallowed | low | rejected | Failing closed would lock everyone out during an audit database outage; the failure is logged (as in Story 1.11). |
| Route alias direction, remember-me units and validation, forensic IP and user agent, hardcoded hero copy and footer year, per-IP spray bucket | low | rejected | Limits and units are `pending_input`; the rest is polish. |

## Design Notes

Fortify's 5 per minute is the starter kit's existing behaviour, not a value Dashflow chose; it stays only as the safe floor while the `pending_input` limits are unset. Failed attempts for an unknown email have no Workspace to audit, so they are logged with the reason only, as in Story 1.12.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools npm run build` -- expected: exit 0
