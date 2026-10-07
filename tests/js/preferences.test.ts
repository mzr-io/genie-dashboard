// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, nextTick, reactive } from 'vue';

const props = reactive<{ auth: { user: Record<string, unknown> | null } }>({
    auth: { user: null },
});

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props }) }));

const { useUserPreferences } =
    await import('../../resources/js/composables/useUserPreferences');
const { currentFormatting } = await import('../../resources/js/lib/format');
const {
    clearShortcuts,
    dispatchShortcut,
    registerShortcut,
    singleKeyShortcutsEnabled,
} = await import('../../resources/js/lib/shortcuts');

let wrapper: VueWrapper | null = null;

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    clearShortcuts();
});

describe('saved settings reach the client', () => {
    it('follows the signed-in profile: locale, time zone and the shortcuts switch', async () => {
        const run = vi.fn();
        registerShortcut({ id: 'add', key: 'a', run });
        wrapper = mount(
            defineComponent({
                setup() {
                    useUserPreferences();

                    return () => null;
                },
            }),
        );

        // Nobody signed in: catalogue locale, browser time zone, shortcuts On.
        expect(currentFormatting()).toEqual({
            locale: 'en',
            timeZone: undefined,
        });
        expect(singleKeyShortcutsEnabled()).toBe(true);

        props.auth.user = {
            locale: 'en',
            timezone: 'Asia/Dhaka',
            keyboard_shortcuts: false,
        };
        await nextTick();

        expect(currentFormatting()).toEqual({
            locale: 'en',
            timeZone: 'Asia/Dhaka',
        });
        expect(singleKeyShortcutsEnabled()).toBe(false);

        // The installed listener respects the switch.
        document.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'a', bubbles: true }),
        );
        expect(run).not.toHaveBeenCalled();

        props.auth.user = {
            locale: 'en',
            timezone: 'UTC',
            keyboard_shortcuts: true,
        };
        await nextTick();
        document.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'a', bubbles: true }),
        );
        expect(run).toHaveBeenCalledTimes(1);
        expect(
            dispatchShortcut(new KeyboardEvent('keydown', { key: 'q' })),
        ).toBe(false);
    });

    it('treats a user without the field as shortcuts On', async () => {
        props.auth.user = { name: 'Ada' };
        wrapper = mount(
            defineComponent({
                setup() {
                    useUserPreferences();

                    return () => null;
                },
            }),
        );
        await nextTick();

        expect(singleKeyShortcutsEnabled()).toBe(true);
    });

    it('resets formatting and the shortcut gate when the layout unmounts', async () => {
        props.auth.user = {
            locale: 'en',
            timezone: 'Asia/Dhaka',
            keyboard_shortcuts: false,
        };
        wrapper = mount(
            defineComponent({
                setup() {
                    useUserPreferences();

                    return () => null;
                },
            }),
        );
        await nextTick();
        expect(currentFormatting().timeZone).toBe('Asia/Dhaka');
        expect(singleKeyShortcutsEnabled()).toBe(false);

        wrapper.unmount();
        wrapper = null;

        expect(currentFormatting()).toEqual({
            locale: 'en',
            timeZone: undefined,
        });
        expect(singleKeyShortcutsEnabled()).toBe(true);
    });
});
