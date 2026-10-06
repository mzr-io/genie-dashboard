---
title: 'Split Valkey into queue and cache stores with signed jobs'
type: 'feature'
created: '2026-10-06'
status: 'done'
baseline_commit: '8b2d10cbecbf815b9d2c3ce9dc40f3e6b1592f71'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-4-observe-every-request-with-opentelemetry-and-scrubbed-logs.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Queue and cache share one default Valkey connection with no eviction policy, TLS or per-role credentials, and queued payloads are executed without any integrity check (Story 1.5; AR-24, AR-52, AR-57, NFR-3, NFR-4).

**Approach:** Two Valkey stores (`queue` with `noeviction`, `cache` with LRU and TTL-only writes), TLS and per-role ACL users in Compose, HMAC-signed job payloads verified before unserialize, and every AR-57 tunable as a named env-driven `pending_input` setting.

## Boundaries & Constraints

**Always:** `maxmemory-policy` is per instance, so Compose runs two Valkey services, `valkey-queue` (`noeviction`) and `valkey-cache` (`allkeys-lru`). Laravel has connections `queue` (queues, locks, rate limits, Reverb fan-out, Horizon) and `cache`; the `cache` store wrapper rejects any write without a TTL (throws; tests rely on it). Sessions stay on the database driver. Valkey runs with TLS and ACL users per role, each limited to the stores that role needs; another role's credentials are refused. No persistence. Job payloads carry IDs only and an HMAC-SHA256 signature checked with `hash_equals` before any unserialize; a failed check logs a security event (no payload content) and the job is never run or retried. Tunables in `config/dashflow.php` are `env(...)` settings with a `pending_input` flag and no invented value, except `dispatch_tick` (proposed 5 s). Settings come from environment only. **Signing key (decided):** a dedicated job-signing key is derived from `APP_KEY` with domain-separated HMAC (fixed context label, e.g. `HMAC-SHA256(APP_KEY, "dashflow.queue.job-signature.v1")`); the raw `APP_KEY` never signs payloads. AR-50 mounts and `ComposeKeysTest` stay unchanged. README documents that rotating `APP_KEY` invalidates signatures of already queued jobs.

**Never:** PgBouncer service or the pooled tenant-isolation test (Story 1.10); real keys or certificates committed; the AR-50 secret-mount rules changed; moving domain events, notifications or audit into Valkey; `allowed_classes` fallback unless the signing hook proves impossible (record in Implementation Notes).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Cache write without TTL | `Cache::store('redis')->put/forever` with no TTL | Rejected | Throws, nothing stored |
| Signed job | Enqueued job, picked up by worker | Signature valid, job runs | N/A |
| Tampered payload | Payload altered in Valkey | Not unserialized or run; security event logged | Job failed, not retried |
| Unsigned payload | No signature field | Same as tampered | Same |
| Wrong role credentials | Role connects with another role's ACL user | Refused | Health check names the failure |
| Missing tunable | AR-57 setting absent from config | Test fails | N/A |
| PgBouncer switch | Env flag off / on | Default connection unchanged / transaction-mode settings applied | N/A |

</frozen-after-approval>

## Code Map

- `config/database.php` -- `redis` block: replace `default`/`cache` with `queue` and `cache` connections (TLS, ACL user, per-store host); Reverb scaling and Horizon use `queue`
- `config/cache.php` -- `redis` store points at `cache`; `lock_connection` at `queue`; wrap with the TTL-enforcing store
- `config/queue.php`, `config/horizon.php` -- redis queue and Horizon `connection` use `queue`
- `compose.yaml` -- split `valkey` into `valkey-queue` / `valkey-cache`; TLS cert generation one-shot, ACL file, per-role env credentials; `depends_on`, healthchecks
- `app/Support/Health/*` -- Valkey check must probe both stores with the role's own user
- `app/Support/Observability/QueueContext.php` -- existing `createPayloadUsing` and `JobProcessing` hooks; the signer sits beside it, not inside it
- `app/Providers/AppServiceProvider.php` -- register signing hook and TTL-enforcing cache wrapper
- `config/dashflow.php` -- add AR-57 settings; `tests/Architecture/ComposeKeysTest.php` -- do not change
- `.env.example`, `README.md` -- new variables and how to read the stores

## Tasks & Acceptance

