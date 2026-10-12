// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { h } from 'vue';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import {
    createDashboard,
    dashboardPageUrl,
    deleteDashboard,
    duplicateDashboard,
    fetchDashboards,
    renameDashboard,
} from '../../resources/js/lib/dashboards';
import type { DashboardSummary } from '../../resources/js/lib/dashboards';
import { createCatalogue } from '../../resources/js/lib/i18n';

// Story 4.16: the dashboard commands of the client and the My dashboards page.
const visit = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: { render: () => null },
    Link: {
        props: ['href'],
        render() {
            return h(
                'a',
                { href: (this as { href: string }).href },
                (
                    this as { $slots: { default?: () => unknown } }
                ).$slots.default?.() as never,
            );
        },
    },
    router: { visit: (...args: unknown[]) => visit(...args) },
    usePage: () => ({
        url: '/dashboards',
        props: { shell: { role: 'user', items: [], can: {} } },
    }),
}));

const { default: DashboardSwitcher } =
    await import('../../resources/js/components/DashboardSwitcher.vue');
const { default: MyDashboards } =
    await import('../../resources/js/pages/MyDashboards.vue');

type Reply = { ok?: boolean; status: number; json: unknown };
let replies: Reply[] = [];
const calls: Array<{ url: string; method: string; body: unknown }> = [];
let wrapper: VueWrapper | null = null;

const overview: DashboardSummary = {
    dashboard_id: 'd-0',
    name: 'Overview',
    is_overview: true,
    item_count: 0,
    updated_at: '2026-10-12T09:00:00Z',
};
const sales: DashboardSummary = {
    dashboard_id: 'd-1',
    name: 'Sales',
    is_overview: false,
    item_count: 3,
    updated_at: '2026-10-12T10:00:00Z',
};

const $ = (selector: string) => document.querySelector<HTMLElement>(selector);
const $$ = (selector: string) =>
    Array.from(document.querySelectorAll<HTMLElement>(selector));

