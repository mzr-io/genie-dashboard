<?php

namespace App\Modules\Blocks\Application;

use App\Modules\Blocks\Contracts\InvalidDraft;
use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\Mapping\Contracts\FieldRole;
use App\Modules\Mapping\Contracts\Presentation;
use App\Modules\Mapping\Contracts\QueryPlan;
use App\Platform\Json\DecimalLiteral;
use App\Platform\Json\JsonObject;
use App\Platform\Json\LosslessJson;
use App\Platform\Json\SamplePath;

/**
 * The lenient `config` document of a Draft (Story 3.4, `schema_version` 1): the Configure Block step's fields. Nothing is required (a
 * Draft saves as it is); what is present must have the right type, and sizes must lie inside the Block Type's registry limits, which
 * are named in the error. The Query Plan (`query_plan`, Story 3.10) is judged by Mapping's {@see QueryPlan}. The mapping choices `record_path` (a stored path ending in `[*]`, or null to clear) and `field_roles` (stored path => role) are judged by {@see self::normaliseMapping()}; since Story 3.13c they are server-owned (written only through the mapping endpoints) and a save that carries one is refused, as are `field_types` (Story 3.13a: stored path => `text|number|boolean|date`, "Treat as") and `slot_map` (Story 3.12a: slot key => `{path, state}`, written by the mapping endpoints and kept over a save that omits it). Unknown keys are dropped. The "Footer action" slot is derived: it is present, optional and empty exactly when
 * "Show footer action" is on. Stored and read through the platform's JSON codec, never `json_decode`.
 */
final class DraftConfig
{
    public const SCHEMA_VERSION = 1;

    public const HEIGHTS = ['small', 'medium', 'large'];

    public const PERIODS = ['follow_dashboard', 'own_selector', 'not_date_filtered'];

    public const DESCRIPTION_MAX = 500;

    public const FIELD_TYPES = ['text', 'number', 'boolean', 'date'];

    /** Written only through the mapping endpoints. */
    public const MAPPING_KEYS = ['record_path', 'field_roles', 'field_types', 'slot_map', Presentation::KEY];

    private const FLAGS = ['show_header', 'show_subtitle', 'allow_resize', 'allow_minimize', 'allow_refresh', 'show_footer_action'];

    /**
     * @param  array<mixed>  $input
     * @return array<string, mixed> the normalised document
     *
     * @throws InvalidDraft
     */
    public static function normalise(array $input, BlockType $type): array
    {
        $errors = [];
        $out = ['schema_version' => self::SCHEMA_VERSION];

        if (array_key_exists('schema_version', $input) && $input['schema_version'] !== self::SCHEMA_VERSION) {
            $errors['config.schema_version'] = ['This draft format is not supported.'];
        }

        if (isset($input['description'])) {
            $description = $input['description'];

            if (! is_string($description) || mb_strlen(trim($description)) > self::DESCRIPTION_MAX || preg_match('/[\p{Cc}\p{Cf}]/u', str_replace(["\r", "\n", "\t"], ' ', $description)) === 1) {
                $errors['config.description'] = ['Use up to '.self::DESCRIPTION_MAX.' characters, with no control characters.'];
            } else {
                $out['description'] = trim($description);
            }
        }

        if (isset($input['icon'])) {
            if (is_string($input['icon']) && preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $input['icon']) === 1 && strlen($input['icon']) <= 48) {
                $out['icon'] = $input['icon'];
            } else {
                $errors['config.icon'] = ['Choose an icon from the list.'];
            }
        }

        if (isset($input['display'])) {
            if (! is_array($input['display'])) {
                $errors['config.display'] = ['Display options must be an object.'];
            } else {
                $display = self::display($input['display'], $type, $errors);

                $out['display'] = $display;

                if (($display['show_footer_action'] ?? false) === true) {
                    $out['slots'] = ['footer_action' => ['required' => false, 'mapping' => null]];
                }
            }
        }

        // The Query Plan (Story 3.10): version 1 only, its aliases, slot keys and stored paths judged here, so a plan the engine would refuse is never saved.
        if (isset($input['query_plan'])) {
            $faults = QueryPlan::validate($input['query_plan'], $type);

            if ($faults === []) {
                $out['query_plan'] = self::queryPlan($input['query_plan']);
            } else {
                $errors += $faults;
            }
        }

