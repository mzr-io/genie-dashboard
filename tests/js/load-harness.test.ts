import { describe, expect, it } from 'vitest';
import { readSettings, VARIABLES } from '../../load/config.mjs';
import { percentile, summarise } from '../../load/stats.mjs';
import { placeholders } from '../../load/scenarios/index.mjs';

const complete: Record<string, string> = {
    DASHFLOW_LOAD_BASE_URL: 'http://localhost:8080',
    DASHFLOW_LOAD_EMAIL: 'a@example.test',
    DASHFLOW_LOAD_PASSWORD: 'secret',
    DASHFLOW_LOAD_ROLE: 'admin',
    DASHFLOW_LOAD_CONCURRENCY: '2',
    DASHFLOW_LOAD_DURATION_SECONDS: '1.5',
    DASHFLOW_LOAD_RATE_CEILING: '5',
    DASHFLOW_LOAD_P95_TARGET_MS: '800',
    DASHFLOW_LOAD_REQUEST_TIMEOUT_MS: '2000',
};

describe('load harness settings', () => {
    it('names every unset variable and has no default', () => {
        const result = readSettings({});

        expect(result.ok).toBe(false);

        if (!result.ok) {
            expect(result.problems).toHaveLength(
                VARIABLES.filter(
                    (variable: { optional?: boolean }) => !variable.optional,
                ).length,
            );

            for (const variable of VARIABLES.filter(
                (v: { optional?: boolean }) => !v.optional,
            )) {
                expect(
                    result.problems.some((line: string) =>
                        line.startsWith(variable.env),
                    ),
                ).toBe(true);
            }
        }
    });

    it('names only the variable that is missing or invalid', () => {
        const result = readSettings({
            ...complete,
            DASHFLOW_LOAD_ROLE: 'root',
            DASHFLOW_LOAD_CONCURRENCY: '0',
            DASHFLOW_LOAD_P95_TARGET_MS: '',
        });

        expect(result.ok).toBe(false);

        if (!result.ok) {
            expect(
                result.problems.map((line: string) => line.split(' ')[0]),
            ).toEqual([
                'DASHFLOW_LOAD_ROLE',
                'DASHFLOW_LOAD_CONCURRENCY',
                'DASHFLOW_LOAD_P95_TARGET_MS',
            ]);
        }
    });

    it('rejects concurrency above the hard bound and trims the email', () => {
        const high = readSettings({
            ...complete,
            DASHFLOW_LOAD_CONCURRENCY: '201',
        });
        expect(high.ok).toBe(false);

        const ok = readSettings({
            ...complete,
            DASHFLOW_LOAD_EMAIL: '  a@example.test ',
        });
        expect(ok.ok && ok.settings.DASHFLOW_LOAD_EMAIL).toBe('a@example.test');
    });

    it('refuses a remote base URL unless DASHFLOW_LOAD_ALLOW_REMOTE=true', () => {
        for (const url of [
            'http://localhost:8080',
            'http://10.1.2.3',
            'http://192.168.0.5',
            'http://172.20.0.1',
            'http://[::1]:8080',
        ]) {
            expect(
                readSettings({ ...complete, DASHFLOW_LOAD_BASE_URL: url }).ok,
            ).toBe(true);
        }

        const remote = {
            ...complete,
            DASHFLOW_LOAD_BASE_URL: 'https://app.example.com',
        };
        const refused = readSettings(remote);
        expect(refused.ok).toBe(false);
        expect(!refused.ok && refused.problems[0]).toContain(
            'DASHFLOW_LOAD_ALLOW_REMOTE',
        );

        const allowed = readSettings({
            ...remote,
            DASHFLOW_LOAD_ALLOW_REMOTE: 'true',
        });
        expect(allowed.ok && allowed.warnings).toHaveLength(1);
        expect(
            readSettings({ ...remote, DASHFLOW_LOAD_ALLOW_REMOTE: 'yes' }).ok,
        ).toBe(false);
    });

    it('reads a complete environment', () => {
        const result = readSettings(complete);

        expect(result.ok).toBe(true);

        if (result.ok) {
            expect(result.settings.DASHFLOW_LOAD_CONCURRENCY).toBe(2);
            expect(result.settings.DASHFLOW_LOAD_DURATION_SECONDS).toBe(1.5);
            expect(result.settings.DASHFLOW_LOAD_BASE_URL).toBe(
                'http://localhost:8080',
            );
        }
    });
});

describe('load harness report', () => {
    it('computes nearest-rank percentiles and the request rate', () => {
        const latencies = Array.from({ length: 100 }, (_, index) => index + 1);

        expect(percentile(latencies, 50)).toBe(50);
        expect(percentile(latencies, 95)).toBe(95);
        expect(percentile([], 95)).toBeNull();

        expect(
            summarise({
                latencies,
                errorLatencies: [900, 800, 700],
                seconds: 10,
                p95TargetMs: 90,
            }),
        ).toMatchObject({
            requests: 103,
            errors: 3,
            request_rate: 10.3,
            ok_requests: 100,
            p50_ms: 50,
            p95_ms: 95,
            p95_within_target: false,
        });
    });

    it('lists four placeholder scenarios as skipped, not implemented', () => {
        expect(
            placeholders.map((scenario: { id: string }) => scenario.id),
        ).toEqual([
            'dashboard-results-fan-in',
            'sync-dispatch-hot-cold',
            'workspace-budgets',
            'reverb-fan-out',
        ]);

        for (const scenario of placeholders) {
            expect(scenario).toMatchObject({
                implemented: false,
                status: 'skipped',
                reason: 'not implemented',
            });
        }
    });
});
