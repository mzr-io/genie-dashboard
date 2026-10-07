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
 * Grants one Workspace a private network (RFC 1918, CGNAT or ULA) for outbound calls (Story 2.2), after the operator's
 * password is confirmed. An undeniable, deployment-own, public or already granted range is refused and nothing is written.
 */
#[Signature('dashflow:egress:grant {workspace : Workspace UUID} {cidr : The private network, for example 10.20.0.0/16} {--reason= : Why the Workspace needs it}')]
class EgressGrantCommand extends Command
{
    use ConfirmsOperatorPassword;

    protected $description = 'Grant a Workspace access to a private network range for outbound calls (operator only)';

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
            $this->components->error('--reason is required: say why the Workspace needs it.');

            return self::INVALID;
        }

        if (! $this->confirmedOperator()) {
            return self::FAILURE;
        }

        try {
            $result = $grants->grant($workspace, $cidr, $reason, WorkspaceCreateCommand::actor());
        } catch (EgressGrantRefused $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->components->error('Nothing was changed: '.$e::class.'.');

            return self::FAILURE;
        }

        $this->components->info("Grant {$result->id} granted: {$result->cidr} for Workspace {$result->workspaceId}.");

        if (! $result->mirrored) {
            $this->components->warn('The grant is stored and in operator_audit, but mirroring it into the Workspace audit log failed.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
