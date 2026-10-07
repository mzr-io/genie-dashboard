import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { BRAND_TOKENS, parseTokens } from '../../resources/js/lib/brand';

const css = readFileSync('resources/css/tokens.css', 'utf8');
const app = readFileSync('resources/css/app.css', 'utf8');
const tokens = parseTokens(css);

const EXPECTED: Record<string, string> = {
    // UX-DR-1 brand
    accent: '#facc15',
    'accent-strong': '#eab308',
    'accent-border': '#fde047',
    'accent-soft': '#fef9c3',
    'accent-wash': '#fffbeb',
    'accent-ink': '#a16207',
    'accent-ink-strong': '#854d0e',
    'accent-ink-inverse': '#fde047',
    'on-accent': '#111827',
    'logo-tile': '#facc15',
    'logo-mark': '#111827',
    // UX-DR-2 system
    'surface-canvas': '#f7f8fa',
    'surface-card': '#ffffff',
    'surface-sunken': '#f9fafb',
    'surface-muted': '#f3f4f6',
    'border-default': '#e5e7eb',
    'border-strong': '#d1d5db',
    'border-control': '#80868f',
    'text-primary': '#111827',
    'text-secondary': '#374151',
    'text-muted': '#6b7280',
    'text-subtle': '#9ca3af',
    'text-inverse': '#f9fafb',
    'surface-inverse': '#111827',
    scrim: '#111827',
    'shadow-color': '#111827',
    'focus-ring': '#2563eb',
    'on-status': '#ffffff',
    // UX-DR-3 status
    success: '#16a34a',
    'success-text': '#15803d',
    'success-soft': '#dcfce7',
    'success-wash': '#f0fdf4',
    'success-border': '#bbf7d0',
    error: '#dc2626',
    'error-text': '#b91c1c',
    'error-soft': '#fef2f2',
    'error-border': '#fca5a5',
    warning: '#c2410c',
    'warning-bar': '#f97316',
    'warning-soft': '#ffedd5',
    'warning-border': '#fed7aa',
    info: '#1d4ed8',
    'info-strong': '#1e40af',
    'info-soft': '#dbeafe',
    // UX-DR-4 hero
    'hero-surface': '#0a0e11',
    'hero-surface-raised': '#11161a',
    'hero-card': '#181920',
    'hero-border': '#1d2229',
    'hero-text': '#ffffff',
    'hero-text-muted': '#9099a6',
    'hero-chip': '#1c1c13',
    // UX-DR-5 roles
    'role-label-text': '#374151',
    'role-label-fill': '#f3f4f6',
    'role-label-border': '#e5e7eb',
    'role-value-text': '#854d0e',
    'role-value-fill': '#fef08a',
    'role-value-border': '#fde047',
    'role-dimension-text': '#0f766e',
    'role-dimension-fill': '#ccfbf1',
    'role-dimension-border': '#99f6e4',
    'role-measure-text': '#1d4ed8',
    'role-measure-fill': '#dbeafe',
    'role-measure-border': '#bfdbfe',
    'role-time-text': '#6d28d9',
    'role-time-fill': '#ede9fe',
    'role-time-border': '#ddd6fe',
    'role-filter-text': '#c2410c',
    'role-filter-fill': '#ffedd5',
    'role-filter-border': '#fed7aa',
    // UX-DR-6 chart
    'chart-2': '#4c6eb1',
    'chart-3': '#0f766e',
    'chart-4': '#db2777',
    'chart-5': '#ea580c',
    'chart-6': '#6b7280',
    'chart-grid': '#f3f4f6',
    'chart-1-fill': '#fef9c3',
};

