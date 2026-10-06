---
title: 'Provision a Workspace and let its first Admin accept an invitation'
type: 'feature'
created: '2026-10-06'
status: 'done'
baseline_commit: 'cfb1fa088a978fa094d1566494a5f54a6a06016d'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-10-isolate-each-workspace-with-row-level-security.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-11-record-audit-events-and-publish-outbox-events-in-the-same-transaction.md'
  - '{project-root}/_bmad-output/planning-artifacts/architecture/architecture-rmg-dashboard-platform-2026-10-05/ARCHITECTURE-SPINE.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** There is no way to create a Workspace or get its first Admin into it, and public registration must stay off (Story 1.12; FR-3, FR-2, FR-6, NFR-4, AR-38, AD-31, UX-DR-23). The Story 1.12 section of `epics.md` holds the acceptance criteria.

**Approach:** An operator-only Artisan command creates the Workspace and a hashed, email-bound, single-use invitation and emails it; an invitation-accept page (Inertia) creates or reuses the user and grants the Admin membership with every Admin permission, with non-enumerating failure handling, audit and security events.

## Boundaries & Constraints

**Always:** The command `dashflow:workspace:create {name} {label} {admin-email}` is the only way to create a Workspace; it runs on the database connection of role `operator` (`DB_OPERATOR_*`, given only to a one-off operator command path, never to web or workers) and writes `workspaces`, `invitations` and `operator_audit` there, then mirrors the action into the Workspace audit log through `Audit::record` inside `WorkspaceTransaction` as `app`. Tables `invitations` (global: UUIDv7 key, `workspace_id`, email, token hash, role, `expires_at`, `used_at`, `created_by`) and `operator_audit` (global) are created as `migrator`; `operator` gets only the grants it needs on `workspaces`, `invitations` and `operator_audit`, `app` gets SELECT and UPDATE on `invitations` and INSERT and SELECT on `operator_audit` is not granted to `app`. The invitation token is 256 random bits; only its SHA-256 hash is stored and compared in constant time; the plain token exists only in the emailed link. The invitation is bound to the email: acceptance with a different email is refused. `membership_permissions` (tenant table, RLS forced) and the closed `Permission` enum (`data_sources.manage`, `blocks.edit`, `blocks.publish`, `templates.manage`, `users.manage`, `settings.manage`, `audit.view`, `data.preview_as_user`, `access.manage`) are created here so the first Admin receives all of them (Story 1.19 adds enforcement on top). Accepting creates the `users` row (name and password, password rules from `Password::defaults()`) or, for a known email, only adds the membership without touching the password or name; in both cases a `workspace_memberships` row with role `admin` and all permissions is created inside `WorkspaceTransaction`, `identity.invitation.accepted` is audited, and the invitation is marked used in the same transaction; a second use fails. An unknown, used, expired or tampered link returns the same neutral page ("This link has expired or was already used. Request a new one." from the catalogue key `reset-expired`, which already exists) with a generic 404 or 410 status and the same timing-safe path, and records `identity.invitation.rejected` through `Audit::recordSecurityEvent` (reason enum only, no token). Invitation lifetime comes from the tunable `dashflow.tunables.users.invitation_lifetime` (`pending_input`, no invented value): the command refuses to run with a clear message while it is unset. The accept form shows invalid-password field errors with the `field-error` copy, validates on blur and submit, and moves focus per UX-DR-274 using the Story 1.8 components; all strings come from the catalogue or `labels.ts`. Registration stays disabled and no HTTP route or API creates a Workspace: such routes return 404 (tests assert it). The invitation email uses Laravel Mail with the configured mailer (`log` locally), contains no password, and the link is the only secret.

