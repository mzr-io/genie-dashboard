<?php

use App\Modules\Ingestion\Contracts\ComputeSources;
use App\Modules\Results\Application\ComputeOnPayloadChanged;
use App\Modules\Results\Application\ComputeResults;
use App\Modules\Results\Application\ComputeResultsJob;
use App\Modules\Results\Application\SweepStaleResults;
use App\Modules\Results\Contracts\BlockResult;
use App\Modules\Results\Contracts\ResultKey;
use App\Modules\Results\Contracts\ResultState;
use App\Modules\Results\Contracts\ResultStore;
use App\Modules\Results\Infrastructure\ValkeyResultStore;
use App\Platform\Outbox\OutboxEnvelope;
use App\Platform\Tenancy\WorkspaceTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;
use Tests\Database\Support\RespClient;

// Story 4.2 against the real PostgreSQL and Valkey: a changed payload is computed for every published Block version that watches the target, stored by
// compare-and-set under the key of the contract, drift is reported per slot, a lost event is repaired by the sweep, and the job carries IDs only.
const CR_CONTEXT = 'cc1:test';

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();

    $this->valkey = new RespClient;
    $this->valkey->command('FLUSHALL');
    $this->store = new ValkeyResultStore($this->valkey, fn () => 600);
    app()->instance(ResultStore::class, $this->store);

    config([
        'dashflow.tunables.guards.max_bytes.value' => '5000',
        'dashflow.tunables.guards.depth_limit.value' => '10',
        'dashflow.preview.membership_limit.value' => '100',
        'dashflow.preview.window_seconds.value' => '60',
        'dashflow.preview.max_extracted_values.value' => '1000',
        'dashflow.preview.max_evaluations.value' => '1000',
        'dashflow.preview.max_wall_ms.value' => '5000',
        'dashflow.results.ttl.value' => '600',
    ]);
});

/** A version in the given state, with the timestamps its state check constraints need. */
function crVersion(string $ws, string $state, array $config): string
{
    $stamps = ['prepared_at' => '2026-10-12 09:00:00+00'] + ($state === 'published' ? ['published_at' => '2026-10-12 09:00:01+00'] : []);

    return Cluster::seedBlockVersion($ws, ['state' => $state, 'config' => json_encode($config)] + $stamps);
}

/**
 * A Workspace with one sync target holding a payload, one published kpi-card version that watches it, and the subscription between them.
 *
 * @return array{ws: string, target: string, version: string, fetchKey: string, key: string}
 */
function crWorld(string $body = '{"data":{"revenue":12345678901234567890.10,"label":"Net"}}', int $seq = 5, ?array $plan = null, string $state = 'published'): array
{
    $ws = Cluster::workspace('Acme');
    $target = (string) Str::uuid7();
    $fetchKey = 'fk1:'.hash('sha256', $target);
    Cluster::seedSyncTarget($ws, ['id' => $target, 'fetch_key' => $fetchKey]);
    $payload = Cluster::seedRawBody($ws, $target, $body);
    Cluster::superuser()->prepare('UPDATE sync_targets SET current_payload_id = ?, payload_seq = ?, last_success_at = ?, payload_changed_at = ? WHERE id = ?')
        ->execute([$payload, $seq, '2026-10-12 10:05:00+00', '2026-10-12 10:00:00+00', $target]);

    $plan ??= ['v' => 1, 'extract' => ['fields' => ['rev' => '$.data.revenue', 'name' => '$.data.label']], 'shape' => ['headline_value' => 'rev', 'kpi_label' => 'name']];
    $config = ['schema_version' => 1, 'type_key' => 'kpi-card', 'contract_version' => 1, 'query_plan' => $plan, 'presentation' => [], 'fields' => ['record_path' => null, 'items' => []]];
    $version = crVersion($ws, $state, $config);
    Cluster::seedSubscription($ws, $target, ['block_version_id' => $version, 'compute_context' => CR_CONTEXT]);

    return ['ws' => $ws, 'target' => $target, 'version' => $version, 'fetchKey' => $fetchKey, 'key' => ResultKey::of($ws, $version, $fetchKey, CR_CONTEXT)];
}

function crCompute(array $w): int
{
    return app(WorkspaceTransaction::class)->run($w['ws'], fn (): int => app(ComputeResults::class)->forTarget($w['ws'], $w['target']));
}

function crOutbox(string $type): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from outbox_events where type = ? order by subject_seq', [$type]);
}

