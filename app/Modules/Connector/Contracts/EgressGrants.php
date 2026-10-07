<?php

namespace App\Modules\Connector\Contracts;

/**
 * Operator-managed private-range grants (Story 2.2). A grant belongs to one Workspace and can only cover RFC 1918,
 * CGNAT or ULA space; nothing can lift an undeniable class. There is no delete: revocation sets `revoked_at`.
 * Grant and revoke run as the operator, write `operator_audit` in the same transaction and then mirror the event into
 * the Workspace audit log; `activeCidrs` reads as the Workspace under row-level security.
 */
interface EgressGrants
{
    /**
     * The canonical CIDR text of every active grant of the Workspace.
     *
     * @return list<string>
     */
    public function activeCidrs(string $workspaceId): array;

    /** @throws EgressGrantRefused */
    public function grant(string $workspaceId, string $cidr, string $reason, string $actor): EgressGrant;

    /** @throws EgressGrantRefused */
    public function revoke(string $workspaceId, string $cidr, string $reason, string $actor): EgressGrant;
}
