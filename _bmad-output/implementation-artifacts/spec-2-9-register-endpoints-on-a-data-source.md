---
title: 'Register Endpoints on a Data Source'
type: 'feature'
created: '2026-10-08'
status: 'done'
baseline_commit: '38028ebaed931575596b5375f7e824adf312b68d'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-2-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-3-register-and-edit-a-data-source.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-8-prevent-overwriting-with-a-soft-lock.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A Data Source can be registered and tested but nothing describes the requests Blocks will later select, so there is no Endpoint, no parameter model and no guarantee that a request only ever reads (Story 2.9; FR-11, FR-8 parameter model, NFR-4, AR-9, AR-44, AR-25, AR-14, UX-DR-134, 27, 28, 23, 26, 37, 22, 282). The Story 2.9 section of `epics.md` holds the acceptance criteria.

**Approach:** Tenant tables `endpoints` (a current-revision pointer) and `endpoint_revisions` (immutable rows holding the method, a path parsed once into a URL AST, parameter and header bindings and an optional typed JSON body template), an Admin API and an Endpoints tab on the Data Source page behind the Story 1.19 gate, strict path, header and method validation, and a read-only flag with risk confirmation for POST.

## Boundaries & Constraints

**Always:** Table `endpoints` (UUIDv7 key, non-null `workspace_id`, `data_source_id` with a composite FK `(workspace_id, data_source_id)` to `data_sources`, `revision` integer for the current revision number, `current_revision_id`, timestamps, `created_by_membership_id` without cross-module FK) and table `endpoint_revisions` (UUIDv7 key, non-null `workspace_id`, composite FK `(workspace_id, endpoint_id)`, `revision` integer starting at 1 and unique per Endpoint, `method` in `GET`/`POST`, `path_template` text, `path_ast` jsonb, `params` jsonb, `headers` jsonb, `body_template` jsonb nullable, `read_only_query` boolean, `created_at`, `created_by_membership_id`) are Connector tenant tables with `ENABLE` and `FORCE ROW LEVEL SECURITY`, the standard policy, created as `migrator`; `app` has SELECT, INSERT and UPDATE on `endpoints` and SELECT and INSERT on `endpoint_revisions`, and a trigger makes every `endpoint_revisions` UPDATE and DELETE fail (immutability, tested); both tables are added to the Cluster seeders and the leak tests, and `endpoint_usage` is not touched. Creating an Endpoint writes the Endpoint, revision 1 and the pointer in one transaction with `connector.endpoint.created`; every later save writes a new revision (number + 1) and moves the pointer in one transaction with `connector.endpoint.revised`, older revisions are never changed, and a stale `revision` returns 409 `connector.revision_conflict` with the current state (an edit does not change the Data Source `revision`). Audit data (through the Connector serializer, closed `AuditAction` cases `connector.endpoint.created`, `connector.endpoint.revised`, `connector.endpoint.read_only_flag_set`) carries ids, the method, revision numbers, counts and a keyed hash of the path and bindings, never the path, values or headers in the clear. The API lives under `/api/v1/admin/data-sources/{dataSource}/endpoints` (`GET` list, `POST` create, `GET {endpoint}` current revision, `PUT {endpoint}` save with `revision`; names `api.admin.data-sources.endpoints.*`; throttled like the Data Source API), permission `data_sources.manage`, mapped in `ShellNavigation::ADMIN_API_ROUTES` and the Story 1.19 matrix; the Endpoints tab route `admin.data-sources.endpoints` is in `ADMIN_PAGES`; a request without the permission is 403 with the security event; another Workspace's Data Source or Endpoint is 404. Methods: only `GET` and `POST` are accepted, any other (PUT, PATCH, DELETE, lower-case, empty) is a 422 `method-not-allowed`; `POST` is accepted only with `read_only_query` true and a request field `confirm_read_only` true (the risk confirmation), otherwise 422, and the first time a revision sets the flag to true `connector.endpoint.read_only_flag_set` is audited in the same transaction. The path (`EndpointPath::parse`, parsed once into an AST of literal and `{name}` placeholder segments and stored with the template) must start with a single `/`, may not be absolute (`scheme:`, `//host`), protocol-relative, contain `\`, `?`, `#`, a control character, an empty segment, a `.` or `..` segment (also after percent-decoding) or an encoded separator `%2f` or `%5c`, and placeholder names match `[A-Za-z][A-Za-z0-9_]{0,63}` and are unique; every placeholder needs a declared parameter and the 422 names it. `EndpointPath::render(ast, values)` (not wired to any fetch yet, covered by tests) percent-encodes each value as one segment and refuses a value containing `/`, equal to `.` or `..`, or empty, naming the parameter; a fixed value that already breaks that rule is rejected on save with the parameter named. Parameters are a list of `{name, binding, value}` with bindings `fixed` (value required, string), `date_range_from`, `date_range_to`, `period_start`, `period_end` (value stored null, resolved at fetch time and out of scope); user-context bindings are not offered or accepted; names are unique, query names match a token pattern, a name that is a path placeholder is a path parameter, a name referenced by the body template is a body parameter, any other is a query parameter, and parameters that match nothing for GET are query parameters. Endpoint headers are `{name, binding, value}` rows with the same bindings: names are HTTP tokens that are not reserved or credential names (reuse `ReservedHeaders`), and any bound header value containing CR, LF or non-visible ASCII (outside 0x20 to 0x7E) is a 422 with nothing stored. A POST body template is a JSON value (parsed with `LosslessJson`, size-limited) in which a parameter may appear only as a whole JSON value written `{"$param": "name"}` (never interpolated inside a string or used as a key), every referenced name must be a declared parameter and a GET Endpoint may not have a body template; typed positions mean the fixed value keeps its type (string, number as an exact lexeme, boolean) and date and period bindings produce ISO date strings. The Endpoint resolves only against the Data Source base URL (never an absolute URL). The Endpoints tab (a new `DataSourceTabs` navigation on the Data Source pages: Settings and Endpoints) lists Endpoints (method, path, revision, updated) with search, count caption, "+ Add endpoint" as the only primary button, and the skeleton, empty (`list-empty`), no-match and failure states, and an add and edit form with the `method-prefix` GET/POST segment joined to a mono path input and a `parameters-table` (Name mono, Binding select, Value input, remove; a bound row shows its resolved placeholder text in muted mono), header rows, a body template editor for POST, field-level 422 errors with `aria-invalid` and focus on the first invalid field, and the required `post-readonly` checkbox when POST is chosen with Save `aria-disabled` and its reason adjacent until it is ticked; ticking opens a risk confirmation and the page announces saves politely and highlights and focuses the saved row. All strings come from the catalogue or `labels.ts`.

