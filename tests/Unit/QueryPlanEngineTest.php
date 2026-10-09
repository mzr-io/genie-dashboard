<?php

use App\Modules\Blocks\Application\DraftConfig;
use App\Modules\Blocks\Contracts\InvalidDraft;
use App\Modules\BlockTypes\Application\LoadBlockTypes;
use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\BlockTypes\Infrastructure\PackageShapers;
use App\Modules\Mapping\Application\DecimalMath;
use App\Modules\Mapping\Application\PreviewRateLimit;
use App\Modules\Mapping\Application\QueryPlanEngine;
use App\Modules\Mapping\Contracts\GuardExceeded;
use App\Modules\Mapping\Contracts\InvalidPlan;
use App\Modules\Mapping\Contracts\PlanGuards;
use App\Modules\Mapping\Contracts\PreviewThrottled;
use App\Modules\Mapping\Contracts\QueryPlan;
use App\Modules\Mapping\Contracts\SettingNotConfigured;
use App\Modules\Mapping\Infrastructure\InProcessExecutor;
use App\Modules\Mapping\Infrastructure\PreviewSettings;
use App\Platform\Json\LosslessJson;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;

// Story 3.10: the Query Plan engine, plan v1 (extract and shape), its guards, its settings and its arithmetic helper.
const QP_WORKSPACE = '018f0000-0000-7000-8000-00000000000a';

const QP_SAMPLE = <<<'JSON'
{"data":{"revenue":12345678901234567890.10,"label":"Net revenue","delta":-2.50,"rows":[{"v":1},{"v":2}],"nested":{"a":1},"flag":true,"nothing":null,"word":"abc"}}
JSON;

function qpType(): BlockType
{
    return (new LoadBlockTypes(dirname(__DIR__, 2).'/block-types'))->resolve('kpi-card', 1);
}

function qpSettings(array $values = []): PreviewSettings
{
    $preview = [];

    foreach ($values + ['membership_limit' => '2', 'window_seconds' => '60', 'max_extracted_values' => '100', 'max_evaluations' => '100', 'max_wall_ms' => '1000'] as $name => $value) {
        $preview[$name] = ['value' => $value];
    }

    return new PreviewSettings(new Repository(['dashflow' => ['preview' => $preview]]));
}

function qpEngine(?Closure $clock = null, array $settings = []): QueryPlanEngine
{
    return new QueryPlanEngine(new InProcessExecutor($clock), new PackageShapers, qpSettings($settings));
}

/** The reason of every slot of the Block Type that a plan does not shape: `missing`. @return array<string, string> */
function qpUnshaped(array $shaped): array
{
    $out = [];

    foreach (qpType()->slots as $slot) {
        if (! in_array($slot->key, $shaped, true)) {
            $out[$slot->key] = 'missing';
        }
    }

    return $out;
}

function qpPlan(array $fields, array $shape): array
{
    return ['v' => 1, 'extract' => ['fields' => $fields], 'shape' => $shape];
}

function qpRun(array $plan, ?Closure $clock = null, ?PlanGuards $guards = null)
{
    $engine = qpEngine($clock);

    return $engine->run(qpType(), $plan, LosslessJson::decode(QP_SAMPLE), $guards ?? $engine->guards());
}

it('fills slots from single scalars and keeps numbers as the digits received', function () {
    $result = qpRun(qpPlan(
        ['rev' => '$.data.revenue', 'name' => '$.data.label', 'cmp' => '$.data.delta'],
        ['headline_value' => 'rev', 'kpi_label' => 'name', 'comparison' => 'cmp'],
    ));

    expect($result->unavailable)->toBe(qpUnshaped(['headline_value', 'kpi_label', 'comparison']))
        ->and($result->payload['state'])->toBe('normal')
        ->and($result->payload['headline']['value'])->toBe('12345678901234567890.10')
        ->and($result->payload['kpiLabel'])->toBe('Net revenue')
        ->and($result->payload['comparison']['value'])->toBe('-2.50');
});

