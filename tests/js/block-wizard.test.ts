// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import {
    BLOCK_TYPES,
    defaultDisplay,
    validateDisplay,
} from '../../resources/js/lib/blockWizard';
import type { WizardDraft } from '../../resources/js/lib/blockWizard';
import { createCatalogue } from '../../resources/js/lib/i18n';
import en from '../../resources/js/locales/en';
import { blockWizardLabels as labels } from '../../resources/js/locales/labels';
import { useToasts } from '../../resources/js/stores/toasts';

// Story 3.4: the Create block wizard: the progress nav, Step 1 validation and save, the display options and their limits, the live
// preview with its device toggles and size pill, the gated Publish and the reopen / not-found states.
const page = reactive<{ url: string; props: Record<string, unknown> }>({
    url: '/admin/blocks/create',
    props: {},
});

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    Head: defineComponent({ setup: () => () => null }),
    Link: defineComponent({
        props: ['href'],
        setup:
            (props, { slots, attrs }) =>
            () =>
                h('a', { ...attrs, href: props.href }, slots.default?.()),
    }),
    router: { visit: vi.fn(), on: () => () => {} },
}));

const { default: BlockWizard } =
    await import('../../resources/js/pages/admin/BlockWizard.vue');
const { default: WizardStepper } =
    await import('../../resources/js/components/wizard/WizardStepper.vue');

const CATEGORIES = [
    { id: 'c-finance', name: 'Finance', archived: false },
    { id: 'c-general', name: 'General', archived: false },
];

function draft(overrides: Partial<WizardDraft> = {}): WizardDraft {
    return {
        draft_id: 'd-1',
        block_id: 'b-1',
        name: 'Revenue today',
        type: 'kpi-card',
        category: 'General',
        category_id: 'c-general',
        version: '1.0',
        state: 'draft',
        revision: 1,
        lock_epoch: 1,
        step: 1,
        owner_membership_id: 'm-1',
        owner: null,
        created_at: '2026-10-01T09:30:00Z',
        updated_at: '2026-10-02T10:00:00Z',
        config: { schema_version: 1 },
        ...overrides,
    };
}

type Reply = { ok: boolean; status: number; json: unknown };
type Call = { url: string; method: string; body: Record<string, any> | null };
const calls: Call[] = [];
let replies: Array<Reply | Promise<Reply>> = [];
const ok = (json: unknown): Reply => ({ ok: true, status: 200, json });
const refused = (status: number, json: unknown = {}): Reply => ({
    ok: false,
    status,
    json,
});
let wrapper: VueWrapper | null = null;

function withShell(can: Record<string, boolean>): void {
    page.props = { shell: { area: 'admin', items: [], can } };
}

async function mountPage(search = '') {
    window.history.replaceState({}, '', `/admin/blocks/create${search}`);
    wrapper = mount(BlockWizard as never, {
        attachTo: document.body,
        global: { plugins: [createCatalogue()] },
    });
    await flushPromises();
}

const $ = (selector: string) => document.querySelector<HTMLElement>(selector);
const $$ = (selector: string) =>
    Array.from(document.querySelectorAll<HTMLElement>(selector));

async function type(selector: string, value: string): Promise<void> {
    const input = $(selector) as HTMLInputElement | HTMLTextAreaElement;

    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
}

async function click(element: Element | null): Promise<void> {
    (element as HTMLElement).click();
    await flushPromises();
}

beforeEach(() => {
    setActivePinia(createPinia());
    withShell({ 'blocks.edit': true, 'blocks.publish': true });
    calls.length = 0;
    replies = [];
    vi.stubGlobal(
        'fetch',
        vi.fn(
            async (url: string, init?: { method?: string; body?: string }) => {
                calls.push({
                    url,
                    method: init?.method ?? 'GET',
                    body: init?.body ? JSON.parse(init.body) : null,
                });
                const next = replies.shift();

                if (!next) {
                    throw new Error(`unexpected request ${url}`);
                }

                const reply = await next;

                return {
                    ok: reply.ok,
                    status: reply.status,
                    json: async () => reply.json,
                };
            },
        ),
    );
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    resetAnnouncer();
    vi.unstubAllGlobals();
});

