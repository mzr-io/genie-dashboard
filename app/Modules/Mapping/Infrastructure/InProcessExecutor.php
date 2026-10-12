<?php

namespace App\Modules\Mapping\Infrastructure;

use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\Mapping\Contracts\ExecutionResult;
use App\Modules\Mapping\Contracts\GuardExceeded;
use App\Modules\Mapping\Contracts\PlanGuards;
use App\Modules\Mapping\Contracts\QueryExecutor;
use App\Modules\Mapping\Contracts\Transforms;
use App\Platform\Json\DecimalLiteral;
use App\Platform\Json\SamplePath;
use Closure;

/**
 * Runs a plan in this process over the lossless tree. Plan v1 has extract (each alias's stored path evaluated by {@see SamplePath}), the row stages
 * filter, sort and top-N (Story 5.1) and shape (each slot takes its alias's value).

 * Rows: the longest list a path selected sets the row count, and every list of that length is a column of the same rows (the n-th value of each is one
 * row); a shorter list (a single value beside the rows) is left as it is. The row stages keep, order and cut those rows in the fixed order filter, sort,
 * top-N, and the shape reads the columns that are left. A stage field that is missing, or whose values do not suit the stage, makes every column of the
 * rows empty, so the slots that read them are unavailable, never 0. A path that selects exactly one scalar fills the slot, as the digits received; no value, several
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

        $rows = null;
        $columns = [];

        if (isset($plan[Transforms::FILTER]) || isset($plan[Transforms::SORT]) || isset($plan[Transforms::TOP_N])) {
            [$selected, $rows, $columns] = $this->transform($plan, $document, $selected, $guards, $started, $evaluations, $extracted);
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

        return new ExecutionResult($slots, $unavailable, $rows, $columns);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, list<mixed>>  $selected  the values each alias selected
     * @return array{0: array<string, list<mixed>>, 1: array{input: int, steps: list<int>}, 2: array<string, list<string|bool|null>>}
     */
    private function transform(array $plan, mixed $document, array $selected, PlanGuards $guards, float $started, int &$evaluations, int &$extracted): array
    {
        $columns = [];
        $fieldOf = function (string $path) use ($document, $guards, $started, &$evaluations, &$extracted, &$columns): array {
            if (isset($columns[$path])) {
                return $columns[$path];
            }

            if (++$evaluations > $guards->maxEvaluations) {
                throw new GuardExceeded(GuardExceeded::EVALUATIONS);
            }

            $this->checkTime($started, $guards);
            $nodes = SamplePath::select($document, $path);
            $extracted += count($nodes);

            if ($extracted > $guards->maxExtractedValues) {
                throw new GuardExceeded(GuardExceeded::EXTRACTED_VALUES);
            }

            return $columns[$path] = $nodes;
        };

        // The rows are as many as the longest list any path selected, the stage fields included; a list of that length is a column of them.
        $rowCount = 0;

        foreach ($selected as $nodes) {
            $rowCount = max($rowCount, count($nodes));
        }

        foreach ([...(array) ($plan[Transforms::FILTER] ?? []), ...(array) ($plan[Transforms::SORT] ?? [])] as $step) {
            $rowCount = max($rowCount, count($fieldOf((string) $step['field'])));
        }

        $aligned = [];

        foreach ($selected as $alias => $nodes) {
            if (count($nodes) === $rowCount) {
                $aligned[] = (string) $alias;
            }
        }

        $keep = $rowCount === 0 ? [] : range(0, $rowCount - 1);
        $counts = [];
        $broken = false;

        $steps = [];

        foreach ((array) ($plan[Transforms::FILTER] ?? []) as $step) {
            $steps[] = [Transforms::FILTER, $step];
        }

        foreach ((array) ($plan[Transforms::SORT] ?? []) as $step) {
            $steps[] = [Transforms::SORT, $step];
        }

        if (isset($plan[Transforms::TOP_N])) {
            $steps[] = [Transforms::TOP_N, $plan[Transforms::TOP_N]];
        }

        foreach ($steps as [$kind, $step]) {
            if (! $broken) {
                if ($kind === Transforms::TOP_N) {
                    $keep = array_slice($keep, 0, (int) $step['count']);
                } else {
                    $values = $fieldOf((string) $step['field']);

                    // A field the rows do not all have (absent, or on a different list) cannot be read per row.
                    if (count($values) !== $rowCount) {
                        $broken = true;
                    } elseif ($kind === Transforms::FILTER) {
                        $kept = self::filter($keep, $values, (string) $step['operator'], (string) $step['value']);

                        if ($kept === null) {
                            $broken = true;
                        } else {
                            $keep = $kept;
                        }
                    } else {
                        $sorted = self::sort($keep, $values, (string) $step['direction'], $guards, fn () => $this->checkTime($started, $guards));

                        if ($sorted === null) {
                            $broken = true;
                        } else {
                            $keep = $sorted;
                        }
                    }
                }
            }

            $counts[] = $broken ? 0 : count($keep);
        }

        $left = [];

        foreach ($aligned as $alias) {
            $nodes = $selected[$alias];
            $selected[$alias] = $broken ? [] : array_map(fn (int $i): mixed => $nodes[$i], $keep);
            $left[$alias] = array_map(
                fn (mixed $node): string|bool|null => match (true) {
                    $node instanceof DecimalLiteral => $node->lexeme,
                    is_string($node), is_bool($node) => $node,
                    default => null,
                },
                $selected[$alias],
            );
        }

        return [$selected, ['input' => $rowCount, 'steps' => $counts], $left];
    }

    /**
     * @param  list<int>  $keep
     * @param  list<mixed>  $values
     * @return list<int>|null the rows that pass, or null when a value does not suit the operator
     */
    private static function filter(array $keep, array $values, string $operator, string $wanted): ?array
    {
        $numeric = in_array($operator, Transforms::NUMERIC_OPERATORS, true);

        if ($numeric && preg_match(ExecutionResult::DECIMAL, trim($wanted)) !== 1) {
            return null;
        }

        $out = [];

        foreach ($keep as $i) {
            $node = $values[$i];

            if ($numeric) {
                $text = $node instanceof DecimalLiteral ? $node->lexeme : (is_string($node) && preg_match(ExecutionResult::DECIMAL, trim($node)) === 1 ? trim($node) : null);

                if ($text === null) {
                    return null;
                }

                $order = self::compare($text, trim($wanted));
                $pass = match ($operator) {
                    'gt' => $order > 0,
                    'gte' => $order >= 0,
                    'lt' => $order < 0,
                    default => $order <= 0,
                };
            } else {
                if (is_array($node) || is_object($node) && ! $node instanceof DecimalLiteral) {
                    return null;
                }

                $text = match (true) {
                    $node === null => '',
                    is_bool($node) => $node ? 'true' : 'false',
                    default => (string) $node,
                };
                $pass = match ($operator) {
                    'is' => $text === $wanted,
                    'is_not' => $text !== $wanted,
                    default => $wanted === '' || mb_stripos($text, $wanted) !== false,
                };
            }

            if ($pass) {
                $out[] = $i;
            }
        }

        return $out;
    }

    /**
     * A stable sort of the rows by one field: numbers compare as numbers, text as text, and nothing else is sortable (an empty value goes last).
     *
     * @param  list<int>  $keep
     * @param  list<mixed>  $values
     * @return list<int>|null null when a value cannot be sorted or the kinds are mixed
     */
    private static function sort(array $keep, array $values, string $direction, PlanGuards $guards, Closure $tick): ?array
    {
        $kind = null;
        $keyed = [];

        foreach ($keep as $position => $i) {
            $node = $values[$i];

            if ($node === null) {
                $keyed[] = [$position, $i, null];

                continue;
            }

            $sortKind = match (true) {
                $node instanceof DecimalLiteral => 'number',
                is_string($node) => 'text',
                default => null,
            };

            if ($sortKind === null || ($kind !== null && $kind !== $sortKind)) {
                return null;
            }

            $kind = $sortKind;
            $keyed[] = [$position, $i, $node instanceof DecimalLiteral ? $node->lexeme : $node];
        }

        $sign = $direction === 'desc' ? -1 : 1;
        $comparisons = 0;

        usort($keyed, function (array $a, array $b) use ($kind, $sign, $tick, &$comparisons): int {
            if (++$comparisons % 4096 === 0) {
                $tick();
            }

            if ($a[2] === null || $b[2] === null) {
                // Empty values last in either direction; two empty ones keep the source order.
                return ($a[2] === null && $b[2] === null) ? $a[0] <=> $b[0] : ($a[2] === null ? 1 : -1);
            }

            $order = $kind === 'number' ? self::compare($a[2], $b[2]) : strcmp($a[2], $b[2]);

            return $order !== 0 ? $order * $sign : $a[0] <=> $b[0];
        });

        return array_map(fn (array $row): int => $row[1], $keyed);
    }

    /** Exact decimal comparison of two JSON or decimal-shaped lexemes: no float is made. */
    private static function compare(string $a, string $b): int
    {
        /** @var numeric-string $left */
        $left = self::plain($a);
        /** @var numeric-string $right */
        $right = self::plain($b);

        return bccomp($left, $right, 400);
    }

    /** A lexeme with its exponent written out. */
    private static function plain(string $lexeme): string
    {
        if (preg_match('/\A([+-]?)(\d*)\.?(\d*)(?:[eE]([+-]?\d+))?\z/', $lexeme, $m) !== 1) {
            return '0';
        }

        [, $sign, $whole, $fraction] = $m;
        $exponent = max(-300, min(300, (int) ($m[4] ?? 0)));
        $digits = $whole.$fraction;
        $point = strlen($whole) + $exponent;

        if ($point <= 0) {
            $digits = str_repeat('0', 1 - $point).$digits;
            $point = 1;
        } elseif ($point > strlen($digits)) {
            $digits .= str_repeat('0', $point - strlen($digits));
        }

        $text = substr($digits, 0, $point).(strlen($digits) > $point ? '.'.substr($digits, $point) : '');

        return ($sign === '-' ? '-' : '').$text;
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
