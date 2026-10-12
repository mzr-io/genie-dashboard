// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Renderer from '@block-types/kpi-card/v1/Renderer.vue';
import { shiftDecimal } from '@block-types/kpi-card/v1/display';
import type {
    FormatDescriptor,
    KpiCardPayload,
} from '@block-types/kpi-card/v1/payload';
import { STILL_LOADING_MS } from '../../resources/js/composables/useStillLoading';
import {
    configureFormatting,
    formatDuration,
    formatRelativeTime,
} from '../../resources/js/lib/format';
import { createCatalogue } from '../../resources/js/lib/i18n';
import { kpiCardLabels } from '../../resources/js/locales/labels';

// Story 3.2: every state of the kpi-card renderer is a fixture; measures stay decimal strings; every slot is literal text.
const descriptor = (
    over: Partial<FormatDescriptor> = {},
): FormatDescriptor => ({
    kind: 'number',
    decimalPlaces: null,
    thousandsSeparator: true,
    currencyCode: null,
    currencyDisplay: 'symbol',
    datePattern: 'medium',
    unitsSuffix: null,
    ...over,
});

const payload = (over: Partial<KpiCardPayload> = {}): KpiCardPayload => ({
    state: 'normal',
    title: 'Revenue',
    subtitle: null,
    icon: null,
    footerAction: null,
    kpiLabel: 'Net revenue',
    headline: {
        available: true,
        value: '284680.10',
        format: descriptor({ kind: 'currency', currencyCode: 'USD' }),
    },
    comparison: null,
    comparisonLabel: null,
    secondaryNote: null,
    statusBadge: null,
    staleSince: null,
    errorMessage: null,
    ...over,
});

const comparison = (
    value: string,
    direction: 'up' | 'down' | 'flat',
    lowerIsBetter = false,
): KpiCardPayload['comparison'] => ({
    value,
    direction,
    lowerIsBetter,
    format: descriptor({ kind: 'percent', decimalPlaces: 1 }),
});

const render = (
    p: KpiCardPayload,
    props: { audience?: 'user' | 'admin'; workspaceLocale?: string } = {},
) =>
    mount(Renderer, {
        props: { payload: p, ...props },
        global: { plugins: [createCatalogue()] },
    });

beforeEach(() => configureFormatting({ locale: 'en', timeZone: 'UTC' }));
afterEach(() => vi.useRealTimers());

