<?php

namespace App\Modules\Blocks\Application;

use App\Modules\Blocks\Contracts\DraftActor;
use App\Modules\Blocks\Contracts\DraftLockLost;
use App\Modules\Blocks\Contracts\DraftNotFound;
use App\Modules\Blocks\Contracts\DraftPublished;
use App\Modules\Blocks\Contracts\Drafts;
use App\Modules\Blocks\Contracts\DraftValidations;
use App\Modules\Blocks\Contracts\ValidationRefused;
use App\Modules\Blocks\Contracts\ValidationReport;
use App\Modules\BlockTypes\Contracts\BlockTypeRegistry;
use App\Modules\Connector\Contracts\DataSourceActor;
use App\Modules\Connector\Contracts\DataSourceNotFound;
use App\Modules\Connector\Contracts\EndpointNotFound;
use App\Modules\Connector\Contracts\Endpoints;
use App\Modules\Connector\Contracts\EndpointValidations;
use App\Modules\Connector\Contracts\PreparedEndpointValidation;
use App\Modules\Connector\Contracts\SampleFetchNotConfigured;
use App\Modules\Mapping\Contracts\QueryPlans;
use App\Modules\Mapping\Contracts\SettingNotConfigured;
use App\Platform\Json\InvalidLimitSetting;
use App\Platform\Json\JsonDepthExceeded;
use App\Platform\Json\JsonObject;
use App\Platform\Json\JsonParseFailed;
use App\Platform\Json\LimitSetting;
use App\Platform\Json\LosslessJson;
use App\Platform\Operations\Operation;
use App\Platform\Operations\Operations;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starts a validation against the real API and reads the latest report (Story 3.18, {@see DraftValidations}). Everything that can be refused is
 * refused before the rate limit is counted and before anything is queued. The Operation carries the Draft's revision (the report is bound to it),
 * the Endpoint's revision at that moment and the values the Connector resolved. The Data Source and Endpoint are the Draft's own `config.source`,
 * never the client's: what is validated is what will be published. The lifetime is the platform timeout ceiling, a `pending_input` setting.
 */
final class StartDraftValidation implements DraftValidations
{
    private const MAX_CEILING_SECONDS = 86_400;

    public function __construct(
        private readonly WorkspaceTransaction $transactions,
        private readonly Operations $operations,
        private readonly EndpointValidations $validations,
        private readonly Drafts $drafts,
        private readonly Endpoints $endpoints,
        private readonly BlockTypeRegistry $types,
        private readonly QueryPlans $plans,
        private readonly Repository $config,
    ) {}

    public function start(DraftActor $actor, string $draftId, ?array $range, ?array $period, int $lockEpoch, ?\Closure $holdsLock = null): Operation
    {
        return $this->transactions->run($actor->workspaceId, function () use ($actor, $draftId, $range, $period, $lockEpoch, $holdsLock): Operation {
            $row = Str::isUuid($draftId)
                ? DB::selectOne("select id, revision, lock_epoch, block_id from block_versions where workspace_id = ? and id = ? and state = 'draft' for update", [$actor->workspaceId, strtolower($draftId)])
                : null;

            if ($row === null) {
                throw $this->notADraft($actor->workspaceId, $draftId);
            }

            $id = strtolower((string) $row->id);
            $draft = $this->drafts->find($actor->workspaceId, $id);

            // The edit lock first: a validation stores a report against the Draft, so a non-holder or a stale epoch changes nothing.
            if ((int) $row->lock_epoch !== $lockEpoch || ($holdsLock !== null && $holdsLock() !== true)) {
                throw new DraftLockLost($draft);
            }

            $ceiling = $this->ceiling() ?? throw new ValidationRefused(ValidationRefused::SETTING_NOT_CONFIGURED, 'DASHFLOW_GUARD_PLATFORM_TIMEOUT_CEILING');

            $source = is_array($draft->config['source'] ?? null) ? $draft->config['source'] : [];
            $dataSourceId = is_string($source['data_source_id'] ?? null) ? strtolower($source['data_source_id']) : null;
            $endpointId = is_string($source['endpoint_id'] ?? null) ? strtolower($source['endpoint_id']) : null;

            // A pasted sample is never proof: with no real Data Source and Endpoint there is nothing to validate against.
            if ($dataSourceId === null || $endpointId === null || ! Str::isUuid($dataSourceId) || ! Str::isUuid($endpointId)) {
                throw new ValidationRefused(ValidationRefused::NO_REAL_SOURCE);
            }

            $type = $this->types->resolve($draft->type, $this->contractVersion($actor->workspaceId, (string) $row->block_id));

            // Fail fast: strict-schema errors are reported by the Operation without any request, and without spending a fetch.
            if (DraftStrictCheck::check($draft->config, $type) !== []) {
                return $this->enqueue($actor, $id, (int) $row->revision, ['fast_fail' => true], $ceiling);
            }

            try {
                $this->plans->guards();
            } catch (SettingNotConfigured $e) {
                throw new ValidationRefused(ValidationRefused::SETTING_NOT_CONFIGURED, $e->setting);
            }

            $bindings = [];

            foreach (is_array($source['bindings'] ?? null) ? $source['bindings'] : [] as $name => $value) {
                $bindings[(string) $name] = is_string($value) ? $value : null;
            }

            $bound = new DataSourceActor($actor->membershipId, $actor->workspaceId);

            try {
                $prepared = $this->validations->prepare($bound, $dataSourceId, $endpointId, $bindings, $range, $period);
            } catch (SampleFetchNotConfigured $e) {
                throw new ValidationRefused(ValidationRefused::SETTING_NOT_CONFIGURED, $e->setting);
            } catch (DataSourceNotFound|EndpointNotFound) {
                throw new ValidationRefused(ValidationRefused::NO_REAL_SOURCE);
            }

            try {
                return $this->enqueue($actor, $id, (int) $row->revision, $this->input($prepared), $ceiling);
            } catch (\Throwable $e) {
                // The slot was counted but nothing was queued: give it back.
                $this->validations->release($bound);

                throw $e;
            }
        });
    }

