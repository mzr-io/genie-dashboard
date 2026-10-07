// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import HelpContent from '../../resources/js/components/HelpContent.vue';
import { createCatalogue } from '../../resources/js/lib/i18n';
import { helpLabels } from '../../resources/js/locales/labels';

let wrapper: VueWrapper | null = null;

function mountHelp(props: {
    helpLinks: { label: string; url: string }[];
    contactHref: string | null;
}) {
    wrapper = mount(HelpContent, {
        attachTo: document.body,
        props,
        global: { plugins: [createCatalogue()] },
    });

    return wrapper;
}

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
});

describe('Help & support content', () => {
    it('lists links as escaped text that open in a new tab with rel noopener noreferrer', () => {
        mountHelp({
            helpLinks: [
                {
                    label: '<img src=x onerror=alert(1)> Guide',
                    url: 'https://docs.example.test/start',
                },
                { label: 'Status', url: 'http://status.example.test' },
            ],
            contactHref: null,
        });

        const links = document.querySelectorAll<HTMLAnchorElement>(
            '[data-test="help-link"]',
        );
        expect(links).toHaveLength(2);

        for (const link of links) {
            expect(link.getAttribute('target')).toBe('_blank');
            expect(link.getAttribute('rel')).toBe('noopener noreferrer');
        }

        expect(links[0].textContent).toContain(
            '<img src=x onerror=alert(1)> Guide',
        );
        expect(document.querySelector('img')).toBeNull();
        expect(links[0].getAttribute('href')).toBe(
            'https://docs.example.test/start',
        );
        expect(document.querySelector('[data-slot="list-empty"]')).toBeNull();
    });

    it('shows msg:list-empty for an empty list', () => {
        mountHelp({ helpLinks: [], contactHref: null });

        expect(
            document.querySelector('[data-slot="list-empty"]')?.textContent,
        ).toBe('No help topics yet. Ask your workspace admin to start.');
        expect(
            document.querySelectorAll('[data-test="help-link"]'),
        ).toHaveLength(0);
    });

    it('never renders a link that is not http or https', () => {
        mountHelp({
            helpLinks: [
                { label: 'Script', url: 'javascript:alert(1)' },
                { label: 'Data', url: 'data:text/html,hi' },
                { label: 'Relative', url: '/etc' },
                { label: '', url: 'https://blank.example.test' },
            ],
            contactHref: 'javascript:alert(1)',
        });

        expect(document.querySelectorAll('a')).toHaveLength(0);
        expect(
            document.querySelector('[data-slot="list-empty"]'),
        ).not.toBeNull();
    });

    it('links the contact line to the configured address and is plain text otherwise', () => {
        mountHelp({
            helpLinks: [],
            contactHref: 'https://help.example.test/contact',
        });

        const link = document.querySelector<HTMLAnchorElement>(
            'a[data-test="help-contact"]',
        )!;
        expect(link.textContent).toBe(helpLabels.contact);
        expect(link.getAttribute('href')).toBe(
            'https://help.example.test/contact',
        );
        expect(link.getAttribute('rel')).toBe('noopener noreferrer');

        wrapper!.unmount();
        mountHelp({ helpLinks: [], contactHref: null });

        expect(
            document.querySelector('a[data-test="help-contact"]'),
        ).toBeNull();
        expect(
            document
                .querySelector('p[data-test="help-contact"]')
                ?.textContent?.trim(),
        ).toBe(helpLabels.contact);
    });

    it('opens a mailto contact in the same tab', () => {
        mountHelp({ helpLinks: [], contactHref: 'mailto:admin@example.test' });

        const link = document.querySelector<HTMLAnchorElement>(
            'a[data-test="help-contact"]',
        )!;
        expect(link.getAttribute('href')).toBe('mailto:admin@example.test');
        expect(link.getAttribute('target')).toBeNull();
    });
});
