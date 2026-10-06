---
title: 'Security and compliance lens review: Dashflow architecture'
reviewed:
  - ../ARCHITECTURE-SPINE.md (draft, 2026-10-05)
  - ../SOLUTION-DESIGN.md (draft v1.0, 2026-10-05)
context: 'Multi-tenant, domain-agnostic dashboard platform. Admins register REST APIs with credentials, the server fetches them, users see per-user data through user-context bound params. Possible regulated domains (healthcare, banking); regimes TBD.'
lens: security / compliance
date: 2026-10-05
---

# Security and compliance review: Dashflow architecture

## Verdict

The architecture has a sound security skeleton: a single egress boundary, RLS as a second layer, write-only secrets with envelope encryption, invalidation-only push, an append-only audit table, and grammar-only expressions. It is **not ready to bind stories for a regulated multi-tenant deployment yet.** Several invariants are stated but not designed through. The worst gaps:

- the per-user data scope fails open when a user has no attribute value;
- any Admin can read any user's per-user data, and can rewrite the attributes that define that scope;
- RLS is incompatible with the cross-tenant background jobs the design depends on;
- one cache bypasses tenancy;
- the SSRF guard does not cover every outbound URL the connector follows.

Each of these needs an AD amendment or a new AD before the Connector, Access and Platform-foundation epics are written.

Severity scale: **Critical** (cross-tenant or cross-user data exposure by design, or trivial exploitation), **High** (realistic exploitation or a regulated-domain blocker), **Medium** (defence-in-depth gap, or exploitable only with an insider or a misconfiguration), **Low** (hardening).

## Top findings (summary)

| # | Sev | Finding | Fix (one line) |
|---|---|---|---|
| S1 | Critical | A missing user-context attribute produces the empty digest, which is the shared-data Fetch Key, so the API gets an unfiltered request and the result is shared | Fail closed: a bound Block with an unresolved attribute returns `unavailable` and never fetches. Domain-separate the digest (`bound:` vs `shared`) |
| S2 | Critical | Fetch-as-user / preview-as (`blocks.edit` only) plus `users.manage` editing `user_attributes` (including your own) let any Admin read any user's per-user data | Add a separate `data.impersonate` permission, forbid self-edit of attributes, notify the target user, set a short TTL on samples from impersonated fetches, and offer dual control in regulated mode |
| S3 | High | RLS mechanics are unfinished: `SET LOCAL` needs an explicit transaction on every read; the dispatcher, outbox relay, retention purge, health sampler and mandatory propagation are cross-tenant, but the app role has no BYPASSRLS | Make a transaction-per-request/job middleware, use `current_setting('app.workspace_id', true)` fail-closed policies plus `FORCE ROW LEVEL SECURITY`, and add a narrow `system` role with column-limited grants for cross-tenant jobs |
| S4 | High | The SSRF guard covers "connect and redirect" only. Pagination next/Link/cursor URLs, the OAuth token URL, proxy mode, IP-literal canonicalization and SaaS private grants are not covered | Run one `EgressGuard::check(url)` on every URL, including token, next and redirect URLs. Restrict pagination to the same origin, canonicalize IPs, and keep a non-overridable deny-list of vendor CIDRs |
| S5 | High | The published-config cache (APCu/Valkey) is keyed only by version ID, so it reads around RLS, and APCu is shared by all tenants on a node | Prefix keys with `ws:{id}`, compare the cached `workspace_id` with the current context on every hit, and never resolve a client-supplied ID through the cache before the authorization check |
| S6 | High | Secrets and PII leak through telemetry and URLs: `api_key` in the query string, user-context values in path/query/headers, OTel `url.full`/header attributes, `sync_runs` errors, queue payloads; `http` allowed with credentials | Add an OTel span processor plus log scrubber that redacts query strings, auth headers and bound values. Forbid credentials over `http`. Store sanitized URLs only |
| S7 | High | ECharts tooltips (and string-template formatters) are HTML by default, so the FR-37 "escaped text" rule is not met by "Vue escaping + no v-html" | Mandate `tooltip.renderMode:'richText'` or escaped formatter functions in a shared chart wrapper. Lint against `formatter` strings and `innerHTML`. Add a Trusted Types CSP |
| S8 | High | No MFA, and `users.manage` can grant permissions it does not hold or edit global identity fields, in a product that holds API credentials and regulated data | Add TOTP/WebAuthn for the Admin area (mandatory per workspace setting). Only allow granting permissions you hold, protect the last admin, and keep global `users` fields out of workspace admin reach |
| S9 | High | Denial-of-wallet and parameter abuse: the client `?range=` alters the Fetch Key; Live 30 s × bound users × Blocks; the read-only-POST flag is self-declared and replayed on schedule | Accept only enumerated or bounded period presets, set per-workspace hot-key and fetch-QPS budgets, allow Live on bound Blocks only within a budget, and make POST need a separate permission and never be scheduled at Live |
| S10 | Medium | Audit integrity and export: CSV formula injection, before/after JSON holding attribute values, retention shortenable by a workspace admin, operator actions not audited in-workspace, no read-access audit | Neutralize `= + - @ \t \r` in CSV cells, add attribute values to the redaction allowlist, set an operator-defined retention floor, keep an operator audit stream, and offer optional data-access audit |

The detailed findings below cover these plus a further set (S11 to S26).

---

