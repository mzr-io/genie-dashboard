<?php

namespace App\Modules\Results\Application;

use App\Modules\Blocks\Contracts\PublishedConfig;
use App\Modules\Blocks\Contracts\PublishedConfigs;
use App\Modules\BlockTypes\Contracts\BlockTypeRegistry;
use App\Modules\BlockTypes\Contracts\UnknownBlockType;
use App\Modules\Ingestion\Contracts\ComputeSources;
use App\Modules\Ingestion\Contracts\ComputeSubscription;
use App\Modules\Ingestion\Contracts\ComputeTarget;
use App\Modules\Ingestion\Contracts\EndpointUserBindings;
use App\Modules\Mapping\Contracts\GuardExceeded;
use App\Modules\Mapping\Contracts\InvalidPlan;
use App\Modules\Mapping\Contracts\PlanGuards;
use App\Modules\Mapping\Contracts\PlanResult;
use App\Modules\Mapping\Contracts\QueryPlans;
use App\Modules\RawStore\Contracts\RawStore;
use App\Modules\Results\Contracts\BlockResult;
use App\Modules\Results\Contracts\ResultKey;
use App\Modules\Results\Contracts\ResultState;
use App\Modules\Results\Contracts\ResultStore;
use App\Platform\Audit\AuditAction;
use App\Platform\Json\JsonDepthExceeded;
use App\Platform\Json\JsonParseFailed;
use App\Platform\Json\LosslessJson;
use App\Platform\Outbox\Outbox;
use App\Platform\Tenancy\TenantCache;
use App\Platform\Tenancy\WorkspaceSettings;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;

/**
 * Computes the Block Results of one sync target (Story 4.2; AD-25). For each Block version that watches the target (its primary subscriptions) it
 * runs the version's frozen Query Plan over the target's current payload, builds the {@see BlockResult} and stores it under
 * `res:{block_version}:{primary_fk}:{compute_ctx}` by compare-and-set on `payload_seq`. It runs in a Workspace transaction and reads the payload only
 * from RawStore: no code here calls a source, and the body is decoded once, by the lossless decoder.
 *
 * A slot that is missing or mistyped in the payload is stored `unavailable` with its reason (never 0, NaN or blank), the rest of the Block still
 * renders, and `results.mapping.slot_failed` is emitted with the consecutive-failure count of that slot. A plan or guard failure stores the version's
 * result in the `error` state.
 */
final class ComputeResults
{
    /** How long a failure count is kept without a new failure: a slot that stays healthy for this long starts again at one. */
    private const FAILURE_COUNT_TTL = 604800;

    public function __construct(
        private readonly ComputeSources $sources,
        private readonly PublishedConfigs $configs,
        private readonly RawStore $raw,
        private readonly BlockTypeRegistry $types,
        private readonly QueryPlans $plans,
        private readonly ResultStore $store,
        private readonly Outbox $outbox,
        private readonly WorkspaceSettings $settings,
        private readonly TenantCache $cache,
        private readonly EndpointUserBindings $bindings,
    ) {}

    /** @return int how many results were written (a kept newer result is not counted) */
    public function forTarget(string $workspaceId, string $syncTargetId): int
    {
        $target = $this->sources->target($workspaceId, $syncTargetId);
        $subscriptions = $this->sources->subscriptions($workspaceId, $syncTargetId);

        if ($target === null || $subscriptions === []) {
            return 0;
        }

        $body = $this->raw->get($workspaceId, $target->id, $target->payloadId);

        if ($body === null) {
            Log::warning('results.compute.payload_missing', ['workspace_id' => $workspaceId, 'sync_target_id' => $target->id]);

            return 0;
        }

        $guards = $this->plans->guards();

        try {
            $document = LosslessJson::decode($body, LosslessJson::configuredDepthLimit());
        } catch (JsonDepthExceeded|JsonParseFailed) {
            $document = null;
        }

        $written = 0;

        foreach ($subscriptions as $subscription) {
            $config = $this->configs->find($workspaceId, $subscription->blockVersionId);

            // Only a published version is computed; anything else is left for the reader to say (unpublished, access removed).
            if ($config === null || $config->state !== 'published') {
                continue;
            }

            $written += $this->compute($workspaceId, $target, $subscription, $config, $document, $guards) ? 1 : 0;
        }

        return $written;
    }

    private function compute(string $workspaceId, ComputeTarget $target, ComputeSubscription $subscription, PublishedConfig $config, mixed $document, PlanGuards $guards): bool
    {
        try {
            $type = $this->types->resolve($config->typeKey, $config->contractVersion);
        } catch (UnknownBlockType) {
            Log::warning('results.compute.block_type_unavailable', ['workspace_id' => $workspaceId, 'block_version_id' => $config->versionId]);

            return false;
        }

        $context = ResultContexts::of($subscription, $config->contractVersion, $this->settings);

        $key = ResultKey::of($workspaceId, $config->versionId, $target->fetchKey, $context);
        $slots = array_keys(is_array($config->queryPlan['shape'] ?? null) ? $config->queryPlan['shape'] : []);
        $previous = $this->store->payloadSeq($workspaceId, $key);
        $failed = [];

        if ($document === null) {
            // A stored body that no longer parses under the platform limits is an error result, not an exception: the job must not retry forever.
            $built = $this->errored($key, $target, $slots);
        } else {
            try {
                $result = $this->plans->run($type, $config->queryPlan, $document, $guards, $config->presentation, self::inclusivePeriod($subscription->periodStart, $subscription->periodEnd));
                $built = $this->build($key, $target, $result, $slots);
                $failed = $result->unavailable;
            } catch (GuardExceeded|InvalidPlan) {
                $built = $this->errored($key, $target, $slots);
            }
        }

        $applied = $this->store->put($workspaceId, $key, $built);

        // A duplicate delivery or a sweep re-run of a sequence already stored is not a new failure.
        if ($applied && $previous !== $target->payloadSeq) {
            $this->trackSlots($workspaceId, $config, $target, $slots, $failed);
            $this->announce($workspaceId, $config, $target, $built->resultEtag);
        }

        return $applied;
    }