describe('Create block wizard: opening', () => {
    it('opens on Step 1 with General selected, the header, the Draft badge and Kpi card the only type', async () => {
        replies = [ok({ data: CATEGORIES })];
        await mountPage();

        expect($('[data-test=breadcrumb]')?.textContent).toBe(
            'Block management / Create block',
        );
        expect($('[data-test=title]')?.textContent?.trim()).toBe(
            'Untitled block',
        );
        expect($('[data-test=draft-badge]')?.textContent).toBe('• Draft');
        expect(($('[data-test=category]') as HTMLSelectElement).value).toBe(
            'c-general',
        );
        expect(
            $$('[data-test=type] option').map((o) => o.getAttribute('value')),
        ).toEqual(BLOCK_TYPES.map((t) => t.key));
        expect(calls.map((c) => c.url)).toEqual([
            '/api/v1/admin/blocks/categories',
        ]);
        expect($('[aria-current=step]')?.getAttribute('data-step')).toBe('1');
    });

    it('loads a Draft from ?draft=&step= at its stored step, with the saved values', async () => {
        replies = [
            ok({ data: CATEGORIES }),
            ok({
                data: draft({
                    step: 3,
                    category_id: 'c-finance',
                    config: {
                        schema_version: 1,
                        description: 'Daily revenue',
                        display: { width: 4, height: 'medium' },
                    },
                }),
            }),
        ];
        await mountPage('?draft=d-1');

        expect(calls[1].url).toBe('/api/v1/admin/blocks/drafts/d-1');
        expect($('[aria-current=step]')?.getAttribute('data-step')).toBe('3');
        expect($('[data-test=later-step]')).not.toBeNull();
        expect($('[data-test=size-pill]')?.textContent?.trim()).toBe(
            '4 columns × Medium',
        );

        await click($$('[data-test=step-button]')[0]);
        expect(($('[data-test=name]') as HTMLInputElement).value).toBe(
            'Revenue today',
        );
        expect(
            ($('[data-test=description]') as HTMLTextAreaElement).value,
        ).toBe('Daily revenue');
        expect(($('[data-test=category]') as HTMLSelectElement).value).toBe(
            'c-finance',
        );
    });

    it('shows a not-found state for an unknown or discarded Draft', async () => {
        replies = [ok({ data: CATEGORIES }), refused(404)];
        await mountPage('?draft=gone');

        expect($('[data-slot=draft-not-found]')?.textContent).toContain(
            labels.notFound,
        );
        expect($('[data-slot=draft-not-found] a')?.getAttribute('href')).toBe(
            '/admin/blocks/drafts',
        );
        expect($('[data-test=step-1]')).toBeNull();
    });
});

describe('Create block wizard: progress', () => {
    function stepper(current: number, reached: number) {
        return mount(WizardStepper as never, {
            props: {
                steps: labels.steps.map((s) => ({
                    ...s,
                    summary:
                        s.key === 'configure' ? 'General · 3 × Small' : null,
                })),
                current,
                reached,
            },
            attachTo: document.body,
            global: { plugins: [createCatalogue()] },
        });
    }

    it('is a nav named Create block progress with an ordered list of five steps', () => {
        wrapper = stepper(1, 1);
        const nav = $('nav');

        expect(nav?.getAttribute('aria-label')).toBe('Create block progress');
        expect(nav?.querySelectorAll('ol > li')).toHaveLength(5);
        expect(
            $$('li').map(
                (li) =>
                    li.querySelector('button')?.getAttribute('aria-label') ??
                    li.textContent?.trim().replace(/\s+/g, ' '),
            ),
        ).toEqual([
            '1. Configure Block, not completed',
            '2Data Source (not started)',
            '3Map Data (not started)',
            '4Preview (not started)',
            '5Save/Publish (not started)',
        ]);
        expect($$('[aria-current=step]')).toHaveLength(1);
    });

    it('collapses a completed step to a summary card with a check and Edit, and names the buttons', () => {
        wrapper = stepper(2, 2);
        const first = $('li[data-step="1"]') as HTMLElement;

        expect(first.querySelector('[data-test=step-check]')).not.toBeNull();
        expect(first.querySelector('[data-test=step-edit]')?.textContent).toBe(
            'Edit',
        );
        expect(
            first
                .querySelector('[data-test=step-summary]')
                ?.textContent?.trim(),
        ).toBe('General · 3 × Small');
        expect(first.querySelector('button')?.getAttribute('aria-label')).toBe(
            '1. Configure Block, completed',
        );
        expect($('li[data-step="2"]')?.getAttribute('aria-current')).toBe(
            'step',
        );
        expect($('li[data-step="2"] button')?.getAttribute('aria-label')).toBe(
            '2. Data Source, not completed',
        );
        expect($('li[data-step="3"] button')).toBeNull();
    });

    it('jumps back to a completed step and not forward past the first incomplete one', async () => {
        const go = vi.fn();

        wrapper = mount(WizardStepper as never, {
            props: { steps: labels.steps, current: 2, reached: 2, onGo: go },
            attachTo: document.body,
            global: { plugins: [createCatalogue()] },
        });
        await click($('li[data-step="1"] button'));
        expect(go).toHaveBeenCalledWith(1);
        expect($$('button')).toHaveLength(2);
    });
});

