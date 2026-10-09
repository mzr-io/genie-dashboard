<?php

/*
| Tunables (AR-57). Each is a named, environment-driven setting flagged
| `pending_input`: the client has not supplied the number, so none is invented
| and `value` is null until the environment sets it. Two exceptions are stated
| by the spec: `dispatch_tick` shows the proposed 5 s, and `require_https`
| defaults to off. Settings come from the environment only.
*/
$tunable = fn (string $env, mixed $default = null, mixed $proposed = null): array => array_filter([
    'env' => $env,
    'value' => env($env, $default),
    'proposed' => $proposed,
    'pending_input' => true,
], fn ($value, $key) => $key !== 'proposed' || $value !== null, ARRAY_FILTER_USE_BOTH);

return [

    'health' => [
        // Database connection the PostgreSQL health check probes (null = default).
        'connection' => env('HEALTH_DB_CONNECTION'),

        // Port nginx listens on inside the web container (local health check).
        'web_port' => (int) env('DASHFLOW_WEB_PORT', 8080),
    ],

    'tunables' => [

        'sync' => [
            'dispatch_tick' => $tunable('DASHFLOW_DISPATCH_TICK', 5, proposed: 5),
            'precompute_timeout' => $tunable('DASHFLOW_PRECOMPUTE_TIMEOUT'),
            'hot_window' => $tunable('DASHFLOW_HOT_WINDOW'),
            'cold_purge_after' => $tunable('DASHFLOW_COLD_PURGE_AFTER'),
            'prewarm_lead' => $tunable('DASHFLOW_PREWARM_LEAD'),
            'manual_refresh_window' => $tunable('DASHFLOW_MANUAL_REFRESH_WINDOW'),
            'refresh_intervals' => $tunable('DASHFLOW_REFRESH_INTERVALS'),
            'live_budget' => $tunable('DASHFLOW_LIVE_BUDGET'),
            'superseded_payload_grace' => $tunable('DASHFLOW_SUPERSEDED_PAYLOAD_GRACE'),
        ],

        'health' => [
            'probe_interval' => $tunable('DASHFLOW_HEALTH_PROBE_INTERVAL'),
            // Story 2.18: the rolling window, in whole seconds, over which `sync_runs` outcomes are counted for the success percentage.
            'window' => $tunable('DASHFLOW_HEALTH_WINDOW'),
            'sampling_interval' => $tunable('DASHFLOW_HEALTH_SAMPLING_INTERVAL'),
            'threshold_healthy' => $tunable('DASHFLOW_HEALTH_THRESHOLD_HEALTHY'),
            'threshold_degraded' => $tunable('DASHFLOW_HEALTH_THRESHOLD_DEGRADED'),
            'threshold_unreachable' => $tunable('DASHFLOW_HEALTH_THRESHOLD_UNREACHABLE'),
            'uptime_window' => $tunable('DASHFLOW_UPTIME_WINDOW'),
            'overview_metric_windows' => $tunable('DASHFLOW_OVERVIEW_METRIC_WINDOWS'),
        ],

        'retry' => [
            'base' => $tunable('DASHFLOW_RETRY_BASE'),
            'cap' => $tunable('DASHFLOW_RETRY_CAP'),
            'max_attempts' => $tunable('DASHFLOW_RETRY_MAX_ATTEMPTS'),
        ],

        'circuit_breaker' => [
            'failure_count' => $tunable('DASHFLOW_CIRCUIT_BREAKER_FAILURE_COUNT'),
            'cool_down' => $tunable('DASHFLOW_CIRCUIT_BREAKER_COOL_DOWN'),
        ],

        'guards' => [
            'max_pages' => $tunable('DASHFLOW_GUARD_MAX_PAGES'),
            'max_bytes' => $tunable('DASHFLOW_GUARD_MAX_BYTES'),
            'depth_limit' => $tunable('DASHFLOW_GUARD_DEPTH_LIMIT'),
            'platform_timeout_ceiling' => $tunable('DASHFLOW_GUARD_PLATFORM_TIMEOUT_CEILING'),
            'require_https' => $tunable('DASHFLOW_REQUIRE_HTTPS', false),
        ],

        'timeouts' => [
            'connect_timeout' => $tunable('DASHFLOW_CONNECT_TIMEOUT'),
            'total_timeout' => $tunable('DASHFLOW_TOTAL_TIMEOUT'),
            'draft_sample_ttl' => $tunable('DASHFLOW_DRAFT_SAMPLE_TTL'),
            'edit_lock_ttl' => $tunable('DASHFLOW_EDIT_LOCK_TTL'),
        ],

        'budgets' => [
            'max_hot_keys_per_workspace' => $tunable('DASHFLOW_BUDGET_MAX_HOT_KEYS_PER_WORKSPACE'),
            'max_fetch_rate_per_data_source' => $tunable('DASHFLOW_BUDGET_MAX_FETCH_RATE_PER_DATA_SOURCE'),
            'max_new_cold_keys_per_membership_per_hour' => $tunable('DASHFLOW_BUDGET_MAX_NEW_COLD_KEYS_PER_MEMBERSHIP_PER_HOUR'),
        ],

        'numbers' => [
            'decimal_scale' => $tunable('DASHFLOW_DECIMAL_SCALE'),
        ],

        'audit' => [
            'retention_floor' => $tunable('DASHFLOW_AUDIT_RETENTION_FLOOR'),
            'retention_default' => $tunable('DASHFLOW_AUDIT_RETENTION_DEFAULT'),
        ],

        'sessions' => [
            'idle_admin' => $tunable('DASHFLOW_SESSION_IDLE_ADMIN'),
            'idle_user' => $tunable('DASHFLOW_SESSION_IDLE_USER'),
            // Whole minutes a Remember-me cookie may last (Laravel's unit). Unset: Remember me adds nothing.
            'remember_me_duration' => $tunable('DASHFLOW_REMEMBER_ME_DURATION'),
            // Sign-in throttle per email and IP. Unset: Fortify's shipped 5 attempts per minute applies.
            'sign_in_max_attempts' => $tunable('DASHFLOW_SIGN_IN_MAX_ATTEMPTS'),
            'sign_in_decay_seconds' => $tunable('DASHFLOW_SIGN_IN_DECAY_SECONDS'),
            // Whole minutes a password-reset link stays valid. Unset: the starter kit's 60 minutes (config/auth.php).
            'reset_link_lifetime' => $tunable('DASHFLOW_RESET_LINK_LIFETIME'),
            // Reset-link request throttle per email and IP. Unset: 5 requests per 60 seconds.
            'reset_request_max_attempts' => $tunable('DASHFLOW_RESET_REQUEST_MAX_ATTEMPTS'),
            'reset_request_decay_seconds' => $tunable('DASHFLOW_RESET_REQUEST_DECAY_SECONDS'),
        ],

        'users' => [
            // Whole hours an invitation link stays valid. No default: `dashflow:workspace:create`
            // refuses to run until the environment sets it.
            'invitation_lifetime' => $tunable('DASHFLOW_INVITATION_LIFETIME'),
        ],

        'lists' => [
            // Largest page a list may return (a requested size above it is clamped). Unset: lists page by the
            // framework's default of 15 rows and a requested size is ignored, so no number is invented.
            'max_page_size' => $tunable('DASHFLOW_LISTS_MAX_PAGE_SIZE'),
        ],

        'profile' => [
            // Largest avatar upload, in bytes. Unset: PHP's own `upload_max_filesize` applies, so no number is invented.
            'avatar_max_bytes' => $tunable('DASHFLOW_AVATAR_MAX_BYTES'),
        ],

        'targets' => [
            'rpo' => $tunable('DASHFLOW_TARGET_RPO'),
            'rto' => $tunable('DASHFLOW_TARGET_RTO'),
            'uptime' => $tunable('DASHFLOW_TARGET_UPTIME'),
            'live_freshness' => $tunable('DASHFLOW_TARGET_LIVE_FRESHNESS'),
            'nfr2_latency' => $tunable('DASHFLOW_TARGET_NFR2_LATENCY'),
            'preview_latency' => $tunable('DASHFLOW_TARGET_PREVIEW_LATENCY'),
        ],

    ],

    // Outbound guard settings (Story 2.2). Each is `pending_input` with no default and kept outside `tunables`, the closed
    // AR-57 list. Unset: no deployment CIDR beyond the built-in undeniable ranges, no SSRF alert, and the operator grant
    // commands refuse to run (no password hash).
    'egress' => [
        // Comma-separated CIDRs of the deployment's own networks (database, cache, internal services): never reachable.
        'deployment_cidrs' => $tunable('DASHFLOW_EGRESS_DEPLOYMENT_CIDRS'),
        // The SSRF alert fires when one Workspace has more than `alert_threshold` blocks within `alert_window` seconds.
        'alert_threshold' => $tunable('DASHFLOW_EGRESS_ALERT_THRESHOLD'),
        'alert_window' => $tunable('DASHFLOW_EGRESS_ALERT_WINDOW'),
        // bcrypt hash of the operator's password, checked by `dashflow:egress:grant` and `:revoke`. Given to the operator service only.
        'operator_password_hash' => $tunable('DASHFLOW_EGRESS_OPERATOR_PASSWORD_HASH'),
    ],

    // Fetch fairness and concurrency (Story 2.17), kept outside `tunables` like `egress`. Both are `pending_input` with no default and
    // unset means off: `data_source_concurrency` is the most calls in flight per Data Source (a Valkey counter, leased for
    // `tunables.guards.platform_timeout_ceiling` when that is set), `workspace_fair_share` the most targets one dispatch tick takes per
    // Workspace. Retry, breaker and bucket numbers are `tunables.retry`, `tunables.circuit_breaker` and
    // `tunables.budgets.max_fetch_rate_per_data_source` (whole calls per minute).
    'fetch' => [
        'data_source_concurrency' => $tunable('DASHFLOW_FETCH_DATA_SOURCE_CONCURRENCY'),
        'workspace_fair_share' => $tunable('DASHFLOW_FETCH_WORKSPACE_FAIR_SHARE'),
    ],

    // Raw history retention (Story 2.16), kept outside `tunables` like `egress`. `max_window_days` is `pending_input` with no
    // default: the longest `window(N days)` an Admin may choose. Unset or malformed: a window is refused (422
    // `retention-window-unavailable`) and the form shows the option disabled; `latest` always works. The sweep's two timings are
    // `tunables.sync.superseded_payload_grace` and `tunables.sync.cold_purge_after` (whole seconds; unset or malformed: inert).
    'retention' => [
        'max_window_days' => $tunable('DASHFLOW_RETENTION_MAX_WINDOW_DAYS'),
    ],

    // The soft edit lock (Story 2.8), kept outside `tunables` like `egress`. The lock's TTL is `tunables.timeouts.edit_lock_ttl`
    // (seconds; unset = the soft lock is disabled and a form is protected by its `revision` only). `flush_timeout_seconds` is
    // `pending_input` with no default: how long a take-over waits for the holder to save and acknowledge before it completes
    // anyway. Unset: a take-over does not wait and discards the holder's unsaved changes, with a notice.
    'edit_lock' => [
        'flush_timeout_seconds' => $tunable('DASHFLOW_EDIT_LOCK_FLUSH_TIMEOUT_SECONDS'),
    ],

    // Write-only secrets (Story 2.4), kept outside `tunables` like `egress`. The platform's public key for the `cred`
    // purpose (base64 of a 32-byte X25519 key) and its version are `pending_input` with no default and are not secret:
    // every role may seal to it, only `worker-connector` holds the private key (the `key-cred` mount). Unset or invalid:
    // saving a secret is refused (503) and nothing is stored. The key file path defaults to the Compose secret mount.
    'secrets' => [
        'cred_public_key' => $tunable('DASHFLOW_SECRETS_CRED_PUBLIC_KEY'),
        'cred_key_version' => $tunable('DASHFLOW_SECRETS_CRED_KEY_VERSION'),
        'cred_key_path' => $tunable('DASHFLOW_SECRETS_CRED_KEY_PATH', '/run/secrets/key-cred'),
        // OAuth token cache (Story 2.7): the 32-byte key (base64) that seals cached access tokens is read, on worker-connector
        // only, from the `key-token` mount. A missing or placeholder key means a token is used for one call and never cached.
        'token_key_path' => $tunable('DASHFLOW_SECRETS_TOKEN_KEY_PATH', '/run/secrets/key-token'),
        // Sample Responses (Story 2.10): the 32-byte key (base64) that seals the body of an Endpoint test in the Valkey cache is
        // read from the `key-data` mount (web and the workers hold it). Unlike a token, a sample is never kept in the clear:
        // with no usable key the test fails with `blob_unavailable` and nothing is stored.
        'data_key_path' => $tunable('DASHFLOW_SECRETS_DATA_KEY_PATH', '/run/secrets/key-data'),
        // User attributes (Story 2.12): values are sealed under the same `data` key, and the 32-byte `digest` key (base64, the
        // `key-digest` mount, `web` only) keys the blind index that lets equal values be found without decrypting. With either
        // key unusable (as with the dev placeholders) saving or reading an attribute value answers 503 and nothing is stored.
        'digest_key_path' => $tunable('DASHFLOW_SECRETS_DIGEST_KEY_PATH', '/run/secrets/key-digest'),
    ],

    // OAuth2 client credentials (Story 2.7), kept outside `tunables`. `token_skew_seconds` is `pending_input` with no
    // default: a cached token lives `expires_in` minus this many seconds, and unset means no skew at all.
    'oauth' => [
        'token_skew_seconds' => $tunable('DASHFLOW_OAUTH_TOKEN_SKEW_SECONDS'),
    ],

    // Connection-test rate limits (Story 2.5), kept outside `tunables` like `egress`. Every one is `pending_input` with no
    // default: at most `membership_limit` tests per person and `workspace_limit` per Workspace in any `window` seconds. A
    // limit that is unset does not apply, and with no window nothing is limited. Over a limit the API answers 429 with
    // `retry_after` and enqueues nothing.
    'connection_test' => [
        'membership_limit' => $tunable('DASHFLOW_CONNECTION_TEST_MEMBERSHIP_LIMIT'),
        'workspace_limit' => $tunable('DASHFLOW_CONNECTION_TEST_WORKSPACE_LIMIT'),
        'window' => $tunable('DASHFLOW_CONNECTION_TEST_WINDOW'),
    ],

    // Endpoint-test (Fetch sample) rate limits (Story 2.10), kept outside `tunables` like `connection_test`. Every one is
    // `pending_input` with no default: at most `membership_limit` tests per person and `workspace_limit` per Workspace in any
    // `window_seconds`. A limit that is unset does not apply, and with no window nothing is limited. Over a limit the API
    // answers 429 with `retry_after` and enqueues nothing.
    'sample_fetch' => [
        'membership_limit' => $tunable('DASHFLOW_SAMPLE_FETCH_MEMBERSHIP_LIMIT'),
        'workspace_limit' => $tunable('DASHFLOW_SAMPLE_FETCH_WORKSPACE_LIMIT'),
        'window' => $tunable('DASHFLOW_SAMPLE_FETCH_WINDOW_SECONDS'),
    ],

    // Load-test harness targets (Story 1.25), read by `npm run load` (load/config.mjs) from the environment.
    // Each is `pending_input` with no default: the harness exits non-zero naming every variable that is unset.
    // Kept outside `tunables`, which is the closed AR-57 list. tests/Feature/LoadSettingsTest.php keeps this list
    // equal to load/config.mjs.
    'load' => [
        'base_url' => $tunable('DASHFLOW_LOAD_BASE_URL'),
        'email' => $tunable('DASHFLOW_LOAD_EMAIL'),
        'password' => $tunable('DASHFLOW_LOAD_PASSWORD'),
        'role' => $tunable('DASHFLOW_LOAD_ROLE'),
        'concurrency' => $tunable('DASHFLOW_LOAD_CONCURRENCY'),
        'duration_seconds' => $tunable('DASHFLOW_LOAD_DURATION_SECONDS'),
        'rate_ceiling' => $tunable('DASHFLOW_LOAD_RATE_CEILING'),
        'p95_target_ms' => $tunable('DASHFLOW_LOAD_P95_TARGET_MS'),
        'request_timeout_ms' => $tunable('DASHFLOW_LOAD_REQUEST_TIMEOUT_MS'),
        // Optional and development-only: `true` lets the harness target a host outside loopback and private networks.
        'allow_remote' => $tunable('DASHFLOW_LOAD_ALLOW_REMOTE'),
    ],

];
