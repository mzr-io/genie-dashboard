<?php

namespace App\Modules\Ingestion\Application;

use App\Modules\Ingestion\Infrastructure\SyncSettings;
use App\Support\Observability\MetricEmitter;
use App\Support\Observability\RequestContext;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The dispatcher (Story 2.14), run by {@see DispatchDueSyncsJob} on `worker-compute`, the only holder of the `system` database role. In
 * one transaction on the `system` connection it takes a fixed batch of due, unretired targets `FOR UPDATE SKIP LOCKED` (two dispatchers never
 * take the same row), raises each target's `dispatch_seq` by one and moves `next_due_at` forward by its refresh interval; after the commit it
 * queues one {@see FetchJob(workspace_id, sync_group_id, dispatch_seq)} per target on `fetch-scheduled`. Nothing is queued for a rolled-back
 * dispatch, and a job that is lost is simply dispatched again at the next `next_due_at`. The role sees only the dispatch columns and can
 * change only those two (plus `budget_limited`); it never bypasses row-level security.
 *
 * Story 2.19, demand: while `sync.hot_window` is a valid number, only a target with a hot subscription (`hot_until` in the future) is
 * scheduled, at the smallest refresh interval among its hot subscriptions; a target nobody watches is left alone (the health probe alone
 * keeps its Data Source in view). Per Workspace the `budgets.max_hot_keys_per_workspace` most recently accessed hot targets keep their
 * interval; each further one is widened to the next larger entry of `sync.refresh_intervals` (none larger: it keeps its interval) and marked
 * budget-limited, with a `dashflow.ingestion.budget_limited` metric. Nothing is queued without bound: the batch and the fair share still apply.
 * With `hot_window` unset or malformed the rule is off and every target is scheduled as before.
 *
 * Story 2.20: a sync group is one dispatch. Only the primary's `dispatch_seq` rises and the one {@see FetchJob} carries the primary's number as the
 * group's fence; a comparison target linked to a primary (`group_primary_target_id`) is never picked on its own. A group is hot when any member has
 * a hot subscription, at the smallest hot interval among the members' subscriptions.
 */
final class DispatchDueSyncs
{
    public const CONNECTION = 'system';

