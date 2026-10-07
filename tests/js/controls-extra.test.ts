// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import CategoryChips from '../../resources/js/components/CategoryChips.vue';
import SecretField from '../../resources/js/components/SecretField.vue';
import SegmentedControl from '../../resources/js/components/SegmentedControl.vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '../../resources/js/components/ui/select';
import SidebarMenuSkeleton from '../../resources/js/components/ui/sidebar/SidebarMenuSkeleton.vue';
import { useBlurValidation } from '../../resources/js/composables/useBlurValidation';
import { formatDay } from '../../resources/js/lib/formatDate';

beforeEach(() => {
    window.matchMedia = (() => ({
        matches: false,
        addEventListener: () => {},
        removeEventListener: () => {},
    })) as unknown as typeof window.matchMedia;
});

afterEach(() => {
    document.body.innerHTML = '';
    vi.useRealTimers();
});

const tick = async () => {
    await nextTick();
    await nextTick();
};

describe('SecretField', () => {
    const mountField = (props: Record<string, unknown> = {}, attrs = {}) =>
        mount(SecretField, {
            props: { savedAt: '2026-10-02T09:30:00Z', ...props },
            attrs: {
                id: 'tok',
                'aria-describedby': 'tok-help',
                'aria-invalid': 'true',
                ...attrs,
            },
            attachTo: document.body,
        });

    it('names the saved group by its "Secret set on" text and forwards describedby and invalid', () => {
        mountField();
        const group = document.getElementById('tok')!;
        const name = document.getElementById(
            group.getAttribute('aria-labelledby')!,
        )!;

        expect(group.getAttribute('role')).toBe('group');
        expect(name.textContent).toBe('Secret set on 2 Oct 2026');
        expect(group.getAttribute('aria-describedby')).toBe('tok-help');
        expect(group.getAttribute('aria-invalid')).toBe('true');
    });

    it('declares replacing as a prop, so it never lands on the input, and syncs it', async () => {
        const wrapper = mountField({ replacing: false });

        expect(wrapper.find('input').exists()).toBe(false);
        await wrapper.setProps({ replacing: true });
        const input = wrapper.get('input');

        expect(input.attributes('replacing')).toBeUndefined();
        expect(input.attributes('aria-invalid')).toBe('true');
        expect(document.body.innerHTML).not.toContain('Secret set on');
    });

    it('emits update:replacing on Replace', async () => {
        const wrapper = mountField();

        await wrapper.get('button').trigger('click');
        expect(wrapper.emitted('update:replacing')).toEqual([[true]]);
        expect(wrapper.emitted('update:modelValue')).toEqual([['']]);
    });

    it('keeps an in-progress replacement when savedAt is a new Date for the same moment', async () => {
        const wrapper = mountField({
            savedAt: new Date('2026-10-02T09:30:00Z'),
        });

        await wrapper.get('button').trigger('click');
        expect(wrapper.find('input').exists()).toBe(true);
        await wrapper.setProps({ savedAt: new Date('2026-10-02T09:30:00Z') });
        expect(wrapper.find('input').exists()).toBe(true);
        // A genuinely new save returns to the saved state, still with no secret in the DOM.
        await wrapper.setProps({ savedAt: '2026-10-03T09:30:00Z' });
        expect(wrapper.find('input').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('hunter2');
    });

    it('renders for an unparsable date instead of throwing', () => {
        expect(() => mountField({ savedAt: 'not a date' })).not.toThrow();
    });
});

describe('formatDay', () => {
    it('formats a day and returns "" for an invalid date', () => {
        expect(formatDay('2026-10-02T09:30:00Z')).toBe('2 Oct 2026');
        expect(formatDay('nonsense')).toBe('');
        expect(formatDay(new Date(NaN))).toBe('');
    });
});

describe('SidebarMenuSkeleton', () => {
    it('adds no "Still loading…" caption after 5 s, keeping the row height', async () => {
        vi.useFakeTimers();
        const wrapper = mount(SidebarMenuSkeleton, {
            props: { showIcon: true },
        });

        await vi.advanceTimersByTimeAsync(6000);
        expect(wrapper.find('[data-slot="skeleton-caption"]').exists()).toBe(
            false,
        );
        expect(wrapper.classes()).toContain('h-8');
    });
});

describe('Select option reason', () => {
    it('describes a disabled option with its reason at full opacity', async () => {
        const reason = 'Live is available after approval.';

        mount(
            defineComponent({
                render: () =>
                    h(
                        Select,
                        { defaultOpen: true, defaultValue: 'test' },
                        () => [
                            h(SelectTrigger, null, () => h(SelectValue)),
                            h(SelectContent, null, () => [
                                h(SelectItem, { value: 'test' }, () => 'Test'),
                                h(
                                    SelectItem,
                                    { value: 'live', disabled: true, reason },
                                    () => 'Live',
                                ),
                            ]),
                        ],
                    ),
            }),
            { attachTo: document.body },
        );
        await tick();

        const live = [...document.querySelectorAll('[role="option"]')].find(
            (o) => o.textContent?.includes('Live'),
        )!;
        const note = document.getElementById(
            live.getAttribute('aria-describedby')!,
        )!;

        expect(live.hasAttribute('data-disabled')).toBe(true);
        expect(live.getAttribute('aria-disabled')).toBe('true');
        expect(note.textContent).toBe(reason);
        expect(note.className).not.toMatch(/opacity/);
    });
});

describe('required group names', () => {
    it('gives the segmented control and chip group their accessible names', () => {
        const seg = mount(SegmentedControl, {
            props: { label: 'Device', options: [{ value: 'a', label: 'A' }] },
        });
        const chips = mount(CategoryChips, { props: { label: 'Category' } });

        expect(seg.attributes('aria-label')).toBe('Device');
        expect(chips.get('[role="radiogroup"]').attributes('aria-label')).toBe(
            'Category',
        );
    });
});

describe('useBlurValidation', () => {
    function harness(onValid: () => Promise<void> | void = () => {}) {
        let api!: ReturnType<typeof useBlurValidation>;
        const values = { a: '', b: '' };

        mount(
            defineComponent({
                setup() {
                    api = useBlurValidation({
                        a: {
                            value: () => values.a,
                            validate: (v) => (v ? null : 'need a'),
                        },
                        b: {
                            value: () => values.b,
                            validate: (v) => (v ? null : 'need b'),
                        },
                    });

                    return () => h('div');
                },
            }),
        );

        return { api, values, onValid };
    }

    it('ignores a blur for a name with no rule', () => {
        const { api } = harness();

        expect(() => api.onBlur('missing')).not.toThrow();
        expect(api.errors.missing).toBeUndefined();
    });

    it('drops the summary once fewer than two errors remain after a blur', async () => {
        const { api, values } = harness();

        await api.submit(() => {});
        expect(api.showSummary.value).toBe(true);
        values.a = 'x';
        api.onBlur('a');
        expect(api.showSummary.value).toBe(false);
        expect(api.summaryItems.value.map((i) => i.label)).toEqual(['b']);
    });

    it('ignores a submit while an async handler is still running', async () => {
        const { api, values } = harness();
        let release!: () => void;
        const onValid = vi.fn(
            () => new Promise<void>((resolve) => (release = resolve)),
        );

        values.a = 'x';
        values.b = 'y';
        const first = api.submit(onValid);

        await tick();
        await api.submit(onValid);
        expect(onValid).toHaveBeenCalledTimes(1);
        release();
        await first;
        await api.submit(async () => {});
        const again = api.submit(onValid);

        await tick();
        release();
        await again;
        expect(onValid).toHaveBeenCalledTimes(2);
    });
});

describe('touch targets', () => {
    it('keeps the inline link button out of the 44px coarse-pointer rule', () => {
        const css = readFileSync('resources/css/app.css', 'utf8');
        const block = css.slice(css.indexOf('@media (pointer: coarse)'));

        expect(block).toContain(
            "[data-slot='button']:not([data-variant='link'])",
        );
        expect(block).not.toMatch(/\[data-slot='button'\],/);
    });
});
