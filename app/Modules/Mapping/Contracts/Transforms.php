<?php

namespace App\Modules\Mapping\Contracts;

use App\Platform\Json\SamplePath;
use InvalidArgumentException;

/**
 * The row transforms of a Draft (Story 5.1 and 5.2, AD-11): the Admin's Filter, Group, Aggregate, Sort and Top-N steps, stored as `config.transforms` (a
 * list of steps, server-owned like the mapping keys) and carried into Query Plan v1 as the `filter`, `group`, `aggregate`, `sort` and `top_n` stages. The
 * stage order is fixed (filter, group, aggregate, sort, top-N) whatever order steps were added in, and no step holds a script or an expression: a field
 * is a stored path, an operator or function is one of a closed list and a value is text.
 *
 * Step: `{type: filter, field, operator, value}`, `{type: group, fields: [path]}`, `{type: aggregate, function, field}`, `{type: sort, field, direction}`
 * or `{type: top_n, count}`. A `field` is a stored path of the response. After a group or aggregate, only the grouped and aggregated fields are columns.
 */
final class Transforms
{
    public const KEY = 'transforms';

    public const FILTER = 'filter';

    public const CALC = 'calc';

    public const ROW_CALC = 'row_calc';

    public const AGGREGATE_CALC = 'aggregate_calc';

    public const GROUP = 'group';

    public const AGGREGATE = 'aggregate';

    public const SORT = 'sort';

    public const TOP_N = 'top_n';

    /** The comparison of a filter: equals, differs, contains (text) and the four orderings (numbers). */
    public const OPERATORS = ['is', 'is_not', 'contains', 'gt', 'gte', 'lt', 'lte'];

    /** The operators that read both sides as numbers. */
    public const NUMERIC_OPERATORS = ['gt', 'gte', 'lt', 'lte'];

    public const DIRECTIONS = ['asc', 'desc'];

    /** The aggregate functions: exactly these five. */
    public const FUNCTIONS = ['sum', 'count', 'avg', 'min', 'max'];

    /** The functions that read numbers. */
    public const NUMERIC_FUNCTIONS = ['sum', 'avg'];

    public const MAX_GROUP_FIELDS = 3;

    public const MAX_AGGREGATES = 5;

    public const MAX_CALCS = 5;

    public const LEVELS = ['row', 'aggregate'];

    /** The name of a calculated field: usable bare in an expression. */
    public const CALC_NAME = '/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D';

    /** The prefix of the stored path a calculated field is read by. */
    public const CALC_PREFIX = 'calc:';

    public const MAX_FILTERS = 5;

    public const MAX_SORTS = 3;

    public const MAX_TOP_N = 1000;

    /** The longest label of the row that holds the rest (Story 5.11). */
    public const OTHER_LABEL_MAX = 40;

    public const VALUE_MAX = 200;

