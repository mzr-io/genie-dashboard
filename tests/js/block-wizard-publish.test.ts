// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';

const page = reactive<{ url: string; props: Record<string, unknown> }>({
    url: '/admin/blocks/create',
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

import ValidateStep from '../../resources/js/components/wizard/ValidateStep.vue';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import type { ValidationReport } from '../../resources/js/lib/blockValidation';
import { createCatalogue } from '../../resources/js/lib/i18n';
import en from '../../resources/js/locales/en';

// Story 3.19: Step 5's Publish action. The component is mounted alone with the network stubbed: the permission gate (`perm-publish`), the report gate,
// the request (lock claim only), the version it reports and the refusals the server can answer.
const BASE = '/api/v1/admin/blocks/drafts/d-1';

type Reply = { status: number; json: unknown };

let wrapper: VueWrapper | null = null;
let calls: { url: string; method: string; body: unknown }[] = [];
let report: () => Reply;
let publish: () => Reply;
let saved: number;
let onSave: () => void;

function reportOf(over: Partial<ValidationReport> = {}): ValidationReport {
    return {
        id: 'r-1',
        status: 'passed',
        stored_status: 'passed',
        reason: null,
        subject_revision: 12,
        draft_revision: 12,
        mismatches: [],
        version: '1.0',
        endpoint_id: 'e-1',
        endpoint_revision: 2,
        validated_at: '2026-10-12T09:14:00Z',
        can_publish: true,
        ...over,
    };
}

const $ = <T extends HTMLElement = HTMLElement>(selector: string): T | null =>
    document.body.querySelector<T>(selector);
const text = (selector: string): string =>
    ($(selector)?.textContent ?? '').replace(/\s+/g, ' ').trim();

function can(permissions: Record<string, boolean>): void {
    page.props = { shell: { area: 'admin', items: [], can: permissions } };
}

async function mountStep(
    props: Record<string, unknown> = {},
): Promise<VueWrapper> {
    wrapper = mount(ValidateStep, {
        attachTo: document.body,
        props: {
            draftId: 'd-1',
            typeKey: 'kpi-card',
            version: '1.0',
            revision: 12,
            hasSource: true,
            pasted: false,
            fetchBody: () => ({
                data_source_id: 's-1',
                endpoint_id: 'e-1',
                bindings: {},
                range: null,
                period: null,
                request_key: 'k',
            }),
            claim: () => ({ lock_epoch: 1, lock_token: 'tok' }),
            save: async () => {
                saved += 1;
                onSave();

                return true;
            },
            ...props,
        },
        global: { plugins: [createCatalogue()] },
    });
    await flushPromises();

    return wrapper;
}

async function clickPublish(): Promise<void> {
    $('[data-test=publish-step]')!.click();
    await flushPromises();
    await flushPromises();
}

const published = {
    data: {
        draft_id: 'd-1',
        block_id: 'b-1',
        version_id: 'd-1',
        name: 'Revenue overview',
        type: 'kpi-card',
        category: 'Finance',
        version: '1.0',
        state: 'published',
        published_at: '2026-10-12T09:20:00Z',
    },
};

beforeEach(() => {
    calls = [];
    saved = 0;
    onSave = () => undefined;
    can({ 'blocks.edit': true, 'blocks.publish': true });
    report = () => ({ status: 200, json: { data: reportOf() } });
    publish = () => ({ status: 200, json: published });
    vi.stubGlobal(
        'fetch',
        vi.fn(
            async (url: string, init?: { method?: string; body?: string }) => {
                const method = init?.method ?? 'GET';
                const body = init?.body ? JSON.parse(init.body) : null;

                calls.push({ url, method, body });

                const reply: Reply =
                    url === `${BASE}/publish` && method === 'POST'
                        ? publish()
                        : url === `${BASE}/validation`
                          ? report()
                          : { status: 404, json: {} };

                return {
                    ok: reply.status < 400,
                    status: reply.status,
                    headers: { get: () => null },
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

const posts = (): typeof calls => calls.filter((c) => c.method === 'POST');

describe('Step 5: Publish', () => {
    it('publishes with the lock claim only, saves first and reports the published version', async () => {
        const mounted = await mountStep();

        expect(
            $('[data-test=publish-step]')!.getAttribute('aria-disabled'),
        ).toBeNull();
        expect($('[data-test=publish-denied]')).toBeNull();

        await clickPublish();

        expect(saved).toBe(1);
        expect(posts()).toEqual([
            {
                url: `${BASE}/publish`,
                method: 'POST',
                body: { lock_epoch: 1, lock_token: 'tok' },
            },
        ]);
        expect(mounted.emitted('published')).toHaveLength(1);
        expect(mounted.emitted('published')![0][0]).toMatchObject({
            block_id: 'b-1',
            name: 'Revenue overview',
            version: '1.0',
            category: 'Finance',
        });
    });

    it('is disabled with perm-publish without blocks.publish, runs nothing, and leaves Save draft alone', async () => {
        can({ 'blocks.edit': true, 'blocks.publish': false });
        const mounted = await mountStep();

        expect(
            $('[data-test=publish-step]')!.getAttribute('aria-disabled'),
        ).toBe('true');
        expect(text('[data-test=publish-denied]')).toBe(en['perm-publish']);
        expect(
            $('[data-test=publish-step]')!.getAttribute('aria-describedby'),
        ).toBe($('[data-test=publish-denied]')!.id);

        await clickPublish();

        expect(posts()).toEqual([]);
        expect(mounted.emitted('published')).toBeUndefined();
        expect(
            $('[data-test=save-draft-step]')!.getAttribute('aria-disabled'),
        ).toBeNull();
    });

    it('stays blocked, with the reason, until a report for the current revision has passed', async () => {
        report = () => ({ status: 200, json: { data: null } });
        const mounted = await mountStep();

        expect(
            $('[data-test=publish-step]')!.getAttribute('aria-disabled'),
        ).toBe('true');
        expect(text('[data-test=publish-reason]')).toContain(
            'Validate the block against the real API',
        );

        await clickPublish();

        expect(posts()).toEqual([]);
        expect(mounted.emitted('published')).toBeUndefined();
    });

    it('does not publish when the save it makes first moves the revision past the report', async () => {
        const mounted = await mountStep();
        onSave = () => void mounted.setProps({ revision: 13 });

        await clickPublish();

        expect(saved).toBe(1);
        expect(posts()).toEqual([]);
        expect(text('[data-test=validate-problem]')).toContain(
            'changed while it was saved',
        );
        expect(mounted.emitted('published')).toBeUndefined();
    });

    it('says the block has not passed validation on a 409 publish_not_ready and reads the report again', async () => {
        publish = () => ({
            status: 409,
            json: {
                error: { code: 'blocks.publish_not_ready' },
                reason: 'report_stale',
            },
        });
        const mounted = await mountStep();
        const before = calls.filter(
            (c) => c.url === `${BASE}/validation`,
        ).length;

        await clickPublish();

        expect(text('[data-test=validate-problem]')).toContain(
            'has not passed validation',
        );
        expect(calls.filter((c) => c.url === `${BASE}/validation`).length).toBe(
            before + 1,
        );
        expect(mounted.emitted('published')).toBeUndefined();
    });

    it('hands a published Draft (409 draft_published) and a lost lock (423) to the page', async () => {
        publish = () => ({
            status: 409,
            json: { error: { code: 'blocks.draft_published' } },
        });
        const mounted = await mountStep();

        await clickPublish();

        expect(mounted.emitted('error')).toHaveLength(1);
        expect(mounted.emitted('published')).toBeUndefined();

        publish = () => ({
            status: 423,
            json: { error: { code: 'platform.edit_lock_lost' } },
        });
        await clickPublish();

        expect(mounted.emitted('error')).toHaveLength(2);
    });

    it('words a 403 without a code and a failure as a retry', async () => {
        publish = () => ({
            status: 403,
            json: { error: { code: 'access.not_authorized' } },
        });
        await mountStep();

        await clickPublish();

        expect(text('[data-test=validate-problem]')).toBe(en['perm-publish']);

        publish = () => ({ status: 500, json: {} });
        await clickPublish();

        expect(text('[data-test=validate-problem]')).toContain(
            'could not be published',
        );
    });

    it('keeps Publish busy after a success, so a second click asks for nothing', async () => {
        await mountStep();

        await clickPublish();
        await clickPublish();

        expect(posts()).toHaveLength(1);
        expect(
            $('[data-test=publish-step]')!.getAttribute('aria-disabled'),
        ).toBe('true');
    });

    it('says the block is saved as ready on publish_pending, not a save failure, and publishing again retries', async () => {
        publish = () => ({
            status: 503,
            json: { error: { code: 'blocks.publish_pending' } },
        });
        const mounted = await mountStep();

        await clickPublish();

        expect(text('[data-test=validate-problem]')).toContain(
            'saved as ready',
        );
        expect(mounted.emitted('error')).toBeUndefined();
        expect(mounted.emitted('published')).toBeUndefined();

        publish = () => ({ status: 200, json: published });
        await clickPublish();

        expect(posts()).toHaveLength(2);
        expect(mounted.emitted('published')).toHaveLength(1);
    });

    it('words fields_unresolved and block_type_unavailable specifically', async () => {
        publish = () => ({
            status: 409,
            json: {
                error: { code: 'blocks.publish_not_ready' },
                reason: 'fields_unresolved',
            },
        });
        await mountStep();

        await clickPublish();

        expect(text('[data-test=validate-problem]')).toBe(
            'Refresh the sample, then publish again.',
        );

        publish = () => ({
            status: 409,
            json: {
                error: { code: 'blocks.publish_not_ready' },
                reason: 'block_type_unavailable',
            },
        });
        await clickPublish();

        expect(text('[data-test=validate-problem]')).toContain(
            'no longer available',
        );
    });
});
