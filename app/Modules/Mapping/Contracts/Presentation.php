<?php

namespace App\Modules\Mapping\Contracts;

use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\BlockTypes\Contracts\KpiCardSlots;
use App\Platform\Period\PriorWindow;

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

    /** The `Intl` styles, and the month patterns a chart's axis uses (Story 5.10). */
    public const DATE_PATTERNS = ['short', 'medium', 'long', 'full', 'MMM', 'MMM yyyy', 'MMM d'];

    public const MAX_PLACES = 20;

    public const ROLES = ['headline', 'primary', 'secondary', 'badge'];

    public const SIZES = ['sm', 'md', 'lg'];

    public const WEIGHTS = ['regular', 'medium', 'bold'];

    public const DIRECTIONS = ['higher_is_better', 'lower_is_better'];

    /** How a comparison is expressed (Story 5.5): the change in percent, the difference, or the difference of two ratios in percentage points. */
    public const MODES = ['pct_change', 'absolute_delta', 'pp_delta'];

    /** Where a comparison's previous value comes from (Story 5.6): a field of the same response, or the same Endpoint called for a prior window. */
    public const SOURCES = ['mapped_previous', 'prior_period'];

    /** A chart's own setting by Block Type: line or area, grouped or stacked bars, a pie or a donut (Stories 5.8, 5.11). */
    public const CHART_KINDS = ['line', 'area', 'grouped', 'stacked', 'pie', 'donut'];

    public const ORIENTATIONS = ['vertical', 'horizontal'];

    public const ALIGNMENTS = ['left', 'right', 'center'];

    public const WIDTH_MIN = 40;

    public const WIDTH_MAX = 800;

    public const PAGE_SIZE_MIN = 5;

    public const PAGE_SIZE_MAX = 100;

    public const SORTS = ['data', 'value'];

    public const CENTRES = ['value', 'total', 'none'];

    public const OPS = ['>=', '<=', 'between', 'equals'];

    /** The longest text a band's `equals` value takes. */
    public const MATCH_MAX = 40;

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
            if (! in_array($key, ['slots', 'bands', 'empty_display', 'chart_kind', 'direct_labels', 'orientation', 'value_labels', 'sort', 'centre', 'centre_label', 'page_size', 'highlight_column'], true)) {
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

        // A chart's own settings (Story 5.8c): line or area, and the direct labels at the line ends.
        if (array_key_exists('chart_kind', $input) && $input['chart_kind'] !== null) {
            if (is_string($input['chart_kind']) && in_array($input['chart_kind'], self::CHART_KINDS, true)) {
                $out['chart_kind'] = $input['chart_kind'];
            } else {
                $errors[self::ROOT.'.chart_kind'] = ['Choose one of: '.implode(', ', self::CHART_KINDS).'.'];
            }
        }

        foreach (['orientation' => self::ORIENTATIONS, 'sort' => self::SORTS, 'centre' => self::CENTRES] as $setting => $choices) {
            if (array_key_exists($setting, $input) && $input[$setting] !== null) {
                if (is_string($input[$setting]) && in_array($input[$setting], $choices, true)) {
                    $out[$setting] = $input[$setting];
                } else {
                    $errors[self::ROOT.'.'.$setting] = ['Choose one of: '.implode(', ', $choices).'.'];
                }
            }
        }

        // A table's page size and the column its row highlight rule reads (Story 5.12).
        if (array_key_exists('page_size', $input) && $input['page_size'] !== null) {
            if (is_int($input['page_size']) && $input['page_size'] >= self::PAGE_SIZE_MIN && $input['page_size'] <= self::PAGE_SIZE_MAX) {
                $out['page_size'] = $input['page_size'];
            } else {
                $errors[self::ROOT.'.page_size'] = ['Use a whole number from '.self::PAGE_SIZE_MIN.' to '.self::PAGE_SIZE_MAX.'.'];
            }
        }

        if (array_key_exists('highlight_column', $input) && $input['highlight_column'] !== null) {
            $known = array_map(fn ($slot): string => $slot->key, $type->slots);

            if (is_string($input['highlight_column']) && in_array($input['highlight_column'], $known, true)) {
                $out['highlight_column'] = $input['highlight_column'];
            } else {
                $errors[self::ROOT.'.highlight_column'] = ['Choose a column of the table.'];
            }
        }

        if (array_key_exists('value_labels', $input) && $input['value_labels'] !== null) {
            if (is_bool($input['value_labels'])) {
                $out['value_labels'] = $input['value_labels'];
            } else {
                $errors[self::ROOT.'.value_labels'] = ['Value labels are on or off.'];
            }
        }

        if (array_key_exists('centre_label', $input) && $input['centre_label'] !== null) {
            $text = self::text($input['centre_label'], self::BAND_LABEL_MAX, self::ROOT.'.centre_label', $errors);

            if ($text !== null) {
                $out['centre_label'] = $text;
            }
        }

        if (array_key_exists('direct_labels', $input) && $input['direct_labels'] !== null) {
            if (is_bool($input['direct_labels'])) {
                $out['direct_labels'] = $input['direct_labels'];
            } else {
                $errors[self::ROOT.'.direct_labels'] = ['Direct labels are on or off.'];
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
                if (! in_array($key, ['format', 'emphasis', 'direction', 'label', 'mode', 'source', 'window', 'align', 'width', 'sortable'], true)) {
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

            // A table column (Story 5.12): alignment, width in pixels and whether a reader may sort by it.
            if (($entry['align'] ?? null) !== null) {
                if (is_string($entry['align']) && in_array($entry['align'], self::ALIGNMENTS, true)) {
                    $clean['align'] = $entry['align'];
                } else {
                    $errors["{$at}.align"] = ['Choose one of: '.implode(', ', self::ALIGNMENTS).'.'];
                }
            }

            if (($entry['width'] ?? null) !== null) {
                if (is_int($entry['width']) && $entry['width'] >= self::WIDTH_MIN && $entry['width'] <= self::WIDTH_MAX) {
                    $clean['width'] = $entry['width'];
                } else {
                    $errors["{$at}.width"] = ['Use a whole number from '.self::WIDTH_MIN.' to '.self::WIDTH_MAX.' pixels.'];
                }
            }

            if (($entry['sortable'] ?? null) !== null) {
                if (is_bool($entry['sortable'])) {
                    $clean['sortable'] = $entry['sortable'];
                } else {
                    $errors["{$at}.sortable"] = ['Sortable is on or off.'];
                }
            }

            // The prior-period source and its window (Story 5.6).
            if (($entry['source'] ?? null) !== null) {
                if ($slot !== KpiCardSlots::SLOT_COMPARISON) {
                    $errors["{$at}.source"] = ['A comparison source applies to the comparison slot only.'];
                } elseif (is_string($entry['source']) && in_array($entry['source'], self::SOURCES, true)) {
                    $clean['source'] = $entry['source'];
                } else {
                    $errors["{$at}.source"] = ['Choose one of: '.implode(', ', self::SOURCES).'.'];
                }
            }

            if (($entry['window'] ?? null) !== null) {
                $window = $slot === KpiCardSlots::SLOT_COMPARISON ? self::window($entry['window'], "{$at}.window", $errors) : null;

                if ($slot !== KpiCardSlots::SLOT_COMPARISON) {
                    $errors["{$at}.window"] = ['A prior window applies to the comparison slot only.'];
                } elseif ($window !== null) {
                    $clean['window'] = $window;
                }
            }

            if ($slot === KpiCardSlots::SLOT_COMPARISON && ($clean['source'] ?? null) === 'prior_period' && ! isset($clean['window']) && ! isset($errors["{$at}.window"])) {
                $errors["{$at}.window"] = ['Choose the prior window: the previous period, the same period last year, or an offset.'];
            }

            if ($slot === KpiCardSlots::SLOT_COMPARISON && isset($clean['window']) && ($clean['source'] ?? null) !== 'prior_period') {
                $errors["{$at}.window"] = ['A prior window belongs to the prior-period source.'];
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
     * The prior window of a comparison: a kind, and for a custom offset a whole number and a unit.
     *
     * @param  array<string, list<string>>  $errors
     * @return array{kind: string, amount?: int, unit?: string}|null
     */
    private static function window(mixed $window, string $path, array &$errors): ?array
    {
        if (! is_array($window) || ($window !== [] && array_is_list($window))) {
            $errors[$path] = ['A prior window must be an object.'];

            return null;
        }

        foreach (array_keys($window) as $key) {
            if (! in_array($key, ['kind', 'amount', 'unit'], true)) {
                $errors["{$path}.".self::name($key)] = ['This is not a window setting.'];
            }
        }

        $kind = $window['kind'] ?? null;

        if (! is_string($kind) || ! in_array($kind, PriorWindow::KINDS, true)) {
            $errors["{$path}.kind"] = ['Choose one of: '.implode(', ', PriorWindow::KINDS).'.'];

            return null;
        }

        if ($kind !== PriorWindow::CUSTOM_OFFSET) {
            if (isset($window['amount']) || isset($window['unit'])) {
                $errors["{$path}.amount"] = ['Only a custom offset has a number and a unit.'];

                return null;
            }

            return ['kind' => $kind];
        }

        $amount = $window['amount'] ?? null;
        $unit = $window['unit'] ?? null;

        if (! is_int($amount) || $amount < 1 || $amount > PriorWindow::MAX_AMOUNT) {
            $errors["{$path}.amount"] = ['Use a whole number from 1 to '.PriorWindow::MAX_AMOUNT.'.'];
        }

        if (! is_string($unit) || ! in_array($unit, PriorWindow::UNITS, true)) {
            $errors["{$path}.unit"] = ['Choose one of: '.implode(', ', PriorWindow::UNITS).'.'];
        }

        return isset($errors["{$path}.amount"]) || isset($errors["{$path}.unit"]) ? null : ['kind' => $kind, 'amount' => $amount, 'unit' => $unit];
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

        // `equals` is a value match on the status text (Story 5.7): its value is the word to match, not a number.
        $value = $op === 'equals'
            ? self::matchText($band['value'] ?? null, "{$path}.value", $errors)
            : self::decimal($band['value'] ?? null, "{$path}.value", $errors);
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
    private static function matchText(mixed $value, string $path, array &$errors): ?string
    {
        $text = is_string($value) ? trim($value) : null;

        return $text === null ? self::text('', self::MATCH_MAX, $path, $errors) : self::text($text, self::MATCH_MAX, $path, $errors);
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