**Never:** A Workspace Admin UI to create Workspaces; sending the plain token anywhere but the email; logging tokens, emails or passwords; invitation management screens, resend and revoke (Story 1.21); permission enforcement or Admin-area gating (Story 1.19); sign-in and session rotation (Story 1.13); converting `users.id` to UUID; runtime use of `maintenance`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Provision | Command with name, label, email | Workspace row, hashed invitation, email sent, `operator_audit` row and mirrored Workspace audit event | Refuses when lifetime is unset or the email is invalid |
| Accept new user | Valid link, name and password | `users` row, admin membership with all permissions, `identity.invitation.accepted`, invitation used | N/A |
| Accept known email | Link for an existing user | Membership added; password and name unchanged | N/A |
| Reuse | Same link again | Neutral expired page; security event | No second membership |
| Expired | Link past `expires_at` | Neutral expired page; security event | N/A |
| Tampered | Altered token or unknown token | Same neutral page and timing; security event with no token | N/A |
| Wrong email | Valid token submitted for a different email | Refused as expired | N/A |
| Weak password | Invalid password on the form | Field errors with `field-error` wording; focus per UX-DR-274 | Nothing created |
| No Workspace route | Any HTTP route to create a Workspace | 404 | N/A |
| Roles | `app` and web try to INSERT into `workspaces` | Permission denied | N/A |
| Secret hygiene | Logs, audit rows, `operator_audit` | No token, password or raw email | N/A |

</frozen-after-approval>

## Code Map

- `app/Console/Commands/` -- existing `HealthCommand`, `HeartbeatCommand`, `ErrorCodesCommand`; add the create command; `routes/console.php` untouched
- `app/Modules/Identity/` (`Infrastructure` only today) -- add `Application` (create and accept invitation), `Http` (controller, form request), `Contracts` (`ErrorCode`); `app/Modules/Access/` -- `WorkspaceMembership` model and `membership_permissions`, `Permission` enum
- `app/Platform/Audit/` (Story 1.11) -- `Audit::record`, `recordSecurityEvent`, `AuditAction` (already has `identity.invitation.accepted`; add `identity.invitation.rejected`, `platform.workspace.created` and the operator mirror if missing), `AuditSerializers` registry
- `app/Platform/Tenancy/` -- `WorkspaceTransaction`, `Workspace`; `config/database.php`, `compose.yaml`, `.env.example`, `docker/postgres/initdb.sh` -- add the `operator` connection (`DB_OPERATOR_*`)
- `config/fortify.php`, `app/Providers/FortifyServiceProvider.php`, `routes/web.php` -- registration is already off; add the invitation routes under guest; `resources/js/pages/auth/` -- existing auth pages; add `AcceptInvitation.vue` and the expired page using `components/ui`, `FormField`, `FormErrorSummary`, `useBlurValidation`, `locales/en.ts` key `reset-expired`
- `config/dashflow.php` -- tunables (add `users.invitation_lifetime`, `pending_input`); `tests/Feature/DashflowTunablesTest.php` -- its closed list must include it
- `database/migrations/` -- Story 1.10/1.11 migrations; `tests/Database/` -- Docker-backed suite; `tests/js/` -- DOM tests; `tests/Architecture/dependencies.php` -- global tables already include `invitations` and `operator_audit`; module edges Identity to nothing, Access to Identity

## Tasks & Acceptance

**Execution:**
- [x] `database/migrations`, `docker/postgres`, `config/database.php`, `compose.yaml`, `.env.example` -- `invitations`, `operator_audit`, `membership_permissions`, privileges, `operator` connection
- [x] `app/Console/Commands`, `app/Modules/Identity`, `app/Modules/Access`, `app/Platform/Audit`, `config/dashflow.php` -- create command, invitation service, accept flow, permissions enum, audit actions, tunable
- [x] `routes/web.php`, `resources/js/pages/auth`, `resources/js/locales` -- accept and expired pages, 404 for Workspace creation routes
- [x] `tests/Database/*`, `tests/Feature/*`, `tests/js/*`, `tests/Architecture/*` -- every matrix row; README

**Acceptance Criteria:**
- Given the operator command runs, when it completes, then a Workspace and a hashed single-use invitation exist and the action is in `operator_audit` and the Workspace audit log.
- Given `bin/tools composer ci:check`, when run, then it passes, including the Docker-backed `Database` suite.

