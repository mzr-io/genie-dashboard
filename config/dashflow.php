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
            'remember_me_duration' => $tunable('DASHFLOW_REMEMBER_ME_DURATION'),
        ],

        'users' => [
            // Whole hours an invitation link stays valid. No default: `dashflow:workspace:create`
            // refuses to run until the environment sets it.
            'invitation_lifetime' => $tunable('DASHFLOW_INVITATION_LIFETIME'),
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

];