it('computes a versioned result under the contract key with the lossless measure and the full DTO', function () {
    $w = crWorld();

    expect(crCompute($w))->toBe(1);

    $result = $this->store->get($w['ws'], $w['key']);

    expect($result)->not->toBeNull()
        ->and($result->state)->toBe(ResultState::Ok)
        ->and($result->payloadSeq)->toBe(5)
        ->and($result->v)->toBe(1)
        ->and($result->dataAsOf)->toBe('2026-10-12T10:00:00Z')
        ->and($result->lastSuccessAt)->toBe('2026-10-12T10:05:00Z')
        ->and($result->stale)->toBeFalse()
        ->and($result->refreshing)->toBeFalse()
        ->and($result->slotStates['headline_value'])->toBe(['state' => 'ok'])
        ->and($result->renderPayload['headline']['value'])->toBe('12345678901234567890.10')
        ->and($result->resultEtag)->toBe(BlockResult_etag($w['key'], $result->generationId))
        ->and($this->valkey->command('TTL', $w['key']))->toBeGreaterThan(0);
});

function BlockResult_etag(string $key, string $generation): string
{
    return BlockResult::etag($key, $generation);
}

it('stores a missing slot as unavailable with its reason, never zero, and emits the failure with its count', function () {
    $w = crWorld('{"data":{"revenue":42.5}}');

    crCompute($w);

    $result = $this->store->get($w['ws'], $w['key']);
    $events = crOutbox('results.mapping.slot_failed');

    expect($result->slotStates['kpi_label'])->toBe(['state' => 'unavailable', 'reason' => 'missing'])
        ->and($result->slotStates['headline_value'])->toBe(['state' => 'ok'])
        ->and($result->partial())->toBeTrue()
        ->and($events)->toHaveCount(1);

    $data = json_decode($events[0]['data'], true);

    expect($data)->toMatchArray(['block_version_id' => $w['version'], 'slot' => 'kpi_label', 'reason' => 'missing', 'consecutive_failures' => 1, 'payload_seq' => 5])
        ->and($events[0]['subject'])->toBe('block_version:'.$w['version']);
});

it('counts consecutive failures per new payload, ignores a re-run of the same one, and resets when the slot computes', function () {
    $w = crWorld('{"data":{"revenue":42.5}}', 5);
    crCompute($w);
    crCompute($w); // the same payload_seq again: a duplicate delivery or a sweep, not a new failure

    expect(crOutbox('results.mapping.slot_failed'))->toHaveCount(1);

    $payload = Cluster::seedRawBody($w['ws'], $w['target'], '{"data":{"revenue":43.5}}');
    Cluster::superuser()->prepare('UPDATE sync_targets SET current_payload_id = ?, payload_seq = 6 WHERE id = ?')->execute([$payload, $w['target']]);
    crCompute($w);

    $counts = array_map(fn ($e) => json_decode($e['data'], true)['consecutive_failures'], crOutbox('results.mapping.slot_failed'));

    expect($counts)->toBe([1, 2]);

    $payload = Cluster::seedRawBody($w['ws'], $w['target'], '{"data":{"revenue":44.5,"label":"Net"}}');
    Cluster::superuser()->prepare('UPDATE sync_targets SET current_payload_id = ?, payload_seq = 7 WHERE id = ?')->execute([$payload, $w['target']]);
    crCompute($w);

    expect(crOutbox('results.mapping.slot_failed'))->toHaveCount(2)
        ->and($this->store->get($w['ws'], $w['key'])->slotStates['kpi_label'])->toBe(['state' => 'ok']);
});

it('keeps a newer result when an older compute finishes last', function () {
    $w = crWorld(seq: 9);
    crCompute($w);

    $payload = Cluster::seedRawBody($w['ws'], $w['target'], '{"data":{"revenue":1,"label":"Old"}}');
    Cluster::superuser()->prepare('UPDATE sync_targets SET current_payload_id = ?, payload_seq = 8 WHERE id = ?')->execute([$payload, $w['target']]);

    expect(crCompute($w))->toBe(0)
        ->and($this->store->payloadSeq($w['ws'], $w['key']))->toBe(9)
        ->and($this->store->get($w['ws'], $w['key'])->renderPayload['headline']['value'])->toBe('12345678901234567890.10');
});

it('computes nothing for a version that is not published', function () {
    $w = crWorld(state: 'prepared');

    expect(crCompute($w))->toBe(0)->and($this->store->payloadSeq($w['ws'], $w['key']))->toBeNull();
});