    /**
     * The steps of a config, judged whole: every fault is keyed `config.transforms.{index}.{part}`. Steps come back in the fixed stage order (filters, then
     * sorts, then the one Top-N), each stage keeping the order the steps were added in.
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, list<string>>}
     */
    public static function check(mixed $input): array
    {
        $root = 'config.'.self::KEY;

        if ($input === null || $input === []) {
            return [[], []];
        }

        if (! is_array($input) || ! array_is_list($input)) {
            return [[], [$root => ['Transforms are a list of steps.']]];
        }

        $errors = [];
        $steps = [];
        $counts = [self::FILTER => 0, self::CALC => 0, self::GROUP => 0, self::AGGREGATE => 0, self::SORT => 0, self::TOP_N => 0];

        foreach ($input as $index => $step) {
            $at = "{$root}.{$index}";

            if (! is_array($step) || ! isset($step['type']) || ! is_string($step['type']) || ! isset($counts[$step['type']])) {
                $errors[$at] = ['Choose Filter, Calculated field, Group, Aggregate, Sort or Top N.'];

                continue;
            }

            $type = $step['type'];

            if (++$counts[$type] > ($limit = self::limit($type))) {
                $errors[$at] = ['Use at most '.$limit.' '.($limit === 1 ? 'step' : 'steps').' of this kind.'];

                continue;
            }

            $allowed = match ($type) {
                self::FILTER => ['type', 'field', 'operator', 'value'],
                self::CALC => ['type', 'name', 'level', 'source', 'ratio', 'ast'],
                self::GROUP => ['type', 'fields'],
                self::AGGREGATE => ['type', 'function', 'field'],
                self::SORT => ['type', 'field', 'direction'],
                default => ['type', 'count', 'other', 'other_label'],
            };

            foreach (array_keys($step) as $key) {
                if (! in_array($key, $allowed, true)) {
                    $errors["{$at}.{$key}"] = ['This setting is not part of a '.$type.' step.'];
                }
            }

            if (in_array($type, [self::FILTER, self::SORT, self::AGGREGATE], true) && ! self::isPath($step['field'] ?? null)) {
                $errors["{$at}.field"] = ['Choose a field of the response.'];
            }

            if ($type === self::CALC) {
                $errors += self::checkCalc($step, $at);
            }

            if ($type === self::GROUP) {
                $fields = $step['fields'] ?? null;

                if (! is_array($fields) || ! array_is_list($fields) || $fields === [] || count($fields) > self::MAX_GROUP_FIELDS) {
                    $errors["{$at}.fields"] = ['Choose 1 to '.self::MAX_GROUP_FIELDS.' fields to group by.'];
                } else {
                    foreach ($fields as $position => $field) {
                        if (! self::isPath($field)) {
                            $errors["{$at}.fields.{$position}"] = ['Choose a field of the response.'];
                        }
                    }

                    if (count(array_unique(array_map('strval', $fields))) !== count($fields)) {
                        $errors["{$at}.fields"] = ['Choose each field once.'];
                    }
                }
            }

            if ($type === self::AGGREGATE && (! is_string($step['function'] ?? null) || ! in_array($step['function'], self::FUNCTIONS, true))) {
                $errors["{$at}.function"] = ['Choose SUM, COUNT, AVG, MIN or MAX.'];
            }

            if ($type === self::FILTER) {
                if (! is_string($step['operator'] ?? null) || ! in_array($step['operator'], self::OPERATORS, true)) {
                    $errors["{$at}.operator"] = ['Choose how to compare.'];
                }

                $value = $step['value'] ?? null;

                if (! is_string($value) || mb_strlen($value) > self::VALUE_MAX || preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 1) {
                    $errors["{$at}.value"] = ['Enter a value of up to '.self::VALUE_MAX.' characters, with no control characters.'];
                }
            }

            if ($type === self::SORT && (! is_string($step['direction'] ?? null) || ! in_array($step['direction'], self::DIRECTIONS, true))) {
                $errors["{$at}.direction"] = ['Choose ascending or descending.'];
            }

            if ($type === self::TOP_N) {
                $count = $step['count'] ?? null;

                if (! is_int($count) || $count < 1 || $count > self::MAX_TOP_N) {
                    $errors["{$at}.count"] = ['Enter a whole number from 1 to '.self::MAX_TOP_N.'.'];
                }

                // "Group the rest as Other" (Story 5.11): the groups beyond the count become one row, aggregated again over their rows.
                if (isset($step['other']) && ! is_bool($step['other'])) {
                    $errors["{$at}.other"] = ['Group the rest as Other is on or off.'];
                }

                if (isset($step['other_label']) && (! is_string($step['other_label']) || trim($step['other_label']) === '' || mb_strlen($step['other_label']) > self::OTHER_LABEL_MAX || preg_match('/[\p{Cc}\p{Cf}]/u', $step['other_label']) === 1)) {
                    $errors["{$at}.other_label"] = ['Enter a label of up to '.self::OTHER_LABEL_MAX.' characters, with no control characters.'];
                }
            }

            $steps[] = $step;
        }

        if ($errors === []) {
            $errors = self::crossCheck($steps, $root, $input);
        }

        if ($errors !== []) {
            return [[], $errors];
        }

        // The stage order of AD-11: filter, row calc, group, aggregate, aggregate calc, sort, top-N.
        $rank = fn (array $step): int => match ($step['type']) {
            self::FILTER => 0,
            self::CALC => $step['level'] === 'row' ? 1 : 4,
            self::GROUP => 2,
            self::AGGREGATE => 3,
            self::SORT => 5,
            default => 6,
        };
        $indexed = array_map(null, array_keys($steps), $steps);
        usort($indexed, fn (array $a, array $b): int => [$rank($a[1]), $a[0]] <=> [$rank($b[1]), $b[0]]);

        return [array_map(fn (array $pair): array => $pair[1], $indexed), []];
    }