beforeEach(() => {
    setActivePinia(createPinia());
    replies = [];
    calls.length = 0;
    visit.mockClear();
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

                return {
                    ok: next.ok ?? (next.status >= 200 && next.status < 300),
                    status: next.status,
                    headers: new Headers(),
                    json: async () => next.json,
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

describe('dashboard commands', () => {
    it('addresses the Overview at /dashboard and any other dashboard by id', () => {
        expect(dashboardPageUrl(overview)).toBe('/dashboard');
        expect(dashboardPageUrl(sales)).toBe('/dashboards/d-1');
    });

    it('lists, creates and renames', async () => {
        replies = [
            { status: 200, json: { data: [overview, sales] } },
            { status: 201, json: { data: sales } },
            { status: 200, json: { data: { ...sales, name: 'Revenue' } } },
        ];

        expect(await fetchDashboards()).toEqual([overview, sales]);
        expect(await createDashboard('Sales')).toEqual({
            ok: true,
            dashboard: sales,
        });
        expect(await renameDashboard('d-1', 'Revenue')).toMatchObject({
            ok: true,
        });
        expect(calls.map((c) => [c.method, c.url, c.body])).toEqual([
            ['GET', '/api/v1/dashboards', null],
            ['POST', '/api/v1/dashboards', { name: 'Sales' }],
            ['PUT', '/api/v1/dashboards/d-1/name', { name: 'Revenue' }],
        ]);
    });

    it('tells a refused name from another failure', async () => {
        replies = [
            {
                status: 422,
                json: { error: { code: 'dashboards.invalid_name' } },
            },
            { status: 500, json: {} },
        ];

        expect(await createDashboard('')).toEqual({ ok: false, invalid: true });
        expect(await createDashboard('x')).toEqual({
            ok: false,
            invalid: false,
            status: 500,
        });
    });

    it('reports what a duplicate omitted and refuses to delete the Overview', async () => {
        replies = [
            { status: 201, json: { data: { ...sales, omitted: 2 } } },
            {
                status: 422,
                json: { error: { code: 'dashboards.overview_undeletable' } },
            },
            { status: 200, json: { data: { deleted: true } } },
        ];

        expect(await duplicateDashboard('d-1')).toMatchObject({
            ok: true,
            omitted: 2,
        });
        expect(await deleteDashboard('d-0')).toEqual({
            ok: false,
            overview: true,
        });
        expect(await deleteDashboard('d-1')).toEqual({ ok: true });
    });
});

async function mountPage(): Promise<void> {
    wrapper = mount(MyDashboards as never, {
        attachTo: document.body,
        global: { plugins: [createCatalogue()] },
    });
    await flushPromises();
    await vi.dynamicImportSettled();
    await flushPromises();
}

describe('My dashboards page', () => {
    it("lists the dashboards with Rename, Duplicate and Delete, and disables the Overview's Delete", async () => {
        replies = [{ status: 200, json: { data: [overview, sales] } }];
        await mountPage();

        const rows = $$('[data-slot=data-row]');

        expect(rows).toHaveLength(2);
        expect(rows[0].textContent).toContain('Overview');
        expect(
            rows[0]
                .querySelector('[data-test=delete]')
                ?.getAttribute('aria-disabled'),
        ).toBe('true');
        expect(
            rows[1]
                .querySelector('[data-test=delete]')
                ?.getAttribute('aria-disabled'),
        ).toBeNull();
        expect(
            rows[1]
                .querySelector('[data-test=open-link]')
                ?.getAttribute('href'),
        ).toBe('/dashboards/d-1');
        expect(rows[1].textContent).toContain('3 blocks');
    });

    it('shows a failure row with Retry and loads again', async () => {
        replies = [
            { status: 500, json: {} },
            { status: 200, json: { data: [overview] } },
        ];
        await mountPage();

        expect($('[data-slot=load-failure]')).not.toBeNull();

        $('[data-test=retry]')?.click();
        await flushPromises();

        expect($$('[data-slot=data-row]')).toHaveLength(1);
    });

    it('duplicates a dashboard and reloads the list', async () => {
        replies = [
            { status: 200, json: { data: [overview, sales] } },
            {
                status: 201,
                json: {
                    data: {
                        ...sales,
                        dashboard_id: 'd-2',
                        name: 'Sales (copy)',
                        omitted: 0,
                    },
                },
            },
            {
                status: 200,
                json: {
                    data: [
                        overview,
                        sales,
                        { ...sales, dashboard_id: 'd-2', name: 'Sales (copy)' },
                    ],
                },
            },
        ];
        await mountPage();

        $$('[data-test=duplicate]')[1].click();
        await flushPromises();

        expect(calls[1]).toMatchObject({
            method: 'POST',
            url: '/api/v1/dashboards/d-1/duplicate',
        });
        expect($$('[data-slot=data-row]')).toHaveLength(3);
    });

    it('creates a dashboard and opens it', async () => {
        replies = [
            { status: 200, json: { data: [overview] } },
            {
                status: 201,
                json: { data: { ...sales, dashboard_id: 'd-9', name: 'Ops' } },
            },
        ];
        await mountPage();

        $('[data-test=create]')?.click();
        await flushPromises();

        const input = $('[data-test=dashboard-name]') as HTMLInputElement;

        input.value = 'Ops';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        await flushPromises();
        $('[data-slot=dashboard-name-dialog] form')?.dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true }),
        );
        await flushPromises();

        expect(calls[1]).toMatchObject({
            method: 'POST',
            body: { name: 'Ops' },
        });
        expect(visit).toHaveBeenCalledWith('/dashboards/d-9');
    });

    it('refuses an empty name before sending anything', async () => {
        replies = [{ status: 200, json: { data: [overview] } }];
        await mountPage();

        $('[data-test=create]')?.click();
        await flushPromises();
        $('[data-slot=dashboard-name-dialog] form')?.dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true }),
        );
        await flushPromises();

        expect(calls).toHaveLength(1);
        expect(document.body.textContent).toContain(
            'Enter a name of 1 to 120 characters.',
        );
    });
});

describe('dashboard switcher', () => {
    it('lists Overview first with a check on the current one and tells the page which was chosen', async () => {
        replies = [{ status: 200, json: { data: [overview, sales] } }];
        wrapper = mount(DashboardSwitcher as never, {
            props: { currentId: 'd-0', currentName: 'Overview' },
            attachTo: document.body,
            global: { plugins: [createCatalogue()] },
        });

        $('[data-slot=dashboard-switcher]')?.click();
        await flushPromises();
        await vi.dynamicImportSettled();
        await flushPromises();

        const options = $$('[data-slot=switcher-list] button');

        expect(options.map((o) => o.getAttribute('data-dashboard'))).toEqual([
            'd-0',
            'd-1',
        ]);
        expect(options[0].getAttribute('aria-current')).toBe('true');
        expect($('[data-slot=new-dashboard]')).not.toBeNull();
        expect($('[data-slot=manage-dashboards]')?.getAttribute('href')).toBe(
            '/dashboards',
        );

        options[1].click();
        await flushPromises();

        expect(wrapper.emitted('switch')?.[0]).toEqual([sales]);
    });
});