**Never:** Sending any request, resolving a period or user context, or testing an Endpoint (Story 2.10); user-context bindings or `endpoint_usage`; deleting an Endpoint or a revision; PUT, PATCH, DELETE or any other method; an absolute or protocol-relative path; string interpolation in a body template; logging or auditing paths, values or headers in the clear.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Create GET | `GET /api/v2/finance/revenue`, saved Data Source | `endpoints` row, revision 1, pointer set; `created` audited; listed | N/A |
| Edit | Valid change, current `revision` | New immutable revision, pointer moves, older rows unchanged; `revised` audited | Stale `revision` is 409 with current state |
| Immutability | UPDATE or DELETE on a revision | Fails | N/A |
| Placeholder | `/customers/{id}` with a parameter `id` | AST built once; segment percent-encoded on render | Value with `/`, `.`, `..` or empty: 422 naming the parameter |
| Missing parameter | Placeholder without a declared parameter | 422 naming it | N/A |
| Bad header | Bound value with CR/LF or non-visible ASCII; reserved name | 422; nothing stored | N/A |
| POST | Method POST | Required `post-readonly` checkbox; Save `aria-disabled` with reason until ticked | Without `read_only_query` and `confirm_read_only`: 422 |
| Read-only flag | First revision with the flag true | Risk confirmation; `read_only_flag_set` audited | N/A |
| Other methods | PUT, PATCH, DELETE, other | 422 `method-not-allowed` | N/A |
| Absolute path | `https://other.host/x`, `//host/x`, no leading `/` | 422 | N/A |
| Body template | `{"$param": "from"}` at a value position | Accepted; typed position kept | Interpolated string, key or unknown name: 422 |
| Bindings | Fixed, date range, period; user context | Accepted; user context refused | N/A |
| Permission | No `data_sources.manage` | 403 on all writes; security event | Story 1.19 |
| Isolation | Another Workspace | Zero rows; 404 | RLS |
| Load states | Cold load, failure, empty, no match | Skeletons, Retry, `list-empty`, `list-no-match` | N/A |