## Implementation Notes

## Spec Change Log

- **Review iteration 1 (clarifications, frozen block untouched).** (a) The constraint "INSERT and SELECT on `operator_audit` is not granted to `app`" is garbled; the intended and implemented rule is that `app` has no privilege on `operator_audit`. (b) Refusals return HTTP 410, not "404 or 410". (c) An unknown or malformed token names no Workspace, so `Audit::recordSecurityEvent` (which needs a Workspace) cannot record it; those are logged as a structured warning with the reason only, and refusals for a known invitation are audited. (d) The timing-equivalence claim is dropped: responses are neutral, but latency is not guaranteed equal.
- **KEEP:** the operator-transaction design, hashed token, neutral 410 page, `Identity`/`Access` port split, `membership_permissions` with forced RLS.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| An existing suspended or removed membership is silently reactivated and raised to admin by any invitation; the audit event does not say created or changed | high | patch | `AdminMembershipGranter` sets `role=admin, status=active` on any existing row; AR-38 caps invitations and a link must not reactivate a removed member. |
| The invitation `role` column is ignored: acceptance always grants admin | medium | patch | The CHECK allows `user`; a later non-admin invitation would hand out Admin. |
| `app` has table-wide UPDATE and SELECT on `invitations` | medium | patch | A compromised web process could rewrite `token_hash`, `expires_at`, `email`, `workspace_id` or read all invitee emails. |
| The invitation token reaches the nginx access log and has no `Referrer-Policy` or `Cache-Control: no-store` | high | patch | `docker/nginx.conf` logs `$log_path`, which contains `/invitations/<token>`; the accept page carries the token as a prop. |
| Single-use lock and in-transaction re-check never exercised; two invitations for one new email hit the `users` unique constraint and return a 500 | high | patch | The "only one wins" test posts twice sequentially, so `check()` rejects the second before the lock. |
| `guest` middleware blocks a signed-in invitee | medium | patch | An authenticated existing user is redirected to the dashboard and cannot accept. |
| Command accepts control characters in the name, duplicate labels fail generically, no lifetime upper bound, `{!! !!}` in the mail view | medium | patch | Newlines reach the mail subject and body. |
| Accept form shows nothing on a server error with no known field and on non-validation failures | medium | patch | `names.length === 0` leaves no error or focus; network, 419 and 500 are silent. |
| Operator actor is the constant `operator-cli`, mirrored event uses `operator` | low | patch | An operator log must say who ran the command. |
| Tests missing: existing-membership branch, case-insensitive email, operator denied UPDATE and DELETE on `operator_audit`, throttle on the routes | medium | patch | Verification-gap layer; changing the implementation keeps tests green. |
| Unknown and malformed tokens are logged, not audited | medium | rejected | No Workspace exists to attach an audit row to (see Spec Change Log). |
| Timing differs between refusal paths | low | rejected | Responses are neutral; the equivalence claim is withdrawn rather than engineered. |
| Mail sent before commit; failed audit mirror leaves a half-provisioned Workspace and a rerun duplicates it | medium | defer | Needs an idempotent `--repair` path; operator_audit still records the action. |
| Mail strings outside the catalogue, no HTML alternative, no confirmation after accept, composite FK for `membership_permissions`, rejection-log flooding, 429 vs 410, RLS test equality loosened | low | rejected | The catalogue is the vue-i18n front end; the rest is polish or unlikely. |

## Design Notes

`membership_permissions` is created here because the first Admin must receive "all Admin permissions" and Story 1.19's own criterion (table with RLS and a closed enum) is then already met; 1.19 adds route and API enforcement. The command uses role `operator` because `app` has no privilege on `workspaces`; this is the first runtime use of that role and is limited to the operator command. The lifetime has no default on purpose: the command fails closed until an operator sets the tunable.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools composer test:database` -- expected: exit 0