    /**
     * What steps say of each other: a field is grouped or aggregated, not both, and aggregated once; and once rows are grouped or aggregated a Sort can
     * only use a field that is left.
     *
     * @param  list<array<string, mixed>>  $steps
     * @param  list<mixed>  $input  the steps as given, to name a fault by the position it was sent in
     * @return array<string, list<string>>
     */
    private static function crossCheck(array $steps, string $root, array $input): array
    {
        $errors = [];
        $grouped = [];
        $aggregated = [];

        foreach ($steps as $step) {
            if ($step['type'] === self::GROUP) {
                $grouped = array_map('strval', $step['fields']);
            }
        }

        $at = fn (array $step): int => (int) array_search($step, $input, true);

        // The rest can only be grouped as Other when there are groups to group (Story 5.11).
        foreach ($steps as $step) {
            if ($step['type'] === self::TOP_N && ($step['other'] ?? false) === true && $grouped === []) {
                $errors[$root.'.'.$at($step).'.other'] = ['Group the rest as Other needs a Group step.'];
            }
        }

        foreach ($steps as $step) {
            if ($step['type'] !== self::AGGREGATE) {
                continue;
            }

            $field = (string) $step['field'];

            if (in_array($field, $grouped, true)) {
                $errors[$root.'.'.$at($step).'.field'] = ['A grouped field cannot also be aggregated.'];
            } elseif (isset($aggregated[$field])) {
                $errors[$root.'.'.$at($step).'.field'] = ['Aggregate each field once.'];
            }

            $aggregated[$field] = true;
        }

        if ($grouped !== [] || $aggregated !== []) {
            $left = [...$grouped, ...array_keys($aggregated)];

            foreach ($steps as $step) {
                if ($step['type'] === self::CALC) {
                    $left[] = self::CALC_PREFIX.$step['name'];
                }
            }

            foreach ($steps as $step) {
                if ($step['type'] === self::SORT && ! in_array((string) $step['field'], $left, true)) {
                    $errors[$root.'.'.$at($step).'.field'] = ['Sort by a field that is grouped or aggregated.'];
                }
            }
        }

        return $errors;
    }

    /**
     * The plan with the steps as its stages. Without steps the plan is returned as it is.
     *
     * @param  array<string, mixed>  $plan
     * @param  list<array<string, mixed>>  $steps  as {@see self::check()} returned them
     * @return array<string, mixed>
     */
    public static function apply(array $plan, array $steps): array
    {
        foreach ([self::FILTER, self::ROW_CALC, self::GROUP, self::AGGREGATE, self::AGGREGATE_CALC, self::SORT, self::TOP_N] as $stage) {
            unset($plan[$stage]);
        }

        $filters = [];
        $rowCalcs = [];
        $aggregateCalcs = [];
        $aggregates = [];
        $sorts = [];

        foreach ($steps as $step) {
            match ($step['type']) {
                self::FILTER => $filters[] = ['field' => $step['field'], 'operator' => $step['operator'], 'value' => $step['value']],
                self::CALC => $step['level'] === 'row'
                    ? $rowCalcs[] = ['name' => $step['name'], 'source' => $step['source'], 'ast' => $step['ast'] ?? null]
                    : $aggregateCalcs[] = isset($step['ratio'])
                        ? ['name' => $step['name'], 'ratio' => $step['ratio']]
                        : ['name' => $step['name'], 'source' => $step['source'], 'ast' => $step['ast'] ?? null],
                self::GROUP => $plan[self::GROUP] = ['fields' => array_values($step['fields'])],
                self::AGGREGATE => $aggregates[] = ['function' => $step['function'], 'field' => $step['field']],
                self::SORT => $sorts[] = ['field' => $step['field'], 'direction' => $step['direction']],
                default => $plan[self::TOP_N] = ['count' => $step['count']]
                    + (($step['other'] ?? false) === true ? ['other' => true, 'other_label' => is_string($step['other_label'] ?? null) ? trim($step['other_label']) : 'Other'] : []),
            };
        }

        if ($filters !== []) {
            $plan[self::FILTER] = $filters;
        }

        if ($rowCalcs !== []) {
            $plan[self::ROW_CALC] = $rowCalcs;
        }

        if ($aggregates !== []) {
            $plan[self::AGGREGATE] = $aggregates;
        }

        if ($aggregateCalcs !== []) {
            $plan[self::AGGREGATE_CALC] = $aggregateCalcs;
        }

        if ($sorts !== []) {
            $plan[self::SORT] = $sorts;
        }

        return $plan;
    }

