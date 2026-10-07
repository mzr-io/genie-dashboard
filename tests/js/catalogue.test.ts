import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { I18nT } from 'vue-i18n';
import { describe, expect, it } from 'vitest';
import TechnicalDetails from '../../resources/js/components/TechnicalDetails.vue';
import { copyText } from '../../resources/js/lib/clipboard';
import { createCatalogue } from '../../resources/js/lib/i18n';
import en from '../../resources/js/locales/en';

type Params = Record<string, string | number>;
type Case = {
    path: string; // '' for a plain message, else the sub-message name
    expected: string; // the Do text from EXPERIENCE.md with example values filled in
    params: Params;
    count?: number; // plural selector
};
type Row = { key: string; dont: string[]; cases: Case[] };

const c = (
    path: string,
    expected: string,
    params: Params = {},
    count?: number,
): Case => ({ path, expected, params, count });
const row = (key: string, dont: string[], ...cases: Case[]): Row => ({
    key,
    dont,
    cases,
});
const shortcut = { shortcut: 'Ctrl+Z' };

// The 89 rows of EXPERIENCE.md > Voice and Tone > Canonical messages: key, Do text (with the
// document's example values as parameters) and the quoted Don't strings. Announced forms the
// document describes in prose are fixed here as the catalogue's own wording.
const ROWS: Row[] = [
    row(
        'field-error',
        ['Invalid input.'],
        c('', 'Enter an email address, like name@company.com.'),
    ),
    row(
        'signin-role-denied',
        ['403 Forbidden'],
        c(
            '',
            "You don't have admin access in this workspace. Sign in as User instead?",
        ),
    ),
    row(
        'signin-failed',
        ['Login failed.'],
        c(
            '',
            "That email and password don't match. Try again, or reset your password.",
        ),
    ),
    row(
        'throttled',
        ['Account locked due to suspicious activity.'],
        c('', 'Too many attempts. Wait a minute, then try again.'),
    ),
    row(
        'fetch-failed',
        ['Error 502 Bad Gateway at /api/v2/…'],
        c(
            '',
            "We couldn't reach Finance data warehouse. Check the endpoint or try again. ▸ Technical details",
            { source: 'Finance data warehouse' },
        ),
    ),
    row(
        'json-invalid',
        ['Parse error.'],
        c(
            '',
            "This isn't valid JSON. Line 4 has an extra comma. Go to line 4",
            { line: 4, problem: 'an extra comma' },
        ),
    ),
    row(
        'json-valid',
        ['OK'],
        c(
            '',
            '✓ Valid JSON · 3 records found at data.monthly[] · Pasted 09:14 · 412 bytes · 4 scalars, 1 array',
            {
                count: 3,
                path: 'data.monthly[]',
                time: '09:14',
                bytes: 412,
                shape: '4 scalars, 1 array',
            },
        ),
    ),
    row(
        'test-ok',
        ['OK'],
        c('', '✓ Connected · 200 · 184 ms', { status: 200, ms: 184 }),
    ),
    row(
        'notify-mapping-failed',
        ['Mapping error 422'],
        c('', "Revenue overview can't read total. Fix mapping →", {
            block: 'Revenue overview',
            field: 'total',
        }),
    ),
    row(
        'notify-version',
        ['Block changed.'],
        c('', 'Revenue overview was updated to v1.1', {
            block: 'Revenue overview',
            version: '1.1',
        }),
    ),
    row(
        'fetch-ok',
        ['Success!'],
        c('', '✓ 200 · 312 ms · 3 records found at data.monthly[]', {
            status: 200,
            ms: 312,
            count: 3,
            path: 'data.monthly[]',
        }),
    ),
    row(
        'required-slot-missing',
        ['Validation failed.'],
        c(
            'one',
            'Map Chart series to continue. Optional slots can stay empty.',
            { slot: 'Chart series' },
        ),
        c(
            'many',
            'Map 2 required slots to continue: Chart series, Chart X. Optional slots can stay empty.',
            { count: 2, slots: 'Chart series, Chart X' },
        ),
    ),
    row(
        'post-readonly',
        [],
        c('label', 'This POST is a read-only query'),
        c(
            'hint',
            'Dashflow never sends requests that change data. Mark this only if the endpoint just reads.',
        ),
    ),
    row(
        'url-invalid',
        ['Invalid URL.'],
        c('', 'Use a link that starts with https:// or http://.'),
    ),
    row(
        'group-required',
        ['Required.'],
        c('', 'Choose at least one group, or make it available to all users.'),
    ),
    row(
        'access-impact',
        ['Are you sure?'],
        c('message', '38 users will lose this block.', { count: '38 users' }),
        c('count', '1 user', {}, 1),
        c('count', '38 users', {}, 38),
    ),
    row(
        'api-changed',
        ['Schema error.'],
        c(
            '',
            'API response changed: field total no longer present → Headline: Unavailable',
            { field: 'total', slot: 'Headline' },
        ),
    ),
    row(
        'api-match-found',
        [],
        c(
            '',
            'Dashflow found a new number field total_revenue that looks like the same data (matching value 284680). [Use total_revenue]',
            { field: 'total_revenue', value: 284680 },
        ),
    ),
    row(
        'live-shape-unreachable',
        ['Validation error.'],
        c(
            '',
            "We couldn't reach the API to check this block. Save a draft and try again.",
        ),
    ),
    row(
        'drawer-empty-search',
        ['No results.'],
        c('', "No blocks match 'payroll'. Ask your admin to publish one.", {
            query: 'payroll',
        }),
    ),
    row(
        'drawer-none',
        ['Empty.'],
        c('', "No blocks are available yet. Your admin hasn't published any."),
    ),
    row(
        'drawer-load-failed',
        [],
        c('', "We couldn't load the blocks. Try again."),
    ),
    row(
        'dashboard-empty',
        ['Nothing here!'],
        c('', 'This dashboard is empty. Add blocks to start.'),
    ),
    row(
        'dashboard-load-failed',
        [],
        c(
            '',
            "We couldn't load this dashboard. Your layout is safe. Try again.",
        ),
    ),
    row('block-empty', ['0'], c('', 'No data for this period.')),
    row('block-error', [], c('', "We couldn't load this block. Try again")),
    row(
        'sample-wizard',
        ['Test', 'Demo', 'Fake data'],
        c('', 'Sample data · used for mapping and preview only'),
    ),
    row(
        'sample-drawer',
        ['Test', 'Demo', 'Fake data'],
        c(
            '',
            "Sample data. Your dashboard will show your organisation's figures.",
        ),
    ),
    row(
        'sample-note',
        [],
        c(
            '',
            "Sample data only. Published blocks call GET /api/v2/finance/revenue live. Before you publish, Dashflow checks that the live response matches this sample's shape.",
            { endpoint: 'GET /api/v2/finance/revenue' },
        ),
    ),
    row(
        'toast-add',
        ['Block successfully added to your dashboard!'],
        c('visible', 'Revenue vs Expense added · Show me · Undo', {
            block: 'Revenue vs Expense',
        }),
        c(
            'announce',
            'Revenue vs Expense added next to Recent activity. Undo with Ctrl+Z.',
            {
                block: 'Revenue vs Expense',
                neighbour: 'Recent activity',
                ...shortcut,
            },
        ),
        c(
            'announce',
            'Revenue vs Expense added next to Recent activity. Undo with ⌘Z.',
            {
                block: 'Revenue vs Expense',
                neighbour: 'Recent activity',
                shortcut: '⌘Z',
            },
        ),
    ),
    row(
        'toast-add-below',
        ['Added.'],
        c('visible', 'Revenue vs Expense added below · Show me ↓ · Undo', {
            block: 'Revenue vs Expense',
        }),
        c(
            'announce',
            "Revenue vs Expense added next to Recent activity. Undo with Ctrl+Z. It's below the visible area.",
            {
                block: 'Revenue vs Expense',
                neighbour: 'Recent activity',
                ...shortcut,
            },
        ),
    ),
    row(
        'toast-remove',
        ['Deleted.'],
        c('visible', 'Recent activity removed · Undo', {
            block: 'Recent activity',
        }),
        c('announce', 'Recent activity removed. Undo with Ctrl+Z.', {
            block: 'Recent activity',
            ...shortcut,
        }),
    ),
    row(
        'toast-rollback',
        ['Error.'],
        c(
            '',
            "We couldn't add Revenue vs Expense. Your dashboard is unchanged. Try again.",
            { block: 'Revenue vs Expense' },
        ),
    ),
    row(
        'edge-pill',
        [],
        c('', '↓ 1 new block below', { count: 1 }, 1),
        c('', '↓ 3 new blocks below', { count: 3 }, 3),
    ),
    row('free-space-hint', [], c('', 'The next block you add goes here.')),
    row('drawer-footer', [], c('', 'New blocks go to the first free space.')),
    row(
        'unavailable-user',
        ['$0', 'NaN', '—'],
        c('', "Unavailable — this value can't be shown right now."),
    ),
    row(
        'unavailable-admin',
        ['Error'],
        c('', 'Unavailable: total missing', { field: 'total' }),
    ),
    row(
        'stale',
        ['Data may be inaccurate.'],
        c('today', 'Stale: last data 10:42', { time: '10:42' }),
        c('earlier', 'Stale: last data yesterday 22:10', {
            day: 'yesterday',
            time: '22:10',
        }),
        c('earlier', 'Stale: last data 3 Oct 22:10', {
            day: '3 Oct',
            time: '22:10',
        }),
        c('announce', 'Block data is stale. Last data yesterday 22:10.', {
            when: 'yesterday 22:10',
        }),
    ),
    row(
        'stale-aggregate',
        [],
        c(
            'announce',
            '3 blocks are stale. Last data 10:42.',
            { count: 3, time: '10:42' },
            3,
        ),
        c(
            'announce',
            '1 block is stale. Last data 10:42.',
            { count: 1, time: '10:42' },
            1,
        ),
        c('recovered', 'Blocks are up to date.'),
    ),
    row(
        'paused',
        ['Stopped'],
        c('', 'Paused · data as of 10:42', { time: '10:42' }),
    ),
    row(
        'reconnecting',
        ['Network error.'],
        c(
            '',
            "Reconnecting… Your dashboard will refresh when you're back online.",
        ),
    ),
    row('back-online', [], c('', 'Back online. Refreshing your dashboard.')),
    row('refresh-limited', [], c('', 'Refreshed a moment ago')),
    row(
        'block-unpublished',
        ['Block not found.'],
        c('', 'This block is no longer available. You can remove it.'),
    ),
    row(
        'block-access-removed',
        ['403'],
        c('', 'This block is no longer available to you.'),
    ),
    row('locked', ["You can't do that."], c('', 'Required by your admin')),
    row(
        'perm-publish',
        [],
        c('', "You don't have permission to publish this block."),
    ),
    row(
        'perm-denied',
        ['403 Forbidden'],
        c('', "You don't have permission to view this. Ask a workspace admin."),
    ),
    row(
        'publish-impact',
        ['This will affect users. Continue?'],
        c(
            '',
            "214 users have this block and 2 templates include it. Their layouts won't move.",
            { users: 214, templates: 2 },
        ),
    ),
    row(
        'publish-success',
        ['Published!'],
        c(
            '',
            'Revenue overview v1.0 is live in the Block Library under Finance.',
            { block: 'Revenue overview', version: '1.0', category: 'Finance' },
        ),
    ),
    row(
        'version-safe',
        [],
        c(
            '',
            'Publishing creates version 1.0. Future edits create a new version, so existing user layouts remain stable.',
            { version: '1.0' },
        ),
    ),
    row(
        'host-not-allowlisted',
        ['Request blocked (SSRF).'],
        c(
            '',
            "This host isn't on your workspace allowlist. Add it in System settings, or ask a platform operator for a private-network address. ▸ Technical details",
        ),
    ),
    row(
        'blocked-address',
        ['Forbidden host.'],
        c(
            '',
            "Dashflow can't call loopback, link-local or cloud-metadata addresses. Use the API's public or allowlisted host.",
        ),
    ),
    row(
        'response-too-large',
        ['Truncated.'],
        c(
            '',
            "Response too large: 14.2 MB is over this data source's 10 MB limit. Narrow the request with parameters, or raise the limit on the data source. Nothing was shown, so no totals are wrong.",
            { size: '14.2 MB', limit: '10 MB' },
        ),
    ),
    row(
        'not-json',
        ['Unexpected token <.'],
        c(
            '',
            'This endpoint returned HTML, not JSON. Dashflow supports REST APIs that return JSON.',
        ),
    ),
    row(
        'live-not-supported',
        [],
        c(
            '',
            "Live isn't available: Finance data warehouse isn't marked as supporting Live refresh.",
            { source: 'Finance data warehouse' },
        ),
    ),
    row(
        'datasource-none',
        [],
        c('message', 'No data sources are registered yet.'),
        c('register', '+ Register data source'),
        c('ask', 'Ask an admin who manages data sources to register one.'),
    ),
    row(
        'reset-requested',
        ['No account found for that email.'],
        c(
            '',
            "Check your email. If an account exists for that address, we've sent a link to reset your password.",
        ),
    ),
    row(
        'reset-expired',
        ['Invalid token.'],
        c(
            '',
            'This reset link has expired or was already used. Request a new one.',
        ),
    ),
    row(
        'password-changed',
        ['Done.'],
        c('', 'Your password was changed. Sign in with your new password.'),
    ),
    row(
        'restore-done',
        ['Rolled back.'],
        c('', 'Draft v1.3 created from v1.1. Publish it to make it live.', {
            draft: '1.3',
            source: '1.1',
        }),
    ),
    row(
        'restore-replace',
        [],
        c('', 'Replace your current draft with v1.1?', { version: '1.1' }),
    ),
    row(
        'unpublish-impact',
        ['Are you sure?'],
        c(
            '',
            "214 users have this block. They'll see 'This block is no longer available.' It leaves the Add-blocks Panel and search.",
            { users: 214 },
        ),
    ),
    row(
        'reset-template',
        ['Reset dashboard?'],
        c(
            '',
            "Reset Finance weekly – Jamie to the template's current layout? Your blocks, positions and sizes on this dashboard will be replaced. This can't be undone.",
            { dashboard: 'Finance weekly – Jamie' },
        ),
    ),
    row(
        'overview-delete',
        [],
        c('', "Overview is your default dashboard and can't be deleted.", {
            dashboard: 'Overview',
        }),
    ),
    row(
        'mandatory-added',
        ['Layout changed.'],
        c(
            '',
            "Your admin added Employee attendance to Finance weekly – Jamie. It's at the end of your dashboard.",
            {
                block: 'Employee attendance',
                dashboard: 'Finance weekly – Jamie',
            },
        ),
    ),
    row(
        'template-block-omitted',
        [],
        c(
            '',
            "1 block in this template isn't available to you.",
            { count: 1 },
            1,
        ),
        c(
            '',
            "2 blocks in this template aren't available to you.",
            { count: 2 },
            2,
        ),
    ),
    row(
        'templates-none',
        ['Empty.'],
        c('', 'No templates are available to you yet.'),
    ),
    row(
        'search-empty',
        ['No results.'],
        c('', 'No matches in Genie Inc. Try a block or dashboard name.', {
            workspace: 'Genie Inc',
        }),
    ),
    row(
        'notifications-empty',
        ['Nothing here!'],
        c(
            '',
            'No notifications yet. Updates to your blocks and dashboards appear here.',
        ),
    ),
    row('expanded-empty', [], c('', 'No rows for this period.')),
    row('expanded-error', [], c('', "We couldn't load the rows. Try again.")),
    row(
        'workspace-role',
        [],
        c('', "You're a User in Acme Ltd.", { workspace: 'Acme Ltd' }),
    ),
    row(
        'layout-save-failed',
        ['Save failed.'],
        c('', "We couldn't save your layout. Try again."),
    ),
    row(
        'save-failed',
        ['Save failed.'],
        c(
            'draft',
            "We couldn't save your draft. Your changes are still here. Try again.",
        ),
        c(
            'form',
            "We couldn't save your changes. They're still here. Try again.",
        ),
    ),
    row(
        'offline-editing',
        ['Network error.'],
        c(
            '',
            "You're offline. Your changes are still here. Save draft and Publish come back when you reconnect.",
        ),
    ),
    row(
        'session-warning',
        [],
        c('visible', "You'll be signed out in 2:00 for security.", {
            time: '2:00',
        }),
        c(
            'announce',
            "You'll be signed out in 2:00. Stay signed in to keep working.",
            { time: '2:00' },
        ),
    ),
    row(
        'session-expired',
        ['Session expired.'],
        c(
            '',
            "You were signed out to protect your workspace. Your draft was saved, and we've restored it.",
        ),
    ),
    row(
        'draft-locked',
        [],
        c(
            '',
            'Maya Patel is editing this draft (since 10:42). You can view it, or take over editing.',
            { user: 'Maya Patel', time: '10:42' },
        ),
    ),
    row(
        'draft-taken-over',
        ['You were kicked out.'],
        c(
            '',
            'Alex Morgan took over editing at 10:58. Your changes were saved.',
            { user: 'Alex Morgan', time: '10:58' },
        ),
    ),
    row(
        'map-small-screen',
        ['Use a desktop.'],
        c(
            '',
            'Map Data works best on a larger screen. Everything still works here.',
        ),
    ),
    row(
        'list-empty',
        ['No data.'],
        c('', 'No draft blocks yet. Create a block to start.', {
            items: 'draft blocks',
            action: 'Create a block',
        }),
    ),
    row(
        'list-no-match',
        ['No results.'],
        c('', "No draft blocks match 'payroll'.", {
            items: 'draft blocks',
            query: 'payroll',
        }),
    ),
    row('saved', ['Success!'], c('', 'Changes saved.')),
    row('unsaved-changes', [], c('', 'You have unsaved changes.')),
    row(
        'wizard-subtitles',
        ['Section 1'],
        c('identity', 'Give your block a clear identity.'),
        c('source', 'Connect the block to a trusted data source.'),
        c('display', 'Set the default dimensions and controls.'),
    ),
    row(
        'page-subtitles',
        [],
        c('overview', "Here's what's happening across your workspace today."),
        c(
            'admin',
            'Monitor dashboard adoption, publishing activity and platform health.',
        ),
    ),
    row(
        'signin-subtitle',
        ['Log in.'],
        c('', 'Choose your workspace role and enter your credentials.'),
    ),
];

