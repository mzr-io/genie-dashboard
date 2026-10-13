<?php

namespace BlockTypes\ItemList\V1;

/**
 * The list v1 Shaper (Story 5.13): a pure function from the row lists to the render payload. A row has a stable key (the mapped key column, else a hash
 * of its cells, made unique by its occurrence), its texts as received, and an avatar: the initials of the mapped name and one of five tints chosen by
 * the name alone, so a person has the same tint in every Block. Nothing is formatted; a missing right value or meta is a null the Renderer leaves out.
 */
final class Shaper
{
    private const STATES = ['normal', 'loading', 'empty', 'stale', 'unavailable', 'error'];

    private const KINDS = ['number', 'percent', 'currency', 'compact', 'date'];

    private const ICON = '/^[a-z0-9]+(-[a-z0-9]+)*\z/';

    private const TINTS = 5;

    /**
     * @param  array<string, mixed>  $slots  the `item_*` slots and `row_key` are lists, one entry per row
     * @param  array<string, mixed>  $options  state, `columnRules` (slot => {format}), staleSince, errorMessage, emptyDisplay
     * @return array<string, mixed>
     */
    public function shape(array $slots, array $options = []): array
    {
        $state = in_array($options['state'] ?? null, self::STATES, true) ? $options['state'] : 'normal';
        $rules = is_array($options['columnRules'] ?? null) ? $options['columnRules'] : [];
        $lists = [];

        foreach (['item_title', 'item_subtitle', 'item_avatar', 'item_icon', 'item_value', 'item_meta', 'item_status', 'row_key'] as $key) {
            $lists[$key] = $this->cells($slots[$key] ?? null);
        }

        $count = 0;

        foreach (['item_title', 'item_subtitle', 'item_avatar', 'item_icon', 'item_value', 'item_meta', 'item_status'] as $key) {
            $count = max($count, count($lists[$key] ?? []));
        }

        $rows = [];
        $seen = [];
        $partial = false;

        for ($at = 0; $at < $count; $at++) {
            $row = [
                'title' => $lists['item_title'][$at] ?? null,
                'subtitle' => $lists['item_subtitle'][$at] ?? null,
                'avatar' => $this->avatar($lists['item_avatar'][$at] ?? null),
                'icon' => $this->icon($lists['item_icon'][$at] ?? null),
                'value' => $lists['item_value'][$at] ?? null,
                'meta' => $lists['item_meta'][$at] ?? null,
                'status' => $lists['item_status'][$at] ?? null,
            ];
            // An item with no title is a gap in the data, said by the badge.
            $partial = $partial || $row['title'] === null;
            $given = $lists['row_key'][$at] ?? null;
            $base = $given !== null && $given !== ''
                ? 'k:'.$given
                : 'h:'.hash('sha256', implode("\x1f", array_map(fn (mixed $c): string => is_array($c) ? $c['initials'] : (string) ($c ?? "\x00"), $row)));
            $seen[$base] = ($seen[$base] ?? 0) + 1;
            $rows[] = ['key' => $seen[$base] === 1 ? $base : $base.'#'.$seen[$base]] + $row;
        }

        $hasTitles = ($lists['item_title'] ?? null) !== null;

        if ($state === 'normal' && ! $hasTitles) {
            $state = 'unavailable';
        } elseif ($state === 'normal' && $rows === []) {
            $state = 'empty';
        }

        return [
            'state' => $state,
            'title' => $this->text($slots['title'] ?? null),
            'subtitle' => $this->text($slots['subtitle'] ?? null),
            'icon' => $this->text($slots['icon'] ?? null),
            'footerAction' => $this->text($slots['footer_action'] ?? null),
            'rows' => $rows,
            'partial' => $partial,
            'valueFormat' => $this->descriptor($rules['item_value']['format'] ?? null),
            'metaFormat' => $this->descriptor($rules['item_meta']['format'] ?? null),
            'emptyDisplay' => $this->text($options['emptyDisplay'] ?? null),
            'staleSince' => $this->text($options['staleSince'] ?? null),
            'errorMessage' => $this->text($options['errorMessage'] ?? null),
        ];
    }

    /** @return array{initials: string, tint: int}|null */
    private function avatar(?string $name): ?array
    {
        $name = $name === null ? '' : trim($name);

        if ($name === '') {
            return null;
        }

        $words = array_values(array_filter(preg_split('/[\s._-]+/u', $name) ?: [], fn (string $w): bool => $w !== ''));
        $first = mb_strtoupper(mb_substr($words[0] ?? '', 0, 1));
        $last = count($words) > 1 ? mb_strtoupper(mb_substr($words[count($words) - 1], 0, 1)) : '';

        // The tint is a function of the name alone (case and surrounding space ignored): the same person is the same colour everywhere.
        return ['initials' => $first.$last, 'tint' => crc32(mb_strtolower($name)) % self::TINTS];
    }

    private function icon(?string $name): ?string
    {
        return $name !== null && preg_match(self::ICON, $name) === 1 ? $name : null;
    }

    /** @return list<string|null>|null a cell as text (a number as its digits, a boolean as true or false), null where a row has none */
    private function cells(mixed $values): ?array
    {
        if (! is_array($values)) {
            return null;
        }

        $out = [];

        foreach (array_values($values) as $value) {
            $out[] = match (true) {
                is_int($value) => (string) $value,
                is_bool($value) => $value ? 'true' : 'false',
                is_string($value) && $value !== '' => $value,
                default => null,
            };
        }

        return $out;
    }

    private function text(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string, mixed>|null */
    private function descriptor(mixed $input): ?array
    {
        if (! is_array($input)) {
            return null;
        }

        $places = $input['decimalPlaces'] ?? null;
        $code = $input['currencyCode'] ?? null;

        return [
            'kind' => in_array($input['kind'] ?? null, self::KINDS, true) ? $input['kind'] : 'number',
            'decimalPlaces' => is_int($places) && $places >= 0 && $places <= 20 ? $places : null,
            'thousandsSeparator' => ($input['thousandsSeparator'] ?? true) !== false,
            'currencyCode' => is_string($code) && preg_match('/^[A-Za-z]{3}\z/', $code) === 1 ? strtoupper($code) : null,
            'currencyDisplay' => ($input['currencyDisplay'] ?? null) === 'code' ? 'code' : 'symbol',
            'datePattern' => in_array($input['datePattern'] ?? null, ['short', 'medium', 'long', 'full', 'MMM', 'MMM yyyy', 'MMM d'], true) ? $input['datePattern'] : 'medium',
            'unitsSuffix' => is_string($input['unitsSuffix'] ?? null) && $input['unitsSuffix'] !== '' ? $input['unitsSuffix'] : null,
        ];
    }
}
