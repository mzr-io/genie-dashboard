# Epic 1 Context: Secure Workspaces and Access

<!-- Compiled from planning artifacts. Edit freely. Regenerate with compile-epic-context if planning docs change. -->

## Goal

People sign in to isolated Workspaces, and Admins invite users and control roles, permissions and groups. The epic also lays the project foundation every later epic builds on: starter kit, CI gates, five-role Docker Compose dev environment, observability, Valkey split, design tokens, shared UI components, message catalogue, row-level-security tenancy, and the audit and outbox kernels.

## Stories

- Story 1.1: Initialise the project from the Laravel Vue starter kit
- Story 1.2: Enforce architecture and quality gates in CI
- Story 1.3: Run all five process roles locally with Docker Compose
- Story 1.4: Observe every request with OpenTelemetry and scrubbed logs
- Story 1.5: Split Valkey into queue and cache stores with signed jobs
- Story 1.6: Apply the Dashflow design tokens and brand seam
- Story 1.7: Serve all product copy from the canonical message catalogue
- Story 1.8: Build the shared form controls and status components
- Story 1.9: Build dialogs, toasts, banners, popovers and live regions
- Story 1.10: Isolate each Workspace with row-level security
- Story 1.11: Record audit events and publish outbox events in the same transaction
- Story 1.12: Provision a Workspace and let its first Admin accept an invitation
- Story 1.13: Sign in as User or Admin
- Story 1.14: Reset a forgotten password
- Story 1.15: Warn before session expiry and keep forms safe
- Story 1.16: Navigate the two-area shell with sign out and profile menu
- Story 1.17: Switch the active Workspace
- Story 1.18: Edit my profile and settings, and open Help & support
- Story 1.19: Enforce Admin-only access with area and permission checks
- Story 1.20: List the Workspace's users in User configuration
- Story 1.21: Invite users to the Workspace
- Story 1.22: Assign roles and Admin permissions
- Story 1.23: Organise users into groups
- Story 1.24: Deactivate and reactivate users
- Story 1.25: Verify security and load readiness with test suites and a load-test skeleton

## Requirements & Constraints

- Public registration is disabled (404) and there is no MFA or two-factor in the MVP. The Teams scaffold is absent. Sign-in rotates the session ID, and so do area change and Workspace switch.
- Invitations are single-use, hashed and bound to the email. They are capped at the inviter's permissions. A permission can be granted only by someone who holds it. The last holder of `users.manage` cannot be removed or downgraded.
- Permission, role, group and deactivation changes take effect on the next request. No access decision is cached beyond the request.
- Accessibility baseline is WCAG 2.1 AA for the light theme: text contrast at least 4.5:1, non-text at least 3:1, keyboard operable, and colour never the only signal. Automated axe checks run on every screen.
- Cross-Workspace data leakage is a release blocker. Secrets and personal data never appear in logs or audit.

## Technical Decisions

