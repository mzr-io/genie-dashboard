// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ValidateStep from '../../resources/js/components/wizard/ValidateStep.vue';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import {
    effectiveStatus,
    publishReason,
} from '../../resources/js/lib/blockValidation';
import type { ValidationReport } from '../../resources/js/lib/blockValidation';
import { createCatalogue } from '../../resources/js/lib/i18n';
import en from '../../resources/js/locales/en';

// Story 3.18: Step 5, Validate against the real API. The component is mounted alone with the network stubbed: it starts the Operation, polls it,
// reads the report back and says whether Publish is allowed and why not.
const BASE = '/api/v1/admin/blocks/drafts/d-1';
const OPERATION = 'o-1';

type Reply = { status: number; json: unknown };

let wrapper: VueWrapper | null = null;
let calls: { url: string; method: string; body: unknown }[] = [];
let start: () => Reply;
let report: () => Reply;
let operation: () => Reply;
let saved: boolean;

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
                range: { from: '2026-10-01', to: '2026-10-31' },
                period: null,
                request_key: 'k',
            }),
            claim: () => ({ lock_epoch: 1, lock_token: 'tok' }),
            save: async () => {
                saved = true;

                return true;
            },
            ...props,
        },
        global: { plugins: [createCatalogue()] },
    });
    await flushPromises();

    return wrapper;
}

async function validate(): Promise<void> {
    $('[data-test=validate]')!.click();
    await flushPromises();
    await flushPromises();
}

