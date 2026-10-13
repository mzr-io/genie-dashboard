<?php

namespace BlockTypes\Table\V1;

/**
 * The table v1 Shaper (Story 5.12): a pure function from the column lists to the render payload. Each column's header, format, alignment, width and
 * sortable flag come from the presentation rules; a row has a stable key (the mapped key column, else a hash of its cells, made unique by its
 * occurrence), the cells as received (a missing value is a null shown as "—", never 0) and the band its highlight value fell in. Nothing is formatted.
 */
final class Shaper
{
    private const STATES = ['normal', 'loading', 'empty', 'stale', 'unavailable', 'error'];

    private const KINDS = ['number', 'percent', 'currency', 'compact', 'date'];

    private const COLUMNS = 12;

    private const COLORS = ['success', 'warning', 'error', 'info', 'neutral'];

    private const ICON = '/^[a-z0-9]+(-[a-z0-9]+)*\z/';

    /**
     * @param  array<string, mixed>  $slots  `col_1` to `col_12` and `row_key` are lists, one entry per row
     * @param  array<string, mixed>  $options  state, `columnRules` (slot => {format, label, align, width, sortable}), `pageSize`, `rowBands`, `fieldNames`, `period`, staleSince, errorMessage, emptyDisplay
     * @return array<string, mixed>
     */
    public function shape(array $slots, array $options = []): array
    {
        $state = in_array($options['state'] ?? null, self::STATES, true) ? $options['state'] : 'normal';
        $rules = is_array($options['columnRules'] ?? null) ? $options['columnRules'] : [];
        $names = is_array($options['fieldNames'] ?? null) ? $options['fieldNames'] : [];
        $columns = [];
        $lists = [];

        for ($n = 1; $n <= self::COLUMNS; $n++) {
            $key = "col_{$n}";
            $values = $this->cells($slots[$key] ?? null);

            if ($values === null) {
                continue;
            }

            $rule = is_array($rules[$key] ?? null) ? $rules[$key] : [];
            $label = is_array($rule['label'] ?? null) ? $this->text($rule['label']['text'] ?? null) : null;
            $format = is_array($rule['format'] ?? null) ? $this->descriptor($rule['format']) : null;
            $columns[] = [
                'key' => $key,
                'header' => $label ?? $this->text($names[$key] ?? null) ?? "Column {$n}",
                'align' => in_array($rule['align'] ?? null, ['left', 'right', 'center'], true) ? $rule['align'] : ($format !== null && $format['kind'] !== 'date' ? 'right' : 'left'),
                'width' => is_int($rule['width'] ?? null) && $rule['width'] > 0 ? $rule['width'] : null,
                'sortable' => ($rule['sortable'] ?? false) === true,
                'format' => $format,
            ];
            $lists[] = $values;
        }

        $count = 0;

        foreach ($lists as $list) {
            $count = max($count, count($list));
        }

        $keys = $this->cells($slots['row_key'] ?? null);
        $bands = is_array($options['rowBands'] ?? null) ? array_values($options['rowBands']) : [];
        $rows = [];
        $seen = [];
        $partial = false;

        for ($at = 0; $at < $count; $at++) {
            $cells = [];

            foreach ($lists as $list) {
                $cell = $list[$at] ?? null;
                $cells[] = $cell;
                $partial = $partial || $cell === null;
            }

            $base = $keys !== null && ($keys[$at] ?? null) !== null && $keys[$at] !== ''
                ? 'k:'.$keys[$at]
                : 'h:'.hash('sha256', implode("\x1f", array_map(fn (?string $c): string => $c ?? "\x00", $cells)));
            $seen[$base] = ($seen[$base] ?? 0) + 1;
            $rows[] = [
                'key' => $seen[$base] === 1 ? $base : $base.'#'.$seen[$base],
                'cells' => $cells,
                'highlight' => $this->band($bands[$at] ?? null),
            ];
        }

        if ($state === 'normal' && $columns === []) {
            $state = 'unavailable';
        } elseif ($state === 'normal' && $rows === []) {
            $state = 'empty';
        }

        $size = $options['pageSize'] ?? null;
        $period = is_array($options['period'] ?? null) ? $options['period'] : null;

        return [
            'state' => $state,
            'title' => $this->text($slots['title'] ?? null),
            'subtitle' => $this->text($slots['subtitle'] ?? null),
            'icon' => $this->text($slots['icon'] ?? null),
            'footerAction' => $this->text($slots['footer_action'] ?? null),
            'columns' => $columns,
            'rows' => $rows,
            'partial' => $partial,
            'pageSize' => is_int($size) && $size >= 1 && $size <= 100 ? $size : 10,
            'periodKey' => $period === null ? null : ($this->text($period['start'] ?? null) ?? '').'..'.($this->text($period['end'] ?? null) ?? ''),
            'emptyDisplay' => $this->text($options['emptyDisplay'] ?? null),
            'staleSince' => $this->text($options['staleSince'] ?? null),
            'errorMessage' => $this->text($options['errorMessage'] ?? null),
        ];
    }

    /** @return array{text: string, color: string, icon: string}|null */
    private function band(mixed $band): ?array
    {
        if (! is_array($band)) {
            return null;
        }

        $text = $this->text($band['text'] ?? null);
        $color = $band['color'] ?? null;
        $icon = $band['icon'] ?? null;

        if ($text === null || ! in_array($color, self::COLORS, true) || ! is_string($icon) || preg_match(self::ICON, $icon) !== 1) {
            return null;
        }

        return ['text' => $text, 'color' => $color, 'icon' => $icon];
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
                is_string($value) => $value,
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

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function descriptor(array $input): array
    {
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
