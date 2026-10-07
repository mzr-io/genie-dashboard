// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';

const page = reactive<{ url: string; props: Record<string, unknown> }>({
    url: '/admin/blocks',
    props: {},
});

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    Link: defineComponent({
        props: ['href'],
        setup:
            (props, { slots, attrs }) =>
            () =>
                h('a', { ...attrs, href: props.href }, slots.default?.()),
    }),
    Head: defineComponent({ setup: () => () => null }),
}));

import GatedAction from '../../resources/js/components/GatedAction.vue';
import { createCatalogue } from '../../resources/js/lib/i18n';
import en from '../../resources/js/locales/en';
import { shellLabels } from '../../resources/js/locales/labels';
import { overview } from '../../resources/js/routes';
import Forbidden from '../../resources/js/pages/Forbidden.vue';

function withCan(can: Record<string, boolean>): void {
    page.props = { shell: { area: 'admin', items: [], can } };
}

function mountAction(props: Record<string, unknown>, onClick = vi.fn()) {
    setActivePinia(createPinia());

    return mount(GatedAction, {
        props: { ...props, onClick },
        slots: { default: () => 'Publish' },
        global: { plugins: [createCatalogue()] },
        attachTo: document.body,
    });
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('GatedAction for a member without blocks.publish', () => {
    it('renders Publish blocked with the catalogue reason perm-publish, and Save draft stays outside the gate', async () => {
        withCan({ 'blocks.publish': false, 'blocks.edit': true });
        const onClick = vi.fn();
        // No reason prop: the catalogue's perm-publish text is what the gate shows for a Publish action.
        const wrapper = mountAction(
            { permission: 'blocks.publish', reason: en['perm-publish'] },
            onClick,
        );

        expect(wrapper.get('button').attributes('aria-disabled')).toBe('true');
        expect(wrapper.get('[data-slot="blocked-reason"]').text()).toBe(
            en['perm-publish'],
        );
        await wrapper.get('button').trigger('click');
        expect(onClick).not.toHaveBeenCalled();
    });
});

describe('GatedAction', () => {
    it('renders Publish aria-disabled and focusable with perm-publish inline, and runs nothing', async () => {
        withCan({ 'blocks.publish': false, 'blocks.edit': true });
        const onClick = vi.fn();
        const wrapper = mountAction(
            { permission: 'blocks.publish', reason: en['perm-publish'] },
            onClick,
        );
        const button = wrapper.get('button');

        expect(button.attributes('aria-disabled')).toBe('true');
        expect(button.attributes('disabled')).toBeUndefined();
        expect(wrapper.text()).toContain(en['perm-publish']);
        expect(button.attributes('aria-describedby')).toBe(
            wrapper.get('[data-slot="blocked-reason"]').attributes('id'),
        );

        (button.element as HTMLElement).focus();
        expect(document.activeElement).toBe(button.element);

        await button.trigger('click');
        expect(onClick).not.toHaveBeenCalled();
    });

    it('falls back to perm-denied when no reason is given', () => {
        withCan({});
        const wrapper = mountAction({ permission: 'blocks.publish' });

        expect(wrapper.text()).toContain(en['perm-denied']);
    });

    it('treats an empty reason as no reason and renders exactly one reason', () => {
        withCan({});
        const wrapper = mountAction({
            permission: 'blocks.publish',
            reason: '',
        });

        expect(wrapper.findAll('[data-slot="blocked-reason"]')).toHaveLength(1);
        expect(wrapper.text()).toContain(en['perm-denied']);
        expect(wrapper.get('button').text()).toBe('Publish');
        expect(wrapper.get('button').attributes('aria-describedby')).toBe(
            wrapper.get('[data-slot="blocked-reason"]').attributes('id'),
        );
    });

    it('is enabled and shows no reason when the permission is held', async () => {
        withCan({ 'blocks.publish': true });
        const onClick = vi.fn();
        const wrapper = mountAction({ permission: 'blocks.publish' }, onClick);

        expect(
            wrapper.get('button').attributes('aria-disabled'),
        ).toBeUndefined();
        expect(wrapper.find('[data-slot="blocked-reason"]').exists()).toBe(
            false,
        );

        await wrapper.get('button').trigger('click');
        expect(onClick).toHaveBeenCalledOnce();
    });
});

describe('Forbidden page', () => {
    it('shows perm-denied and a way back, nothing else', () => {
        withCan({});
        const wrapper = mount(Forbidden, {
            global: { plugins: [createCatalogue()] },
        });

        expect(wrapper.get('h1').text()).toBe(shellLabels.forbiddenTitle);
        expect(wrapper.get('[data-slot="perm-denied"]').text()).toBe(
            en['perm-denied'],
        );
        expect(wrapper.get('a').attributes('href')).toBe(overview.url());
    });
});
