// @vitest-environment happy-dom
//
// Story 1.25: an axe-core check of Sign in, Reset password, Profile & settings and User configuration.
// happy-dom has no layout engine, so axe runs here only the WCAG 2.1 A and AA rules it can evaluate
// without one: accessible names, roles, labels, ARIA validity, landmarks and heading order. Colour
// contrast stays with tests/js/contrast.test.ts, and keyboard order, focus, zoom and 320 px reflow in a
// real browser are the manual checklist in docs/accessibility-checklist.md. Real-browser axe runs are
// deferred: this file does not claim them.
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import axe from 'axe-core';
import { createPinia, setActivePinia } from 'pinia';
import { existsSync, readFileSync, statSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import { createCatalogue } from '../../resources/js/lib/i18n';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

function makeForm(initial: Record<string, unknown>): Record<string, unknown> {
    const fields = Object.keys(initial);
    const form: Record<string, unknown> = reactive({
        ...initial,
        errors: {},
        processing: false,
        isDirty: false,
        transform: () => form,
        defaults: () => form,
        reset: () => undefined,
        clearErrors: () => undefined,
        post: () => undefined,
        put: () => undefined,
        patch: () => undefined,
        setError: () => undefined,
        data: () => Object.fromEntries(fields.map((key) => [key, form[key]])),
    });

    return form;
}

vi.mock('@inertiajs/vue3', () => ({
    Head: { render: () => null },
    Link: defineComponent({
        props: ['href'],
        setup:
            (props, { slots, attrs }) =>
            () =>
                h('a', { ...attrs, href: props.href }, slots.default?.()),
    }),
    useForm: (initial: Record<string, unknown>) => makeForm(initial),
    usePage: () => ({
        url: '/admin/users',
        props: {
            auth: {
                user: {
                    name: 'Ada Lovelace',
                    email: 'ada@example.test',
                    avatar: null,
                    locale: 'en',
                    timezone: 'UTC',
                    keyboard_shortcuts: true,
                },
            },
            shell: { can: { 'users.manage': true }, items: [] },
        },
    }),
}));

const { default: Login } =
    await import('../../resources/js/pages/auth/Login.vue');
const { default: ResetPassword } =
    await import('../../resources/js/pages/auth/ResetPassword.vue');
const { default: Profile } =
    await import('../../resources/js/pages/settings/Profile.vue');
const { default: Users } =
    await import('../../resources/js/pages/admin/Users.vue');

// WCAG 2.1 A and AA by tag, plus the structural rules the epic names (heading order, landmarks) by id.
// No `best-practice` tag.
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];
const STRUCTURE_RULES = [
    'heading-order',
    'landmark-unique',
    'landmark-no-duplicate-main',
    'landmark-no-duplicate-banner',
    'landmark-no-duplicate-contentinfo',
];
// Rules that need a rendering engine (layout, computed colour or real focus), left to the contrast
// tests and the manual checklist.
const NEEDS_LAYOUT = [
    'color-contrast',
    'color-contrast-enhanced',
    'target-size',
    'scrollable-region-focusable',
    'focus-order-semantics',
];

let wrapper: VueWrapper | null = null;

async function audit(): Promise<string[]> {
    const disabled: Record<string, { enabled: boolean }> = {};

    for (const id of NEEDS_LAYOUT) {
        disabled[id] = { enabled: false };
    }

    // Pass 1: the WCAG 2.1 A and AA tags only. Pass 2: the structure rules named above, by id.
    const passes = [
        await axe.run(document.body, {
            runOnly: { type: 'tag', values: TAGS },
            rules: disabled,
        }),
        await axe.run(document.body, {
            runOnly: { type: 'rule', values: STRUCTURE_RULES },
        }),
    ];

    return passes
        .flatMap((results) => results.violations)
        .flatMap((violation) =>
            violation.nodes.map(
                (node) =>
                    `${violation.id} (${violation.impact}): ${node.html.slice(0, 140)} -- ${node.failureSummary?.split('\n')[1]?.trim() ?? ''}`,
            ),
        );
}

// A page is mounted where the shell would put it: inside the one `main` landmark of an HTML document
// that declares its language and title.
function mountPage(
    component: object,
    props: Record<string, unknown> = {},
): void {
    document.documentElement.lang = 'en';
    document.title = 'Dashflow';
    const main = document.createElement('main');
    document.body.appendChild(main);
    wrapper = mount(component, {
        attachTo: main,
        props,
        global: { plugins: [createCatalogue()], directives: { focus: {} } },
    });
}

