---
title: 'Register and edit a Data Source'
type: 'feature'
created: '2026-10-07'
status: 'done'
baseline_commit: 'be8ccce6f740bb8847759b71d9e85af399ae8f0c'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-2-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-1-manage-the-workspace-host-allowlist.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-2-guard-every-outbound-url-with-egressguard-and-operator-private-range-grants.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Admins cannot yet describe the APIs a Workspace will read, so nothing can later be fetched or pointed at (Story 2.3; FR-9, FR-10, NFR-4, NFR-12, AR-8, AR-9, AR-14, AR-25, AR-44, AR-57, UX-DR-207, 115, 261, 263, 23, 26, 22, 37, 39, 274, 282). The Story 2.3 section of `epics.md` holds the acceptance criteria.

**Approach:** A `data_sources` tenant table in the Connector module with a per-row `revision`, an Admin API and Data sources list and form pages behind the Story 1.19 gate (`data_sources.manage`), allowlist and header validation on the server, a Workspace and deployment `require_https` rule, audit in the same transaction, and the real dependents implementation that Story 2.1's removal dialog was waiting for.

## Boundaries & Constraints

**Always:** Table `data_sources` (UUIDv7 key, non-null `workspace_id`, `name`, `base_url`, `scheme`, `host`, `port`, `auth_type` defaulting to `none` with a CHECK for the five epic values of which only `none` is accepted in this story, `default_headers` jsonb, optional `timeout_seconds`, `max_response_bytes`, `max_pages`, `live_capable` boolean default false, `revision` integer starting at 1, timestamps, `created_by_membership_id` without cross-module FK) is a Connector tenant table with `ENABLE` and `FORCE ROW LEVEL SECURITY`, the standard policy, created as `migrator`, with `app` SELECT, INSERT and UPDATE only (no delete exists in this story). `name` is trimmed, 1 to 64 characters, no control or zero-width characters, unique per Workspace case-insensitively (422 field error on a duplicate). The API lives under `/api/v1/admin/data-sources` (`GET` list with `q` and sort, `POST` create, `GET {id}`, `PUT {id}` update with the current `revision`, `POST check-url` for the blur check; names `api.admin.data-sources.*`; throttled like the host allowlist API), permission `data_sources.manage`, mapped in `ShellNavigation::ADMIN_API_ROUTES`; the existing placeholder route `admin.data-sources.index` becomes the list page and `admin.data-sources.create` and `admin.data-sources.edit` are added to `ADMIN_PAGES`, all in the Story 1.19 test matrix. A request without the permission, or from the User area, follows the Story 1.19 denial (403, audited); the User area never lists Data sources. Base URL: `http` or `https` only, no userinfo, query or fragment, host parsed with Story 2.1's `AllowedHost` rules and checked for the scheme, host and port on the Workspace allowlist through `HostAllowlist::isAllowed` (a miss is a 422 on `base_url` with key `host-not-allowlisted`; `check-url` answers the same for the blur check and never resolves DNS or sends a request); the stored `scheme`, `host` and `port` are derived from it. `require_https` is a boolean on `workspace_settings` (new migration, default false, read through the kernel `WorkspaceSettings`) OR-ed with the existing deployment setting `dashflow.tunables.guards.require_https`; when either is on, an `http://` Base URL is a 422 with an inline explanation and nothing is written; when both are off an `http://` URL saves, is audited with `scheme=http`, and shows the persistent "Not encrypted" badge (with text, reusing `hostAllowlistLabels` wording) in the form and the list on every later view. No admin control for the Workspace setting is built (Epic 8 edits settings). Default headers are key/value rows: names are HTTP tokens, values are visible ASCII (0x20 to 0x7E) without CR or LF, otherwise 422 with nothing stored; the reserved names the egress transport rejects, `Authorization`, `Proxy-Authorization` and `Cookie` are refused (credentials belong to Story 2.4's write-only secrets). Timeout, maximum response size and maximum pages are optional positive integers; each is validated against the matching platform ceiling (`tunables.timeouts`/`guards` settings, no invented numbers: when a ceiling is unset it is not checked) and a blank value means "use the platform setting"; length limits for names and header values are validation constants in code, not tunables. Create writes `connector.data_source.created` and update `connector.data_source.updated` (with allowlisted before and after: scheme, host, port, auth type, limits, `live_capable`, header count and a keyed hash of the header map, never header values or the full URL path) through the Connector `AuditSerializer` in the same transaction as the change; update compares the request's `revision` under a row lock, bumps it by one (this is the later `data_source_revision`), and a stale `revision` returns 409 `connector.revision_conflict` with the current state; a refused permission attempt is recorded through `Audit::recordSecurityEvent`. `live_capable` is off by default with the helper "Only turn this on if the API can handle a call every 30 seconds per block." The list is a `data-table` with name, host, auth type, health (status dot plus the word "Checking…" until Story 2.18), last successful call ("—" until Story 2.14) and Blocks using it (0 until Epic 3), search, count caption, "+ Register data source" as the only primary button, five skeleton rows when cold, `list-empty` with that action, `list-no-match` with Clear search, and "We couldn't load {items}. Try again." with Retry; a new row is highlighted and focused after create. The form (create and edit, `novalidate`, `FormErrorSummary`, focus to the first invalid field, `UnsavedChangesDialog` on leave) shows skeletons with Save disabled until loaded, "We couldn't load these settings. Try again." with Retry and never stale values on a load failure, runs the blur check on Base URL (nothing on success; inline `host-not-allowlisted` with Save `aria-disabled` and its reason adjacent on a miss), and returns to the list after a successful create. A real `HostAllowlistDependents` implementation lists Data Sources by host and port and replaces `NoHostAllowlistDependents` in `AppServiceProvider`. All strings come from the catalogue or `labels.ts`.

