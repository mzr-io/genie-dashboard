# Tech Verification (as of 2026-10-05)

Method: version and license data pulled live from the Packagist, npm and GitHub APIs (release tags plus LICENSE files), plus web search and fetch. Unverified or weakly verified items are marked **[UNVERIFIED]** or **[WEAK]**.

## 1. Laravel and first-party packages
| Item | Current version | License | Fit note | Source |
|---|---|---|---|---|
| Laravel framework | 13.x, released 2026-03-17; latest patch v13.34.0 (2026-09-29). Needs PHP 8.3–8.5. Bug fixes until Q3 2027, security fixes until 2028-03-17. L12 bug fixes ended 2026-08-13 (security until 2027-02-24). | MIT | Start on L13. L14 is expected around Q1 2027. L13 ships Queue::route, job attributes (Tries, Backoff), PreventRequestForgery and pgvector query support. | https://laravel.com/docs/13.x/releases ; https://repo.packagist.org/p2/laravel/framework.json |
| Horizon | v5.50.0 (2026-09-15) | MIT | **Redis queues only.** The docs say to run `queue:work database` alongside it for DB queues. | https://laravel.com/docs/13.x/queues |
| Reverb | v1.12.0 (2026-09-22) | MIT | First-party WebSocket server (Pusher protocol). Scaling out horizontally uses Redis pub/sub. | https://repo.packagist.org/p2/laravel/reverb.json |
| Octane | v2.20.0 (2026-08-23) | MIT | FrankenPHP/Swoole/RoadRunner. Watch for state leaking between tenants in long-lived workers. | https://repo.packagist.org/p2/laravel/octane.json |
| Sanctum | v4.3.3 (2026-06-23) | MIT | SPA cookie auth plus API tokens. Fits Inertia. | https://repo.packagist.org/p2/laravel/sanctum.json |
| Pulse | v1.8.1 (2026-08-20) | MIT | Ops dashboard. Can store data in Postgres. | https://repo.packagist.org/p2/laravel/pulse.json |
| Pennant | v1.26.0 (2026-08-13) | MIT | Feature flags with per-tenant scoping. | https://repo.packagist.org/p2/laravel/pennant.json |
| Vue starter kit | Inertia **3** (@inertiajs/vue3 3.8.0), Vue 3.5, TypeScript, Tailwind 4, **shadcn-vue on reka-ui** (reka-ui 2.11.0), Vite 8 | MIT | Matches the proposed stack. It does not include Pinia or Vue Router because Inertia uses server-side routing. | https://laravel.com/docs/13.x/starter-kits ; https://github.com/laravel/vue-starter-kit/blob/main/package.json |
| DB queue on Postgres | Uses `FOR UPDATE SKIP LOCKED` on PG ≥9.5 (since Laravel 7) | — | Viable for moderate production load, with no Redis needed. Horizon cannot manage it. The current docs make **no explicit production-grade claim [WEAK]**. | https://github.com/laravel/framework/pull/31287 |

## 2. PHP
| PHP | 8.5 (8.5.0 released 2025-11-20). Active support until 2027-12-31, security until 2029-12-31. 8.4: active until 2026-12-31. 8.2 security support ends 2026-12-31. | PHP License | Target 8.5 (L13 supports 8.3–8.5). | https://www.php.net/supported-versions |

## 3. Vue ecosystem (npm "latest" tags)
| Item | Version | License | Fit note | Source |
|---|---|---|---|---|
| Vue | 3.5.43 (2026-09-17). **3.6 is still pre-release** (3.6.0-rc.10). Vapor mode is feature-complete but not stable. | MIT | Pin 3.5. Don't adopt Vapor yet. | https://registry.npmjs.org/vue ; https://versionlog.com/vuejs/3.6/ |
| Vite | 8.3.2 (2026-10-01) | MIT | The starter kit already uses ^8. | https://registry.npmjs.org/vite |
| Pinia | 4.0.3 (2026-08-12) | MIT | Optional with Inertia. Use it for dashboard-builder client state. | https://registry.npmjs.org/pinia |
| Vue Router | 5.3.1 (2026-09-02) | MIT | Not needed with Inertia. | https://registry.npmjs.org/vue-router |
| TypeScript | 7.0.2 (2026-07-08, the native Go compiler). The starter kit pins ^5.2. | Apache-2.0 | Check vue-tsc compatibility before moving to TS 7 **[UNVERIFIED]**. | https://registry.npmjs.org/typescript |

