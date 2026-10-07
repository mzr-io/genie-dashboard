import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { contrastRatio, parseTokens } from '../../resources/js/lib/brand';

const raw = parseTokens(readFileSync('resources/css/tokens.css', 'utf8'));

// Resolve var(--df-*) references so followers such as chart-1-stroke are tested as themselves.
const t: Record<string, string> = Object.fromEntries(
    Object.keys(raw).map((name) => {
        let value = raw[name];

        for (let hops = 0; hops < 5; hops++) {
            const ref = /^var\(--df-([\w-]+)\)$/.exec(value)?.[1];

            if (!ref) {
                break;
            }

            value = raw[ref];
        }

        return [name, value];
    }),
);

type Pair = [string, string, string, number];

// [description, foreground, background, minimum ratio]; values are token names, resolved from the token file.
const PAIRS: Pair[] = [
    // UX-DR-1
    ['accent-ink on white', 'accent-ink', 'surface-card', 4.92],
    ['accent-ink on accent-soft', 'accent-ink', 'accent-soft', 4.58],
    ['accent-ink on canvas', 'accent-ink', 'surface-canvas', 4.63],
    ['accent-ink on accent-wash', 'accent-ink', 'accent-wash', 4.75],
    ['accent-ink-strong on white', 'accent-ink-strong', 'surface-card', 6.85],
    [
        'accent-ink-inverse on surface-inverse',
        'accent-ink-inverse',
        'surface-inverse',
        13.46,
    ],
    [
        'accent-ink-inverse on hero-chip',
        'accent-ink-inverse',
        'hero-chip',
        13.01,
    ],
    ['on-accent on accent', 'on-accent', 'accent', 11.58],
    // UX-DR-2
    ['text-primary on white', 'text-primary', 'surface-card', 17.74],
    ['text-secondary on white', 'text-secondary', 'surface-card', 10.31],
    ['text-secondary on muted', 'text-secondary', 'surface-muted', 9.37],
    ['text-muted on white', 'text-muted', 'surface-card', 4.83],
    ['text-muted on canvas', 'text-muted', 'surface-canvas', 4.55],
    ['border-control on white', 'border-control', 'surface-card', 3.67],
    ['border-control on canvas', 'border-control', 'surface-canvas', 3.45],
    ['border-control on sunken', 'border-control', 'surface-sunken', 3.51],
    ['border-control on muted', 'border-control', 'surface-muted', 3.33],
    ['focus-ring on white', 'focus-ring', 'surface-card', 5.17],
    ['focus-ring on surface-inverse', 'focus-ring', 'surface-inverse', 3.43],
    ['focus-ring on accent', 'focus-ring', 'accent', 3.38],
    // UX-DR-3
    ['success-text on white', 'success-text', 'surface-card', 5.02],
    ['success on white', 'success', 'surface-card', 3.3],
    ['error on white', 'error', 'surface-card', 4.83],
    ['error-text on error-soft', 'error-text', 'error-soft', 5.91],
    ['text-secondary on error-soft', 'text-secondary', 'error-soft', 9.42],
    ['warning on white', 'warning', 'surface-card', 5.18],
    ['warning on warning-soft', 'warning', 'warning-soft', 4.52],
    ['info on white', 'info', 'surface-card', 6.7],
    ['info-strong on info-soft', 'info-strong', 'info-soft', 7.15],
    // UX-DR-4
    ['hero-text on hero-surface', 'hero-text', 'hero-surface', 19.38],
    [
        'hero-text-muted on hero-surface',
        'hero-text-muted',
        'hero-surface',
        6.73,
    ],
    // UX-DR-5: each role text on its fill
    ...['label', 'value', 'dimension', 'measure', 'time', 'filter'].map(
        (role): Pair => [
            `role-${role} text on fill`,
            `role-${role}-text`,
            `role-${role}-fill`,
            4.5,
        ],
    ),
    // UX-DR-6: series strokes on white
    ['chart-1-stroke', 'chart-1-stroke', 'surface-card', 4.92],
    ['chart-2', 'chart-2', 'surface-card', 5.03],
    ['chart-3', 'chart-3', 'surface-card', 5.47],
    ['chart-4', 'chart-4', 'surface-card', 4.6],
    ['chart-5', 'chart-5', 'surface-card', 3.56],
    ['chart-6', 'chart-6', 'surface-card', 4.83],
    // UX-DR-13 non-text
    [
        'checked checkbox edge on white',
        'accent-ink-strong',
        'surface-card',
        6.85,
    ],
    [
        'checked checkbox edge next to accent',
        'accent-ink-strong',
        'accent',
        4.47,
    ],
    [
        'selected border on accent-soft',
        'accent-ink-strong',
        'accent-soft',
        6.38,
    ],
    ['active-option ring on accent-soft', 'focus-ring', 'accent-soft', 4.81],
    ['required-missing rule on error-soft', 'error', 'error-soft', 4.41],
];

describe('contrast pairs (UX-DR-1..6, 13)', () => {
    it.each(PAIRS)(
        '%s is at least %s',
        (_name, foreground, background, expected) => {
            const ratio = contrastRatio(t[foreground], t[background]);

            // The documented figures are rounded to two decimals; allow that rounding only.
            expect(ratio).toBeGreaterThanOrEqual(expected - 0.01);
        },
    );

    it('keeps every text pair at or above 4.5:1 and every non-text pair at or above 3:1', () => {
        const nonText = new Set([
            'success on white',
            'chart-5',
            'checked checkbox edge next to accent',
            'focus-ring on accent',
            'focus-ring on surface-inverse',
            'border-control on white',
            'border-control on canvas',
            'border-control on sunken',
            'border-control on muted',
            'required-missing rule on error-soft',
        ]);

        for (const [name, foreground, background] of PAIRS) {
            const ratio = contrastRatio(t[foreground], t[background]);
            expect(ratio, name).toBeGreaterThanOrEqual(
                nonText.has(name) ? 3 : 4.5,
            );
        }
    });

    it('computes known ratios', () => {
        expect(contrastRatio('#000000', '#ffffff')).toBeCloseTo(21, 5);
        expect(contrastRatio('#ffffff', '#ffffff')).toBeCloseTo(1, 5);
        expect(contrastRatio('nope', '#ffffff')).toBe(0);
    });
});
