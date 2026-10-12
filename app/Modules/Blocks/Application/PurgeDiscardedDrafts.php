<?php

namespace App\Modules\Blocks\Application;

use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Physically deletes discarded Drafts (Story 3.3), run by {@see PurgeDiscardedDraftsJob} on the `maintenance` connection, Workspace by
 * Workspace, each in its own transaction with the Workspace context set so row-level security still applies. For every Draft whose state is
 * `discarded` it deletes its `draft_samples` and `draft_ui_state` rows and then the version row, then any Block of the Workspace left with no version; it also deletes every `draft_samples` row whose `expires_at` has passed. Nothing else is deleted and nothing
 * is audited (the discard was). It is idempotent: a second run finds nothing.
 */
final class PurgeDiscardedDrafts
{
    public const CONNECTION = 'maintenance';

    /** How many Drafts one run takes per Workspace: the rest waits for the next run. */
    private const BATCH = 200;

    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly WorkspaceTransaction $transactions,
    ) {}

    /** @return int the Drafts deleted */
    public function run(?string $workspaceId = null): int
    {
        /** @var Connection $maintenance */
        $maintenance = $this->db->connection(self::CONNECTION);

        if ($workspaceId !== null) {
            $workspaceIds = Str::isUuid($workspaceId) ? [strtolower($workspaceId)] : [];
        } else {
            // `workspaces` is a global table; `maintenance` can read it and nothing else outside a Workspace context.
            $workspaceIds = array_map(fn (object $row): string => strtolower((string) ($row->id ?? '')), $maintenance->select('select id from workspaces order by id'));
        }

        $deleted = 0;

        foreach ($workspaceIds as $id) {
            try {
                $deleted += $this->transactions->runIsolated(self::CONNECTION, $id, fn (Connection $db): int => $this->purge($db, $id));
            } catch (Throwable $e) {
                // One Workspace failing must not stop the others; nothing from the error but its class is logged.
                Log::error('blocks.draft_purge.workspace_failed', ['workspace_id' => $id, 'exception' => $e::class]);
            }
        }

        return $deleted;
    }

    private function purge(Connection $db, string $workspaceId): int
    {
        // A sample past its hard end (Story 3.8) is deleted whether or not its Draft was discarded; it was already unreadable.
        $db->delete('delete from draft_samples where workspace_id = ? and expires_at is not null and expires_at <= now()', [$workspaceId]);

        $ids = array_map(
            fn (object $row): string => (string) ($row->id ?? ''),
            $db->select("select id from block_versions where workspace_id = ? and state = 'discarded' order by discarded_at, id limit ".self::BATCH, [$workspaceId]),
        );

        if ($ids === []) {
            return 0;
        }

        $marks = implode(', ', array_fill(0, count($ids), '?'));

        $db->delete("delete from draft_samples where workspace_id = ? and block_version_id in ({$marks})", [$workspaceId, ...$ids]);
        $db->delete("delete from draft_ui_state where workspace_id = ? and block_version_id in ({$marks})", [$workspaceId, ...$ids]);
        $db->delete("delete from validation_reports where workspace_id = ? and block_version_id in ({$marks})", [$workspaceId, ...$ids]);

        // The state is re-checked: only a discarded Draft is ever deleted.
        $deleted = $db->delete("delete from block_versions where workspace_id = ? and state = 'discarded' and id in ({$marks})", [$workspaceId, ...$ids]);

        // A Block left with no version at all is deleted too (same Workspace, under row-level security).
        $db->delete('delete from blocks b where b.workspace_id = ? and not exists (select 1 from block_versions v where v.block_id = b.id)', [$workspaceId]);

        return $deleted;
    }
}
