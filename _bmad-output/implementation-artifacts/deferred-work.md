- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-initialise-the-project-from-the-laravel-vue-starter-kit.md`
  summary: Leftover starter-kit branding (Welcome page links, sidebar GitHub links, "Laravel" title fallback) must be replaced by the Dashflow shell.
  evidence: Reported by the blind review; `Welcome.vue`, `AppSidebar.vue`, `AppHeader.vue` and `app.ts` still carry kit content. Stories 1.6 and 1.16 own the shell.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-initialise-the-project-from-the-laravel-vue-starter-kit.md`
  summary: A user can delete their own account (`DELETE settings/profile`), which can remove the last user now that registration is gone.
  evidence: The PRD has no self-deletion. Settle in Stories 1.18 (profile) and 1.24 (user lifecycle).
- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-initialise-the-project-from-the-laravel-vue-starter-kit.md`
  summary: Tools image lacks the Redis extension and `.env.example` still defaults queue, cache, broadcast to database and log, while Horizon and Reverb are installed.
  evidence: Valkey, Horizon and Reverb configuration is Stories 1.3 and 1.5; the Docker tools image is replaced by the Compose environment in 1.3.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-initialise-the-project-from-the-laravel-vue-starter-kit.md`
  summary: Password reset requests and password confirmation have no explicit rate limit; only login is throttled.
  evidence: Story 1.14 already requires throttling for password recovery; confirm-password throttle to be added there.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-initialise-the-project-from-the-laravel-vue-starter-kit.md`
  summary: Production hardening defaults are kit defaults: `APP_DEBUG=true` in `.env.example`, `SESSION_ENCRYPT=false`, no secure-cookie guidance, `Password::defaults` lax outside production and untested in production.
  evidence: Deployment and security hardening belong to Epic 9 (Stories 9.x) and the session story 1.15; the production password policy test is cheap to add there.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-initialise-the-project-from-the-laravel-vue-starter-kit.md`
  summary: Review the kit-inherited manifest items: `pnpm-workspace.yaml`, `.npmrc` ignore-scripts, stale `@rollup/rollup-*` optionalDependencies pins limited to linux-x64 and win32-x64, older `@vueuse/core` and `vue-tsc`.
  evidence: Byte-identical in the pristine kit (verified against laravel/vue-starter-kit main), so not caused by this story; look again when CI runs on other platforms in Story 1.2.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-initialise-the-project-from-the-laravel-vue-starter-kit.md`
  summary: Base images in the dev tools Dockerfile are unpinned (`php:8.5-cli`, `composer:2`, `node:22`).
  evidence: A rebuild can change PHP or Node minors; Story 1.3 builds the pinned production image and supersedes the tools image.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-2-enforce-architecture-and-quality-gates-in-ci.md`
  summary: Add the Playwright end-to-end gate (Chromium, Firefox, WebKit, mobile emulation; sample page at desktop, tablet and mobile widths; browserslist latest two versions) and the axe WCAG 2.1 AA gate on every routed page to CI.
  evidence: Split from Story 1.2 by product-owner choice to keep the story within scope; still required by NFR-8, NFR-9 and UX-DR-264. Needs its own story (for example a new Story 1.26 in epics.md), best placed once Stories 1.6 and 1.16 replace the kit's UI, which may fail axe today.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-2-enforce-architecture-and-quality-gates-in-ci.md`
  summary: Add the CI workflow that runs `composer ci:check` on every pull request and blocks merges (platform to be chosen: GitHub Actions on the current remote, or GitLab CI as AR-51 states).
  evidence: Skipped from Story 1.2 by product-owner decision. Until it exists, the gates run only when someone runs `bin/tools composer ci:check`, so nothing stops a violating change from merging.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-2-enforce-architecture-and-quality-gates-in-ci.md`
  summary: Extend the `json_decode` ban to other lossy decoders (`->json()` on HTTP responses, the Http client) in Ingestion, RawStore, Mapping and Results once those modules exist.
  evidence: The architecture test only matches the literal `json_decode`; `$response->json()` decodes decimals as floats through another door. Revisit with the lossless-decoder story in Epic 2.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-2-enforce-architecture-and-quality-gates-in-ci.md`
  summary: Extend the module-boundary and table-ownership rules to code under `app/` outside `Modules` and `Platform` (controllers, providers, jobs) and to Eloquent `$table` properties.
  evidence: The scanner only owns `app/Modules/*` and `app/Platform`; add the outside-code rule with the first module controllers (Story 1.12) and re-check table ownership when the first module models land.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-2-enforce-architecture-and-quality-gates-in-ci.md`
  summary: Licence audit gaps: packages with `UNLICENSED`, no licence field or `SEE LICENSE IN`, and nested `node_modules` copies pass silently.
  evidence: Reviewers showed the deny-list only matches declared top-level licences; decide a policy for unlicensed packages before Epic 9 supply-chain gates.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-2-enforce-architecture-and-quality-gates-in-ci.md`
  summary: When Story 1.10 creates the `workspaces` tenant-root table, add it to the global-table list (or give the migration guard a tenant-root exemption).
  evidence: `workspaces` has no `workspace_id` column and is not in the agreed list, so the guard would fail that migration; Story 1.10 already says `workspaces` is handled as a global table.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-3-run-all-five-process-roles-locally-with-docker-compose.md`
  summary: Production hardening of the Compose stack and health probes: image digests; throttle/cache on `/health/ready`; statement and Redis read timeouts for probes; per-host scheduler heartbeat and start-time check; refuse default `POSTGRES_PASSWORD`/`REVERB_APP_SECRET` when `APP_ENV=production`; Reverb `allowed_origins` from env; `APP_KEY` not required for `compose down/ps`.
  evidence: Raised by the three reviewers; none breaks the local or demo stack, all matter before Epic 9 production hardening.
- source_spec: `_bmad-output/implementation-artifacts/spec-1-3-run-all-five-process-roles-locally-with-docker-compose.md`
  summary: Jobs and broadcasts sent to the `default` queue have no consumer; decide the queue for Reverb broadcasts and notifications (Story 1.5 queue design) and add tests for the heartbeat schedule, the connector/compute queue split and entrypoint behaviour.
  evidence: `config/horizon.php` supervisors list only the named queues from the spec.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-5-split-valkey-into-queue-and-cache-stores-with-signed-jobs.md`
  summary: Run an executed ACL test in CI that connects to both Valkey stores with another role's credentials and expects WRONGPASS/NOPERM.
  evidence: Pest only parses the ACL and Compose files because the CI image has no Valkey; the refusal was verified by hand on the rebuilt stack, so an ACL swap between roles could pass `ci:check`.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-6-apply-the-dashflow-design-tokens-and-brand-seam.md`
  summary: Remove the third-party Inter stylesheet request (`https://rsms.me/inter/inter.css`) from `Welcome.vue`.
  evidence: Pre-existing starter kit code on the lint allowlist; the request bypasses any CSP and contradicts serving fonts from the app origin. Resolve when `Welcome.vue` is migrated in Story 1.16.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-4-observe-every-request-with-opentelemetry-and-scrubbed-logs.md`
  summary: Scrub SQL bindings and personal data out of exception messages (for example `QueryException`) before they reach logs and spans.
  evidence: Laravel embeds bound values in `QueryException` text, and the scrubber only removes URLs, query strings and headers; epic 1 requires that personal data never appears in logs.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-4-observe-every-request-with-opentelemetry-and-scrubbed-logs.md`
  summary: Replace span `url.path` with the route template so path-embedded tokens never reach exported spans.
  evidence: Auto-instrumented server spans carry the raw path, such as `/reset-password/{token}`; the log line is patched in Story 1.4 but spans are not.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-7-serve-all-product-copy-from-the-canonical-message-catalogue.md`
  summary: Add a DOM test environment and mounted tests for `TechnicalDetails` (disclosure toggle, Copy request ID success and failure states).
  evidence: Story 1.7 tests render with `vue/server-renderer`, which never runs click handlers, so a regression in `copy()` or the toggle would pass; the spec ruled out a new dev dependency, so add happy-dom or jsdom with the first Admin error screen.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-8-build-the-shared-form-controls-and-status-components.md`
  summary: Create the polite live region before the first announcement (and set its text on a later tick) in `lib/announce.ts`, as part of the Story 1.9 live-region work.
  evidence: The region is created and filled in the same tick on first use, and many screen readers skip content present at insertion, so the first blocked-action reason may not be spoken.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-8-build-the-shared-form-controls-and-status-components.md`
  summary: Verify Space on the switch and arrow-key behaviour of radio groups, segmented control and chips in a real browser (for example Playwright with axe).
  evidence: happy-dom does not turn Space into a click on a native button, so the tests toggle with `click()`; keyboard semantics rely on reka-ui and were not checked in a browser.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-9-build-dialogs-toasts-banners-popovers-and-live-regions.md`
  summary: Verify reduced-motion behaviour, focus trap, Esc handling and live-region speech for dialogs, sheets and toasts in a real browser (for example Playwright with axe).
  evidence: happy-dom does not evaluate `prefers-reduced-motion` or real focus trapping; the reduced-motion test only reads CSS text, so removing a `motion-safe:` class or overriding a rule would pass.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-10-isolate-each-workspace-with-row-level-security.md`
  summary: Convert `users.id` and every dependent from bigint to UUIDv7 (AR-14): `sessions.user_id`, `workspace_memberships.user_id`, the `access_user_memberships(bigint)` function signature, the `::bigint` cast in the `membership_lookup` policy, `MembershipLookup::forUser(int)` and the `WorkspaceMembership` `user_id` property.
  evidence: Story 1.10 keeps the starter kit's bigint `users.id` so it does not rewrite authentication; AR-14 requires every platform key to be a UUIDv7 with no sequential ID exposed, so the identity stories must convert it.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-10-isolate-each-workspace-with-row-level-security.md`
  summary: Replace the default privileges in `docker/postgres/initdb.sh` with default-deny plus explicit per-table grants, and add a Database test that every global table has its expected grant list.
  evidence: Default privileges give `app` INSERT and UPDATE (and `maintenance` SELECT) on every table `migrator` creates, including `migrations` and `users`, so a future migration must remember to REVOKE and no test enforces it.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-12-provision-a-workspace-and-let-its-first-admin-accept-an-invitation.md`
  summary: Add an idempotent repair path to `dashflow:workspace:create` (for example `--repair {workspace-id}`) that re-mirrors the Workspace audit event, and move the invitation email after commit with a retry.
  evidence: The audit mirror runs after the operator transaction commits, so a failure leaves a Workspace and a sent link without the Workspace audit event, and a rerun would create a duplicate Workspace; the email is sent before commit, so a failed commit leaves a link to nothing.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-14-reset-a-forgotten-password.md`
  summary: Add a per-IP-only throttle bucket (a new `pending_input` limit) to the password-reset and sign-in requests, and send a "your password was changed" notice email after a reset.
  evidence: The throttle keys on email plus IP, so one IP rotating target emails is never limited (mail-bombing, probing), and a completed reset sends no confirmation, which account-takeover detection normally needs.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-15-warn-before-session-expiry-and-keep-forms-safe.md`
  summary: Decide whether sessions need an absolute maximum lifetime (a new `pending_input` tunable) on top of the idle timeout, and add a Story 1.9 dialog-refusal test for the session warning.
  evidence: Extending resets the idle clock every time, so a session can live indefinitely; the spec defines only an idle limit. The warning dialog's refusal path when another dialog is open is untested.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-17-switch-the-active-workspace.md`
  summary: Make every Epic 1+ form register with `registerUnsavedForm` (and reconcile it with the `formDrafts` dirty tracking) so the Workspace switcher's unsaved-changes dialog covers all forms.
  evidence: Only the Profile page registers; a switch on any other form silently discards unsaved edits.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-18-edit-my-profile-and-settings-and-open-help-and-support.md`
  summary: Add a "remove avatar" action and re-encode uploaded avatars (strip EXIF and trailing data) once an image library is chosen; give single-key shortcuts of the drawer story a test that they go through `registerShortcut`.
  evidence: Avatars are stored byte for byte after a `getimagesize` sniff and cannot be cleared; no single-key feature exists yet, so nothing proves a real handler honours the Keyboard shortcuts switch.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-22-assign-roles-and-admin-permissions.md`
  summary: Make the Admin password-confirmation throttles (Stories 1.21 and 1.22) independent of the request transaction (a counter outside the transaction, or a boot-time check that the cache store is not the database connection) and add a two-connection concurrency helper to `Cluster` to test the `FOR UPDATE` and last-holder recount.
  evidence: With `CACHE_STORE=database` the failed-attempt count is written inside the request's PostgreSQL transaction that rolls back on 4xx, so the limit never accumulates; Compose uses Valkey so it works there. The row-lock and last-holder guarantees are only exercised sequentially.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-23-organise-users-into-groups.md`
  summary: Paginate the Groups list and its embedded members (cursor, with a per-group member cap), add group add/remove from the member's row in User configuration, and test the concurrent duplicate-name and duplicate-add races once `Cluster` has a two-connection helper.
  evidence: `SqlGroupDirectory::list` returns every group with every member in one response; the epic's "or from the member's row" entry point is not built; the 23505 mapping and the group-row lock are exercised only sequentially.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-25-verify-security-and-load-readiness-with-test-suites-and-a-load-test-skeleton.md`
  summary: Add a negative run for `bin/test-restore` (a scratch copy with FORCE RLS removed or DELETE granted to `app` must fail), real-browser axe with contrast, layout and error-state coverage, and the full backup and restore drill.
  evidence: The restore check has only been seen passing; axe runs in happy-dom without layout, so contrast, reflow and dialog or error states are unchecked; the full drill is Epic 9.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-1-manage-the-workspace-host-allowlist.md`
  summary: Test the host-allowlist version-row lock with two live connections.
  evidence: The stale tests run sequentially; dropping `for update` in `ManageHostAllowlist::lockVersion` would pass them. Needs the two-connection helper already deferred in Story 1.23.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-1-manage-the-workspace-host-allowlist.md`
  summary: Story 2.2 must review `BlockedAddress` for documentation and benchmark ranges (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24, 198.18.0.0/15, 2001:db8::/32) and decide about reserved host names (`localhost`, `*.internal`).
  evidence: Story 2.1 blocks only the classes the spec names and accepts any non-IP name; the EgressGuard classifier reuses `BlockedAddress` as the single list.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-2-guard-every-outbound-url-with-egressguard-and-operator-private-range-grants.md`
  summary: Add a replay path for a failed Workspace-audit mirror of an egress grant or revoke.
  evidence: The mirror runs after the operator commit; on failure the row and `operator_audit` entry exist but a retry fails as already_granted or not_granted.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-2-guard-every-outbound-url-with-egressguard-and-operator-private-range-grants.md`
  summary: Replace the single shared operator password hash with real operator identities, add a cross-run lockout, and keep the hash out of container env.
  evidence: Operator re-confirmation is one bcrypt hash in an env setting; the three-attempt limit resets on every run and the hash is visible via docker inspect.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-2-guard-every-outbound-url-with-egressguard-and-operator-private-range-grants.md`
  summary: Add a response-size ceiling to the egress transport (Story 2.6) and pin all checked records for dual-stack fallback (Story 2.14).
  evidence: NativeCurlClient buffers the whole body and pins only addresses[0]; no caller exists yet.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-3-register-and-edit-a-data-source.md`
  summary: Test the concurrent duplicate-name path (SQLSTATE 23505) of Data Source create and update with two live connections.
  evidence: `ManageDataSources::nameTaken` is never executed; the pre-check catches every sequential duplicate.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-3-register-and-edit-a-data-source.md`
  summary: Add paging for the Data Source list, a retire or delete action, and an outbox event for Data Source changes when a consuming story needs them.
  evidence: The list ships every row; the `app` role has no DELETE; downstream consumers (health, Blocks) will need an invalidation signal.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-4-add-authentication-and-write-only-secrets-to-a-data-source.md`
  summary: Decide whether changing the Base URL of a Data Source that holds a credential should require password re-confirmation.
  evidence: The spec requires confirmation for secret and auth-type changes only; repointing a stored credential to another allowlisted host is allowed without it.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-4-add-authentication-and-write-only-secrets-to-a-data-source.md`
  summary: Story 2.5 must add `SecretVault::resolve(SecretRef, SecretContext)` that reads the secrets row and opens it, and key-version selection with a distinct KeyringMismatch for rotation.
  evidence: FetchRequest carries only SecretRef(id, slot) but `open()` takes a ciphertext, and `open()` always uses the single mounted key regardless of the stored key_version or key_ref.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-5-test-a-data-source-connection.md`
  summary: Commit an Operation's `running` status first and run the handler and its network call outside the Workspace transaction.
  evidence: `RunsInWorkspace` wraps the whole job, so `running` is never observable by pollers and a row lock is held for the outbound call.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-5-test-a-data-source-connection.md`
  summary: Move the expired-secrets purge and `sync_runs` partition upkeep to the `maintenance` role, drop UPDATE on `sync_runs` from `app`, and add retention for `operations` and `sync_runs`.
  evidence: `connector_purge_expired_secrets()` is executable by `app` and crosses Workspaces; `operations` has no sweep; a non-empty DEFAULT partition has no repair or alert path.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-6-accept-only-json-losslessly-and-within-limits.md`
  summary: Decide whether to add a built-in depth and response-size ceiling that the pending_input settings can only lower.
  evidence: With both settings unset nothing is capped; the README records a worker segfault when PHP frees a body nested 300,000 levels deep, and a gzip bomb is unbounded in memory until a limit is configured.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-7-use-oauth2-client-credentials.md`
  summary: Add single-flight locking for OAuth token refresh and restrict the 401 retry to idempotent requests when Story 2.9 adds read-only POST.
  evidence: DirectFetchTransport::token() does get, request, put with no lock, so parallel jobs stampede the token endpoint; the retry resends the whole request.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-8-prevent-overwriting-with-a-soft-lock.md`
  summary: Back the edit lock with a DB-recorded token (or re-grant by token when the cache entry is lost and the epoch is unchanged) and make completion transactional with the cache write.
  evidence: The lock lives only in the cache; a rollback after completion or an eviction leaves the cache and lock_epoch out of step, so the holder's next save is a 423 and typed work is lost, though no overwrite can occur.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-8-prevent-overwriting-with-a-soft-lock.md`
  summary: Add the Reverb private-channel push (and its Echo client and channel auth) for the edit-lock flush request as a consumer of platform.edit_lock.flush_requested.
  evidence: The architecture names a realtime push with polling as the same-semantics fallback; only polling exists because no Reverb or Echo wiring is in the app.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-9-register-endpoints-on-a-data-source.md`
  summary: Decide whether saving an Endpoint must honour the Data Source soft lock from Story 2.8.
  evidence: ManageEndpoints::create and revise never check who holds the Data Source edit lock; the spec is silent, so two Admins can edit Endpoints and the Data Source form at once.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-9-register-endpoints-on-a-data-source.md`
  summary: Add a value type to Endpoint parameters so a fixed value can be a number or boolean at a typed body position, and test concurrent revises with two connections.
  evidence: Fixed values are stored as strings only; nothing renders a request yet, and the revise row lock is only tested sequentially.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-10-test-an-endpoint-and-see-the-sample-response.md`
  summary: Decide whether a POST sample test should fetch a fresh OAuth token before the first send when the cached token may have expired.
  evidence: A POST is never retried, so a POST sent with an expired cached token ends as auth-failed and needs a manual re-run; the token request itself is idempotent.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-10-test-an-endpoint-and-see-the-sample-response.md`
  summary: Audit sample reads and consider consuming the sample blob after the first read.
  evidence: ReadSample can be called repeatedly until the Operation expires and reads are not recorded.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-11-follow-pagination-up-to-the-limits.md`
  summary: Carry the Operation's expiry into FetchRequest as a run deadline for paged fetches and add a merged-size ceiling.
  evidence: Each page has its own timeout but nothing bounds the whole run; the merged records are held as a decoded tree and sealed into one cache value, so memory can exceed the byte cap.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-12-define-user-attributes-for-user-context-binding.md`
  summary: Setting member attributes does not check the target membership's status (e.g. removed or invited).
  evidence: ManageMemberAttributes::member() only matches id and workspace; unverified (maybe-false, medium if true) — settle by checking which membership statuses can exist and whether values on them matter.
