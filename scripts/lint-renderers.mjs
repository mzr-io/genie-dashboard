#!/usr/bin/env node
// Renderer lint (Story 3.1, AD-21). A Block Type's Renderer.vue renders text only by interpolation: `v-html`, `innerHTML` (and its
// siblings), `eval`/`new Function` and formatter strings (a formatter given as a string, which is evaluated) fail.
// Usage: node scripts/lint-renderers.mjs [root]   (exit 1 and names file:line on failure)
import { lstatSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const RULES = [
    [/\bv-html\b/, 'v-html'],
    [/\b(?:innerHTML|outerHTML|insertAdjacentHTML)\b/, 'innerHTML'],
    [
        /(?:\beval\s*\(|\bnew\s+Function\b|\bFunction\s*\()/,
        'dynamic code evaluation',
    ],
    [
        /(?:\bformat(?:ter)?\s*[:=]\s*["'`]|\bformat(?:ter)?String\b)/i,
        'formatter string',
    ],
];

/** @returns {{line: number, message: string}[]} */
export function findViolations(text) {
    const found = [];

    text.split('\n').forEach((content, index) => {
        for (const [pattern, name] of RULES) {
            if (pattern.test(content)) {
                found.push({ line: index + 1, message: `${name} is banned` });
            }
        }
    });

    return found;
}

function renderers(dir) {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        const stat = lstatSync(path);

        if (stat.isSymbolicLink()) {
            return [];
        }

        if (stat.isDirectory()) {
            return renderers(path);
        }

        return /\.(?:vue|ts|js|mjs)$/.test(name) ? [path] : [];
    });
}

// Story 5.8: the shared chart wrapper is held to the same rules as the packages that use it.
const SHARED = ['resources/js/charts'];

/** @returns {string[]} `file:line message` for every violation under `{root}/block-types` and the shared chart code */
export function lintTree(root) {
    const base = join(root, 'block-types');

    try {
        statSync(base);
    } catch {
        return [`${base} is missing: there is nothing to lint`];
    }

    const shared = SHARED.map((dir) => join(root, dir)).filter((dir) => {
        try {
            return statSync(dir).isDirectory();
        } catch {
            return false;
        }
    });

    return [base, ...shared]
        .flatMap(renderers)
        .flatMap((file) =>
            findViolations(readFileSync(file, 'utf8')).map(
                ({ line, message }) =>
                    `${relative(root, file).split(sep).join('/')}:${line} ${message}`,
            ),
        );
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    const failures = lintTree(resolve(process.argv[2] ?? '.'));

    failures.forEach((message) => console.error(message));
    process.exit(failures.length > 0 ? 1 : 0);
}
