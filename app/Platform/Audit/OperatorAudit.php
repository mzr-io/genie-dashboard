<?php

namespace App\Platform\Audit;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * Writes the operator's own log (`operator_audit`) on the caller's operator connection and transaction, so the row
 * commits or rolls back with the change it describes. Callers pass hashed values only (see AuditHasher).
 */
final class OperatorAudit
{
    /** @param  array<string, mixed>  $details */
    public function record(ConnectionInterface $operator, AuditAction $action, string $actor, ?string $workspaceId, array $details): string
    {
        $id = (string) Str::uuid7();

        $operator->table('operator_audit')->insert([
            'id' => $id,
            'action' => $action->value,
            'actor' => $actor,
            'workspace_id' => $workspaceId,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
        ]);

        return $id;
    }
}
