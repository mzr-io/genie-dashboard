<?php

namespace App\Modules\Blocks\Application;

use App\Modules\Blocks\Contracts\InvalidDraft;
use App\Modules\BlockTypes\Contracts\BlockType;
use App\Platform\Json\DecimalLiteral;
use App\Platform\Json\JsonObject;
use App\Platform\Json\LosslessJson;

/**
 * The lenient `config` document of a Draft (Story 3.4, `schema_version` 1): the Configure Block step's fields. Nothing is required (a
 * Draft saves as it is); what is present must have the right type, and sizes must lie inside the Block Type's registry limits, which
 * are named in the error. Unknown keys are dropped. The "Footer action" slot is derived: it is present, optional and empty exactly when
 * "Show footer action" is on. Stored and read through the platform's JSON codec, never `json_decode`.
 */
final class DraftConfig
{
    public const SCHEMA_VERSION = 1;

    public const HEIGHTS = ['small', 'medium', 'large'];

    public const PERIODS = ['follow_dashboard', 'own_selector', 'not_date_filtered'];

    public const DESCRIPTION_MAX = 500;

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

        if ($errors !== []) {
            throw new InvalidDraft($errors, 'blocks.invalid_config');
        }

        return $out;
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
        // `source` is Step 2's key (Story 3.7): it is replaced only when the save carries one (see ManageDrafts).
        $kept = array_diff_key($stored, array_flip(['schema_version', 'description', 'icon', 'display', 'slots', ...(array_key_exists('source', $step) ? ['source'] : [])]));
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

    /** Every array of a config is an object (it holds no lists); whole numbers go out as the lexemes the codec writes. */
    private static function toObjects(mixed $value): mixed
    {
        if (is_int($value)) {
            return new DecimalLiteral((string) $value);
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
