<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ConfirmsOperatorPassword;
use App\Modules\Connector\Contracts\Cidr;
use App\Modules\Connector\Contracts\CidrProblem;
use App\Modules\Connector\Contracts\EgressGrantRefused;
use App\Modules\Connector\Contracts\EgressGrants;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Revokes a Workspace's private-network grant (Story 2.2), after the operator's password is confirmed. The row stays
 * with `revoked_at` set; later requests to the range are denied. The revocation is audited like the grant.
 */
#[Signature('dashflow:egress:revoke {workspace : Workspace UUID} {cidr : The granted network, exactly as granted} {--reason= : Why it is revoked}')]
class EgressRevokeCommand extends Command
{
    use ConfirmsOperatorPassword;

    protected $description = 'Revoke a Workspace\'s private network grant for outbound calls (operator only)';

    public function handle(EgressGrants $grants): int
    {
        if (! $this->passwordConfigured()) {
            return self::FAILURE;
        }

        $workspace = strtolower((string) $this->argument('workspace'));
        $cidr = (string) $this->argument('cidr');
        $reason = (string) $this->option('reason');

        if (! Str::isUuid($workspace)) {
            $this->components->error('The workspace must be a Workspace UUID.');

            return self::INVALID;
        }

        if (Cidr::parse($cidr) === null) {
            $this->components->error(CidrProblem::Invalid->message());

            return self::INVALID;
        }

        if (trim($reason) === '') {
            $this->components->error('--reason is required: say why this is revoked.');

            return self::INVALID;
        }

        if (! $this->confirmedOperator()) {
            return self::FAILURE;
        }

        try {
            $result = $grants->revoke($workspace, $cidr, $reason, WorkspaceCreateCommand::actor());
        } catch (EgressGrantRefused $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->components->error('Nothing was changed: '.$e::class.'.');

            return self::FAILURE;
        }

        $this->components->info("Grant {$result->id} revoked: {$result->cidr} for Workspace {$result->workspaceId}.");

        if (! $result->mirrored) {
            $this->components->warn('The revocation is stored and in operator_audit, but mirroring it into the Workspace audit log failed.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
