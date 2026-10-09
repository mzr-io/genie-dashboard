---
title: 'Define user attributes for user-context binding'
type: 'feature'
created: '2026-10-09'
status: 'done'
baseline_commit: '11402fb3baea6afa815a11281d219b0a430b5017'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-2-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-22-assign-roles-and-admin-permissions.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-23-organise-users-into-groups.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-10-test-an-endpoint-and-see-the-sample-response.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Endpoints cannot yet return only each user's own data, because there is no catalogue of attributes (such as a region or an employee number) and no place to store a value per member, and nothing guarantees those values are protected or that nobody edits their own (Story 2.12; FR-8, FR-67 attributes part, NFR-4, AR-6, AR-26, AR-37, AR-25, AR-36, AD-4, AD-30, UX-DR-115, 23, 263, 282). The Story 2.12 section of `epics.md` holds the acceptance criteria.

**Approach:** In the Access module, tenant tables `user_attribute_keys` (an immutable key id and an editable label) and `user_attributes` (one encrypted value per member and key, with a blind index), an Admin API and a System settings > User attributes page for keys under `settings.manage`, per-member value editing in User configuration under `users.manage`, self-edit refusal, audit with hashes only, an outbox event in the same transaction, and a consumer that deletes a member's values when the membership is removed.

## Boundaries & Constraints

**Always:** Table `user_attribute_keys` (UUIDv7 key, non-null `workspace_id`, `key_id` text matching `[a-z][a-z0-9_]{0,47}` and unique per Workspace, never changed after creation (a trigger refuses any UPDATE of `key_id` and of `value_type`), `label` 1 to 64 characters without control or zero-width characters and unique per Workspace case-insensitively, `value_type` in `text`, `identifier`, `integer`, `revision` integer starting at 1, timestamps) and table `user_attributes` (UUIDv7 key, non-null `workspace_id`, composite FKs `(workspace_id, membership_id)` and `(workspace_id, attribute_key_id)`, `value_ciphertext` bytea, `value_blind_index` text, `blind_index_version` integer, `updated_at`, unique `(membership_id, attribute_key_id)`) are Access tenant tables with `ENABLE` and `FORCE ROW LEVEL SECURITY`, the standard policy, created as `migrator`; `app` has SELECT, INSERT and UPDATE only, and values are removed through a `SECURITY DEFINER` function owned by `migrator` and executable by `app` that reads `current_setting('app.workspace_id', true)`, takes the membership id, and refuses when the setting is unset; both tables are added to the Cluster seeders, the leak tests and the ownership list (they are already listed under Access). Validation by `value_type`, applied after trimming surrounding ASCII spaces and always refused when empty or longer than the limit: `text` is 1 to 256 characters of visible Unicode without control characters; `identifier` is 1 to 128 characters of `[A-Za-z0-9._-]`; `integer` is an optional minus sign and 1 to 18 digits with no leading zero except `0`; a failure is a 422 with the field named and nothing stored. Values are stored as sealed text only: libsodium `secretbox` under the 32-byte `data` key from the `key-data` mount (`dashflow.secrets.data_key_path`, already a setting), with the Workspace, membership and key id inside the sealed payload and verified on read; the blind index is a hex HMAC-SHA256 under the `digest` key from the `key-digest` mount (new setting `dashflow.secrets.digest_key_path`, default `/run/secrets/key-digest`, base64, 32 bytes, documented in `.env.example`, README and `compose.yaml`) over `attr|` + Workspace id + `|` + key id + `|` + the validated value, stored with a `blind_index_version` (1), so equal values for one key can be found without decrypting. Both keys fail closed: when either key is unavailable, saving or reading a value answers a 503 `access.attributes_unavailable` (added to `Access\Contracts\ErrorCode` and the generated TS enum) and nothing is stored. The key catalogue API is `GET`, `POST` and `PUT {key}` (label only, with the current `revision`, stale returns 409) under `/api/v1/admin/user-attributes` (names `api.admin.user-attributes.*`, permission `settings.manage`, throttled like the host allowlist); the value API is `GET` and `PUT` `/api/v1/admin/members/{membership}/attributes` (names `api.admin.members.attributes.*`, permission `users.manage`), where `GET` returns each defined key with its value (decrypted for the Admin who may set it) and `PUT` sets a map of `key_id` to value for the keys supplied (an absent key is unchanged; an empty value is a 422, there is no way to clear a value in this story); a key id that is not defined is a 422; all routes are in `ShellNavigation::ADMIN_API_ROUTES`, the page `admin.settings.user-attributes` is in `ADMIN_PAGES` under `settings.manage`, and all are in the Story 1.19 matrix. Editing the caller's own membership's attributes is refused 403 `access.self_change_forbidden` and recorded through `Audit::recordSecurityEvent` (as `MemberController` does for roles); a membership of another Workspace is 404; a request without the permission is 403 with the denial recorded. Creating a key audits `access.attribute_key.created` (key id and value type) and renaming audits `access.attribute_key.renamed` (new `AuditAction` case; key id and a hash of the label), both in the same transaction as the change; setting values audits `access.attribute.changed` per changed key (membership and key ids, and `attribute_value` as a keyed hash only, never the value) and emits the outbox event `access.attribute.changed` (IDs and enums only: membership id, key id) in the same transaction through the Access serializer; setting an identical value is a no-op with no audit and no event. An Access outbox consumer (registered in `OutboxConsumers`, name `access.attributes_on_membership_removed`) handles `access.membership.removed` by deleting that member's values through the definer function; the removal flow itself does not exist yet, so tests emit the event. The System settings page gets a User attributes link and a `UserAttributes` page (a data table of key id, label, type, with search, count caption, "+ Add attribute" as the only primary button, an inline add form with the key id and type chosen once and a label, inline label edit, five skeleton rows when cold, `list-empty` with that action, a load-failure state with Retry, field errors with `aria-invalid` and focus on the first invalid field, and the saved row highlighted, focused and announced politely); the Users page gets an Attributes action on members other than the Admin's own row (shown only with `users.manage`) opening an inline editor with one labelled input per defined key, showing current values, saving the changed ones with field errors, and a polite announcement. Values never appear in logs, audit rows, outbox data, Inertia props or error messages. All strings come from the catalogue or `labels.ts`.