## 1. Tenant isolation

### S3 [High]: RLS cannot work as written

**Where:** AD-3, SD §10.1.

1. **`SET LOCAL` needs a transaction.** Laravel's ordinary reads run in autocommit. `SET LOCAL` outside a transaction is a no-op with a warning. The possible results:
   - the policy sees no setting;
   - with `current_setting('app.workspace_id')` and no `missing_ok`, every query errors;
   - if a developer "fixes" that with plain `SET` or `set_config(..., false)`, the value **persists on the pooled connection**. Under PgBouncer transaction pooling, the next tenant's transaction then inherits it. This is the classic cross-tenant RLS leak.

   **Fix:** middleware or job-middleware that wraps the whole request/job in `DB::transaction` and runs `set_config('app.workspace_id', $id, true)` as the first statement. Policies use `current_setting('app.workspace_id', true)::uuid`, which returns NULL and so matches no rows (fail closed). Add a CI test that runs a query with no context and asserts zero rows. Add a test under PgBouncer transaction mode that alternates tenants on one server connection.
2. **Table owner bypass.** State `ALTER TABLE ... FORCE ROW LEVEL SECURITY` on every tenant table, so that the `migrator` role, which owns the tables, is also subject to RLS when running data migrations. Data backfills then need an explicit context.
3. **Cross-tenant system work is undesigned.** The architecture depends on these jobs:
   - the sync dispatcher (`SELECT ... FROM sync_targets WHERE next_due_at <= now()` across all workspaces);
   - the outbox relay;
   - the retention purge;
   - health sampling;
   - search re-indexing;
   - the Admin overview materialized view;
   - mandatory-block propagation.

   The app role has no BYPASSRLS, and the spine says "cross-workspace operations exist only in operator commands". So these jobs either fail, or someone quietly gives the workers a bypass role. That would hand the most exposed process (worker-connector, which holds unwrap capability) a cross-tenant DB role.

   **Fix:** a new AD for a `system` DB role whose RLS policy grants cross-tenant SELECT **only on the columns needed for dispatch** (`sync_targets(id, workspace_id, next_due_at, hot)`, `outbox_events(id, workspace_id, status)`), through a security-barrier view or a `SECURITY DEFINER` function. Each dispatched job then re-enters tenant context with `workspace_id` from the payload and re-reads the row under RLS.
4. **Global tables are not enumerated.** Candidates: `users`, `sessions`, `password_resets`, `invitations`, `host_allowlist_entries` (if operator-global), `service_health_samples`, `operations`, `outbox_events`, Horizon/failed-jobs tables, Laravel `cache`/`jobs` tables if they are ever enabled.

   **Fix:** list in AD-3 every table that is *not* tenant-scoped, with the reason. Put RLS on the rest. A Pest architecture test fails if a migration creates a table without `workspace_id` that is not on the list.
   - `sessions` holds the active workspace. A session row must never be readable cross-user.
   - `failed_jobs` stores serialized job payloads cross-tenant. Purge it, or encrypt it.
5. **Job payloads are trusted for tenancy.** A job carries `workspace_id` plus IDs. A worker must set context from the payload **and** confirm that every referenced ID resolves under that context. A mismatch is a hard failure plus a security event. Without that check, a forged or buggy payload (see S15, Valkey) runs in the wrong tenant.

### S5 [High]: The published-config cache bypasses RLS

**Where:** SD §13: "Published config | Block Version, Endpoint, Dataset by ID | APCu/Valkey ... keys contain the version ID".

- These keys have no workspace prefix, which contradicts AD-3 ("Cache keys ... all start with the workspace ID").
- APCu is shared by every tenant served by the same php-fpm pool.
- A cache hit returns configuration without touching Postgres, so RLS never runs.
- The config includes the endpoint path, parameter bindings, the Query Plan and the Data Source base URL. Any endpoint that takes a version ID from the client exposes another tenant's config if the cache is consulted before (or instead of) the authorization check. Examples: `restore {version}`, old-version preview, `result.updated{block_version}` handling, the "About this block" view.
- UUIDv7 IDs leak through shared URLs, screenshots, support tickets and push events, so "unguessable" is not a control.

**Fix:**
- key as `cfg:{ws}:{type}:{id}`;
- store `workspace_id` in the cached value and assert it equals the current context on every hit;
- route cache reads through the same workspace-scoped repository (Layer 1), so the cache is an implementation detail of the repository and never a separate access path.

Do the same for `lock:draft:{block}`, `sync:{fetch_key}` (which is safe today only because the hash includes the workspace) and the OAuth token cache.

### S11 [Medium]: Reverb channel authorization and stale sockets

**Where:** AD-16, SD §11.2: "Already-open websocket channels re-authorize on reconnect."

- Membership removal, workspace suspension or a role downgrade leaves sockets subscribed until they reconnect, which can be hours. Payloads are IDs only, but these still leak:
  - activity metadata (which Blocks changed and when);
  - `notification.created`;
  - `draft.lock_taken` holder identity;
  - `operation.completed` IDs on the admin channel.
- **`workspace.{id}.admin` is coarse.** Every Admin, whatever their permissions, receives every admin event. That includes `operation.completed` for another Admin's fetch-as-user. Any follow-up `GET /operations/{id}` must check the **initiator** (or a permission), not just the workspace.
- **Channel auth checks.** The auth endpoint must verify all of these against the database on each auth: `{id}` equals the session's active workspace; `{membershipId}` belongs to the session user; the membership is active; and, for the admin channel, the role is admin.