**Never:** Any fetch, test call or DNS lookup (Stories 2.5 and later); credentials or secrets of any kind; deleting a Data Source; a Workspace-setting editor; invented default values for limits or ceilings; showing or storing header values in audit data; a User-area view of Data sources.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Register | Name, allowlisted https Base URL | Row with `revision` 1; list shows it highlighted with "Checking…"; created audited | N/A |
| Blocked host | Base URL host not on the allowlist | Blur shows `host-not-allowlisted`; Save `aria-disabled` with reason; server 422 on `base_url` | Nothing written |
| Bad header | Value with CR, LF or non-visible ASCII; reserved or credential header name | 422 field error | Nothing stored |
| Plain http | `http://` URL, `require_https` off | Saves; audited `scheme=http`; "Not encrypted" badge persists | N/A |
| Require https | Workspace setting or deployment override on, `http://` URL | 422 with inline explanation | Nothing written |
| Edit | Current `revision` | `revision` + 1; `updated` audited with allowlisted before and after | N/A |
| Stale edit | Old `revision` | 409 with current state | Nothing changes |
| Limits | Value above a set ceiling, zero or non-integer | 422 field error | N/A |
| Live | `live_capable` toggle | Off by default; stored as sent | N/A |
| Duplicate name | Same name differing by case or spaces | 422 field error | N/A |
| No permission | No `data_sources.manage` or User area | 403, audited; no data | Story 1.19 |
| Isolation | Another Workspace | Zero rows; 404 on an id | RLS |
| Dependents | Allowlist entry removal with Data Sources on that host | Dialog lists them | N/A |
| Load states | Cold load, failure, empty, no match | Skeletons, Retry, `list-empty`, `list-no-match` | N/A |

</frozen-after-approval>

## Code Map

- `app/Modules/Connector/Application/ManageHostAllowlist.php` (`lockVersion` 202, `assertCurrent`, `isAllowed` 81, dependents call 172), `Contracts/{HostAllowlist,HostAllowlistDependents,DependentDataSource,AllowedHost,ErrorCode,AllowlistRevisionConflict}.php`, `Infrastructure/{ConnectorAuditSerializer,NoHostAllowlistDependents}.php`, `app/Providers/AppServiceProvider.php:105-138` -- patterns to mirror and the binding to replace
- `app/Http/Controllers/Admin/HostAllowlistController.php` (`actor`, `workspaceId`, `AdminApiError::json`, 409 `extra['current']`, `Cache-Control: no-store`), `app/Http/Requests/Admin/AddHostRequest.php`, `Resources/HostEntryResource.php`, `routes/api.php:50`, `routes/web.php:65` (placeholder to replace), `app/Http/Navigation/ShellNavigation.php` (`ADMIN_ITEMS` has `data-sources`; `ADMIN_PAGES` 60; `ADMIN_API_ROUTES` 69) -- API, routes and gate
- `app/Platform/Tenancy/WorkspaceSettings.php`, `database/migrations/2026_10_07_100001_create_workspace_settings_table.php`, `config/dashflow.php` (`tunables.guards` max_pages, max_bytes, platform_timeout_ceiling, require_https; `tunables.timeouts`), `tests/Database/Support/Cluster.php:218,266` -- setting and ceilings
- `app/Modules/Connector/Infrastructure/CurlEgressTransport.php` (reserved header names) -- reuse the list, do not copy it; `app/Platform/Audit/{AuditAction,AuditField}.php` -- new cases and fields
- `resources/js/pages/admin/HostAllowlist.vue`, `components/{AddHostForm,DataTable,ListStates,FormField,FormErrorSummary,StatusDot,SecretField,UnsavedChangesDialog,ConfirmDialog}.vue`, `ui/{badge,switch}`, `lib/{hostAllowlist,session,formDrafts,unsavedForms,announce}.ts`, `locales/labels.ts` (`hostAllowlistLabels` 576, `notEncrypted`), `pages/Placeholder.vue` -- UI patterns; `tests/js/host-allowlist.test.ts`, `shell.test.ts:69,361`, `tests/Feature/ShellTest.php:194,210`, `tests/Database/ShellTest.php:94-116` -- tests to update when the placeholder is swapped
- `tests/Architecture/dependencies.php` (Connector already owns `data_sources`; Connector may use the Platform kernel only), `tests/Database/HostAllowlistTest.php`, `tests/Unit/AuditSerializerTest.php`, `tests/Feature/DashflowTunablesTest.php` -- test patterns