**Never:** Editing or deleting a key id, changing a key's type, deleting a key, storing or logging a plaintext value, showing values to anyone without `users.manage`, letting anyone edit their own attributes, using a per-key regex, inventing a cap on keys or members, or building the membership-removal flow or the User-context binding (Story 2.13).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Add key | `settings.manage`, valid key id, label, type | Row created; `access.attribute_key.created` audited | Duplicate key id or label: 422 |
| Immutable | Any attempt to change `key_id` or `value_type` | Refused (API ignores them; DB trigger fails an UPDATE) | N/A |
| Rename | New label, current `revision` | Label changed; `access.attribute_key.renamed` audited | Stale revision: 409 |
| Set value | `users.manage`, valid value | Sealed value and blind index stored; audit with hash only; outbox event | N/A |
| Bad value | Empty, too long, wrong shape for the type | 422 naming the field; nothing stored | N/A |
| Unchanged | Same value again | 200 no-op; no audit, no event | N/A |
| Self-edit | Own membership | 403 `access.self_change_forbidden`; security event recorded | N/A |
| No permission | Missing `settings.manage` or `users.manage` | 403; denial recorded | Story 1.19 |
| Removal | `access.membership.removed` consumed | Member's values deleted | N/A |
| Keys missing | `key-data` or `key-digest` unavailable | 503 `access.attributes_unavailable`; nothing stored | N/A |
| Isolation | Another Workspace | Zero keys and values; 404 on ids | RLS |
| Undefined key | Unknown `key_id` in a value update | 422 | N/A |
| Canary | Canary value | Absent from logs, audit, outbox, props, error messages | N/A |

</frozen-after-approval>

## Code Map