**Fix:**
- On `access.changed`, membership removal or a role change, the outbox relay also calls Reverb's connection termination for that user (`pusher:...` terminate-user, or a forced-disconnect event the client must obey, backed by short-lived channel auth signatures).
- Split the admin channel by permission (`workspace.{id}.admin.{permission}`), or keep operation events on the initiator's user channel.
- Destroy the user's DB sessions for that workspace on membership removal.

### S12 [Medium]: Search index scope

`search_documents` must carry `workspace_id` under RLS, and must apply the `canUseBlock` SQL predicate *inside* the FTS query, not after a LIMIT. Filtering after a LIMIT leaks result counts and pagination gaps. Snippets must never be generated from Draft-only fields for non-admin areas. Draft titles and descriptions should be indexed separately from published ones.

### S13 [Medium]: Operator role and private-range grants in SaaS

- `allowlist:grant-private` in Model A (SaaS multi-tenant) lets one tenant's connector reach the **vendor's** private network: cluster CIDR, Postgres, Valkey, the Reverb internal port, cloud metadata through private endpoints, other customers' peered VPCs.
- The design says nothing about how operator commands are authenticated (shell access to a pod?), whether they need two people, or where they are audited.

**Fix:**
- A non-overridable deny-list of the deployment's own CIDRs and service IPs (from env/Helm values) that no grant can lift.
- In SaaS, private grants only to CIDRs reached through a per-tenant egress path (a dedicated egress proxy/NAT per tenant, or the agent).
- Operator commands write to a platform-level `operator_audit` stream and mirror into the target workspace's audit log.
- Use break-glass procedures and named operator identities, not a shared shell.

---

## 2. SSRF and the egress boundary

### S4 [High]: EgressGuard coverage and canonicalization

**Where:** AD-6, SD §10.2 SSRF row, §6.1.

The rule says the guard runs "on every connect and every redirect". The design has outbound URLs that are **not** a redirect:

1. **Pagination `link_header` and `cursor` next URLs.** These are server-controlled absolute URLs. A compromised or malicious source API (or an Admin pointing at their own server) returns `Link: <http://169.254.169.254/latest/meta-data/>; rel="next"` or `<https://other-tenant-api.example/...>`. The connector would send the Data Source's **credentials** there.

   **Fix:** a next URL must have the same scheme, host and port as the Endpoint's resolved origin (optionally a path prefix), and must also pass the full guard. A cursor is a token appended to the configured URL, never a URL.
2. **OAuth2 token URL.** This is a separate host that receives `client_id` and `client_secret`. It is an Admin-entered URL that is fetched with no allowlist requirement in the design.

   **Fix:** the token URL passes the same allowlist and guard, is pinned, never follows redirects, and only accepts a JSON response under a small size cap. Show the token-URL host in the allowlist UI.
3. **Redirects.** "Re-checked or refused" is ambiguous. **Fix:**
   - refuse cross-origin redirects by default;
   - on same-origin redirects, re-run the guard;
   - always strip `Authorization`, cookies and secret headers on any origin change;
   - never downgrade https to http;
   - cap the number of hops.
4. **IP literal and resolution canonicalization.** The address-class check must run on the **parsed binary address** after resolution (`inet_pton`), never on strings. Block or deliberately handle each of these:

   | Form | Example |
   |---|---|
   | IPv4-mapped IPv6 | `::ffff:127.0.0.1`, `::ffff:7f00:1` |
   | IPv4-compatible IPv6 | `::127.0.0.1` |
   | NAT64 | `64:ff9b::/96` |
   | 6to4 | `2002::/16` (embeds an IPv4) |
   | Teredo | `2001::/32` |
   | ULA | `fc00::/7` |
   | Site-local | `fec0::/10` |
   | Link-local | `fe80::/10` (and zone IDs `%eth0`) |
   | Unspecified | `0.0.0.0/8`, `::` |
   | CGNAT | `100.64.0.0/10` |
   | Benchmark | `198.18.0.0/15` |
   | Multicast and broadcast | — |
   | Metadata hostnames and IPs | `metadata.google.internal`, `169.254.169.254`, `fd00:ec2::254` (AWS IPv6 IMDS), `100.100.100.200` (Alibaba) |
   | Decimal, octal, hex, short forms | `2130706433`, `0177.0.0.1`, `0x7f.1`, `127.1` |

   - A host that is an IP literal should be rejected unless explicitly allowlisted as an IP.
   - **Check every A/AAAA record**, then pin one checked address with `CURLOPT_RESOLVE`.
   - Make sure Guzzle uses the curl handler (the stream handler ignores `CURLOPT_RESOLVE`), and that `CURLOPT_PROTOCOLS`/`REDIR_PROTOCOLS` are limited to http(s).