beforeEach(() => {
    calls = [];
    saved = false;
    start = () => ({
        status: 202,
        json: {
            data: {
                operation_id: OPERATION,
                status: 'queued',
                expires_at: '2026-10-12T09:20:00Z',
                draft_revision: 12,
            },
        },
    });
    operation = () => ({
        status: 200,
        json: {
            data: {
                id: OPERATION,
                kind: 'publish_validation',
                status: 'succeeded',
                result: { ok: true, status: 'passed', report_id: 'r-1' },
                expires_at: '2026-10-12T09:20:00Z',
            },
        },
    });
    report = () => ({ status: 200, json: { data: null } });
    vi.stubGlobal(
        'fetch',
        vi.fn(
            async (url: string, init?: { method?: string; body?: string }) => {
                const method = init?.method ?? 'GET';
                const body = init?.body ? JSON.parse(init.body) : null;

                calls.push({ url, method, body });

                const reply: Reply =
                    url === `${BASE}/validation` && method === 'POST'
                        ? start()
                        : url === `${BASE}/validation`
                          ? report()
                          : url.startsWith('/api/v1/operations/')
                            ? operation()
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

describe('the report and Publish readiness', () => {
    it('reads a stored report for another revision as stale', () => {
        const passed = reportOf();

        expect(effectiveStatus(passed, 12)).toBe('passed');
        expect(effectiveStatus(passed, 13)).toBe('stale');
        expect(effectiveStatus(null, 13)).toBeNull();
        expect(publishReason(true, passed, 12, false)).toBeUndefined();
        expect(publishReason(true, passed, 13, false)).toBe('stale');
        expect(publishReason(false, passed, 12, false)).toBe('no_source');
        expect(publishReason(true, null, 12, false)).toBe('not_validated');
        expect(publishReason(true, passed, 12, true)).toBe('running');
        expect(
            publishReason(
                true,
                reportOf({ status: 'failed', reason: 'strict_schema' }),
                12,
                false,
            ),
        ).toBe('strict');
    });
});

describe('Step 5: Validate', () => {
    it('shows the version to create and version-safe, and blocks Publish until the block is validated', async () => {
        await mountStep();

        expect(text('[data-test=version]')).toBe('Version 1.0');
        expect(text('[data-test=version-safe]')).toBe(
            en['version-safe'].replace('{version}', '1.0'),
        );
        expect(
            $('[data-test=publish-step]')!.getAttribute('aria-disabled'),
        ).toBe('true');
        expect(text('[data-test=publish-reason]')).toContain(
            'Validate the block against the real API',
        );
        expect(
            $('[data-test=validate]')!.getAttribute('aria-disabled'),
        ).toBeNull();
        // Save draft is always there.
        expect(
            $('[data-test=save-draft-step]')!.getAttribute('aria-disabled'),
        ).toBeNull();
    });

    it('starts the Operation with the lock claim and the dates only, saves first, and reports a pass bound to the revision', async () => {
        await mountStep();
        report = () => ({ status: 200, json: { data: reportOf() } });
        await validate();

        const post = calls.find((c) => c.method === 'POST')!;

        expect(saved).toBe(true);
        expect(post.url).toBe(`${BASE}/validation`);
        expect(post.body).toEqual({
            range: { from: '2026-10-01', to: '2026-10-31' },
            period: null,
            lock_epoch: 1,
            lock_token: 'tok',
        });
        expect(text('[data-test=result-title]')).toBe('Validated');
        expect(text('[data-test=result-body]')).toContain('Version 1.0');
        expect($('[data-test=publish-readiness]')!.dataset.state).toBe('ready');
        expect(text('[data-test=readiness-title]')).toBe('Ready to publish');
    });

    it('reads the report as stale once the Draft revision moves, and Publish is blocked again', async () => {
        report = () => ({ status: 200, json: { data: reportOf() } });
        const mounted = await mountStep();

        expect($('[data-test=publish-readiness]')!.dataset.state).toBe('ready');

        await mounted.setProps({ revision: 13 });

        expect($('[data-test=publish-readiness]')!.dataset.state).toBe(
            'blocked',
        );
        expect(text('[data-test=result-title]')).toBe('Validate again');
        expect(text('[data-test=publish-reason]')).toContain(
            'changed after it was last validated',
        );
    });

    it('lists the mismatching paths and types of a failed report and keeps Save draft available', async () => {
        report = () => ({
            status: 200,
            json: {
                data: reportOf({
                    status: 'failed',
                    stored_status: 'failed',
                    reason: 'shape_mismatch',
                    can_publish: false,
                    mismatches: [
                        {
                            path: '$.data.label',
                            slot: 'kpi_label',
                            type: 'missing',
                            expected: 'text',
                        },
                        {
                            path: '$.data.revenue',
                            slot: 'headline_value',
                            type: 'wrong_type',
                            expected: 'number',
                        },
                    ],
                }),
            },
        });
        await mountStep();

        const items = [
            ...document.body.querySelectorAll('[data-test=mismatch]'),
        ].map((item) => item.textContent?.trim());

        expect(text('[data-test=result-title]')).toBe(
            'The live response does not match',
        );
        expect(items).toEqual([
            'KPI label: $.data.label is not in the live response.',
            'Headline value: $.data.revenue is not a number in the live response.',
        ]);
        expect(text('[data-test=publish-reason]')).toContain(
            'does not match the mapping',
        );
        expect(
            $('[data-test=save-draft-step]')!.getAttribute('aria-disabled'),
        ).toBeNull();
    });

    it('says the API was unreachable with live-shape-unreachable, and blocks Publish', async () => {
        report = () => ({
            status: 200,
            json: {
                data: reportOf({
                    status: 'unreachable',
                    stored_status: 'unreachable',
                    reason: 'fetch-failed',
                    can_publish: false,
                }),
            },
        });
        await mountStep();

        expect(text('[data-test=result-title]')).toBe(
            'The API could not be reached',
        );
        expect(text('[data-test=result-body]')).toBe(
            en['live-shape-unreachable'],
        );
        expect(text('[data-test=publish-reason]')).toBe(
            en['live-shape-unreachable'],
        );
        expect($('[data-test=publish-readiness]')!.dataset.state).toBe(
            'blocked',
        );
    });

    it('lists the strict-schema faults of a draft that is not complete', async () => {
        report = () => ({
            status: 200,
            json: {
                data: reportOf({
                    status: 'failed',
                    stored_status: 'failed',
                    reason: 'strict_schema',
                    can_publish: false,
                    mismatches: [
                        {
                            path: null,
                            slot: 'kpi_label',
                            type: 'required_slot_unmapped',
                            expected: 'text',
                        },
                    ],
                }),
            },
        });
        await mountStep();

        expect(text('[data-test=result-title]')).toBe(
            'The draft is not complete',
        );
        expect(text('[data-test=mismatch]')).toBe(
            'KPI label is required and is not mapped. Map it in Map Data.',
        );
    });

    it('never accepts a pasted sample as proof: Validate and Publish say why and nothing is requested', async () => {
        await mountStep({ hasSource: false, pasted: true });

        expect($('[data-test=validate]')!.getAttribute('aria-disabled')).toBe(
            'true',
        );
        expect(text('[data-test=validate-reason]')).toContain('pasted sample');
        expect(text('[data-test=publish-reason]')).toContain(
            'A pasted sample is never accepted as proof',
        );

        await validate();

        expect(calls.filter((c) => c.method === 'POST')).toEqual([]);
        expect(
            $('[data-test=save-draft-step]')!.getAttribute('aria-disabled'),
        ).toBeNull();
    });

    it('says when it is over the limit and when a setting is not configured', async () => {
        await mountStep();
        start = () => ({
            status: 429,
            json: {
                error: { code: 'platform.too_many_requests', retry_after: 12 },
            },
        });
        await validate();

        expect(text('[data-test=validate-problem]')).toBe(
            'Too many validations. Try again in 12 seconds.',
        );

        start = () => ({
            status: 422,
            json: {
                error: { code: 'blocks.validation_refused' },
                reason: 'setting_not_configured',
                setting: 'DASHFLOW_PREVIEW_MAX_WALL_MS',
            },
        });
        await validate();

        expect(text('[data-test=validate-problem]')).toContain(
            'DASHFLOW_PREVIEW_MAX_WALL_MS is not configured',
        );
    });

    it('hands a lost lock to the page and shows no result of its own', async () => {
        const mounted = await mountStep();
        start = () => ({
            status: 423,
            json: { error: { code: 'platform.edit_lock_lost' } },
        });
        await validate();

        expect(mounted.emitted('error')).toHaveLength(1);
        expect($('[data-test=validate-problem]')).toBeNull();
    });

    it('does not start when the changes cannot be saved first', async () => {
        await mountStep({ save: async () => false });
        await validate();

        expect(calls.filter((c) => c.method === 'POST')).toEqual([]);
    });

    it('does not present an older report as the result of a run that stored none', async () => {
        report = () => ({ status: 200, json: { data: reportOf() } });
        operation = () => ({
            status: 200,
            json: {
                data: {
                    id: OPERATION,
                    kind: 'publish_validation',
                    status: 'failed',
                    result: { ok: false, report_id: null },
                    expires_at: '2026-10-12T09:20:00Z',
                },
            },
        });
        await mountStep();
        // The earlier report is on screen from the load; the failed run does not replace it with a claim of its own.
        await validate();

        expect(text('[data-test=validate-problem]')).toContain(
            'could not finish',
        );
    });

    it('treats a malformed mismatch list as empty and words an unreachable reason', async () => {
        report = () => ({
            status: 200,
            json: {
                data: {
                    ...reportOf({
                        status: 'unreachable',
                        stored_status: 'unreachable',
                        reason: 'host-not-allowlisted',
                        can_publish: false,
                    }),
                    mismatches: 'oops',
                },
            },
        });
        await mountStep();

        expect(text('[data-test=unreachable-reason]')).toBe(
            'The data source host is not on the allowlist.',
        );
    });

    it('words a guard stop and a missing setting without codes', async () => {
        report = () => ({
            status: 200,
            json: {
                data: reportOf({
                    status: 'failed',
                    stored_status: 'failed',
                    reason: 'guard_exceeded',
                    can_publish: false,
                }),
            },
        });
        await mountStep();

        expect(text('[data-test=result-body]')).toContain('safety limit');
    });
});