    /** How many targets one tick may dispatch: a backlog drains over the next ticks. */
    public const BATCH = 200;

    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly SyncSettings $settings,
        private readonly MetricEmitter $metrics,
        private readonly RequestContext $context,
    ) {}

    /** @return int how many fetch jobs were queued */
    public function run(): int
    {
        /** @var Connection $system */
        $system = $this->db->connection(self::CONNECTION);

        $limited = [];

        $due = $system->transaction(function () use ($system, &$limited): array {
            $hotWindow = $this->settings->hotWindowSeconds();

            if ($hotWindow !== null) {
                return $this->dispatchHot($system, $limited);
            }

            $share = $this->settings->workspaceFairShare();
            $due = 'retired_at is null and group_primary_target_id is null and next_due_at is not null and next_due_at <= now()';

            if ($share === null) {
                $rows = $system->select(
                    "select id, workspace_id, sync_group_id, refresh_interval_seconds from sync_targets where {$due} "
                    .'order by next_due_at, id limit '.self::BATCH.' for update skip locked',
                );
            } else {
                // Story 2.17 fairness: at most `$share` targets per Workspace per tick, the oldest first, inside the same batch. A window
                // function cannot sit under FOR UPDATE, so the ranked ids are picked first and then locked (SKIP LOCKED still keeps two
                // dispatchers apart, and the due test is repeated on the locked rows).
                $rows = $system->select(
                    'select id, workspace_id, sync_group_id, refresh_interval_seconds from sync_targets where id in ('
                    ."select id from (select id, next_due_at, row_number() over (partition by workspace_id order by next_due_at, id) as rn from sync_targets where {$due}) ranked "
                    .'where rn <= ? order by next_due_at, id limit '.self::BATCH.") and {$due} order by next_due_at, id for update skip locked",
                    [$share],
                );
            }

            $jobs = [];

            foreach ($rows as $row) {
                $moved = $system->selectOne(
                    'update sync_targets set dispatch_seq = dispatch_seq + 1, next_due_at = now() + make_interval(secs => refresh_interval_seconds) where id = ? returning dispatch_seq',
                    [$row->id],
                );

                if ($moved !== null) {
                    $jobs[] = [(string) $row->workspace_id, (string) $row->sync_group_id, (int) $moved->dispatch_seq];
                }
            }

            return $jobs;
        });

        foreach ($limited as $workspaceId => $count) {
            $this->metrics->increment('dashflow.ingestion.budget_limited', ['workspace_id' => $workspaceId, 'request_id' => $this->context->requestId() ?? (string) Str::ulid()], $count);
        }

        $queued = 0;

        foreach ($due as [$workspaceId, $syncGroupId, $dispatchSeq]) {
            try {
                FetchJob::dispatch($workspaceId, $syncGroupId, $dispatchSeq);
                $queued++;
            } catch (Throwable $e) {
                // A job that could not be queued is a lost job: the target is dispatched again at its next due time.
                Log::error('ingestion.dispatch.enqueue_failed', ['workspace_id' => $workspaceId, 'exception' => $e::class]);
            }
        }

        return $queued;
    }

    /**
     * The demand rule: due targets that have a hot subscription, at the smallest hot interval, widened when over the hot-key budget.
     *
     * @param  array<string, int>  $limited  filled: how many budget-limited targets each Workspace had in this tick
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private function dispatchHot(Connection $system, array &$limited): array
    {
        $maxHot = $this->settings->maxHotKeysPerWorkspace();
        $share = $this->settings->workspaceFairShare();

        // The rank runs over every hot target of the Workspace (not only the due ones), so the budget is about how many are watched.
        $picked = $system->select(
            'with hot as ('
            .'select t.id, t.workspace_id, t.next_due_at, min(s.refresh_interval_seconds) as interval_seconds, max(s.last_access_at) as seen '
            .'from sync_targets t '
            // Story 2.20: a group is hot when any member is; the primary stands for it, and a linked comparison never dispatches on its own.
            .'join sync_targets m on m.workspace_id = t.workspace_id and (m.id = t.id or m.group_primary_target_id = t.id) and m.retired_at is null '
            .'join sync_subscriptions s on s.sync_target_id = m.id and s.workspace_id = m.workspace_id '
            .'where t.retired_at is null and t.group_primary_target_id is null and t.next_due_at is not null and s.hot_until > now() group by t.id, t.workspace_id, t.next_due_at'
            .'), ranked as ('
            .'select hot.*, row_number() over (partition by workspace_id order by seen desc, id) as hot_rank from hot'
            .'), due as ('
            .'select ranked.*, row_number() over (partition by workspace_id order by next_due_at, id) as due_rank from ranked where next_due_at <= now()'
            .') select id, workspace_id, interval_seconds, (?::bigint is not null and hot_rank > ?::bigint) as limited from due '
            .'where (?::bigint is null or due_rank <= ?::bigint) order by next_due_at, id limit '.self::BATCH,
            [$maxHot, $maxHot, $share, $share],
        );

        if ($picked === []) {
            return [];
        }

        $byId = [];

        foreach ($picked as $row) {
            $byId[strtolower((string) $row->id)] = $row;
        }

        $ids = array_keys($byId);
        $locked = $system->select(
            'select id, workspace_id, sync_group_id from sync_targets where id in ('.implode(', ', array_fill(0, count($ids), '?')).') '
            .'and retired_at is null and group_primary_target_id is null and next_due_at is not null and next_due_at <= now() order by next_due_at, id for update skip locked',
            $ids,
        );

        $jobs = [];

        foreach ($locked as $row) {
            $pick = $byId[strtolower((string) $row->id)];
            $interval = (int) $pick->interval_seconds;
            $isLimited = (bool) $pick->limited;

            if ($isLimited) {
                $interval = $this->settings->widened($interval) ?? $interval;
            }

            $moved = $system->selectOne(
                'update sync_targets set dispatch_seq = dispatch_seq + 1, next_due_at = now() + make_interval(secs => ?), budget_limited = ?::boolean where id = ? returning dispatch_seq',
                [$interval, $isLimited ? 'true' : 'false', $row->id],
            );

            if ($moved !== null) {
                $jobs[] = [(string) $row->workspace_id, (string) $row->sync_group_id, (int) $moved->dispatch_seq];

                if ($isLimited) {
                    $limited[(string) $row->workspace_id] = ($limited[(string) $row->workspace_id] ?? 0) + 1;
                }
            }
        }

        return $jobs;
    }
}