beforeEach(() => {
    setActivePinia(createPinia());
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => ({
            ok: true,
            status: 200,
            json: async () => ({
                data: [
                    {
                        kind: 'member',
                        membership_id: 'm-1',
                        name: 'Ada Admin',
                        email: 'ada@example.test',
                        role: 'admin',
                        status: 'active',
                        groups: [],
                        last_active_at: '2026-10-01T09:30:00Z',
                    },
                    {
                        kind: 'invitation',
                        invitation_id: 'i-1',
                        name: null,
                        email: 'new@example.test',
                        role: 'user',
                        status: 'invited',
                        groups: [],
                        last_active_at: null,
                    },
                ],
                meta: {
                    per_page: 15,
                    next_cursor: null,
                    total: 2,
                    matched: 2,
                    sort: 'name',
                    direction: 'asc',
                },
            }),
        })),
    );
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
});

describe('axe on the four named pages (rules that need no layout)', () => {
    it('finds no WCAG 2.1 A or AA violation on Sign in', async () => {
        mountPage(Login, { canResetPassword: true });
        await flushPromises();

        expect(await audit()).toEqual([]);
    });

    it('finds no WCAG 2.1 A or AA violation on Reset password, form and expired link', async () => {
        mountPage(ResetPassword, {
            token: 'token',
            email: 'ada@example.test',
            passwordRules: 'minlength: 8;',
        });
        await flushPromises();
        expect(await audit()).toEqual([]);

        wrapper?.unmount();
        document.body.innerHTML = '';
        mountPage(ResetPassword, {
            token: null,
            email: null,
            expired: true,
            passwordRules: 'minlength: 8;',
        });
        await flushPromises();
        expect(await audit()).toEqual([]);
    });

    it('finds no WCAG 2.1 A or AA violation on Profile & settings', async () => {
        mountPage(Profile, {
            locales: ['en'],
            timezones: ['UTC', 'Asia/Dhaka'],
            passwordRules: 'minlength: 8;',
            avatarMaxBytes: 1000,
        });
        await flushPromises();

        expect(await audit()).toEqual([]);
    });

    it('finds no WCAG 2.1 A or AA violation on User configuration', async () => {
        mountPage(Users);
        await flushPromises();

        expect(document.querySelector('table')).not.toBeNull();
        expect(await audit()).toEqual([]);
    });

    it('reports a violation it can evaluate (the check is live)', async () => {
        document.documentElement.lang = 'en';
        document.body.innerHTML =
            '<main><h1>Page</h1><img src="x.png"><button></button></main>';

        const found = await audit();

        expect(found.some((line) => line.startsWith('image-alt'))).toBe(true);
        expect(found.some((line) => line.startsWith('button-name'))).toBe(true);
    });
});