5. **Proxy mode defeats pinning.** With the "optional egress proxy", the proxy resolves DNS, so the platform's `CURLOPT_RESOLVE` check no longer applies (DNS rebinding comes back). **Fix:** when a proxy is configured, the proxy itself must enforce the address-class policy (for example a smokescreen-class proxy with deny-by-default and its own deny-list). Ignore the `HTTP_PROXY`/`http_proxy` env vars inside workers (httpoxy).
6. **`http` to private hosts.** Credentials over cleartext in a regulated environment. **Fix:** refuse `api_key`, `bearer`, `basic` and OAuth over `http` unless an operator sets a per-host `allow_cleartext_credentials` flag, with a persistent warning badge.
7. **The guard as an oracle.** "Test connection" error codes, timing and messages (`connection refused` vs `timeout` vs `TLS error`) give an Admin a port scanner against allowlisted ranges. **Fix:**
   - collapse the errors to a few user-facing codes and keep the details in operator logs;
   - rate-limit Test connection (S19);
   - audit and alert on repeated `connector.ssrf_blocked`.
8. **Response handling.** Apply `max_bytes` to the **decompressed** stream (gzip bombs). Enforce a JSON depth limit at parse time (`json_decode` depth), not only at compute time. Check Content-Type *and* that the body parses as JSON. Refuse `Transfer-Encoding` abuse by streaming with a hard cap.

### S14 [Medium]: URL and parameter injection into the upstream API

**Where:** SD §6.1 parameters, headers bound to user context, POST body template; FR-34 footer URL template.

1. **Path and query binding.** A user-context value or a period value inserted into a path or query can contain `/`, `../`, `?`, `#`, `&`, `%2F` or `%00`. Such a value can change the target resource (`/users/{id}` → `/users/../admin/export`) or add parameters (`region=EU&all=true`). Attribute values come from Admins (S2) or, later, from SSO/SCIM claims.

   **Fix:** bindings fill typed slots only. Path segments are percent-encoded per RFC 3986 segment rules (with `/`, `.` and `..` rejected). Query values are encoded by the HTTP client's query builder. Templates are parsed once at save time into a URL AST, never string-concatenated.
2. **Header binding.** Reject CR/LF and non-visible-ASCII in bound header values (header injection and request smuggling through badly-behaved proxies).
3. **POST body templates.** JSON-encode every bound value as a JSON string or number in a typed position. Never splice text into a raw template.
4. **Footer external URL template.** The PRD example is `https://erp.example.com/approvals?user={user.email}`.
   - It sends personal data to third-party hosts (in the URL, logs and Referer).
   - Field tokens taken from API data can change the authority (`https://{field}/...`), which enables phishing or an open redirect.

   **Fix:**
   - the scheme and host are fixed literals validated at publish time;
   - tokens only in path segments or query values, percent-encoded;
   - optionally a workspace allowlist of link hosts;
   - render with `rel="noopener noreferrer"` and `referrerpolicy="no-referrer"`;
   - classify `{user.*}` tokens as PII and show a publish-time warning.

### S16 [Medium]: The read-only POST flag

The `read_only_query` flag is the Admin's own assertion. A mistaken or malicious flag on a mutating endpoint has these consequences:

- the scheduler replays the POST every interval (Live: every 30 s) for every bound user;
- retries replay it again;
- Test, Fetch sample, Fetch as user and Validate each fire it.

**Fix:**
- Marking an Endpoint POST requires `data_sources.manage` **and** a confirmation that names the risk; it is audited.
- POST Endpoints get no automatic retries on ambiguous failures (timeouts).
- POST Endpoints are excluded from Live, or capped.
- Send an `Idempotency-Key` derived from the Fetch Key and interval slot.
- Show a "POST" chip on every Block that uses the Endpoint.

---

## 3. Per-user data scope and Admin abuse

### S1 [Critical]: A missing attribute fails open

**Where:** SD §6.2: `user_context_digest ... "" for shared-data Blocks`; §6.1 parameter bindings.

Suppose an Endpoint binds `region={user_context.region}` and a user has no `region` attribute: a new member, a removed attribute, or a typo in the key. The resolver then has three choices:

- send the request with an empty or omitted parameter. Many APIs treat that as "no filter" and return **all rows**;
- produce a digest that equals another user's, or the shared `""`;
- reuse the shared result.

The design does not say which. Every one of these leaks other users' rows in a healthcare or banking context.

**Fix (amend AD-7):**
- If any bound attribute is missing, empty or fails its type check, the item state is `unavailable` (with an Admin-visible reason `access.context_missing`) and **no fetch is made**.
- The digest input is domain-separated and includes the binding keys: `HMAC(k_ws, "v1|bound|" ‖ canonical({key: value}))`. The shared case is a distinct constant (`"v1|shared"`), never an empty string.
- An Endpoint can be flagged `requires_user_context`. A Block on such an Endpoint cannot be published without a binding, which closes the "Admin forgot to bind" path, an intentional design risk the PRD names.
- Publish validation includes a negative check: fetch as a user without the attribute and assert the Block refuses.

### S2 [Critical]: Admins can read any user's scoped data

**Where:** SD §11.2 (Fetch as user / Preview as needs `blocks.edit`), §7.2 (`user_attributes` editable under User configuration, `users.manage`), C5 (samples stored in the Draft), C10 (preview-as = fetch-as-user).

Two paths let an Admin see data they are not entitled to:

1. **Fetch-as-user.** Any holder of `blocks.edit` picks a user and receives the raw API response scoped to that user, for example a clinician's patient list or a relationship manager's client accounts.
   - The response is stored in the Draft. Every other Admin who opens the Draft sees it until the TTL expires.
   - "The UI shows attribute names, never values" protects nothing, because the **response** is the sensitive part.