    /**
     * The steps a plan was made from, in the fixed stage order (the inverse of {@see self::apply()}), so that a published version's transforms can be
     * edited again (Story 6.1): `apply(steps(plan))` is the plan.
     *
     * @param  array<string, mixed>  $plan
     * @return list<array<string, mixed>>
     */
    public static function steps(array $plan): array
    {
        $steps = [];

        foreach ((array) ($plan[self::FILTER] ?? []) as $filter) {
            $steps[] = ['type' => self::FILTER, 'field' => $filter['field'] ?? null, 'operator' => $filter['operator'] ?? null, 'value' => $filter['value'] ?? null];
        }

        foreach ((array) ($plan[self::ROW_CALC] ?? []) as $calc) {
            $steps[] = ['type' => self::CALC, 'name' => $calc['name'] ?? null, 'level' => 'row', 'source' => $calc['source'] ?? null];
        }

        if (is_array($plan[self::GROUP] ?? null)) {
            $steps[] = ['type' => self::GROUP, 'fields' => array_values((array) ($plan[self::GROUP]['fields'] ?? []))];
        }

        foreach ((array) ($plan[self::AGGREGATE] ?? []) as $aggregate) {
            $steps[] = ['type' => self::AGGREGATE, 'function' => $aggregate['function'] ?? null, 'field' => $aggregate['field'] ?? null];
        }

        foreach ((array) ($plan[self::AGGREGATE_CALC] ?? []) as $calc) {
            $steps[] = isset($calc['ratio'])
                ? ['type' => self::CALC, 'name' => $calc['name'] ?? null, 'level' => 'aggregate', 'ratio' => $calc['ratio']]
                : ['type' => self::CALC, 'name' => $calc['name'] ?? null, 'level' => 'aggregate', 'source' => $calc['source'] ?? null];
        }

        foreach ((array) ($plan[self::SORT] ?? []) as $sort) {
            $steps[] = ['type' => self::SORT, 'field' => $sort['field'] ?? null, 'direction' => $sort['direction'] ?? null];
        }

        if (is_array($plan[self::TOP_N] ?? null)) {
            $top = $plan[self::TOP_N];
            $steps[] = ['type' => self::TOP_N, 'count' => $top['count'] ?? null]
                + (($top['other'] ?? false) === true ? ['other' => true, 'other_label' => $top['other_label'] ?? 'Other'] : []);
        }

        return $steps;
    }

