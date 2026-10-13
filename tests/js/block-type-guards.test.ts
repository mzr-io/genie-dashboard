import { spawnSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';
// @ts-expect-error plain ESM script without type declarations
import { missingPins } from '../../scripts/check-block-type-pins.mjs';
// @ts-expect-error plain ESM script without type declarations
import {
    findViolations,
    isolationViolations,
    lintTree,
    missingStates,
} from '../../scripts/lint-renderers.mjs';
import { kpiCardV1 } from '../../resources/js/types/block-types';

// Story 3.1: the Renderer lint, the pinned-directory guard and the generated type.
const dirs: string[] = [];
const tmp = () => {
    const dir = mkdtempSync(join(tmpdir(), 'block-types-'));
    dirs.push(dir);

    return dir;
};
const put = (root: string, file: string, content: string) => {
    const path = join(root, file);
    mkdirSync(join(path, '..'), { recursive: true });
    writeFileSync(path, content);
};

afterEach(() => {
    for (const dir of dirs.splice(0)) {
        rmSync(dir, { recursive: true, force: true });
    }
});

describe('renderer lint', () => {
    it('fails v-html, innerHTML and formatter strings, naming the line', () => {
        const found = findViolations(
            [
                '<template>',
                '<p v-html="x"></p>',
                '<p :ref="(el) => (el.innerHTML = x)"></p>',
                `<Num formatter="#,##0.00" />`,
                '</template>',
            ].join('\n'),
        );

        expect(found).toEqual([
            { line: 2, message: 'v-html is banned' },
            { line: 3, message: 'innerHTML is banned' },
            { line: 4, message: 'formatter string is banned' },
        ]);
    });

    it('fails eval, new Function, Function( and outerHTML', () => {
        const found = findViolations(
            [
                'eval(x)',
                'new Function("a")',
                'Function("a")()',
                'el.outerHTML = x',
            ].join('\n'),
        );

        expect(found.map((f: { line: number }) => f.line)).toEqual([
            1, 2, 3, 4,
        ]);
    });

    it('scans every script in a package and fails when block-types is missing', () => {
        const root = tmp();

        expect(lintTree(root)[0]).toContain('is missing');

        put(root, 'block-types/x/v1/helper.ts', 'el.innerHTML = x;');

        expect(lintTree(root)).toEqual([
            'block-types/x/v1/helper.ts:1 innerHTML is banned',
        ]);
    });

    it('passes interpolation only', () => {
        expect(findViolations('<p>{{ value }}</p>')).toEqual([]);
    });

    it('passes the real kpi-card Renderer and fails an unsafe one in a tree', () => {
        expect(lintTree(resolve('.'))).toEqual([]);

        const root = tmp();
        put(root, 'block-types/x/v1/Renderer.vue', '<p v-html="a"></p>');

        const failures = lintTree(root);

        expect(failures[0]).toBe(
            'block-types/x/v1/Renderer.vue:1 v-html is banned',
        );
    });

    it('exits non-zero from the CLI', () => {
        const root = tmp();
        put(root, 'block-types/x/v1/Renderer.vue', '<p v-html="a"></p>');

        const run = spawnSync('node', ['scripts/lint-renderers.mjs', root], {
            encoding: 'utf8',
        });

        expect(run.status).toBe(1);
        expect(run.stderr).toContain('Renderer.vue:1');
    });
});

describe('pinned directory guard', () => {
    it('passes when every pinned directory exists', () => {
        expect(missingPins(resolve('.'))).toEqual([]);
    });

    it('names a pinned directory that was removed or renamed', () => {
        const root = tmp();
        put(
            root,
            'block-types/pins.json',
            JSON.stringify({ pins: [{ key: 'kpi-card', contractVersion: 1 }] }),
        );
        put(root, 'block-types/kpi-card/v2/schema.json', '{}');

        const failures = missingPins(root);

        expect(failures).toHaveLength(1);
        expect(failures[0]).toContain('block-types/kpi-card/v1/');

        const run = spawnSync(
            'node',
            ['scripts/check-block-type-pins.mjs', root],
            {
                encoding: 'utf8',
            },
        );

        expect(run.status).toBe(1);
    });
});

describe('pins.json problems', () => {
    it('reports a missing, malformed or incomplete pins.json readably', () => {
        const missing = tmp();

        expect(missingPins(missing)[0]).toContain('is missing');

        const badJson = tmp();
        put(badJson, 'block-types/pins.json', '{nope');

        expect(missingPins(badJson)[0]).toContain('not valid JSON');

        const notArray = tmp();
        put(notArray, 'block-types/pins.json', '{"pins": {}}');

        expect(missingPins(notArray)[0]).toContain('"pins" array');

        const incomplete = tmp();
        put(incomplete, 'block-types/pins.json', '{"pins": [{"key": "a"}]}');

        expect(missingPins(incomplete)[0]).toContain('pin #0');
    });
});

describe('generated type', () => {
    it('describes kpi-card v1 with its required slots and default size', () => {
        expect(kpiCardV1.contract_version).toBe(1);
        expect(kpiCardV1.size.default_width).toBe(3);
        expect(kpiCardV1.size.default_height).toBe('small');
        expect(
            kpiCardV1.slots.filter((s) => s.required).map((s) => s.key),
        ).toEqual(['kpi_label', 'headline_value']);
    });
});

// Story 5.17: a package is the shared kit and itself; its Renderer has every state.
describe('package isolation and states', () => {
    it('names a Renderer that lacks a state and accepts one on the shared frame', () => {
        expect(
            missingStates('<p v-if="state === \'loading\'"></p> empty stale'),
        ).toEqual(['unavailable', 'error']);
        expect(missingStates('<ChartBlockFrame :state="s" />')).toEqual([]);
    });

    it('fails an import of the core and a request, and allows the shared kit', () => {
        const found = isolationViolations(
            [
                "import { fmt } from '@/lib/format';",
                "import ChartBlockFrame from '@/charts/ChartBlockFrame.vue';",
                "import { save } from '@/lib/blockWizard';",
                "import Card from '@/components/BlockCard.vue';",
                "const r = await fetch('/api/x');",
            ].join('\n'),
        );

        expect(found.map((f: { line: number }) => f.line)).toEqual([3, 4, 5]);
    });

    it('fails a tree whose Renderer lacks a state or reaches the core', () => {
        const root = tmp();

        put(root, 'block-types/pins.json', '{"pins":[]}');
        put(
            root,
            'block-types/x/v1/Renderer.vue',
            "<script setup>import { go } from '@/lib/blockWizard';</script><template><p>loading</p></template>",
        );

        const failures = lintTree(root) as string[];

        expect(failures.join('\n')).toContain('is not allowed');
        expect(failures.join('\n')).toContain('has no empty state');
    });
});
