<?php

namespace BlockTypes\ActivityFeed\V1;

/**
 * The activity-feed v1 Shaper (Story 5.14): a pure function from the row lists to the render payload. A row is an actor, an action, an optional object
 * and a timestamp, with an avatar from the name alone. The timestamp is kept as an instant string when it parses and is null when it does not (the
 * Renderer writes "—" and the Block is partial); "how long ago" is the viewer's locale and clock, so it is never written here.
 */
final class Shaper
{
    private const STATES = ['normal', 'loading', 'empty', 'stale', 'unavailable', 'error'];

    private const TINTS = 5;

    private const INSTANT = '/\A(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?\z/';

    /**
     * @param  array<string, mixed>  $slots  `actor`, `action`, `object`, `timestamp`, `avatar` and `row_key` are lists, one entry per row
     * @param  array<string, mixed>  $options  state, staleSince, errorMessage, emptyDisplay
     * @return array<string, mixed>
     */
    public function shape(array $slots, array $options = []): array
    {
        $state = in_array($options['state'] ?? null, self::STATES, true) ? $options['state'] : 'normal';
        $lists = [];

        foreach (['actor', 'action', 'object', 'timestamp', 'avatar', 'row_key'] as $key) {
            $lists[$key] = $this->cells($slots[$key] ?? null);
        }

        $count = max(count($lists['actor'] ?? []), count($lists['action'] ?? []), count($lists['timestamp'] ?? []));
        $rows = [];
        $seen = [];
        $partial = false;

        for ($at = 0; $at < $count; $at++) {
            $raw = $lists['timestamp'][$at] ?? null;
            $stamp = $raw === null ? null : $this->instant($raw);
            $actor = $lists['actor'][$at] ?? null;
            $action = $lists['action'][$at] ?? null;
            $partial = $partial || $stamp === null || $actor === null || $action === null;
            $given = $lists['row_key'][$at] ?? null;
            $base = $given !== null
                ? 'k:'.$given
                : 'h:'.hash('sha256', implode("\x1f", [$actor ?? "\x00", $action ?? "\x00", $lists['object'][$at] ?? "\x00", $raw ?? "\x00"]));
            $seen[$base] = ($seen[$base] ?? 0) + 1;
            $rows[] = [
                'key' => $seen[$base] === 1 ? $base : $base.'#'.$seen[$base],
                'actor' => $actor,
                'action' => $action,
                'object' => $lists['object'][$at] ?? null,
                'timestamp' => $stamp,
                'avatar' => $this->avatar($lists['avatar'][$at] ?? null),
            ];
        }

        if ($state === 'normal' && ($lists['actor'] === null || $lists['action'] === null || $lists['timestamp'] === null)) {
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

    /** The timestamp as it was written when it is a real date (and time), else null. A date with no time stays a date. */
    private function instant(string $value): ?string
    {
        $value = trim($value);

        if (preg_match(self::INSTANT, $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        if (isset($m[4]) && $m[4] !== '' && ((int) $m[4] > 23 || (int) ($m[5] ?? 0) > 59 || (int) ($m[6] ?? 0) > 59)) {
            return null;
        }

        return $value;
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

        return ['initials' => $first.$last, 'tint' => crc32(mb_strtolower($name)) % self::TINTS];
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
