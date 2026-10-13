// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import TemplatesSection from '../../resources/js/components/wizard/TemplatesSection.vue';
import { resetAnnouncer } from '../../resources/js/lib/announce';

// Story 5.4: the Text templates section of Map Data.
const slots = [
    { slot: 'kpi_label', label: 'KPI label' },
    { slot: 'subtitle', label: 'Subtitle' },
];
const fields = [
    { path: '$.data[*].status', display: 'data[].status' },
    { path: '$.data[*].revenue', display: 'data[].revenue' },
];

describe('TemplatesSection', () => {
    let wrapper: VueWrapper | null = null;

    afterEach(() => {
        wrapper?.unmount();
        wrapper = null;
        document.body.innerHTML = '';
        resetAnnouncer();
    });

    function mountSection(props: Record<string, unknown> = {}): VueWrapper {
        wrapper = mount(TemplatesSection as never, {
            props: {
                templates: { kpi_label: '{ROW_COUNT} requests' },
                slots,
                fields,
                errors: {},
                ...props,
            },
            attachTo: document.body,
        });

        return wrapper;
    }

    it('draws nothing while no slot has a template', async () => {
        mountSection({ templates: {} });
        await flushPromises();

        expect(
            document.querySelector('[data-test=templates-section]'),
        ).toBeNull();
    });

    it('names each template field and describes it', async () => {
        mountSection();
        await flushPromises();

        const input = document.querySelector(
            '[data-test=template-input]',
        ) as HTMLInputElement;

        expect(input.getAttribute('aria-label')).toBe(
            'Text template for KPI label',
        );
        expect(input.value).toBe('{ROW_COUNT} requests');
        expect(
            document.getElementById(
                input.getAttribute('aria-describedby') ?? '',
            )?.textContent,
        ).toContain('{period.start|MMM d}');
    });

    it('saves on change and on Enter, only when the text changed', async () => {
        const w = mountSection();
        const input = document.querySelector(
            '[data-test=template-input]',
        ) as HTMLInputElement;

        input.dispatchEvent(new Event('change', { bubbles: true }));
        expect(w.emitted('change')).toBeUndefined();

        input.value = '{category} · {days} days';
        input.dispatchEvent(new Event('change', { bubbles: true }));
        await flushPromises();

        expect(w.emitted('change')![0]).toEqual([
            'kpi_label',
            '{category} · {days} days',
        ]);
    });

    it('inserts a token at the caret and saves with it', async () => {
        const w = mountSection({ templates: { kpi_label: 'Total  items' } });
        const input = document.querySelector(
            '[data-test=template-input]',
        ) as HTMLInputElement;

        input.focus();
        input.setSelectionRange(6, 6);
        (
            document.querySelector('[data-test=token-rowCount]') as HTMLElement
        ).click();
        await flushPromises();

        expect(w.emitted('change')![0]).toEqual([
            'kpi_label',
            'Total {ROW_COUNT} items',
        ]);

        const select = document.querySelector(
            '[data-test=token-field]',
        ) as HTMLSelectElement;
        const format = document.querySelector(
            '[data-test=token-format]',
        ) as HTMLSelectElement;

        select.value = '$.data[*].revenue';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        format.value = 'currency';
        format.dispatchEvent(new Event('change', { bubbles: true }));
        await flushPromises();
        (
            document.querySelector(
                '[data-test=token-insert-field]',
            ) as HTMLElement
        ).click();
        await flushPromises();

        expect(w.emitted('change')![1][1] as string).toContain(
            '{revenue|currency}',
        );
    });

    it("shows the server's refusal naming the token, and blocks every control while read-only", async () => {
        const w = mountSection({
            errors: {
                kpi_label:
                    '{ghost|currency}: ghost isn’t a field of the response (position 7).',
            },
        });
        await flushPromises();

        expect(
            document.querySelector('[data-test=template-error]')?.textContent,
        ).toContain('{ghost|currency}');
        expect(
            document
                .querySelector('[data-test=template-input]')
                ?.getAttribute('aria-invalid'),
        ).toBe('true');

        await w.setProps({ blocked: 'Read only' });

        expect(
            (
                document.querySelector(
                    '[data-test=template-input]',
                ) as HTMLInputElement
            ).disabled,
        ).toBe(true);
        expect(
            document
                .querySelector('[data-test=template-remove]')
                ?.getAttribute('aria-disabled'),
        ).toBe('true');
    });

    it('removes a template and says Escape to the page', async () => {
        const w = mountSection();

        (
            document.querySelector('[data-test=template-remove]') as HTMLElement
        ).click();
        await flushPromises();
        expect(w.emitted('remove')![0]).toEqual(['kpi_label']);

        (
            document.querySelector('[data-test=template-input]') as HTMLElement
        ).dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        );
        await flushPromises();
        expect(w.emitted('cancel')![0]).toEqual(['kpi_label']);
    });
});
