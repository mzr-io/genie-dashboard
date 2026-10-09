#!/usr/bin/env node
// Pinned-directory guard (Story 3.1). A Block Version pins a Block Type contract `(key, contractVersion)`, and a contract directory
// `block-types/{key}/v{N}/` is never deleted or renamed while a version pins it. `block-types/pins.json` lists the pinned pairs.
// Usage: node scripts/check-block-type-pins.mjs [root]   (exit 1 and names each missing directory on failure)
import { existsSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

/** @returns {string[]} one message per pinned (key, version) whose directory or schema.json is missing */
export function missingPins(root) {
    const pinsFile = join(root, 'block-types', 'pins.json');

    if (!existsSync(pinsFile)) {
        return [`${pinsFile} is missing`];
    }

    let pins;

    try {
        pins = JSON.parse(readFileSync(pinsFile, 'utf8')).pins;
    } catch (error) {
        return [`${pinsFile} is not valid JSON (${error.message})`];
    }

    if (!Array.isArray(pins)) {
        return [`${pinsFile} must hold a "pins" array`];
    }

    const malformed = pins.flatMap((pin, index) =>
        pin !== null &&
        typeof pin === 'object' &&
        typeof pin.key === 'string' &&
        Number.isInteger(pin.contractVersion)
            ? []
            : [
                  `${pinsFile}: pin #${index} needs a string key and an integer contractVersion`,
              ],
    );

    if (malformed.length > 0) {
        return malformed;
    }

    return pins.flatMap(({ key, contractVersion }) => {
        const dir = `block-types/${key}/v${contractVersion}`;

        return existsSync(join(root, dir, 'schema.json'))
            ? []
            : [
                  `${dir}/ is pinned by a Block Version: a contract directory is never deleted or renamed while a version pins it`,
              ];
    });
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    const root = resolve(process.argv[2] ?? '.');
    const failures = missingPins(root);

    failures.forEach((message) => console.error(message));
    process.exit(failures.length > 0 ? 1 : 0);
}
