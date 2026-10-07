<?php

namespace App\Platform\Operations;

use App\Platform\Tenancy\RunsInWorkspace;
use App\Platform\Tenancy\WorkspaceScopedJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The signed, Workspace-scoped job that runs one Operation on the queue its kind names. It carries IDs, the kind and the
 * small non-secret input; the Operation is re-read under row-level security before anything runs. One try: an Operation
 * is never repeated behind the requester's back.
 */
final class RunOperation implements ShouldQueue, WorkspaceScopedJob
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInWorkspace;

    public int $tries = 1;

    /** @param  array<string, mixed>  $input */
    public function __construct(
        public readonly string $workspaceId,
        public readonly string $operationId,
        public readonly string $kind,
        public readonly array $input = [],
    ) {}

    public function workspaceId(): string
    {
        return $this->workspaceId;
    }

    public function referencedIds(): array
    {
        return ['operations' => [$this->operationId]];
    }

    public function handle(Operations $operations): void
    {
        $operations->run($this->workspaceId, $this->operationId, $this->input);
    }
}
