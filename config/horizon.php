<?php

use Illuminate\Support\Str;

/*
| Horizon runs in two worker roles of the one image. HORIZON_ENV selects the
| supervisors: "connector" (fetch queues) or "compute" (everything else).
*/

$supervisor = fn (array $queues, int $processes) => [
    'connection' => 'redis',
    'queue' => $queues,
    'balance' => 'auto',
    'autoScalingStrategy' => 'time',
    'minProcesses' => 1,
    'maxProcesses' => (int) env('HORIZON_MAX_PROCESSES', $processes),
    'tries' => 1,
    'timeout' => 60,
];

$connector = ['supervisor-connector' => $supervisor(['fetch-interactive', 'fetch-scheduled'], 4)];
$compute = ['supervisor-compute' => $supervisor(['compute', 'outbox', 'notifications', 'maintenance'], 4)];

return [

    'name' => env('HORIZON_NAME'),

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    'use' => 'queue',

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug((string) env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    'middleware' => ['web'],

    'waits' => [
        'redis:fetch-interactive' => 30,
        'redis:fetch-scheduled' => 120,
        'redis:compute' => 120,
        'redis:outbox' => 30,
        'redis:notifications' => 60,
        'redis:maintenance' => 300,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [],

    'silenced_tags' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 64,

    // Selects the supervisors below: connector or compute.
    'env' => env('HORIZON_ENV'),

    'defaults' => [],

    'environments' => [
        'connector' => $connector,
        'compute' => $compute,
        // Keep any other APP_ENV (local, testing) bootable with the compute set.
        'production' => $compute,
        'local' => $compute,
    ],
];
