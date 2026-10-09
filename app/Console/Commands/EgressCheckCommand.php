<?php

namespace App\Console\Commands;

use App\Modules\Connector\Contracts\EgressGuard;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Asks the EgressGuard what it would do with a URL for a Workspace (Story 2.2) and prints the verdict, the pinned IP
 * and the decision trail. It reads the allowlist and grants as the Workspace on the `app` connection and resolves the
 * name, but sends no request, writes no audit event and counts nothing towards the SSRF alert. The pinned IP is printed
 * here for the operator only: it is never shown to an Admin.
 */
#[Signature('dashflow:egress:check {workspace : Workspace UUID} {url : The absolute http or https URL to evaluate}')]
class EgressCheckCommand extends Command
{
    protected $description = 'Show whether the outbound guard would allow a URL for a Workspace (operator only; sends no request)';

    public function handle(EgressGuard $guard): int
    {
        $workspace = strtolower((string) $this->argument('workspace'));

        if (! Str::isUuid($workspace)) {
            $this->components->error('The workspace must be a Workspace UUID.');

            return self::INVALID;
        }

        try {
            $verdict = $guard->decide($workspace, (string) $this->argument('url'), record: false);
        } catch (Throwable $e) {
            $this->components->error('The guard could not decide: '.$e::class.'.');

            return self::FAILURE;
        }

        if ($verdict->allowed) {
            $this->line('allowed');
            $this->line('pinned ip: '.$verdict->pinnedIp);
        } else {
            $this->line('denied: '.$verdict->reason?->value);
            $this->line('error code: '.$verdict->code()?->value);
            $this->line('message: msg:'.$verdict->messageKey());
        }

        $this->line('decision trail:');

        foreach ($verdict->trail as $step) {
            $this->line('  '.$step);
        }

        return $verdict->allowed ? self::SUCCESS : self::FAILURE;
    }
}
