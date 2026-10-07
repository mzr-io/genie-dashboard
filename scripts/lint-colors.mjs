#!/usr/bin/env node
// Colour lint (UX-DR-15, UX-DR-285). Raw hex and rgba() colours fail outside the token file;
// the four banned brand values fail everywhere, including the token file.
// Usage: node scripts/lint-colors.mjs [root]   (exit 1 and names file:line on failure)
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

export const TOKEN_FILE = 'resources/css/tokens.css';

// Temporary allowlist for raw colours (banned values still fail). Remove each entry when the
// file moves to design tokens: Welcome.vue in Story 1.16.
export const ALLOWLIST = ['resources/js/pages/Welcome.vue'];

const BANNED = ['#00D987', '#FF004A', '#FFDD1D', '#CA8A04'];
const RAW_HEX =
    /(?<![\w&])#(?:[0-9a-f]{8}|[0-9a-f]{6}|[0-9a-f]{3,4})(?![\w-])/gi;
const RAW_RGBA = /\brgba\s*\(/gi;
// Tailwind palette utilities (bg-red-500, text-neutral-600/50) and bare black/white bypass the tokens.
const PALETTE_UTILITY =
    /(?<![\w-])(?:bg|text|border|ring|stroke|fill|from|to|via|divide|outline|decoration|shadow|placeholder|caret|accent)-(?:(?:red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|slate|gray|zinc|neutral|stone)-\d+|black|white)(?![\w-])/g;
const EXTENSIONS = /\.(vue|css|scss|ts|tsx|js|mjs|html|php)$/;
const SKIP = [
    'resources/js/actions',
    'resources/js/routes',
    'resources/js/wayfinder',
];

/** @returns {{line: number, message: string}[]} */
export function findViolations(text, file) {
    const rel = file.split(sep).join('/');
    const isToken = rel === TOKEN_FILE;
    const isAllowed = ALLOWLIST.includes(rel);
    const found = [];

    text.split('\n').forEach((content, index) => {
        const line = index + 1;
        const upper = content.toUpperCase();

        for (const banned of BANNED) {
            if (upper.includes(banned)) {
                found.push({ line, message: `banned colour ${banned}` });
            }
        }

        if (isToken || isAllowed) {
            return;
        }

        for (const match of content.matchAll(RAW_HEX)) {
            if (!BANNED.includes(match[0].toUpperCase())) {
                found.push({ line, message: `raw hex colour ${match[0]}` });
            }
        }

        for (const match of content.matchAll(PALETTE_UTILITY)) {
            found.push({
                line,
                message: `Tailwind palette colour ${match[0]}`,
            });
        }

        if (RAW_RGBA.test(content)) {
            found.push({ line, message: 'raw rgba() colour' });
        }

        RAW_RGBA.lastIndex = 0;
    });

    return found;
}

function* walk(dir) {
    for (const name of readdirSync(dir)) {
        const path = join(dir, name);

        if (statSync(path).isDirectory()) {
            yield* walk(path);
        } else if (EXTENSIONS.test(name)) {
            yield path;
        }
    }
}

export function lintTree(root) {
    const resources = join(root, 'resources');
    const failures = [];

    for (const path of walk(resources)) {
        const rel = relative(root, path).split(sep).join('/');

        if (SKIP.some((skip) => rel.startsWith(skip + '/'))) {
            continue;
        }

        for (const { line, message } of findViolations(
            readFileSync(path, 'utf8'),
            rel,
        )) {
            failures.push(`${rel}:${line} ${message}`);
        }
    }

    return failures;
}

if (
    process.argv[1] &&
    resolve(process.argv[1]) === fileURLToPath(import.meta.url)
) {
    const failures = lintTree(resolve(process.argv[2] ?? '.'));

    if (failures.length > 0) {
        console.error(failures.join('\n'));
        console.error(`\nColour lint failed: ${failures.length} violation(s).`);
        process.exit(1);
    }

    console.log('Colour lint passed.');
}
