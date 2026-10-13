<?php

namespace App\Modules\Mapping\Contracts;

use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\BlockTypes\Contracts\KpiCardSlots;

/**
 * Presentation rules of a Block (Story 3.15a), `config.presentation`: per slot a format descriptor, emphasis, direction and label; threshold `bands`; and
 * the `empty_display`. This class only judges the document and returns it in its stored form: a descriptor of how to format (never a locale-formatted
 * string), exact decimal strings for band limits, and nothing else. Every fault is keyed `config.presentation.<path>`, and no fault carries the value.
 */
final class Presentation
{
    public const KEY = 'presentation';

    public const KINDS = ['number', 'percent', 'currency', 'compact', 'date', 'relative-time', 'duration'];

    public const CURRENCY_DISPLAYS = ['symbol', 'code'];

    public const DATE_PATTERNS = ['short', 'medium', 'long', 'full'];

    public const MAX_PLACES = 20;

    public const ROLES = ['headline', 'primary', 'secondary', 'badge'];

    public const SIZES = ['sm', 'md', 'lg'];

    public const WEIGHTS = ['regular', 'medium', 'bold'];

    public const DIRECTIONS = ['higher_is_better', 'lower_is_better'];

    /** How a comparison is expressed (Story 5.5): the change in percent, the difference, or the difference of two ratios in percentage points. */
    public const MODES = ['pct_change', 'absolute_delta', 'pp_delta'];

    public const OPS = ['>=', '<=', 'between'];

    /** The accent colour is the brand's, not a status: a band is never accent. */
    public const COLORS = ['success', 'warning', 'error', 'info', 'neutral'];

    public const LABEL_MAX = 80;

    public const AFFIX_MAX = 16;

    public const UNITS_MAX = 16;

    public const EMPTY_MAX = 32;

    public const BAND_LABEL_MAX = 40;

    public const BANDS_MAX = 10;

    public const ICON_MAX = 48;

    public const DECIMAL_MAX = 64;

    /** The currencies of ISO 4217 that `Intl` formats (active codes, funds included). */
    public const CURRENCIES = [
        'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BGN', 'BHD', 'BIF', 'BMD', 'BND', 'BOB', 'BRL',
        'BSD', 'BTN', 'BWP', 'BYN', 'BZD', 'CAD', 'CDF', 'CHF', 'CLP', 'CNY', 'COP', 'CRC', 'CUP', 'CVE', 'CZK', 'DJF', 'DKK', 'DOP', 'DZD', 'EGP',
        'ERN', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GHS', 'GIP', 'GMD', 'GNF', 'GTQ', 'GYD', 'HKD', 'HNL', 'HTG', 'HUF', 'IDR', 'ILS', 'INR',
        'IQD', 'IRR', 'ISK', 'JMD', 'JOD', 'JPY', 'KES', 'KGS', 'KHR', 'KMF', 'KPW', 'KRW', 'KWD', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'LSL',
        'LYD', 'MAD', 'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MRU', 'MUR', 'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR',
        'NZD', 'OMR', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'PYG', 'QAR', 'RON', 'RSD', 'RUB', 'RWF', 'SAR', 'SBD', 'SCR', 'SDG', 'SEK', 'SGD',
        'SHP', 'SLE', 'SOS', 'SRD', 'SSP', 'STN', 'SVC', 'SYP', 'SZL', 'THB', 'TJS', 'TMT', 'TND', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'UGX',
        'USD', 'UYU', 'UZS', 'VES', 'VND', 'VUV', 'WST', 'XAF', 'XCD', 'XOF', 'XPF', 'YER', 'ZAR', 'ZMW', 'ZWG',
    ];

    private const ROOT = 'config.presentation';

    private const ICON = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D';

    private const DECIMAL = '/\A[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?\z/D';