## 4. PostgreSQL and extensions
| Item | Version | License | Fit note | Source |
|---|---|---|---|---|
| PostgreSQL | **18.6** (18.0 released 2025-09-25, EOL 2030-11-14). 17.11 EOL 2029-11-08. 16.15 EOL 2028-11-09. 14 EOL 2026-11-12. **PG 19 is in Beta 4** (2026-09-24) and has no GA yet. | PostgreSQL | Target PG18. RLS (since 9.5), declarative partitioning and JSONB are all native and mature. | https://www.postgresql.org/support/versioning/ |
| pg_partman | v5.5.0 (2026-07-22) | PostgreSQL License (GitHub reports "Other") | Time-based partitioning for synced snapshots. Available on RDS. | https://github.com/pgpartman/pg_partman/releases |
| TimescaleDB | 2.30.2 (2026-09-29) | Split license: Apache-2.0 core, **TSL** for `tsl/` (compression, continuous aggregates). TSL is free unless you resell it as a DBaaS. | **Not available on AWS RDS or Aurora.** Managed options are Tiger Cloud or self-hosting. Keep it optional. | https://github.com/timescale/timescaledb/blob/main/LICENSE ; https://www.tigerdata.com/docs/about/latest/timescaledb-editions |
| Citus | v14.2.0 (2026-08-06). 14.0 (Feb 2026) added PG18 support. | AGPL-3.0 | Active (Microsoft). Overkill unless you shard by tenant later. | https://www.citusdata.com/updates/v14-0/ |

## 5. Redis vs Valkey
| Item | Version | License | Fit note | Source |
|---|---|---|---|---|
| Redis OSS | 8.10.2 (2026-09-17) | Redis 8+ is tri-licensed RSALv2 / SSPLv1 / **AGPLv3**. 7.4 was RSAL/SSPL only. ≤7.2 was BSD. | OSI-licensed again through AGPL. | https://github.com/redis/redis/blob/unstable/LICENSE.txt ; https://redis.io/legal/licenses/ |
| Valkey | 9.1.2 (2026-09-01) | BSD-3-Clause | Works as a drop-in through phpredis or predis (RESP-compatible). Laravel Forge and Cloud offer managed Valkey. An optional GLIDE driver package exists. Recommended. | https://github.com/valkey-io/valkey ; https://cloud.laravel.com/docs/resources/caches |

## 6. BI / semantic layers
**All of these query SQL or warehouse sources. None of them pull REST APIs directly**, which validates the design of syncing into Postgres first.
| Item | Version | License | Multi-tenant embed / RLS | Source |
|---|---|---|---|---|
| Cube Core | v1.7.50 (2026-10-02) | Apache-2.0 backend, MIT clients | securityContext plus queryRewrite give row-level security. Multitenancy is built in. The best headless fit for Vue. | https://github.com/cube-js/cube/blob/master/LICENSE ; https://docs.cube.dev/embedding/multitenancy |
| Apache Superset | 6.1.0 (2026-05-13). Embedded SDK 0.4.0. | Apache-2.0 | Embeds through an iframe. A guest token carries RLS clauses (e.g. `tenant_id='x'`). | https://github.com/apache/superset/releases ; https://apache.googlesource.com/superset/+show/HEAD/superset-embedded-sdk/README.md |
| Metabase | v0.63.19 (2026-10-01) | AGPL (OSS) plus commercial `enterprise/` | OSS covers static and guest embeds, with a "Powered by" badge and no drill-through. **Interactive or SDK embedding, sandboxed RLS and white-labelling need Pro or Enterprise.** | https://github.com/metabase/metabase/blob/master/LICENSE.txt ; https://www.metabase.com/docs/latest/embedding/introduction |
| Grafana | v13.2.3 (2026-09-29) | AGPL-3.0 | Ops-focused. Tenant RLS embedding is weak. Not recommended for end users **[WEAK]**. | https://github.com/grafana/grafana |
| Apache ECharts | 6.1.0 (2026-05-19) | Apache-2.0 | Rendering library only. | https://github.com/apache/echarts/releases |
| Evidence | 40.1.8 (2026-02-06; no release since) | MIT | Static, code-based BI. Not designed for per-user dashboards. Its cadence has slowed **[WEAK]**. | https://github.com/evidence-dev/evidence |
| Lightdash | 2.427.0 (2026-10-05) | MIT core plus `ee/` commercial | JWT embedding with user attributes for RLS. Needs Cloud or Enterprise on-prem, and dbt. | https://docs.lightdash.com/embed/set-up-embedding |

