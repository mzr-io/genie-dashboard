---
title: 'Reset a forgotten password'
type: 'feature'
created: '2026-10-06'
status: 'done'
baseline_commit: 'c0c2f6f646671702238a726a628fdd053c787ea7'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-13-sign-in-as-user-or-admin.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-rmg-dashboard-platform-2026-10-05/EXPERIENCE.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The starter kit's forgot and reset pages leak whether an account exists, use generic copy, keep other sessions alive after a reset and are not audited, throttled by a configurable limit or logged safely (Story 1.14; FR-2, NFR-4, AR-38, UX-DR-23, 159, 282). The Story 1.14 section of `epics.md` holds the acceptance criteria.

**Approach:** Keep Fortify's reset broker and views but wrap the request and reset steps: a non-enumerating response with the catalogue copy, configurable throttle and link lifetime, all other sessions invalidated on success, the audit event recorded, and mail failures logged without the address.

## Boundaries & Constraints

**Always:** Requesting a reset with any well-formed email returns the same `reset-requested` response (HTTP 200 or the same redirect, same body) whether or not an account exists, a reset email is sent only if it does, and a mail failure still shows `reset-requested` and logs the failure with no email address, token or user identifier in clear. An email that fails the format check shows the `field-error` message inline on blur and on submit. Requests are throttled per email and IP; over the limit returns HTTP 429 with the `throttled` copy. The link lifetime and the throttle limits are `pending_input` tunables (`reset_link_lifetime`, `reset_request_max_attempts`, `reset_request_decay_seconds` under `sessions`) with no invented value; while unset, the starter kit's existing `config/auth.php` expiry (60 minutes) and a limit of 5 per minute apply, documented as the fallback. An expired, used or tampered token, or a token for the wrong email, shows `reset-expired` with a link to request a new one, changes no password and returns the same page for all cases. On success the password changes through the existing Fortify action (password rules from `Password::defaults()`), every other session of that user is deleted from the `sessions` table (the current one, if any, is regenerated), the sign-in page shows `password-changed`, and `identity.password.reset` is recorded through `Audit::recordSecurityEvent` in the user's active Workspace (a structured log line with the reason only when the user has no Workspace). No log, audit row or span contains the email, token or password. The pages use the Story 1.6 tokens, Story 1.8 controls, `FormField`, `FormErrorSummary` and `useBlurValidation`, with focus per UX-DR-274; routes named `password.request`, `password.email`, `password.reset` and `password.update` remain. All strings come from the catalogue or `labels.ts`.

**Never:** Sign-in changes (Story 1.13), session-timeout dialogs (Story 1.15), password change inside Profile (Story 1.18), email templates beyond the Fortify default plus the neutral wording needed, MFA, SSO, registration.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Known email | Well-formed email of an account | `reset-requested`; one reset email | N/A |
| Unknown email | Well-formed email, no account | Identical `reset-requested` response; no email | No enumeration |
| Malformed email | `abc` on blur or submit | `field-error` inline | Nothing sent |
| Throttled | Above the limit | HTTP 429 with `throttled` | Fortify-equivalent fallback while unset |
| Mail failure | Mailer throws | `reset-requested` still shown; failure logged without the address | N/A |
| Valid link | Within lifetime | Reset page accepts password and confirmation | N/A |
| Success | Valid reset | Password changed; other sessions deleted; sign-in shows `password-changed`; `identity.password.reset` | N/A |
| Expired or used | Old or reused token | `reset-expired` with link to request a new one; no change | Same page for tampered or wrong email |
| Weak password | Fails rules | Field errors; focus per UX-DR-274; token stays valid | N/A |
| Secret hygiene | Logs, audit, spans | No email, token or password | N/A |

</frozen-after-approval>

## Code Map