- source_spec: `_bmad-output/implementation-artifacts/spec-2-12-define-user-attributes-for-user-context-binding.md`
  summary: AttributesOnMembershipRemoved subject-only (`membership:{id}`) fallback has no test.
  evidence: The only consumer test supplies data.membership_id, so the fallback branch is unexercised; add a case when the removal flow emits the event.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-13-bind-endpoint-parameters-and-headers-to-user-context.md`
  summary: Fetch as user on an Endpoint with requires_user_context false still audits and emits the notification event for the target member.
  evidence: StartFetchAsUser does not check the flag; unverified whether product wants it refused (maybe-false, medium if real).
- source_spec: `_bmad-output/implementation-artifacts/spec-2-13-bind-endpoint-parameters-and-headers-to-user-context.md`
  summary: binding-options member list is capped at 50 with no cursor, has no throttle, and its filtering/search and shared rate-limit budget are not asserted by tests.
  evidence: EndpointController::bindingOptions hard-codes pageSize 50 and carries only the admin middleware; no test passes search or mixes Test endpoint and Fetch as user against one limit.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-14-fetch-each-endpoint-on-a-schedule-and-keep-the-last-good-response.md`
  summary: Scheduled fetch runs inside the workspace job transaction (connection held during slow HTTP), FetchJob has no timeout or failed() handler, and PostgresRawStore copies large bodies as hex several times.
  evidence: FetchJob uses RunsInWorkspace around FetchSyncTarget; a killed job leaves no sync_runs row; put() uses bin2hex.
