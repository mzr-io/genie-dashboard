<?php

namespace Tests\Database\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use PDO;

/**
 * Connections and fixtures for the Database suite. Everything here talks to the real PostgreSQL
 * started by bin/test-db; a connection failure throws (there is no skip).
 */
final class Cluster
{
    public const DATABASE = 'dashflow_test';

    private static bool $migrated = false;

    /**
     * Refuses to run against anything but the throwaway test database: the suite runs `migrate:fresh`
     * and truncates every table. Reads the process environment (phpunit.database.xml forces the values).
     *
     * @param  array<string, string|null>|null  $env  overrides the process environment (for the guard's own test)
     */
    public static function guard(?array $env = null): void
    {
        $get = fn (string $key): ?string => $env !== null
            ? ($env[$key] ?? null)
            : (($value = getenv($key)) === false ? null : $value);

        $expected = [
            'DB_DATABASE' => self::DATABASE,
            'DB_MIGRATOR_DATABASE' => null,
            'DB_PORT' => '56432',
            'DB_MIGRATOR_PORT' => '55432',
            'DB_HOST' => '127.0.0.1',
            'DB_MIGRATOR_HOST' => '127.0.0.1',
        ];

        foreach ($expected as $key => $value) {
            $actual = $get($key);

            // An unset migrator database falls back to DB_DATABASE, which is checked itself.
            if ($value === null ? ($actual !== null && $actual !== self::DATABASE) : $actual !== $value) {
                throw new \RuntimeException("Refusing to run the Database suite: {$key} is ".var_export($actual, true).' (expected '.var_export($value ?? self::DATABASE, true).').');
            }
        }
    }

    public static function postgresPort(): int
    {
        return (int) env('DASHFLOW_TEST_PG_PORT', 55432);
    }

    public static function pgbouncerPort(): int
    {
        return (int) env('DASHFLOW_TEST_PGBOUNCER_PORT', 56432);
    }

    /**
     * @param  array<int, mixed>  $options
     */
    public static function connect(string $user, string $password, int $port, string $database = self::DATABASE, array $options = []): PDO
    {
        return new PDO(
            "pgsql:host=127.0.0.1;port={$port};dbname={$database}",
            $user,
            $password,
            $options + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 5],
        );
    }

    /** The bootstrap superuser, direct to PostgreSQL: seeds data and inspects roles. */
    public static function superuser(): PDO
    {
        return self::connect('postgres', (string) env('DASHFLOW_TEST_SUPERUSER_PASSWORD'), self::postgresPort());
    }

    /**
     * Role `app` through PgBouncer: a new client (one shared PostgreSQL server connection).
     *
     * @param  array<int, mixed>  $options  PDO options, for example emulated prepares
     */
    public static function pooledApp(array $options = []): PDO
    {
        return self::connect('app', (string) env('DB_PASSWORD'), self::pgbouncerPort(), self::DATABASE, $options);
    }

    /** Role `app` straight to PostgreSQL. */
    public static function directApp(): PDO
    {
        return self::connect('app', (string) env('DB_PASSWORD'), self::postgresPort());
    }

    public static function maintenance(): PDO
    {
        return self::connect('maintenance', (string) env('DASHFLOW_TEST_MAINTENANCE_PASSWORD'), self::postgresPort());
    }

    /** PgBouncer's admin console. */
    public static function pgbouncerAdmin(): PDO
    {
        return self::connect('pgbouncer_admin', (string) env('DASHFLOW_TEST_PGBOUNCER_ADMIN_PASSWORD'), self::pgbouncerPort(), 'pgbouncer', [PDO::ATTR_EMULATE_PREPARES => true]);
    }

    /** Drops and re-runs every migration as role `migrator`, once per process. */
    public static function migrateOnce(): void
    {
        self::guard();

        if (self::$migrated) {
            return;
        }

        $code = Artisan::call('migrate:fresh', ['--database' => 'migrator', '--force' => true]);

        if ($code !== 0) {
            throw new \RuntimeException('migrate:fresh as migrator failed: '.Artisan::output());
        }

        self::$migrated = true;
    }

    public static function truncate(): void
    {
        self::guard();

        $tables = array_values(array_diff(self::allTables(), ['migrations']));

        if ($tables !== []) {
            self::superuser()->exec('TRUNCATE '.implode(', ', array_map(fn (string $t): string => '"'.$t.'"', $tables)).' RESTART IDENTITY CASCADE');
        }
    }

    /**
     * @param  array<int, mixed>  $params
     * @return list<array<string, mixed>>
     */
    public static function rows(PDO $pdo, string $sql, array $params = []): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * Tables in `public` that carry a `workspace_id` column.
     *
     * @return list<string>
     */
    public static function tenantTables(): array
    {
        $rows = self::rows(self::superuser(), <<<'SQL'
            SELECT c.relname FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            JOIN pg_attribute a ON a.attrelid = c.oid AND a.attname = 'workspace_id' AND NOT a.attisdropped
            WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p')
            ORDER BY c.relname
            SQL);

        return array_column($rows, 'relname');
    }

    /**
     * Every ordinary table in `public`.
     *
     * @return list<string>
     */
    public static function allTables(): array
    {
        $rows = self::rows(self::superuser(), <<<'SQL'
            SELECT c.relname FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p')
            ORDER BY c.relname
            SQL);

        return array_column($rows, 'relname');
    }

    public static function workspace(string $name): string
    {
        $id = (string) Str::uuid7();
        self::superuser()->prepare('INSERT INTO workspaces (id, name, status, created_at, updated_at) VALUES (?, ?, ?, now(), now())')
            ->execute([$id, $name, 'active']);

        return $id;
    }

    public static function user(string $email): int
    {
        $statement = self::superuser()->prepare('INSERT INTO users (name, email, password, created_at, updated_at) VALUES (?, ?, ?, now(), now()) RETURNING id');
        $statement->execute([$email, $email, 'not-a-real-hash']);

        return (int) $statement->fetchColumn();
    }

    /**
     * Inserts one row of a tenant table for a Workspace and returns its ID. Every tenant table
     * needs an entry here: a new tenant table without a seeder fails the leak test, by name.
     */
    public static function seedTenantRow(string $table, string $workspaceId): ?string
    {
        return match ($table) {
            'workspace_memberships' => self::seedMembership($workspaceId),
            default => null,
        };
    }

    private static function seedMembership(string $workspaceId): string
    {
        $id = (string) Str::uuid7();
        $userId = self::user('member-'.$id.'@example.test');
        self::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, now(), now())')
            ->execute([$id, $workspaceId, $userId, 'user', 'active']);

        return $id;
    }

    /**
     * Runs `$work` inside a transaction on `$pdo` with the Workspace context set.
     *
     * @template T
     *
     * @param  callable(PDO): T  $work
     * @return T
     */
    public static function inWorkspace(PDO $pdo, string $workspaceId, callable $work): mixed
    {
        $pdo->beginTransaction();
        try {
            self::rows($pdo, "select set_config('app.workspace_id', ?, true)", [$workspaceId]);
            $result = $work($pdo);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