it('makes a slot unavailable with its reason, passes null (never 0) and still renders the others', function () {
    $result = qpRun(qpPlan(
        ['gone' => '$.data.absent', 'many' => '$.data.rows[*].v', 'obj' => '$.data.nested', 'list' => '$.data.rows', 'null' => '$.data.nothing', 'flag' => '$.data.flag', 'word' => '$.data.word', 'name' => '$.data.label'],
        ['headline_value' => 'gone', 'comparison' => 'many', 'title' => 'obj', 'subtitle' => 'list', 'secondary_note' => 'null', 'status_badge' => 'flag', 'icon' => 'name', 'kpi_label' => 'name'],
    ));

    expect($result->unavailable)->toBe([
        'headline_value' => 'missing',
        'comparison' => 'not_single_value',
        'title' => 'wrong_type',
        'subtitle' => 'wrong_type',
        'secondary_note' => 'missing',
        'status_badge' => 'wrong_type',
    ] + qpUnshaped(['headline_value', 'comparison', 'title', 'subtitle', 'secondary_note', 'status_badge', 'icon', 'kpi_label']))
        ->and($result->payload['headline'])->toBe(['available' => false, 'field' => 'Headline value'])
        ->and($result->payload['state'])->toBe('unavailable')
        ->and($result->payload['comparison'])->toBeNull()
        ->and($result->payload['kpiLabel'])->toBe('Net revenue')
        ->and($result->payload['icon'])->toBe('Net revenue');

    // Text where a number is wanted.
    $result = qpRun(qpPlan(['word' => '$.data.word'], ['headline_value' => 'word']));
    expect($result->unavailable)->toBe(['headline_value' => 'wrong_type'] + qpUnshaped(['headline_value']));
});

it('gives a slot the plan does not shape the reason missing, and a null value', function () {
    $result = qpRun(qpPlan(['name' => '$.data.label'], ['kpi_label' => 'name']));

    expect($result->unavailable['headline_value'])->toBe('missing')
        ->and($result->unavailable['title'])->toBe('missing')
        ->and($result->unavailable)->not->toHaveKey('kpi_label')
        ->and($result->payload['headline']['available'])->toBeFalse();
});

it('reads a wildcard that selects exactly one value as one value', function () {
    $result = qpRun(qpPlan(['one' => '$.data.nested[*]'], ['headline_value' => 'one']));

    expect($result->unavailable)->not->toHaveKey('headline_value')->and($result->payload['headline']['value'])->toBe('1');
});

it('refuses a plan of another version, an unknown stage, a bad path, a bad alias and an unknown slot, by field', function () {
    $type = qpType();
    $base = qpPlan(['a' => '$.x'], ['headline_value' => 'a']);

    expect(QueryPlan::validate($base, $type))->toBe([])
        ->and(array_keys(QueryPlan::validate(['v' => 2] + $base, $type)))->toBe(['config.query_plan.v'])
        ->and(array_keys(QueryPlan::validate(['v' => '1'] + $base, $type)))->toBe(['config.query_plan.v'])
        ->and(array_keys(QueryPlan::validate(['filter' => []] + $base, $type)))->toBe(['config.query_plan.filter'])
        ->and(array_keys(QueryPlan::validate(qpPlan(['a' => '$..x'], []), $type)))->toBe(['config.query_plan.extract.fields.a'])
        ->and(array_keys(QueryPlan::validate(qpPlan(['1a' => '$.x'], []), $type)))->toBe(['config.query_plan.extract.fields'])
        ->and(array_keys(QueryPlan::validate(qpPlan(['a' => '$.x'], ['nope' => 'a']), $type)))->toBe(['config.query_plan.shape.nope'])
        ->and(array_keys(QueryPlan::validate(qpPlan(['a' => '$.x'], ['headline_value' => 'b']), $type)))->toBe(['config.query_plan.shape.headline_value'])
        ->and(array_keys(QueryPlan::validate('x', $type)))->toBe(['config.query_plan'])
        ->and(array_keys(QueryPlan::validate(['v' => 1, 'extract' => ['fields' => ['a']], 'shape' => []], $type)))->toBe(['config.query_plan.extract.fields']);

    expect(fn () => qpEngine()->run($type, ['v' => 2] + $base, LosslessJson::decode(QP_SAMPLE), new PlanGuards(1, 1, 1)))->toThrow(InvalidPlan::class);
});

it('keeps the stage order fixed with extract first and shape last', function () {
    expect(QueryPlan::STAGE_ORDER)->toBe(['extract', 'filter', 'row_calc', 'group', 'aggregate', 'aggregate_calc', 'sort', 'top_n', 'shape'])
        ->and(QueryPlan::STAGES_V1)->toBe(['extract', 'shape']);
});

it('aborts, with no partial result, when a guard is passed', function () {
    $plan = qpPlan(['a' => '$.data.rows[*].v', 'b' => '$.data.label'], ['headline_value' => 'b']);
    $document = LosslessJson::decode(QP_SAMPLE);
    $engine = qpEngine();
    $run = fn (PlanGuards $guards) => $engine->run(qpType(), $plan, $document, $guards);

    expect(fn () => $run(new PlanGuards(2, 10, 1000)))->toThrow(fn (GuardExceeded $e) => $e->guard === 'max_extracted_values')
        ->and(fn () => $run(new PlanGuards(3, 1, 1000)))->toThrow(fn (GuardExceeded $e) => $e->guard === 'max_evaluations')
        ->and($run(new PlanGuards(3, 2, 1000))->unavailable)->toBe(['headline_value' => 'wrong_type'] + qpUnshaped(['headline_value']));

    // Wall time: a clock that moves 10 ms at every read.
    $now = 0.0;
    $clock = function () use (&$now): float {
        return $now += 10.0;
    };
    $slow = qpEngine($clock);

    expect(fn () => $slow->run(qpType(), $plan, $document, new PlanGuards(100, 100, 25)))->toThrow(fn (GuardExceeded $e) => $e->guard === 'max_wall_ms');
});