describe('kpi-card renderer', () => {
    it('formats currency exactly', () => {
        const w = render(payload());

        expect(w.get('[data-test=kpi-headline]').text()).toBe('$284,680.10');
        expect(w.get('[data-test=kpi-label]').text()).toBe('Net revenue');
    });

    it('keeps every digit of a 20-digit decimal', () => {
        const w = render(
            payload({
                headline: {
                    available: true,
                    value: '12345678901234567.890',
                    format: descriptor({ thousandsSeparator: false }),
                },
            }),
        );

        expect(w.get('[data-test=kpi-headline]').text()).toBe(
            '12345678901234567.89',
        );

        const exact = render(
            payload({
                headline: {
                    available: true,
                    value: '98765432109876543210',
                    format: descriptor({ thousandsSeparator: false }),
                },
            }),
        );

        expect(exact.get('[data-test=kpi-headline]').text()).toBe(
            '98765432109876543210',
        );
    });

    it('applies decimal places, percent, compact and a units suffix', () => {
        const text = (format: FormatDescriptor, value = '1234.5678') =>
            render(payload({ headline: { available: true, value, format } }))
                .get('[data-test=kpi-headline]')
                .text();

        expect(text(descriptor({ decimalPlaces: 1 }))).toBe('1,234.6');
        expect(
            text(descriptor({ kind: 'percent', decimalPlaces: 1 }), '12.5'),
        ).toBe('12.5%');
        expect(text(descriptor({ kind: 'compact' }), '1500000')).toBe('1.5M');
        expect(text(descriptor({ unitsSuffix: 'kg' }), '3')).toBe('3 kg');
        expect(text(descriptor({ kind: 'duration' }), '3903')).toContain('1');
        expect(
            text(
                descriptor({ kind: 'date', datePattern: 'medium' }),
                '2026-10-07',
            ),
        ).toBe('Oct 7, 2026');
    });

    it('uses the workspace locale only when the person saved none', () => {
        configureFormatting({ locale: null, timeZone: 'UTC' });

        const de = payload({
            headline: {
                available: true,
                value: '1234.5',
                format: descriptor(),
            },
        });

        expect(
            render(de, { workspaceLocale: 'de' })
                .get('[data-test=kpi-headline]')
                .text(),
        ).toBe('1.234,5');

        configureFormatting({ locale: 'en', timeZone: 'UTC' });
        // An explicit user locale wins.
        expect(
            render(de, { workspaceLocale: 'de' })
                .get('[data-test=kpi-headline]')
                .text(),
        ).toBe('1,234.5');
    });

    it('shifts decimals as strings', () => {
        expect(shiftDecimal('12.5', -2)).toBe('0.125');
        expect(shiftDecimal('-5', -2)).toBe('-0.05');
        expect(shiftDecimal('1e2', -2)).toBe('1');
    });

    describe('delta', () => {
        it.each([
            ['up', '12.5', '▲'],
            ['down', '-3.25', '▼'],
        ] as const)(
            'shows an arrow and the value for %s',
            (direction, value, arrow) => {
                const w = render(
                    payload({
                        comparison: comparison(value, direction),
                        comparisonLabel: 'vs last month',
                    }),
                );
                const visible = w.get('[data-test=kpi-delta-visible]');

                expect(visible.attributes('aria-hidden')).toBe('true');
                expect(w.get('[data-test=kpi-delta-arrow]').text()).toBe(arrow);
                expect(visible.text()).toContain(
                    direction === 'up' ? '12.5%' : '3.3%',
                );
                expect(visible.text()).toContain('vs last month');
                expect(
                    w.get('[data-test=kpi-delta-spoken]').classes(),
                ).toContain('sr-only');
                expect(w.get('[data-test=kpi-delta-spoken]').text()).toContain(
                    direction === 'up' ? 'Up 12.5%' : 'Down 3.3%',
                );
            },
        );

        it('says "No change" when flat', () => {
            const w = render(
                payload({ comparison: comparison('0.00', 'flat') }),
            );

            expect(w.get('[data-test=kpi-delta-visible]').text()).toBe(
                'No change',
            );
            expect(w.find('[data-test=kpi-delta-arrow]').exists()).toBe(false);
        });

        it('appends better or worse when lower is better, and states it in hidden text', () => {
            const better = render(
                payload({ comparison: comparison('-4', 'down', true) }),
            );

            expect(
                better.get('[data-test=kpi-delta-visible]').text(),
            ).toContain('· better');
            expect(better.get('[data-test=kpi-delta]').classes()).toContain(
                'text-success-text',
            );
            expect(better.get('[data-test=kpi-delta-spoken]').text()).toContain(
                'better',
            );

            const worse = render(
                payload({ comparison: comparison('4', 'up', true) }),
            );

            expect(worse.get('[data-test=kpi-delta-visible]').text()).toContain(
                '· worse',
            );
            expect(worse.get('[data-test=kpi-delta]').classes()).toContain(
                'text-error-text',
            );
            expect(worse.get('[data-test=kpi-delta-spoken]').text()).toContain(
                'worse',
            );
        });
    });

    describe('states', () => {
        it('unavailable never shows 0, NaN or a dash; user and admin messages differ', () => {
            const missing = payload({
                state: 'unavailable',
                headline: { available: false, field: 'Headline value' },
            });
            const user = render(missing);
            const admin = render(missing, { audience: 'admin' });

            expect(user.get('[data-test=kpi-unavailable]').text()).toBe(
                "Unavailable — this value can't be shown right now.",
            );
            expect(admin.get('[data-test=kpi-unavailable]').text()).toBe(
                'Unavailable: Headline value missing',
            );
            expect(user.find('[data-test=kpi-headline]').exists()).toBe(false);
            expect(user.text()).not.toMatch(/\bNaN\b|^0$/);
        });

        it('a normal state with an unformattable headline is unavailable, not zero', () => {
            const w = render(
                payload({
                    headline: {
                        available: true,
                        value: 'not-a-number',
                        format: descriptor(),
                    },
                }),
            );

            expect(w.find('[data-test=kpi-unavailable]').exists()).toBe(true);
            expect(w.find('[data-test=kpi-headline]').exists()).toBe(false);
        });

        it('loading shows skeleton bars, then a caption after the wait', async () => {
            vi.useFakeTimers();
            const w = render(payload({ state: 'loading' }));

            expect(w.find('[data-test=kpi-loading]').exists()).toBe(true);
            expect(w.findAll('[data-slot=skeleton]').length).toBeGreaterThan(1);
            expect(w.text()).not.toContain(kpiCardLabels.empty);
            expect(w.text()).not.toContain('Still loading');

            vi.advanceTimersByTime(STILL_LOADING_MS + 10);
            await w.vm.$nextTick();

            expect(w.text()).toContain('Still loading…');
            expect(
                w
                    .findAll('[data-slot=skeleton]')
                    .every((b) => b.attributes('data-shimmer') === 'false'),
            ).toBe(true);
        });

        it('empty shows a muted caption', () => {
            const w = render(payload({ state: 'empty' }));

            expect(w.get('[data-test=kpi-empty]').text()).toBe(
                kpiCardLabels.empty,
            );
            expect(w.get('[data-test=kpi-empty]').classes()).toContain(
                'text-text-muted',
            );
        });

        it('stale mutes the value and shows a clock and the stale message', () => {
            const w = render(
                payload({ state: 'stale', staleSince: '2020-01-02T09:30:00Z' }),
            );

            expect(w.get('[data-test=kpi-headline]').classes()).toContain(
                'text-text-muted',
            );
            expect(w.get('[data-test=kpi-stale]').classes()).toContain(
                'text-warning',
            );
            expect(
                w.get('[data-test=kpi-stale] svg').attributes('aria-hidden'),
            ).toBe('true');
            expect(w.get('[data-test=kpi-stale]').text()).toBe(
                'Stale: last data Jan 2, 2020 9:30 AM',
            );
        });

        it('error shows the message and a Retry that emits retry', async () => {
            const w = render(payload({ state: 'error' }));

            expect(w.get('[data-test=kpi-error]').text()).toContain(
                kpiCardLabels.errorMessage,
            );
            await w.get('[data-test=kpi-retry]').trigger('click');

            expect(w.emitted('retry')).toHaveLength(1);
        });
    });

    describe('safety and layout', () => {
        const attack = '<img src=x onerror=alert(1)>';
        const quote = '"><script>alert(1)</script>';

        it('renders every slot as literal text', () => {
            const w = render(
                payload({
                    title: attack,
                    subtitle: quote,
                    icon: attack,
                    footerAction: quote,
                    kpiLabel: attack,
                    comparison: comparison('5', 'up'),
                    comparisonLabel: quote,
                    secondaryNote: attack,
                    statusBadge: quote,
                }),
            );

            expect(w.find('img').exists()).toBe(false);
            expect(w.find('script').exists()).toBe(false);
            expect(w.get('[data-test=kpi-title]').text()).toBe(attack);
            expect(w.get('[data-test=kpi-subtitle]').text()).toBe(quote);
            expect(w.get('[data-test=kpi-icon]').text()).toBe(attack);
            expect(w.get('[data-test=kpi-footer]').text()).toBe(quote);
            expect(w.get('[data-test=kpi-label]').text()).toBe(attack);
            expect(w.get('[data-test=kpi-delta-visible]').text()).toContain(
                quote,
            );
            expect(w.get('[data-test=kpi-note]').text()).toBe(attack);
            expect(w.get('[data-test=kpi-badge]').text()).toBe(quote);
        });

        it('shows the error message and units suffix literally too', () => {
            const w = render(payload({ state: 'error', errorMessage: attack }));

            expect(w.find('img').exists()).toBe(false);
            expect(w.get('[data-test=kpi-error]').text()).toContain(attack);

            const s = render(
                payload({
                    headline: {
                        available: true,
                        value: '1',
                        format: descriptor({ unitsSuffix: attack }),
                    },
                }),
            );

            expect(s.find('img').exists()).toBe(false);
        });

        it('has a focusable scroll region named by the title, in every state', () => {
            for (const state of [
                'normal',
                'loading',
                'empty',
                'stale',
                'unavailable',
                'error',
            ] as const) {
                const w = render(
                    payload({ state, title: 'A very long title '.repeat(20) }),
                );
                const body = w.get('[data-test=kpi-body]');

                expect(body.attributes('role')).toBe('region');
                expect(body.attributes('tabindex')).toBe('0');
                expect(body.classes()).toContain('overflow-auto');
                expect(body.attributes('aria-labelledby')).toBe(
                    w.get('[data-test=kpi-title]').attributes('id'),
                );
            }
        });
    });
});

