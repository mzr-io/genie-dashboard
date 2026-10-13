<?php

namespace BlockTypes\ProgressList\V1;

use BcMath\Number;

/**
 * The progress-list v1 Shaper (Story 5.13): a pure function from the row lists to the render payload. The percent of a row is its value over its total
 * (computed in decimal, exact to two places, in `percents` when the presentation made them), or the value itself when there is no total. A total of 0 or
 * a missing value has no percent: the Renderer shows a dash and the Block is partial. The bar's fill is the percent held between 0 and 100; the percent
 * itself is never clamped.
 */
final class Shaper
{
    private const STATES = ['normal', 'loading', 'empty', 'stale', 'unavailable', 'error'];

    private const COLORS = ['success', 'warning', 'error', 'info', 'neutral'];

    private const ICON = '/^[a-z0-9]+(-[a-z0-9]+)*\z/';

    private const DECIMAL = '/\A[+-]?(\d+\.?\d*|\.\d+)\z/D';

    /**
     * @param  array<string, mixed>  $slots  `item_title`, `progress_value`, `progress_total`, `item_caption` and `row_key` are lists, one entry per row
     * @param  array<string, mixed>  $options  state, `percents` (per row, made by the presentation), `rowBands`, staleSince, errorMessage, emptyDisplay
     * @return array<string, mixed>
     */
    public function shape(array $slots, array $options = []): array
    {
        $state = in_array($options['state'] ?? null, self::STATES, true) ? $options['state'] : 'normal';
        $titles = $this->cells($slots['item_title'] ?? null);
        $values = $this->cells($slots['progress_value'] ?? null);
        $totals = $this->cells($slots['progress_total'] ?? null);
        $captions = $this->cells($slots['item_caption'] ?? null);
        $keys = $this->cells($slots['row_key'] ?? null);
        $percents = is_array($options['percents'] ?? null) ? array_values($options['percents']) : null;
        $bands = is_array($options['rowBands'] ?? null) ? array_values($options['rowBands']) : [];
        $count = max(count($titles ?? []), count($values ?? []));
        $rows = [];
        $seen = [];
        $partial = false;

        for ($at = 0; $at < $count; $at++) {
            $percent = $this->percent($values[$at] ?? null, $totals === null ? null : ($totals[$at] ?? null), $totals !== null, $percents[$at] ?? null);
            $partial = $partial || $percent === null || ($titles[$at] ?? null) === null;
            $base = ($keys[$at] ?? null) !== null && $keys[$at] !== ''
                ? 'k:'.$keys[$at]
                : 'h:'.hash('sha256', implode("\x1f", [$titles[$at] ?? "\x00", $values[$at] ?? "\x00", $totals[$at] ?? "\x00", $captions[$at] ?? "\x00"]));
            $seen[$base] = ($seen[$base] ?? 0) + 1;
            $rows[] = [
                'key' => $seen[$base] === 1 ? $base : $base.'#'.$seen[$base],
                'title' => $titles[$at] ?? null,
                'caption' => $captions[$at] ?? null,
                'percent' => $percent,
                'fill' => $percent === null ? null : $this->fill($percent),
                'band' => $this->band($bands[$at] ?? null),
            ];
        }

        if ($state === 'normal' && ($titles === null || $values === null)) {
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
            'emptyDisplay' => $this->text($options['emptyDisplay'] ?? null),
            'staleSince' => $this->text($options['staleSince'] ?? null),
            'errorMessage' => $this->text($options['errorMessage'] ?? null),
        ];
    }

    /** The percent as text: from the presentation when it made one, else value over total, else the value itself when no total is mapped. */
    private function percent(?string $value, ?string $total, bool $hasTotal, mixed $made): ?string
    {
        if (is_string($made) && preg_match(self::DECIMAL, $made) === 1) {
            return $made;
        }

        if ($value === null || preg_match(self::DECIMAL, trim($value)) !== 1) {
            return null;
        }

        $value = trim($value);
        assert(is_numeric($value));

        if (! $hasTotal) {
            return $this->trim($value);
        }

        if ($total === null || preg_match(self::DECIMAL, trim($total)) !== 1) {
            return null;
        }

        $divisor = trim($total);
        assert(is_numeric($divisor));

        if ((new Number($divisor))->compare(new Number('0')) === 0) {
            return null;
        }

        return $this->trim((string) (new Number($value))->mul(new Number('100'))->div(new Number($divisor), 6)->round(2));
    }

    /** The bar's width, 0 to 100: a percent above 100 fills the track, one below 0 leaves it empty. */
    private function fill(string $percent): string
    {
        /** @var numeric-string $percent */
        $number = new Number($percent);

        if ($number->compare(new Number('0')) < 0) {
            return '0';
        }

        return $number->compare(new Number('100')) > 0 ? '100' : $this->trim($percent);
    }

    private function trim(string $number): string
    {
        $number = ltrim($number, '+');

        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
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

    /** @return list<string|null>|null */
    private function cells(mixed $values): ?array
    {
        if (! is_array($values)) {
            return null;
        }

        $out = [];

        foreach (array_values($values) as $value) {
            $out[] = match (true) {
                is_int($value) => (string) $value,
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
}
