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
