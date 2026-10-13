<?php

namespace App\Modules\Mapping\Infrastructure;

use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\Mapping\Application\DecimalMath;
use App\Modules\Mapping\Application\Expressions\ExpressionEvaluator;
use App\Modules\Mapping\Application\Expressions\ExpressionNumber;
use App\Modules\Mapping\Application\Expressions\ExpressionResolver;
use App\Modules\Mapping\Application\Expressions\ExpressionTypeError;
use App\Modules\Mapping\Contracts\ExecutionResult;
use App\Modules\Mapping\Contracts\GuardExceeded;
use App\Modules\Mapping\Contracts\PlanGuards;
use App\Modules\Mapping\Contracts\QueryExecutor;
use App\Modules\Mapping\Contracts\SettingNotConfigured;
use App\Modules\Mapping\Contracts\Templates;
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

    /**
     * @param  (Closure(): float)|null  $clock  milliseconds, monotonic; for tests
     * @param  DecimalMath|null  $math  the arithmetic of SUM and AVG, at the one decimal scale of the platform; without it those two are refused
     */
    public function __construct(?Closure $clock = null, private readonly ?DecimalMath $math = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000;
    }

    public function execute(array $plan, mixed $document, BlockType $type, PlanGuards $guards, ?array $period = null): ExecutionResult
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

            // A calculated field (`calc:<name>`) is made by a calc stage, not read from the document.
            if (str_starts_with((string) $path, Transforms::CALC_PREFIX) || str_starts_with((string) $path, Templates::PREFIX)) {
                $selected[(string) $alias] = [];

                continue;
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
        $table = [];
        $rowsLeft = 0;
        // The table as it was before the top-N stage (Story 5.9), by stored path: the rows an Expanded view browses.
        $beforeTop = null;

        foreach ($selected as $nodes) {
            $rowsLeft = max($rowsLeft, count($nodes));
        }

        if (array_intersect_key($plan, array_flip([Transforms::FILTER, Transforms::ROW_CALC, Transforms::GROUP, Transforms::AGGREGATE, Transforms::AGGREGATE_CALC, Transforms::SORT, Transforms::TOP_N])) !== []) {
            [$selected, $rows, $columns, $table, $rowsLeft, $beforeTop] = $this->transform($plan, $document, $selected, $guards, $started, $evaluations, $extracted);
        }

        $valueTypes = [];
        $lists = [];

        foreach ($type->slots as $slot) {
            $valueTypes[$slot->key] = $slot->valueType;
            $lists[$slot->key] = $slot->cardinality === 'rows';
        }

        $slots = [];
        $unavailable = [];

        /** @var array<string, string> $shape */
        $shape = $plan['shape'] ?? [];

        /** @var array<string, array<string, mixed>> $templates */
        $templates = is_array($plan[Templates::KEY] ?? null) ? $plan[Templates::KEY] : [];

        // Without row stages the rows are the longest list any path of the plan selects, the template's own fields included.
        if ($rows === null && $templates !== []) {
            foreach ($templates as $template) {
                foreach ((array) ($template['parts'] ?? []) as $part) {
                    if (($part['t'] ?? null) === 'field' && is_string($part['path'] ?? null)) {
                        $rowsLeft = max($rowsLeft, count(SamplePath::select($document, $part['path'])));
                    }
                }
            }
        }

        foreach ($shape as $slot => $alias) {
            if (isset($templates[(string) $slot]) && ($fields[$alias] ?? null) === Templates::PREFIX.$slot) {
                /** @var list<array<string, mixed>> $parts */
                $parts = array_values((array) ($templates[(string) $slot]['parts'] ?? []));
                $slots[(string) $slot] = $this->render(
                    $parts,
                    $document,
                    $table,
                    $rowsLeft,
                    $period,
                    $guards,
                    $started,
                    $evaluations,
                    $extracted,
                );

                continue;
            }

            // A `rows` slot (a chart's axis or series) is the list of the value of every row; a single slot is one scalar.
            [$value, $reason] = ($lists[$slot] ?? false)
                ? self::slotList($selected[$alias] ?? [], $valueTypes[$slot] ?? 'text')
                : self::slotValue($selected[$alias] ?? [], $valueTypes[$slot] ?? 'text');
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

        // The rows behind a `rows` slot, before top-N and in the order the stages left them (Story 5.9): the same values the slot holds, uncut.
        $rowset = [];

        foreach ($shape as $slot => $alias) {
            if (! ($lists[$slot] ?? false)) {
                continue;
            }

            $path = $fields[$alias] ?? null;
            $nodes = $beforeTop !== null && is_string($path) && isset($beforeTop[$path])
                ? array_values($beforeTop[$path])
                : ($selected[$alias] ?? []);

            $rowset[(string) $slot] = array_map(
                fn (mixed $node): string|bool|null => match (true) {
                    $node instanceof DecimalLiteral => $node->lexeme,
                    is_string($node), is_bool($node) => $node,
                    default => null,
                },
                $nodes,
            );
        }

        $this->checkTime($started, $guards);

        return new ExecutionResult($slots, $unavailable, $rows, $columns, $rowset);
    }

    /**
     * The row stages in their fixed order: filter, group, aggregate, sort, top-N. The rows are a table of columns by stored path; the first stage that
     * cannot be run on them (a field the rows do not all have, a value the stage cannot read) empties every column, so the slots that read rows are
     * unavailable. After a group or an aggregate the table holds only the grouped and the aggregated fields.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, list<mixed>>  $selected  the values each alias selected
     * @return array{0: array<string, list<mixed>>, 1: array{input: int, steps: list<int>}, 2: array<string, list<string|bool|null>>, 3: array<string, array<mixed>>, 4: int, 5: array<string, array<mixed>>}
     */
    private function transform(array $plan, mixed $document, array $selected, PlanGuards $guards, float $started, int &$evaluations, int &$extracted): array
    {
        /** @var array<string, string> $paths */
        $paths = (array) ($plan['extract']['fields'] ?? []);
        $cache = [];
        $fieldOf = function (string $path) use ($document, $guards, $started, &$evaluations, &$extracted, &$cache): array {
            if (isset($cache[$path])) {
                return $cache[$path];
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

            return $cache[$path] = $nodes;
        };

        $filters = (array) ($plan[Transforms::FILTER] ?? []);
        $groupFields = array_map('strval', (array) ($plan[Transforms::GROUP]['fields'] ?? []));
        $aggregates = (array) ($plan[Transforms::AGGREGATE] ?? []);
        $sorts = (array) ($plan[Transforms::SORT] ?? []);
        $rowCalcs = (array) ($plan[Transforms::ROW_CALC] ?? []);
        $aggregateCalcs = (array) ($plan[Transforms::AGGREGATE_CALC] ?? []);
        $calcFields = [];

        // The fields the calculated fields read are columns too (a calculated field read by another is made by the stage, not read from the document).
        foreach ([...$rowCalcs, ...$aggregateCalcs] as $calc) {
            $reads = isset($calc['ratio']) ? [(string) $calc['ratio']['numerator'], (string) $calc['ratio']['denominator']] : ExpressionResolver::paths((array) ($calc['ast'] ?? ['t' => 'null']));

            foreach ($reads as $path) {
                if (! str_starts_with($path, Transforms::CALC_PREFIX)) {
                    $calcFields[] = $path;
                }
            }
        }

        $stageFields = [
            ...$calcFields,
            ...array_map(fn (array $s): string => (string) $s['field'], $filters),
            ...$groupFields,
            ...array_map(fn (array $s): string => (string) $s['field'], $aggregates),
            ...array_map(fn (array $s): string => (string) $s['field'], $sorts),
        ];

        // The rows are as many as the longest list any path selected, the stage fields included; a list of that length is a column of them.
        $rowCount = 0;

        foreach ($selected as $nodes) {
            $rowCount = max($rowCount, count($nodes));
        }

        $stageNodes = [];

        foreach (array_unique($stageFields) as $path) {
            $stageNodes[$path] = $fieldOf($path);
            $rowCount = max($rowCount, count($stageNodes[$path]));
        }

        /** @var array<string, list<mixed>> $table */
        $table = [];
        foreach ($paths as $alias => $path) {
            if (! str_starts_with($path, Transforms::CALC_PREFIX) && count($selected[(string) $alias] ?? []) === $rowCount) {
                $table[$path] = $selected[(string) $alias];
            }
        }

        foreach ($stageNodes as $path => $nodes) {
            if (count($nodes) === $rowCount) {
                $table[$path] = $nodes;
            }
        }

        $rows = $rowCount;
        $counts = [];
        $broken = false;
        $take = static fn (array $table, array $indexes): array => array_map(
            fn (array $column): array => array_map(fn (int $i): mixed => $column[$i], $indexes),
            $table,
        );

        foreach ($filters as $step) {
            if (! $broken) {
                /** @var list<mixed>|null $values */
                $values = $table[(string) $step['field']] ?? null;
                $kept = $values === null ? null : self::filter(self::indexes($rows), $values, (string) $step['operator'], (string) $step['value']);

                if ($kept === null) {
                    $broken = true;
                } else {
                    $table = $take($table, $kept);
                    $rows = count($kept);
                }
            }

            $counts[] = $broken ? 0 : $rows;
        }

        // Row-level calculated fields (the "row calc" stage): one value per row, read from the columns the rows have so far.
        foreach ($rowCalcs as $calc) {
            if (! $broken) {
                $column = $this->calculate($calc, $table, $rows);

                if ($column === null) {
                    $broken = true;
                } else {
                    $table[Transforms::CALC_PREFIX.$calc['name']] = $column;
                }
            }

            $counts[] = $broken ? 0 : $rows;
        }

        // The rows each group holds, by the position of the row now; null while the rows are not grouped.
        /** @var list<list<int>>|null $members */
        $members = null;
        $source = $table;

        if ($groupFields !== []) {
            if (! $broken) {
                foreach ($groupFields as $field) {
                    if (! isset($table[$field])) {
                        $broken = true;
                    }
                }
            }

            if (! $broken) {
                $index = [];
                $members = [];

                for ($i = 0; $i < $rows; $i++) {
                    $key = '';

                    foreach ($groupFields as $field) {
                        $key .= self::keyPart($table[$field][$i]).'|';
                    }

                    if (! isset($index[$key])) {
                        if (count($index) >= $guards->maxGroups) {
                            throw new GuardExceeded(GuardExceeded::GROUPS);
                        }

                        $index[$key] = count($index);
                        $members[$index[$key]] = [];
                    }

                    $members[$index[$key]][] = $i;
                }

                $this->checkTime($started, $guards);
                $source = $table;
                $table = [];

                foreach ($groupFields as $field) {
                    $table[$field] = array_map(fn (array $group): mixed => $source[$field][$group[0]], $members);
                }

                $rows = count($members);
            }

            $counts[] = $broken ? 0 : $rows;
        }

        foreach ($aggregates as $step) {
            if (! $broken) {
                if ($members === null) {
                    // Without a group the whole table is one group, and only the aggregates are left of it.
                    $members = $rows === 0 ? [] : [self::indexes($rows)];
                    $source = $table;
                    $table = [];
                    $rows = count($members);
                }

                /** @var list<mixed>|null $column */
                $column = $source[(string) $step['field']] ?? null;
                $made = $column === null ? null : $this->aggregate((string) $step['function'], $column, array_values($members));

                if ($made === null) {
                    $broken = true;
                } else {
                    $table[(string) $step['field']] = $made;
                }
            }

            $counts[] = $broken ? 0 : $rows;
        }

        // Aggregate-level calculated fields (the "aggregate calc" stage): one value per row that is left, and a ratio is made from the sums.
        foreach ($aggregateCalcs as $calc) {
            if (! $broken) {
                if (isset($calc['ratio'])) {
                    if ($members === null) {
                        $members = $rows === 0 ? [] : [self::indexes($rows)];
                        $source = $table;
                        $table = [];
                        $rows = count($members);
                    }

                    $column = $this->ratio((string) $calc['ratio']['numerator'], (string) $calc['ratio']['denominator'], $source, array_values($members));
                } else {
                    $column = $this->calculate($calc, $table, $rows);
                }

                if ($column === null) {
                    $broken = true;
                } else {
                    $table[Transforms::CALC_PREFIX.$calc['name']] = $column;
                }
            }

            $counts[] = $broken ? 0 : $rows;
        }

        foreach ($sorts as $step) {
            if (! $broken) {
                /** @var list<mixed>|null $values */
                $values = $table[(string) $step['field']] ?? null;
                $order = $values === null ? null : self::sort(self::indexes($rows), $values, (string) $step['direction'], $guards, fn () => $this->checkTime($started, $guards));

                if ($order === null) {
                    $broken = true;
                } else {
                    $table = $take($table, $order);
                }
            }

            $counts[] = $broken ? 0 : $rows;
        }

        $beforeTop = $broken ? [] : $table;

        if (isset($plan[Transforms::TOP_N])) {
            if (! $broken) {
                $rows = min($rows, (int) $plan[Transforms::TOP_N]['count']);
                $table = $take($table, self::indexes($rows));
            }

            $counts[] = $broken ? 0 : $rows;
        }

        $left = [];

        foreach ($paths as $alias => $path) {
            $alias = (string) $alias;

            // A list of another length than the rows (a single value beside them) is not a column and is left as it is; a calculated field is one.
            if (! str_starts_with($path, Transforms::CALC_PREFIX) && count($selected[$alias] ?? []) !== $rowCount) {
                continue;
            }

            $selected[$alias] = $broken ? [] : array_values($table[$path] ?? []);
            $left[$alias] = array_map(
                fn (mixed $node): string|bool|null => match (true) {
                    $node instanceof DecimalLiteral => $node->lexeme,
                    is_string($node), is_bool($node) => $node,
                    default => null,
                },
                $selected[$alias],
            );
        }

        return [$selected, ['input' => $rowCount, 'steps' => $counts], $left, $broken ? [] : $table, $broken ? 0 : $rows, $beforeTop];
    }

    /**
     * A calculated field over the rows of the table: its expression evaluated once per row. Null when a column it reads is not there or a value cannot be
     * read as what the expression uses it as, so the slots that read it are unavailable.
     *
     * @param  array<string, mixed>  $calc  `{name, ast}`
     * @param  array<string, array<mixed>>  $table
     * @return list<mixed>|null
     */
    private function calculate(array $calc, array $table, int $rows): ?array
    {
        if ($this->math === null) {
            throw new SettingNotConfigured('DASHFLOW_DECIMAL_SCALE');
        }

        $ast = $calc['ast'] ?? null;

        if (! is_array($ast)) {
            return null;
        }

        $evaluator = new ExpressionEvaluator($this->math);
        $column = [];

        try {
            for ($i = 0; $i < $rows; $i++) {
                $value = $evaluator->value($ast, function (string $path) use ($table, $i): string|bool|null|ExpressionNumber {
                    if (! isset($table[$path])) {
                        throw new ExpressionTypeError('A field is not in the rows.');
                    }

                    $node = $table[$path][$i] ?? null;

                    return match (true) {
                        $node instanceof DecimalLiteral => new ExpressionNumber($node->lexeme),
                        $node === null, is_string($node), is_bool($node) => $node,
                        default => throw new ExpressionTypeError('A value is not a scalar.'),
                    };
                });

                $column[] = $value instanceof ExpressionNumber ? new DecimalLiteral(ExpressionEvaluator::trimmed($value->value)) : $value;
            }
        } catch (ExpressionTypeError) {
            return null;
        }

        return $column;
    }

    /**
     * A ratio of two measures over each group: the numerator and the denominator are summed apart, then divided, as a percentage. A group with no
     * denominator, or a denominator of 0, has no value.
     *
     * @param  array<string, array<mixed>>  $source  the columns before the rows were grouped
     * @param  list<list<int>>  $members
     * @return list<mixed>|null
     */
    private function ratio(string $numerator, string $denominator, array $source, array $members): ?array
    {
        if ($this->math === null) {
            throw new SettingNotConfigured('DASHFLOW_DECIMAL_SCALE');
        }

        if (! isset($source[$numerator], $source[$denominator])) {
            return null;
        }

        $nums = $this->aggregate('sum', array_values($source[$numerator]), $members);
        $dens = $this->aggregate('sum', array_values($source[$denominator]), $members);

        if ($nums === null || $dens === null) {
            return null;
        }

        $out = [];

        foreach ($nums as $i => $num) {
            $den = $dens[$i];

            if (! $num instanceof DecimalLiteral || ! $den instanceof DecimalLiteral || bccomp(self::plain($den->lexeme), '0', 400) === 0) {
                $out[] = null;

                continue;
            }

            $scaled = $this->math->mul(self::plain($num->lexeme), '100');
            /** @var numeric-string $scaled */
            $out[] = new DecimalLiteral(self::trimmed($this->math->div($scaled, self::plain($den->lexeme))));
        }

        return $out;
    }

    /**
     * A Text Template as its wire form (see {@see Templates}): the rows' own columns when the rows are down to one, else the one value a path selects.
     *
     * @param  list<array<string, mixed>>  $parts
     * @param  array<string, array<mixed>>  $table
     * @param  array{start: string, end: string}|null  $period
     */
    private function render(array $parts, mixed $document, array $table, int $rows, ?array $period, PlanGuards $guards, float $started, int &$evaluations, int &$extracted): string
    {
        $out = '';

        foreach ($parts as $part) {
            switch ($part['t']) {
                case 'lit':
                    $out .= Templates::escape((string) $part['v']);
                    break;
                case 'row_count':
                    $out .= '{#'.$rows.'|'.$part['fmt'].'}';
                    break;
                case 'period':
                    $date = $period === null ? null : $period[$part['end'] ? 'end' : 'start'];
                    $out .= $date === null ? Templates::EMPTY : '{@'.$date.'|'.$part['fmt'].'}';
                    break;
                case 'user':
                    $out .= '{user.'.$part['key'].'}';
                    break;
                default:
                    $path = (string) ($part['path'] ?? '');
                    $node = null;

                    if (isset($table[$path]) && count($table[$path]) === 1) {
                        $node = $table[$path][0];
                    } else {
                        if (++$evaluations > $guards->maxEvaluations) {
                            throw new GuardExceeded(GuardExceeded::EVALUATIONS);
                        }

                        $this->checkTime($started, $guards);
                        $nodes = SamplePath::select($document, $path);
                        $extracted += count($nodes);

                        if ($extracted > $guards->maxExtractedValues) {
                            throw new GuardExceeded(GuardExceeded::EXTRACTED_VALUES);
                        }

                        $node = count($nodes) === 1 ? $nodes[0] : null;
                    }

                    $out .= self::wire($node, $part['fmt'] ?? null);
            }
        }

        return $out;
    }

    /** One value of a template as its wire form: formatted in the browser when it has a format, escaped text otherwise, `{-}` when it is nothing. */
    private static function wire(mixed $node, ?string $format): string
    {
        $numeric = ['number', 'integer', 'currency', 'percent', 'compact'];

        if ($node instanceof DecimalLiteral) {
            return $format !== null && in_array($format, $numeric, true) ? '{#'.$node->lexeme.'|'.$format.'}' : $node->lexeme;
        }

        if (is_bool($node)) {
            return $node ? 'true' : 'false';
        }

        if (is_string($node)) {
            if ($format === 'date' && preg_match('/\A(\d{4}-\d{2}-\d{2})/', $node, $m) === 1) {
                return '{@'.$m[1].'|MMM d, yyyy}';
            }

            if ($format !== null && in_array($format, $numeric, true) && preg_match(ExecutionResult::DECIMAL, trim($node)) === 1) {
                return '{#'.trim($node).'|'.$format.'}';
            }

            return $node === '' ? Templates::EMPTY : Templates::escape($node);
        }

        return Templates::EMPTY;
    }

    /** @return list<int> */
    private static function indexes(int $count): array
    {
        return $count > 0 ? range(0, $count - 1) : [];
    }

    /** What a value is, for grouping: a number by its value (1.10 and 1.1 are one group), text as it is, and an empty value on its own. */
    private static function keyPart(mixed $node): string
    {
        return match (true) {
            $node === null => 'z:',
            $node instanceof DecimalLiteral => 'n:'.(str_contains($plain = self::plain($node->lexeme), '.') ? rtrim(rtrim($plain, '0'), '.') : $plain),
            is_bool($node) => 'b:'.($node ? '1' : '0'),
            is_string($node) => 's:'.$node,
            default => 'o:'.md5(serialize($node)),
        };
    }

    /**
     * One aggregate over a column, per group. COUNT counts the rows of the group; SUM, AVG, MIN and MAX read numbers (an empty value is left out, and a
     * group of nothing but empty values has no value). A value that is not a number, or is the wrong kind, makes the whole column unreadable.
     *
     * @param  list<mixed>  $column
     * @param  list<list<int>>  $members
     * @return list<mixed>|null
     */
    private function aggregate(string $function, array $column, array $members): ?array
    {
        $out = [];

        foreach ($members as $group) {
            if ($function === 'count') {
                $out[] = new DecimalLiteral((string) count($group));

                continue;
            }

            $numbers = [];
            $texts = [];

            foreach ($group as $i) {
                $node = $column[$i];

                if ($node === null) {
                    continue;
                }

                if ($node instanceof DecimalLiteral) {
                    $numbers[] = $node;
                } elseif (is_string($node) && $function !== 'sum' && $function !== 'avg') {
                    $texts[] = $node;
                } else {
                    return null;
                }
            }

            if ($numbers !== [] && $texts !== []) {
                return null;
            }

            if ($numbers === [] && $texts === []) {
                $out[] = null;

                continue;
            }

            if ($function === 'min' || $function === 'max') {
                if ($texts !== []) {
                    $out[] = $function === 'min' ? min($texts) : max($texts);

                    continue;
                }

                $best = $numbers[0];

                foreach ($numbers as $candidate) {
                    $order = self::compare($candidate->lexeme, $best->lexeme);

                    if (($function === 'min' && $order < 0) || ($function === 'max' && $order > 0)) {
                        $best = $candidate;
                    }
                }

                $out[] = $best;

                continue;
            }

            if ($this->math === null) {
                throw new SettingNotConfigured('DASHFLOW_DECIMAL_SCALE');
            }

            $total = null;

            foreach ($numbers as $number) {
                $plain = self::plain($number->lexeme);
                /** @var numeric-string $total */
                $total = $total === null ? $plain : $this->math->add($total, $plain);
            }

            if ($function === 'avg' && $total !== null) {
                $total = $this->math->div($total, (string) count($numbers));
            }

            $out[] = new DecimalLiteral(self::trimmed((string) $total));
        }

        return $out;
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
        return bccomp(self::plain($a), self::plain($b), 400);
    }

    /** A computed decimal without the zeros the internal scale padded it with: `110.000000` is `110`, `0.500000` is `0.5`. */
    private static function trimmed(string $decimal): string
    {
        if (! str_contains($decimal, '.')) {
            return $decimal;
        }

        $trimmed = rtrim(rtrim($decimal, '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }

    /**
     * A lexeme with its exponent written out.
     *
     * @return numeric-string
     */
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

        /** @var numeric-string $plain */
        $plain = ($sign === '-' ? '-' : '').$text;

        return $plain;
    }

    private function checkTime(float $started, PlanGuards $guards): void
    {
        if (($this->clock)() - $started > $guards->maxWallMs) {
            throw new GuardExceeded(GuardExceeded::WALL_TIME);
        }
    }

    /**
     * The value of every row for a `rows` slot (Story 5.8a): each selected node read as a scalar of the slot's type, a node that is not one a null
     * beside the rest (partial data, never 0). Nothing selected, or no node that fits, is the slot unavailable.
     *
     * @param  list<mixed>  $nodes
     * @return array{0: list<string|bool|null>|null, 1: string|null} the values and, when there are none, why
     */
    private static function slotList(array $nodes, string $valueType): array
    {
        if ($nodes === []) {
            return [null, ExecutionResult::MISSING];
        }

        $values = [];
        $reason = null;

        foreach ($nodes as $node) {
            [$value, $why] = self::slotValue([$node], $valueType);
            $values[] = $value;
            $reason ??= $value === null ? $why : null;
        }

        return array_filter($values, fn ($value): bool => $value !== null) === [] ? [null, $reason ?? ExecutionResult::MISSING] : [$values, null];
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
