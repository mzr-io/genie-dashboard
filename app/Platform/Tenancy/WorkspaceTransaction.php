<?php

namespace App\Platform\Tenancy;

use Closure;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Opens the request or job transaction and sets the database Workspace context.
 *
 * This is the only opener of that transaction and the only caller of `set_config(..., true)`
 * (an architecture test enforces it). The setting is transaction-local, so it never outlives the
 * transaction on a pooled connection; with no Workspace there is no context and tenant tables
 * return no rows.
 *
 * Notes for callers: a request whose response status is 400 or above is rolled back. The context is
 * cleared before the transaction commits, so after-commit hooks run without a Workspace context and
 * must re-enter one. Jobs must not rely on SerializesModels for tenant models (they are restored
 * before the context is set, so under RLS they would not be found): pass IDs and re-read them.
 */
final class WorkspaceTransaction
{
    /** Session key holding the active Workspace ID (set when the user signs in or switches). */
    public const SESSION_KEY = 'workspace_id';

    public const REQUEST_ATTRIBUTE = 'workspace_id';

    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly WorkspaceContext $context,
    ) {}

    /**
     * HTTP middleware. Requests without an active Workspace run without a transaction and without context.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $workspaceId = $this->requestWorkspace($request);

        if ($workspaceId === null) {
            return $next($request);
        }

        try {
            return $this->run($workspaceId, function () use ($request, $next): Response {
                $response = $next($request);

                // The framework turns exceptions (and failed authorisation or validation) into 4xx and 5xx
                // responses before they reach this layer; roll the request's writes back for any of them.
                if ($response->getStatusCode() >= 400) {
                    throw new RollbackResponse($response);
                }

                return $response;
            });
        } catch (RollbackResponse $rollback) {
            return $rollback->response;
        }
    }

    /**
     * Job middleware: re-enters the job's Workspace and re-reads the IDs it was given under RLS.
     * A mismatch fails the job for good and records `security.tenancy.workspace_mismatch`.
     */
    public function runJob(object $job, Closure $next): mixed
    {
        if (! $job instanceof WorkspaceScopedJob) {
            throw new LogicException($job::class.' must implement '.WorkspaceScopedJob::class.' to run in a Workspace.');
        }

        try {
            $workspaceId = TenantKey::workspace($job->workspaceId());
        } catch (Throwable) {
            // A malformed or unreadable Workspace ID is a mismatch too: no data is logged, the job is not retried.
            $e = new WorkspaceMismatchException(null, '', [], 'malformed Workspace ID');
            $this->recordMismatch($e);
            $this->failForGood($job, $e);

            throw $e;
        }

        try {
            return $this->run($workspaceId, function () use ($job, $next): mixed {
                $this->assertReferencedIdsVisible($job);

                return $next($job);
            });
        } catch (WorkspaceMismatchException $e) {
            $this->recordMismatch($e);
            $this->failForGood($job, $e);

            throw $e;
        }
    }

    /**
     * Runs `$callback` in one transaction with the Workspace context set.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(string $workspaceId, Closure $callback): mixed
    {
        $workspaceId = TenantKey::workspace($workspaceId);
        $current = $this->context->workspaceId();

        if ($current !== null) {
            if ($current !== $workspaceId) {
                throw new LogicException('A transaction cannot move from one Workspace to another.');
            }

            return $callback();
        }

        /** @var Connection $connection */
        $connection = $this->db->connection();

        if ($connection->getDriverName() !== 'pgsql') {
            // Row-level security exists only on PostgreSQL: never pretend to isolate without it.
            throw new RuntimeException('Workspace context requires PostgreSQL.');
        }

        return $connection->transaction(function () use ($connection, $workspaceId, $callback): mixed {
            $this->setContext($connection, $workspaceId);
            $this->context->swap($workspaceId);

            try {
                return $callback();
            } finally {
                $this->context->swap(null);
            }
        });
    }

    /**
     * Runs `$callback` in its own transaction on a second, named connection that sets its own Workspace
     * context. PostgreSQL has no autonomous transactions: what the callback writes commits on its own
     * connection, whatever later happens to the caller's transaction (used for security events).
     * The Workspace context holder is untouched, since the caller's transaction still owns it.
     *
     * @template T
     *
     * @param  Closure(Connection): T  $callback
     * @return T
     */
    public function runIsolated(string $connectionName, string $workspaceId, Closure $callback): mixed
    {
        $workspaceId = TenantKey::workspace($workspaceId);

        /** @var Connection $connection */
        $connection = $this->db->connection($connectionName);

        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Workspace context requires PostgreSQL.');
        }

        if ($connection === $this->db->connection()) {
            throw new LogicException('An isolated transaction needs a connection other than the default one.');
        }

        return $connection->transaction(function () use ($connection, $workspaceId, $callback): mixed {
            $this->setContext($connection, $workspaceId);

            return $callback($connection);
        });
    }

    private function setContext(Connection $connection, string $workspaceId): void
    {
        $connection->select('select set_config(?, ?, true)', ['app.workspace_id', $workspaceId]);
    }

    private function requestWorkspace(Request $request): ?string
    {
        $id = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        if ($id === null && $request->hasSession()) {
            $id = $request->session()->get(self::SESSION_KEY);
        }

        return is_string($id) && Str::isUuid($id) ? strtolower($id) : null;
    }

    private function assertReferencedIdsVisible(WorkspaceScopedJob $job): void
    {
        $workspaceId = TenantKey::workspace($job->workspaceId());
        $connection = $this->db->connection();

        foreach ($job->referencedIds() as $table => $ids) {
            $ids = array_values(array_unique(array_map(fn (mixed $id): string => strtolower((string) $id), $ids)));

            if ($ids === []) {
                continue;
            }

            if (preg_match('/^[a-z_][a-z0-9_]*$/', $table) !== 1) {
                throw new WorkspaceMismatchException($workspaceId, 'invalid', [], 'invalid table name');
            }

            $malformed = array_values(array_filter($ids, fn (string $id): bool => ! Str::isUuid($id)));
            if ($malformed !== []) {
                throw new WorkspaceMismatchException($workspaceId, $table, [], 'malformed ID');
            }

            $found = $connection->table($table)->whereIn('id', $ids)->pluck('id')
                ->map(fn (mixed $id): string => strtolower((string) $id))->all();
            $missing = array_values(array_diff($ids, $found));

            if ($missing !== []) {
                throw new WorkspaceMismatchException($workspaceId, $table, $missing, 'ID not visible in the Workspace');
            }
        }
    }

    private function recordMismatch(WorkspaceMismatchException $e): void
    {
        try {
            Log::error(WorkspaceMismatchException::SECURITY_EVENT, [
                'security_event' => WorkspaceMismatchException::SECURITY_EVENT,
                'workspace_id' => $e->workspaceId,
                'table' => $e->table,
                'ids' => $e->ids,
                'reason' => $e->reason,
            ]);
        } catch (Throwable) {
            // Logging must never decide whether a mismatched job runs.
        }
    }

    private function failForGood(object $job, Throwable $e): void
    {
        $queueJob = property_exists($job, 'job') ? $job->job : null;

        if ($queueJob instanceof QueueJob && method_exists($job, 'fail')) {
            $job->fail($e);
        }
    }
}