2. **Attribute rewriting.** A holder of `users.manage` can set their *own* `region` or `employee_id` attribute to another person's value. They then see that person's data on their own dashboard. Nothing in the design forbids self-edits or flags them. Row scope rests entirely on attributes, so attribute editing is an authorization action, not profile maintenance.

**Fix:**
- A dedicated permission `data.preview_as_user` (off by default, never implied by `blocks.edit`). An optional workspace policy "preview only as designated test users".
- Samples from impersonated fetches:
  - are kept only in memory/Operation results with a short TTL (minutes);
  - are visible only to the initiator;
  - are never persisted into the shared Draft row.

  For mapping, prefer storing the **shape fingerprint** of the impersonated response, not its values.
- Audit records the target membership, the Endpoint and the Fetch Key (not the values). Optionally notify the target user ("An admin previewed data as you").
- Attribute changes are audited (with values hashed or redacted, see S10), cannot be self-applied, and can require a second admin in a regulated mode. Long term, source attributes from the IdP/SCIM so Admins cannot edit them.
- Treat attribute changes as an `access.changed` event: invalidate affected results and hot targets.

### S17 [Medium]: Area switching and invitations

- **Area.** "Area chosen at sign-in" must be enforced server-side on every Admin endpoint, as AD-4 says. Switching from User to Admin mid-session should re-authenticate (step-up, see S8). Remember-me sessions should not carry the Admin area.
- **Invitations.** These are unspecified. Required:
  - single-use, hashed-at-rest tokens with a short expiry;
  - bound to the invited email, so acceptance requires signing in as or creating that exact global identity;
  - roles and permissions in an invite capped by the inviter's own permissions;
  - invitation acceptance for an existing global user never changes that user's password or email;
  - non-enumerating responses (consistent with sign-in).
- **Global identity.** Workspace admins with `users.manage` must not be able to change a global user's email or password, or force a reset, for a user who also belongs to other workspaces. Otherwise one workspace's admin can take over the account in every workspace. Make global identity fields self-service or operator-only.

---

## 4. Secrets, keys and sensitive values

### S6 [High]: Leakage through telemetry, URLs and storage

**Where:** AD-24 ("payload bodies, secrets and user-context values are never logged"), SD §15, §6.1, §14.

That rule cannot hold with the current mechanics:

- **OTel auto-instrumentation** for Guzzle and PSR-18 records `url.full` (including the query string) and, depending on configuration, request and response headers. `api_key` in the query, user-context values in query or path, and bearer headers then go to the customer's or vendor's observability stack. **Fix:** a mandatory span processor and log processor that strips query strings and fragments, redacts a header allowlist (keeping only `content-type`, `etag` and similar), and drops `http.request.header.*`. Unit-test the processor against a fixture span.
- **`sync_runs` and Admin-visible errors.** Store a **sanitized** URL template plus a parameter-name list, never the resolved URL. Exception messages from curl and Guzzle embed the full URL, so wrap and sanitize them.
- **Queue payloads.** "No job carries secrets or payload bodies", but a resolved `FetchRequest` carries user-context values and query secrets in Valkey (and in `failed_jobs`). **Fix:** jobs carry `sync_target_id` only. The worker rebuilds the request from encrypted state. `failed_jobs` is purged after N days and never stores resolved requests.
- **The source's own access logs.** Prefer header-based API keys. Warn when an Admin chooses query-string key placement.
- **`http` with credentials:** see S4.6.

### S18 [High]: Key purposes and the plaintext-attribute inconsistency

1. `user_attributes (membership, key, value)` is stored **in plaintext** (SD §7.2), but the copy on `sync_targets` is "stored encrypted". The source of truth is the weaker copy, so the encryption is theatre. Decide one way: either attribute values are sensitive (encrypt both, with blind-index HMAC for lookups) or not.
2. **Who decrypts what.** AD-19 says only `worker-connector` holds unwrap capability. But:
   - `web` decrypts Draft samples for the in-process preview (C6);
   - `web` resolves user-context values to compute the Fetch Key digest;
   - the OAuth token cache is "encrypted", with no key named.

   **Fix:** define key purposes explicitly in AD-19:
   - `cred` (connector only);
   - `data` (Draft samples, user-context values; `web` + `worker-connector`);
   - `digest` (HMAC key for `user_context_digest`, per workspace, versioned);
   - `token` (OAuth cache, connector only).

   Each has its own DEK wrapped by the SecretVault, so that compromise of `web` never yields source credentials.
3. **HMAC digest key management.** A global HMAC key makes digests comparable across tenants. An unkeyed or low-entropy design lets anyone with DB access brute-force small attribute spaces (regions, branch codes). Rotation changes every Fetch Key, which causes a cold-cache and refetch storm. **Fix:** a per-workspace digest key with a `key_version` folded into the Fetch Key. Rotation is a planned operation that pre-warms the new keys or accepts dual keys during a window.
4. **The `local` keyring driver.** Specify where the keyring lives (a mounted secret, not the image or env). Specify that it is readable only by worker-connector pods (a separate Kubernetes Secret, not shared with `web`), and how rotation happens.
5. **The OAuth token cache.** Key it `oauth:{ws}:{data_source}:{secret_version}`, so a credential rotation invalidates it. Encrypt it with the `token` DEK. Never return it in errors or logs. Bound its TTL by `expires_in` and a platform cap.

