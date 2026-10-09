---
title: 'Add authentication and write-only secrets to a Data Source'
type: 'feature'
created: '2026-10-07'
status: 'done'
baseline_commit: '95b7dfbe657a0dbbee67c3ac6d4fc83d82b2e8b1'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-2-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-3-register-and-edit-a-data-source.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-2-guard-every-outbound-url-with-egressguard-and-operator-private-range-grants.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Data Sources can describe an API but not authenticate to it, and no code exists that stores a credential so that it can never be read back by the web tier (Story 2.4; FR-9, NFR-4, AR-26, AR-44, AR-50, AR-54, AR-25, AR-31, AD-19, UX-DR-29, 207, 23, 248, 282). The Story 2.4 section of `epics.md` holds the acceptance criteria.

**Approach:** Human-approved design (Option A, sealed box): a `SecretVault` port whose `web` implementation only seals values to a platform X25519 public key, so only `worker-connector`, which alone holds the private key in `key-cred`, can open them. A `secrets` tenant table holds the sealed values, the Data Source form gains an Authentication section with write-only secret fields behind password re-confirmation, and a versioned `FetchRequest` carries `secret_ref`s and a credential scheme only.

## Boundaries & Constraints

**Always:** `SecretVault` (Connector contract, `seal`, `status`, `open`) has one driver in this story, `local` sealed box using libsodium `sodium_crypto_box_seal` (`ext-sodium` declared in `composer.json`). The public key and key version come from settings flagged `pending_input` with no default (`dashflow.secrets.cred_public_key`, base64, and `cred_key_version`; documented in `.env.example` and README, passed through `compose.yaml` to every role as non-secret values); when the public key is unset or invalid, saving a secret is refused with a clear 503-class error and nothing is stored. `open` is available only where the private key file at the `key-cred` mount path is readable (path from a setting, default the `/run/secrets/key-cred` mount); on `web` it fails with a typed `KeyringUnavailable` exception and the AR-50 mounts in `compose.yaml` stay unchanged. The sealed payload is a small versioned envelope (`purpose`, `workspace_id`, `data_source_id`, `slot`, value) and `open` verifies the context it is asked for, so a value copied to another Workspace, Data Source or slot is refused. Table `secrets` (UUIDv7 key, non-null `workspace_id`, `data_source_id`, `slot`, `purpose` fixed to `cred`, `key_version`, `key_ref` (fingerprint of the public key used, the "wrapped DEK ref" of the criterion), `ciphertext` bytea, `updated_at`) is a Connector tenant table with `ENABLE` and `FORCE ROW LEVEL SECURITY`, the standard policy, unique `(data_source_id, slot)`, `app` SELECT, INSERT and UPDATE only, and removal through a `SECURITY DEFINER` function owned by `migrator` and executable by `app` that reads the Workspace setting (as the allowlist function); the table is in Cluster seeders, the ownership list and the leak tests. `data_sources` gains `api_key_name` and `api_key_placement` (`header` or `query`); the `auth_type` values `none`, `api_key`, `bearer` and `basic` are accepted (`oauth2_client_credentials` stays refused until Story 2.7). Slots: `api_key` uses `api_key`; `bearer` uses `bearer_token`; `basic` uses `basic_username` and `basic_password`; a default header marked secret uses `header:{name}` and its value is kept out of `default_headers` (which keeps only the name and a `secret: true` flag). Switching `auth_type` removes the secrets of slots no longer used, through the removal function. The API returns for each secret slot only `{configured, updated_at}` and never a value, ciphertext or hash, in any response, Inertia prop, log, trace or audit row; the `GET` detail returns the auth type, API-key name and placement, header names with their secret flag and each slot's status. A write supplies a value only for slots being set or replaced (an absent slot is left unchanged; an empty value is a 422 `secret-required`); changing or replacing any secret, or switching `auth_type`, requires `confirm_password` (same rules, throttle key and error shapes as `MemberController::confirmPassword`; a wrong password is 422, a throttled one 429, and nothing is stored), bumps the Data Source `revision` (the later `data_source_revision`) and audits `connector.data_source.secret_changed` (slot enum, purpose, key version, action `set` or `replaced` or `removed`, and a keyed hash of the value only) in the same transaction as the change; a stale `revision` is 409 as in Story 2.3. An API-key placed in the query shows a visible warning in the form and the stored placement; a value is rejected 422 if it contains CR, LF or non-visible ASCII (as header values are), and a header name used for the API key must be a token that is not reserved. Form and draft endpoints that must not carry secrets (`check-url`, and a reusable `RejectsSecretValues` guard for the Draft endpoints of later epics) answer 422 `secret-values-refused` when the payload contains a secret-valued field (any field named or flagged as a secret value). A versioned `FetchRequest` value object (Connector contract) carries Workspace, Data Source and Endpoint ids, a sanitized URL template, parameter names, the credential scheme (`none`, `api_key_header`, `api_key_query`, `bearer`, `basic`) and a list of `secret_ref`s (id, slot, purpose), and its serialisation never contains a value; a contract test fails if any secret value or ciphertext reaches it. Permission is `data_sources.manage` on the existing routes (Story 1.19 denial with 403 and no disclosure otherwise). The form's Authentication section shows only the fields of the chosen type with masked `••••••••` fields, uses `SecretField` for a saved secret ("•••••••• · set 2 Oct 2026 · Replace", announced as "Secret set on 2 Oct 2026", no reveal control, "Replace token" clears the field and requires a new value), asks for the password only when a secret value or auth type changes, marks default headers as secret with a per-row control, and never autosaves or restores an unsaved secret value (the form-draft and unsaved-changes storage excludes secret fields and the draft is discarded on session expiry). Canary secrets used in a test run must not appear in logs, traces, audit rows, API responses or Inertia props, and the Scrubber covers the new field names. All strings come from the catalogue or `labels.ts`.

