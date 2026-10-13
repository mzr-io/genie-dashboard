<?php

namespace BlockTypes\TextStatus\V1;

/**
 * The text-status v1 Shaper (Story 5.14): a pure function from the text slot to the render payload. In Text mode the text is as written (a Text Template
 * is the slot's mapping). In Status mode the value is a word in a pill: the band it matched (by value or by match) gives the colour and icon, and one
 * that matched none is shown as neutral text, the word and an icon always present.
 */
final class Shaper
{
    private const STATES = ['normal', 'loading', 'empty', 'stale', 'unavailable', 'error'];

    private const COLORS = ['success', 'warning', 'error', 'info', 'neutral'];

    private const ICON = '/^[a-z0-9]+(-[a-z0-9]+)*\z/';

    /**
     * @param  array<string, mixed>  $slots  `status_text`
     * @param  array<string, mixed>  $options  state, `textMode`, `statusBand`, staleSince, errorMessage, emptyDisplay
     * @return array<string, mixed>
     */
    public function shape(array $slots, array $options = []): array
    {
        $state = in_array($options['state'] ?? null, self::STATES, true) ? $options['state'] : 'normal';
        $mode = ($options['textMode'] ?? null) === 'status' ? 'status' : 'text';
        $text = $this->text($slots['status_text'] ?? null);

        if ($state === 'normal' && $text === null) {
            $state = array_key_exists('status_text', $slots) && $slots['status_text'] === null ? 'unavailable' : 'empty';
        }

        return [
            'state' => $state,
            'mode' => $mode,
            'title' => $this->text($slots['title'] ?? null),
            'subtitle' => $this->text($slots['subtitle'] ?? null),
            'icon' => $this->text($slots['icon'] ?? null),
            'footerAction' => $this->text($slots['footer_action'] ?? null),
            'text' => $text,
            'band' => $mode === 'status' ? $this->band($options['statusBand'] ?? null) : null,
            'partial' => false,
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

    private function text(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