const i18n = createCatalogue();
const t = i18n.global.t as (key: string, ...args: unknown[]) => string;

function render(key: string, path: string, params: Params, count?: number) {
    const full = path ? `${key}.${path}` : key;

    return count === undefined
        ? t(full, params)
        : t(full, params, { plural: count });
}

function leaves(value: unknown, prefix: string): string[] {
    if (typeof value === 'string') {
        return [prefix];
    }

    return Object.entries(value as Record<string, unknown>).flatMap(
        ([name, child]) => leaves(child, `${prefix}.${name}`),
    );
}

// Plain-text view of a catalogue string, parameters removed, for the copy rules.
function strings(): { id: string; text: string }[] {
    return Object.entries(en).flatMap(([key, value]) =>
        leaves(value, key).map((id) => {
            const parts = id
                .slice(key.length + 1)
                .split('.')
                .filter(Boolean);
            const text = parts.reduce<unknown>(
                (node, part) => (node as Record<string, unknown>)[part],
                value,
            ) as string;

            return { id, text };
        }),
    );
}

describe('message catalogue', () => {
    it('has exactly the 89 canonical keys, naming each missing or extra one', () => {
        const expected = ROWS.map((r) => r.key);
        const actual = Object.keys(en);

        expect(expected).toHaveLength(89);
        expect(new Set(expected).size).toBe(89);
        expect(
            expected.filter((key) => !actual.includes(key)),
            'missing keys',
        ).toEqual([]);
        expect(
            actual.filter((key) => !expected.includes(key)),
            'extra keys',
        ).toEqual([]);
    });

    it('serves every Do text verbatim, with parameters in place of example values', () => {
        for (const { key, cases } of ROWS) {
            for (const { path, expected, params, count } of cases) {
                expect(
                    render(key, path, params, count),
                    `${key}${path ? '.' + path : ''}`,
                ).toBe(expected);
            }
        }
    });

    it('covers every catalogue string with a verbatim case', () => {
        const covered = new Set(
            ROWS.flatMap((r) =>
                r.cases.map((x) => (x.path ? `${r.key}.${x.path}` : r.key)),
            ),
        );

        expect(
            strings()
                .map((s) => s.id)
                .filter((id) => !covered.has(id)),
        ).toEqual([]);
    });

    it('pairs each announced variant with a screen-reader sub-key', () => {
        const announced = [
            'toast-add',
            'toast-add-below',
            'toast-remove',
            'session-warning',
            'stale',
            'stale-aggregate',
        ];

        for (const key of announced) {
            const entry = (en as Record<string, unknown>)[key] as Record<
                string,
                unknown
            >;

            expect(typeof entry.announce, `${key}.announce`).toBe('string');
        }
    });

    it.each([
        [38, '<span><strong>38 users</strong> will lose this block.</span>'],
        [1, '<span><strong>1 user</strong> will lose this block.</span>'],
        [0, '<span><strong>0 users</strong> will lose this block.</span>'],
    ])(
        'renders access-impact with count %i in <strong>, never through v-html',
        async (count, expected) => {
            const app = createSSRApp({
                render: () =>
                    h(
                        I18nT,
                        {
                            keypath: 'access-impact.message',
                            tag: 'span',
                            scope: 'global',
                        },
                        {
                            count: () =>
                                h('strong', t('access-impact.count', count)),
                        },
                    ),
            });
            app.use(i18n);

            expect(
                (await renderToString(app)).replace(/<!--[[\]]-->/g, ''),
            ).toBe(expected);
        },
    );

    it('installs English as both locale and fallback', () => {
        const { global } = createCatalogue();

        expect(global.locale.value).toBe('en');
        expect(global.fallbackLocale.value).toBe('en');
    });

    it('never uses v-html in product code', () => {
        const hits: string[] = [];

        for (const path of sourceFiles('resources/js')) {
            const rel = relative('.', path).split(sep).join('/');

            if (
                SKIP_DIRS.some((dir) => rel.startsWith(dir + '/')) ||
                !readFileSync(path, 'utf8').includes('v-html')
            ) {
                continue;
            }

            hits.push(rel);
        }

        expect(hits).toEqual([]);
    });

    it('keeps the example values out of the catalogue', () => {
        const examples =
            /\b(Revenue|Expense|Finance|payroll|Jamie|Maya|Alex|Acme|Genie|Employee|Recent activity)\b|\d/;

        for (const { id, text } of strings()) {
            expect(text, id).not.toMatch(examples);
        }
    });

    it('follows the copy rules: sentence case, no exclamation marks, emoji or domain terms', () => {
        // Product and glossary names that stay capitalised inside a sentence.
        const NAMES = new Set([
            'Dashflow',
            'Block',
            'Library',
            'Map',
            'Data',
            'Add',
            'Panel',
            'Live',
            'User',
            'Unavailable',
            'System',
            'Publish',
            'Stay',
        ]);
        const DOMAIN =
            /\b(rmg|finance|financial|revenue|expense|payroll|invoice|invoices|approval|approvals|task|tasks|attendance|employee|salary|ledger)\b/i;
        const ALLOWED_SYMBOLS = /[✓▸→↓⌘·…—←]/gu;

        for (const { id, text } of strings()) {
            const plain = text
                .replace(/\{'.'\}/g, '')
                .replace(/\{\w+\}/g, 'X')
                .replace(ALLOWED_SYMBOLS, ' | ');

            expect(plain, `${id}: exclamation mark`).not.toContain('!');
            expect(plain, `${id}: emoji`).not.toMatch(
                /\p{Extended_Pictographic}/u,
            );
            expect(plain, `${id}: domain term`).not.toMatch(DOMAIN);

            // A sentence or segment may only start in capitals; no other word may be Title Case.
            const words = plain.split(/(\s+)/);
            let atStart = true;

            for (const word of words) {
                if (/^\s*$/.test(word)) {
                    continue;
                }

                const bare = word.replace(/^[^A-Za-z]+/, '');

                if (
                    !atStart &&
                    !/^['"]/.test(word) &&
                    /^[A-Z][a-z]+/.test(bare) &&
                    !NAMES.has(bare.replace(/[^A-Za-z].*$/, ''))
                ) {
                    expect.fail(`${id}: "${word}" is not sentence case`);
                }

                atStart =
                    word === '|' ||
                    /[.?:]['")]?$/.test(word) ||
                    /^[[(]?\s*$/.test(bare);
            }

            expect(plain.trim()[0], `${id}: starts lowercase`).not.toMatch(
                /[a-z]/,
            );
        }
    });
});

// Duplicate-copy scan (UX-DR-282): a Do or Don't string must not be hard-coded outside the
// catalogue. A string counts as hard-coded when it stands alone as a quoted literal or tag text,
// or, for strings of three words or more, when it appears anywhere. Exact-literal matching keeps
// generic words such as "Test", "OK" or "0" from flagging prose, so no exemption list is needed.
const SKIP_DIRS = [
    'resources/js/locales',
    'resources/js/components/ui',
    'resources/js/actions',
    'resources/js/routes',
    'resources/js/wayfinder',
];

function* sourceFiles(dir: string): Generator<string> {
    for (const name of readdirSync(dir)) {
        const path = join(dir, name);

        if (statSync(path).isDirectory()) {
            yield* sourceFiles(path);
        } else if (/\.(vue|ts|tsx|js)$/.test(name)) {
            yield path;
        }
    }
}

export function findDuplicateCopy(
    text: string,
    candidates: string[],
): string[] {
    return candidates.filter((candidate) => {
        // Candidates with no word in them ("0", "403", "$0", "—") cannot be told apart from
        // ordinary code, so they are the only exemption.
        if (!/[A-Za-z]{2,}/.test(candidate)) {
            return false;
        }

        const words = candidate.trim().split(/\s+/).length;
        const escaped = candidate.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const literal = new RegExp(
            `(['"\`])${escaped}\\1|>\\s*${escaped}\\s*<`,
        );

        return literal.test(text) || (words >= 3 && text.includes(candidate));
    });
}

describe('duplicate copy scan', () => {
    const candidates = [
        ...new Set(
            ROWS.flatMap((r) => [...r.dont, ...r.cases.map((x) => x.expected)]),
        ),
    ];

    it("flags a hard-coded Do or Don't string", () => {
        expect(
            findDuplicateCopy(
                `<p>Invalid input.</p> const a = 'Saved.' x = "Changes saved."`,
                candidates,
            ),
        ).toEqual(['Invalid input.', 'Changes saved.']);
        expect(
            findDuplicateCopy('Test connection, OK button', candidates),
        ).toEqual([]);
    });

    it("finds no Do or Don't string in the components, pages or layouts", () => {
        const hits: string[] = [];

        for (const path of sourceFiles('resources/js')) {
            const rel = relative('.', path).split(sep).join('/');

            if (SKIP_DIRS.some((dir) => rel.startsWith(dir + '/'))) {
                continue;
            }

            for (const string of findDuplicateCopy(
                readFileSync(path, 'utf8'),
                candidates,
            )) {
                hits.push(`${rel}: "${string}"`);
            }
        }

        expect(hits).toEqual([]);
    });
});

describe('TechnicalDetails', () => {
    const props = {
        status: 502,
        path: 'data.monthly[]',
        requestId: 'req-8f3a2c',
    };

    async function html(
        area: 'admin' | 'user',
        extra: Record<string, unknown> = {},
    ) {
        return renderToString(
            createSSRApp({
                render: () => h(TechnicalDetails, { area, ...props, ...extra }),
            }),
        );
    }

    it('shows a collapsed disclosure with the three values and Copy request ID for Admins', async () => {
        const out = await html('admin');

        expect(out).toContain('aria-expanded="false"');
        expect(out).toContain('Technical details');
        expect(out).toMatch(/<div[^>]*hidden/);
        expect(out).toContain('HTTP status');
        expect(out).toContain('502');
        expect(out).toContain('Field path');
        expect(out).toContain('data.monthly[]');
        expect(out).toContain('Request ID');
        expect(out).toContain('req-8f3a2c');
        expect(out).toContain('Copy request ID');
    });

    it('renders nothing in the User area, leaking no status, path or request ID', async () => {
        const out = await html('user');

        expect(out.replace(/<!--.*?-->/g, '')).toBe('');
        for (const secret of ['502', 'data.monthly[]', 'req-8f3a2c']) {
            expect(out).not.toContain(secret);
        }
    });

    it('omits a null status instead of printing "null"', async () => {
        const out = await html('admin', { status: null });

        expect(out).not.toContain('null');
        expect(out).not.toContain('HTTP status');
        expect(out).toContain('req-8f3a2c');
    });

    it('renders nothing for an Admin when status, path and request ID are all missing', async () => {
        const out = await html('admin', {
            status: null,
            path: null,
            requestId: '',
        });

        expect(out.replace(/<!--.*?-->/g, '')).toBe('');
    });

    it('reports a refused copy without throwing, leaving the request ID to select', async () => {
        const original = Object.getOwnPropertyDescriptor(
            globalThis,
            'navigator',
        );

        Object.defineProperty(globalThis, 'navigator', {
            configurable: true,
            value: {
                clipboard: { writeText: () => Promise.reject(new Error('no')) },
            },
        });

        try {
            expect(await copyText('req-8f3a2c')).toBe(false);
        } finally {
            if (original) {
                Object.defineProperty(globalThis, 'navigator', original);
            } else {
                Reflect.deleteProperty(globalThis, 'navigator');
            }
        }

        expect(await html('admin')).toContain('req-8f3a2c');
    });
});