it('stores an error result when the plan cannot run, and the rest of the target\'s versions still compute', function () {
    $w = crWorld();
    $bad = crVersion($w['ws'], 'published', ['schema_version' => 1, 'type_key' => 'kpi-card', 'contract_version' => 1, 'query_plan' => ['v' => 9], 'presentation' => []]);
    Cluster::seedSubscription($w['ws'], $w['target'], ['block_version_id' => $bad, 'compute_context' => CR_CONTEXT]);

    expect(crCompute($w))->toBe(2);

    $error = $this->store->get($w['ws'], ResultKey::of($w['ws'], $bad, $w['fetchKey'], CR_CONTEXT));

    expect($error->state)->toBe(ResultState::Error)
        ->and($this->store->get($w['ws'], $w['key'])->state)->toBe(ResultState::Ok);
});

it('uses a different key when the compute context differs, so an old result is not served for the new one', function () {
    $w = crWorld();
    Cluster::seedSubscription($w['ws'], $w['target'], ['block_version_id' => $w['version'], 'compute_context' => 'cc1:other']);

    crCompute($w);

    $other = ResultKey::of($w['ws'], $w['version'], $w['fetchKey'], 'cc1:other');

    expect($this->store->payloadSeq($w['ws'], $w['key']))->toBe(5)
        ->and($this->store->payloadSeq($w['ws'], $other))->toBe(5)
        ->and($other)->not->toBe($w['key'])
        ->and($this->store->payloadSeq($w['ws'], ResultKey::of($w['ws'], $w['version'], $w['fetchKey'], 'cc1:never')))->toBeNull();
});

it('queues one signed-by-the-queue job with IDs only when the consumer sees a payload change, and a malformed event queues none', function () {
    Queue::fake();
    $w = crWorld();
    $event = fn (array $data) => new OutboxEnvelope((string) Str::uuid7(), 'ingestion.payload.changed', 1, $w['ws'], 'sync_target:'.$w['target'], 1, CarbonImmutable::now(), null, null, $data);
    $consumer = new ComputeOnPayloadChanged;

    expect($consumer->handles($event([])))->toBeTrue();

    $consumer->handle($event(['sync_target_id' => $w['target'], 'payload_seq' => 5]));
    $consumer->handle($event(['sync_target_id' => 'not-a-uuid']));

    Queue::assertPushed(ComputeResultsJob::class, 1);
    Queue::assertPushed(ComputeResultsJob::class, function (ComputeResultsJob $job) use ($w) {
        // The job's own properties are the two IDs: no payload, value or period travels with it.
        $own = array_diff(array_keys(get_object_vars($job)), array_keys(get_class_vars(Queueable::class)));

        return $job->workspaceId === $w['ws'] && $job->syncTargetId === $w['target'] && $job->queue === 'compute'
            && $job->referencedIds() === ['sync_targets' => [$w['target']]]
            && in_array('workspaceId', $own, true) && in_array('syncTargetId', $own, true);
    });
});

it('has the sweep queue a compute for a target whose result is missing or behind, and none once it has caught up', function () {
    Queue::fake();
    $w = crWorld(seq: 5);

    expect(app(SweepStaleResults::class)->run())->toBe(1);
    Queue::assertPushed(ComputeResultsJob::class, fn (ComputeResultsJob $j) => $j->syncTargetId === $w['target']);

    crCompute($w);
    Queue::fake();

    expect(app(SweepStaleResults::class)->run())->toBe(0);

    // The payload moved on (a lost event) and then the cache was flushed.
    Cluster::superuser()->prepare('UPDATE sync_targets SET payload_seq = 6 WHERE id = ?')->execute([$w['target']]);

    expect(app(SweepStaleResults::class)->run())->toBe(1);

    Queue::fake();
    $this->valkey->command('FLUSHALL');

    expect(app(SweepStaleResults::class)->run())->toBe(1);
});

it('reads nothing from a retired target or one without a payload', function () {
    $w = crWorld();
    Cluster::superuser()->prepare('UPDATE sync_targets SET retired_at = now() WHERE id = ?')->execute([$w['target']]);

    expect(app(WorkspaceTransaction::class)->run($w['ws'], fn () => app(ComputeSources::class)->target($w['ws'], $w['target'])))->toBeNull()
        ->and(crCompute($w))->toBe(0);
});