    public function latest(string $workspaceId, string $draftId): ?ValidationReport
    {
        try {
            $draft = $this->drafts->find($workspaceId, $draftId);
        } catch (DraftNotFound $e) {
            $throwable = $this->transactions->run($workspaceId, fn (): \Throwable => $this->notADraft($workspaceId, $draftId));

            throw $throwable instanceof DraftPublished ? $throwable : $e;
        }

        return $this->transactions->run($workspaceId, function () use ($workspaceId, $draft): ?ValidationReport {
            /** @var \stdClass|null $row */
            $row = DB::selectOne(
                'select id, status, reason, subject_revision, mismatches::text as mismatches, endpoint_id, endpoint_revision, '
                ."to_char(created_at at time zone 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') as validated_at "
                .'from validation_reports where workspace_id = ? and block_version_id = ? '
                // The report of the Draft's current revision first (a late run for an older one never masks it), then the newest.
                .'order by (subject_revision = ?) desc, created_at desc, id desc limit 1',
                [$workspaceId, $draft->id, $draft->revision],
            );

            if ($row === null) {
                return null;
            }

            $decoded = self::mismatches((string) $row->mismatches);
            $stored = (string) $row->status;
            $subject = (int) $row->subject_revision;

            return new ValidationReport(
                (string) $row->id,
                $draft->id,
                // Any later autosave bumps the revision: the report is for a revision that is no longer the Draft's.
                $subject === $draft->revision && ! $this->endpointMoved($workspaceId, $draft->config, $row) ? $stored : ValidationReport::STALE,
                $stored,
                $row->reason === null ? null : (string) $row->reason,
                $subject,
                $draft->revision,
                $decoded,
                $draft->version,
                $row->endpoint_id === null ? null : (string) $row->endpoint_id,
                $row->endpoint_revision === null ? null : (int) $row->endpoint_revision,
                (string) $row->validated_at,
            );
        });
    }

    /**
     * Whether the Endpoint the report was made against is no longer the Draft's: another Endpoint is chosen, or its revision has moved on (or it is gone).
     *
     * @param  array<string, mixed>  $config
     */
    private function endpointMoved(string $workspaceId, array $config, \stdClass $row): bool
    {
        if ($row->endpoint_id === null) {
            return false;
        }

        $source = is_array($config['source'] ?? null) ? $config['source'] : [];
        $dataSourceId = is_string($source['data_source_id'] ?? null) ? strtolower($source['data_source_id']) : null;
        $endpointId = is_string($source['endpoint_id'] ?? null) ? strtolower($source['endpoint_id']) : null;

        if ($dataSourceId === null || $endpointId === null || $endpointId !== strtolower((string) $row->endpoint_id)) {
            return true;
        }

        try {
            return $this->endpoints->find($workspaceId, $dataSourceId, $endpointId)->revision !== (int) $row->endpoint_revision;
        } catch (DataSourceNotFound|EndpointNotFound) {
            return true;
        }
    }

    /**
     * The stored list of mismatches (strings and nulls only), read by the lossless decoder.
     *
     * @return list<array{path: string|null, slot: string|null, type: string, expected: string|null}>
     */
    private static function mismatches(string $json): array
    {
        try {
            $list = LosslessJson::decode($json);
        } catch (JsonDepthExceeded|JsonParseFailed) {
            return [];
        }

        $out = [];

        foreach (is_array($list) ? $list : [] as $item) {
            if (! $item instanceof JsonObject) {
                continue;
            }

            $row = ['path' => null, 'slot' => null, 'type' => '', 'expected' => null];

            foreach ($item->entries() as [$key, $value]) {
                if (array_key_exists($key, $row) && (is_string($value) || $value === null)) {
                    $row[$key] = $value;
                }
            }

            $out[] = $row;
        }

        return $out;
    }

    /** @param  array<string, mixed>  $input */
    private function enqueue(DraftActor $actor, string $id, int $revision, array $input, int $ceiling): Operation
    {
        return $this->operations->enqueue($actor->workspaceId, self::KIND, $actor->membershipId, 'draft', $id, $revision, $input, $ceiling);
    }

    /** @return array<string, mixed> */
    private function input(PreparedEndpointValidation $prepared): array
    {
        return [
            'data_source_id' => $prepared->dataSourceId,
            'endpoint_id' => $prepared->endpointId,
            'endpoint_revision' => $prepared->endpointRevision,
            'values' => $prepared->values,
            'user_bound' => $prepared->userBound,
        ];
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

    private function contractVersion(string $workspaceId, string $blockId): int
    {
        $row = DB::selectOne('select contract_version from blocks where workspace_id = ? and id = ?', [$workspaceId, $blockId]);

        return $row === null ? throw new DraftNotFound : (int) $row->contract_version;
    }

    private function notADraft(string $workspaceId, string $id): \Throwable
    {
        $row = Str::isUuid($id) ? DB::selectOne('select state from block_versions where workspace_id = ? and id = ?', [$workspaceId, strtolower($id)]) : null;

        return $row !== null && $row->state !== 'discarded' ? new DraftPublished : new DraftNotFound;
    }
}
