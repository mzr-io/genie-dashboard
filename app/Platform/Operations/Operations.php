<?php

namespace App\Platform\Operations;

use App\Platform\Audit\AuditAction;
use App\Platform\Outbox\Outbox;
use App\Platform\Tenancy\TenantKey;
use App\Platform\Tenancy\WorkspaceTransaction;
use App\Support\Observability\RequestContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * The Operations kernel (Story 2.5): an asynchronous job started by one person and run by a worker.
 *
 * `enqueue` writes the row inside the caller's Workspace transaction and dispatches the signed {@see RunOperation} job
 * only after that transaction commits (a rollback removes the row and the dispatch with it). `run` is what the job
 * does: it runs the registered handler, stores its small summary, always lets the handler clean up what it staged
 * (transient secrets) and emits `platform.operation.completed` in the same transaction as the status change. `status`
 * answers only the membership that asked: anyone else gets nothing, as if it did not exist.
 */
final class Operations
{
    private const STAMP = "'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"'";

    private const COLUMNS = 'id, workspace_id, kind, requester_membership_id, subject_type, subject_id, subject_revision, status, request_id, result, '
        .'to_char(expires_at, '.self::STAMP.') AS expires, (expires_at <= now()) AS past';

    public function __construct(
        private readonly WorkspaceTransaction $transactions,
        private readonly OperationKinds $kinds,
        private readonly Outbox $outbox,
        private readonly RequestContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $input  small and never a secret; handed to the handler as it is
     */
    public function enqueue(
        string $workspaceId,
        string $kind,
        string $requesterMembershipId,
        string $subjectType,
        ?string $subjectId = null,
        ?int $subjectRevision = null,
        array $input = [],
    ): Operation {
        $workspaceId = TenantKey::workspace($workspaceId);
        $definition = $this->kinds->get($kind);

        if (! Str::isUuid($requesterMembershipId) || ($subjectId !== null && ! Str::isUuid($subjectId))) {
            throw new InvalidArgumentException('An Operation names its requester and subject by UUID.');
        }

        return $this->transactions->run($workspaceId, function () use ($workspaceId, $definition, $requesterMembershipId, $subjectType, $subjectId, $subjectRevision, $input): Operation {
            $id = (string) Str::uuid7();
            $requestId = $this->context->requestId();

            DB::insert(
                'insert into operations (id, workspace_id, kind, requester_membership_id, subject_type, subject_id, subject_revision, status, expires_at, request_id, created_at, updated_at) '
                ."values (?, ?, ?, ?, ?, ?, ?, 'queued', now() + (? * interval '1 second'), ?, now(), now())",
                [$id, $workspaceId, $definition->name, strtolower($requesterMembershipId), $subjectType, $subjectId === null ? null : strtolower($subjectId), $subjectRevision, $definition->ttlSeconds, $requestId === null ? null : substr($requestId, 0, 64)],
            );

            $operation = $this->find($workspaceId, $id) ?? throw new \LogicException('The Operation was not stored.');

            // Only a committed Operation is run: a rolled-back request dispatches nothing.
            DB::afterCommit(function () use ($workspaceId, $id, $definition, $input): void {
                $this->dispatch($workspaceId, $id, $definition, $input);
            });

            return $operation;
        });
    }

    /**
     * The Operation for the membership that asked for it, or null: an unknown ID, another member's and another Workspace's
     * Operation all look the same. One past its `expires_at` reads as `expired` without a result.
     */
    public function status(string $workspaceId, string $operationId, string $requesterMembershipId): ?Operation
    {
        if (! Str::isUuid($operationId) || ! Str::isUuid($requesterMembershipId)) {
            return null;
        }

        $operation = $this->transactions->run($workspaceId, fn (): ?Operation => $this->find($workspaceId, strtolower($operationId)));

        return $operation !== null && $operation->requesterMembershipId === strtolower($requesterMembershipId) ? $operation : null;
    }

    /**
     * Ends an Operation that has not ended: stores the status and the summary and emits the completion event, in one
     * transaction. An Operation that already ended is left as it is (false).
     *
     * @param  array<string, string|int|bool|null>  $summary
     */
    public function complete(string $workspaceId, string $operationId, OperationStatus $status, array $summary = []): bool
    {
        if (! $status->ended()) {
            throw new InvalidArgumentException('An Operation is completed with an ended status.');
        }

        OperationOutcome::assertSummary($summary);

        return $this->transactions->run($workspaceId, function () use ($workspaceId, $operationId, $status, $summary): bool {
            $row = DB::selectOne('select kind, status, requester_membership_id from operations where workspace_id = ? and id = ? for update', [$workspaceId, strtolower($operationId)]);

            if ($row === null || OperationStatus::from($row->status)->ended()) {
                return false;
            }

            DB::update(
                'update operations set status = ?, result = ?::jsonb, updated_at = now() where workspace_id = ? and id = ?',
                [$status->value, json_encode($summary === [] ? new \stdClass : $summary, JSON_THROW_ON_ERROR), $workspaceId, strtolower($operationId)],
            );

            $this->outbox->emit(
                AuditAction::PlatformOperationCompleted,
                'operation:'.strtolower($operationId),
                ['operation_id' => strtolower($operationId), 'kind' => $row->kind, 'status' => $status->value],
                actor: strtolower((string) $row->requester_membership_id),
            );

            return true;
        });
    }

    /**
     * What the queued job does, inside the Workspace's transaction: run the handler, then always let it clean up, then
     * record the outcome. A handler that throws fails the Operation with a generic code; the exception class is logged
     * with the IDs, never its message.
     *
     * @param  array<string, mixed>  $input
     */
    public function run(string $workspaceId, string $operationId, array $input): void
    {
        $this->transactions->run($workspaceId, function () use ($workspaceId, $operationId, $input): void {
            $row = DB::selectOne('select '.self::COLUMNS.' from operations where workspace_id = ? and id = ? for update', [$workspaceId, strtolower($operationId)]);

            if ($row === null || $row->status !== OperationStatus::Queued->value) {
                return;
            }

            $operation = $this->operation($row);

            try {
                $handler = app($this->kinds->get($operation->kind)->handler);
            } catch (Throwable $e) {
                Log::error('platform.operation.unknown_kind', ['workspace_id' => $workspaceId, 'operation_id' => $operation->id, 'kind' => $operation->kind, 'exception' => $e::class]);
                $this->discardStaged($workspaceId, $operation->id);
                $this->complete($workspaceId, $operation->id, OperationStatus::Failed, ['ok' => false, 'code' => 'operation-failed']);

                return;
            }

            if ($row->past) {
                $this->cleanup($handler, $operation);
                DB::update("update operations set status = 'expired', updated_at = now() where workspace_id = ? and id = ?", [$workspaceId, $operation->id]);

                return;
            }

            DB::update("update operations set status = 'running', updated_at = now() where workspace_id = ? and id = ?", [$workspaceId, $operation->id]);

            try {
                // A savepoint: a database error inside the handler undoes only its own writes, so the clean-up and the
                // final status below still run in a usable transaction.
                $outcome = DB::transaction(fn (): OperationOutcome => $handler->handle($operation, $input));
            } catch (Throwable $e) {
                Log::error('platform.operation.handler_failed', ['workspace_id' => $workspaceId, 'operation_id' => $operation->id, 'kind' => $operation->kind, 'exception' => $e::class]);
                $outcome = new OperationOutcome(false, ['ok' => false, 'code' => 'operation-failed']);
            } finally {
                $this->cleanup($handler, $operation);
            }

            $this->complete($workspaceId, $operation->id, $outcome->status(), $outcome->summary);
        });
    }

    private function cleanup(OperationHandler $handler, Operation $operation): void
    {
        try {
            $handler->cleanup($operation);
        } catch (Throwable $e) {
            // The rows carry an expiry and are purged by the maintenance command: a cleanup failure never hides the outcome.
            Log::error('platform.operation.cleanup_failed', ['workspace_id' => $operation->workspaceId, 'operation_id' => $operation->id, 'kind' => $operation->kind, 'exception' => $e::class]);
        }
    }

    /** @param  array<string, mixed>  $input */
    private function dispatch(string $workspaceId, string $id, OperationKind $kind, array $input): void
    {
        try {
            dispatch((new RunOperation($workspaceId, $id, $kind->name, $input))->onQueue($kind->queue));
        } catch (Throwable $e) {
            // The row is committed but nothing will run it: fail it now rather than leave the requester polling.
            Log::error('platform.operation.dispatch_failed', ['workspace_id' => $workspaceId, 'operation_id' => $id, 'kind' => $kind->name, 'exception' => $e::class]);

            $this->discardStaged($workspaceId, $id);

            try {
                $this->complete($workspaceId, $id, OperationStatus::Failed, ['ok' => false, 'code' => 'operation-failed']);
            } catch (Throwable) {
            }
        }
    }

    /**
     * Removes the transient secrets an Operation staged when no handler can be asked to (no kind, no job): through the
     * Connector's SECURITY DEFINER removal function, which touches only the Workspace in the context.
     */
    private function discardStaged(string $workspaceId, string $operationId): void
    {
        try {
            $this->transactions->run($workspaceId, fn () => DB::selectOne('select connector_remove_operation_secrets(?::uuid) as removed', [$operationId]));
        } catch (Throwable $e) {
            Log::error('platform.operation.discard_failed', ['workspace_id' => $workspaceId, 'operation_id' => $operationId, 'exception' => $e::class]);
        }
    }

    private function find(string $workspaceId, string $id): ?Operation
    {
        $row = DB::selectOne('select '.self::COLUMNS.' from operations where workspace_id = ? and id = ?', [$workspaceId, $id]);

        return $row === null ? null : $this->operation($row);
    }

    private function operation(object $row): Operation
    {
        /** @var object{id: string, workspace_id: string, kind: string, requester_membership_id: string, subject_type: string, subject_id: string|null, subject_revision: int|string|null, status: string, request_id: string|null, result: string|null, expires: string, past: bool|string|int} $row */
        $expired = filter_var($row->past, FILTER_VALIDATE_BOOLEAN);
        $decoded = $row->result === null || $expired ? null : json_decode($row->result, true);

        return new Operation(
            strtolower($row->id),
            strtolower($row->workspace_id),
            $row->kind,
            strtolower($row->requester_membership_id),
            $row->subject_type,
            $row->subject_id === null ? null : strtolower($row->subject_id),
            $row->subject_revision === null ? null : (int) $row->subject_revision,
            $expired ? OperationStatus::Expired : OperationStatus::from($row->status),
            $row->expires,
            $row->request_id,
            is_array($decoded) ? $decoded : null,
        );
    }
}