- `app/Modules/Access/Application/{ChangeMemberAccess,ManageGroups,MembershipLocks}.php` (transaction, lock, `Audit::record`, `Outbox::emit`, `SelfChangeForbidden` ~66), `Contracts/{MemberEditor,SelfChangeForbidden,EditorNotAuthorized,MembershipNotFound,ErrorCode}.php`, `Infrastructure/{SqlMemberDirectory,SqlGroupDirectory,AccessAuditSerializer}.php` (already allowlists `attribute_key` as Enum and `attribute_value` as Hashed; add `attribute_key_id`), `app/Providers/AppServiceProvider.php:114` (`OutboxConsumers`) -- patterns and registration
- `app/Http/Controllers/Admin/{MemberController,GroupController}.php` (`recordRefusal` ~231, `AdminApiError`), `app/Http/Requests/Admin/`, `routes/api.php:30-49`, `routes/web.php:79-81`, `app/Http/Navigation/ShellNavigation.php` (`ADMIN_PAGES` ~59, `ADMIN_API_ROUTES` 72), `tests/Database/AdminAccessTest.php`, `tests/Architecture/AdminRoutesTest.php` -- API, pages and gate
- `app/Platform/Audit/{AuditAction,AuditField,AuditHasher}.php` (`AccessAttributeChanged`, `AccessAttributeKeyCreated`, `AccessMembershipRemoved` pre-registered at 35-42; the hasher key derives from APP_KEY, not the digest key), `app/Platform/Outbox/{Outbox,OutboxConsumer,OutboxConsumers}.php` (no consumer exists yet) -- audit and outbox
- `app/Modules/Connector/Infrastructure/{SampleBlobStore,SecretSettings}.php` (secretbox under `key-data`, `dataKeyPath()`), `config/dashflow.php:160-170` (`secrets`), `compose.yaml` (`key-data`, `key-digest` mounted on `web`), `tests/Architecture/ComposeKeysTest.php` -- key pattern; Access must not import Connector: write its own small reader
- `database/migrations/2026_10_07_140000_create_user_groups_and_group_members.php` (composite FKs, RLS, definer delete function), `tests/Database/Support/Cluster.php` (`seedTenantRow` ~240-252), `tests/Database/TenantLeakCoverageTest.php`, `tests/Architecture/dependencies.php:47` -- tables and seeders
- `resources/js/pages/admin/{SystemSettings,Users,HostAllowlist}.vue`, `components/{MemberAccessEditor,AddHostForm,DataTable,ListStates,FormField,ConfirmDialog}.vue`, `lib/{members,groups,hostAllowlist}.ts`, `locales/labels.ts`, `tests/js/{groups,member-access,host-allowlist}.test.ts` -- UI patterns (one primary button per view)
- `tests/Database/{GroupsTest,MemberAccessTest}.php` (helpers `grp*`, `acc*`; self-edit security event test ~314; rollback on audit failure ~438), `tests/Database/OutboxRelayTest.php` -- test patterns

## Tasks & Acceptance

**Execution:**
- [x] migration (two tables, triggers, RLS, grants, definer function), Access contracts and service (create key, rename, set values, read values), attribute vault (secretbox plus blind index, fail closed), settings and compose, audit action and serializer fields, outbox event, consumer and its registration, error code, seeders -- model and rules
- [x] controllers, requests, resources, routes, `ShellNavigation` entries, self-edit refusal and security event -- API and gate
- [x] `resources/js` (User attributes page, System settings link, member Attributes editor), `lib/userAttributes.ts`, `labels.ts`, generated error codes -- UI
- [x] `tests/Unit`, `tests/Database`, `tests/Architecture`, `tests/Security`, `tests/js`, `README.md` -- every matrix row including a canary scan over Postgres, logs, audit, outbox and props

**Acceptance Criteria:**
- Given an Admin with `settings.manage` and another with `users.manage`, when keys are defined and values set for other members, then keys are immutable, values are stored sealed with a blind index, audit holds hashes only, the outbox event is emitted in the same transaction, self-edit is refused with a security event, and a removed membership's values are deleted by the consumer.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including the Docker-backed `Database` suite.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| Search escapes with addcslashes but SQL says `escape '!'` | medium | patch (fixed) | Underscored key ids never matched; fixed with test |
| `values` map unbounded | low | patch (fixed) | Added `max:200` |
| Composite same-Workspace FKs untested | medium | patch (fixed) | Database test added |
| Audit/outbox atomicity untested | medium | patch (fixed) | Rollback tests for value and key create |
| Integer max 19 vs regex 18 digits | false | rejected | 18 digits plus sign is 19 characters; consistent |
| Audit hash of low-entropy values | low | rejected | Keyed HMAC per architecture; spec-chosen |
| No way to clear a value | false | rejected | Spec decision in Design Notes |
| Rename stale revision with unchanged label returns 409 | low | rejected | Fix adds branching; unlikely |
| Membership status not checked on set | maybe-false | defer | Unverified; depends on membership status model |
| Subject-only fallback in removal consumer untested | low | defer | Producer does not exist yet |
| JSON number for integer attribute refused | low | rejected | String-typed API by design |
| vault not asserted when no keys/values | low | rejected | No secret involved; 200 with empty result harmless |
| Key-file re-read per call, no cipher key id, ops docs, other minor items | low | rejected | Negligible or design choices without named harm |
| Post-save refetch failure shows error in UI | low | rejected | Edge; guard adds complexity |

## Design Notes

The architecture fixes only the two table names, so the columns, the three value types and the blind-index recipe are decisions made here. Value types replace per-key regular expressions to avoid unbounded or hostile patterns while still letting a key reject malformed values. The blind index lets Story 2.13 and later group or compare members by equal attribute values without decrypting; it uses the `digest` key the architecture reserves for exactly this kind of HMAC. Values can be set but not cleared because the criterion treats an empty value as invalid; removing a member's values happens only on membership removal. Concurrent writes to one member's attributes are last-write-wins, with each change audited.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools npm run build` -- expected: exit 0