it('refuses a run while a preview setting is unset or not a positive whole number, naming the variable', function () {
    $names = [
        'membership_limit' => 'DASHFLOW_PREVIEW_MEMBERSHIP_LIMIT',
        'window_seconds' => 'DASHFLOW_PREVIEW_WINDOW_SECONDS',
        'max_extracted_values' => 'DASHFLOW_PREVIEW_MAX_EXTRACTED_VALUES',
        'max_evaluations' => 'DASHFLOW_PREVIEW_MAX_EVALUATIONS',
        'max_wall_ms' => 'DASHFLOW_PREVIEW_MAX_WALL_MS',
    ];

    foreach ($names as $setting => $env) {
        foreach ([null, '', '0', '-1', '1.5', 'abc'] as $bad) {
            expect(fn () => qpSettings([$setting => $bad])->guards())->toThrow(fn (SettingNotConfigured $e) => $e->setting === $env);
        }
    }

    expect(qpSettings()->guards())->toEqual(new PlanGuards(100, 100, 1000));
});

it('limits previews per membership and says how long to wait', function () {
    $limiter = new RateLimiter(new CacheRepository(new ArrayStore));
    $limit = new PreviewRateLimit(qpSettings(['membership_limit' => '2']), $limiter);

    $limit->hit(QP_WORKSPACE, 'm1');
    $limit->hit(QP_WORKSPACE, 'm1');
    $limit->hit(QP_WORKSPACE, 'm2');

    expect(fn () => $limit->hit(QP_WORKSPACE, 'm1'))->toThrow(fn (PreviewThrottled $e) => $e->retryAfter >= 1 && $e->retryAfter <= 60);
});

it('does arithmetic with BcMath\Number at the configured scale and refuses an unset scale', function () {
    $math = new DecimalMath(new Repository(['dashflow' => ['tunables' => ['numbers' => ['decimal_scale' => ['value' => '2']]]]]));

    expect($math->add('0.10', '0.20'))->toBe('0.30')
        ->and($math->mul('12345678901234567890.10', '2'))->toBe('24691357802469135780.20')
        ->and($math->div('1', '3'))->toBe('0.33')
        ->and($math->sub('1', '2.5'))->toBe('-1.50');

    expect(fn () => (new DecimalMath(new Repository(['dashflow' => []])))->add('1', '2'))->toThrow(SettingNotConfigured::class);
});

it('normalises a valid plan into its stored order', function () {
    $type = qpType();
    $config = DraftConfig::normalise(['query_plan' => ['shape' => ['headline_value' => 'a'], 'extract' => ['fields' => ['a' => '$.x']], 'v' => 1]], $type);

    expect($config['query_plan'])->toBe(['v' => 1, 'extract' => ['fields' => ['a' => '$.x']], 'shape' => ['headline_value' => 'a']]);

    $stored = DraftConfig::decode(DraftConfig::encode($config));
    expect($stored['query_plan'])->toBe($config['query_plan']);

    // A later save of Step 1 alone keeps the stored plan; one that carries a plan replaces it.
    $kept = DraftConfig::merge($stored, DraftConfig::normalise(['description' => 'x'], $type));
    expect($kept['query_plan'])->toBe($config['query_plan']);
});

it('refuses a plan with version 2 as config.query_plan.v', function () {
    expect(fn () => DraftConfig::normalise(['query_plan' => ['v' => 2, 'extract' => ['fields' => []], 'shape' => []]], qpType()))->toThrow(
        fn (InvalidDraft $e) => array_keys($e->errors) === ['config.query_plan.v'] && $e->errorCode === 'blocks.invalid_config',
    );
});

it('replaces a stored plan with the plan a later save carries', function () {
    $type = qpType();
    $stored = DraftConfig::normalise(['description' => 'x', 'query_plan' => qpPlan(['a' => '$.x'], ['headline_value' => 'a'])], $type);
    $step = DraftConfig::normalise(['query_plan' => qpPlan(['b' => '$.y'], ['kpi_label' => 'b'])], $type);

    $merged = DraftConfig::merge($stored, $step);

    expect($merged['query_plan'])->toBe(qpPlan(['b' => '$.y'], ['kpi_label' => 'b']));
});
