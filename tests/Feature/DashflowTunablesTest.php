<?php

/** AR-57: every listed tunable exists as a named, env-driven `pending_input` setting. */
const AR57_TUNABLES = [
    'sync.dispatch_tick', 'sync.precompute_timeout', 'sync.hot_window', 'sync.cold_purge_after',
    'sync.prewarm_lead', 'sync.manual_refresh_window', 'sync.refresh_intervals', 'sync.live_budget',
    'sync.superseded_payload_grace',
    'health.probe_interval', 'health.sampling_interval', 'health.threshold_healthy',
    'health.threshold_degraded', 'health.threshold_unreachable', 'health.uptime_window',
    'health.overview_metric_windows',
    'retry.base', 'retry.cap', 'retry.max_attempts',
    'circuit_breaker.failure_count', 'circuit_breaker.cool_down',
    'guards.max_pages', 'guards.max_bytes', 'guards.depth_limit', 'guards.platform_timeout_ceiling',
    'guards.require_https',
    'timeouts.connect_timeout', 'timeouts.total_timeout', 'timeouts.draft_sample_ttl', 'timeouts.edit_lock_ttl',
    'budgets.max_hot_keys_per_workspace', 'budgets.max_fetch_rate_per_data_source',
    'budgets.max_new_cold_keys_per_membership_per_hour',
    'numbers.decimal_scale',
    'audit.retention_floor', 'audit.retention_default',
    'sessions.idle_admin', 'sessions.idle_user', 'sessions.remember_me_duration',
    'sessions.sign_in_max_attempts', 'sessions.sign_in_decay_seconds',
    'sessions.reset_link_lifetime', 'sessions.reset_request_max_attempts', 'sessions.reset_request_decay_seconds',
    'users.invitation_lifetime',
    'lists.max_page_size',
    'profile.avatar_max_bytes',
    'targets.rpo', 'targets.rto', 'targets.uptime', 'targets.live_freshness',
    'targets.nfr2_latency', 'targets.preview_latency',
];

const AR57_WITH_VALUE = ['sync.dispatch_tick' => 5, 'guards.require_https' => false];

it('has every AR-57 tunable as an env-driven pending_input setting', function (string $name) {
    $setting = config("dashflow.tunables.{$name}");

    expect($setting)->toBeArray("missing tunable {$name}")
        ->and($setting['pending_input'])->toBeTrue()
        ->and($setting['env'])->toMatch('/^DASHFLOW_[A-Z0-9_]+$/');
})->with(AR57_TUNABLES);

it('invents no value except the two the spec states', function () {
    foreach (AR57_TUNABLES as $name) {
        $value = config("dashflow.tunables.{$name}.value");

        array_key_exists($name, AR57_WITH_VALUE)
            ? expect($value)->toBe(AR57_WITH_VALUE[$name], $name)
            : expect($value)->toBeNull($name);
    }
});

it('shows the proposed 5 seconds only on dispatch_tick', function () {
    expect(config('dashflow.tunables.sync.dispatch_tick.proposed'))->toBe(5);

    $others = collect(AR57_TUNABLES)->reject(fn ($name) => $name === 'sync.dispatch_tick')
        ->filter(fn ($name) => array_key_exists('proposed', config("dashflow.tunables.{$name}")));

    expect($others)->toBeEmpty();
});

it('reads a tunable from its environment variable', function () {
    putenv('DASHFLOW_HOT_WINDOW=15');
    try {
        $config = require dirname(__DIR__, 2).'/config/dashflow.php';
        expect($config['tunables']['sync']['hot_window']['value'])->toBe('15');
    } finally {
        putenv('DASHFLOW_HOT_WINDOW');
    }
});

// Story 2.16: the maximum retention window is a pending_input setting kept outside the closed `tunables` list, like `egress`.
it('has the maximum retention window as a pending_input setting with no default, outside the tunables', function () {
    $setting = config('dashflow.retention.max_window_days');

    expect($setting)->toBe(['env' => 'DASHFLOW_RETENTION_MAX_WINDOW_DAYS', 'value' => null, 'pending_input' => true])
        ->and(config('dashflow.tunables'))->not->toHaveKey('retention');

    putenv('DASHFLOW_RETENTION_MAX_WINDOW_DAYS=90');
    try {
        $config = require dirname(__DIR__, 2).'/config/dashflow.php';
        expect($config['retention']['max_window_days']['value'])->toBe('90');
    } finally {
        putenv('DASHFLOW_RETENTION_MAX_WINDOW_DAYS');
    }
});

it('lists no tunable outside the AR-57 names', function () {
    $present = collect(config('dashflow.tunables'))
        ->flatMap(fn ($group, $area) => collect($group)->keys()->map(fn ($key) => "{$area}.{$key}"))
        ->all();

    expect($present)->toEqualCanonicalizing(AR57_TUNABLES);
});
