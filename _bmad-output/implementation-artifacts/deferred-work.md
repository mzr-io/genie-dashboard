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
