<?php

namespace App\Modules\Blocks\Application;

use App\Platform\Json\InvalidLimitSetting;
use App\Platform\Json\LimitSetting;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Activates the versions left `prepared` (Story 3.19), run by {@see ActivatePreparedVersionsJob}. A version is left prepared only when the second publish
 * transaction did not finish (the web process died, the database failed between the two). One that has been prepared for longer than
 * `precompute_timeout` (`dashflow.tunables.sync.precompute_timeout`, `pending_input`, whole seconds) is handed to {@see ActivateVersion}, the same code
 * path as the request, so the version, the Block's current version, the Access subject, the audit event and the outbox event are the same.
 *
 * The `maintenance` connection only finds the candidates (it reads, Workspace by Workspace, under row-level security); the activation is the `app` role's
 * own transaction. With the setting unset nothing is activated and nothing is invented: a stuck version waits for the operator to set it. A failure of one
 * version or Workspace is logged by class and ID and does not stop the others. Idempotent: a second run finds nothing.
 */
final class ActivatePreparedVersions
{
    /** How many versions one run takes per Workspace: the rest waits for the next run. */
    private const BATCH = 50;

    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly WorkspaceTransaction $transactions,
        private readonly ActivateVersion $activate,
        private readonly Repository $config,
    ) {}

    /** @return int the versions activated */
    public function run(?string $workspaceId = null): int
    {
        $timeout = $this->timeout();

        if ($timeout === null) {
            return 0;
        }

        /** @var Connection $maintenance */
        $maintenance = $this->db->connection(PurgeDiscardedDrafts::CONNECTION);

        if ($workspaceId !== null) {
            $workspaceIds = Str::isUuid($workspaceId) ? [strtolower($workspaceId)] : [];
        } else {
            $workspaceIds = array_map(fn (object $row): string => strtolower((string) ($row->id ?? '')), $maintenance->select('select id from workspaces order by id'));
        }

        $activated = 0;

        foreach ($workspaceIds as $id) {
            try {
                $ids = $this->transactions->runIsolated(
                    PurgeDiscardedDrafts::CONNECTION,
                    $id,
                    fn (Connection $db): array => array_map(
                        fn (object $row): string => strtolower((string) ($row->id ?? '')),
                        $db->select(
                            "select id from block_versions where workspace_id = ? and state = 'prepared' and prepared_at <= now() - make_interval(secs => ?) order by prepared_at, id limit ".self::BATCH,
                            [$id, $timeout],
                        ),
                    ),
                );
            } catch (Throwable $e) {
                Log::error('blocks.version_activation.workspace_failed', ['workspace_id' => $id, 'exception' => $e::class]);

                continue;
            }

            foreach ($ids as $versionId) {
                try {
                    $this->activate->activate($id, $versionId);
                    $activated++;
                } catch (Throwable $e) {
                    Log::error('blocks.version_activation.failed', ['workspace_id' => $id, 'version_id' => $versionId, 'exception' => $e::class]);
                }
            }
        }

        return $activated;
    }

    private function timeout(): ?int
    {
        try {
            return LimitSetting::positive('precompute_timeout', $this->config->get('dashflow.tunables.sync.precompute_timeout.value'));
        } catch (InvalidLimitSetting) {
            return null;
        }
    }
}