        // The mapping keys are the mapping endpoints' (Story 3.13c): a save that carries one is refused, and a save that omits them keeps the stored ones.
        foreach (self::MAPPING_KEYS as $key) {
            if (array_key_exists($key, $input)) {
                $errors["config.{$key}"] = [$key === Presentation::KEY ? 'Set this through PUT /api/v1/admin/blocks/drafts/{draft}/mapping/presentation.' : 'Set this through the mapping endpoints.'];
            }
        }

        if ($errors !== []) {
            throw new InvalidDraft($errors, 'blocks.invalid_config');
        }

        return $out;
    }

    /**
     * The mapping keys of a config as the mapping endpoints write them (Story 3.13c): `record_path`, `field_roles`, `field_types` and `slot_map`, judged by the
     * same rules a save of them was before they became server-owned. Only the keys present are returned.
     *
     * @param  array<mixed>  $input
     * @return array<string, mixed>
     *
     * @throws InvalidDraft
     */
    public static function normaliseMapping(array $input, BlockType $type): array
    {
        $errors = [];
        $out = [];

        // The Admin's choices for mapping (Story 3.11a): the record path and the role overrides, in the stored path subset.
        if (array_key_exists('record_path', $input)) {
            if ($input['record_path'] === null) {
                $out['record_path'] = null;
            } elseif (is_string($input['record_path']) && self::isRecordPath($input['record_path'])) {
                $out['record_path'] = $input['record_path'];
            } else {
                $errors['config.record_path'] = ['Choose an array of records, such as $.data[*].'];
            }
        }

        if (array_key_exists('field_roles', $input) && $input['field_roles'] === null) {
            $out['field_roles'] = [];
        } elseif (isset($input['field_roles'])) {
            if (! is_array($input['field_roles'])) {
                $errors['config.field_roles'] = ['Field roles must be an object.'];
            } else {
                $roles = [];

                foreach ($input['field_roles'] as $path => $role) {
                    $path = (string) $path;

                    if (! self::isCanonicalPath($path)) {
                        $errors["config.field_roles.{$path}"] = ['Use a stored path such as $.data[*].revenue.'];
                    } elseif (! is_string($role) || FieldRole::tryFrom($role) === null) {
                        $errors["config.field_roles.{$path}"] = ['Choose one of: '.implode(', ', array_column(FieldRole::cases(), 'value')).'.'];
                    } else {
                        $roles[$path] = $role;
                    }
                }

                $out['field_roles'] = $roles;
            }
        }

        // "Treat as" (Story 3.13a): stored path => text|number|boolean|date. Null clears the map.
        if (array_key_exists('field_types', $input) && $input['field_types'] === null) {
            $out['field_types'] = [];
        } elseif (isset($input['field_types'])) {
            if (! is_array($input['field_types'])) {
                $errors['config.field_types'] = ['Field types must be an object.'];
            } else {
                $types = [];

                foreach ($input['field_types'] as $path => $treatAs) {
                    $path = (string) $path;

                    if (! self::isCanonicalPath($path)) {
                        $errors["config.field_types.{$path}"] = ['Use a stored path such as $.data[*].revenue.'];
                    } elseif (! is_string($treatAs) || ! in_array($treatAs, self::FIELD_TYPES, true)) {
                        $errors["config.field_types.{$path}"] = ['Choose one of: '.implode(', ', self::FIELD_TYPES).'.'];
                    } else {
                        $types[$path] = $treatAs;
                    }
                }

                $out['field_types'] = $types;
            }
        }

        // The Admin's slot decisions (Story 3.12a): slot key => {path, state}, `confirmed` or `overridden`; a null path is a cleared slot. Null clears the map.
        if (array_key_exists('slot_map', $input) && $input['slot_map'] === null) {
            $out['slot_map'] = [];
        } elseif (isset($input['slot_map'])) {
            if (! is_array($input['slot_map'])) {
                $errors['config.slot_map'] = ['The slot map must be an object.'];
            } else {
                $known = array_map(fn ($slot): string => $slot->key, $type->slots);
                $map = [];

                foreach ($input['slot_map'] as $slot => $entry) {
                    $slot = (string) $slot;

                    if (! in_array($slot, $known, true)) {
                        $errors["config.slot_map.{$slot}"] = ['This slot is not part of the '.$type->title.'.'];
                    } elseif (! is_array($entry) || ! array_key_exists('path', $entry) || ! in_array($entry['state'] ?? null, ['confirmed', 'overridden'], true)
                        || ($entry['path'] !== null && (! is_string($entry['path']) || ! self::isCanonicalPath($entry['path'])))) {
                        $errors["config.slot_map.{$slot}"] = ['Use a stored path (or null) and a state of confirmed or overridden.'];
                    } else {
                        $map[$slot] = ['path' => $entry['path'], 'state' => $entry['state']];
                    }
                }

                $out['slot_map'] = $map;
            }
        }

        if ($errors !== []) {
            throw new InvalidDraft($errors, 'blocks.invalid_config');
        }

        return $out;
    }

    /**
     * The presentation rules of a Block (Story 3.15a) as the mapping endpoint writes them, judged by Mapping's {@see Presentation}: every fault is keyed
     * `config.presentation.<path>` and nothing is stored when there is one. Null or an empty object is no presentation (an empty result).
     *
     * @return array<string, mixed>
     *
     * @throws InvalidDraft
     */
    public static function normalisePresentation(mixed $input, BlockType $type): array
    {
        $errors = [];
        $out = Presentation::check($input, $type, $errors);

        if ($errors !== []) {
            throw new InvalidDraft($errors, 'blocks.invalid_config');
        }

        return $out;
    }

    /** A stored path in its canonical spelling, naming at least one segment. */
    public static function isCanonicalPath(string $path): bool
    {
        try {
            $segments = SamplePath::parse($path);

            return $segments !== [] && SamplePath::stored($segments) === $path;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** A record path names an array's elements: a stored path that ends in `[*]`. */
    public static function isRecordPath(string $path): bool
    {
        try {
            return str_ends_with($path, '[*]') && SamplePath::parse($path) !== [] && SamplePath::stored(SamplePath::parse($path)) === $path;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * A plan {@see QueryPlan::validate()} accepted, in its stored order and with nothing else.
     *
     * @param  array<mixed>  $plan
     * @return array<string, mixed>
     */
    private static function queryPlan(array $plan): array
    {
        return [
            'v' => QueryPlan::VERSION,
            'extract' => ['fields' => array_map('strval', $plan['extract']['fields'])],
            'shape' => array_map('strval', $plan['shape']),
        ];
    }

    /**
     * @param  array<mixed>  $display
     * @param  array<string, list<string>>  $errors
     * @return array<string, mixed>
     */
    private static function display(array $display, BlockType $type, array &$errors): array
    {
        $size = $type->size;
        $out = [];

        foreach (self::FLAGS as $flag) {
            if (isset($display[$flag])) {
                if (is_bool($display[$flag])) {
                    $out[$flag] = $display[$flag];
                } else {
                    $errors["config.display.{$flag}"] = ['Choose on or off.'];
                }
            }
        }

        if (isset($display['period'])) {
            if (is_string($display['period']) && in_array($display['period'], self::PERIODS, true)) {
                $out['period'] = $display['period'];
            } else {
                $errors['config.display.period'] = ['Choose how the block follows the period.'];
            }
        }

        foreach (['min_width', 'max_width', 'width'] as $key) {
            if (! isset($display[$key])) {
                continue;
            }

            $range = 'Use '.$size['min_width'].' to '.$size['max_width'].' columns for a '.$type->title.'.';

            if (is_int($display[$key]) && $display[$key] >= $size['min_width'] && $display[$key] <= $size['max_width']) {
                $out[$key] = $display[$key];
            } else {
                $errors["config.display.{$key}"] = [$range];
            }
        }

        foreach (['min_height', 'max_height', 'height'] as $key) {
            if (! isset($display[$key])) {
                continue;
            }

            $from = (int) array_search($size['min_height'], self::HEIGHTS, true);
            $to = (int) array_search($size['max_height'], self::HEIGHTS, true);
            $at = is_string($display[$key]) ? array_search($display[$key], self::HEIGHTS, true) : false;

            if ($at !== false && $at >= $from && $at <= $to) {
                $out[$key] = $display[$key];
            } else {
                $errors["config.display.{$key}"] = ['Use a height from '.$size['min_height'].' to '.$size['max_height'].' for a '.$type->title.'.'];
            }
        }

        // The chosen size must sit between the chosen limits.
        if (isset($out['min_width'], $out['max_width']) && $out['min_width'] > $out['max_width']) {
            $errors['config.display.min_width'] = ['The minimum width cannot be more than the maximum width.'];
        }

        if (isset($out['width']) && ((isset($out['min_width']) && $out['width'] < $out['min_width']) || (isset($out['max_width']) && $out['width'] > $out['max_width']))) {
            $errors['config.display.width'] = ['The default width must be between the minimum and maximum width.'];
        }

        $index = fn (string $key): ?int => isset($out[$key]) ? (int) array_search($out[$key], self::HEIGHTS, true) : null;

        if ($index('min_height') !== null && $index('max_height') !== null && $index('min_height') > $index('max_height')) {
            $errors['config.display.min_height'] = ['The minimum height cannot be more than the maximum height.'];
        }

        if ($index('height') !== null && (($index('min_height') !== null && $index('height') < $index('min_height')) || ($index('max_height') !== null && $index('height') > $index('max_height')))) {
            $errors['config.display.height'] = ['The default height must be between the minimum and maximum height.'];
        }

        return $out;
    }

    /**
     * Step 1's keys replace the stored ones; every other stored top-level key (a later step's) survives, as do the other slots.
     *
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $step  the output of {@see self::normalise()}
     * @return array<string, mixed>
     */
    public static function merge(array $stored, array $step): array
    {
        // `source` is Step 2's key (Story 3.7): it is replaced only when the save carries one (see ManageDrafts). The mapping keys are never in a step (they
        // are refused by normalise); a save that carries a plan replaces it, and so drops the sample revision the stored plan was stamped with.
        $replaced = ['schema_version', 'description', 'icon', 'display', 'slots', ...(array_key_exists('source', $step) ? ['source'] : []), ...(array_key_exists('query_plan', $step) ? ['query_plan', 'mapped_sample_revision'] : [])];
        $kept = array_diff_key($stored, array_flip($replaced));
        $slots = is_array($stored['slots'] ?? null) ? $stored['slots'] : [];
        unset($slots['footer_action']);
        $slots += $step['slots'] ?? [];

        return $kept + ($slots === [] ? [] : ['slots' => $slots]) + $step;
    }

    /** @param  array<string, mixed>  $config */
    public static function encode(array $config): string
    {
        return LosslessJson::encode(self::toObjects($config));
    }

    /** @return array<string, mixed> */
    public static function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return ['schema_version' => self::SCHEMA_VERSION];
        }

        $plain = self::plain(LosslessJson::decode($json));

        return is_array($plain) ? $plain : ['schema_version' => self::SCHEMA_VERSION];
    }

    /**
     * The wizard's own state (Story 3.5) as the stored document: the acknowledged warnings (a list of strings) and the slot statuses (a map
     * of strings). Anything else is dropped. It is never part of a version's config.
     *
     * @param  array<mixed>  $state
     */
    public static function encodeUiState(array $state): string
    {
        $warnings = [];

        foreach (is_array($state['acknowledged_warnings'] ?? null) ? $state['acknowledged_warnings'] : [] as $warning) {
            if (is_string($warning)) {
                $warnings[] = $warning;
            }
        }

        $statuses = [];

        foreach (is_array($state['slot_statuses'] ?? null) ? $state['slot_statuses'] : [] as $slot => $status) {
            if (is_string($status)) {
                $statuses[(string) $slot] = $status;
            }
        }

        return LosslessJson::encode(new JsonObject([
            'acknowledged_warnings' => array_values(array_unique($warnings)),
            'slot_statuses' => new JsonObject($statuses),
        ]));
    }

    /** @return array<string, mixed> */
    public static function decodeUiState(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $plain = self::plain(LosslessJson::decode($json));

        return is_array($plain) ? $plain : [];
    }

    /** Every array of a config is an object, but the presentation's `bands` list (Story 3.15a); whole numbers go out as the lexemes the codec writes. */
    private static function toObjects(mixed $value): mixed
    {
        if (is_int($value)) {
            return new DecimalLiteral((string) $value);
        }

        if (is_array($value) && $value !== [] && array_is_list($value)) {
            return array_map(self::toObjects(...), $value);
        }

        return is_array($value) ? new JsonObject(array_map(self::toObjects(...), $value)) : $value;
    }

    private static function plain(mixed $value): mixed
    {
        if ($value instanceof JsonObject) {
            $out = [];

            foreach ($value->entries() as [$key, $inner]) {
                $out[$key] = self::plain($inner);
            }

            return $out;
        }

        if ($value instanceof DecimalLiteral) {
            return $value->isInteger() ? (int) $value->lexeme : $value->lexeme;
        }

        return is_array($value) ? array_map(self::plain(...), $value) : $value;
    }
}