    /**
     * @param  list<string>  $slots
     */
    private function build(string $key, ComputeTarget $target, PlanResult $result, array $slots): BlockResult
    {
        $states = [];

        foreach ($slots as $slot) {
            $value = $result->slots[$slot] ?? null;

            $states[$slot] = isset($result->unavailable[$slot])
                ? ['state' => 'unavailable', 'reason' => $result->unavailable[$slot]]
                : ['state' => $value === null || $value === '' ? 'empty' : 'ok'];
        }

        $counts = array_count_values(array_column($states, 'state'));
        $payloadState = is_string($result->payload['state'] ?? null) ? $result->payload['state'] : 'normal';

        $applying = [];

        if ($payloadState === 'unavailable' || (($counts['ok'] ?? 0) === 0 && ($counts['unavailable'] ?? 0) > 0)) {
            $applying[] = ResultState::Unavailable;
        }

        if ($payloadState === 'empty' || (($counts['ok'] ?? 0) === 0 && ($counts['unavailable'] ?? 0) === 0 && $slots !== [])) {
            $applying[] = ResultState::Empty;
        }

        // A mapped Data-as-of slot says how old the data is; without one it is when the payload was first observed (Story 4.3).
        return $this->result($key, $target, ResultState::strongest(...$applying, ...[ResultState::Ok]), $states, $result->payload, self::asOf($result->slots['data_as_of'] ?? null));
    }

    private static function asOf(mixed $value): ?string
    {
        // Only an ISO date or date-time: PHP's parser would also take `yesterday` or `now`.
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?\z/D', trim($value)) !== 1) {
            return null;
        }

        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception) {
            return null;
        }
    }

    /** @param  list<string>  $slots */
    private function errored(string $key, ComputeTarget $target, array $slots): BlockResult
    {
        $states = [];

        foreach ($slots as $slot) {
            $states[$slot] = ['state' => 'unavailable', 'reason' => 'compute_failed'];
        }

        return $this->result($key, $target, ResultState::Error, $states, []);
    }

    /**
     * @param  array<string, array{state: string, reason?: string}>  $states
     * @param  array<string, mixed>  $payload
     */
    private function result(string $key, ComputeTarget $target, ResultState $state, array $states, array $payload, ?string $dataAsOf = null): BlockResult
    {
        return new BlockResult(
            BlockResult::VERSION,
            $target->payloadId,
            $target->payloadSeq,
            $dataAsOf ?? $target->payloadChangedAt,
            $target->lastSuccessAt,
            $state,
            false,
            false,
            $states,
            $payload,
            BlockResult::etag($key, $target->payloadId),
            CarbonImmutable::now()->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }

    /**
     * Counts consecutive failures per slot and emits `results.mapping.slot_failed` for each failing slot; a slot that computed resets its count.
     * The count lives in the `cache` store with a TTL: it is a signal for Epic 8's incidents, not a record.
     *
     * @param  list<string>  $slots
     * @param  array<string, string>  $failed  slot => reason
     */
    private function trackSlots(string $workspaceId, PublishedConfig $config, ComputeTarget $target, array $slots, array $failed): void
    {
        foreach ($slots as $slot) {
            $counter = 'res-fail:'.$config->versionId.':'.$slot;

            if (! isset($failed[$slot])) {
                $this->cache->forget($workspaceId, $counter);

                continue;
            }

            $count = (int) $this->cache->get($workspaceId, $counter, 0) + 1;
            $this->cache->put($workspaceId, $counter, $count, self::FAILURE_COUNT_TTL);

            $this->outbox->emit(AuditAction::ResultsMappingSlotFailed, 'block_version:'.$config->versionId, [
                'block_id' => $config->blockId,
                'block_version_id' => $config->versionId,
                'slot' => $slot,
                'reason' => $failed[$slot],
                'consecutive_failures' => $count,
                'payload_seq' => $target->payloadSeq,
            ]);
        }
    }

    /**
     * `results.result.updated` (Story 4.13): the Block and the new ETag, and for a member's own data the member, so the relay can tell the right
     * people. No value is in it.
     */
    private function announce(string $workspaceId, PublishedConfig $config, ComputeTarget $target, string $etag): void
    {
        $own = $config->dataSourceId !== null && $config->endpointId !== null
            && $this->bindings->forEndpoint($workspaceId, $config->dataSourceId, $config->endpointId) !== [];

        $this->outbox->emit(AuditAction::ResultsResultUpdated, 'block:'.$config->blockId, [
            'block_id' => $config->blockId,
            'block_version_id' => $config->versionId,
            'result_etag' => $etag,
            'scope' => $own && $target->ownerMembershipId !== null ? 'member' : 'shared',
        ] + ($own && $target->ownerMembershipId !== null ? ['membership_id' => $target->ownerMembershipId] : []));
    }

    /**
     * The window `[start, end)` of a subscription as the two days a reader sees: `end` is the last day inside it.
     *
     * @return array{start: string, end: string}|null
     */
    private static function inclusivePeriod(?string $start, ?string $end): ?array
    {
        if ($start === null || $end === null) {
            return null;
        }

        try {
            return ['start' => $start, 'end' => (new DateTimeImmutable($end))->modify('-1 day')->format('Y-m-d')];
        } catch (\Exception) {
            return null;
        }
    }
}
