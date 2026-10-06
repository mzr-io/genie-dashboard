#!/usr/bin/env node
// Licence deny-list: AGPL, SSPL and BSL (Business Source) fail the build. Boost's BSL-1.0 is permissive and allowed.
// Checks Composer packages (`composer licenses`) and installed npm packages (node_modules).
// Usage: node scripts/license-audit.mjs   (exit 1 and names each package on failure)
import { execFileSync } from 'node:child_process';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const DENIED = /AGPL|SSPL|BUSL|Business[\s-]Source/i;
const BSL = /^BSL(?!-1\.0)/i;

function isDeniedSingle(license) {
    return DENIED.test(license) || BSL.test(license.trim());
}

// For an SPDX expression with OR the package may be used under any branch,
// so deny only when every branch is denied. AND and single licences are checked as written.
export function isDenied(license) {
    const branches = license.replace(/^\s*\(|\)\s*$/g, '').split(/\s+OR\s+/i);

    return branches.every(isDeniedSingle);
}

function npmLicenses(pkg) {
    const raw = pkg.license ?? pkg.licenses;
    const list = Array.isArray(raw) ? raw : [raw];

    return list
        .map((entry) => (typeof entry === 'string' ? entry : entry?.type))
        .filter(Boolean);
}

export function npmFailures(root) {
    const modules = join(root, 'node_modules');
    const failures = [];

    if (!existsSync(modules)) {
        return ['node_modules is missing; run npm ci first'];
    }

    const dirs = [];
    for (const name of readdirSync(modules)) {
        if (name.startsWith('.')) {
            continue;
        }
        if (name.startsWith('@')) {
            for (const sub of readdirSync(join(modules, name))) {
                dirs.push(join(modules, name, sub));
            }
        } else {
            dirs.push(join(modules, name));
        }
    }

    for (const dir of dirs) {
        const file = join(dir, 'package.json');

        if (!existsSync(file)) {
            continue;
        }

        const pkg = JSON.parse(readFileSync(file, 'utf8'));
        const denied = npmLicenses(pkg).filter(isDenied);

        if (denied.length > 0) {
            failures.push(
                `npm ${pkg.name}@${pkg.version} (${file}) uses ${denied.join(', ')}`,
            );
        }
    }

    return failures;
}

export function composerFailures(root) {
    const out = execFileSync(
        'composer',
        ['licenses', '--format=json', '--no-interaction'],
        {
            cwd: root,
            encoding: 'utf8',
            maxBuffer: 64 * 1024 * 1024,
        },
    );
    const { dependencies } = JSON.parse(out);

    return Object.entries(dependencies)
        .filter(([, info]) => info.license.some(isDenied))
        .map(
            ([name, info]) =>
                `composer ${name} (vendor/) uses ${info.license.join(', ')}`,
        );
}

if (
    process.argv[1] &&
    resolve(process.argv[1]) === fileURLToPath(import.meta.url)
) {
    const root = resolve('.');
    const failures = [...composerFailures(root), ...npmFailures(root)];

    if (failures.length > 0) {
        console.error(failures.join('\n'));
        console.error(
            `\nLicence audit failed: ${failures.length} denied package(s).`,
        );
        process.exit(1);
    }

    console.log('Licence audit passed.');
}