## Tasks & Acceptance

**Execution:**
- [x] migrations (`data_sources`, `workspace_settings.require_https`), Connector service, contracts, audit actions and serializer, `WorkspaceSettings` accessor, real dependents implementation and binding, seeders and ownership -- persistence and rules
- [x] controller, requests, resource, routes, `ShellNavigation`, validation (Base URL, headers, limits, `require_https`) -- API and gate
- [x] `resources/js` (list page, form page, header rows, blur check, "Not encrypted" badge, states), `labels.ts`, route and nav changes, error codes -- UI
- [x] `tests/Database`, `tests/Feature`, `tests/Unit`, `tests/Architecture`, `tests/js`, `README.md` -- every matrix row

**Acceptance Criteria:**
- Given an Admin with `data_sources.manage`, when they register and edit a Data Source, then it is stored under RLS with a rising `revision`, audited in the same transaction, listed with the right badge, and a blocked host or stale revision is refused as described.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including the Docker-backed `Database` suite.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| The list endpoint returns every row's default header values although the table never shows them | medium | patch | `DataSourceResource` is shared by list and detail; only the edit form needs values. |
| Names accept NBSP, U+3000 and other `Zs` spaces that `trim()` and `btrim()` leave, so look-alike names pass the unique index; the DB CHECK and validator disagree | medium | patch | `ValidateDataSourceInput::name` rejects `\p{Cc}\p{Cf}\p{Zl}\p{Zp}` only. |
| Base URL path allows `.`/`..` segments and encoded separators | medium | patch | `DataSourceUrl::parse` path regex; a later join could leave the declared prefix. |
| `check-url` shares the `throttle:30,1` bucket with Save | medium | patch | The default key ignores the route; the form swallows the 429 from the blur check. |
| Fail-closed `requireHttps()` read, `?created=` selector injection, missing reason labels, no runbook line for the Workspace setting | medium | patch | No test makes the read throw; `querySelector` interpolates the query value; three reasons have no label. |
| Edit re-checks the allowlist and `require_https` even when the URL is unchanged | low | rejected | The criterion says an `http://` save is rejected when `require_https` is on; the strict reading is the safe one, pinned by a test and now stated in the README. |
| Allowlist check races with an entry removal | low | rejected | The egress guard re-checks the allowlist at every fetch, so a saved source on a just-removed host is still denied. |
| Identical edit bumps `revision` and audits | low | rejected | The criterion says a save increments `revision`; no no-op rule is specified for this story. |
| Credential-looking header names (`X-API-Key`) are accepted; host reason collapses to `invalid-host`; submit via Enter; client `maxlength` differs from server; unmapped error fields; two-pass validation; duplicate header names slip a pass | low | rejected | The spec names the refused headers; each other fix adds branches for cosmetic or recoverable cases. |
| Dirty-form discard replays a non-GET visit as GET | low | rejected | Same behaviour as the shared `unsavedForms` pattern used by the other forms. |
| Missing `workspace_settings` row returns `require_https` false | false | rejected | The criterion says the default is off; only a thrown read fails closed. |
| Concurrent duplicate-name race (`23505`) path is untested | medium | defer | Needs the two-connection helper already deferred; the unique index and the pre-check are each tested. |
| List returns every Data Source with no pagination; no delete or retire action; no outbox event | low | defer | No acceptance criterion; a retire action, paging and an invalidation event belong with the stories that consume Data Sources. |

## Design Notes

`auth_type` is a column now (with only `none` accepted) so Story 2.4 adds secrets without reshaping the row. The Workspace `require_https` setting has no editor here because the epic puts Workspace settings editing in Epic 8; the deployment override already exists. The blur check consults the allowlist only: DNS and address checks belong to fetch time (`EgressGuard`), so saving never does network I/O. Health, last successful call and Blocks using it are placeholders ("Checking…", "—", 0) until Stories 2.18 and 2.14 and Epic 3, kept as list-row fields so those stories only fill them.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools npm run build` -- expected: exit 0