### S15 [High]: Valkey is a code-execution trust boundary

Laravel queue payloads are PHP-`serialize`d command objects, and they are not signed by default. Anyone who can write to Valkey can inject a gadget-chain payload and get **code execution on worker-connector**, the one role that can unwrap every tenant's credentials. Valkey also holds `render_payload` (personal data) in memory and in any persistence files. "Disposable" means it holds no durable state; it does not mean it is low-trust.

**Fix:**
- Valkey ACL users per role, with key-pattern and command restrictions (`web` cannot `LPUSH` to `queues:fetch-*` directly except through the queue's own keys).
- TLS, plus a NetworkPolicy that allows only the platform pods.
- Persistence off, or on encrypted volumes.
- Consider signed job payloads (an HMAC over the payload, checked in a job middleware before `unserialize`, or an allowed-classes `unserialize`).
- Separate Valkey logical instances for queues and for cache/pub-sub in high-assurance deployments.

---

## 5. Rendering and client-side

### S7 [High]: ECharts tooltip HTML and formatter XSS

**Where:** AD-21 ("render API values as escaped text"), SD §10.2 Rendering ("Vue's default escaping; no v-html").

ECharts tooltips render as **HTML** (`renderMode: 'html'` is the default), outside Vue's escaping:

- **String templates** such as `formatter: '{b}: {c}'` substitute series, category and data names (API data) without escaping.
- **Function formatters** return strings assigned to `innerHTML`.
- `tooltip.extraCssText`, `axisPointer.label.formatter` and `legend.formatter` (canvas, safer) also need review.
- The default formatter encodes HTML in current versions. Any custom formatter that a renderer author adds to show units, deltas or Presentation Rules reopens the hole.

Category labels, series names and Text Template outputs all come from the source API, which may be third-party controlled.

**Fix:**
- A shared `useChart` wrapper is the only way renderers build ECharts options. It forces either `tooltip.renderMode: 'richText'` (canvas) or a formatter that builds DOM nodes or calls `echarts.format.encodeHTML` on every interpolated value.
- An ESLint rule bans `formatter:` string templates, `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `v-html` and `document.write` outside the wrapper.
- A renderer conformance test feeds `<img src=x onerror=...>` through every slot of every Block Type, including tooltip, legend, axis labels, table cells, list items, the calendar and the activity feed. It asserts there is no element injection.
- CSP: `script-src 'self'` with nonces (Inertia/Vite), `object-src 'none'`, `base-uri 'none'`, `frame-ancestors 'none'`, plus `require-trusted-types-for 'script'` where supported. ECharts and Tailwind may need `style-src 'unsafe-inline'`. Accept that, but never `script-src 'unsafe-inline'`.
- Link targets: parse with `URL`, allow only the `http:`/`https:` protocol or same-origin relative paths (blocking `javascript:`, `data:`, `vbscript:` and protocol-relative `//evil`), and use `rel="noopener noreferrer"`.

### S20 [Low]: Inertia page props

Inertia serializes page props into the `data-page` attribute. Confirm that Laravel's JSON encoding flags (`JSON_HEX_TAG` and so on) are on, and that no Block data travels as Inertia props (AD-20 already says only shell and CRUD form props). Make sure Admin CRUD props never contain secret values: a `{configured: true}` projection enforced by a Resource class, tested.

---

## 6. Audit integrity, export and retention

### S10 [Medium]: Audit integrity and CSV export

1. **CSV formula injection.** Audit fields contain Admin-controlled and source-controlled strings: Block names, Endpoint paths, URLs, error messages, user agents. An auditor who opens the export in Excel or Sheets can trigger `=HYPERLINK(...)`, `=WEBSERVICE(...)` or DDE payloads. **Fix:**
   - prefix any cell that starts with `=`, `+`, `-`, `@`, tab or CR with `'`;
   - quote all fields;
   - write UTF-8 with a BOM;
   - add a conformance test.

   Exports run as an Operation with a size cap, are audited (already listed), and use signed, short-lived download URLs.
2. **Before/after JSON.** The redaction allowlist must also cover:
   - user-attribute values (S2);
   - Draft samples;
   - Endpoint header values (even "plain" headers often carry tenant IDs);
   - OAuth scopes and client IDs (low risk);
   - any bound parameter values.

   Store a hash of the old and new value so changes stay detectable without disclosure.
3. **Retention floor.** `settings.manage` can shorten audit retention (FR-67), and the maintenance job then **drops partitions**. An insider can therefore destroy evidence by changing a setting and waiting a day. **Fix:**
   - an operator-defined minimum retention per deployment;
   - retention reductions take effect after a delay (for example 30 days), notify all Admins, and are themselves audited in a partition outside the window;
   - offer streaming of audit events to an external sink (SIEM, WORM object store).
4. **Tamper evidence.** INSERT/SELECT-only grants do not protect against the `migrator`, `maintenance` or DB-superuser roles. For regulated modes, make the per-partition hash chain (already proposed as optional) standard, with periodic anchoring (a signed digest exported off-box).
5. **Operator and system actions.** Cross-workspace operator commands and system jobs (retention purge, mandatory propagation) need audit entries too, attributed to `system` or a named operator.
6. **Read-access audit.** Only *denied* access is audited. HIPAA-style regimes expect records of *who viewed what*, and so may banking regimes. **Fix:** an optional, aggregated data-access log: `(membership, block, fetch_key, first_seen, last_seen, count)` per day, written asynchronously by the results path.
7. **Sign-in audit volume.** Failed sign-ins for unknown emails have no workspace. Define where they go (a platform audit), or they become a cross-tenant table.

### S21 [High for regulated domains]: Retention and erasure of personal data

**Where:** AD-9, SD §7.3, C1, C5.

1. **`latest` retention keeps data forever.** Cold `sync_targets` keep their last payload and their encrypted context values indefinitely. For per-user targets, that is one copy of each user's data per Endpoint, kept for life. **Fix:** a `cold_purge_after` tunable that deletes payloads, context values and Valkey results for targets not accessed in N days, while the shared health-probe target is kept.
2. **Removing users.** Membership removal deletes the user-context targets (good). Still to cover:
   - deleting the **global** user (across workspaces);
   - sessions;
   - notifications;
   - their dashboards;
   - Draft samples fetched *as* that user (S2);
   - Valkey `res:` keys;
   - search documents;
   - OTel and logs.

   "Pseudonymized display" in audit while keeping the actor ID is fine under most regimes, but write it down as an explicit legal-basis decision.
3. **Erasure of data subjects who are not platform users.** A patient or customer who appears inside API payloads cannot be erased by the platform except by expiring payloads. Document this, and keep `latest`/`cold_purge` short enough that erasure at the source propagates within a defined SLA.
4. **Backups.** PITR backups keep everything for the backup window. Erasure SLAs must state that window.
5. **Draft samples.** These may be pasted production data with PII. They are encrypted (good), but the Draft TTL is TBD and the Draft is shared among Admins. Add a hard maximum TTL and a "contains personal data" acknowledgement on paste. Clear the sample on Draft lock takeover by a different Admin if it came from fetch-as-user.
6. **Application-level payload encryption (Q-A3).** For regulated deployments this should default to **on**, with a key per workspace. That gives crypto-shredding: deleting a workspace DEK erases its payloads in backups too, which addresses item 4.

---

## 7. Abuse, rate limiting and cost

### S9 [High]: Denial-of-wallet and Fetch Key explosion

1. **Client-controlled period.** `GET /results?range=…` and the per-Block Period Selector feed `canonical(resolved_params)`. AD-7 says the key is "never from client input", which is true only if the range is an enumerated preset. Arbitrary `from`/`to` lets a user (or a script with their session) create unbounded distinct Fetch Keys. Each one triggers a cold `fetch-interactive` sync against the customer's API: upstream cost, rate-limit exhaustion for everyone, and Raw Store growth. **Fix:**
   - accept only the presets configured on the Block (`this_month`, `last_quarter`, and so on), resolved server-side;
   - if custom ranges are ever allowed, quantize them to days and cap the span;
   - cap distinct cold keys per membership per hour.
2. **Live (30 s) × user-context.** Load is Blocks × active users × 2,880 fetches/day each. One Admin publishing a bound Live Block to a 5,000-user workspace creates a self-inflicted DDoS on the source. **Fix:**
   - per-workspace budgets (max hot Fetch Keys, max fetch QPS per Data Source) enforced by the dispatcher;
   - Live allowed on bound Blocks only when the publish-time estimate (shown in the impact dialog) fits the budget;
   - degrade gracefully, by widening the effective interval and marking it, rather than queueing without bound.
3. **Interactive operations.** Test connection, Fetch sample, Fetch as user, Validate and preview (in-process in `web`, CPU-bound) need per-membership and per-workspace rate limits and concurrency caps. Pasted sample size needs a hard byte limit and a depth limit **before** parsing in `web`, so one Admin cannot pin php-fpm workers.
4. **Other endpoints.** Throttle: manual refresh (designed), sign-in (designed), password reset and invitation sends (email-bombing), channel auth, search, audit export and results batch size (max items per batch).
5. **Fair-share on the shared egress IP.** One tenant's abusive fetching can get the platform's egress IP blocked by a shared SaaS API provider, which affects other tenants. Use per-tenant egress IPs (for allowlisting customers too) in SaaS, or document the risk.

### S19 [Medium]: Port-scan and enumeration through interactive operations

This is covered in S4.7. In addition, the allowlist itself enumerates what the platform can reach. Restrict who can view the allowlist and grants to `data_sources.manage`.

---

## 8. Authentication

### S8 [High]: No MFA; privilege escalation within a workspace

- Email and password only (SSO deferred), with no MFA, for Admins who can:
  - register credentials;
  - point the platform's egress at internal hosts;
  - impersonate data scope (S2);
  - export audit logs.

  For healthcare and banking this is a likely procurement blocker, and it is the main account-takeover risk. **Fix:**
  - TOTP and WebAuthn in the `IdentityProvider` port for the MVP;
  - a workspace setting "require MFA for Admin area" (default on for new workspaces);
  - step-up re-authentication for secret changes, private-host requests, permission grants and audit export.
- **Permission escalation.** Design these rules:
  - `users.manage` can grant only permissions the granter holds;
  - nobody can modify their own permissions;
  - the last admin with `users.manage` cannot be removed or downgraded;
  - permission changes are `access.changed` events that end the target's Admin sessions.
- Session idle timeout and Remember-me are TBD. Set a shorter idle timeout for the Admin area than for the User area. Rotate the session ID on sign-in and on area or workspace switch.

---

## 9. Supply chain and platform hardening

### S22 [Medium]: Block Type packages are code

`block-types/{key}` contains a PHP Shaper (running in worker-compute and in `web` for preview) and a Vue renderer (running in every user's browser). If third-party or customer-contributed Block Types are ever allowed (FR-33 extensibility), they are full code execution in both tiers. **Fix:** state in AD-21 that Block Types are first-party, build-time only, and code-reviewed like core. They are never installed at runtime or per workspace. Architecture tests stop Shapers from using I/O, network, DB or `env()`.

### S23 [Medium]: Dependency hygiene

The design is right to include SBOM, signing and scans, which are [PROPOSED]. Make them binding:

- lockfiles committed;
- `composer audit` and `npm audit` (or OSV-Scanner) as **failing** gates for high/critical;
- Renovate/Dependabot with a grouping policy;
- npm `ignore-scripts` in CI where feasible;
- pinned base-image digests;
- cosign signatures verified at deploy (a Kyverno or admission policy in Helm);
- SLSA provenance for the image;
- no runtime CDN scripts.

Watch these components specifically:
- `grid-layout-plus`: a small maintainer base; spike-gated;
- `vue-echarts`;
- `symfony/json-path` 8.1: a new component, so fuzz it with hostile paths and payloads;
- `opis/json-schema`.

### S24 [Low]: Container and Kubernetes hardening

- non-root, read-only rootfs (stated);
- add `seccomp: RuntimeDefault`, dropped capabilities, `automountServiceAccountToken: false` (so the connector cannot read the Kubernetes API token if it is SSRF'd internally), and egress NetworkPolicy deny-by-default for every role except connector-to-guarded-egress;
- block IMDS at the node level (IMDSv2 hop limit 1, GKE metadata concealment) as a backstop to EgressGuard.

### S25 [Low]: Future agent transport

Define mTLS certificate issuance, rotation and revocation per agent. Bind agents to a workspace and a set of Data Sources. The agent must verify that a `FetchRequest` targets one of its own configured hosts, so a compromised platform cannot use the agent to pivot into the customer network beyond its allowlist. The agent gateway in `web` gives `web` a new long-lived inbound protocol: rate-limit it and isolate it as a separate route or role.

### S26 [Low]: CSRF and CORS

Sanctum SPA plus SameSite=Lax is fine for same-origin use. Make sure `/api/v1` has no permissive CORS, that state-changing endpoints reject `GET`, and that Reverb's allowed origins are pinned.

---

## 10. Compliance readiness notes (regimes TBD)

| Topic | Gap | Suggested architecture stance |
|---|---|---|
| Data classification | No classification on Data Sources | Add a `data_classification` (public/internal/confidential/regulated) on Data Source. It drives defaults: app-level payload encryption, short `cold_purge`, read-access audit, MFA, impersonation off |
| Residency | Region tag on workspace only | The tag must constrain the deployment, Valkey, backups and the OTel destination, or be removed to avoid a false assurance |
| Sub-processors | The OTel destination may be a vendor | Telemetry must be scrubbed (S6) before it leaves the trust boundary |
| Breach detection | Metrics exist; no security alerts | Alert on SSRF blocks, repeated denied access, impersonation bursts, audit export, MFA resets, and retention reductions |
| Access reviews | "Membership and permission listings" | Add the last-used time per permission, and an export for quarterly reviews |
| Key custody | KMS optional | Regulated deployments: KMS/HSM-backed SecretVault required, customer-managed keys optional |

---

## Suggested spine amendments (for the architect)

1. **AD-3:** transaction-per-request context, `current_setting(..., true)` fail-closed, FORCE RLS, an enumerated global-table list, a `system` role for cross-tenant dispatch, and job re-verification of tenancy. All caches workspace-prefixed with an ownership check on hit (S3, S5).
2. **AD-6:** the guard covers every URL (token, pagination next, redirect); same-origin pagination; binary IP canonicalization and the full deny-list; a vendor-CIDR deny-list no grant can lift; the proxy enforces policy in proxy mode; no cleartext credentials; collapsed errors (S4, S13).
3. **AD-7:** fail closed on missing context; domain-separated, keyed, versioned digest; enumerated period presets only; per-workspace hot-key budgets (S1, S9).
4. **New AD, "Data-scope impersonation":** a separate permission, initiator-only ephemeral samples, notification, audit, and no self-edit of attributes (S2).
5. **AD-19:** key purposes (`cred`, `data`, `digest`, `token`), with `web` never holding `cred`; attribute values encrypted at the source of truth (S18).
6. **AD-17:** Valkey is a trust boundary: ACLs, TLS, signed or allowed-class job payloads (S15).
7. **AD-21:** a chart wrapper with richText or escaped tooltips, a lint ban list, XSS conformance fixtures, Block Types first-party only (S7, S22).
8. **AD-18:** CSV neutralization, extended redaction, a retention floor with delay, operator and system audit, an optional read-access log, hash chain standard in regulated mode (S10).
9. **AD-24:** a mandatory OTel and log scrubbing processor, with sanitized URLs only (S6).
10. **New AD, "Authentication assurance":** MFA and step-up for the Admin area, the grant-only-what-you-hold rule, global identity fields out of workspace-admin reach (S8, S17).
