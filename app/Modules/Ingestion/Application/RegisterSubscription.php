<?php

namespace App\Modules\Ingestion\Application;

use App\Modules\Connector\Contracts\DataSources;
use App\Modules\Connector\Contracts\Endpoint;
use App\Modules\Connector\Contracts\Endpoints;
use App\Modules\Ingestion\Contracts\FetchKeyInput;
use App\Modules\Ingestion\Contracts\FetchKeyResolver;
use App\Modules\Ingestion\Contracts\FetchKeyResult;
use App\Modules\Ingestion\Contracts\Subscribe;
use App\Modules\Ingestion\Contracts\SubscribeInput;
use App\Modules\Ingestion\Contracts\SubscribeResult;
use App\Modules\Ingestion\Infrastructure\SyncSettings;
use App\Support\Observability\MetricEmitter;
use App\Support\Observability\RequestContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * {@see Subscribe} (Story 2.19). Finds or creates the sync target the fetch key names (the {@see FetchKeyResolver} owns the key), then upserts the
 * subscription. A target made for user-bound data is `user_scoped`: it can go cold and be purged, and it has no schedule of its own, because a
 * scheduled fetch has no member to speak for (it is fetched when someone subscribes, Epic 3). A shared target made here is scheduled like a
 * registered one, but only the dispatcher's demand rule makes it due.
 */
final class RegisterSubscription implements Subscribe
{
    private const DATE = '/\A(\d{4})-(\d{2})-(\d{2})\z/D';

    public function __construct(
        private readonly FetchKeyResolver $keys,
        private readonly SyncSettings $settings,
        private readonly MetricEmitter $metrics,
        private readonly RequestContext $context,
    ) {}

    public function subscribe(SubscribeInput $input): SubscribeResult
    {
        $this->check($input);

        $workspaceId = strtolower($input->workspaceId);
        $source = app(DataSources::class)->find($workspaceId, strtolower($input->dataSourceId));
        $endpoint = app(Endpoints::class)->find($workspaceId, $source->id, strtolower($input->endpointId));

        $resolved = $this->params($endpoint, $input);

        if (is_string($resolved)) {
            return SubscribeResult::refused($resolved);
        }

        [$params, $bound] = $resolved;
        $result = $this->keys->resolve(new FetchKeyInput(
            $workspaceId, $endpoint->revisionId, $source->revision, $params, $bound, $endpoint->scopeByCaller, $input->membershipId,
        ));

        if ($result->key === null) {
            return SubscribeResult::refused($result->reason ?? FetchKeyResult::CONTEXT_MISSING);
        }

        $userScoped = $bound !== null;
        $targetId = null;

        // A purge may delete a cold target between the insert and the lock: one more round makes it again.
        for ($attempt = 0; $attempt < 2 && $targetId === null; $attempt++) {
            $existing = DB::selectOne('select id from sync_targets where workspace_id = ? and fetch_key = ? for share', [$workspaceId, $result->key]);

            if ($existing === null) {
                if ($userScoped && $this->overColdKeyBudget($workspaceId, $input->membershipId)) {
                    $this->metrics->increment('dashflow.ingestion.budget_limited', ['workspace_id' => $workspaceId, 'request_id' => $this->requestId()]);

                    return SubscribeResult::refused(SubscribeResult::BUDGET_LIMITED);
                }

                $this->createTarget($workspaceId, $result->key, $source->id, $endpoint, $source->revision, $params, $source->retention->mode, $source->retention->days, $userScoped, $input->membershipId);
                $existing = DB::selectOne('select id from sync_targets where workspace_id = ? and fetch_key = ? for share', [$workspaceId, $result->key]);
            }

            $targetId = $existing === null ? null : strtolower((string) $existing->id);
        }

        if ($targetId === null) {
            throw new \RuntimeException('The sync target could not be kept for the subscription.');
        }

        return SubscribeResult::subscribed($targetId, $this->upsert($workspaceId, $targetId, $input));
    }