- source_spec: `_bmad-output/implementation-artifacts/spec-2-14-fetch-each-endpoint-on-a-schedule-and-keep-the-last-good-response.md`
  summary: Targets for deleted or disabled Endpoints and permanently failing targets (non-read-only POST) are never retired and re-run every tick; no unique-active-target-per-Endpoint constraint; data_source.updated may re-register only a partial Endpoint list; targets registered before refresh_intervals is set wait for the next save.
  evidence: RegisterSyncTargets reacts only to created/revised/data_source.updated; unverified (maybe-false) for paging and the race.
- source_spec: `_bmad-output/implementation-artifacts/spec-2-14-fetch-each-endpoint-on-a-schedule-and-keep-the-last-good-response.md`
  summary: Admin UI shows only last success; consecutive failures and last attempt are not visible.
  evidence: SyncStatus has only succeeded/waiting/not_scheduled; health belongs to a later story.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-15-use-conditional-requests-and-skip-unchanged-data.md`
  summary: Bodies that cannot be canonicalised (duplicate keys, deep nesting) are always treated as changed, and the not_modified_without_payload reason is not stored on the run.
  evidence: CanonicalBodyHash returns null so content_hash stays null; error code falls back to fetch-failed. Falling back to a byte hash would stop repeat payload.changed events.
- source_spec: `_bmad-output/implementation-artifacts/spec-2-15-use-conditional-requests-and-skip-unchanged-data.md`
  summary: Canonicalisation runs under the target row lock, and stored validators are not reset on credential rotation unless the Data Source revision bumps.
  evidence: commitSuccess hashes after select for update; unverified whether secret or OAuth rotation bumps data_source_revision (maybe-false, medium if not).

- source_spec: `_bmad-output/implementation-artifacts/spec-2-16-choose-how-much-raw-history-a-data-source-keeps.md`
  summary: Saving a retention change bumps data_source_revision, which re-keys sync targets (old target retired with its payload, new target has no current payload until refetched), so retention edits are not independent of fetch state.
  evidence: ManageDataSources::update revision+1 and ResolveFetchKey hashes data_source_revision; needs a decision whether retention should be excluded from the revision bump (conflicts with epic AC wording about leaving current_payload_id untouched).
- source_spec: `_bmad-output/implementation-artifacts/spec-2-16-choose-how-much-raw-history-a-data-source-keeps.md`
  summary: Sweep robustness: one failing target rolls back a Workspace's whole sweep every run, the 20-target cold limit can starve behind un-purgeable targets, only 1000 rows per rule per run, no overlap guard on SweepRawHistoryJob, no failure/duration metrics.
  evidence: SweepRawHistory sweeps each Workspace in one runIsolated transaction and logs only the exception class.
- source_spec: `_bmad-output/implementation-artifacts/spec-2-16-choose-how-much-raw-history-a-data-source-keeps.md`
  summary: A Data Source saved as window(N) cannot be edited for unrelated fields once the deployment maximum is lowered or unset until the Admin switches to Latest only.
  evidence: Validator checks the maximum on every save; the form shows the window radio selected but disabled.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-17-retry-rate-limit-and-break-the-circuit-on-failing-sources.md`
  summary: The ValkeySourceGovernor Lua scripts (breaker, probe lease, bucket, concurrency) are never run by the automated suite; tests use FakeGovernor.
  evidence: Test image has no Valkey or phpredis; the scripts were run by hand against valkey/valkey:9-alpine (empty-bucket path not exercised). Add a Database-suite test against the Docker Valkey queue store when the image supports it.