- **Starter kit (AR-1):** Laravel 13, Inertia 3, Vue 3.5, TypeScript 5, Tailwind 4, shadcn-vue on reka-ui 2, Fortify, Wayfinder, Sanctum (same-origin SPA cookie plus CSRF for `/api/v1`), Pest 5 (PHPUnit removed), Horizon, Reverb, Pinia (one store per module), vue-i18n 11. Requires PHP 8.5 with `ext-bcmath` and `ext-uv`, Node 22, Vite 8.
- **One image, five process roles:** `web`, `realtime` (Reverb), `scheduler`, `worker-connector` (queues `fetch-interactive`, `fetch-scheduled`), `worker-compute` (queues `compute`, `outbox`, `notifications`, `maintenance`). A new deployable needs a new architecture decision.
- **Modular monolith:** each module exposes a public `Contracts` namespace (commands, queries, DTOs, events, ports). `Domain`, `Application`, `Infrastructure` and `Http` stay private. A module touches only its own tables and calls others only along the edges in `tests/Architecture/dependencies.php`, which is the source of truth. There are no reverse edges. A module needing downstream data subscribes to events and keeps its own read model. The kernel `app/Platform` (Tenancy, Outbox, Audit, Operations, EditLock, Layout, Period) calls no module. Access never depends on Blocks or Templates, and Identity sits below Access. No domain or client vocabulary in core code, schemas or seeds.
- **Pest architecture tests fail CI** on forbidden namespace edges and on `DB::table()` or queries naming another module's table.
- **Tenancy:** one shared schema. Every tenant table has non-null `workspace_id`, `ENABLE` plus `FORCE ROW LEVEL SECURITY`, and a policy on `current_setting('app.workspace_id', true)::uuid`. Unset context returns zero rows. `WorkspaceTransaction` (HTTP and job) is the only place that opens the transaction and calls `set_config(..., true)`. Global tables are only `users`, `sessions`, `password_reset_tokens`, `invitations`, `service_health_samples`, `operator_audit` and framework job tables. A migration adding any other table without `workspace_id` fails CI. Cross-Workspace membership lookup (sign-in, switcher) uses one `SECURITY DEFINER` function owned by Access. Every cache key, lock, queue payload and channel name is prefixed with the Workspace ID.
- **DB roles:** `app` (no BYPASSRLS; INSERT and SELECT only on audit), `migrator` (owns tables), `maintenance` (the only DELETE), `system` (column-limited dispatch and outbox relay access), `operator`.
- **Identity and access:** users are global. Role, permissions, groups and attributes belong to a workspace membership. The session holds the active Workspace and area (User or Admin). Admin endpoints require area Admin and the permission key. `Access\Contracts\AccessEvaluator` is the only visibility definition (an SQL fragment plus `canUse()` as `EXISTS`). Other modules only filter with `WHERE id IN (fragment)`.
- **Audit and outbox:** audited commands write the audit event and `Outbox::emit` inside the same transaction as the change. Denials and sign-in outcomes use `Audit::recordSecurityEvent` in an autonomous transaction. Event and audit `type` is `{module}.{noun}.{past_verb}` from the closed `AuditAction` enum, with a per-module allowlist `AuditSerializer` (sensitive values are stored as hashes). Outbox envelope data holds IDs and enums only. Error codes start with the owning module name. Consumers dedupe on `(consumer, event_id)` and respect `subject_seq`.
- **Valkey:** two stores. `queue` (queues, locks, rate limits, Reverb fan-out) is `noeviction`. `cache` is LRU and every key has a TTL. Sessions use the database driver. Jobs carry IDs only and are HMAC-signed, verified before unserialize. Valkey is never backed up and holds nothing irreplaceable. PostgreSQL is the system of record.
- **HTTP surface:** Inertia pages for screens, `/api/v1` for async and live concerns, with the typed fetch client sending `X-XSRF-TOKEN`. Password re-confirmation guards sensitive grants.
- **Observability:** OpenTelemetry with correlated request IDs and scrubbed logs.
- **Frontend:** `resources/js/modules/{module}`, `components/ui`. Design tokens are CSS custom properties with a brand-override seam, so components never hardcode colours. All UI strings come from the vue-i18n catalogue (English only in the MVP), and tests catch key drift. Numbers are lossless. Formatting is client-side.

## UX & Interaction Patterns

- Two areas, User and Admin, in one shell with a profile menu, sign out and Workspace switcher.
- Shared form controls, status components, dialogs, toasts, banners, popovers and live regions are built once in Epic 1 and reused everywhere. Copy comes only from the canonical message catalogue.
- Session expiry is warned in advance and in-progress form input is preserved.
- Light theme only for the MVP (dark deferred), with Inter and the specified type scale.

## Cross-Story Dependencies

- Stories 1.1 to 1.3 (starter kit, CI gates, Compose roles) precede everything. 1.2's architecture tests and 1.5's Valkey split apply to all later stories.
- Stories 1.10 (RLS and `WorkspaceTransaction`) and 1.11 (audit and outbox) are prerequisites for every tenant-owned table and audited command in 1.12 to 1.24, and for all later epics.
- Stories 1.6 to 1.9 (tokens, catalogue, shared components) are prerequisites for all UI stories.
- Sign-in (1.13) precedes the shell (1.16), switcher (1.17) and Admin-only checks (1.19), which gate the user, invite, role, group and deactivation stories (1.20 to 1.24).
- 1.12 (Workspace provisioning and first Admin invitation) must exist before invitation flows (1.21).
- 1.25 verifies security and load readiness over the whole epic.
- Epic 2 and later rely on the Access module, `AccessEvaluator`, the Outbox and Audit kernels, and the user-attribute catalog owned by Access.