- `app/Actions/Fortify/ResetUserPassword.php`, `app/Providers/FortifyServiceProvider.php` -- reset action and views (`auth/ForgotPassword`, `auth/ResetPassword`), rate limiter registration; `config/auth.php` -- `passwords.users.expire` (60) and `throttle`
- `resources/js/pages/auth/ForgotPassword.vue`, `ResetPassword.vue`, `Login.vue` (shows a status banner), `layouts/auth/*` -- existing starter-kit pages; `resources/js/locales/en.ts` keys `reset-requested`, `reset-expired`, `password-changed`, `field-error`, `throttled`
- `app/Modules/Identity/` (Stories 1.12 and 1.13: `Application`, `Http`, `Contracts`, `Infrastructure`) -- add the reset flow there, with `SignInRecorder`-style recording; `app/Platform/Audit/AuditAction.php` -- `identity.password.reset` exists
- `config/dashflow.php`, `tests/Feature/DashflowTunablesTest.php` -- add the three tunables to the closed list; `.env.example`, `compose.yaml` `x-app-env`
- `tests/Feature/Auth/PasswordResetTest.php` (existing starter-kit test), `tests/Database/`, `tests/js/` -- extend

## Tasks & Acceptance

**Execution:**
- [x] `config/dashflow.php`, `app/Modules/Identity`, `app/Providers/FortifyServiceProvider.php`, `.env.example`, `compose.yaml` -- request and reset flow, tunables, throttle, session invalidation, audit
- [x] `resources/js/pages/auth/ForgotPassword.vue`, `ResetPassword.vue`, `Login.vue`, `labels.ts` -- pages with catalogue copy and focus handling
- [x] `tests/Feature`, `tests/Database`, `tests/js`, `README.md` -- every matrix row

**Acceptance Criteria:**
- Given a reset request for a known or unknown email, when submitted, then the response is identical and an email is sent only for a known one.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including the Docker-backed `Database` suite.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| The reset URL `/reset-password/{token}?email=...` reaches the nginx access log and has no `Referrer-Policy` or `Cache-Control: no-store` | high | patch | Same leak fixed for invitations in Story 1.12: `$log_path` keeps the path token and the page carries the email in the query. |
| Mail-failure test throws a `TypeError` (the fake does not implement `PasswordBroker`), so `sendResetLink` is never reached | high | patch | The `catch (Throwable)` swallows the `TypeError` and the test still passes; a real mailer failure outside the try would 500 and reveal the account. |
| Email lowercased before lookup, so a stored mixed-case email is never found and looks like an unknown account | medium | patch | `Str::lower($email)` then `getUser(['email' => ...])`; Story 1.13 already looks up by `lower(email)`. |
| A reset can leave the token usable: two concurrent resets both pass `tokenExists`, and a failure after the password commit leaves the token alive | medium | patch | The broker deletes the token after the callback; password write, session delete and token delete are not one transaction. |
| The current session is regenerated without destroying its old row | medium | patch | `regenerate()` keeps the old id valid in `sessions`. |
| Link lifetime tunable, reset decay tunable and the per-IP half of the key are untested through requests | medium | patch | Tests only assert the config value and the 60-minute fallback. |
| `ResetPassword.vue` shows "Enter a new password." for an error naming no known field; stale props after revisit; hardcoded `/login` and `/forgot-password` literals; 254 and 512 duplicated | low | patch | The previous code used `@/routes`; limits drift from `SignIn::MAX_EMAIL`. |
| Per-IP-only throttle bucket (mail-bombing by rotating target emails) | medium | defer | Needs a new `pending_input` limit; spec asks only for per-request throttling. |
| No "your password was changed" notice email | low | defer | Email templates are in the spec's Never list; a follow-up for account-takeover detection. |
| Known email slower than unknown (inline send); timing of throttle audit | low | rejected | Responses are identical in body and status as specified; timing equivalence is not claimed. |
| Reset POST not throttled; failed or expired attempts not audited; `SESSION_DRIVER` not asserted; audit failure swallowed; Login hides other statuses; password-rule messages verbatim | low | rejected | 256-bit token; Compose uses the database session driver (documented); audit failure policy as in Stories 1.11 to 1.13. |

## Design Notes

The fallbacks (60 minutes, 5 per minute) are the starter kit's shipped behaviour, not values Dashflow chose, and stay only while the `pending_input` settings are unset, as in Story 1.13.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools npm run build` -- expected: exit 0
