// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import ActivityRenderer from '@block-types/activity-feed/v1/Renderer.vue';
import type { ActivityPayload } from '@block-types/activity-feed/v1/payload';
import { ago } from '@block-types/activity-feed/v1/table';
import AgendaRenderer from '@block-types/calendar-agenda/v1/Renderer.vue';
import type { AgendaPayload } from '@block-types/calendar-agenda/v1/payload';
import { spoken, weekOf } from '@block-types/calendar-agenda/v1/table';
import TextStatusRenderer from '@block-types/text-status/v1/Renderer.vue';
import type { TextStatusPayload } from '@block-types/text-status/v1/payload';
import { describe, expect, it } from 'vitest';
import { createCatalogue } from '../../resources/js/lib/i18n';
import { expandKpiPayload } from '../../resources/js/lib/textTemplate';

// Story 5.14: the activity feed, the calendar agenda and the text/status Blocks.
const base = {
    state: 'normal' as const,
    title: 'Block',
    subtitle: null,
    icon: null,
    footerAction: null,
    partial: false,
    emptyDisplay: null,
    staleSince: null,
    errorMessage: null,
};
const global = { plugins: [createCatalogue()] };

describe('the activity feed', () => {
    const now = new Date('2026-05-22T10:00:00Z');

    it('says how long ago in the viewer locale, the date for the future and a dash for junk', () => {
        expect(ago('2026-05-22T09:52:00Z', now, 'en')).toMatch(/8 min/);
        expect(ago('2026-06-01T09:00:00Z', now, 'en')).toMatch(/Jun 1, 2026/);
        expect(ago(null, now, 'en')).toBe('—');
        expect(ago('2026-05-22T09:52:00Z', now, 'de')).toMatch(/8 Min/);
    });

    it('draws the actor, the action and the time, literally', () => {
        const payload: ActivityPayload = {
            ...base,
            rows: [
                {
                    key: 'k:1',
                    actor: 'Ada',
                    action: 'merged',
                    object: '<b>PR</b>',
                    timestamp: '2020-01-01T00:00:00Z',
                    avatar: { initials: 'A', tint: 1 },
                },
                {
                    key: 'k:2',
                    actor: 'Bob',
                    action: 'left',
                    object: null,
                    timestamp: null,
                    avatar: null,
                },
            ],
        };
        const wrapper = mount(ActivityRenderer, { props: { payload }, global });
        const rows = wrapper.findAll('[data-test=activity-row]');

        expect(rows[0].get('[data-test=activity-actor]').text()).toBe('Ada');
        expect(rows[0].get('[data-test=activity-object]').text()).toBe(
            '<b>PR</b>',
        );
        expect(rows[0].find('b').exists()).toBe(false);
        expect(rows[1].get('[data-test=activity-time]').text()).toBe('—');
        wrapper.unmount();
    });
});

describe('the agenda', () => {
    const payload: AgendaPayload = {
        ...base,
        weekStrip: true,
        rows: [
            {
                key: 'k:1',
                date: '2026-05-22',
                time: '09:30',
                title: 'Product sync',
                subtitle: 'Meeting room 2A',
                duration: '45',
                category: 'Meeting',
                tint: 1,
            },
            {
                key: 'k:2',
                date: '2026-05-23',
                time: '11:00',
                title: 'Lunch',
                subtitle: null,
                duration: null,
                category: null,
                tint: null,
            },
        ],
    };

    it('is read as the time, the title, the place and the length', () => {
        expect(spoken({ ...payload.rows[0], category: null })).toBe(
            '09:30, Product sync, Meeting room 2A, 45 min',
        );
    });

    it('lists the days of the week of today and marks today', () => {
        expect(weekOf('2026-05-22')).toEqual([
            '2026-05-18',
            '2026-05-19',
            '2026-05-20',
            '2026-05-21',
            '2026-05-22',
            '2026-05-23',
            '2026-05-24',
        ]);

        const wrapper = mount(AgendaRenderer, {
            props: { payload, today: '2026-05-22' },
            global,
        });
        const days = wrapper.findAll('[data-test=week-day]');

        expect(days).toHaveLength(7);
        expect(days[4].attributes('aria-current')).toBe('date');
        expect(days[4].attributes('aria-label')).toMatch(
            /^Friday.*22.*, today$/,
        );
        expect(days.filter((d) => d.attributes('aria-current'))).toHaveLength(
            1,
        );
        expect(wrapper.findAll('[data-test=agenda-row]')).toHaveLength(2);
        expect(wrapper.get('[data-test=agenda-category]').text()).toBe(
            'Meeting',
        );
        wrapper.unmount();
    });
});

describe('the text and status Block', () => {
    const text: TextStatusPayload = {
        ...base,
        mode: 'text',
        text: 'A very long text',
        band: null,
    };

    it('clamps text to three lines', () => {
        const wrapper = mount(TextStatusRenderer, {
            props: { payload: text },
            global,
        });

        expect(wrapper.get('[data-test=text-body]').classes()).toContain(
            'line-clamp-3',
        );
        wrapper.unmount();
    });

    it('shows the band pill with its word, and neutral text with an icon for no band', () => {
        const matched = mount(TextStatusRenderer, {
            props: {
                payload: {
                    ...text,
                    mode: 'status',
                    text: 'On track',
                    band: { text: 'On track', color: 'success', icon: 'check' },
                },
            },
            global,
        });

        expect(matched.get('[data-test=status-word]').text()).toBe('On track');
        expect(
            matched.get('[data-test=status-pill]').attributes('data-band'),
        ).toBe('success');
        expect(matched.find('[data-test=status-pill] svg').exists()).toBe(true);
        matched.unmount();

        const none = mount(TextStatusRenderer, {
            props: { payload: { ...text, mode: 'status', text: 'Weird' } },
            global,
        });

        expect(none.get('[data-test=status-word]').text()).toBe('Weird');
        expect(
            none.get('[data-test=status-pill]').attributes('data-band'),
        ).toBe('none');
        expect(none.find('[data-test=status-pill] svg').exists()).toBe(true);
        none.unmount();
    });

    it('expands the tokens of its text', () => {
        const made = expandKpiPayload(
            { ...text, text: 'Hello {user.name}' } as never,
            { name: 'Ada' },
        ) as never as TextStatusPayload;

        expect(made.text).toBe('Hello Ada');
    });
});
