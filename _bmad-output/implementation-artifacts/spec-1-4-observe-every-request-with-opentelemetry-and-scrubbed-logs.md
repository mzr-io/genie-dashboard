---
title: 'Observe every request with OpenTelemetry and scrubbed logs'
type: 'feature'
created: '2026-10-06'
status: 'in-review'
baseline_commit: '1397d614ad20471be077379b92aa3035acfd07d6'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-3-run-all-five-process-roles-locally-with-docker-compose.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Requests, jobs and logs cannot be tied together, logs go to stderr as plain text, nothing is exported over OTLP, and nothing stops secrets in URLs or headers from reaching telemetry (Story 1.4; AR-31, AR-53, NFR-13, NFR-4).

**Approach:** A request-context layer gives every request a `request_id`; JSON logs, spans and queued jobs carry it with `trace_id` and `workspace_id`. One scrubbing layer protects logs and spans. OTLP export is configured only by environment. The API error envelope strips `details` outside the Admin area.

## Boundaries & Constraints

**Always:** Valid incoming `X-Request-Id` (`[A-Za-z0-9._-]{8,64}`) is kept, anything else is replaced by a generated ULID; the response always carries it. Logs are one JSON object per line on stdout, with `request_id`, `trace_id`, `workspace_id` when known. Scrubbing is mandatory and not switchable by config: query strings and fragments are removed from every URL in telemetry, headers are dropped except an allowlist (`content-type`, `accept`, `user-agent`, `x-request-id`, `content-length`), and the same code serves logs and spans. Metric names match `dashflow.<module>.<measure>` and a helper rejects others. Queued jobs carry `request_id` in the payload and restore it before running. With no `OTEL_EXPORTER_OTLP_ENDPOINT` the app runs and logs exactly one warning per process. All settings come from environment; the Compose stack sets `LOG_CHANNEL=stdout`. Error envelope: `{error:{code, message, request_id, details}}`; `details` is emitted only when the request is marked Admin area (`request->attributes` key `area` = `admin`, set by future Admin middleware), so today it is always stripped.

**Never:** Real collector in Compose, dashboards, `sync_runs` URL templates (Epic 2), the signed-job work and queue/cache split (1.5), per-module metrics beyond the naming helper, a custom tracing SDK (use `open-telemetry/sdk` and auto-laravel already installed), logging request bodies.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| No request id | Request without `X-Request-Id` | Response header set; log lines and spans carry the same `request_id` | N/A |
| Bad request id | Header with spaces, over 64 chars or control chars | Replaced by a generated ID; the bad value is not logged | N/A |
| Job from request | Job dispatched in a request, run by a worker | Job logs and spans carry the originating `request_id` | Job without the field gets a fresh ID |
| Canary secret | Canary in query, fragment, `Authorization`, custom header, log context URL | Absent from log output, span attributes and metric labels | Test fails on any hit |
| No collector | `OTEL_EXPORTER_OTLP_ENDPOINT` unset | App serves requests; one warning line | Never throws |
| API error | Failing `api/v1` request | Envelope with `request_id`, `details` absent | 4xx/5xx body stays JSON |
| Bad metric name | `dashflow.Foo bar` | Helper throws `InvalidArgumentException` | N/A |

</frozen-after-approval>

## Code Map

- `bootstrap/app.php` -- register the request-context middleware (global, first) and the error envelope renderer; keep existing API JSON rule
- `config/logging.php`, `compose.yaml`, `.env.example` -- add a `stdout` JSON channel; Compose switches from `stderr` and passes `OTEL_*` through
- `app/Support/Observability/*` -- new home (not `app/Platform`, which the kernel rules scan): context holder, middleware, log processor, scrubber, metric-name helper, queue hooks, OTel bootstrap/warning
- `app/Providers/AppServiceProvider.php` -- wire queue payload hook and job-processing restore
- `composer.json` -- `open-telemetry/sdk`, `exporter-otlp` (add if missing; auto-laravel already present, ext in tools image)
- `routes/api.php`, `tests/Feature/PingTest.php` -- existing `api/v1` routes used to exercise the envelope
- `tests/Architecture/*` -- do not change; `app/Support` is outside the scanned trees

## Tasks & Acceptance

**Execution:**
- [ ] `app/Support/Observability/*`, `bootstrap/app.php` -- request context, middleware, response header, log processor adding ids, shared scrubber
- [ ] `config/logging.php`, `compose.yaml`, `.env.example` -- JSON stdout channel and `OTEL_*` env, one startup warning when no endpoint
- [ ] `app/Providers/AppServiceProvider.php` -- request id in job payload and restored on processing
- [ ] span scrubbing via an SDK span processor and attribute allowlist; metric-name helper
- [ ] error envelope renderer for `api/*` with `details` stripping
- [ ] `tests/Feature/ObservabilityTest.php`, `tests/Unit/*` -- every matrix row, with canary secrets across logs, spans (in-memory exporter) and metrics
- [ ] `README.md` -- variables and how to read the logs

**Acceptance Criteria:**
- Given the Compose stack, when a request is made, then stdout holds JSON lines whose `request_id` equals the response header.
- Given `bin/tools composer ci:check`, when run, then it passes including the canary tests.

## Implementation Notes

## Spec Change Log

## Review Triage Log

## Design Notes

`details` handling: the Admin area does not exist yet, so the rule is a request attribute future Admin middleware sets; the default is stripped, which is the safe side of UX-DR-162.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0
- `docker compose up -d`, `curl -si localhost:8080/up`, `docker compose logs web` -- expected: JSON line with the header's `request_id`
