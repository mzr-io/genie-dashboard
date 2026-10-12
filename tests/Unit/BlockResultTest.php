<?php

use App\Modules\Results\Contracts\BlockResult;
use App\Modules\Results\Contracts\ComputeContext;
use App\Modules\Results\Contracts\ResultKey;
use App\Modules\Results\Contracts\ResultState;

// Story 4.2: the BlockResult DTO, the compute context, the cache key and the state precedence.
function aResult(array $over = []): BlockResult
{
    return BlockResult::fromArray($over + [
        'v' => 1, 'generation_id' => 'g-1', 'payload_seq' => 7, 'data_as_of' => '2026-10-12T10:00:00Z', 'last_success_at' => '2026-10-12T10:05:00Z',
        'state' => 'ok', 'stale' => false, 'refreshing' => false,
        'slot_states' => ['headline_value' => ['state' => 'ok'], 'kpi_label' => ['state' => 'unavailable', 'reason' => 'missing']],
        'render_payload' => ['headline' => ['value' => '12345678901234567890.10']], 'result_etag' => 'e', 'computed_at' => '2026-10-12T10:06:00Z',
    ]);
}

it('round-trips every field and derives partial from the slot states', function () {
    $result = aResult();

    expect(BlockResult::fromArray($result->toArray()))->toEqual($result)
        ->and($result->partial())->toBeTrue()
        ->and(aResult(['slot_states' => ['a' => ['state' => 'ok']]])->partial())->toBeFalse()
        ->and(aResult(['slot_states' => ['a' => ['state' => 'unavailable', 'reason' => 'missing']]])->partial())->toBeFalse();
});

it('refuses a DTO of another version, an unknown state or a missing field', function (array $over) {
    expect(BlockResult::fromArray($over + aResult()->toArray()))->toBeNull();
})->with([
    'version' => [['v' => 2]],
    'state' => [['state' => 'loading']],
    'sequence' => [['payload_seq' => '7']],
    'payload' => [['render_payload' => 'x']],
]);

it('gives the same etag for the same key and generation and another for any other', function () {
    $etag = BlockResult::etag('k', 'g');

    expect($etag)->toBe(hash('sha256', "k\ng\n1"))
        ->and(BlockResult::etag('k', 'g'))->toBe($etag)
        ->and(BlockResult::etag('k2', 'g'))->not->toBe($etag)
        ->and(BlockResult::etag('k', 'g2'))->not->toBe($etag)
        ->and(BlockResult::etag('k', 'g', 2))->not->toBe($etag);
});

it('changes the compute context when any one of its inputs changes', function () {
    $base = ['period' => ['start' => '2026-10-01', 'end' => '2026-11-01'], 'comparison' => ['start' => '2026-09-01', 'end' => '2026-10-01'], 'tz' => 'UTC', 'settings' => 3, 'shaper' => 1, 'engine' => 1];
    $of = fn (array $v): string => ComputeContext::of($v['period'], $v['comparison'], $v['tz'], $v['settings'], $v['shaper'], $v['engine']);
    $original = $of($base);

    expect($of($base))->toBe($original)->and($original)->toStartWith('cc1:');

    foreach ([
        ['period', ['start' => '2026-11-01', 'end' => '2026-12-01']],
        ['comparison', null],
        ['tz', 'Asia/Dhaka'],
        ['settings', 4],
        ['shaper', 2],
        ['engine', 2],
    ] as [$input, $value]) {
        expect($of([$input => $value] + $base))->not->toBe($original, "changing {$input}");
    }
});

it('builds the cache key from the Workspace, the version, the primary fetch key and the context', function () {
    $ws = '0197a000-0000-7000-8000-000000000001';
    $bv = '0197A000-0000-7000-8000-000000000002';
    $key = ResultKey::of($ws, $bv, 'fk1:'.str_repeat('a', 64), 'cc1:abc');

    expect($key)->toBe($ws.':cache:res:0197a000-0000-7000-8000-000000000002:fk1:'.str_repeat('a', 64).':cc1:abc');
});

it('orders states access_removed, unpublished, error, pending, unavailable, empty, ok', function () {
    expect(array_map(fn (ResultState $s) => $s->value, ResultState::precedence()))
        ->toBe(['access_removed', 'unpublished', 'error', 'pending', 'unavailable', 'empty', 'ok'])
        ->and(ResultState::strongest(ResultState::Ok, ResultState::Empty, ResultState::Unavailable))->toBe(ResultState::Unavailable)
        ->and(ResultState::strongest(ResultState::Ok, ResultState::Error, ResultState::Pending))->toBe(ResultState::Error)
        ->and(ResultState::strongest(ResultState::Empty, ResultState::AccessRemoved))->toBe(ResultState::AccessRemoved)
        ->and(ResultState::strongest())->toBe(ResultState::Ok);
});
