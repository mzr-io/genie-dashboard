<?php

namespace App\Platform\Audit;

use App\Platform\Outbox\EventData;
use App\Platform\Tenancy\TenantKey;
use App\Platform\Tenancy\WorkspaceContext;
use App\Platform\Tenancy\WorkspaceTransaction;
use App\Support\Observability\RequestContext;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use PDOException;
use Throwable;

/**
 * The audit log writer. `record` joins the caller's open Workspace transaction, so a rollback removes
 * the row with the change it describes. `recordSecurityEvent` (denials, sign-in outcomes) writes through
 * a second connection in its own transaction, so the row survives a rollback of the caller's.
 *
 * Only AuditAction cases are accepted. Values pass through the module's AuditSerializer allowlist:
 * IDs and enums plain, everything else a keyed hash. `audit_events` is append-only for role `app`.
 */
class Audit
{
    /** The second connection used by recordSecurityEvent (config/database.php). */
    public const SECURITY_CONNECTION = 'security_audit';

    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly WorkspaceContext $context,
        private readonly WorkspaceTransaction $transactions,
        private readonly AuditSerializers $serializers,
        private readonly AuditHasher $hasher,
        private readonly RequestContext $requestContext,
        private readonly Application $container,
    ) {}

    /**
     * Records an audited change in the caller's transaction and returns the event ID.
     *
     * @param  array<string, mixed>  $after  the new values (allowlisted by the module's serializer)
     * @param  array<string, mixed>  $before  the previous values
     */
    public function record(AuditAction|string $action, array $after = [], array $before = [], ?string $subject = null, ?string $actor = null): string
    {
        $action = $this->action($action);
        $connection = $this->db->connection();

        $workspaceId = $this->context->workspaceId();

        if ($connection->transactionLevel() < 1 || $workspaceId === null) {
            throw new LogicException('Audit::record must run inside a Workspace transaction (use Audit::recordSecurityEvent for denials).');
        }

        return $this->insert($connection, $workspaceId, $action, $after, $before, $subject, $actor, false);
    }

    /**
     * Records a security event (a denial, a sign-in outcome) on its own connection and transaction.
     * It stays when the caller's transaction rolls back. The Workspace is the current one unless given.
     * A database failure (unknown Workspace, outage) is logged with IDs only and returns null: a denial
     * must still be a denial, never a 500.
     *
     * @param  array<string, mixed>  $values
     */
    public function recordSecurityEvent(AuditAction|string $action, array $values = [], ?string $workspaceId = null, ?string $subject = null, ?string $actor = null): ?string
    {
        $action = $this->action($action);
        $workspaceId ??= $this->context->workspaceId();

        if ($workspaceId === null) {
            throw new LogicException('A security event needs a Workspace.');
        }

        // Validate and serialize before opening anything.
        $workspaceId = TenantKey::workspace($workspaceId);

        try {
            return $this->transactions->runIsolated(
                self::SECURITY_CONNECTION,
                $workspaceId,
                fn (Connection $connection): string => $this->insert($connection, $workspaceId, $action, $values, [], $subject, $actor, true),
            );
        } catch (QueryException|PDOException $e) {
            try {
                Log::error('audit.security.failed', ['workspace_id' => $workspaceId, 'action' => $action->value, 'exception' => $e::class]);
            } catch (Throwable) {
                // Logging must not turn a denial into an error either.
            }

            return null;
        }
    }

    private function action(AuditAction|string $action): AuditAction
    {
        return $action instanceof AuditAction ? $action : AuditAction::fromString($action);
    }

    /**
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $before
     */
    private function insert(ConnectionInterface $connection, string $workspaceId, AuditAction $action, array $after, array $before, ?string $subject, ?string $actor, bool $security): string
    {
        $afterState = $this->serializers->serialize($action, $after);
        $beforeState = $this->serializers->serialize($action, $before);
        $subject = $subject === null ? null : EventData::subject($subject);
        $actor = EventData::actor($actor);
        [$ip, $userAgent] = $this->client();

        $id = (string) Str::uuid7();

        $connection->table('audit_events')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'action' => $action->value,
            'actor' => $actor,
            'subject' => $subject,
            'before_state' => $beforeState === [] ? null : json_encode($beforeState, JSON_THROW_ON_ERROR),
            'after_state' => $afterState === [] ? null : json_encode($afterState, JSON_THROW_ON_ERROR),
            'request_id' => EventData::requestId($this->requestContext->requestId()),
            'ip_hash' => $ip === null ? null : $this->hasher->hash($ip),
            'user_agent_hash' => $userAgent === null ? null : $this->hasher->hash($userAgent),
            'security' => $security,
            'occurred_at' => now(),
        ]);

        return $id;
    }

    /**
     * The client's IP and user agent of the current web request, if any (hashed before storage).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function client(): array
    {
        if (! $this->container->bound('request')) {
            return [null, null];
        }

        /** @var Request $request */
        $request = $this->container->make('request');

        if ($this->container->runningInConsole()) {
            return [null, null];
        }

        $userAgent = $request->userAgent();

        return [$request->ip(), $userAgent === null || $userAgent === '' ? null : $userAgent];
    }
}
