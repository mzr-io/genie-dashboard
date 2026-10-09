---
title: 'Guard every outbound URL with EgressGuard and operator private-range grants'
type: 'feature'
created: '2026-10-07'
status: 'done'
baseline_commit: 'be4170fe2e057357611d53caaea27c7882957370'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-2-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-1-manage-the-workspace-host-allowlist.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-12-provision-a-workspace-and-let-its-first-admin-accept-an-invi.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Nothing yet stops an outbound request from reaching internal infrastructure through an allowlisted name, a DNS trick, a redirect or an unusual IP spelling, and an operator has no way to grant a private range for one Workspace (Story 2.2; FR-10, NFR-4, AR-8, AR-9, AR-25, AR-54, AR-31, AD-6, UX-DR-282). The Story 2.2 section of `epics.md` holds the acceptance criteria.

**Approach:** A Connector `EgressGuard` that decides every URL from the Workspace allowlist plus the parsed binary addresses of all resolved A and AAAA records, a pinned curl-only transport that re-guards every redirect and ignores proxy variables, an `egress_grants` tenant table managed only by three operator commands (`dashflow:egress:check`, `grant`, `revoke`), and a rate-based SSRF alert metric.

## Boundaries & Constraints

**Always:** `EgressGuard` (Connector contract, `decide(workspaceId, url)` returning a verdict with the pinned IP and a decision trail) evaluates in order: scheme `http` or `https` only; the host parsed with Story 2.1's `AllowedHost` rules, where any numeric IP spelling other than canonical (decimal, octal, hex, short forms) is treated as an IP literal and classified, never resolved; the host and port on the Workspace allowlist (else `denied: host_not_allowlisted`, message key `host-not-allowlisted`); then DNS through a `HostResolver` port (real implementation, faked in tests, so no test touches the network) returning every A and AAAA record. Each address is classified from `inet_pton` binary form by the one `BlockedAddress` classifier from Story 2.1, extended to return a class: **undeniable** (loopback, link-local, cloud metadata `169.254.169.254` and `fd00:ec2::254`, unspecified, multicast, IPv4-mapped and compatible, NAT64, 6to4, Teredo, documentation and benchmarking ranges, and the deployment's own CIDRs from an env setting flagged `pending_input`), **grantable private** (RFC 1918, CGNAT, ULA), or **public**. Any undeniable address, or any one bad record in a mixed answer, denies the whole request with `blocked_address` (key `blocked-address`) and no grant can lift it; a grantable private address is allowed only when an active grant for this Workspace covers it, else it is denied with `host_not_allowlisted`; a resolution failure or an empty answer denies. Every denial is returned as error code `connector.ssrf_blocked`, recorded by `Audit::recordSecurityEvent` as `connector.egress.blocked` (reason enum, host, port, request ID; never the resolved address), and counted for the alert: when the count of blocks for one Workspace passes `pending_input` threshold and window settings (declared outside the AR-57 tunable list, documented in `.env.example` and README, no defaults, the alert disabled when unset) the metric `dashflow.connector.ssrf_blocked` fires with no resolved address in labels or Admin-visible text. An `allowed` verdict carries the IP that was checked. The transport (`EgressTransport`) uses only the curl handler, sets `CURLOPT_RESOLVE` to that IP for the exact host and port, `CURLOPT_PROTOCOLS` and redirect protocols to http and https, ignores `HTTP_PROXY` and every other proxy variable (httpoxy), follows no redirect on its own, and handles each redirect itself: a different origin, a non-allowlisted host or an https-to-http downgrade is refused with credentials stripped on any origin change and the refusal audited as a security event, and a permitted redirect target is run through the whole guard again. `ext-curl` is declared in `composer.json`. Table `egress_grants` (UUIDv7 key, non-null `workspace_id`, `cidr`, `reason`, `granted_by`, `granted_at`, `revoked_at`, `revoked_by`) is a Connector tenant table with `ENABLE` and `FORCE ROW LEVEL SECURITY`, the standard policy, created as `migrator`; `operator` receives only the privileges the commands need and works under `WorkspaceTransaction::runIsolated('operator', ...)`; no delete exists, revocation sets `revoked_at`; the table is added to the ownership list, Cluster seeders and the `RolePrivilegesTest` operator assertion. Commands (in `app/Console/Commands`, touching Connector contracts only): `dashflow:egress:check {workspace} {url}` runs on the `app` connection through `WorkspaceTransaction`, prints `allowed` with the pinned IP and the decision trail or the denial, and sends no request; `dashflow:egress:grant {workspace} {cidr} --reason=` and `dashflow:egress:revoke {workspace} {cidr} --reason=` take a Workspace UUID, require the operator's password re-confirmation (a hidden prompt checked with `Hash::check` against a bcrypt hash in an env setting flagged `pending_input`; unset means the command refuses; three wrong attempts end it), accept a valid CIDR only, and refuse with a clear error and no row a CIDR that is undeniable, overlaps an undeniable range or a deployment CIDR, is public, or is already granted. Grant and revoke each write `operator_audit` and mirror the event into the Workspace audit log (`connector.egress_grant.created` and `.revoked`, cidr, grant id and a hashed reason, actor `operator:<user>`), as `dashflow:workspace:create` does. The guard reads grants and the allowlist as the Workspace (RLS), so one Workspace's grant never applies to another. Messages `host-not-allowlisted` and `blocked-address` come from the canonical catalogue (add them under Story 1.7's rules if absent).

**Never:** Sending any request to a Data Source in this story (no fetch pipeline); a grant that can lift an undeniable class; honouring proxy environment variables; following redirects inside curl; resolving a name twice between the check and the connect; returning or logging the resolved address to an Admin; an Admin-facing grant control; a default for any alert or deployment-CIDR setting; deleting grant rows.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Allowed | Allowlisted host, all records public | `allowed` with the pinned IP and trail; no request sent by `check` | N/A |
| Not allowlisted | Host absent from the allowlist | `denied: host_not_allowlisted`, `connector.egress.blocked` audited, code `connector.ssrf_blocked` | N/A |
| Undeniable address | Any listed class incl. odd spellings; mixed A/AAAA with one bad | Whole request denied `blocked_address`; every class has a test | N/A |
| Private, no grant | RFC 1918 answer | Denied with `host_not_allowlisted` wording | N/A |
| Private, granted | Active grant covering the address for this Workspace | Allowed for that Workspace only | Other Workspaces still denied |
| Grant | Valid private CIDR, correct password | Row stored; `operator_audit` and Workspace audit written | Wrong password, unset hash, undeniable, deployment, public or duplicate CIDR: refused, no row |
| Revoke | Active grant | `revoked_at` set; later requests denied; audited | Unknown grant: clear error |
| Rebinding | Name resolves public, then loopback | Connection uses the checked IP via `CURLOPT_RESOLVE`; loopback never reached | N/A |
| Redirect | Other origin, non-allowlisted host, https to http | Refused, credentials stripped, security event | N/A |
| Proxy env | `HTTP_PROXY` and friends set | Ignored | N/A |
| Alert | Blocks pass the configured threshold | Metric `dashflow.connector.ssrf_blocked` fires; no address leaks | Unset threshold: no alert |

</frozen-after-approval>

## Code Map

- `app/Modules/Connector/Contracts/BlockedAddress.php`, `AllowedHost.php`, `HostAllowlist.php`, `ErrorCode.php`, `Infrastructure/ConnectorAuditSerializer.php`, `Application/ManageHostAllowlist.php:42` (RLS read pattern) -- extend the classifier with address classes, add an allowlist lookup, `SsrfBlocked`, serializer fields; new `EgressGuard`, `HostResolver`, `EgressTransport`, grants contract and implementation under `app/Modules/Connector`
- `app/Console/Commands/WorkspaceCreateCommand.php` (`CONNECTION`, `actor()`, `operator_audit` insert, post-commit mirror via `WorkspaceTransaction::run` and `Audit::record`) -- pattern for grant and revoke; `app/Platform/Tenancy/WorkspaceTransaction.php:113,157` (`run`, `runIsolated`) -- guard and operator access
- `database/migrations/2026_10_07_150000_create_host_allowlist.php`, `2026_10_06_120000_create_invitations_and_operator_audit_tables.php`, `docker/postgres/initdb.sh` -- RLS, grant and role patterns (operator gets nothing by default)
- `app/Platform/Audit/{AuditAction,AuditField,Audit}.php`, `app/Support/Observability/MetricName.php` (name and labels only; no emitter exists, write one) -- audit cases (`connector.egress.blocked`, `connector.egress_grant.created|revoked`), metric
- `config/dashflow.php` (outside `tunables`, like the `load` block; `tests/Feature/DashflowTunablesTest.php`), `.env.example`, `README.md`, `compose.yaml` (`x-app-env` and `operator` env pass-through), `composer.json` (`ext-curl`)
- `tests/Architecture/dependencies.php` (add `egress_grants` to Connector), `tests/Database/Support/Cluster.php:218`, `tests/Database/RolePrivilegesTest.php:103,139`, `WorkspaceProvisioningTest.php` (command test pattern), `tests/Unit/AllowedHostTest.php` (matrix style), `tests/Security/CoverageManifestTest.php`, `resources/js/locales/en.ts` and `tests/js` catalogue tests -- test hooks

## Tasks & Acceptance

**Execution:**
- [x] migration (`egress_grants`, RLS, operator grants), Connector `EgressGuard`, `HostResolver`, address classes in `BlockedAddress`, grants service, audit actions and serializer, ownership list -- the guard and grants
- [x] `EgressTransport` (curl only, pin, no proxy, manual redirects) and the alert counter and metric emitter, config and env documentation, `composer.json` -- transport and alerting
- [x] `dashflow:egress:check`, `grant`, `revoke` commands with password re-confirmation, compose env, README -- operator surface
- [x] `tests/Unit`, `tests/Database`, `tests/Feature`, `tests/Architecture`, `tests/Security`, catalogue messages -- every matrix row, an address-class matrix (each class, odd spellings, mixed answers), rebinding, redirects, httpoxy

**Acceptance Criteria:**
- Given an operator grant for one Workspace, when the guard evaluates a private-range host for that Workspace and another, then only the first is allowed, and an undeniable class is denied whatever the grants.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including the Docker-backed `Database` suite.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| `100.100.100.200` (Alibaba metadata) is grantable inside CGNAT and `168.63.129.16` (Azure wireserver) is public | high | patch | `BlockedAddress` lists only `169.254.169.254` and `fd00:ec2::254`; the spec makes cloud metadata undeniable. |
| Caller headers may set `Host` and other framing headers, and the method is unvalidated, so a pinned IP can serve another virtual host | high | patch | `CurlEgressTransport::options` checks only CR, LF and NUL in names; the epic allows GET and read-only POST only. |
| The allowlist match ignores the entry scheme, so an `https` entry allows `http` on the same host and port | medium | patch | `isAllowed` matches host and port only; a downgrade sends plaintext and credentials. |
| An audit write failure raises a generic exception instead of `SsrfBlocked`; `unresolvable` counts toward the SSRF alert | medium | patch | `RecordEgressBlock::record` has no guard around `recordSecurityEvent`; a DNS outage would page as an attack. |
| `DnsHostResolver`, the timeout options and the mirror-failure path have no tests; interim `1xx` headers merge into the final response | medium | patch | Dropping `DNS_AAAA` or the timeout loop leaves every test passing; `NativeCurlClient` collects all header lines. |
| Non-bcrypt hash only fails after the prompt | low | patch | One-line check before prompting. |
| Nothing prevents a later story from calling out around the guard | medium | patch | No architecture rule on `curl_*`, `Http::` or Guzzle outside `NativeCurlClient`. |
| Grant and revoke cannot replay a failed Workspace-audit mirror; a retry fails as `already_granted` or `not_granted` | medium | defer | Needs a replay path or an outbox-style retry; the command warns, exits 1 and the `operator_audit` row is stored. |
| No response-size cap and no default timeouts in the transport | medium | defer | Timeouts are `pending_input` by rule; the size ceiling belongs to the Data Source limits in Story 2.6. No caller exists yet. |
| Single shared operator hash, no cross-run lockout, hash visible in container env | medium | defer | Recorded decision in Design Notes; real operator identities are a later decision, tracked in `deferred-work.md`. |
| Only the first resolved record is pinned, with no dual-stack fallback | low | defer | Availability only; `CURLOPT_RESOLVE` accepts several addresses and can be widened when Story 2.14 fetches for real. |
| Wider grant over a narrower one allowed; whole-ULA grant refused without a hint; fixed alert window; numeric-label host names; trailing-dot redirects; stale `Content-Type` on method switch; `increment` returning false; invalid deployment CIDR env throws; `varchar(64)` actor; dead `$bytes++`; no DB-level private check; revoke validation order untested; null-port audit not asserted | low | rejected | Safe, cosmetic or unlikely, and each fix adds branches or settings. |
| Cross-origin redirect refused instead of stripped and re-guarded | false | rejected | The criterion allows refusal ("the redirect is refused, credentials are stripped on any origin change"); a refused redirect sends nothing, so nothing carries credentials. |
| Catalogue messages not added | false | rejected | `host-not-allowlisted` and `blocked-address` already exist in `en.ts`, as the task says "if absent". |

## Design Notes

Undeniable versus grantable: classes that embed or alias another address (mapped, NAT64, 6to4, Teredo) or are never a legitimate destination (loopback, link-local, metadata, unspecified, multicast, documentation, benchmarking) cannot be granted; the three genuinely private ranges can. Story 2.1's deferred note on documentation and benchmark ranges is resolved here as undeniable. Reserved names such as `localhost` need no name rule because the guard classifies what they resolve to. The epic says "after password re-confirmation" without saying whose password a CLI checks and no operator account exists, so the check is a hidden-prompt password against a bcrypt hash held in an operator-only setting, refusing when unset; replacing it with real operator identities is a later decision. The decision-trail text uses reason codes, never addresses, for anything an Admin can see; only the operator command prints the pinned IP.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools npm run build` -- expected: exit 0
