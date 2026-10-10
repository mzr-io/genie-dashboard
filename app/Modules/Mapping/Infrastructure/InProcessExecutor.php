<?php

namespace App\Modules\Mapping\Infrastructure;

use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\Mapping\Contracts\ExecutionResult;
use App\Modules\Mapping\Contracts\GuardExceeded;
use App\Modules\Mapping\Contracts\PlanGuards;
use App\Modules\Mapping\Contracts\QueryExecutor;
use App\Platform\Json\DecimalLiteral;
use App\Platform\Json\SamplePath;
use Closure;

/**
 * Runs a plan in this process over the lossless tree. Plan v1 has two stages: extract (each alias's stored path evaluated by {@see SamplePath})
 * and shape (each slot takes its alias's value). A path that selects exactly one scalar fills the slot, as the digits received; no value, several
 * values or a non-scalar make the slot unavailable (`missing`, `not_single_value`, `wrong_type`) and it is null, never 0.
 *
 * Guards: a path evaluation is one evaluation; the values every path selected are the extracted values; the wall clock is read between
 * evaluations. Passing a limit aborts the run with {@see GuardExceeded} and returns nothing.
 */
final class InProcessExecutor implements QueryExecutor
{
    /** @var Closure(): float */
    private readonly Closure $clock;

    /** @param  (Closure(): float)|null  $clock  milliseconds, monotonic; for tests */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000;
    }

    public function execute(array $plan, mixed $document, BlockType $type, PlanGuards $guards): ExecutionResult
    {
        $started = ($this->clock)();
        $evaluations = 0;
        $extracted = 0;
        $selected = [];

        /** @var array<string, string> $fields */
        $fields = $plan['extract']['fields'] ?? [];

        foreach ($fields as $alias => $path) {
            if (++$evaluations > $guards->maxEvaluations) {
                throw new GuardExceeded(GuardExceeded::EVALUATIONS);
            }

            $this->checkTime($started, $guards);
            $nodes = SamplePath::select($document, $path);
            $extracted += count($nodes);

            if ($extracted > $guards->maxExtractedValues) {
                throw new GuardExceeded(GuardExceeded::EXTRACTED_VALUES);
            }

            $this->checkTime($started, $guards);
            $selected[(string) $alias] = $nodes;
        }

        $valueTypes = [];

        foreach ($type->slots as $slot) {
            $valueTypes[$slot->key] = $slot->valueType;
        }

        $slots = [];
        $unavailable = [];

        /** @var array<string, string> $shape */
        $shape = $plan['shape'] ?? [];

        foreach ($shape as $slot => $alias) {
            [$value, $reason] = self::slotValue($selected[$alias] ?? [], $valueTypes[$slot] ?? 'text');
            $slots[(string) $slot] = $value;

            if ($reason !== null) {
                $unavailable[(string) $slot] = $reason;
            }
        }

        // A slot the plan does not shape has no value, and says why.
        foreach ($valueTypes as $key => $_) {
            if (! array_key_exists($key, $slots)) {
                $slots[$key] = null;
                $unavailable[$key] = ExecutionResult::MISSING;
            }
        }

        $this->checkTime($started, $guards);

        return new ExecutionResult($slots, $unavailable);
    }

    private function checkTime(float $started, PlanGuards $guards): void
    {
        if (($this->clock)() - $started > $guards->maxWallMs) {
            throw new GuardExceeded(GuardExceeded::WALL_TIME);
        }
    }

    /**
     * @param  list<mixed>  $nodes
     * @return array{0: string|bool|null, 1: string|null} the value and, when there is none, why
     */
    private static function slotValue(array $nodes, string $valueType): array
    {
        if ($nodes === []) {
            return [null, ExecutionResult::MISSING];
        }

        if (count($nodes) > 1) {
            return [null, ExecutionResult::NOT_SINGLE_VALUE];
        }

        $node = $nodes[0];

        if ($node === null) {
            return [null, ExecutionResult::MISSING];
        }

        $wrong = [null, ExecutionResult::WRONG_TYPE];

        if (is_bool($node)) {
            return $valueType === 'boolean' ? [$node, null] : $wrong;
        }

        if ($node instanceof DecimalLiteral) {
            return $valueType === 'boolean' ? $wrong : [$node->lexeme, null];
        }

        if (is_string($node)) {
            return match ($valueType) {
                'boolean' => $wrong,
                'number' => preg_match(ExecutionResult::DECIMAL, trim($node)) === 1 ? [trim($node), null] : $wrong,
                default => [$node, null],
            };
        }

        // An object or an array: not a scalar.
        return $wrong;
    }
}