- source_spec: `_bmad-output/implementation-artifacts/spec-2-17-retry-rate-limit-and-break-the-circuit-on-failing-sources.md`
  summary: A killed worker's concurrency slot and half-open probe lease are freed only by TTL; skipped runs are written every interval while a breaker is open; the governor is consulted whenever only retry.cap is set; FetchJob retries re-enter every target of a sync group.
  evidence: jobFailed does not release governor state by design; no coalescing of skipped rows; GovernorLimits::active() keys off the penalty cap; groups equal one target until Story 2.20.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-18-see-data-source-health.md`
  summary: Add probe tests for AuthFailed, SsrfBlocked and a stored-credential (api-key, basic, secret header) Data Source.
  evidence: Verification-gap review found no probe test with a secret header or auth type other than none; `ProbeDataSource` maps these exceptions to codes that nothing asserts.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-19-refresh-only-what-is-being-watched-within-budgets.md`
  summary: Story 2.19 Live refusal (`msg:live-not-supported`), the scheduler clamp when a Data Source loses `live_capable` or an interval is removed, and the Data Source impact list with its Admin-notification event.
  evidence: Split at the planning checkpoint; no Block or interval picker exists yet to use them.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-19-refresh-only-what-is-being-watched-within-budgets.md`
  summary: Test the workspace fair share and batch cap in the hot dispatch path of `DispatchDueSyncs`.
  evidence: Verification-gap review: `DemandRefreshTest` forces `workspace_fair_share` to null; the existing fair-share test runs with the demand rule off.
- source_spec: `_bmad-output/implementation-artifacts/spec-2-19-refresh-only-what-is-being-watched-within-budgets.md`
  summary: Check that a `scopeByCaller` Endpoint without a user-bound parameter does not become a scheduled shared target in `RegisterSubscription`.
  evidence: Unverified: `userScoped` is `bound !== null`; read `Endpoint::requiresUserContext` to settle it (medium if not covered).
- source_spec: `_bmad-output/implementation-artifacts/spec-2-19-refresh-only-what-is-being-watched-within-budgets.md`
  summary: Give `hot_targets` and `cold_targets` gauge semantics, and the subscription refusal its own metric name.
  evidence: `MetricEmitter` is counter-only, so the sweep adds levels every run.
- source_spec: `_bmad-output/implementation-artifacts/spec-2-19-refresh-only-what-is-being-watched-within-budgets.md`
  summary: Purge shared per-period targets that subscriptions created once they go cold.
  evidence: The cold purge covers only `user_scoped` targets, so each period or compute context leaves a permanent shared target.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-20-fetch-comparison-data-together-as-a-sync-group.md`
  summary: Story 2.20 Data Source detail view showing a group run as one run with both sides' outcomes.
  evidence: Split at the planning checkpoint; no sync-runs read path or UI exists and `sync_runs` is Connector-owned.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-20-fetch-comparison-data-together-as-a-sync-group.md`
  summary: Test the group fetch under governor denial, retry and requeue, a moved revision and a late comparison, and give the re-emit exclusion test a real caught-up consumer.
  evidence: Verification-gap review: `SyncGroupTest` sets no retry or governor tunables, and its caught-up assertion is vacuous.
- source_spec: `_bmad-output/implementation-artifacts/spec-2-20-fetch-comparison-data-together-as-a-sync-group.md`
  summary: Make the payload re-emit sweep compare against the payload carried by the last consumed event, skip subjects with no consumer, and bound its run across Workspaces.
  evidence: It compares `payload_seq` with the outbox `subject_seq` (different counters), re-emits every stored target every ~5 minutes while no consumer exists, and a 55 s job can end before late Workspaces.
- source_spec: `_bmad-output/implementation-artifacts/spec-2-20-fetch-comparison-data-together-as-a-sync-group.md`
  summary: Decide how compute tells a failing group (both sides failed, no generation) from a never-fetched one, add `find(generationId)`, and tie raw retention and pruning to generations.
  evidence: `comparisonUnavailable` only reads the newest generation; generations name payload ids retention may purge and have no pruning path.

- source_spec: `_bmad-output/implementation-artifacts/spec-3-1-register-the-block-type-contract-v1-and-the-kpi-card-package.md`
  summary: Verify each package's Shaper class autoloads under its `BlockTypes\<Key>\V<N>` namespace and exposes `shape()`.
  evidence: The registry only checks that Shaper.php exists; a namespace mismatch passes boot and fails at shaping time. Shaper is a stub until Story 3.2.

- source_spec: `_bmad-output/implementation-artifacts/spec-3-2-see-the-kpi-card-in-every-state-rendered-safely-and-exactly.md`
  summary: Add a shared Shaper-output fixture asserted in Pest and mounted in Vitest, and generate payload/descriptor types from schema.json instead of hand-writing payload.ts.
  evidence: PHP Shaper keys and payload.ts can drift with both suites green; enums are duplicated in Shaper, payload.ts and display.ts. Also the `icon` slot renders as literal text until icons are mapped.

- source_spec: `_bmad-output/implementation-artifacts/spec-3-3-start-save-and-discard-a-draft-block.md`
  summary: Guard Discard with the edit lock/revision (and decide owner rules) once Draft lock endpoints exist, and audit block type changes made through save.
  evidence: DELETE draft takes no expected_revision/lock_epoch, so an Admin can discard a Draft another is editing; type_key changes through save are unaudited. Locking is Story 3.6, wizard Story 3.4.
- source_spec: `_bmad-output/implementation-artifacts/spec-3-3-start-save-and-discard-a-draft-block.md`
  summary: Add a deterministic test for the unique-index race in ManageDrafts::create.
  evidence: The savepoint/23505 to DraftExists path is only reachable with concurrent writers and is untested.

- source_spec: `_bmad-output/implementation-artifacts/spec-3-4-configure-block-step-1-with-a-live-preview.md`
  summary: Recover the wizard from 409/423 on save (reload revision/epoch or show both versions) and read block types/heights from the registry instead of the generated constant.
  evidence: After a 409/423 the wizard keeps the stale revision so every retry fails until reload; client hardcodes kpi-card. Recovery is Story 3.5/3.6; second block type arrives in Epic 5.

- source_spec: `_bmad-output/implementation-artifacts/spec-3-5-autosave-continuously-and-recover-from-expiry-offline-and-save-failure.md`
  summary: Replay that gets 401 again re-stashes with the reloaded revision as base, and a single PENDING_KEY slot lets one Draft's stash overwrite another's
  evidence: Review edge-case hunter; wizardPending.ts uses one key, BlockWizard stash() uses draft.value.revision. Unverified severity medium.
- source_spec: `_bmad-output/implementation-artifacts/spec-3-5-autosave-continuously-and-recover-from-expiry-offline-and-save-failure.md`
  summary: Leave guard replays a blocked non-GET visit as a bare GET, and manual Save draft double-click can queue two saves
  evidence: Review hunters; router.on('before') stores only the URL, save() no longer short-circuits when saving. Unverified.
- source_spec: `_bmad-output/implementation-artifacts/spec-3-5-autosave-continuously-and-recover-from-expiry-offline-and-save-failure.md`
  summary: Add tests for beforeunload guard and owner-mismatch drop in readPending; consider autosave retry after failure and stashing on 404 while dirty
  evidence: Verification-gap and edge-case reviews; no test dispatches beforeunload.

- source_spec: `_bmad-output/implementation-artifacts/spec-3-6-soft-lock-a-draft-and-take-over-editing.md`
  summary: Idle holder suspends heartbeats so a take-over flush request never reaches it, and a slow flush holds the heartbeat guard so the lock can lapse mid-flush
  evidence: Review edge-case and blind hunters; useEditLock activityGated/beating logic. Unverified severity medium.
- source_spec: `_bmad-output/implementation-artifacts/spec-3-6-soft-lock-a-draft-and-take-over-editing.md`
  summary: Lock UI polish: focus move to banner when lock is lost, form aria-label landmark, read-only for wizard steps 2-5 and stepper, ttl_seconds guard, shared limiter/route factory, DataSourceError coupling
  evidence: Review blind hunter; steps 2-5 do not exist yet (3.7+), the rest is cosmetic or structural.

- source_spec: `_bmad-output/implementation-artifacts/spec-3-7-choose-the-data-source-endpoint-parameters-and-refresh-step.md`
  summary: Persist the POST read-only acknowledgement and detect pinned-revision drift (Endpoint/Data Source edited after the sample) so sample_out_of_date is also server-derived
  evidence: Review hunters; ack is client-only and flag is only set by the client. Unverified severity medium; belongs with 3.8/3.9/3.18.
- source_spec: `_bmad-output/implementation-artifacts/spec-3-7-choose-the-data-source-endpoint-parameters-and-refresh-step.md`
  summary: Fixed parameters named like credentials are refused by RejectsSecretValues; default-range dates use browser clock and Monday week start; read routes lack a throttle; unused blockSourceLabels keys
  evidence: Review hunters; verified in diff, low impact today.

- source_spec: `_bmad-output/implementation-artifacts/spec-3-8-paste-sample-json-and-inspect-it-with-exact-numbers.md`
  summary: Cap shape node count, show sample expiry to the Admin, treat a sample sealed under a rotated data key as ended instead of 503, and decode before taking the Draft row lock on store
  evidence: Review hunters; verified in diff. Unverified severity medium for key rotation (stuck until TTL).
- source_spec: `_bmad-output/implementation-artifacts/spec-3-8-paste-sample-json-and-inspect-it-with-exact-numbers.md`
  summary: Audit event (metadata only) for storing/erasing a personal-data sample; map 409/404 on store to dedicated client messages; ignore stale restore responses; replace Go-to-line string splitting with a structured message
  evidence: Review hunters; spec allowed no audit, other items low impact.

- source_spec: `spec-3-9-fetch-a-sample-from-the-api.md`
  summary: Concurrent Draft fetches can overwrite each other, and `sample_revision` restarts at 1 after a purge.
  evidence: storeFetched checks only the lock epoch; the preview cache of Story 3.10 keys on (Draft, sample_revision). Unverified.
- source_spec: `spec-3-9-fetch-a-sample-from-the-api.md`
  summary: Add tests for `fetchBodyOf`/`stepGate` date mapping, 401/419 `auth-error`, PollTimeout card, and a 409 for a published Draft fetch.
  evidence: Verification-gap review found no existing coverage; paths mirror the paste flow.
- source_spec: `spec-3-9-fetch-a-sample-from-the-api.md`
  summary: Draft fetch writes no audit event, and `bindings.*` validation skips dotted parameter names.
  evidence: Reviewer findings; not exercised by the current acceptance criteria.

- source_spec: `spec-3-10-render-the-sample-through-the-query-plan-engine-live-preview.md`
  summary: Bound the Query Plan guards inside `SamplePath::select`, so one huge `[*]` select cannot pass the extracted-values and wall-time guards.
  evidence: InProcessExecutor checks them only between evaluations and after select() has finished.
- source_spec: `spec-3-10-render-the-sample-through-the-query-plan-engine-live-preview.md`
  summary: Purge or expire the per-process decoded-sample cache on discard or sample expiry, and add `created_at` to its key.
  evidence: Plaintext trees (up to 8) can outlive the sample TTL in long-lived workers; sample_revision restarts after a purge.
- source_spec: `spec-3-10-render-the-sample-through-the-query-plan-engine-live-preview.md`
  summary: Allow a Draft save to clear `config.query_plan` and test the 409 published-Draft preview once Story 3.19 adds the state.
  evidence: `isset()` treats null as absent; `block_versions.state` allows only draft and discarded for now.

- source_spec: `spec-3-11a-infer-field-roles-and-record-path-candidates.md`
  summary: Story 3.11b: the Map Data step UI (layout, scalar cards, role chips and menus, suggestion row, record-path radiogroup, field tree, roles summary, small-screen message).
  evidence: Split from Story 3.11 on the scope rule; it consumes the 3.11a endpoint and config keys.
- source_spec: `spec-3-11a-infer-field-roles-and-record-path-candidates.md`
  summary: Story 3.11c: upsert the Dataset (workspace, endpoint, record_path) with typed fields as a suggestion for new Drafts.
  evidence: Split from Story 3.11; needs a Datasets table that does not exist yet.

- source_spec: `spec-3-11a-infer-field-roles-and-record-path-candidates.md`
  summary: Report stale overrides and a stale `record_path` after the sample is replaced; treat an empty array of objects as a candidate; match pagination words as whole words only; give an invalid sample its own 422 message.
  evidence: Edge-case review: dead overrides can revive on a reused path, and `$.pages[*]` or `$.next_steps[*]` are flagged pagination-like.

- source_spec: `spec-3-12a-auto-map-slots-with-confidence.md`
  summary: Story 3.12b: the slot checklist UI (groups, state matrix, confidence meters, filters, status bar, Continue gating, error summary, announcements).
  evidence: Split from Story 3.12 on the scope rule; it consumes the 3.12a endpoints.

- source_spec: `spec-3-12a-auto-map-slots-with-confidence.md`
  summary: Wire `carryOver` to the Block Type change in Step 1; validate `slot_map` fit on the generic Draft save; widen baseline-name and text-slot matching; decide how the epic's numeric-`revenue` example should grade.
  evidence: Review findings; none is exercised by the current acceptance criteria.

- source_spec: `spec-3-13a-override-pick-field-and-fix-mismatches.md`
  summary: Story 3.13b: the field picker popover, role-change note with Undo, Type mismatch pill, "Treat as" menu, `msg:api-changed` and `msg:api-match-found`, read-only view.
  evidence: Split from Story 3.13 on the scope rule; it consumes the 3.13a endpoints.

- source_spec: `spec-3-13a-override-pick-field-and-fix-mismatches.md`
  summary: Tighten api-changed matching (short keys, fields already used), keep a role override while another slot still maps the field on undo, bound and prune `field_types`, and teach publish readiness the `api_changed` blocker.
  evidence: Review findings; no UI or publish consumer exists yet.

- source_spec: `spec-3-13c-harden-the-mapping-api-for-the-map-data-ui.md`
  summary: Cross-story LATER items: one `SlotFit` shared by AutoMapper, the executor and 3.18 validation; machine `reason_code` beside every prose reason; a `ResolvesDraftActor` trait and shared error responses; throttles on the read routes; consistent empty-map encoding; shared `SamplePath::isStored` helpers.
  evidence: Cross-story review of 3.9-3.13a; none blocks the UI.

- source_spec: `spec-3-13c-harden-the-mapping-api-for-the-map-data-ui.md`
  summary: Derive the ETag before computing the checklist and include the contract version; read draft, sample and contract in one snapshot; re-check limits on a shape-cache hit; prune orphaned overrides after a record-path change.
  evidence: Review findings; none blocks the UI.

- source_spec: `spec-3-11b-map-data-step-roles-ui.md`
  summary: UI stages 2-4: slot checklist and Continue gating (3.12b), field picker, undo, Treat as, api-changed messages (3.13b), preview hotspots and source legend (3.14), APG field tree (3.11b remainder).
  evidence: Stage 1 builds the step shell and Roles section only.

- source_spec: `spec-3-11b-map-data-step-roles-ui.md`
  summary: Stage-1 UI polish: a test that interleaves autosave with a mapping write, role-chip type-ahead, section headings and focus when the step opens, match table columns to fields by path not display, move glyphs and plurals into the catalogue, and check the layout and chip colours in a real browser.
  evidence: Review findings; tests run in happy-dom, so sticky layout and colours were never seen in a browser.

- source_spec: `spec-3-12b-slot-checklist-ui.md`
  summary: Merge the two confidence meters, review row list semantics and unfilled-segment contrast, explain the Sample data badge, and add the preview client so the live preview refreshes after mapping writes.
  evidence: Review findings; the preview client is built with the hotspots stage because nothing in the front end calls the preview endpoint yet.

- source_spec: `spec-3-13b-field-picker-ui.md`
  summary: Add a "None" entry to clear an optional slot, support static text once the backend stores it, reopen the picker after a refused pick, list all fitting fields, and fetch fitting fields lazily instead of one GET per mismatch row.
  evidence: Review findings; the backend cannot store static text yet.

- source_spec: `spec-3-14-preview-hotspots-and-source-legend.md`
  summary: Check the Map Data step in a real browser (sticky preview, hotspot geometry, tooltip clipping, role-chip colours, focus behaviour), then add the `cannotChange` and `returnTo` tests and keep the payload icon.
  evidence: All Map Data UI tests run in happy-dom, which has no layout; stages 1 to 4 were never seen in a browser.

- source_spec: `spec-3-11c-datasets-suggestion-catalog.md`
  summary: Story 3.11d: the searchable, collapsible APG field tree (breadcrumb, "Use" per leaf, "Selected:" footer, type chips) and showing the Datasets suggestion in the record-path list.
  evidence: Remaining Story 3.11 UI; consumes the 3.11c `dataset_suggestion` and the 3.13b picker endpoints.

- source_spec: `spec-3-11c-datasets-suggestion-catalog.md`
  summary: Bound dataset growth per Endpoint and purge retired `dataset_fields` rows and datasets of removed Endpoints; flag a suggestion whose record path is not among the new sample's candidates; compute rollback steps in tests from a named migration.
  evidence: `app` cannot DELETE, so rows accumulate; review findings.