describe('design tokens (UX-DR-1..6)', () => {
    it.each(Object.entries(EXPECTED))(
        '--df-%s resolves to %s',
        (name, value) => {
            expect(tokens[name]).toBe(value);
        },
    );

    it('makes chart-1 follow accent and chart-1-stroke follow accent-ink', () => {
        expect(tokens['chart-1']).toBe('var(--df-accent)');
        expect(tokens['chart-1-stroke']).toBe('var(--df-accent-ink)');
    });

    it('defines no -dark values and no dark scope', () => {
        expect(
            Object.keys(tokens).filter((name) => name.endsWith('-dark')),
        ).toEqual([]);
        expect(css).not.toMatch(/-dark\s*:/);
        expect(css + app).not.toMatch(
            /\.dark\b|@custom-variant dark|prefers-color-scheme/,
        );
    });

    it('keeps raw colour values out of everything but hex declarations', () => {
        expect(css).not.toMatch(/rgba?\(/);
    });
});

describe('typography (UX-DR-7)', () => {
    const scale: Record<string, [string, string, string, string]> = {
        'display-hero': ['56px', '500', '1.08', '-0.02em'],
        'headline-lg': ['28px', '500', '1.25', '-0.01em'],
        'headline-md': ['20px', '700', '1.3', '0'],
        'title-md': ['15px', '600', '1.35', '0'],
        'title-sm': ['13px', '600', '1.35', '0'],
        'body-md': ['14px', '400', '1.5', '0'],
        'body-sm': ['13px', '400', '1.45', '0'],
        caption: ['12px', '400', '1.4', '0'],
        'label-caps': ['11px', '600', '1.4', '0.08em'],
        'kpi-headline': ['26px', '700', '1.15', '-0.01em'],
        'kpi-card': ['20px', '700', '1.2', '-0.01em'],
        mono: ['12px', '400', '1.45', '0'],
    };

    it.each(Object.entries(scale))(
        '%s has the specified values',
        (name, [size, weight, leading, tracking]) => {
            expect(tokens[`type-${name}-size`]).toBe(size);
            expect(tokens[`type-${name}-weight`]).toBe(weight);
            expect(tokens[`type-${name}-leading`]).toBe(leading);
            expect(tokens[`type-${name}-tracking`]).toBe(tracking);
            expect(app).toContain(`.type-${name} {`);
        },
    );

    it('uses no size below 12px except label-caps', () => {
        for (const [name, [size]] of Object.entries(scale)) {
            if (name !== 'label-caps') {
                expect(parseInt(size)).toBeGreaterThanOrEqual(12);
            }
        }
    });

    it('uses Inter with the fallback stack and tabular numerals', () => {
        expect(tokens['font-sans']).toBe(
            "Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif",
        );
        expect(tokens['font-mono']).toBe(
            'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
        );
        expect(app).toContain('font-variant-numeric: tabular-nums');
        expect(readFileSync('vite.config.ts', 'utf8')).toMatch(
            /bunny\('Inter'/,
        );
    });
});

describe('radius, spacing, sizing and elevation (UX-DR-9..11)', () => {
    it('defines the scales', () => {
        expect(
            ['xs', 'sm', 'md', 'lg', 'xl', 'sheet', 'full'].map(
                (k) => tokens[`radius-${k}`],
            ),
        ).toEqual(['4px', '6px', '8px', '10px', '12px', '16px', '9999px']);
        expect(
            [1, 2, 3, 4, 5, 6, 7, 8, 10].map((k) => tokens[`space-${k}`]),
        ).toEqual([
            '4px',
            '8px',
            '12px',
            '16px',
            '20px',
            '24px',
            '28px',
            '32px',
            '40px',
        ]);
        expect(tokens['grid-row-unit']).toBe('60px');
        for (const key of [
            'block-height-small',
            'block-height-medium',
            'block-height-large',
        ]) {
            expect(
                parseInt(tokens[key]) % parseInt(tokens['grid-row-unit']),
            ).toBe(0);
        }
        expect(tokens['sidebar-width']).toBe('232px');
        expect(tokens['topbar-height']).toBe('64px');
        expect(tokens['target-touch']).toBe('44px');
    });

    it('gives resting cards no shadow and floating items a shadow-color shadow', () => {
        expect(tokens['shadow-card']).toBe('none');
        expect(tokens['shadow-toast']).toContain('0 10px 30px');
        expect(tokens['shadow-toast']).toContain('var(--df-shadow-color) 25%');
        expect(tokens['shadow-dialog']).toContain('0 12px 40px');
        expect(tokens['shadow-dialog']).toContain('12%');
        expect(tokens['shadow-drawer-row']).toContain('0 4px 16px');
        expect(app).toMatch(
            /\.surface-card \{[^}]*border: 1px solid var\(--df-border-default\)/,
        );
        expect(app).toMatch(
            /\.surface-card \{[^}]*box-shadow: var\(--df-shadow-card\)/,
        );
    });
});

describe('focus and scroll padding (UX-DR-12, UX-DR-268)', () => {
    it('draws a 3px solid blue focus ring with a 2px offset', () => {
        expect(tokens['focus-width']).toBe('3px');
        expect(tokens['focus-offset']).toBe('2px');
        expect(app).toMatch(
            /:focus-visible \{\s*outline: var\(--df-focus-width\) solid var\(--df-focus-ring\);\s*outline-offset: var\(--df-focus-offset\)/,
        );
        expect(tokens['focus-ring']).not.toBe(tokens['accent']);
    });

    it('sets scroll-padding for sticky layers', () => {
        expect(app).toMatch(
            /scroll-padding-top: calc\(var\(--df-topbar-height\)/,
        );
    });
});

describe('shadcn-vue binding (UX-DR-287)', () => {
    it.each([
        ['primary', 'df-accent'],
        ['primary-foreground', 'df-on-accent'],
        ['ring', 'df-focus-ring'],
        ['input', 'df-border-control'],
        ['border', 'df-border-default'],
        ['destructive', 'df-error'],
    ])('--%s is --%s', (variable, token) => {
        expect(app).toMatch(new RegExp(`--${variable}: var\\(--${token}\\);`));
    });
});

describe('selected-state cues (UX-DR-14)', () => {
    it('ships a non-colour cue for each selected state', () => {
        expect(app).toMatch(
            /\.cue-bar\[data-active='true'\]::before[\s\S]*?width: 3px/,
        );
        expect(app).toMatch(
            /\.cue-border[\s\S]*?2px solid var\(--df-accent-ink-strong\)/,
        );
        expect(app).toMatch(
            /\.cue-segmented[\s\S]*?1px solid var\(--df-border-control\)/,
        );
        expect(app).toMatch(/\.cue-chip[\s\S]*?content: '\\2713'/);
        expect(app).toMatch(
            /\.cue-option-active[\s\S]*?outline: 2px solid var\(--df-focus-ring\)/,
        );
        expect(
            readFileSync('resources/js/components/ui/sidebar/index.ts', 'utf8'),
        ).toContain('data-[active=true]:before:w-[3px]');
    });
});

describe('brand seam (UX-DR-283)', () => {
    it('lets the [data-workspace] scope redeclare only brand-following tokens', () => {
        const scope = /\[data-workspace\]\s*\{([^}]*)\}/.exec(css)?.[1] ?? '';
        const declared = [...scope.matchAll(/--df-([\w-]+):/g)].map(
            (m) => m[1],
        );
        const allowed = new Set<string>([
            ...BRAND_TOKENS,
            'chart-1',
            'chart-1-stroke',
            'halo-accent',
            'progress-bar',
        ]);

        expect(declared.length).toBeGreaterThan(0);
        expect(declared.filter((name) => !allowed.has(name))).toEqual([]);
    });

    it('keeps the brand token list inside the token file', () => {
        for (const token of BRAND_TOKENS) {
            expect(tokens[token]).toBeDefined();
        }
    });
});

function* sources(dir: string): Generator<string> {
    for (const name of readdirSync(dir)) {
        const path = join(dir, name);

        if (statSync(path).isDirectory()) {
            yield* sources(path);
        } else if (/\.(vue|ts|css|php)$/.test(name)) {
            yield path;
        }
    }
}

describe('Appearance and dark theme are gone (UX-DR-286)', () => {
    it('leaves no trace in app source', () => {
        const found: string[] = [];

        for (const root of ['resources', 'app', 'routes', 'bootstrap']) {
            for (const path of sources(root)) {
                const text = readFileSync(path, 'utf8');

                if (
                    /appearance|\bdark:|\.dark\b|\bdark mode|useAppearance|prefers-color-scheme/i.test(
                        text,
                    )
                ) {
                    found.push(path);
                }
            }
        }

        expect(found).toEqual([]);
    });
});

describe('component focus and link colour', () => {
    const uiFiles = [...sources('resources/js/components/ui')];

    it('sets no focus ring, focus border or outline utility in ui components', () => {
        const offenders = uiFiles.filter((path) =>
            /(?:focus(?:-visible|-within)?:(?:ring|border-ring|outline)|(?<![\w-])outline-(?:none|hidden|1)\b|(?<![\w:-])ring-(?:2|4|ring|sidebar-ring))/.test(
                readFileSync(path, 'utf8'),
            ),
        );

        expect(offenders).toEqual([]);
    });

    it('colours the link button with accent-ink, never the yellow accent', () => {
        const button = readFileSync(
            'resources/js/components/ui/button/index.ts',
            'utf8',
        );

        expect(button).toMatch(/"link":\s*"[^"]*\btext-accent-ink\b/);
        expect(button).not.toMatch(/(?<![\w-])text-primary(?!-foreground)/);
    });

    it('uses no Tailwind palette utilities in app components', () => {
        const palette =
            /(?<![\w-])(?:bg|text|border|stroke|fill)-(?:(?:red|green|neutral|zinc|gray)-\d+|black|white)\b/;
        const offenders = [
            ...sources('resources/js/components'),
            ...sources('resources/js/layouts'),
        ].filter((path) => palette.test(readFileSync(path, 'utf8')));

        expect(offenders).toEqual([]);
    });
});
