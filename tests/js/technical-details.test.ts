// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import TechnicalDetails from '../../resources/js/components/TechnicalDetails.vue';

// The mounted behaviour deferred from Story 1.7: toggling the disclosure and copying.
const props = {
    area: 'admin' as const,
    status: 502,
    path: 'data.monthly[]',
    requestId: 'req-8f3a2c',
};

afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

describe('TechnicalDetails (mounted)', () => {
    it('toggles the disclosure with aria-expanded and aria-controls', async () => {
        const wrapper = mount(TechnicalDetails, {
            props,
            attachTo: document.body,
        });
        const toggle = wrapper.get('button[aria-expanded]');
        const panel = document.getElementById(
            toggle.attributes('aria-controls')!,
        )!;

        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(panel.hasAttribute('hidden')).toBe(true);

        await toggle.trigger('click');
        expect(toggle.attributes('aria-expanded')).toBe('true');
        expect(panel.hasAttribute('hidden')).toBe(false);

        await toggle.trigger('click');
        expect(panel.hasAttribute('hidden')).toBe(true);
    });

    it('copies the request ID and confirms it', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);

        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText },
        });
        const wrapper = mount(TechnicalDetails, {
            props,
            attachTo: document.body,
        });
        const copy = wrapper
            .findAll('button')
            .find((b) => b.text() === 'Copy request ID')!;

        await copy.trigger('click');
        await new Promise((resolve) => setTimeout(resolve, 0));
        await nextTick();

        expect(writeText).toHaveBeenCalledWith('req-8f3a2c');
        expect(wrapper.get('[role="status"]').text()).toBe('Copied');
    });

    it('says so when copying fails, leaving the ID visible', async () => {
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText: vi.fn().mockRejectedValue(new Error('no')) },
        });
        const wrapper = mount(TechnicalDetails, {
            props,
            attachTo: document.body,
        });

        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'Copy request ID')!
            .trigger('click');
        await new Promise((resolve) => setTimeout(resolve, 0));
        await nextTick();

        expect(wrapper.get('[role="status"]').text()).toContain('Copy failed');
        expect(wrapper.text()).toContain('req-8f3a2c');
    });

    it('renders nothing in the User area', () => {
        const wrapper = mount(TechnicalDetails, {
            props: { ...props, area: 'user' },
        });

        expect(wrapper.html()).not.toContain('502');
        expect(wrapper.find('button').exists()).toBe(false);
    });
});
