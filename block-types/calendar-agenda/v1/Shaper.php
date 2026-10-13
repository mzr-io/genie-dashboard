<?php

namespace BlockTypes\CalendarAgenda\V1;

/**
 * The calendar-agenda v1 Shaper (Story 5.14): a pure function from the row lists to the render payload. A date is a calendar day (the first ten
 * characters of what was mapped, never moved by a time zone), a start time is the wall clock of the workspace as written (`HH:MM`). Rows are in date
 * and time order, equal ones in the order they came. A category is a word, and its rule colour is one of six chosen from the word alone.
 */
final class Shaper
{
    private const STATES = ['normal', 'loading', 'empty', 'stale', 'unavailable', 'error'];

    private const TINTS = 6;

    /**
     * @param  array<string, mixed>  $slots  `event_*`, `start_time`, `duration`, `category` and `row_key` are lists, one entry per row
     * @param  array<string, mixed>  $options  state, `weekStrip`, staleSince, errorMessage, emptyDisplay
     * @return array<string, mixed>
     */
    public function shape(array $slots, array $options = []): array
    {
        $state = in_array($options['state'] ?? null, self::STATES, true) ? $options['state'] : 'normal';
        $lists = [];

        foreach (['event_date', 'start_time', 'event_title', 'event_subtitle', 'duration', 'category', 'row_key'] as $key) {
            $lists[$key] = $this->cells($slots[$key] ?? null);
        }

        $count = max(count($lists['event_date'] ?? []), count($lists['start_time'] ?? []), count($lists['event_title'] ?? []));
        $rows = [];
        $seen = [];
        $partial = false;

        for ($at = 0; $at < $count; $at++) {
            $date = $this->date($lists['event_date'][$at] ?? null);
            $time = $this->time($lists['start_time'][$at] ?? null);
            $title = $lists['event_title'][$at] ?? null;
            $category = $lists['category'][$at] ?? null;
            $partial = $partial || $date === null || $time === null || $title === null;
            $given = $lists['row_key'][$at] ?? null;
            $base = $given !== null
                ? 'k:'.$given
                : 'h:'.hash('sha256', implode("\x1f", [$date ?? "\x00", $time ?? "\x00", $title ?? "\x00", $lists['event_subtitle'][$at] ?? "\x00"]));
            $seen[$base] = ($seen[$base] ?? 0) + 1;
            $rows[] = [
                'key' => $seen[$base] === 1 ? $base : $base.'#'.$seen[$base],
                'date' => $date,
                'time' => $time,
                'title' => $title,
                'subtitle' => $lists['event_subtitle'][$at] ?? null,
                'duration' => $this->minutes($lists['duration'][$at] ?? null),
                'category' => $category,
                'tint' => $category === null ? null : crc32(mb_strtolower(trim($category))) % self::TINTS,
                '_at' => $at,
            ];
        }

        // Date then time; a row with neither comes last; equal rows keep the order they came in.
        usort($rows, fn (array $a, array $b): int => [$a['date'] ?? '9999-99-99', $a['time'] ?? '99:99', $a['_at']] <=> [$b['date'] ?? '9999-99-99', $b['time'] ?? '99:99', $b['_at']]);
        $rows = array_map(function (array $row): array {
            unset($row['_at']);

            return $row;
        }, $rows);

        if ($state === 'normal' && ($lists['event_date'] === null || $lists['start_time'] === null || $lists['event_title'] === null)) {
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
            'weekStrip' => ($options['weekStrip'] ?? false) === true,
            'partial' => $partial,
            'emptyDisplay' => $this->text($options['emptyDisplay'] ?? null),
            'staleSince' => $this->text($options['staleSince'] ?? null),
            'errorMessage' => $this->text($options['errorMessage'] ?? null),
        ];
    }

    private function date(?string $value): ?string
    {
        if ($value === null || preg_match('/\A(\d{4})-(\d{2})-(\d{2})(?:[T ].*)?\z/s', trim($value), $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }

    private function time(?string $value): ?string
    {
        if ($value === null || preg_match('/\A(\d{1,2}):(\d{2})(?::\d{2})?\z/', trim($value), $m) !== 1 || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }

        return str_pad($m[1], 2, '0', STR_PAD_LEFT).':'.$m[2];
    }

    private function minutes(?string $value): ?string
    {
        return $value !== null && preg_match('/\A\d{1,5}(?:\.0+)?\z/', trim($value)) === 1 ? (string) (int) $value : null;
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
