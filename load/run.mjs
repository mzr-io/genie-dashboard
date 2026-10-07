#!/usr/bin/env node
// Dashflow load-test harness (Story 1.25). Plain Node, no dependencies; it never runs in CI.
//   npm run load                 sign in, run the page flow, print a JSON report
//   npm run load -- --strict     also exit 1 when p95 is over its target (or unknown) or any request failed
//   npm run load:plan            list the scenarios and the DASHFLOW_LOAD_* variables, run nothing
// Every target number, the base URL and the credentials come from DASHFLOW_LOAD_* (no defaults).
import { readSettings, VARIABLES } from './config.mjs';
import { runPageFlow } from './page-flow.mjs';
import { skippedReport } from './scenarios/index.mjs';

const plan = process.argv.includes('--plan');
const strict = process.argv.includes('--strict');

if (plan) {
    console.log(
        JSON.stringify(
            {
                scenarios: [
                    {
                        id: 'page-flow',
                        name: 'Sign in and authenticated page flow',
                        status: 'implemented',
                    },
                    ...skippedReport(),
                ],
                variables: VARIABLES.map(({ env, description, optional }) => ({
                    env,
                    description,
                    optional: optional === true,
                    set: (process.env[env] ?? '').trim() !== '',
                })),
            },
            null,
            2,
        ),
    );
    process.exit(0);
}

const settings = readSettings();

if (!settings.ok) {
    console.error(
        'The load harness has no default targets. Fix these and run again:',
    );

    for (const problem of settings.problems) {
        console.error(`  - ${problem}`);
    }

    process.exit(2);
}

for (const warning of settings.warnings) {
    console.error(`warning: ${warning}`);
}

try {
    const report = await runPageFlow(settings.settings);

    console.log(
        JSON.stringify({ ...report, skipped: skippedReport() }, null, 2),
    );

    if (strict && (report.p95_within_target !== true || report.errors > 0)) {
        console.error(
            `--strict: p95 within target is ${report.p95_within_target}, errors ${report.errors}.`,
        );
        process.exit(1);
    }
} catch (error) {
    console.error(
        `Load run failed: ${error instanceof Error ? error.message : String(error)}`,
    );
    process.exit(1);
}
