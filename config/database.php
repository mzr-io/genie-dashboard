<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

/*
| Valkey connection for one store (AR-24). The role's own ACL user comes from
| VALKEY_USERNAME / VALKEY_PASSWORD; a store may override them. With the `tls`
| scheme the server certificate is verified against VALKEY_TLS_CA.
*/
$valkey = function (string $store) {
    $prefix = 'VALKEY_'.strtoupper($store).'_';
    $scheme = env($prefix.'SCHEME', 'tcp');

    return [
        'scheme' => $scheme,
        'host' => env($prefix.'HOST', '127.0.0.1'),
        'port' => env($prefix.'PORT', '6379'),
        'username' => env($prefix.'USERNAME', env('VALKEY_USERNAME')),
        'password' => env($prefix.'PASSWORD', env('VALKEY_PASSWORD')),
        'database' => 0,
        'context' => $scheme === 'tls' ? ['stream' => array_filter([
            'cafile' => env('VALKEY_TLS_CA'),
            'verify_peer' => filter_var(env('VALKEY_TLS_VERIFY', true), FILTER_VALIDATE_BOOL),
            'verify_peer_name' => filter_var(env('VALKEY_TLS_VERIFY', true), FILTER_VALIDATE_BOOL),
        ], fn ($value) => $value !== null)] : [],
        'max_retries' => env('REDIS_MAX_RETRIES', 3),
        'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
        'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
        'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
    ];
};

/*
| Optional PgBouncer transaction-mode switch (DB_PGBOUNCER). Off leaves the
| connection exactly as configured; on turns server-side prepared statements
| off, which transaction pooling does not support.
*/
$pgbouncer = filter_var(env('DB_PGBOUNCER', false), FILTER_VALIDATE_BOOL);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            'options' => [
                PDO::ATTR_TIMEOUT => (int) env('DB_CONNECT_TIMEOUT', 5),
            ] + ($pgbouncer ? [PDO::ATTR_EMULATE_PREPARES => true] : []),
        ],

        /*
        | The `migrator` role owns every table and runs migrations only
        | (`php artisan migrate --database=migrator`). The runtime never uses it.
        */
        'migrator' => [
            'driver' => 'pgsql',
            'url' => env('DB_MIGRATOR_URL'),
            'host' => env('DB_MIGRATOR_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('DB_MIGRATOR_PORT', env('DB_PORT', '5432')),
            'database' => env('DB_MIGRATOR_DATABASE', env('DB_DATABASE', 'laravel')),
            'username' => env('DB_MIGRATOR_USERNAME', 'migrator'),
            'password' => env('DB_MIGRATOR_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            'options' => [
                PDO::ATTR_TIMEOUT => (int) env('DB_CONNECT_TIMEOUT', 5),
            ],
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Valkey Stores
    |--------------------------------------------------------------------------
    |
    | Two stores on two instances (eviction policy is per instance): `queue`
    | and `cache`. There is no `default` connection on purpose.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        // Queues, locks, rate limits, Reverb fan-out and Horizon. Instance runs `noeviction`.
        'queue' => $valkey('queue'),

        // Results and tokens. Instance runs `allkeys-lru`; every key set has a TTL.
        'cache' => $valkey('cache'),

    ],

];
