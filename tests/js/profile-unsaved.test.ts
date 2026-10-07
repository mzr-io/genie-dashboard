// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, ref } from 'vue';

const dirty = ref(false);
const submit = vi.fn();
let formEmit: ((event: string) => void) | null = null;

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: { auth: { user: { name: 'Ada', email: 'a@example.test' } } },
    }),
    Head: defineComponent({ setup: () => () => null }),
    Form: defineComponent({
        emits: ['success', 'error', 'finish'],
        setup(_, { emit, expose, slots }) {
            formEmit = (event) => emit(event as 'success');
            expose({ isDirty: dirty, submit });

            return () =>
                h('form', slots.default?.({ errors: {}, processing: false }));
        },
    }),
}));

import Profile from '../../resources/js/pages/settings/Profile.vue';
import {
    clearUnsavedForms,
    hasUnsavedWork,
    saveUnsavedForms,
} from '../../resources/js/lib/unsavedForms';

function mountProfile() {
    return mount(Profile, { global: { stubs: { DeleteUser: true } } });
}

beforeEach(() => {
    clearUnsavedForms();
    dirty.value = false;
    submit.mockReset();
});

afterEach(() => clearUnsavedForms());

describe('Profile unsaved work', () => {
    it('registers, reports dirty state and unregisters on unmount', () => {
        const wrapper = mountProfile();

        expect(hasUnsavedWork()).toBe(false);
        dirty.value = true;
        expect(hasUnsavedWork()).toBe(true);

        wrapper.unmount();
        expect(hasUnsavedWork()).toBe(false);
    });

    it('resolves true when the save succeeds', async () => {
        const wrapper = mountProfile();
        dirty.value = true;

        const saved = saveUnsavedForms();
        expect(submit).toHaveBeenCalledTimes(1);
        formEmit!('success');
        formEmit!('finish');

        expect(await saved).toBe(true);
        wrapper.unmount();
    });

    it('resolves false when the save errors', async () => {
        const wrapper = mountProfile();
        dirty.value = true;

        const saved = saveUnsavedForms();
        formEmit!('error');

        expect(await saved).toBe(false);
        wrapper.unmount();
    });

    it('resolves false when the request finishes without success (cancelled or interrupted)', async () => {
        const wrapper = mountProfile();
        dirty.value = true;

        const saved = saveUnsavedForms();
        formEmit!('finish');

        expect(await saved).toBe(false);
        wrapper.unmount();
    });

    it('settles a pending save as false when saved again or unmounted', async () => {
        const wrapper = mountProfile();
        dirty.value = true;

        const first = saveUnsavedForms();
        const second = saveUnsavedForms();

        expect(await first).toBe(false);

        wrapper.unmount();
        expect(await second).toBe(false);
    });
});
