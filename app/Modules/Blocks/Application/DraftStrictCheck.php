<?php

namespace App\Modules\Blocks\Application;

use App\Modules\Blocks\Contracts\InvalidDraft;
use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\Mapping\Contracts\Presentation;
use App\Modules\Mapping\Contracts\QueryPlan;

/**
 * The strict-schema check of a Draft before it is validated against the real API (Story 3.18). The Draft saves leniently; this is the check that
 * everything it holds is complete and well formed: the plan passes its type check ({@see QueryPlan::validate()}), every required Slot is mapped,
 * and the configuration and the presentation rules are valid. It reads the stored config only and calls nothing, so a Draft that fails it is
 * reported without any request to the API. Each fault is value-free: `{path, slot, type, expected}`.
 */
final class DraftStrictCheck
{
    /**
     * @param  array<string, mixed>  $config  the Draft's stored `config`
     * @return list<array{path: string|null, slot: string|null, type: string, expected: string|null}>
     */
    public static function check(array $config, BlockType $type): array
    {
        $faults = [];
        $plan = $config['query_plan'] ?? null;

        if (! is_array($plan) || $plan === []) {
            $faults[] = self::fault('config.query_plan', null, 'no_plan');
        } else {
            foreach (array_keys(QueryPlan::validate($plan, $type)) as $path) {
                $faults[] = self::fault((string) $path, null, 'invalid_plan');
            }
        }

        $shaped = is_array($plan['shape'] ?? null) ? $plan['shape'] : [];

        foreach ($type->slots as $slot) {
            if ($slot->required && ! isset($shaped[$slot->key])) {
                $faults[] = self::fault(null, $slot->key, 'required_slot_unmapped', $slot->valueType);
            }
        }

        $presentation = $config[Presentation::KEY] ?? null;

        if (is_array($presentation) && $presentation !== []) {
            $errors = [];
            Presentation::check($presentation, $type, $errors);

            foreach (array_keys($errors) as $path) {
                $faults[] = self::fault((string) $path, null, 'invalid_presentation');
            }
        }

        $own = array_intersect_key($config, array_flip(['description', 'icon', 'display']));

        try {
            DraftConfig::normalise($own, $type);
        } catch (InvalidDraft $e) {
            foreach (array_keys($e->errors) as $path) {
                $faults[] = self::fault((string) $path, null, 'invalid_config');
            }
        }

        return $faults;
    }

    /** @return array{path: string|null, slot: string|null, type: string, expected: string|null} */
    private static function fault(?string $path, ?string $slot, string $type, ?string $expected = null): array
    {
        return ['path' => $path === null ? null : mb_substr($path, 0, 200), 'slot' => $slot, 'type' => $type, 'expected' => $expected];
    }
}
