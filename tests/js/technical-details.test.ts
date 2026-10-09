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

    // Story 2.5: a failed call to a source adds the host and the collapsed reason code.
    it('shows the host and the reason code of a failed call, and only when given', async () => {
        const wrapper = mount(TechnicalDetails, {
            props: { ...props, host: 'api.example.com', reason: 'http_503' },
            attachTo: document.body,
        });

        expect(wrapper.get('[data-field="host"]').text()).toBe(
            'api.example.com',
        );
        expect(wrapper.get('[data-field="reason"]').text()).toBe('http_503');
        expect(wrapper.text()).toContain('Host');
        expect(wrapper.text()).toContain('Reason code');

        const plain = mount(TechnicalDetails, {
            props,
            attachTo: document.body,
        });
        expect(plain.find('[data-field="host"]').exists()).toBe(false);
        expect(plain.find('[data-field="reason"]').exists()).toBe(false);
    });

    it('discloses on host or reason alone, and leaves out its own copy button when the card has one', () => {
        const only = mount(TechnicalDetails, {
            props: { area: 'admin', host: 'api.example.com' },
        });
        const noCopy = mount(TechnicalDetails, {
            props: { ...props, copy: false },
        });

        expect(only.find('button[aria-expanded]').exists()).toBe(true);
        expect(noCopy.findAll('button').map((b) => b.text())).not.toContain(
            'Copy request ID',
        );
        expect(noCopy.get('[data-field="request-id"]').text()).toBe(
            'req-8f3a2c',
        );
    });
});
