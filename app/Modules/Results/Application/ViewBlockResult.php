<?php

namespace App\Modules\Results\Application;

use App\Modules\Blocks\Contracts\PublishedConfig;
use App\Modules\Blocks\Contracts\PublishedConfigs;
use App\Modules\Ingestion\Contracts\ComputeSources;
use App\Modules\Ingestion\Contracts\RefreshIntervals;
use App\Modules\Ingestion\Contracts\Subscribe;
use App\Modules\Ingestion\Contracts\SubscribeInput;
use App\Modules\Results\Contracts\ComputeContext;
use App\Modules\Results\Contracts\Freshness;
use App\Modules\Results\Contracts\ResultKey;
use App\Modules\Results\Contracts\ResultState;
use App\Modules\Results\Contracts\ResultStore;
use App\Modules\Results\Contracts\ViewedResult;
use App\Modules\Results\Contracts\ViewedResults;
use App\Modules\Results\Contracts\ViewRequest;
use App\Platform\Period\InvalidPeriodInput;
use App\Platform\Period\PeriodBehaviour;
use App\Platform\Period\PeriodResolver;
use App\Platform\Period\ResolvedPeriod;
use App\Platform\Tenancy\WorkspaceSettings;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * {@see ViewedResults}. In order: the version (a version that is not published is `unpublished`), the period (the platform's resolver: the caller's
 * selection and dashboard range are preset tokens, so an unknown or not-enabled one is a 422), the compute context, the subscription (register or
 * throttled touch through Ingestion's `Subscribe`: that is what makes the Block hot), then the stored result with its freshness judged by
 * {@see Freshness} against the database clock. No step here reaches a source: a refusal to keep the Block hot never fails the read, and the last result
 * is served and marked Stale on schedule.
 */
final class ViewBlockResult implements ViewedResults
{
    /** Hot refresh could not be arranged: a setting is unset. The read still serves what is stored. */
    public const REFRESH_INTERVAL_UNSET = 'refresh_interval_unset';

    /** The version names no Data Source or Endpoint, so there is nothing to keep hot. */
    public const NO_SOURCE = 'no_source';

    public function __construct(
        private readonly PublishedConfigs $configs,
        private readonly Subscribe $subscribe,
        private readonly ComputeSources $sources,
        private readonly ResultStore $store,
        private readonly WorkspaceSettings $settings,
        private readonly RefreshIntervals $intervals,
    ) {}

    public function view(ViewRequest $request): ViewedResult
    {
        $workspaceId = strtolower($request->workspaceId);
        $config = $this->configs->find($workspaceId, $request->blockVersionId);

        if ($config === null || $config->state !== 'published') {
            return new ViewedResult(ResultState::Unpublished, null, null, null, null);
        }

        $now = $this->now();
        $period = $this->period($config, $request, $now);
        $context = ComputeContext::of(
            $period === null ? null : ['start' => $period->start, 'end' => $period->lastDay()],
            null,
            $this->settings->timezone(),
            $this->settings->revision(),
            $config->contractVersion,
        );

        [$hotUntil, $refusal] = $this->keepHot($config, $request, $period, $context);
        $watching = $this->sources->watching($workspaceId, $config->versionId, $context);

        if ($watching === null) {
            return new ViewedResult(ResultState::Pending, null, null, $hotUntil, $refusal);
        }

        $key = ResultKey::of($workspaceId, $config->versionId, $watching->target->fetchKey, $context);
        $stored = $this->store->get($workspaceId, $key);

        if ($stored === null) {
            return new ViewedResult(ResultState::Pending, null, null, $hotUntil, $refusal);
        }

        $interval = $watching->subscription->refreshIntervalSeconds;
        $lastSuccess = $watching->target->lastSuccessAt === null ? null : new DateTimeImmutable($watching->target->lastSuccessAt);
        $stale = $lastSuccess === null || $interval < 1 || Freshness::isStale($lastSuccess, $interval, $now);
        $hot = $watching->subscription->hotUntil !== null && new DateTimeImmutable($watching->subscription->hotUntil) > $now;
        $staleAt = $lastSuccess === null || $interval < 1 ? null : Freshness::staleAt($lastSuccess, $interval)->format('Y-m-d\TH:i:s\Z');

        return new ViewedResult($stored->state, $stored->withFreshness($stale, $stale && $hot), $staleAt, $hotUntil ?? $watching->subscription->hotUntil, $refusal);
    }

    /** @throws InvalidPeriodInput a selection or dashboard range the Block does not offer */
    private function period(PublishedConfig $config, ViewRequest $request, DateTimeImmutable $now): ?ResolvedPeriod
    {
        $default = $config->defaultRange;

        if ($default === null) {
            return null;
        }

        return PeriodResolver::resolve(
            PeriodBehaviour::tryFrom((string) $config->period) ?? PeriodBehaviour::FollowDashboard,
            $request->selection,
            $request->dashboardRange,
            $default['preset'],
            [],
            $this->settings->timezone(),
            $now,
            customDefault: ['from' => $default['from'] ?? '', 'to' => $default['to'] ?? ''],
        );
    }

    /** @return array{0: ?string, 1: ?string} hot until, and why the Block is not kept hot */
    private function keepHot(PublishedConfig $config, ViewRequest $request, ?ResolvedPeriod $period, string $context): array
    {
        if ($config->dataSourceId === null || $config->endpointId === null) {
            return [null, self::NO_SOURCE];
        }

        $interval = $config->refreshInterval;

        // `live` has no interval of its own in the settings: the smallest the Workspace offers (Story 4.13 adds the push path).
        if ($interval === 'live') {
            $interval = ($this->intervals->refreshIntervals() ?? [])[0] ?? null;
        }

        if (! is_int($interval) || $interval < 1) {
            return [null, self::REFRESH_INTERVAL_UNSET];
        }

        $result = $this->subscribe->subscribe(new SubscribeInput(
            $request->workspaceId,
            $config->dataSourceId,
            $config->endpointId,
            $config->versionId,
            'primary',
            $interval,
            $context,
            $period?->start,
            $period?->lastDay(),
            $request->bound,
            $request->membershipId,
        ));

        return [$result->hotUntil, $result->ok() ? null : $result->reason];
    }

    private function now(): DateTimeImmutable
    {
        // The database clock, so every judgement uses the same time as the timestamps it compares against.
        return CarbonImmutable::parse((string) DB::selectOne('select clock_timestamp() as now')->now)->toDateTimeImmutable();
    }
}
