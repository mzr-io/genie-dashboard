<?php

namespace App\Modules\Blocks\Application;

use App\Modules\Blocks\Contracts\DraftActor;
use App\Modules\Blocks\Contracts\DraftLockLost;
use App\Modules\Blocks\Contracts\DraftNotFound;
use App\Modules\Blocks\Contracts\DraftPublished;
use App\Modules\Blocks\Contracts\Drafts;
use App\Modules\Blocks\Contracts\DraftSampleFetches;
use App\Modules\Blocks\Contracts\DraftSamples;
use App\Modules\Blocks\Contracts\SampleRejected;
use App\Modules\Connector\Contracts\DataSourceActor;
use App\Modules\Connector\Contracts\DataSourceNotFound;
use App\Modules\Connector\Contracts\EndpointNotFound;
use App\Modules\Connector\Contracts\PreparedSampleFetch;
use App\Modules\Connector\Contracts\SampleFetchNotConfigured;
use App\Modules\Connector\Contracts\SampleRuns;
use App\Platform\Json\InvalidLimitSetting;
use App\Platform\Json\LimitSetting;
use App\Platform\Operations\Operation;
use App\Platform\Operations\Operations;
use App\Platform\Tenancy\WorkspaceSettings;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starts Fetch from API for a Draft (Story 3.9, {@see DraftSampleFetches}). Everything that can be refused is refused before the rate limit is counted
 * and before anything is queued; the Operation then carries the Draft's revision, the Endpoint's revision at that moment, the values the Connector
 * resolved and the Draft's lock epoch (the lock token never travels in the job). The Operation's lifetime is the platform timeout ceiling, a
 * `pending_input` setting: unset, the fetch is refused naming it and no number is invented.
 */
final class StartDraftSampleFetch implements DraftSampleFetches
{
    /** The longest fetch lifetime accepted: one day. A setting above it is treated as not configured. */
    private const MAX_CEILING_SECONDS = 86_400;

    public function __construct(
        private readonly WorkspaceTransaction $transactions,
        private readonly Operations $operations,
        private readonly SampleRuns $runs,
        private readonly DraftSamples $samples,
        private readonly Drafts $drafts,
        private readonly Repository $config,
        private readonly WorkspaceSettings $settings,
    ) {}

    public function start(DraftActor $actor, string $draftId, string $dataSourceId, string $endpointId, array $bindings, ?array $defaultRange, string $requestKey, bool $containsPersonalData, int $lockEpoch, ?\Closure $holdsLock = null): Operation
    {
        return $this->transactions->run($actor->workspaceId, function () use ($actor, $draftId, $dataSourceId, $endpointId, $bindings, $defaultRange, $requestKey, $containsPersonalData, $lockEpoch, $holdsLock): Operation {
            $row = Str::isUuid($draftId)
                ? DB::selectOne("select id, revision, lock_epoch from block_versions where workspace_id = ? and id = ? and state = 'draft' for update", [$actor->workspaceId, strtolower($draftId)])
                : null;

            if ($row === null) {
                throw $this->notADraft($actor->workspaceId, $draftId);
            }

            $id = strtolower((string) $row->id);

            // The edit lock first: a fetch is a write to the Draft like a save, so a non-holder or a stale epoch changes nothing.
            if ((int) $row->lock_epoch !== $lockEpoch || ($holdsLock !== null && $holdsLock() !== true)) {
                throw new DraftLockLost($this->drafts->find($actor->workspaceId, $id));
            }

            if (! $containsPersonalData) {
                throw new SampleRejected(SampleRejected::ACKNOWLEDGEMENT_REQUIRED);
            }

            // The limits and the TTL a stored sample needs: a fetch that could not be stored is not made.
            $limits = $this->samples->check('null');

            if (! $limits->valid && $limits->reason === 'limit_not_configured') {
                throw new SampleRejected($limits->reason, $limits);
            }

            if (! $this->ttlConfigured()) {
                throw new SampleRejected(SampleRejected::TTL_NOT_CONFIGURED);
            }

            $ceiling = $this->ceiling() ?? throw new SampleRejected(SampleRejected::SETTING_NOT_CONFIGURED, setting: self::CEILING_ENV);

            // The dates come from the platform's period resolver in the Workspace time zone, never from the client.
            [$range, $period] = DraftPeriod::dates($this->drafts->find($actor->workspaceId, $id)->config, $defaultRange, $this->settings->timezone(), now());

            try {
                $prepared = $this->runs->prepare(new DataSourceActor($actor->membershipId, $actor->workspaceId), $dataSourceId, $endpointId, $bindings, $range, $period);
            } catch (SampleFetchNotConfigured $e) {
                throw new SampleRejected(SampleRejected::SETTING_NOT_CONFIGURED, setting: $e->setting);
            } catch (DataSourceNotFound|EndpointNotFound) {
                throw new SampleRejected(SampleRejected::ENDPOINT_NOT_FOUND);
            }

            $bound = new DataSourceActor($actor->membershipId, $actor->workspaceId);

            try {
                return $this->enqueue($actor, $id, $row, $prepared, $requestKey, $lockEpoch, $ceiling);
            } catch (\Throwable $e) {
                // The slot was counted but nothing was queued: give it back.
                $this->runs->release($bound);

                throw $e;
            }
        });
    }

    private function enqueue(DraftActor $actor, string $id, \stdClass $row, PreparedSampleFetch $prepared, string $requestKey, int $lockEpoch, int $ceiling): Operation
    {
        return $this->operations->enqueue(
            $actor->workspaceId,
            self::KIND,
            $actor->membershipId,
            'draft',
            $id,
            (int) $row->revision,
            [
                'data_source_id' => $prepared->dataSourceId,
                'endpoint_id' => $prepared->endpointId,
                'endpoint_revision' => $prepared->endpointRevision,
                'values' => $prepared->values,
                'request_key' => $requestKey,
                'lock_epoch' => $lockEpoch,
            ],
            $ceiling,
        );
    }

    private function ttlConfigured(): bool
    {
        try {
            $ttl = LimitSetting::positive('draft_sample_ttl', $this->config->get('dashflow.tunables.timeouts.draft_sample_ttl.value'));

            return $ttl !== null && $ttl <= 31_622_400;
        } catch (InvalidLimitSetting) {
            return false;
        }
    }

    private function ceiling(): ?int
    {
        try {
            $value = LimitSetting::positive('platform_timeout_ceiling', $this->config->get('dashflow.tunables.guards.platform_timeout_ceiling.value'));

            return $value !== null && $value <= self::MAX_CEILING_SECONDS ? $value : null;
        } catch (InvalidLimitSetting) {
            return null;
        }
    }

    private function notADraft(string $workspaceId, string $id): \Throwable
    {
        $row = Str::isUuid($id) ? DB::selectOne('select state from block_versions where workspace_id = ? and id = ?', [$workspaceId, strtolower($id)]) : null;

        return $row !== null && $row->state !== 'discarded' ? new DraftPublished : new DraftNotFound;
    }
}