describe('kpi-card renderer edge cases (review)', () => {
    const head = (value: string, format: FormatDescriptor) =>
        payload({ headline: { available: true, value, format } });

    it('formats durations and relative times exactly', () => {
        expect(formatDuration('3903')).toBe('1 hr 5 min 3 sec');
        expect(formatDuration('0')).toBe('0 sec');
        expect(formatDuration('60')).toBe('1 min');
        expect(formatDuration('abc')).toBe('');

        const now = new Date('2026-10-09T12:00:00Z');

        expect(formatRelativeTime('2026-10-09T09:00:00Z', now)).toBe(
            '3 hours ago',
        );
        expect(formatRelativeTime('nope', now)).toBe('');
    });

    it('shows a currency code when asked', () => {
        const w = render(
            head(
                '1234.5',
                descriptor({
                    kind: 'currency',
                    currencyCode: 'USD',
                    currencyDisplay: 'code',
                }),
            ),
        );

        expect(
            w.get('[data-test=kpi-headline]').text().replace(/\s/g, ' '),
        ).toBe('USD 1,234.50');
    });

    it('stale with an unavailable headline shows no headline, zero or NaN', () => {
        const w = render(
            payload({
                state: 'stale',
                staleSince: '2020-01-02T09:30:00Z',
                headline: { available: false, field: 'Headline value' },
            }),
        );

        expect(w.find('[data-test=kpi-unavailable]').exists()).toBe(true);
        expect(w.find('[data-test=kpi-headline]').exists()).toBe(false);
        expect(w.text()).not.toMatch(/NaN|\b0\b/);
    });

    it.each(['', '.', 'e5', '1e999999999'])(
        'percent %j is unavailable',
        (value) => {
            const w = render(head(value, descriptor({ kind: 'percent' })));

            expect(w.find('[data-test=kpi-unavailable]').exists()).toBe(true);
            expect(w.find('[data-test=kpi-headline]').exists()).toBe(false);
        },
    );

    it('a delta that rounds to zero is No change; an unformattable one is omitted', () => {
        const zero = render(
            payload({
                comparison: {
                    ...comparison('-0.001', 'down')!,
                    format: descriptor({ decimalPlaces: 0 }),
                },
            }),
        );

        expect(zero.get('[data-test=kpi-delta-visible]').text()).toBe(
            'No change',
        );
        expect(zero.find('[data-test=kpi-delta-arrow]').exists()).toBe(false);
        expect(zero.get('[data-test=kpi-delta]').classes()).toContain(
            'text-text-secondary',
        );

        const bad = render(payload({ comparison: comparison('abc', 'up') }));

        expect(bad.find('[data-test=kpi-delta]').exists()).toBe(false);
    });

    it('stale without staleSince shows a generic label with the clock', () => {
        const w = render(payload({ state: 'stale', staleSince: null }));

        expect(w.get('[data-test=kpi-stale]').text()).toBe(
            kpiCardLabels.staleGeneric,
        );
        expect(w.find('[data-test=kpi-stale] svg').exists()).toBe(true);
    });

    it('an unjudged delta still says favourable or unfavourable in hidden text', () => {
        const up = render(
            payload({
                comparison: comparison('14.2', 'up'),
                comparisonLabel: 'from last year',
            }),
        );

        expect(up.get('[data-test=kpi-delta-spoken]').text()).toBe(
            'Up 14.2% from last year, favourable',
        );
        expect(up.get('[data-test=kpi-delta-visible]').text()).not.toContain(
            'better',
        );

        const down = render(payload({ comparison: comparison('-1', 'down') }));

        expect(down.get('[data-test=kpi-delta-spoken]').text()).toContain(
            'unfavourable',
        );
    });
});