    /**
     * The stages of a plan, judged for shape: used by {@see QueryPlan::validate()}. A fault is keyed `{root}.{stage}.{n}.{part}` for a list stage and
     * `{root}.{stage}.{part}` for `group` and `top_n`.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, list<string>>
     */
    public static function validateStages(array $plan, string $root): array
    {
        $errors = [];
        $steps = [];

        foreach ([self::FILTER, self::AGGREGATE, self::SORT] as $stage) {
            if (! isset($plan[$stage])) {
                continue;
            }

            if (! is_array($plan[$stage]) || ! array_is_list($plan[$stage])) {
                $errors["{$root}.{$stage}"] = ['This stage is a list of steps.'];

                continue;
            }

            foreach ($plan[$stage] as $index => $step) {
                $judged = is_array($step) ? ['type' => $stage] + $step : $step;

                foreach (self::check([$judged])[1] as $key => $messages) {
                    $errors[str_replace('config.'.self::KEY.'.0', "{$root}.{$stage}.{$index}", $key)] = $messages;
                }

                if (is_array($judged)) {
                    $steps[] = $judged;
                }
            }
        }

        foreach ([self::ROW_CALC => 'row', self::AGGREGATE_CALC => 'aggregate'] as $stage => $level) {
            if (! isset($plan[$stage])) {
                continue;
            }

            if (! is_array($plan[$stage]) || ! array_is_list($plan[$stage])) {
                $errors["{$root}.{$stage}"] = ['This stage is a list of steps.'];

                continue;
            }

            foreach ($plan[$stage] as $index => $item) {
                $judged = is_array($item) ? ['type' => self::CALC, 'level' => $level] + $item : $item;

                foreach (self::check([$judged])[1] as $key => $messages) {
                    $errors[str_replace('config.'.self::KEY.'.0', "{$root}.{$stage}.{$index}", $key)] = $messages;
                }

                if (is_array($item) && ! isset($item['ratio']) && ! is_array($item['ast'] ?? null)) {
                    $errors["{$root}.{$stage}.{$index}.ast"] = ['The expression is not parsed.'];
                }

                // A calculated field is a field that is left, so a Sort may use it.
                if (is_array($judged)) {
                    $steps[] = $judged;
                }
            }
        }

        foreach ([self::GROUP, self::TOP_N] as $stage) {
            if (! isset($plan[$stage])) {
                continue;
            }

            $judged = is_array($plan[$stage]) ? ['type' => $stage] + $plan[$stage] : $plan[$stage];

            // "Group the rest as Other" is judged against the group stage below, not against a Top-N judged alone.
            $alone = is_array($judged) ? array_diff_key($judged, ['other' => true]) : $judged;

            foreach (self::check([$alone])[1] as $key => $messages) {
                $errors[str_replace('config.'.self::KEY.'.0', "{$root}.{$stage}", $key)] = $messages;
            }

            if (is_array($judged)) {
                $steps[] = $judged;
            }
        }

        if ($errors === []) {
            foreach (self::crossCheck($steps, "{$root}.stages", $steps) as $key => $messages) {
                $errors[$root.'.'.$steps[(int) substr($key, strlen("{$root}.stages."))]['type']] = $messages;
            }
        }

        return $errors;
    }

    /**
     * The shape of a calculated field: a name, a level, and either the source of an expression or (at the aggregate level) a ratio's numerator and
     * denominator. The expression itself is judged where the fields of the sample are known.
     *
     * @param  array<string, mixed>  $step
     * @return array<string, list<string>>
     */
    private static function checkCalc(array $step, string $at): array
    {
        $errors = [];
        $name = $step['name'] ?? null;
        $level = $step['level'] ?? null;
        $source = $step['source'] ?? null;
        $ratio = $step['ratio'] ?? null;

        if (! is_string($name) || preg_match(self::CALC_NAME, $name) !== 1) {
            $errors["{$at}.name"] = ['Name it with letters, digits and underscores, starting with a letter.'];
        }

        if (! is_string($level) || ! in_array($level, self::LEVELS, true)) {
            $errors["{$at}.level"] = ['Choose row level or aggregate level.'];
        }

        if ($ratio !== null) {
            if ($level !== 'aggregate') {
                $errors["{$at}.ratio"] = ['A ratio is calculated after the rows are aggregated.'];
            } elseif (! is_array($ratio) || array_diff(array_keys($ratio), ['numerator', 'denominator']) !== [] || ! is_string($ratio['numerator'] ?? null) || trim($ratio['numerator']) === '' || ! is_string($ratio['denominator'] ?? null) || trim($ratio['denominator']) === '') {
                $errors["{$at}.ratio"] = ['Name both a numerator and a denominator.'];
            } elseif ($source !== null && $source !== '') {
                $errors["{$at}.source"] = ['A ratio takes its numerator and denominator, not an expression.'];
            }
        } elseif (! is_string($source) || trim($source) === '') {
            $errors["{$at}.source"] = ['Enter an expression.'];
        } elseif (mb_strlen($source) > 500) {
            $errors["{$at}.source"] = ['Use 500 characters or fewer.'];
        }

        return $errors;
    }

    private static function limit(string $type): int
    {
        return match ($type) {
            self::FILTER => self::MAX_FILTERS,
            self::AGGREGATE => self::MAX_AGGREGATES,
            self::CALC => self::MAX_CALCS,
            self::SORT => self::MAX_SORTS,
            default => 1,
        };
    }

    private static function isPath(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        // A calculated field is read by `calc:<name>`.
        if (str_starts_with($value, self::CALC_PREFIX)) {
            return preg_match(self::CALC_NAME, substr($value, strlen(self::CALC_PREFIX))) === 1;
        }

        try {
            return SamplePath::parse($value) !== [];
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
