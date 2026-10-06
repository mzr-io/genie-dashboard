/** Brand tokens a Workspace may override (UX-DR-283). Everything else is a fixed system token. */
export const BRAND_TOKENS = [
    'accent',
    'accent-strong',
    'accent-border',
    'accent-soft',
    'accent-wash',
    'accent-ink',
    'accent-ink-strong',
    'accent-ink-inverse',
    'on-accent',
    'logo-tile',
    'logo-mark',
] as const;

export type BrandToken = (typeof BRAND_TOKENS)[number];
export type BrandOverrides = Partial<Record<BrandToken, string>>;

const MIN_TEXT_CONTRAST = 4.5;

/** Reads the `--df-*` custom properties declared in the first `:root` block of a token file. */
export function parseTokens(css: string): Record<string, string> {
    const block = /:root\s*\{([\s\S]*?)\n\}/.exec(css)?.[1] ?? '';
    const tokens: Record<string, string> = {};

    for (const match of block.matchAll(/--df-([\w-]+):\s*([^;]+);/g)) {
        tokens[match[1]] = match[2].replace(/\s+/g, ' ').trim();
    }

    return tokens;
}

/** Tokens the guardrails compare against. They always come from the token file, never from literals. */
const GUARD_TOKENS = [
    'accent',
    'accent-soft',
    'text-primary',
    'on-status',
    'surface-card',
    'surface-inverse',
    'hero-chip',
    'accent-ink',
    'accent-ink-inverse',
] as const;

export type TokenValues = Record<string, string>;

/** Reads the default token values from the loaded stylesheet (browser only). */
export function readDefaults(
    element: Element = document.documentElement,
): TokenValues {
    const style = getComputedStyle(element);
    const values: TokenValues = {};

    for (const name of GUARD_TOKENS) {
        values[name] = style.getPropertyValue(`--df-${name}`).trim();
    }

    return values;
}

function parseHex(value: string): [number, number, number] | null {
    const hex = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(value.trim())?.[1];

    if (!hex) {
        return null;
    }

    const full =
        hex.length === 3
            ? hex.replace(/./g, (character) => character + character)
            : hex;

    return [0, 2, 4].map((at) => parseInt(full.slice(at, at + 2), 16)) as [
        number,
        number,
        number,
    ];
}

function luminance([red, green, blue]: [number, number, number]): number {
    const [r, g, b] = [red, green, blue].map((channel) => {
        const value = channel / 255;

        return value <= 0.03928
            ? value / 12.92
            : ((value + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** WCAG 2.1 contrast ratio between two hex colours; 0 when either is not a valid hex colour. */
export function contrastRatio(first: string, second: string): number {
    const a = parseHex(first);
    const b = parseHex(second);

    if (!a || !b) {
        return 0;
    }

    const [high, low] = [luminance(a), luminance(b)].sort((x, y) => y - x);

    return (high + 0.05) / (low + 0.05);
}

/** `on-accent`: whichever of the dark and light ink has the higher contrast against the accent. */
export function deriveOnAccent(accent: string, defaults: TokenValues): string {
    const dark = defaults['text-primary'];
    const light = defaults['on-status'];

    return contrastRatio(dark, accent) >= contrastRatio(light, accent)
        ? dark
        : light;
}

function validColour(value: string | undefined): string | undefined {
    return value !== undefined && parseHex(value) ? value.trim() : undefined;
}

/**
 * Resolves a Workspace's brand override into CSS custom properties for a `[data-workspace]` scope.
 * Only brand tokens are accepted; anything else, and any invalid colour, is ignored. A weak
 * `accent-ink` or `accent-ink-inverse` falls back to the default. Never throws.
 */
export function resolveBrand(
    overrides: BrandOverrides,
    defaults: TokenValues,
): Record<string, string> {
    const input: Record<string, string | undefined> = {};

    for (const token of BRAND_TOKENS) {
        input[token] = validColour(overrides[token]);
    }

    const accent = input['accent'] ?? defaults['accent'];
    const soft = input['accent-soft'] ?? defaults['accent-soft'];
    const white = defaults['surface-card'];

    if (
        input['accent-ink'] !== undefined &&
        (contrastRatio(input['accent-ink'], white) < MIN_TEXT_CONTRAST ||
            contrastRatio(input['accent-ink'], soft) < MIN_TEXT_CONTRAST)
    ) {
        input['accent-ink'] = undefined;
    }

    if (
        input['accent-ink-inverse'] !== undefined &&
        (contrastRatio(
            input['accent-ink-inverse'],
            defaults['surface-inverse'],
        ) < MIN_TEXT_CONTRAST ||
            contrastRatio(input['accent-ink-inverse'], defaults['hero-chip']) <
                MIN_TEXT_CONTRAST)
    ) {
        input['accent-ink-inverse'] = undefined;
    }

    // on-accent is always derived, never taken from the override.
    input['on-accent'] = deriveOnAccent(accent, defaults);

    const resolved: Record<string, string> = {};

    for (const token of BRAND_TOKENS) {
        const value = input[token];

        if (value !== undefined) {
            resolved[`--df-${token}`] = value;
        }
    }

    return resolved;
}
