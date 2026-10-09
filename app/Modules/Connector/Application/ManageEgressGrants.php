<?php

namespace App\Modules\Connector\Application;

use App\Modules\Connector\Contracts\BlockedAddress;
use App\Modules\Connector\Contracts\Cidr;
use App\Modules\Connector\Contracts\CidrProblem;
use App\Modules\Connector\Contracts\EgressGrant;
use App\Modules\Connector\Contracts\EgressGrantRefused;
use App\Modules\Connector\Contracts\EgressGrants;
use App\Modules\Connector\Infrastructure\EgressSettings;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Audit\AuditHasher;
use App\Platform\Audit\OperatorAudit;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Operator-managed private-range grants (Story 2.2), in `egress_grants`.
 *
 * Grant and revoke run on the `operator` connection under the Workspace's row-level security
 * (`WorkspaceTransaction::runIsolated`), write `operator_audit` in the same transaction, and are then mirrored into the
 * Workspace audit log as role `app`, as `dashflow:workspace:create` does. A grant must lie wholly inside RFC 1918, CGNAT
 * or ULA space and clear every undeniable range and the deployment's own CIDRs; nothing is ever deleted (revocation sets
 * `revoked_at`). The guard reads `activeCidrs` as the Workspace, so a grant never reaches another Workspace.
 */
final class ManageEgressGrants implements EgressGrants
{
    public const CONNECTION = 'operator';

    public function __construct(
        private readonly WorkspaceTransaction $transactions,
        private readonly Audit $audit,
        private readonly OperatorAudit $operatorAudit,
        private readonly AuditHasher $hasher,
        private readonly EgressSettings $settings,
    ) {}

    public function activeCidrs(string $workspaceId): array
    {
        return $this->transactions->run($workspaceId, function () use ($workspaceId): array {
            /** @var list<object{cidr: string}> $rows */
            $rows = DB::select('select cidr from egress_grants where workspace_id = ? and revoked_at is null order by granted_at, id', [$workspaceId]);

            return array_map(fn (object $row): string => (string) $row->cidr, $rows);
        });
    }

    public function grant(string $workspaceId, string $cidr, string $reason, string $actor): EgressGrant
    {
        $network = Cidr::parse($cidr) ?? throw new EgressGrantRefused(CidrProblem::Invalid);
        $this->assertReason($reason);
        $this->assertGrantable($network);

        $id = (string) Str::uuid7();
        $text = $network->text();

        $this->transactions->runIsolated(self::CONNECTION, $workspaceId, function (Connection $operator) use ($workspaceId, $id, $network, $text, $reason, $actor): void {
            foreach ($operator->table('egress_grants')->where('workspace_id', $workspaceId)->whereNull('revoked_at')->pluck('cidr') as $existing) {
                $active = Cidr::parse((string) $existing);

                if ($active !== null && $network->within($active)) {
                    throw new EgressGrantRefused(CidrProblem::AlreadyGranted);
                }
            }

            try {
                $operator->table('egress_grants')->insert([
                    'id' => $id,
                    'workspace_id' => $workspaceId,
                    'cidr' => $text,
                    'reason' => $reason,
                    'granted_by' => $actor,
                    'granted_at' => now(),
                ]);
            } catch (QueryException $e) {
                throw match ($e->errorInfo[0] ?? null) {
                    '23505' => new EgressGrantRefused(CidrProblem::AlreadyGranted),
                    '23503' => new EgressGrantRefused(CidrProblem::UnknownWorkspace),
                    default => $e,
                };
            }

            $this->operatorAudit->record($operator, AuditAction::ConnectorEgressGrantCreated, $actor, $workspaceId, [
                'cidr' => $text, 'grant_id' => $id, 'reason' => $this->hasher->hash($reason),
            ]);
        });

        return new EgressGrant($id, $workspaceId, $text, $this->mirror(AuditAction::ConnectorEgressGrantCreated, $workspaceId, $id, $text, $reason, $actor));
    }

    public function revoke(string $workspaceId, string $cidr, string $reason, string $actor): EgressGrant
    {
        $network = Cidr::parse($cidr) ?? throw new EgressGrantRefused(CidrProblem::Invalid);
        $this->assertReason($reason);

        $text = $network->text();

        $id = $this->transactions->runIsolated(self::CONNECTION, $workspaceId, function (Connection $operator) use ($workspaceId, $text, $reason, $actor): string {
            /** @var object{id: string}|null $row */
            $row = $operator->table('egress_grants')->where('workspace_id', $workspaceId)->where('cidr', $text)->whereNull('revoked_at')->first(['id']);

            if ($row === null) {
                throw new EgressGrantRefused(CidrProblem::NotGranted);
            }

            $changed = $operator->table('egress_grants')->where('id', $row->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'revoked_by' => $actor]);

            if ($changed !== 1) {
                throw new EgressGrantRefused(CidrProblem::NotGranted);
            }

            $this->operatorAudit->record($operator, AuditAction::ConnectorEgressGrantRevoked, $actor, $workspaceId, [
                'cidr' => $text, 'grant_id' => $row->id, 'reason' => $this->hasher->hash($reason),
            ]);

            return strtolower($row->id);
        });

        return new EgressGrant($id, $workspaceId, $text, $this->mirror(AuditAction::ConnectorEgressGrantRevoked, $workspaceId, $id, $text, $reason, $actor));
    }

    private function assertReason(string $reason): void
    {
        if (trim($reason) === '' || preg_match('/\A[^\p{C}\p{Zl}\p{Zp}]{1,500}\z/u', $reason) !== 1) {
            throw new EgressGrantRefused(CidrProblem::InvalidReason);
        }
    }

    /** @throws EgressGrantRefused unless the network is wholly private (grantable) space clear of every undeniable range and deployment CIDR */
    private function assertGrantable(Cidr $network): void
    {
        foreach ($this->settings->deploymentCidrs() as $own) {
            if ($network->overlaps($own)) {
                throw new EgressGrantRefused(CidrProblem::OverlapsDeployment);
            }
        }

        foreach (BlockedAddress::undeniable() as $range) {
            if ($network->within($range)) {
                throw new EgressGrantRefused(CidrProblem::Undeniable);
            }

            if ($network->overlaps($range)) {
                throw new EgressGrantRefused(CidrProblem::OverlapsUndeniable);
            }
        }

        foreach (BlockedAddress::grantable() as $range) {
            if ($network->within($range)) {
                return;
            }
        }

        throw new EgressGrantRefused(CidrProblem::NotPrivate);
    }

    private function mirror(AuditAction $action, string $workspaceId, string $id, string $cidr, string $reason, string $actor): bool
    {
        try {
            $this->transactions->run($workspaceId, fn () => $this->audit->record(
                $action,
                ['cidr' => $cidr, 'grant_id' => $id, 'grant_reason' => $reason],
                subject: 'egress_grant:'.$id,
                actor: $actor,
            ));

            return true;
        } catch (Throwable $e) {
            // Class only: the stored grant and operator_audit row stand; the cause must not be lost.
            Log::error('connector.egress_grant.mirror_failed', ['workspace_id' => $workspaceId, 'grant_id' => $id, 'action' => $action->value, 'exception' => $e::class]);

            return false;
        }
    }
}
