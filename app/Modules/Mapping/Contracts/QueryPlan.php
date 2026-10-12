<?php

namespace App\Modules\Mapping\Contracts;

use App\Modules\BlockTypes\Contracts\BlockType;
use App\Platform\Json\SamplePath;
use InvalidArgumentException;

/**
 * Query Plan version 1 (AD-11): `{"v":1,"extract":{"fields":{"<alias>":"<stored path>"}},"shape":{"<slot key>":"<alias>"}}`, with the row stages
 * `filter` (a list of `{field, operator, value}`), `sort` (a list of `{field, direction}`) and `top_n` (`{count}`) of Story 5.1 between them. The stage
 * order is fixed (extract, filter, row calc, group, aggregate, aggregate calc, sort, top-N, shape) and plan `v` 1 runs forever; `extract`, `filter`,
 * `sort`, `top_n` and `shape` exist so far. A stored path is the RFC 9535 subset {@see SamplePath} reads. This class only judges the document: it never runs it.
 */
final class QueryPlan
{
    public const VERSION = 1;

    /** The stages of every plan, in the order they run. */
    public const STAGE_ORDER = ['extract', 'filter', 'row_calc', 'group', 'aggregate', 'aggregate_calc', 'sort', 'top_n', 'shape'];

    /** The stages plan v1 carries. */
    public const STAGES_V1 = ['extract', 'filter', 'sort', 'top_n', 'shape'];

    private const ALIAS = '/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D';

    /**
     * Every fault of the plan, keyed by the field the Admin sees (`config.query_plan.v`, `config.query_plan.extract.fields.{alias}`, ...). An empty
     * list means the plan is valid for the Block Type.
     *
     * @return array<string, list<string>>
     */
    public static function validate(mixed $plan, BlockType $type): array
    {
        $root = 'config.query_plan';

        if (! is_array($plan) || ($plan !== [] && array_is_list($plan))) {
            return [$root => ['The query plan must be an object.']];
        }

        $errors = [];

        if (! array_key_exists('v', $plan) || $plan['v'] !== self::VERSION) {
            $errors["{$root}.v"] = ['This query plan version is not supported.'];

            return $errors;
        }

        foreach (array_keys($plan) as $key) {
            if (! in_array($key, ['v', ...self::STAGES_V1], true)) {
                $errors["{$root}.{$key}"] = ['This stage is not part of query plan version 1.'];
            }
        }

        $fields = [];
        $extract = $plan['extract'] ?? null;

        if (! is_array($extract) || ($extract !== [] && array_is_list($extract))) {
            $errors["{$root}.extract"] = ['Extract must be an object with fields.'];
        } else {
            $raw = $extract['fields'] ?? null;

            if (! is_array($raw) || ($raw !== [] && array_is_list($raw))) {
                $errors["{$root}.extract.fields"] = ['Fields must map an alias to a path.'];
            } else {
                foreach ($raw as $alias => $path) {
                    $alias = (string) $alias;

                    if (preg_match(self::ALIAS, $alias) !== 1) {
                        $errors["{$root}.extract.fields"][] = 'An alias starts with a letter or underscore and holds letters, digits and underscores.';

                        continue;
                    }

                    try {
                        if (! is_string($path)) {
                            throw new InvalidArgumentException('A path is text.');
                        }

                        SamplePath::parse($path);
                        $fields[$alias] = true;
                    } catch (InvalidArgumentException) {
                        $errors["{$root}.extract.fields.{$alias}"] = ['Use a stored path: $ then member names and [*] only.'];
                    }
                }
            }
        }

        $errors += Transforms::validateStages($plan, $root);

        $slots = [];

        foreach ($type->slots as $slot) {
            $slots[$slot->key] = true;
        }

        $shape = $plan['shape'] ?? null;

        if (! is_array($shape) || ($shape !== [] && array_is_list($shape))) {
            $errors["{$root}.shape"] = ['Shape must map a slot to an alias.'];
        } else {
            foreach ($shape as $slot => $alias) {
                $slot = (string) $slot;

                if (! isset($slots[$slot])) {
                    $errors["{$root}.shape.{$slot}"] = ['This slot is not part of the '.$type->title.'.'];
                } elseif (! is_string($alias) || ! isset($fields[$alias])) {
                    $errors["{$root}.shape.{$slot}"] = ['Map this slot to an extracted field.'];
                }
            }
        }

        return $errors;
    }
}