**Never:** Returning, logging, auditing or autosaving a secret value, ciphertext or reversible form; a reveal control; decrypting on `web`; changing the AR-50 key mounts; any outbound call, connection test or token fetch (Stories 2.5 and 2.7); a Workspace DEK service or key rotation tooling (documented, not built); accepting `oauth2_client_credentials`; sending secrets in query strings or URLs from the app.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Set secret | `bearer`, token, correct password, current `revision` | Sealed row stored; response `{configured, updated_at}`; `revision` + 1; `secret_changed` audited with hash only | N/A |
| Reopen | Saved secret | "•••••••• · set <date> · Replace"; no value anywhere | N/A |
| Replace | New value, correct password | Row updated; audited `replaced`; `revision` + 1 | Empty value 422 `secret-required` |
| Wrong password | Secret change without or with bad password | 422 (429 when throttled); nothing stored | N/A |
| Switch auth | `bearer` to `none` or `basic` | Unused slots removed; audited `removed`; password required | N/A |
| API key in query | Placement `query` | Saved; warning shown and stored | N/A |
| Secret header | Header row marked secret | Value sealed under `header:{name}`; only the name and flag in `default_headers` | CR/LF 422 |
| web decrypt | `SecretVault::open` on `web` | `KeyringUnavailable`; wrong context on the worker is refused | N/A |
| Keys unset | Public key missing or invalid | Saving a secret refused with a clear error; nothing stored | N/A |
| Draft guard | Secret-valued field to `check-url` or a Draft route | 422 `secret-values-refused` | N/A |
| FetchRequest | Built for a secret-bearing source | `secret_ref`s and scheme only | Contract test fails on any value |
| No permission | No `data_sources.manage` | 403; no disclosure | Story 1.19 |
| Canary | Canary used as a secret | Absent from logs, traces, audit, responses, props | N/A |
| Stale | Old `revision` | 409; nothing changes | N/A |

</frozen-after-approval>

## Code Map

- `app/Modules/Connector/Application/ManageDataSources.php` (`register` ~94, `update` ~128 with lock and revision, `auditState` 238, `source` 259), `Application/ValidateDataSourceInput.php:53-58` (auth gate to replace), `Contracts/{DataSource,DataSourceInput,DataSources,ReservedHeaders}.php`, `Infrastructure/ConnectorAuditSerializer.php`, `app/Platform/Audit/{AuditAction,AuditField}.php` -- extend with auth, secrets and the new audit action
- `app/Http/Controllers/Admin/DataSourceController.php`, `Requests/Admin/DataSourceRequest.php`, `Resources/DataSourceResource.php`, `routes/api.php:57-62`, `ShellNavigation::ADMIN_API_ROUTES` -- API surface; `app/Http/Controllers/Admin/MemberController.php:55-57,252-277`, `Access/Contracts/{PasswordNotConfirmed,ConfirmationThrottled}.php` -- password re-confirmation pattern (do not import Access into Connector: confirm in the controller layer and pass a closure or boolean)
- `database/migrations/2026_10_07_170001_create_data_sources.php` (`auth_type` CHECK already allows the five values), the allowlist and grants migrations -- RLS and `SECURITY DEFINER` patterns for the `secrets` table and removal function; `compose.yaml:215-217,264-267,327-335`, `docker/dev-keys/*.placeholder`, `tests/Architecture/ComposeKeysTest.php` -- mounts to leave unchanged; `app/Support/Queue/JobSigner.php` -- domain-separation precedent
- `app/Support/Observability/Scrubber.php` (`SENSITIVE_KEY` 33), `tests/Feature/ObservabilityTest.php` (`CANARY` 27, tests 127 and 238), `tests/Unit/ScrubberTest.php` -- canary coverage to extend to audit rows, API responses and props
- `resources/js/components/SecretField.vue`, `locales/labels.ts` (`controlLabels` 19-24), `pages/admin/DataSourceForm.vue` (auth hard-coded at 320, headers repeater), `lib/dataSources.ts` (25, 67), `lib/{formDrafts,unsavedForms}.ts`, `tests/js/data-sources.test.ts` -- UI
- `config/dashflow.php` (settings outside `tunables`, like `egress`), `tests/Feature/DashflowTunablesTest.php`, `.env.example`, `README.md`, `composer.json` -- settings and docs; `tests/Architecture/dependencies.php` (Connector owns `secrets`), `tests/Database/Support/Cluster.php`, `RolePrivilegesTest` -- tenant table hooks