describe('Create block wizard: Step 1', () => {
    it('shows inline errors for empty required fields on Continue, focuses the first and keeps the other values', async () => {
        replies = [ok({ data: CATEGORIES })];
        await mountPage();
        await type('[data-test=width]', '5');
        await click($('[data-test=continue]'));

        const errors = $$('[data-slot=field-error]').map((e) =>
            e.textContent?.replace(/\s+/g, ' ').trim(),
        );

        expect(errors).toEqual([
            `Error:${labels.nameRequired}`,
            `Error:${labels.descriptionRequired}`,
        ]);
        expect(document.activeElement?.id).toBe('wizard-name');
        expect(($('[data-test=width]') as HTMLInputElement).value).toBe('5');
        expect(calls).toHaveLength(1);
    });

    it('focuses the description when only it is missing', async () => {
        replies = [ok({ data: CATEGORIES })];
        await mountPage();
        await type('[data-test=name]', 'Revenue today');
        await click($('[data-test=continue]'));

        expect(document.activeElement?.id).toBe('wizard-description');
        expect(calls).toHaveLength(1);
    });

    it('starts the Draft on Continue, saves the config, and moves to Step 2 with Step 1 completed', async () => {
        replies = [
            ok({ data: CATEGORIES }),
            { ok: true, status: 201, json: { data: draft() } },
            ok({ data: draft({ revision: 2, step: 2 }) }),
        ];
        await mountPage();
        await type('[data-test=name]', 'Revenue today');
        await type('[data-test=description]', 'Daily revenue');
        await click($('[data-test=show_footer_action]'));
        await click($('[data-test=continue]'));

        expect(calls[1]).toMatchObject({
            method: 'POST',
            url: '/api/v1/admin/blocks/drafts',
            body: { name: 'Revenue today', type: 'kpi-card' },
        });
        expect(calls[2].method).toBe('PUT');
        expect(calls[2].url).toBe('/api/v1/admin/blocks/drafts/d-1');
        expect(calls[2].body).toMatchObject({
            expected_revision: 1,
            lock_epoch: 1,
            step: 2,
            category_id: 'c-general',
            config: {
                schema_version: 1,
                description: 'Daily revenue',
                display: {
                    width: 3,
                    height: 'small',
                    show_footer_action: true,
                    period: 'follow_dashboard',
                },
            },
        });
        expect($('li[data-step="2"]')?.getAttribute('aria-current')).toBe(
            'step',
        );
        expect($('li[data-step="1"] [data-test=step-check]')).not.toBeNull();
        expect(window.location.search).toBe('?draft=d-1&step=2');
    });

    it('Save draft needs only a name, and saves at the same step', async () => {
        replies = [
            ok({ data: CATEGORIES }),
            { ok: true, status: 201, json: { data: draft() } },
            ok({ data: draft({ revision: 2 }) }),
        ];
        await mountPage();
        await click($('[data-test=save-draft]'));
        expect($('[data-slot=field-error]')?.textContent).toContain(
            labels.nameRequired,
        );
        expect(calls).toHaveLength(1);

        await type('[data-test=name]', 'Revenue today');
        await click($('[data-test=save-draft]'));
        expect(calls[2].body).toMatchObject({ step: 1 });
        expect($('[data-slot=field-error]')).toBeNull();
    });

    it('refuses a width outside the registry limits with the allowed range, on the client and from the server', async () => {
        const type1 = BLOCK_TYPES[0];

        expect(
            validateDisplay({ ...defaultDisplay(type1), width: 8 }, type1)
                .width,
        ).toBe('Use 2 to 6 columns.');
        expect(
            validateDisplay(
                { ...defaultDisplay(type1), height: 'large' },
                type1,
            ).height,
        ).toBe('Use a height from Small to Medium.');
        expect(validateDisplay(defaultDisplay(type1), type1)).toEqual({});

        replies = [ok({ data: CATEGORIES })];
        await mountPage();
        await type('[data-test=name]', 'Revenue today');
        await type('[data-test=width]', '8');
        await click($('[data-test=save-draft]'));

        expect($$('[data-slot=field-error]').map((e) => e.textContent)).toEqual(
            [expect.stringContaining('Use 2 to 6 columns.')],
        );
        expect(document.activeElement?.id).toBe('wizard-width');
        expect(calls).toHaveLength(1);

        // A server 422 lands on the field too.
        await type('[data-test=width]', '4');
        replies = [
            { ok: true, status: 201, json: { data: draft() } },
            refused(422, {
                error: { code: 'blocks.invalid_config' },
                errors: {
                    'config.display.max_width': [
                        'Use 2 to 6 columns for a KPI card.',
                    ],
                },
            }),
        ];
        await click($('[data-test=save-draft]'));
        expect($('#wizard-max-width-error')?.textContent).toContain(
            'Use 2 to 6 columns for a KPI card.',
        );
    });

    it('shows the generic failure toast, not silence, for a 422 no field can show', async () => {
        replies = [
            ok({ data: CATEGORIES }),
            { ok: true, status: 201, json: { data: draft() } },
            refused(422, {
                error: { code: 'blocks.invalid_config' },
                errors: { 'config.icon': ['Choose an icon from the list.'] },
            }),
        ];
        await mountPage();
        await type('[data-test=name]', 'Revenue today');
        await click($('[data-test=save-draft]'));

        expect($$('[data-slot=field-error]')).toHaveLength(0);
        expect(useToasts().items.map((t) => t.message)).toEqual([
            en['save-failed'].form,
        ]);
    });

    it('offers the period behaviours and the display options', async () => {
        replies = [ok({ data: CATEGORIES })];
        await mountPage();

        expect(
            $$('[data-test=period] option').map((o) => o.textContent?.trim()),
        ).toEqual([
            'Follow dashboard default',
            'Own selector',
            'Not date-filtered',
        ]);
        expect(
            $$('[data-test=height] option').map((o) => o.textContent?.trim()),
        ).toEqual(['Small', 'Medium (360 px)', 'Large']);
        for (const label of [
            labels.showHeader,
            labels.showSubtitle,
            labels.allowResize,
            labels.allowMinimize,
            labels.allowRefresh,
            labels.showFooterAction,
        ]) {
            expect(document.body.textContent).toContain(label);
        }
    });

    it('changes the icon from the popover grid, and goes back to the category icon', async () => {
        replies = [ok({ data: CATEGORIES })];
        await mountPage();
        expect(document.body.textContent).toContain(
            'From the General category',
        );

        await click($('[data-test=icon-change]'));
        const popover = $('[data-slot=icon-popover]');

        expect(popover?.className).toContain('w-80');
        expect($('[data-test=icon-grid]')?.className).toContain('grid-cols-6');
        expect($$('[data-test=icon-tile]').length).toBeGreaterThan(6);

        await type('[data-test=icon-search]', 'wallet');
        expect($$('[data-test=icon-tile]')).toHaveLength(1);
        await click($('[data-test=icon-tile]'));

        expect($('[data-slot=icon-popover]')).toBeNull();
        expect(document.body.textContent).toContain('Chosen for this block');
        await click($('[data-test=icon-reset]'));
        expect(document.body.textContent).toContain(
            'From the General category',
        );
    });
});

