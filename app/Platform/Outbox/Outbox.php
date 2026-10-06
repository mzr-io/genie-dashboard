<?php

namespace App\Platform\Outbox;

use App\Platform\Audit\AuditAction;
use App\Platform\Tenancy\WorkspaceContext;
use App\Support\Observability\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Writes outbox events in the caller's open Workspace transaction: a rollback removes the event with
 * the change, a commit publishes both. The relay (role `system`) delivers it afterwards.
 *
 * `subject_seq` is allocated under a transaction-scoped advisory lock per (Workspace, subject): it
 * rises by one per subject with no gap or duplicate, even for concurrent emitters (a second emitter
 * waits until the first commits or rolls back, and a rolled-back number is reused).
 */
final class Outbox
{
    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly WorkspaceContext $context,
        private readonly RequestContext $requestContext,
    ) {}

    /**
     * @param  array<string, mixed>  $data  integers, booleans, null, UUIDs and short slugs only
     */
    public function emit(AuditAction|string $type, string $subject, array $data = [], int $version = 1, ?string $actor = null): OutboxEnvelope
    {
        $type = $type instanceof AuditAction ? $type : AuditAction::fromString($type);
        $subject = EventData::subject($subject);
        $data = EventData::data($data);
        $actor = EventData::actor($actor);

        if ($version < 1 || $version > 32767) {
            throw new InvalidArgumentException('An event version must be between 1 and 32767.');
        }

        $connection = $this->db->connection();
        $workspaceId = $this->context->workspaceId();

        if ($connection->transactionLevel() < 1 || $workspaceId === null) {
            throw new LogicException('Outbox::emit must run inside a Workspace transaction.');
        }

        // Serialises emitters of one subject until their transaction ends, so the numbers have no gaps.
        $connection->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$workspaceId.'|'.$subject]);

        $seq = (int) $connection->table('outbox_events')
            ->where('workspace_id', $workspaceId)
            ->where('subject', $subject)
            ->max('subject_seq') + 1;

        $envelope = new OutboxEnvelope(
            (string) Str::uuid7(),
            $type->value,
            $version,
            $workspaceId,
            $subject,
            $seq,
            CarbonImmutable::now()->utc()->startOfSecond(),
            $actor,
            EventData::requestId($this->requestContext->requestId()),
            $data,
        );

        $connection->table('outbox_events')->insert([
            'id' => $envelope->eventId,
            'workspace_id' => $envelope->workspaceId,
            'type' => $envelope->type,
            'v' => $envelope->v,
            'subject' => $envelope->subject,
            'subject_seq' => $envelope->subjectSeq,
            'occurred_at' => $envelope->occurredAt,
            'actor' => $envelope->actor,
            'request_id' => $envelope->requestId,
            'data' => json_encode($envelope->data === [] ? new \stdClass : $envelope->data, JSON_THROW_ON_ERROR),
        ]);

        return $envelope;
    }
}
