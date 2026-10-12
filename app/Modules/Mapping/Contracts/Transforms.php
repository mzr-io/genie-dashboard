<?php

namespace App\Modules\Mapping\Contracts;

use App\Platform\Json\SamplePath;
use InvalidArgumentException;

/**
 * The row transforms of a Draft (Story 5.1, AD-11): the Admin's Filter, Sort and Top-N steps, stored as `config.transforms` (a list of steps, server-owned
 * like the mapping keys) and carried into Query Plan v1 as the `filter`, `sort` and `top_n` stages. The stage order is fixed (filter, sort, top-N) whatever
 * order steps were added in, and no step holds a script or an expression: a field is a stored path, an operator is one of a closed list and a value is text.
 *
 * Step: `{type: filter, field, operator, value}`, `{type: sort, field, direction}` or `{type: top_n, count}`. A `field` is a stored path of the response.
 */
final class Transforms
{
    public const KEY = 'transforms';

    public const FILTER = 'filter';

    public const SORT = 'sort';

    public const TOP_N = 'top_n';

    /** The comparison of a filter: equals, differs, contains (text) and the four orderings (numbers). */
    public const OPERATORS = ['is', 'is_not', 'contains', 'gt', 'gte', 'lt', 'lte'];

    /** The operators that read both sides as numbers. */
    public const NUMERIC_OPERATORS = ['gt', 'gte', 'lt', 'lte'];

    public const DIRECTIONS = ['asc', 'desc'];

    public const MAX_FILTERS = 5;

    public const MAX_SORTS = 3;

    public const MAX_TOP_N = 1000;

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
        $counts = [self::FILTER => 0, self::SORT => 0, self::TOP_N => 0];

        foreach ($input as $index => $step) {
            $at = "{$root}.{$index}";

            if (! is_array($step) || ! isset($step['type']) || ! is_string($step['type']) || ! isset($counts[$step['type']])) {
                $errors[$at] = ['Choose Filter, Sort or Top N.'];

                continue;
            }

            $type = $step['type'];

            if (++$counts[$type] > ($limit = self::limit($type))) {
                $errors[$at] = ['Use at most '.$limit.' '.($limit === 1 ? 'step' : 'steps').' of this kind.'];

                continue;
            }

            $allowed = match ($type) {
                self::FILTER => ['type', 'field', 'operator', 'value'],
                self::SORT => ['type', 'field', 'direction'],
                default => ['type', 'count'],
            };

            foreach (array_keys($step) as $key) {
                if (! in_array($key, $allowed, true)) {
                    $errors["{$at}.{$key}"] = ['This setting is not part of a '.$type.' step.'];
                }
            }

            if ($type !== self::TOP_N && ! self::isPath($step['field'] ?? null)) {
                $errors["{$at}.field"] = ['Choose a field of the response.'];
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
            }

            $steps[] = $step;
        }

        if ($errors !== []) {
            return [[], $errors];
        }

        $order = [self::FILTER => 0, self::SORT => 1, self::TOP_N => 2];
        $indexed = array_map(null, array_keys($steps), $steps);
        usort($indexed, fn (array $a, array $b): int => [$order[$a[1]['type']], $a[0]] <=> [$order[$b[1]['type']], $b[0]]);

        return [array_map(fn (array $pair): array => $pair[1], $indexed), []];
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
        foreach ([self::FILTER, self::SORT, self::TOP_N] as $stage) {
            unset($plan[$stage]);
        }

        $filters = [];
        $sorts = [];

        foreach ($steps as $step) {
            match ($step['type']) {
                self::FILTER => $filters[] = ['field' => $step['field'], 'operator' => $step['operator'], 'value' => $step['value']],
                self::SORT => $sorts[] = ['field' => $step['field'], 'direction' => $step['direction']],
                default => $plan[self::TOP_N] = ['count' => $step['count']],
            };
        }

        if ($filters !== []) {
            $plan[self::FILTER] = $filters;
        }

        if ($sorts !== []) {
            $plan[self::SORT] = $sorts;
        }

        return $plan;
    }

    /**
     * The stages of a plan, judged for shape: used by {@see QueryPlan::validate()}. A fault is keyed `{root}.filter.{n}.{part}`, `{root}.sort.{n}.{part}`
     * or `{root}.top_n.{part}`.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, list<string>>
     */
    public static function validateStages(array $plan, string $root): array
    {
        $errors = [];

        foreach ([self::FILTER, self::SORT] as $stage) {
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
            }
        }

        if (isset($plan[self::TOP_N])) {
            $judged = is_array($plan[self::TOP_N]) ? ['type' => self::TOP_N] + $plan[self::TOP_N] : $plan[self::TOP_N];

            foreach (self::check([$judged])[1] as $key => $messages) {
                $errors[str_replace('config.'.self::KEY.'.0', "{$root}.".self::TOP_N, $key)] = $messages;
            }
        }

        return $errors;
    }

    private static function limit(string $type): int
    {
        return match ($type) {
            self::FILTER => self::MAX_FILTERS,
            self::SORT => self::MAX_SORTS,
            default => 1,
        };
    }

    private static function isPath(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        try {
            return SamplePath::parse($value) !== [];
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