// The 320 px reflow rules that need no layout: no fixed width wider than the viewport outside a
// breakpoint, and target sizes taken from tokens. Real reflow is on the manual checklist.
describe('static 320 px reflow rules', () => {
    const files = [
        'resources/js/pages/auth/Login.vue',
        'resources/js/pages/auth/ResetPassword.vue',
        'resources/js/pages/settings/Profile.vue',
        'resources/js/pages/admin/Users.vue',
        'resources/js/layouts/SignInLayout.vue',
        'resources/js/layouts/AuthLayout.vue',
        'resources/js/layouts/auth/AuthSimpleLayout.vue',
        'resources/js/layouts/AppLayout.vue',
        'resources/js/layouts/app/AppSidebarLayout.vue',
        'resources/css/app.css',
    ];

    const exists = (path: string) =>
        existsSync(resolve(root, path)) &&
        statSync(resolve(root, path)).isFile();

    // `@/x`, `./x` and `../x` imports of a source file, resolved to a file under resources/js: an exact
    // file, `.vue`, `.ts`, or a directory's `index.ts`.
    function importsOf(path: string, source: string): string[] {
        const resolved: string[] = [];

        for (const match of source.matchAll(/(?:from|import\()\s*'([^']+)'/g)) {
            const spec = match[1];
            let base: string;

            if (spec.startsWith('@/')) {
                base = `resources/js/${spec.slice(2)}`;
            } else if (spec.startsWith('.')) {
                base = join(dirname(path), spec);
            } else {
                continue;
            }

            const hit = ['', '.vue', '.ts', '/index.ts']
                .map((suffix) => base + suffix)
                .find(exists);

            if (hit) {
                resolved.push(hit);
            }
        }

        return resolved;
    }

    function reachable(paths: string[]): string[] {
        const seen = new Set<string>();
        const queue = [...paths];

        while (queue.length > 0) {
            const path = queue.pop() as string;

            if (seen.has(path)) {
                continue;
            }

            seen.add(path);

            if (/\.(vue|ts)$/.test(path)) {
                queue.push(
                    ...importsOf(
                        path,
                        readFileSync(resolve(root, path), 'utf8'),
                    ),
                );
            }
        }

        return [...seen];
    }

    const checked = reachable(files);
    const VIEWPORT = 320;
    const BREAKPOINT =
        /(^|:)(sm|md|lg|xl|2xl|@[a-z0-9]+|min-\[[^\]]+\]|max-[a-z0-9]+):/;

    const px = (value: number, unit: string): number =>
        unit === 'vw'
            ? (value * VIEWPORT) / 100
            : unit === 'px' || unit === ''
              ? value
              : value * 16;

    /** Fixed widths found in a source: Tailwind scale and arbitrary values, and CSS or style widths. */
    function wideWidths(source: string): string[] {
        const found: string[] = [];

        // Tailwind classes: w-96, min-w-80, w-[30rem], min-w-[120vw]; skipped behind a breakpoint prefix.
        for (const match of source.matchAll(
            /(?<![\w-])((?:[a-z0-9@[\]-]+:)*)((?:min-)?w-(?:\[(\d+(?:\.\d+)?)(px|rem|em|vw)\]|(\d+(?:\.\d+)?)(?![\w.%/])))(?![\w-])/g,
        )) {
            if (BREAKPOINT.test(match[1])) {
                continue;
            }

            // A fixed width inside the same class list as a max-width that caps it to the viewport still reflows.
            const start = source.lastIndexOf('"', match.index) + 1;
            const end = source.indexOf('"', match.index);
            const classes = source.slice(start, end === -1 ? undefined : end);

            if (
                /(?<![\w-])max-w-(?:\[[^\]]*vw[^\]]*\]|full|screen)(?![\w-])/.test(
                    classes,
                )
            ) {
                continue;
            }

            const width = match[3]
                ? px(Number(match[3]), match[4])
                : Number(match[5]) * 4;

            // `w-80` and up (320 px and wider) leave no room for the page gutter.
            if (width >= (match[3] ? VIEWPORT + 1 : VIEWPORT)) {
                found.push(match[2]);
            }
        }

        // Named min-widths (min-w-xs 320 px and up).
        for (const match of source.matchAll(
            /(?<![\w:-])(min-w-(?:xs|sm|md|lg|xl|[2-7]xl))(?![\w-])/g,
        )) {
            found.push(match[1]);
        }

        // CSS and style bindings: width and min-width in px, rem, em or vw (not media queries, not max-width).
        for (const match of source.matchAll(
            /(?<![(\w-])(?:min-)?width:\s*(\d+(?:\.\d+)?)(px|rem|em|vw)\b/g,
        )) {
            if (px(Number(match[1]), match[2]) > VIEWPORT) {
                found.push(match[0]);
            }
        }

        return found;
    }

    it('follows relative and alias imports, including the ui components the pages use', () => {
        expect(checked.length).toBeGreaterThan(files.length);
        expect(checked.some((path) => path.includes('components/ui/'))).toBe(
            true,
        );
    });

    it('recognises wide fixed widths in Tailwind, rem, em and vw (the scan is live)', () => {
        const wide = wideWidths(
            'class="w-96 min-w-80 w-[400px] w-[30rem] min-w-[25em] w-[120vw] min-w-xs" style="width: 30rem; min-width: 150vw"',
        );

        expect(wide).toHaveLength(9);
        expect(
            wideWidths(
                'class="w-64 w-[300px] lg:w-[438px] md:min-w-96 w-full max-w-[600px] w-[90vw]" style="width: 12rem"',
            ),
        ).toEqual([]);
    });

    it('has no fixed width or min-width at 320 px or wider outside a breakpoint', () => {
        const offences = checked.flatMap((path) =>
            wideWidths(readFileSync(resolve(root, path), 'utf8')).map(
                (hit) => `${path}: ${hit}`,
            ),
        );

        expect(offences).toEqual([]);
    });

    it('defines the target sizes as tokens and uses them for the hit area', () => {
        const tokens = readFileSync(
            resolve(root, 'resources/css/tokens.css'),
            'utf8',
        );
        const app = readFileSync(
            resolve(root, 'resources/css/app.css'),
            'utf8',
        );

        expect(tokens).toMatch(/--df-target-min:\s*24px/);
        expect(tokens).toMatch(/--df-target-touch:\s*44px/);
        expect(app).toContain('var(--df-target-min)');
        expect(app).toContain('var(--df-target-touch)');
    });
});