## Tasks & Acceptance

**Execution:**
- [x] migration (`secrets`, removal function, `data_sources` auth columns), `SecretVault` contract and sealed-box driver, `KeyringUnavailable`, `FetchRequest`, settings, `ext-sodium` -- vault and storage
- [x] Connector service, validation, audit action and serializer, password re-confirmation in the controller, `RejectsSecretValues` guard, Scrubber field names, resource and API changes -- auth and secret API
- [x] `resources/js` (Authentication section, `SecretField` flow, secret header rows, password field, draft exclusion), `labels.ts`, error codes -- UI
- [x] `tests/Unit`, `tests/Database`, `tests/Feature`, `tests/Architecture`, `tests/js`, `tests/Security` (canary scan across logs, traces, audit, responses, props; web decrypt failure; FetchRequest contract), `README.md` -- every matrix row

**Acceptance Criteria:**
- Given an Admin with `data_sources.manage`, when they save a credential after password confirmation, then only `{configured, updated_at}` is ever returned, the value is sealed so that `web` cannot open it, and no canary appears in any log, trace, audit row, response or prop.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including the Docker-backed `Database` suite.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| Changing `api_key_placement` or `api_key_name` on a source holding a credential needs no password | high | patch | `ManageDataSources::confirm` asks only for a secret change or auth switch; a session could reroute a stored credential into the query string. |
| `secrets` has independent FKs, so a row can attach to another Workspace's Data Source | medium | patch | FKs ignore RLS; the other tenant tables use composite FKs. |
| Plaintext credentials pass through unmarked parameters | medium | patch | Only `LocalSecretVault::seal` and `SealedSecret` use `#[SensitiveParameter]`; a trace on the validator or `plan()` frames could capture a value. |
| `RejectsSecretValues` and the Scrubber use different name lists; flagged header needs strict `true` | medium | patch | The middleware lacks bearer, cipher, sealed, private_key and plurals; `"secret": 1` bypasses it. |
| Query-placed key name can inject parameters; `:` in a Basic username breaks the header; whitespace-only or null secret accepted or mis-reasoned | medium | patch | `ValidateDataSourceInput::apiKey`, `secretValue` and `secrets` use only the header token rule and a blank check. |
| Compose does not pass the documented key-path setting; `secret-values-refused` has no label; env wiring, `sealed`/`private_key` scrubbing and the form's remove-header password branch are untested | medium | patch | A typo or dropped compose line would make every save 503 with no failing test. |
| Changing `base_url` on a source holding a credential needs no password | medium | defer | The spec asks for confirmation on secret and auth-type changes only; repointing to another allowlisted host is a product decision about friction. |
| No `resolve(SecretRef, SecretContext)` and no key-version selection or `KeyringMismatch` on `open` | medium | defer | Story 2.5 is the first consumer and will define `resolve`; rotation is documented as not built. |
| Password check (bcrypt) runs inside the row-locked transaction; parallel guesses can pass the throttle check; shared throttle key with the member editor | low | rejected | Same pattern as the member editor, throttle-bounded, and documented as intentional. |
| Guard on `check-url` only; `FetchRequest` userinfo; plaintext not zeroed; `JsonException` mapped to 503; replace state on toggle, `required` on saved fields, 429/503 mapping; temp key files; untested corner cases | low | rejected | No Draft route exists yet; userinfo is refused at Base URL validation; the rest are cosmetic or unlikely, each fix adds guards or branches. |

## Design Notes

Why a sealed box: the epic requires both that the web tier stores credentials and that only `worker-connector` can read them; asymmetric sealing satisfies both without moving the keyring. The "wrapped DEK ref" of the criterion becomes `key_ref`, the fingerprint of the public key used; a true per-Workspace DEK hierarchy (and re-sealing on key rotation, which only the worker can do) is documented as a later hardening rather than built here. The worker-side `open` is implemented and tested with a generated key pair so Story 2.5 and 2.14 can use it; the dev placeholder in `key-cred` is not key material, so local flows that need `open` set the key file setting in tests only.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools npm run build` -- expected: exit 0