    /**
     * The presentation in its stored form, with every fault added to `$errors` (keyed `config.presentation.<path>`). Nothing is returned for a part that is
     * faulty. An empty result means "no presentation": null and `{}` both clear it.
     *
     * @param  array<string, list<string>>  $errors
     * @return array<string, mixed>
     */
    public static function check(mixed $input, BlockType $type, array &$errors): array
    {
        if ($input === null) {
            return [];
        }

        if (! is_array($input) || ($input !== [] && array_is_list($input))) {
            $errors[self::ROOT] = ['Presentation must be an object.'];

            return [];
        }

        $out = [];

        foreach (array_keys($input) as $key) {
            if (! in_array($key, ['slots', 'bands', 'empty_display'], true)) {
                $errors[self::ROOT.'.'.self::name($key)] = ['This is not a presentation setting.'];
            }
        }

        if (array_key_exists('slots', $input) && $input['slots'] !== null) {
            $slots = self::slots($input['slots'], $type, $errors);

            if ($slots !== []) {
                $out['slots'] = $slots;
            }
        }

        if (array_key_exists('bands', $input) && $input['bands'] !== null) {
            $bands = self::bands($input['bands'], $errors);

            if ($bands !== []) {
                $out['bands'] = $bands;
            }
        }

        if (array_key_exists('empty_display', $input) && $input['empty_display'] !== null) {
            $text = self::text($input['empty_display'], self::EMPTY_MAX, self::ROOT.'.empty_display', $errors);

            if ($text !== null) {
                $out['empty_display'] = $text;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return array<string, array<string, mixed>>
     */
    private static function slots(mixed $slots, BlockType $type, array &$errors): array
    {
        $path = self::ROOT.'.slots';

        if (! is_array($slots) || ($slots !== [] && array_is_list($slots))) {
            $errors[$path] = ['Slots must be an object of slot settings.'];

            return [];
        }

        $known = array_map(fn ($slot): string => $slot->key, $type->slots);
        $out = [];
        $headlines = [];

        foreach ($slots as $slot => $entry) {
            $slot = self::name($slot);
            $at = "{$path}.{$slot}";

            if (! in_array($slot, $known, true)) {
                $errors[$at] = ['This slot is not part of the '.$type->title.'.'];

                continue;
            }

            if (! is_array($entry) || ($entry !== [] && array_is_list($entry))) {
                $errors[$at] = ['Slot settings must be an object.'];

                continue;
            }

            $clean = [];

            foreach (array_keys($entry) as $key) {
                if (! in_array($key, ['format', 'emphasis', 'direction', 'label', 'mode'], true)) {
                    $errors["{$at}.".self::name($key)] = ['This is not a slot setting.'];
                }
            }

            if (($entry['format'] ?? null) !== null) {
                $format = self::format($entry['format'], "{$at}.format", $errors);

                if ($format !== null) {
                    $clean['format'] = $format;
                }
            }

            if (($entry['emphasis'] ?? null) !== null) {
                $emphasis = self::emphasis($entry['emphasis'], "{$at}.emphasis", $errors);

                if ($emphasis !== null) {
                    $clean['emphasis'] = $emphasis;

                    if (($emphasis['role'] ?? null) === 'headline') {
                        $headlines[] = $slot;
                    }
                }
            }

            if (($entry['direction'] ?? null) !== null) {
                if ($slot !== KpiCardSlots::SLOT_COMPARISON) {
                    $errors["{$at}.direction"] = ['Direction applies to the comparison slot only.'];
                } elseif (is_string($entry['direction']) && in_array($entry['direction'], self::DIRECTIONS, true)) {
                    $clean['direction'] = $entry['direction'];
                } else {
                    $errors["{$at}.direction"] = ['Choose one of: '.implode(', ', self::DIRECTIONS).'.'];
                }
            }

            // How the comparison reads its previous value (Story 5.5): the Slot's own mapped field is the previous value and the change is calculated.
            if (($entry['mode'] ?? null) !== null) {
                if ($slot !== KpiCardSlots::SLOT_COMPARISON) {
                    $errors["{$at}.mode"] = ['A comparison mode applies to the comparison slot only.'];
                } elseif (is_string($entry['mode']) && in_array($entry['mode'], self::MODES, true)) {
                    $clean['mode'] = $entry['mode'];
                } else {
                    $errors["{$at}.mode"] = ['Choose one of: '.implode(', ', self::MODES).'.'];
                }
            }

            if (($entry['label'] ?? null) !== null) {
                $label = self::label($entry['label'], "{$at}.label", $errors);

                if ($label !== []) {
                    $clean['label'] = $label;
                }
            }

            if ($clean !== []) {
                $out[$slot] = $clean;
            }
        }

        // One headline per Block: the second is the one refused, with the reason.
        foreach (array_slice($headlines, 1) as $slot) {
            $errors["{$path}.{$slot}.emphasis"] = ['Only one slot can be the headline: '.$headlines[0].' already is.'];
        }

        return $out;
    }

    /**
     * A format descriptor: how to format, never the formatted text.
     *
     * @param  array<string, list<string>>  $errors
     * @return array<string, mixed>|null
     */
    private static function format(mixed $format, string $path, array &$errors): ?array
    {
        if (! is_array($format) || ($format !== [] && array_is_list($format))) {
            $errors[$path] = ['A format must be an object.'];

            return null;
        }

        $before = count($errors);
        $out = [];

        foreach (array_keys($format) as $key) {
            if (! in_array($key, ['kind', 'decimalPlaces', 'thousandsSeparator', 'currencyCode', 'currencyDisplay', 'datePattern', 'unitsSuffix'], true)) {
                $errors["{$path}.".self::name($key)] = ['This is not a format setting.'];
            }
        }

        $kind = $format['kind'] ?? null;

        if (! is_string($kind) || ! in_array($kind, self::KINDS, true)) {
            $errors["{$path}.kind"] = ['Choose one of: '.implode(', ', self::KINDS).'.'];
        } else {
            $out['kind'] = $kind;
        }

        if (($format['decimalPlaces'] ?? null) !== null) {
            $places = $format['decimalPlaces'];

            if (is_int($places) && $places >= 0 && $places <= self::MAX_PLACES) {
                $out['decimalPlaces'] = $places;
            } else {
                $errors["{$path}.decimalPlaces"] = ['Use a whole number from 0 to '.self::MAX_PLACES.'.'];
            }
        }

        if (($format['thousandsSeparator'] ?? null) !== null) {
            if (is_bool($format['thousandsSeparator'])) {
                $out['thousandsSeparator'] = $format['thousandsSeparator'];
            } else {
                $errors["{$path}.thousandsSeparator"] = ['Choose on or off.'];
            }
        }

        $code = $format['currencyCode'] ?? null;

        if ($code !== null) {
            if (is_string($code) && preg_match('/\A[A-Za-z]{3}\z/D', $code) === 1 && in_array(strtoupper($code), self::CURRENCIES, true)) {
                $out['currencyCode'] = strtoupper($code);
            } else {
                $errors["{$path}.currencyCode"] = ['Use a three-letter ISO 4217 currency code, such as USD.'];
            }
        } elseif ($kind === 'currency') {
            $errors["{$path}.currencyCode"] = ['A currency format needs a currency code.'];
        }

        if (($format['currencyDisplay'] ?? null) !== null) {
            if (is_string($format['currencyDisplay']) && in_array($format['currencyDisplay'], self::CURRENCY_DISPLAYS, true)) {
                $out['currencyDisplay'] = $format['currencyDisplay'];
            } else {
                $errors["{$path}.currencyDisplay"] = ['Choose one of: '.implode(', ', self::CURRENCY_DISPLAYS).'.'];
            }
        }

        if (($format['datePattern'] ?? null) !== null) {
            if (is_string($format['datePattern']) && in_array($format['datePattern'], self::DATE_PATTERNS, true)) {
                $out['datePattern'] = $format['datePattern'];
            } else {
                $errors["{$path}.datePattern"] = ['Choose one of: '.implode(', ', self::DATE_PATTERNS).'.'];
            }
        }

        if (($format['unitsSuffix'] ?? null) !== null) {
            $suffix = self::text($format['unitsSuffix'], self::UNITS_MAX, "{$path}.unitsSuffix", $errors);

            if ($suffix !== null) {
                $out['unitsSuffix'] = $suffix;
            }
        }

        return count($errors) > $before ? null : $out;
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return array<string, string>|null
     */
    private static function emphasis(mixed $emphasis, string $path, array &$errors): ?array
    {
        if (! is_array($emphasis) || ($emphasis !== [] && array_is_list($emphasis))) {
            $errors[$path] = ['Emphasis must be an object.'];

            return null;
        }

        $before = count($errors);
        $out = [];

        foreach (array_keys($emphasis) as $key) {
            if (! in_array($key, ['role', 'size', 'weight'], true)) {
                $errors["{$path}.".self::name($key)] = ['This is not an emphasis setting.'];
            }
        }

        foreach (['role' => self::ROLES, 'size' => self::SIZES, 'weight' => self::WEIGHTS] as $key => $allowed) {
            if (($emphasis[$key] ?? null) === null) {
                continue;
            }

            if (is_string($emphasis[$key]) && in_array($emphasis[$key], $allowed, true)) {
                $out[$key] = $emphasis[$key];
            } else {
                $errors["{$path}.{$key}"] = ['Choose one of: '.implode(', ', $allowed).'.'];
            }
        }

        return count($errors) > $before || $out === [] ? null : $out;
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return array<string, string>
     */
    private static function label(mixed $label, string $path, array &$errors): array
    {
        if (! is_array($label) || ($label !== [] && array_is_list($label))) {
            $errors[$path] = ['A label must be an object.'];

            return [];
        }

        $out = [];

        foreach (array_keys($label) as $key) {
            if (! in_array($key, ['text', 'prefix', 'suffix'], true)) {
                $errors["{$path}.".self::name($key)] = ['This is not a label setting.'];
            }
        }

        foreach (['text' => self::LABEL_MAX, 'prefix' => self::AFFIX_MAX, 'suffix' => self::AFFIX_MAX] as $key => $max) {
            if (($label[$key] ?? null) === null) {
                continue;
            }

            $text = self::text($label[$key], $max, "{$path}.{$key}", $errors);

            if ($text !== null) {
                $out[$key] = $text;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return list<array<string, string>>
     */
    private static function bands(mixed $bands, array &$errors): array
    {
        $path = self::ROOT.'.bands';

        if (! is_array($bands) || ($bands !== [] && ! array_is_list($bands))) {
            $errors[$path] = ['Bands must be a list.'];

            return [];
        }

        if (count($bands) > self::BANDS_MAX) {
            $errors[$path] = ['Use at most '.self::BANDS_MAX.' bands.'];

            return [];
        }

        $out = [];

        foreach ($bands as $index => $band) {
            $clean = self::band($band, "{$path}.{$index}", $errors);

            if ($clean !== null) {
                $out[] = $clean;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return array<string, string>|null
     */
    private static function band(mixed $band, string $path, array &$errors): ?array
    {
        if (! is_array($band) || ($band !== [] && array_is_list($band))) {
            $errors[$path] = ['A band must be an object.'];

            return null;
        }

        $before = count($errors);
        $out = [];

        foreach (array_keys($band) as $key) {
            if (! in_array($key, ['op', 'value', 'to', 'color', 'icon', 'label'], true)) {
                $errors["{$path}.".self::name($key)] = ['This is not a band setting.'];
            }
        }

        $op = $band['op'] ?? null;

        if (! is_string($op) || ! in_array($op, self::OPS, true)) {
            $errors["{$path}.op"] = ['Choose one of: '.implode(', ', self::OPS).'.'];
        } else {
            $out['op'] = $op;
        }

        $value = self::decimal($band['value'] ?? null, "{$path}.value", $errors);
        $to = null;

        if ($value !== null) {
            $out['value'] = $value;
        }

        if ($op === 'between') {
            $to = self::decimal($band['to'] ?? null, "{$path}.to", $errors);

            if ($to !== null) {
                $out['to'] = $to;

                if ($value !== null && DecimalValue::compare($value, $to) === 1) {
                    $errors["{$path}.to"] = ['The upper limit cannot be below the lower limit.'];
                }
            }
        } elseif (($band['to'] ?? null) !== null) {
            $errors["{$path}.to"] = ['Only a between band has an upper limit.'];
        }

        $color = $band['color'] ?? null;

        if (! is_string($color) || ! in_array($color, self::COLORS, true)) {
            $errors["{$path}.color"] = ['Choose one of: '.implode(', ', self::COLORS).'.'];
        } else {
            $out['color'] = $color;
        }

        // A band is never colour alone: it carries an icon and a label.
        $icon = $band['icon'] ?? null;

        if (! is_string($icon) || $icon === '') {
            $errors["{$path}.icon"] = ['A band needs an icon.'];
        } elseif (strlen($icon) > self::ICON_MAX || preg_match(self::ICON, $icon) !== 1) {
            $errors["{$path}.icon"] = ['Choose an icon from the list.'];
        } else {
            $out['icon'] = $icon;
        }

        $label = $band['label'] ?? null;

        if (! is_string($label) || trim($label) === '') {
            $errors["{$path}.label"] = ['A band needs a label.'];
        } else {
            $text = self::text(trim($label), self::BAND_LABEL_MAX, "{$path}.label", $errors);

            if ($text !== null) {
                $out['label'] = $text;
            }
        }

        return count($errors) > $before ? null : $out;
    }

    /** @param  array<string, list<string>>  $errors */
    private static function decimal(mixed $value, string $path, array &$errors): ?string
    {
        if (! is_string($value) || strlen($value) > self::DECIMAL_MAX || preg_match(self::DECIMAL, trim($value)) !== 1 || DecimalValue::plain($value) === null) {
            $errors[$path] = ['Use a decimal number as text, such as "100" or "2.5".'];

            return null;
        }

        return trim($value);
    }

    /**
     * Short single-line text: not empty, within the limit, no control characters.
     *
     * @param  array<string, list<string>>  $errors
     */
    private static function text(mixed $value, int $max, string $path, array &$errors): ?string
    {
        if (! is_string($value) || $value === '' || mb_strlen($value) > $max || preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 1) {
            $errors[$path] = ["Use 1 to {$max} characters, with no control characters."];

            return null;
        }

        return $value;
    }

    /** A key as text, kept short so a refusal never echoes a long input. */
    private static function name(int|string $key): string
    {
        return mb_substr((string) $key, 0, 64);
    }
}
