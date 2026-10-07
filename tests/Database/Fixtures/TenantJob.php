<?php

namespace Tests\Database\Fixtures;

use App\Platform\Tenancy\RunsInWorkspace;
use App\Platform\Tenancy\WorkspaceScopedJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/** A test job that records what it can see under the re-entered Workspace context. */
final class TenantJob implements ShouldQueue, WorkspaceScopedJob
{
    use Dispatchable, InteractsWithQueue, RunsInWorkspace, SerializesModels;

    /** @var list<string>|null */
    public static ?array $seen = null;

    /**
     * @param  list<string>  $membershipIds
     */
    public function __construct(
        public readonly string $workspaceId,
        public readonly array $membershipIds,
    ) {}

    public function workspaceId(): string
    {
        return $this->workspaceId;
    }

    public function referencedIds(): array
    {
        return ['workspace_memberships' => $this->membershipIds];
    }

    public function handle(): void
    {
        self::$seen = DB::table('workspace_memberships')->pluck('id')->all();
    }
}
