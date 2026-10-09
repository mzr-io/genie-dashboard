---
title: 'Bind Endpoint parameters and headers to user context'
type: 'feature'
created: '2026-10-09'
status: 'done'
baseline_commit: '9ed76a0c2093054614cd2fd788a7add21cf6c63c'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-2-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-9-register-endpoints-on-a-data-source.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-10-test-an-endpoint-and-see-the-sample-response.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-12-define-user-attributes-for-user-context-binding.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Endpoints cannot yet return only one user's data: the Binding select refuses user context, nothing resolves a member's ID, email, group or attribute into a request, and nobody can check what a given user would get (Story 2.13; FR-8, FR-11, FR-12, NFR-4, AR-10, AR-37, AR-25, AR-27, AR-35, AR-54, AD-7, AD-30, UX-DR-134, 37, 22, 276, 282). The Story 2.13 section of `epics.md` holds the acceptance criteria.

**Approach:** Four user-context bindings on parameters and headers, stored in the immutable revision with a derived `requires_user_context` and an optional `scope_by_caller`; a server-only resolver that fails closed; a `fetch_as_user` Operation, gated by `data.preview_as_user`, that returns an ephemeral sealed blob to the initiator only; and the UI (Binding options, "user context" tag, "shared data" badge, Fetch as user).

## Boundaries & Constraints