// Story 3.15a: presentation rules.
describe('kpi-card renderer presentation rules', () => {
    const headline = (
        emphasis?: NonNullable<
            Extract<KpiCardPayload['headline'], { available: true }>['emphasis']
        >,
    ): KpiCardPayload['headline'] => ({
        available: true,
        value: '284680',
        format: descriptor({
            kind: 'currency',
            currencyCode: 'USD',
            decimalPlaces: 0,
        }),
        ...(emphasis === undefined ? {} : { emphasis }),
    });

    it('formats currency with Intl and wraps the value in its prefix and suffix as literal text', () => {
        const w = render(
            payload({
                headline: headline(),
                prefix: '~',
                suffix: ' gross',
            }),
        );

        expect(w.get('[data-test=kpi-headline]').text()).toBe(
            '~$284,680 gross',
        );
    });

    it('applies the headline emphasis size and weight, and leaves the default alone without one', () => {
        const plain = render(payload({ headline: headline() }));
        const big = render(
            payload({
                headline: headline({
                    role: 'headline',
                    size: 'lg',
                    weight: 'regular',
                }),
            }),
        );
        const small = render(
            payload({
                headline: headline({
                    role: 'primary',
                    size: 'sm',
                    weight: 'medium',
                }),
            }),
        );

        const classes = (w: ReturnType<typeof render>) =>
            w.get('[data-test=kpi-headline]').classes();

        expect(classes(plain)).not.toContain('font-bold');
        expect(classes(big)).toEqual(
            expect.arrayContaining(['text-4xl', 'font-normal']),
        );
        expect(classes(small)).toEqual(
            expect.arrayContaining(['text-xl', 'font-medium']),
        );
        expect(
            big.get('[data-test=kpi-headline]').attributes('data-emphasis'),
        ).toBe('headline');
    });

    it('shows a band badge with its icon and its label, colour never alone', () => {
        const w = render(
            payload({
                statusBadge: {
                    text: 'On target',
                    color: 'success',
                    icon: 'trending-up',
                },
            }),
        );

        expect(w.get('[data-test=kpi-band-label]').text()).toBe('On target');
        expect(w.find('[data-test=kpi-band] svg').exists()).toBe(true);
        expect(w.get('[data-test=kpi-band]').classes()).toContain(
            'bg-success-soft',
        );
        expect(w.find('[data-test=kpi-badge]').exists()).toBe(false);
    });

    it('shows a plain status text as before when no band matched', () => {
        const w = render(payload({ statusBadge: 'Open' }));

        expect(w.get('[data-test=kpi-badge]').text()).toBe('Open');
        expect(w.find('[data-test=kpi-band]').exists()).toBe(false);
    });

    it('shows the empty display for a mapped empty slot, and never for the headline', () => {
        const w = render(
            payload({
                emptyDisplay: '—',
                emptySlots: ['secondary_note', 'comparison'],
            }),
        );

        expect(w.get('[data-test=kpi-note-empty]').text()).toBe('—');
        expect(w.get('[data-test=kpi-delta-empty]').text()).toBe('—');

        const unavailable = render(
            payload({
                state: 'unavailable',
                headline: { available: false, field: 'Headline value' },
                emptyDisplay: '—',
                emptySlots: ['secondary_note'],
            }),
        );

        expect(unavailable.find('[data-test=kpi-headline]').exists()).toBe(
            false,
        );
        expect(unavailable.get('[data-test=kpi-unavailable]').text()).not.toBe(
            '—',
        );
    });

    it('does not show the empty display for a slot that was not mapped or has a value', () => {
        const none = render(payload({ emptyDisplay: '—', emptySlots: [] }));
        const valued = render(
            payload({
                secondaryNote: 'Note',
                emptyDisplay: '—',
                emptySlots: ['secondary_note'],
            }),
        );

        expect(none.find('[data-test=kpi-note-empty]').exists()).toBe(false);
        expect(valued.find('[data-test=kpi-note-empty]').exists()).toBe(false);
        expect(valued.get('[data-test=kpi-note]').text()).toBe('Note');
    });
});