describe('Create block wizard: live preview', () => {
    it('renders the kpi-card loading skeleton, the size pill, version-safe and the pane copy', async () => {
        replies = [ok({ data: CATEGORIES })];
        await mountPage();

        expect($('[data-slot=live-preview] h2')?.textContent).toBe(
            'Live preview',
        );
        expect($('[data-slot=live-preview]')?.textContent).toContain(
            'Changes appear here automatically.',
        );
        expect($('[data-test=kpi-loading]')).not.toBeNull();
        expect($('[data-test=size-pill]')?.textContent?.trim()).toBe(
            '3 columns × Small',
        );
        expect($('[data-test=version-safe]')?.textContent).toBe(
            en['version-safe'].replace('{version}', '1.0'),
        );
        expect($('[data-test=preview-column]')?.className).toContain(
            'lg:sticky',
        );
    });

    it('re-renders on every change: the size pill and the card follow the form', async () => {
        replies = [ok({ data: CATEGORIES })];
        await mountPage();
        await type('[data-test=width]', '5');
        const height = $('[data-test=height]') as HTMLSelectElement;

        height.value = 'medium';
        height.dispatchEvent(new Event('change', { bubbles: true }));
        await flushPromises();

        expect($('[data-test=size-pill]')?.textContent?.trim()).toBe(
            '5 columns × Medium',
        );
        expect($('[data-height=medium]')?.getAttribute('style')).toContain(
            '360px',
        );
    });

    it('has Desktop, Tablet and Mobile toggles that change the canvas width and not the pill', async () => {
        replies = [ok({ data: CATEGORIES })];
        await mountPage();
        const toggles = $$('[data-slot=live-preview] [role=radio]');

        expect(toggles.map((t) => t.textContent?.trim())).toEqual([
            'Desktop',
            'Tablet',
            'Mobile',
        ]);
        expect(
            $('[data-test=preview-canvas]')?.getAttribute('style'),
        ).toContain('width: 100%');

        await click(toggles[1]);
        expect($('[data-test=preview-canvas]')?.dataset.device).toBe('tablet');
        expect(
            $('[data-test=preview-canvas]')?.getAttribute('style'),
        ).toContain('480px');
        expect($('[data-test=size-pill]')?.textContent?.trim()).toBe(
            '3 columns × Small',
        );

        await click(toggles[2]);
        expect(
            $('[data-test=preview-canvas]')?.getAttribute('style'),
        ).toContain('320px');
    });

    it('is not shown after Step 3', async () => {
        replies = [ok({ data: CATEGORIES }), ok({ data: draft({ step: 4 }) })];
        await mountPage('?draft=d-1');

        expect($('[data-slot=live-preview]')).toBeNull();
    });
});

describe('Create block wizard: Publish', () => {
    it('is blocked with perm-publish without blocks.publish, while Save draft still works', async () => {
        withShell({ 'blocks.edit': true, 'blocks.publish': false });
        replies = [
            ok({ data: CATEGORIES }),
            { ok: true, status: 201, json: { data: draft() } },
            ok({ data: draft({ revision: 2 }) }),
        ];
        await mountPage();
        const publish = $('[data-slot=gated-action]') as HTMLElement;

        expect(publish.textContent).toContain('Publish block');
        expect(publish.getAttribute('aria-disabled')).toBe('true');
        expect(document.body.textContent).toContain(en['perm-publish']);

        await click(publish);
        expect(calls).toHaveLength(1);

        await type('[data-test=name]', 'Revenue today');
        await click($('[data-test=save-draft]'));
        expect(calls.map((c) => c.method)).toEqual(['GET', 'POST', 'PUT']);
    });

    it('shows no reason when the member holds blocks.publish', async () => {
        replies = [ok({ data: CATEGORIES })];
        await mountPage();

        expect(
            $('[data-slot=gated-action]')?.getAttribute('aria-disabled'),
        ).not.toBe('true');
        expect(document.body.textContent).not.toContain(en['perm-publish']);
    });
});
