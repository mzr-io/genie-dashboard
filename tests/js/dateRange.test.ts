// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import DateRangeCalendar from '../../resources/js/components/DateRangeCalendar.vue';
import DateRangePopover from '../../resources/js/components/DateRangePopover.vue';
import {
    addDays,
    addMonths,
    monthWeeks,
    rangeText,
    weekday,
} from '../../resources/js/lib/dateRange';

// Story 4.15: the plain-day helpers, the APG date grid and the Date Range popover.
describe('dateRange helpers', () => {
    it('adds days and months without drifting and starts the week on Monday', () => {
        expect(addDays('2026-05-31', 1)).toBe('2026-06-01');
        expect(addDays('2026-01-01', -1)).toBe('2025-12-31');
        expect(addMonths('2026-01-31', 1)).toBe('2026-02-28');
        expect(weekday('2026-05-04')).toBe(0);
        expect(weekday('2026-05-10')).toBe(6);
        expect(rangeText('2026-05-01', '2026-05-31')).toBe('May 1 – May 31');
    });

    it('lays a month out in weeks of seven with blanks for the neighbouring days', () => {
        const weeks = monthWeeks(2026, 5);

        expect(weeks.every((w) => w.length === 7)).toBe(true);
        expect(weeks[0].filter((d) => d === null)).toHaveLength(4);
        expect(weeks.flat().filter((d) => d !== null)).toHaveLength(31);
    });
});

describe('DateRangeCalendar', () => {
    let wrapper: ReturnType<typeof mount> | null = null;

    afterEach(() => {
        wrapper?.unmount();
        wrapper = null;
        document.body.innerHTML = '';
    });

    const mountCalendar = (from: string | null, to: string | null) => {
        const seen: Array<{ from: string | null; to: string | null }> = [];

        wrapper = mount(DateRangeCalendar as never, {
            props: {
                modelValue: { from, to },
                'onUpdate:modelValue': (v: {
                    from: string | null;
                    to: string | null;
                }) => {
                    seen.push(v);
                    void wrapper?.setProps({ modelValue: v });
                },
            },
            attachTo: document.body,
        });

        return seen;
    };

    const day = (iso: string) =>
        document.querySelector<HTMLElement>(`[data-date="${iso}"]`)!;
    const key = async (iso: string, name: string) => {
        day(iso).dispatchEvent(
            new KeyboardEvent('keydown', { key: name, bubbles: true }),
        );
        await nextTick();
        await flushPromises();
    };

    it('moves focus by day, week, month and week ends with the grid keys', async () => {
        mountCalendar('2026-05-13', null);

        await key('2026-05-13', 'ArrowRight');
        expect(document.activeElement).toBe(day('2026-05-14'));

        await key('2026-05-14', 'ArrowDown');
        expect(document.activeElement).toBe(day('2026-05-21'));

        await key('2026-05-21', 'Home');
        expect(document.activeElement).toBe(day('2026-05-18'));

        await key('2026-05-18', 'End');
        expect(document.activeElement).toBe(day('2026-05-24'));

        await key('2026-05-24', 'PageDown');
        expect(document.activeElement).toBe(day('2026-06-24'));
    });

    it('sets the start, then the end, in either order, and starts over after a complete range', async () => {
        const seen = mountCalendar('2026-05-20', null);

        await key('2026-05-20', 'Enter');
        // The start only was held: the same day makes a one-day range.
        expect(seen.at(-1)).toEqual({ from: '2026-05-20', to: '2026-05-20' });

        await key('2026-05-20', 'Enter');
        expect(seen.at(-1)).toEqual({ from: '2026-05-20', to: null });

        await key('2026-05-20', 'ArrowLeft');
        await key('2026-05-19', 'ArrowLeft');
        await key('2026-05-18', 'Enter');

        expect(seen.at(-1)).toEqual({ from: '2026-05-18', to: '2026-05-20' });
    });

    it('marks the ends and the days between as selected', async () => {
        mountCalendar('2026-05-10', '2026-05-12');

        expect(day('2026-05-10').getAttribute('aria-selected')).toBe('true');
        expect(day('2026-05-11').getAttribute('aria-selected')).toBe('true');
        expect(day('2026-05-13').getAttribute('aria-selected')).toBe('false');
        // Only the focused day is in the tab order.
        expect(
            document.querySelectorAll('[data-date][tabindex="0"]'),
        ).toHaveLength(1);
    });
});

describe('DateRangePopover', () => {
    let wrapper: ReturnType<typeof mount> | null = null;

    afterEach(() => {
        wrapper?.unmount();
        wrapper = null;
        document.body.innerHTML = '';
    });

    it('shows the current range on the button and applies a preset', async () => {
        wrapper = mount(DateRangePopover as never, {
            props: {
                value: { range: 'this_month', from: null, to: null },
            },
            attachTo: document.body,
        });

        const button = document.querySelector<HTMLElement>(
            '[data-slot="date-range"]',
        )!;

        expect(button.textContent).toContain('This month');

        button.click();
        await flushPromises();
        document
            .querySelector<HTMLElement>('[data-preset="current_year"]')!
            .click();
        await flushPromises();
        document
            .querySelector<HTMLElement>('[data-slot="date-range-apply"]')!
            .click();
        await flushPromises();

        expect(wrapper.emitted('apply')).toEqual([
            [{ range: 'current_year', from: null, to: null }],
        ]);
    });

    it('keeps Apply disabled with the reason until a Custom range has both ends', async () => {
        wrapper = mount(DateRangePopover as never, {
            props: { value: null },
            attachTo: document.body,
        });

        document
            .querySelector<HTMLElement>('[data-slot="date-range"]')!
            .click();
        await flushPromises();
        document.querySelector<HTMLElement>('[data-preset="custom"]')!.click();
        await flushPromises();

        const apply = document.querySelector<HTMLElement>(
            '[data-slot="date-range-apply"]',
        )!;

        expect(apply.getAttribute('aria-disabled')).toBe('true');
        expect(
            document.getElementById(apply.getAttribute('aria-describedby')!)
                ?.textContent,
        ).toContain('start and an end');

        apply.click();
        await flushPromises();

        expect(wrapper.emitted('apply')).toBeUndefined();
    });
});
