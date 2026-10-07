import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { describe, expect, it } from 'vitest';

// Static check (UX-DR-16): a view has exactly one primary button. A <Button> is primary when it
// has no `variant` or `variant="primary"`. Alternative branches (v-else, v-else-if) are one
// button at a time, so they do not add to the count.
export function primaryButtons(text: string): number {
    let count = 0;
    let from = 0;

    for (;;) {
        const start = text.indexOf('<Button', from);

        if (start === -1) {
            return count;
        }

        from = start + 7;

        if (!/[\s/>]/.test(text[from] ?? '')) {
            continue;
        }

        // Read the opening tag up to the first `>` that is outside a quoted attribute value.
        let quote = '';
        let end = from;

        for (; end < text.length; end++) {
            const ch = text[end];

            if (quote) {
                if (ch === quote) {
                    quote = '';
                }
            } else if (ch === '"' || ch === "'") {
                quote = ch;
            } else if (ch === '>') {
                break;
            }
        }

        const tag = text.slice(start, end);
        const staticVariant = tag.match(/(?<![:\w-])variant\s*=\s*"([^"]*)"/);
        const dynamicVariant = /(?:^|\s):variant\s*=/.test(tag);
        const alternative = /\sv-else(?:-if)?\b/.test(tag);

        if (alternative || dynamicVariant) {
            continue;
        }

        if (!staticVariant || staticVariant[1] === 'primary') {
            count++;
        }
    }
}

const SCAN = [
    'resources/js/pages',
    'resources/js/layouts',
    'resources/js/components',
];

function* views(dir: string): Generator<string> {
    for (const name of readdirSync(dir)) {
        const path = join(dir, name);
        const rel = relative('.', path).split(sep).join('/');

        if (rel.startsWith('resources/js/components/ui')) {
            continue;
        }

        if (statSync(path).isDirectory()) {
            yield* views(path);
        } else if (name.endsWith('.vue')) {
            yield path;
        }
    }
}

describe('one primary button per view', () => {
    it('counts primary buttons and ignores other variants and alternatives', () => {
        expect(primaryButtons('<Button>A</Button>')).toBe(1);
        expect(primaryButtons('<Button variant="primary">A</Button>')).toBe(1);
        expect(primaryButtons('<Button variant="secondary">A</Button>')).toBe(
            0,
        );
        expect(
            primaryButtons(
                '<Button @click="() => go(1 > 0)">A</Button><Button variant="ghost" />',
            ),
        ).toBe(1);
        expect(
            primaryButtons(
                '<Button v-if="a">A</Button><Button v-else>B</Button>',
            ),
        ).toBe(1);
        expect(
            primaryButtons('<ButtonGroup><Button>A</Button></ButtonGroup>'),
        ).toBe(1);
    });

    it('fails a view with two primary buttons, naming the file', () => {
        const offenders = [
            [
                'resources/js/pages/Two.vue',
                '<Button>A</Button><Button>B</Button>',
            ],
        ].filter(([, text]) => primaryButtons(text) > 1);

        expect(offenders.map(([file]) => file)).toEqual([
            'resources/js/pages/Two.vue',
        ]);
    });

    it('finds at most one primary button in every page, layout and component', () => {
        const offenders: string[] = [];

        for (const dir of SCAN) {
            for (const path of views(dir)) {
                const count = primaryButtons(readFileSync(path, 'utf8'));

                if (count > 1) {
                    offenders.push(`${path}: ${count} primary buttons`);
                }
            }
        }

        expect(offenders).toEqual([]);
    });

    it('leaves no caller of the removed default or outline variants', () => {
        const stale: string[] = [];

        for (const path of views('resources/js')) {
            if (
                /variant="(default|outline)"/.test(readFileSync(path, 'utf8'))
            ) {
                stale.push(path);
            }
        }

        expect(stale).toEqual([]);
    });
});