**Execution:**
- [x] `config/database.php`, `config/cache.php`, `config/queue.php`, `config/horizon.php`, `.env.example` -- two connections, session stays on `database`, optional PgBouncer env switch
- [x] `app/Support/Queue/*`, `app/Providers/AppServiceProvider.php` -- TTL-enforcing cache wrapper; signed-payload creation and verification before unserialize, key derived from `APP_KEY` as decided; security-event log
- [x] `compose.yaml`, `docker/valkey/*` -- two instances, TLS, ACL users per role, health checks
- [x] `app/Support/Health/*` -- probe both stores over TLS with the role's credentials
- [x] `config/dashflow.php` -- every AR-57 tunable, grouped by area, flagged `pending_input`
- [x] `tests/Feature/*`, `tests/Unit/*`, `tests/Architecture/*` -- every matrix row; config inspection test listing the required AR-57 names; Compose test for ACL and TLS keys
- [x] `README.md` -- stores, credentials, tunables

**Acceptance Criteria:**
- Given the Laravel config, when inspected, then connections `queue` and `cache` exist and `SESSION_DRIVER` defaults to `database`.
- Given the Compose stack, when a role connects with another role's credentials, then Valkey refuses it, and `bin/tools composer ci:check` passes.

## Implementation Notes

- Signing hook: `Queue::createPayloadUsing` runs before the command is serialized (`data.command` is still the job object), so it cannot sign the serialized string. The signature is added by a `SignedRedisQueue` (extends Horizon's queue, installed by replacing the `redis` queue connector after Horizon's), verification is a `JobProcessing` listener that deletes the job and raises `JobFailed` so `failed()` (which unserializes) never runs. No `allowed_classes` fallback was needed.
- Only drivers named `redis` are verified; sync and database queues are not signed.
- Reverb's async Valkey client sends `AUTH <password>` only, so on `valkey-queue` the realtime role is the `default` user (restricted to pub/sub) and the `default` user is off on `valkey-cache`.
- `cache.limiter` and `cache.schedule_store` select the `queue` store only when `CACHE_STORE=redis`, so tests and non-Valkey setups keep working.
- `increment`/`decrement` on a missing key is rejected too (it would create a key without TTL); the check is a read before the write, not atomic.
- Health check keys are now `Valkey queue` and `Valkey cache`; realtime starts with `-d openssl.cafile` so Reverb trusts the Valkey CA.

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| Horizon retry rewrites `uuid`, signature then mismatches | medium | patch | `RetryFailedJob::preparePayload` sets new `uuid`/`id` and calls `pushRaw`, which does not re-sign; retried job is rejected. |
| `cache:clear` / `Cache::flush` denied on cache store | medium | patch | `cache.acl` app roles have `-@dangerous`, which includes `FLUSHDB`. |
| Legacy string-job `data` and `maxTries`/`timeout`/`backoff` unsigned | medium | patch | `JobSigner::signature` covers only `job`, `uuid`, `data.commandName`, `data.command`. |
| Schedule store, cache lock connection and Reverb values only checked as keys | medium | patch | Verification-gap layer: deleting `Schedule::useCache`, `setLockConnection` or changing Reverb host leaves tests green. |
| First-deploy rollout of signing undocumented | low | patch | README only mentions `APP_KEY` rotation; pre-upgrade unsigned jobs are rejected. |
| README says non-positive TTL throws | low | patch | `Repository::put` with TTL <= 0 forgets the key before the store is reached. |
| Wrong-role credentials refusal not executed in CI | medium | defer | Needs a Valkey container in CI; verified manually on the rebuilt stack. |
| `JobProcessed` raised after rejection, forged payload stored in `failed_jobs` | low | rejected | Worker raises it for any deleted job; the payload is never run, and the patched retry path re-verifies before re-signing. |
| Removed `default` Redis connection breaks unnamed callers | false | rejected | No `Redis::connection()` callers in app/routes/config; Horizon `use` is `queue`. |
| `QueueContext` restores request id before signature check | false | rejected | `RequestContext::enter` sanitizes the candidate id, so a forged value cannot inject content. |
| No `APP_PREVIOUS_KEYS` overlap | false | rejected | User decision A: rotation invalidates queued signatures, documented. |
| Replay / queue name unsigned | low | rejected | Requires Valkey write access, which already allows pushing any valid job; accepted limit. |
| Empty `APP_KEY`, fail-open on unknown driver, provider order, `cache::extend` timing | low | rejected | App cannot boot without `APP_KEY`; fixes add branches for unreached states. |
| Atomic `increment`/`decrement`, `tags()`, tunable typing, TLS script expiry, maxmemory docs | low | rejected | Noted in spec or dev-only; fixes add complexity for unlikely cases. |

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0
- `docker compose up -d --build`, then `docker compose exec web php artisan dashflow:health web` and `docker compose exec valkey-queue valkey-cli --tls ... INFO` -- expected: healthy; `maxmemory_policy` `noeviction` on queue and `allkeys-lru` on cache