    private function check(SubscribeInput $input): void
    {
        foreach ([$input->workspaceId, $input->dataSourceId, $input->endpointId, $input->blockVersionId] as $id) {
            if (! Str::isUuid($id)) {
                throw new InvalidArgumentException('A subscription needs a Workspace, Data Source, Endpoint and Block version ID.');
            }
        }

        if (! in_array($input->role, SubscribeInput::ROLES, true) || $input->refreshIntervalSeconds < 1 || mb_strlen($input->computeContext) > 255) {
            throw new InvalidArgumentException('A subscription needs a role of primary or comparison, an interval of at least one second and a compute context of at most 255 characters.');
        }

        if ($input->membershipId !== null && ! Str::isUuid($input->membershipId)) {
            throw new InvalidArgumentException('The membership ID is not a UUID.');
        }

        foreach ([$input->periodStart, $input->periodEnd] as $date) {
            if ($date !== null && (preg_match(self::DATE, $date, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1]))) {
                throw new InvalidArgumentException('The period must be Workspace-local YYYY-MM-DD dates.');
            }
        }
    }

    /**
     * The typed parameters (user-bound rows left out) and the bound values, or the reason there can be no key.
     *
     * @return array{0: array<string, array{t: string, v: string}|null>, 1: array<string, mixed>|null}|string
     */
    private function params(Endpoint $endpoint, SubscribeInput $input): array|string
    {
        $params = [];
        $bound = [];
        $rows = [];

        foreach ($endpoint->params as $param) {
            $rows[$param['name']] = $param;
        }

        foreach ($endpoint->headers as $header) {
            $rows['header:'.$header['name']] = $header;
        }

        foreach ($rows as $name => $row) {
            $binding = $row['binding'];

            if (Endpoint::isUserBound($binding)) {
                $params[$name] = null;
                $bound[$name] = $input->bound[$name] ?? null;

                continue;
            }

            if ($binding === 'fixed') {
                if ($row['value'] === null || $row['value'] === '') {
                    return SubscribeResult::PARAMS_UNRESOLVED;
                }

                $params[$name] = ['t' => 'string', 'v' => $row['value']];

                continue;
            }

            $date = match ($binding) {
                'period_start', 'date_range_from' => $input->periodStart,
                'period_end', 'date_range_to' => $input->periodEnd,
                default => null,
            } ?? ($endpoint->testValues[$name] ?? null);

            if ($date === null || $date === '') {
                return SubscribeResult::PERIOD_MISSING;
            }

            $params[$name] = ['t' => 'date', 'v' => $date];
        }

        return [$params, $bound === [] ? null : $bound];
    }

    private function overColdKeyBudget(string $workspaceId, ?string $membershipId): bool
    {
        $max = $this->settings->maxNewColdKeysPerMembershipPerHour();

        if ($max === null || $membershipId === null) {
            return false;
        }

        $made = DB::selectOne(
            "select count(*) as n from sync_targets where workspace_id = ? and created_by_membership_id = ? and user_scoped and created_at > now() - interval '1 hour'",
            [$workspaceId, strtolower($membershipId)],
        );

        return (int) ($made->n ?? 0) >= $max;
    }

    /** @param  array<string, array{t: string, v: string}|null>  $params */
    private function createTarget(string $workspaceId, string $key, string $dataSourceId, Endpoint $endpoint, int $dataSourceRevision, array $params, string $retentionMode, ?int $retentionDays, bool $userScoped, ?string $membershipId): void
    {
        $interval = $this->settings->refreshInterval();
        $id = (string) Str::uuid7();
        $stored = array_filter($params, fn ($param): bool => $param !== null);

        DB::insert(
            'insert into sync_targets (id, workspace_id, fetch_key, data_source_id, endpoint_id, endpoint_revision_id, data_source_revision, params, sync_group_id, refresh_interval_seconds, next_due_at, retention_mode, retention_days, user_scoped, created_by_membership_id, created_at, updated_at) '
            .'values (?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?::integer, case when ?::integer is null or ?::boolean then null else now() end, ?, ?::integer, ?::boolean, ?, now(), now()) on conflict (workspace_id, fetch_key) do nothing',
            [
                $id, $workspaceId, $key, $dataSourceId, $endpoint->id, $endpoint->revisionId, $dataSourceRevision, json_encode((object) $stored, JSON_THROW_ON_ERROR),
                $id, $interval, $interval, $userScoped ? 'true' : 'false', $retentionMode, $retentionDays, $userScoped ? 'true' : 'false', $userScoped ? $membershipId : null,
            ],
        );
    }

    /** @return string|null `hot_until`, ISO 8601 UTC */
    private function upsert(string $workspaceId, string $targetId, SubscribeInput $input): ?string
    {
        $window = $this->settings->hotWindowSeconds();
        $stamp = "to_char(hot_until at time zone 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"')";
        $identity = [$workspaceId, $targetId, strtolower($input->blockVersionId), $input->role, $input->computeContext];

        // A touch moves `last_access_at` only once the subscription's own interval (copied when it was made) has passed since the last move.
        $row = DB::selectOne(
            'insert into sync_subscriptions (id, workspace_id, sync_target_id, block_version_id, role, compute_context, refresh_interval_seconds, period_start, period_end, last_access_at, hot_until, created_at, updated_at) '
            .'values (?, ?, ?, ?, ?, ?, ?, ?::date, ?::date, now(), case when ?::integer is null then null else now() + make_interval(secs => ?::integer) end, now(), now()) '
            .'on conflict (workspace_id, sync_target_id, block_version_id, role, compute_context) do update set last_access_at = now(), hot_until = excluded.hot_until, updated_at = now() '
            .'where sync_subscriptions.last_access_at <= now() - make_interval(secs => sync_subscriptions.refresh_interval_seconds) '
            ."returning {$stamp} as hot_until",
            [
                (string) Str::uuid7(), ...$identity, $input->refreshIntervalSeconds, $input->periodStart, $input->periodEnd, $window, $window,
            ],
        );

        if ($row === null) {
            $row = DB::selectOne(
                "select {$stamp} as hot_until from sync_subscriptions where workspace_id = ? and sync_target_id = ? and block_version_id = ? and role = ? and compute_context = ?",
                $identity,
            );
        }

        return $row === null || $row->hot_until === null ? null : (string) $row->hot_until;
    }

    private function requestId(): string
    {
        return $this->context->requestId() ?? (string) Str::ulid();
    }
}