## 7. Grid and charting (Vue 3)
| Item | Version | License | Fit note | Source |
|---|---|---|---|---|
| grid-layout-plus | 1.1.1 (2025-10-13), 2.0.0-beta.0 | MIT | Vue 3 native, port of vue-grid-layout. Repo is active (pushed 2026-08). **Recommended.** | https://github.com/qmhc/grid-layout-plus |
| vue3-grid-layout | 1.0.0 (2021-10-10) | ISC | **Abandoned.** Avoid. | https://registry.npmjs.org/vue3-grid-layout |
| gridstack.js | 14.0.0 (2026-09-21) | MIT | Framework-agnostic. Vue 3 needs a wrapper (Vue examples are in the repo). | https://github.com/gridstack/gridstack.js/releases |
| ECharts + vue-echarts | echarts 6.1.0. vue-echarts 8.3.1 (2026-09-28). | Apache-2.0 / MIT | Standard pairing. | https://registry.npmjs.org/vue-echarts |

## 8. Secrets
| Item | Version | License | Fit note | Source |
|---|---|---|---|---|
| HashiCorp Vault | v2.1.1 (2026-09-16). 2.0 shipped Apr 2026 under IBM. | BSL 1.1 (licensor IBM). Bars competing hosted or embedded offerings. | Internal use is OK. Embedding it in a SaaS needs a legal check. | https://github.com/hashicorp/vault/blob/main/LICENSE ; https://infoq.com/news/2026/04/vault-2-0-ibm-identity/ |
| OpenBao | v2.7.1 (2026-10-01) | MPL-2.0 | The OSI-licensed Vault fork (LF). | https://github.com/openbao/openbao/releases |
| KMS envelope encryption | aws/aws-sdk-php 3.399.1. google/cloud-kms v2.13.1. | Apache-2.0 | Pattern: a per-tenant data encryption key (DEK) wrapped by the KMS, with the DEK used through Laravel's `Encrypter`. There is no first-party Laravel KMS package **[UNVERIFIED for 3rd-party packages]**. | https://repo.packagist.org/p2/aws/aws-sdk-php.json |

## 9. OpenTelemetry PHP
| Item | Version | License | Fit note | Source |
|---|---|---|---|---|
| open-telemetry/sdk | 1.15.0 (2026-07-14). exporter-otlp 1.4.0. | Apache-2.0 | Traces, metrics and logs are all marked **Stable**. | https://opentelemetry.io/docs/languages/php/ |
| opentelemetry-auto-laravel | 1.9.1 (2026-09-17) | Apache-2.0 | Needs the `ext-opentelemetry` extension. Auto-instruments HTTP, queues and DB. | https://repo.packagist.org/p2/open-telemetry/opentelemetry-auto-laravel.json |

