// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import VersionDiffRows from '../../resources/js/components/wizard/VersionDiffRows.vue';
import type { VersionDiff } from '../../resources/js/lib/versionDiff';

// Story 6.3: the rows of "What changed": four sections, before struck through and muted, was and now for a screen reader, "No changes" when empty.
const diff = (over: Partial<VersionDiff['sections']> = {}): VersionDiff => ({
    first_publish: false,
    current_version: '1.0',
    draft_version: '1.1',
    summary: {},
    sections: {
        mapping: [],
        presentation: [],
        chrome: [],
        endpoint: [],
        ...over,
    },
});

describe('the diff rows', () => {
    it('lists the four sections and says no changes for an empty one', () => {
        const wrapper = mount(VersionDiffRows, { props: { diff: diff() } });

        expect(
            wrapper.findAll('[data-test=diff-none]').map((n) => n.text()),
        ).toEqual(['No changes', 'No changes', 'No changes', 'No changes']);
        expect(wrapper.findAll('section')).toHaveLength(4);
    });

    it('shows before struck through and after in primary text, with was and now', () => {
        const wrapper = mount(VersionDiffRows, {
            props: {
                diff: diff({
                    presentation: [
                        {
                            path: 'presentation.empty_display',
                            before: 'n/a',
                            after: '–',
                        },
                    ],
                }),
            },
        });
        const row = wrapper.get('[data-test=diff-row]');

        expect(row.get('[data-test=diff-before]').classes()).toContain(
            'line-through',
        );
        expect(row.get('[data-test=diff-before]').classes()).toContain(
            'text-text-muted',
        );
        expect(row.get('[data-test=diff-after]').text()).toBe('–');
        expect(row.text()).toContain('was');
        expect(row.text()).toContain('now');
        expect(
            wrapper
                .get('[data-test=diff-mapping] [data-test=diff-none]')
                .text(),
        ).toBe('No changes');
    });

    it('writes hostile text as text', () => {
        const wrapper = mount(VersionDiffRows, {
            props: {
                diff: diff({
                    chrome: [
                        {
                            path: 'description',
                            before: '<img src=x onerror=1>',
                            after: null,
                        },
                    ],
                }),
            },
        });

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.get('[data-test=diff-before]').text()).toBe(
            '<img src=x onerror=1>',
        );
        expect(wrapper.get('[data-test=diff-after]').text()).toBe('none');
    });
});