**Always:** Bindings are `user_id`, `user_email`, `user_group` (value null; it sends the name of the member's one group, and zero or several groups fail closed with `access.context_missing`, naming the binding) and `user_attribute` (value is a defined `key_id`; an unknown key is a 422 `binding-attribute-unknown`), added to `EndpointInput::BINDINGS`; the Story 2.9 refusal `binding-user-context-unavailable` goes. A new migration adds `requires_user_context` boolean (default false) and `scope_by_caller` boolean (default false) to `endpoint_revisions`, set only by `ManageEndpoints` on insert (the immutability trigger stays); `requires_user_context` is derived (true when any parameter or header uses a user binding, never client-supplied); `scope_by_caller` may be true only when it is true, else 422; it is stored and audited here, and used by Story 2.14's `ctx`. Saving a user binding creates a new revision and audits `connector.endpoint.revised` (plus counts and the two flags in the clear). The Connector may read user context only through a new Access contract `UserContext::resolve(workspaceId, membershipId, bindings)` returning values or the missing key ids, with `tests/Architecture/dependencies.php` gaining the edge `Connector => [Access]`; the Access side reads the membership ID, email (Identity), group and decrypted attributes, and the `key-data` mount is the only key worker-connector needs (the vault opens with `data`; add a non-digest availability check). The browser never supplies a bound value: a `values` entry for a user-bound name is a 422 `values.{name}` (not accepted) on every Endpoint test and Fetch as user request, and the plain `sample_fetch` of an Endpoint that requires user context is refused 422 (nothing queued), pointing to Fetch as user. `POST /api/v1/admin/data-sources/{dataSource}/endpoints/{endpoint}/fetch-as-user` (name `api.admin.data-sources.endpoints.fetch-as-user`, permissions `data_sources.manage` and `data.preview_as_user`, in `ADMIN_API_ROUTES` and the Story 1.19 matrix, throttled and rate-limited with the Story 2.10 settings) takes `membership` and the non-bound `values` and starts a `fetch_as_user` Operation (same queue, lifetime and blob store as `sample_fetch`, subject the Endpoint and its `revision`); the target must be an active member of the same Workspace (404 otherwise); a request without `data.preview_as_user` is 403 with the security event. `RunFetchAsUser` resolves the target's values server-side, then renders and sends through the same guard, transport and error ladder as Story 2.10; any missing value fails closed with `access.context_missing` before any request, naming only the missing attribute key ids; a resolved header value containing CR/LF or non-visible ASCII, and a path value failing `EndpointPath::valueAllowed`, fail closed the same way with a distinct reason; resolved values never reach logs, audit, `sync_runs`, summaries or Inertia props. The response exists only as the sealed Valkey blob read through the existing sample route for the requesting membership; nothing goes to `draft_samples`, `raw_bodies` or PostgreSQL. Each run audits `connector.fetch_as_user.performed` (new `AuditAction`; Endpoint id, target membership id, revision; no values) and emits the outbox event `connector.fetch_as_user.performed` (IDs only) in the same transaction as the enqueue, for the notifications epic to deliver. Binding options come from a read route under `data_sources.manage` listing the fixed four plus each defined key id and label. UI: a bound row shows the "user context" `Tag` and resolved text "user context", never a value, and no screen-reader text includes a value; an Endpoint without a user binding shows a "shared data" badge in text; Fetch as user shows a member picker and is `aria-disabled` with `msg:perm-denied` without `data.preview_as_user`. All strings come from the catalogue or `labels.ts`.

**Never:** The `ctx` or fetch key (Story 2.14), scheduled or Live fetches, the Block-side Preview as and tree integration (Story 3.17), notification delivery (Epic 8), a shared or stored sample for a user-bound fetch, clearing or editing attributes, a membership-removal flow, retries on a resolved-value failure, and any user-bound value in a URL, header or body that the client chose.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Save binding | Param or header bound to `user_attribute` `region` | New revision, `requires_user_context` true, audited | Unknown key: 422 |
| Shared | No user binding | "shared data" badge; flags false | N/A |
| Client value | `values` carries a bound name | Refused, nothing queued | 422 `values.{name}` |
| Plain test | Endpoint requires user context, Test endpoint | Refused, points to Fetch as user | 422, nothing queued |
| Fetch as user | `data.preview_as_user`, active member with all values | Sealed blob for the initiator; audit and outbox event | N/A |
| No permission | Missing `data.preview_as_user` | Control `aria-disabled` `msg:perm-denied` | 403, security event |
| Missing attribute | Target lacks a bound value | No request sent; `access.context_missing` naming key ids | Operation fails |
| Bad header value | Resolved value has CR/LF | Fails closed, no request | Distinct reason |
| Group count | Target in zero or several groups, `user_group` bound | No request sent; `access.context_missing` | Operation fails |
| Scope flag | `scope_by_caller` without a user binding | Not stored | 422 |
| Other Workspace | Target member of another Workspace | 404 | RLS |
| Canary | Canary attribute value | Absent from logs, audit, outbox, sync_runs, summaries, props | N/A |

</frozen-after-approval>

## Code Map

- `app/Modules/Connector/Contracts/{EndpointInput,Endpoint}.php`, `Application/{ValidateEndpointInput,ManageEndpoints,RenderEndpointRequest}.php` (`binding()` ~216 rejects `user*`; `values()` ~45; `pick()`), `Infrastructure/ConnectorAuditSerializer.php` (~52, ~59), `database/migrations/2026_10_08_130000_create_endpoints.php` (immutability trigger) -- binding model, revision insert, render
- `Application/{StartSampleFetch,RunSampleFetch,ReadSample}.php`, `Contracts/SampleFetches.php`, `Infrastructure/SampleBlobStore.php`, `app/Providers/AppServiceProvider.php:159,204` (`OperationKinds::register`) -- model `fetch_as_user` on these; `ReadSample` currently requires kind `sample_fetch`, allow both; do not change the blob sealing
- `app/Modules/Access/Contracts/{MemberAttributes,AttributeVault,AttributeKeys,Permission}.php`, `Application/ManageMemberAttributes.php`, `Infrastructure/{SodiumAttributeVault,SqlGroupDirectory,SqlMemberNames}.php` -- source of values; `Permission::DataPreviewAsUser` and `ErrorCode::ContextMissing` already exist
- `app/Platform/Audit/AuditAction.php:54-59`, `app/Platform/Outbox/Outbox.php`, `Audit::recordSecurityEvent` -- audit and outbox patterns; no consumer or Notifications module exists
- `app/Http/Controllers/Admin/EndpointController.php` (`test`, `sample`), `routes/api.php:85-86`, `app/Http/Navigation/ShellNavigation.php`, `tests/Architecture/{dependencies.php,AdminRoutesTest.php}`, `compose.yaml` (`worker-connector` secrets) -- API, gate, module edges
- `resources/js/components/{EndpointBindingRows,EndpointForm,EndpointTestPanel,Tag}.vue`, `pages/admin/DataSourceEndpoints.vue`, `lib/endpoints.ts` (`RESOLVED_TEXT` ~281), `locales/labels.ts`, `tests/js/` -- UI
- `tests/Database/` Endpoint, Sample and Cluster helpers, `tests/Security/` -- tests; do not change `endpoint_usage`, `FetchTransport` retry rules or the Data Source soft lock

## Tasks & Acceptance

**Execution:**
- [x] migration (two columns), `EndpointInput`, `ValidateEndpointInput`, `ManageEndpoints`, `Endpoint`, audit serializer -- bindings, derived flag, `scope_by_caller`
- [x] `Access\Contracts\UserContext` and implementation, `dependencies.php` edge, vault availability check, `compose.yaml` -- server-side resolution, fail closed
- [x] `RenderEndpointRequest` (reject client bound values, accept resolved ones), `StartFetchAsUser`, `RunFetchAsUser`, `OperationKinds` registration, `ReadSample`, audit action, outbox event, controller, routes, `ShellNavigation`, options route -- Fetch as user
- [x] `resources/js` (Binding options, tag, shared-data badge, Fetch as user with picker, disabled state), `lib/endpoints.ts`, `labels.ts`, `README.md` -- UI
- [x] `tests/Unit`, `tests/Database`, `tests/Architecture`, `tests/Security`, `tests/js` -- every matrix row, CR/LF, RLS leak, canary scan

**Acceptance Criteria:**
- Given a user-bound Endpoint, an Admin with `data.preview_as_user` and a target with every value, when Fetch as user runs, then the response is readable only by the initiator from the sealed blob, nothing else stores it, and audit and outbox hold IDs only.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including the Docker-backed `Database` suite.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| Resolver opened every attribute, not only bound keys | medium | patch (fixed) | One corrupt unrelated value failed fetches; now bound keys only |
| fetch_as_user blob readable after initiator loses data.preview_as_user | medium | patch (fixed) | ReadSample now requires the permission; test added |
| `missing` truncated mid-id, '' instead of null | low | patch (fixed) | Cut at comma boundary, null when empty |
| Error card text wrong for member_unavailable / invalid value | medium | patch (fixed) | Branches on reason; vitest added |
| Picker stale selection and out-of-order responses | medium | patch (fixed) | Request counter, stale selection cleared |
| Hidden scope_by_caller state after removing bindings | low | patch (fixed) | Reset on last user binding removed |
| assertReadable, member_unavailable untested | medium | patch (fixed) | DB tests added |
| Fetch as user on non-user-context Endpoint notifies member | maybe-false | defer | Unverified product intent |
| Member picker capped at 50, binding-options unthrottled, rate-limit sharing/binding-options filter untested | low | defer | Low impact; recorded |
| Event emitted at enqueue not at fetch | false | rejected | Spec accepts this |
| Non-ASCII header values fail closed | false | rejected | By design (distinct reason) |
| Validator instance state, 429 wording, missing security event without workspace, task-list nits, other minor | low | rejected | Negligible or fix adds complexity |

## Design Notes

Decided here: bindings are four string kinds with the attribute `key_id` as the value, so a revision never holds a user value; resolution is a separate Access contract (rather than a Connector table read) because Access owns attributes, groups and the keys, and `Connector => Access` is the only new edge. A plain Test endpoint refuses user-bound Endpoints instead of sending blanks, which would look like a working shared fetch. `scope_by_caller` is stored now because the revision is immutable and Story 2.14's `FetchKeyResolver` needs it. Fetch as user shares the sample blob and its read route so no second unencrypted path exists. The `requires_user_context` publish rule belongs to Epic 3.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools npm run build` -- expected: exit 0