## 10. SSRF protection
| Item | Version | License | Fit note | Source |
|---|---|---|---|---|
| craftcms/url-validator | 1.1.0 (2026-07-06) | MIT | Defends against SSRF, DNS rebinding and cloud metadata access. Framework-agnostic. | https://packagist.org/packages/craftcms/url-validator |
| cboxdk/laravel-ssrf | v1.5.0 (2026-09-29), PHP ^8.4 | MIT | Laravel Http/Guzzle guard. **Low adoption (~3k downloads)**. | https://packagist.org/packages/cboxdk/laravel-ssrf |
| securized/laravel-ssrf | 1.0.0 (2026-07-22) | MIT | Similar. **Low adoption (~4k downloads)**. | https://packagist.org/packages/securized/laravel-ssrf |
| Pattern | — | — | Resolve the host, validate every IP, then pin it with `CURLOPT_RESOLVE`. Disable redirects or re-check each hop. Best combined with an egress proxy or network policy. | https://docs.typo3.org/p/netresearch/nr-vault/0.7/en-us/Developer/Adr/ADR-026-DnsRebindingDefence.html |

## 11. Private-network connectors
| Item | Version | License | Fit note | Source |
|---|---|---|---|---|
| Cloudflare Tunnel (cloudflared) | 2026.9.3 | Apache-2.0 (client) | Outbound-only. Ties you to the Cloudflare service. | https://github.com/cloudflare/cloudflared |
| Tailscale | v1.102.5 | BSD-3 (client). Coordination server is SaaS (Headscale is the OSS alternative). | Mesh VPN. Needs the customer to install it. | https://github.com/tailscale/tailscale |
| Fivetran / Retool | — | Commercial | Fivetran offers a Proxy Agent (outbound) and reverse SSH. Retool offers an RPC agent (reverse tunnel). Both validate the outbound-agent pattern. | https://fivetran.com/docs/connectors/databases/connection-options |
| frp | v0.71.0 (2026-08-14) | Apache-2.0 | Mature, self-hosted reverse proxy. | https://github.com/fatedier/frp |
| chisel | v1.12.0 (2026-08-29) | MIT | Tunnels TCP over HTTP/WebSocket via SSH. Simple to embed as an agent. | https://github.com/jpillora/chisel |
| inlets / inlets Uplink | inlets-pro 0.11.17 | **Commercial subscription** (not OSS) | Uplink is built specifically for SaaS-to-customer tunnels on Kubernetes. | https://inlets.dev/pricing |

## 12. JSONPath / JSON Schema
| Item | Version | License | Fit note | Source |
|---|---|---|---|---|
| symfony/json-path | v8.1.2 (2026-07-29), PHP ≥8.4.1 | MIT | **RFC 9535.** Backed by Symfony. Recommended. | https://packagist.org/packages/symfony/json-path |
| loilo/jsonpath | 0.3.1 (2026-01-26) | MIT | RFC 9535, passing about 99% of the compliance suite. Pre-1.0. | https://packagist.org/packages/loilo/jsonpath |
| ropi/json-path-evaluator | v1.1.0 (2023-12) | MIT | Claims it passes the RFC 9535 compliance suite. Stale. | https://packagist.org/packages/ropi/json-path-evaluator |
| softcreatr/jsonpath | 2.1.0 (2026-09-28) | MIT | Popular, but **does not claim RFC 9535** compliance. | https://packagist.org/packages/softcreatr/jsonpath |
| opis/json-schema | 2.6.0 (2025-10-17) | Apache-2.0 | Supports drafts 2020-12 and 2019-09. | https://packagist.org/packages/opis/json-schema |
| justinrainbow/json-schema | 6.13.1 (2026-09-30) | MIT | Actively maintained. Its draft 2020-12 coverage is **[UNVERIFIED]**. | https://packagist.org/packages/justinrainbow/json-schema |

## Flags
- Production-grade claim for the Laravel DB queue: the docs don't state it explicitly. It relies on SKIP LOCKED.
- Not checked: TypeScript 7 with vue-tsc compatibility, Grafana multi-tenant embedding specifics, justinrainbow 2020-12 support, third-party Laravel KMS packages.
- The Laravel SSRF packages are new and low-adoption. Treat them as references and own the guard code.
