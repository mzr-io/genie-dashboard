import { spawnSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';
// @ts-expect-error plain ESM script without type declarations
import { findViolations, lintTree } from '../../scripts/lint-colors.mjs';
// @ts-expect-error plain ESM script without type declarations
import { isDenied, npmFailures } from '../../scripts/license-audit.mjs';

describe('colour lint', () => {
    it('fails raw hex and rgba() outside the token file and names the line', () => {
        const found = findViolations(
            'a\n.x { color: #fff; }\n.y { background: rgba(0, 0, 0, .5); }',
            'resources/css/app.css',
        );

        expect(found).toEqual([
            { line: 2, message: 'raw hex colour #fff' },
            { line: 3, message: 'raw rgba() colour' },
        ]);
    });

    it('allows raw colours in the token file but never the banned values', () => {
        expect(
            findViolations('--a: #123456;', 'resources/css/tokens.css'),
        ).toEqual([]);
        expect(
            findViolations('--a: #ca8a04;', 'resources/css/tokens.css'),
        ).toEqual([{ line: 1, message: 'banned colour #CA8A04' }]);
    });

    it('fails banned colours even in allowlisted files', () => {
        expect(
            findViolations('#FF004A', 'resources/js/pages/Welcome.vue'),
        ).toHaveLength(1);
    });

    it('fails Tailwind palette utilities and bare black/white, naming the line', () => {
        const found = findViolations(
            'a\n<p class="text-neutral-600 bg-black/80 hover:text-white">',
            'resources/js/components/X.vue',
        );

        expect(found.map((f) => f.message)).toEqual([
            'Tailwind palette colour text-neutral-600',
            'Tailwind palette colour bg-black',
            'Tailwind palette colour text-white',
        ]);
        expect(found.every((f) => f.line === 2)).toBe(true);
    });

    it('allows token utilities and palette utilities in the allowlisted Welcome page', () => {
        expect(
            findViolations(
                'class="text-text-primary bg-surface-card text-error-text border-red"',
                'resources/js/components/X.vue',
            ),
        ).toEqual([]);
        expect(
            findViolations(
                'class="bg-red-500"',
                'resources/js/pages/Welcome.vue',
            ),
        ).toEqual([]);
    });

    it('ignores HTML entities and non-colour hashes', () => {
        expect(
            findViolations('&#123; #app #section-1', 'resources/js/x.ts'),
        ).toEqual([]);
    });
});

describe('licence audit', () => {
    it.each([
        'AGPL-3.0-only',
        'AGPL-3.0-or-later',
        'SSPL-1.0',
        'BUSL-1.1',
        'BSL-1.1',
        'Business Source License 1.1',
        '(AGPL-3.0-only OR SSPL-1.0)',
        '(MIT AND AGPL-3.0-only)',
    ])('denies %s', (license) => expect(isDenied(license)).toBe(true));

    it.each([
        'MIT',
        'Apache-2.0',
        'BSD-3-Clause',
        'BSL-1.0',
        'GPL-3.0-only',
        '(MIT OR AGPL-3.0-only)',
    ])('allows %s', (license) => expect(isDenied(license)).toBe(false));
});

const dirs: string[] = [];
const tmp = () => {
    const dir = mkdtempSync(join(tmpdir(), 'gates-'));
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

describe('npm licence audit', () => {
    it('names a denied package and passes a permissive one', () => {
        const root = tmp();
        put(
            root,
            'node_modules/bad/package.json',
            '{"name":"bad","version":"1.0.0","license":"AGPL-3.0-only"}',
        );
        put(
            root,
            'node_modules/@s/ok/package.json',
            '{"name":"@s/ok","version":"1.0.0","license":"MIT"}',
        );
        put(
            root,
            'node_modules/dual/package.json',
            '{"name":"dual","version":"1.0.0","license":"(MIT OR AGPL-3.0-only)"}',
        );

        const failures = npmFailures(root);

        expect(failures).toHaveLength(1);
        expect(failures[0]).toContain('bad@1.0.0');
        expect(failures[0]).toContain('node_modules/bad/package.json');
    });
});

describe('colour lint over a tree', () => {
    it('reports file:line, skips generated folders and exits by result', () => {
        const root = tmp();
        put(
            root,
            'resources/js/pages/A.vue',
            '<template>\n<p style="color: #abc" />\n</template>',
        );
        put(root, 'resources/js/routes/gen.ts', "export const c = '#abc';");

        expect(lintTree(root)).toEqual([
            'resources/js/pages/A.vue:2 raw hex colour #abc',
        ]);

        const script = join(process.cwd(), 'scripts/lint-colors.mjs');
        const bad = spawnSync('node', [script, root], { encoding: 'utf8' });
        expect(bad.status).toBe(1);
        expect(bad.stderr).toContain('resources/js/pages/A.vue:2');

        const clean = tmp();
        put(clean, 'resources/js/pages/B.vue', '<template><p /></template>');
        expect(spawnSync('node', [script, clean]).status).toBe(0);
    });
});
