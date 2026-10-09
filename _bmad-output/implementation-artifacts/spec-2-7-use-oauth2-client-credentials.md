---
title: 'Use OAuth2 client credentials'
type: 'feature'
created: '2026-10-08'
status: 'done'
baseline_commit: '6e8e605ac1a9337fc82d2084ea87363fc5b3495f'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-2-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-4-add-authentication-and-write-only-secrets-to-a-data-source.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-5-test-a-data-source-connection.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-6-accept-only-json-losslessly-and-within-limits.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** APIs that require OAuth2 tokens cannot be used, because a Data Source can only hold static credentials and nothing fetches, caches or refreshes a token (Story 2.7; FR-9, NFR-4, AR-26, AR-44, AR-45, AR-9, AD-19, UX-DR-29, 207). The Story 2.7 section of `epics.md` holds the acceptance criteria.

**Approach:** Accept the `oauth2_client_credentials` auth type with a token URL, client ID, scope and a write-only client secret, and teach `DirectFetchTransport` to obtain a token through the guard (no redirects, JSON only, size-capped), cache it encrypted in Valkey under the secret's version until `expires_in` minus a configured skew, refresh once on a 401, and report a second 401 as `connector.auth_failed`.

## Boundaries & Constraints

**Always:** `data_sources` gains `oauth_token_url`, `oauth_client_id` and `oauth_scope` (new expand-only migration, all null unless the auth type is `oauth2_client_credentials`, with a CHECK for that); the client ID is a plain value (not a secret) and the client secret is a new write-only slot `oauth_client_secret` handled exactly like the Story 2.4 slots (sealed with the platform public key, `{configured, updated_at}` only, password re-confirmation, `connector.data_source.secret_changed`, `revision` bump, removal when the auth type changes, `secrets_slot_check` widened). `secrets` gains an integer `version` (default 1, incremented in place on every replace) surfaced through `SecretStatus`, `SecretRef` and `FetchRequest` as `secret_version`; `FetchRequest` moves to version 4 and carries the token URL, scope, client ID and `secret_version` (never a secret value). The token URL is validated like a Base URL (http or https only, no userinfo, query or fragment, the Story 2.1 host rules, the allowlist check through `HostAllowlist::isAllowed`, and the `require_https` rule) on blur through the existing check endpoint and again on save, and is checked by `EgressGuard` at every token request; changing the token URL or client ID on a source that holds a client secret also requires `confirm_password`. The token request is a `POST` of `application/x-www-form-urlencoded` (`grant_type=client_credentials`, `client_id`, `client_secret`, optional `scope`) sent through `EgressGuard` and the egress transport with a new `EgressRequest` option that treats any 3xx as a failure and follows nothing; the answer must pass the Story 2.6 JSON check and size cap and carry a string `access_token` and a `token_type` of `bearer` (case-insensitive) and an optional numeric `expires_in`. A token endpoint answering 400 or 401 is an authentication failure; a block, a non-JSON answer, a redirect, a timeout or any other failure maps to `host-not-allowlisted`, `blocked-address`, `not-json` or `fetch-failed` as in Stories 2.5 and 2.6, and an egress block is audited by the guard as `connector.egress.blocked`. Every token request is recorded in `sync_runs` (kind `oauth_token`, sanitised token URL template, status, latency, bytes, code, request ID, never a token, secret or body). The token is cached through `TenantCache` in the Valkey cache store under `oauth:{data_source_id}:{secret_version}` (the Workspace prefix comes from `TenantCache`) as an encrypted value: libsodium `secretbox` under a 32-byte key read from the `key-token` mount (path setting `dashflow.secrets.token_key_path`, default `/run/secrets/key-token`, base64), with the Workspace, Data Source and `secret_version` inside the sealed payload and verified on read (a mismatch or a decryption failure is a miss); the cache TTL is `expires_in` minus the skew from a `pending_input` setting `DASHFLOW_OAUTH_TOKEN_SKEW_SECONDS` (no default; unset means no skew; documented in `.env.example`, README and `compose.yaml`), a token with no usable `expires_in` or a TTL of zero or less is not cached, and when the token key is unavailable (as in the dev placeholder) the token is used for that call only and never cached or logged, with one logged warning that names no value. A Replace increments `secret_version`, so the next call uses a fresh key and an old cached token is never used. `DirectFetchTransport` gets the scheme `oauth2_client_credentials`: it attaches `Authorization: Bearer <token>`; on a 401 with a cached or fresh token it drops the cache entry, requests a new token once and retries the call once; a second 401 raises `AuthFailed` (`connector.auth_failed` added to `ErrorCode`, never retried again, non-retryable); Test connection maps it to the user code `auth-failed` and its error card says authentication failed (add the message to the canonical catalogue under Story 1.7's rules and update its pinned expectations, or to `labels.ts` if the catalogue is closed, and record which in the Implementation Notes). The form's Authentication section offers OAuth2 client credentials with Token URL (blur check), Client ID, a `SecretField` for the Client secret and an optional Scope, and the typed secret and fields travel to Test connection as in Story 2.5. No token, client secret or Authorization header value appears in logs, traces, audit, `sync_runs`, summaries, responses or props (canary test with a canary token and secret). All strings come from the catalogue or `labels.ts`.

**Never:** Returning, logging or auditing a token or secret; following a redirect on the token URL; caching a plaintext token; using a cached token after a Replace; retrying more than once; a default for the skew; accepting an access token that is not a string; letting the token key or the platform public key be read by `web` for opening anything.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| First call | OAuth source, no cached token | Token requested through the guard, cached encrypted until `expires_in` minus skew, call sent with Bearer | N/A |
| Cached | Valid cached token for this `secret_version` | No token request; Bearer from cache | N/A |
| 401 once | API answers 401 | Cache dropped, fresh token, call retried once, succeeds | N/A |
| 401 twice | Second 401 | `connector.auth_failed`; card says authentication failed; no further retry | N/A |
| Replace | Client secret replaced | `secret_version` + 1; next call uses a new token | Old cached token unused |
| Blocked token URL | Not allowlisted or blocked address | `host-not-allowlisted` or `blocked-address`; guard audits | N/A |
| Bad token answer | HTML, malformed JSON, too large, redirect | `not-json`, `response-too-large` or `fetch-failed` | No retry |
| Bad credentials | Token endpoint 400 or 401 | `auth-failed` | N/A |
| No skew set | Setting unset | TTL is `expires_in` | N/A |
| No usable expiry | `expires_in` missing or at or below skew | Token used once, not cached | N/A |
| No token key | Key file missing or placeholder | Token used for that call only; warning logged | N/A |
| Blur check | Token URL not allowlisted | Inline `host-not-allowlisted`; Save `aria-disabled` | N/A |
| Reroute | Token URL or client ID changed with a stored secret | Password required | N/A |
| Canary | Canary token and secret | Absent from logs, traces, audit, `sync_runs`, responses, props | N/A |

</frozen-after-approval>

## Code Map

- `app/Modules/Connector/Contracts/{SecretSlots,CredentialScheme,FetchRequest,SecretRef,SecretStatus,EgressRequest,ErrorCode,ConnectionTestCode,DataSourceInput,DataSource}.php`, `Infrastructure/{DirectFetchTransport,LocalSecretVault,SecretSettings,CurlEgressTransport}.php`, `Application/{ValidateDataSourceInput,ManageDataSources,StartConnectionTest,RunConnectionTest,RecordSyncRun}.php` (the `auth-type-unavailable` branch ~63-71, `plan()` 241-285, `storeSecrets` ~300-340, catch chain ~85-130, `request()` 176-222) -- where OAuth plugs in
- `app/Platform/Tenancy/TenantCache.php`, `config/cache.php` (`valkey-cache`), `docker/valkey/cache.acl`, `compose.yaml` (`key-token` mount, env block ~71-75), `config/dashflow.php` (`secrets`, `egress`, `connection_test` blocks), `tests/Architecture/ComposeKeysTest.php` -- cache, key and settings plumbing
- `database/migrations/2026_10_07_180000_create_secrets_and_data_source_auth.php` (`secrets_slot_check` ~69-70, `auth_type` CHECK in `2026_10_07_170001_create_data_sources.php:65-66`), `2026_10_08_100002_add_transient_secrets.php` -- expand-only change patterns
- `resources/js/pages/admin/DataSourceForm.vue` (auth select ~1281-1320, `SECRET_SLOTS` 223, `chooseAuth` 225, `needsPassword` 231-250, payload ~488, error order ~586, `checkUrl` 395), `lib/dataSources.ts` (`AuthType` 13, `SECRET_SLOTS` 16-21, `ConnectionTestCode` 318), `components/FetchErrorCard.vue` (code union 19-24), `locales/{en,labels}.ts` -- UI
- `tests/Database/{DataSourceSecretsTest,ConnectionTestTest,EgressTransportTest}.php`, `tests/Unit/{DirectFetchTransportTest,LocalSecretVaultTest,FetchRequestTest}.php`, `tests/Unit/Support/FakeCurl.php`, `tests/js/{data-source-secrets,connection-test}.test.ts` -- test patterns (CACHE_STORE=array in tests)

## Tasks & Acceptance

**Execution:**
- [x] migration (`oauth_*` columns, `secrets.version`, widened slot check), contracts (`oauth2_client_credentials` auth and scheme, `secret_version`, `FetchRequest` v4, `AuthFailed`, `ErrorCode`), validation and persistence (token URL checks, `require_https`, password on reroute), settings (token key path, skew) -- OAuth data model
- [x] token client and encrypted cache, `EgressRequest` no-redirect option, `DirectFetchTransport` OAuth scheme with the 401 refresh-once rule, `sync_runs` for token requests, Test connection mapping to `auth-failed` -- runtime
- [x] `resources/js` (OAuth fields, blur check for the token URL, error card), catalogue or `labels.ts` message, generated error codes -- UI
- [x] `tests/Unit`, `tests/Database`, `tests/Feature`, `tests/js`, `README.md` -- every matrix row and a canary scan

**Acceptance Criteria:**
- Given an OAuth2 Data Source, when a fetch or Test connection needs a token, then it is obtained through the guard, cached encrypted per secret version, refreshed once on a 401, and a second 401 reports authentication failed, with no token or secret anywhere in logs, audit, `sync_runs`, responses or props.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including the Docker-backed `Database` suite.

## Implementation Notes

- **Message for `auth-failed`:** added to `labels.ts` (`dataSourceLabels.authFailed(source)`), not to the canonical catalogue: `catalogue.test.ts` pins `en.ts` to exactly the 89 rows of EXPERIENCE.md, which has no authentication-failed row, so the catalogue is closed. `FetchErrorCard` renders it for the code `auth-failed`.
- **Cache binding:** the sealed token payload also carries a digest of the token URL, client ID and scope (beside Workspace, Data Source and `secret_version`), so an edit of those without a Replace (they need the password, but keep the version) never meets an old token. A token requested with a client secret typed into a form (a transient row) or for a form with no Data Source is never cached.
- **Token cache key class:** `OAuthTokenCache` (Infrastructure) over `TenantCache`; a missing or placeholder `key-token` logs one warning per process through the injected PSR logger and disables caching. `OAuthTokenClient` records each token request through `TokenRequestLog` (`SyncRunTokenLog` writes the `sync_runs` row of kind `oauth_token`, in its own savepoint).
- **Settings:** `dashflow.secrets.token_key_path` (`DASHFLOW_SECRETS_TOKEN_KEY_PATH`, default `/run/secrets/key-token`) and `dashflow.oauth.token_skew_seconds` (`DASHFLOW_OAUTH_TOKEN_SKEW_SECONDS`, `pending_input`, unset = 0, a malformed value fails the call closed as `misconfigured`). Both are in `.env.example`, README and the `x-app-env` block of `compose.yaml`.
- **Size cap of the token answer:** only the platform ceiling (`guards.max_bytes`) applies, not the Data Source's own `max_response_bytes`, which is about its API's responses.
- **Migration `down`:** drops the client secrets and turns OAuth2 sources back to `none` (the token URL and client ID are gone), so the CHECK can be restored on the way forward.
- **Test suite memory:** the Pest run was within about 1 MB of the 128 MB limit; `ProfileSettingsTest`'s 413 case now runs with `app.debug` off so the debug renderer does not load its 700 KB of assets.
- **Removed:** `DataSourceInput::ACCEPTED_AUTH_TYPES` and the `auth-type-unavailable` reason (every type is accepted).

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| `secrets.version` restarts at 1 after the client secret row is removed and re-added, so a token cached for the old secret with a matching binding is reused | high | patch | Only the UPDATE branch of `storeSecrets` raises `version`; nothing forgets `oauth:{ds}:1` on removal. |
| Test connection with a saved id uses the stored client secret against an edited token URL, client ID or scope without the password a save needs | high | patch | `RunConnectionTest::request` falls back to stored secrets for any untyped slot; the tests delete `confirm_password`. |
| A 401 forgets whatever token is cached, deleting a fresh token another worker stored | medium | patch | `DirectFetchTransport::forgetToken` deletes unconditionally. |
| `RejectsSecretValues` exclusion for `oauth_token_url` matches any name containing it | medium | patch | `isSecretName` regex is unanchored. |
| The https-required branch for a token URL in Test connection, the swallowed `sync_runs` write, and the token-URL form wording are untested or hard-coded; the PHP suite sits about 1 MB below the 128 MB limit | medium | patch | Deleting the `assertTestable` branch or the try/catch leaves every test passing; the form rewrites base-URL text with `.replace`; the implementer turned debug off in one test to fit. |
| Concurrent callers stampede the token endpoint (no single-flight lock); only GET exists so a 401 retry cannot repeat a non-idempotent call yet | medium | defer | A lock needs the TenantCache lock path and a decision on wait behaviour; the retry rule must be revisited when Story 2.9 adds read-only POST. |
| Client authentication only by body (`client_secret_post`), 400 reported as bad credentials, 403 not refreshed, string `expires_in`, no default skew or TTL cap, 429 or 5xx token behaviour, token-key rotation overlap, `down()` data loss, latency includes token time, summary host on token failures, role guard in the cache class | low | rejected | Provider-specific or product choices the spec settles (no invented defaults, body credentials), or cosmetic; each fix adds branches or settings. |
| Auth-failed message lives in `labels.ts`, not the canonical catalogue | low | rejected | `catalogue.test.ts` pins `en.ts` to exactly the 89 rows of EXPERIENCE.md; the spec allows `labels.ts` when the catalogue is closed. |

## Design Notes

The client ID is a plain column because the criterion makes only the client secret write-only and a reopened form should show the ID. The cache payload carries its own context so a stolen or misplaced entry cannot be used for another Data Source or version, and a missing token key degrades to "no caching" rather than to caching in clear. A token request is its own `sync_runs` row because it is an outbound attempt and Epic 9 health work will want it counted separately from source calls. The skew is unset by default and then zero, matching the rule that no setting gets an invented value.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools npm run build` -- expected: exit 0
