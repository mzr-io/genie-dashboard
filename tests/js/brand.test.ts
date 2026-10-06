import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import {
    BRAND_TOKENS,
    contrastRatio,
    parseTokens,
    deriveOnAccent,
    resolveBrand,
} from '../../resources/js/lib/brand';

const t = parseTokens(readFileSync('resources/css/tokens.css', 'utf8'));

describe('on-accent derivation (UX-DR-284)', () => {
    it('picks dark ink on a light accent', () => {
        expect(deriveOnAccent('#FACC15', t)).toBe('#111827');
    });

    it('picks white on a dark accent', () => {
        expect(deriveOnAccent('#1E3A8A', t)).toBe('#ffffff');
    });

    it('always has at least the higher of the two contrasts', () => {
        for (const accent of [
            '#FACC15',
            '#1E3A8A',
            '#16A34A',
            '#DC2626',
            '#888888',
        ]) {
            const chosen = deriveOnAccent(accent, t);
            const other =
                chosen === t['text-primary']
                    ? t['on-status']
                    : t['text-primary'];

            expect(contrastRatio(chosen, accent)).toBeGreaterThanOrEqual(
                contrastRatio(other, accent),
            );
        }
    });
});

describe('brand override (UX-DR-283)', () => {
    it('changes brand and logo tokens and derives on-accent', () => {
        const brand = resolveBrand(
            {
                accent: '#1E3A8A',
                'logo-tile': '#1E3A8A',
                'logo-mark': '#FFFFFF',
            },
            t,
        );

        expect(brand['--df-accent']).toBe('#1E3A8A');
        expect(brand['--df-logo-tile']).toBe('#1E3A8A');
        expect(brand['--df-logo-mark']).toBe('#FFFFFF');
        expect(brand['--df-on-accent']).toBe('#ffffff');
    });

    it('never emits a system token, even when asked', () => {
        const brand = resolveBrand(
            {
                accent: '#FACC15',
                'surface-card': '#000000',
                'focus-ring': '#FACC15',
                'text-primary': '#FFFFFF',
            } as never,
            t,
        );
        const allowed = new Set(BRAND_TOKENS.map((token) => `--df-${token}`));

        expect(Object.keys(brand).every((name) => allowed.has(name))).toBe(
            true,
        );
        expect(brand).not.toHaveProperty('--df-surface-card');
        expect(brand).not.toHaveProperty('--df-focus-ring');
    });

    it('ignores invalid colours without throwing', () => {
        expect(() =>
            resolveBrand({ accent: 'banana', 'accent-ink': '' }, t),
        ).not.toThrow();
        expect(
            resolveBrand({ accent: 'banana' }, t)['--df-accent'],
        ).toBeUndefined();
    });
});

describe('accent-ink guardrail (UX-DR-284)', () => {
    it('keeps a strong override', () => {
        expect(
            resolveBrand({ accent: '#3B82F6', 'accent-ink': '#1E3A8A' }, t)[
                '--df-accent-ink'
            ],
        ).toBe('#1E3A8A');
    });

    it('falls back to the default when below 4.5:1 on white', () => {
        expect(
            resolveBrand({ 'accent-ink': '#FACC15' }, t)['--df-accent-ink'],
        ).toBeUndefined();
    });

    it('falls back when below 4.5:1 on its own accent-soft', () => {
        // Passes on white (4.6) but fails on this darker soft fill.
        const brand = resolveBrand(
            {
                'accent-ink': '#767676',
                'accent-soft': '#999999',
            },
            t,
        );

        expect(contrastRatio('#767676', '#ffffff')).toBeGreaterThanOrEqual(4.5);
        expect(brand['--df-accent-ink']).toBeUndefined();
        expect(brand['--df-accent-soft']).toBe('#999999');
    });

    it('falls back for a weak accent-ink-inverse', () => {
        expect(
            resolveBrand({ 'accent-ink-inverse': '#333333' }, t)[
                '--df-accent-ink-inverse'
            ],
        ).toBeUndefined();
        expect(
            resolveBrand({ 'accent-ink-inverse': '#FFFFFF' }, t)[
                '--df-accent-ink-inverse'
            ],
        ).toBe('#FFFFFF');
    });
});