</frozen-after-approval>

## Code Map

- `app/Modules/Connector/Application/{ManageDataSources,ValidateDataSourceInput}.php` (locked update with `revision`, `$fail` validation pattern, header rule at ValidateDataSourceInput:274-276), `Contracts/{DataSourceUrl,ReservedHeaders,DataSourceRevisionConflict,DataSourceNotFound,InvalidDataSource,FetchRequest}.php` (`FetchRequest::for` already takes an `endpointId`), `Infrastructure/ConnectorAuditSerializer.php`, `app/Platform/Audit/AuditAction.php` (Connector cases at L48-53), `app/Platform/Json/LosslessJson.php` -- patterns to mirror and tools to reuse
- `app/Http/Controllers/Admin/DataSourceController.php`, `Requests/Admin/DataSourceRequest.php`, `Resources/DataSourceResource.php`, `routes/api.php:60-76`, `routes/web.php:66-71` (`admin.data-sources.edit` renders `admin/DataSourceForm`), `app/Http/Navigation/ShellNavigation.php` (`ADMIN_PAGES` 60, `ADMIN_API_ROUTES` 71-100), `tests/Database/AdminAccessTest.php`, `tests/Architecture/dependencies.php:49-52` (`endpoints`, `endpoint_revisions` already Connector-owned) -- API, gate and ownership
- `database/migrations/2026_10_07_170001_create_data_sources.php`, `2026_10_08_120000_add_lock_epoch_to_data_sources.php` (never-decreasing trigger pattern), `tests/Database/Support/Cluster.php` (`seedTenantRow`), `tests/Database/{TenantLeakCoverageTest,RowLevelSecurityTest}.php` -- RLS, immutability trigger, seeders
- `resources/js/pages/admin/{DataSources,DataSourceForm}.vue`, `components/{FormField,DataTable,ListStates,SegmentedControl,NativeSelect,ConfirmDialog,GatedAction,BlockedReason,Tag,RequiredNote,FormErrorSummary,PageHeader}.vue`, `lib/{dataSources,announce}.ts`, `locales/{en,labels}.ts` (`post-readonly` at en.ts:27-30; the catalogue is pinned to 89 keys), `tests/js/{data-sources,gated-action,catalogue,primary-button}.test.ts` -- UI patterns (one primary button per view)
- `tests/Database/DataSourcesTest.php` (helpers `dsAdmin`, `dsBody`, `dsCreate`, `dsUpdate`, `DS_HEADERS`), `tests/Unit/AuditSerializerTest.php`, `tests/Feature/ApiErrorCodesTest.php` -- test patterns

## Tasks & Acceptance

**Execution:**
- [x] migration (`endpoints`, `endpoint_revisions`, RLS, immutability trigger, grants), `EndpointPath` (AST, parse, render), validation (methods, bindings, headers, body template), Connector service (create, revise, list, find, CAS), audit actions and serializer, seeders and ownership -- model and rules
- [x] controller, requests, resources, routes, `ShellNavigation` entries, error codes -- API and gate
- [x] `resources/js` (Endpoints tab and navigation, list states, `method-prefix`, `parameters-table`, header rows, body template editor, POST read-only checkbox and risk confirmation), `lib/endpoints.ts`, `labels.ts` -- UI
- [x] `tests/Unit`, `tests/Database`, `tests/Architecture`, `tests/js`, `README.md` -- every matrix row, path-rendering and parsing vectors, immutability

**Acceptance Criteria:**
- Given a saved Data Source, when an Admin creates and then edits a GET Endpoint with placeholders and bindings, then revision rows are immutable and increase, the pointer follows, both writes are audited, and POST, other methods, bad paths and bad header values are refused as described.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including the Docker-backed `Database` suite.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| The pointer FK checks only the Workspace, so `current_revision_id` can name another Endpoint's revision or a number that differs from `endpoints.revision` | high | patch | The migration's pointer FK is `(workspace_id, current_revision_id)`; `app` has UPDATE on `endpoints`. |
| The losing concurrent revise re-checks the join after `FOR UPDATE OF e` against a stale revision row and can get 404 instead of 409 | medium | patch | `fetch(..., lock: true)` locks through a join; after the winner moves the pointer the join no longer matches. |
| The interpolation heuristic refuses legitimate bodies such as GraphQL `{"query":"{revenue{total}}"}`; a top-level JSON `null` template is stored as SQL NULL and dropped | medium | patch | `EndpointBodyTemplate::INTERPOLATION` matches any `{identifier}`; a `null` parse equals "absent". |
| The repeated percent-decode accepts after eight rounds; a `..;x` segment passes | medium | patch | `EndpointPath` loop bound and the exact `.`/`..` compare. |
| Unnamed `throttle:30,1` shares a bucket with the Data Source update; params errors land on the wrong row after a dropped row; `_`/`!` search escapes and revise rollback are untested; Cancel discards a dirty form; 403/404 shown as retryable; placeholder typing adds stray rows | medium | patch | Same shared-bucket cause as Story 2.3; index shift in `ValidateEndpointInput`; no revise failure test; no dirty prompt. |
| Endpoint saves ignore the Data Source soft lock from Story 2.8 | medium | defer | The spec exempts Endpoints implicitly; whether an Endpoint edit must honour the Data Source lock is a product decision for the human. |
| Concurrent revise has no two-connection test | medium | defer | Needs the two-connection helper already deferred in Stories 1.23 and 2.3. |
| Fixed values are always strings, so a number or boolean cannot be expressed at a typed body position | medium | defer | Nothing renders a request yet; the type model belongs with Story 2.10 or 2.13 when values are resolved, and needs a value-type field on parameters. |
| Duplicate keys in a body template collapse silently | false | rejected | `LosslessJson` rejects duplicate keys as a parse error before `walk()` runs. |
| No paging or cap on the Endpoint list; past revisions cannot be read or restored; audit hash covers params, headers and body together; read-only flag audited on every false-to-true transition; parameter names differing by case; `$this->all()` merges the query string; byte versus character length; no `no-store` on error responses; `TRUNCATE` and `DELETE` | low | rejected | No acceptance criterion; `app` is not granted DELETE or TRUNCATE by default privileges; each fix adds branches. |

## Design Notes

Immutable revisions plus a pointer let Block Versions later pin an exact request definition; the Data Source `revision` stays out of Endpoint edits because Fetch Keys carry both revisions separately. The path is parsed once into an AST so encoding and rejection happen per position at render time instead of by string substitution, and the body template only allows whole-value parameter references so a bound value can never change the shape of the JSON. Endpoints have no name or delete here because the epic gives neither; the list shows the method and path.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools npm run build` -- expected: exit 0
