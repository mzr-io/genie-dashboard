// Load-harness settings. Every target number, the base URL and the credentials come from the
// environment (DASHFLOW_LOAD_*) and none has a default: the client has not supplied them, so the
// harness refuses to run until they are set. config/dashflow.php documents the same variables under
// `load` as `pending_input` settings; tests/Feature/LoadSettingsTest.php keeps the two lists equal.
// DASHFLOW_LOAD_ALLOW_REMOTE is the one optional variable: it stays off unless set to `true`.

/** Hard upper bound for DASHFLOW_LOAD_CONCURRENCY: a skeleton harness must not become a stress tool by typo. */
export const MAX_CONCURRENCY = 200;

export const VARIABLES = [
    {
        env: 'DASHFLOW_LOAD_BASE_URL',
        kind: 'url',
        description:
            'Base URL of the Compose stack. Only loopback and private-network hosts unless DASHFLOW_LOAD_ALLOW_REMOTE=true.',
    },
    {
        env: 'DASHFLOW_LOAD_EMAIL',
        kind: 'email',
        description: 'Email of the account the flow signs in as.',
    },
    {
        env: 'DASHFLOW_LOAD_PASSWORD',
        kind: 'secret',
        description: 'Password of that account (never printed).',
    },
    {
        env: 'DASHFLOW_LOAD_ROLE',
        kind: 'role',
        description: 'Sign-in card: user or admin.',
    },
    {
        env: 'DASHFLOW_LOAD_CONCURRENCY',
        kind: 'integer',
        max: MAX_CONCURRENCY,
        description: `Concurrent virtual users sharing the signed-in session (at most ${MAX_CONCURRENCY}).`,
    },
    {
        env: 'DASHFLOW_LOAD_DURATION_SECONDS',
        kind: 'number',
        description: 'How long the page flow runs, in seconds.',
    },
    {
        env: 'DASHFLOW_LOAD_RATE_CEILING',
        kind: 'number',
        description: 'Most requests per second the harness may send in total.',
    },
    {
        env: 'DASHFLOW_LOAD_P95_TARGET_MS',
        kind: 'number',
        description:
            'p95 latency target in milliseconds, compared with the result.',
    },
    {
        env: 'DASHFLOW_LOAD_REQUEST_TIMEOUT_MS',
        kind: 'number',
        description: 'Per-request timeout in milliseconds.',
    },
    {
        env: 'DASHFLOW_LOAD_ALLOW_REMOTE',
        kind: 'flag',
        optional: true,
        description:
            'Set to true to allow a base URL outside loopback and private networks. Development use only: never point the harness at a shared or production system.',
    },
];

/** True for localhost, loopback and private-network IP literals. */
export function isLocalHost(hostname) {
    const host = hostname.replace(/^\[|\]$/g, '').toLowerCase();

    if (host === 'localhost' || host.endsWith('.localhost') || host === '::1') {
        return true;
    }

    if (/^(fc|fd)[0-9a-f]{2}:/.test(host) || host.startsWith('fe80:')) {
        return true;
    }

    const octets = host.split('.');

    if (octets.length !== 4 || !octets.every((o) => /^\d{1,3}$/.test(o))) {
        return false;
    }

    const [a, b] = octets.map(Number);

    return (
        a === 127 ||
        a === 10 ||
        (a === 172 && b >= 16 && b <= 31) ||
        (a === 192 && b === 168) ||
        (a === 169 && b === 254)
    );
}

function parse(variable, raw) {
    const text = raw.trim();

    switch (variable.kind) {
        case 'url': {
            try {
                const url = new URL(text);

                return ['http:', 'https:'].includes(url.protocol)
                    ? url.origin
                    : undefined;
            } catch {
                return undefined;
            }
        }
        case 'role':
            return ['user', 'admin'].includes(text) ? text : undefined;
        case 'integer': {
            if (!/^[1-9]\d*$/.test(text)) {
                return undefined;
            }

            return Number(text) <= (variable.max ?? Infinity)
                ? Number(text)
                : undefined;
        }
        case 'number': {
            const value = Number(text);

            return text !== '' && Number.isFinite(value) && value > 0
                ? value
                : undefined;
        }
        case 'flag':
            return text === 'true' ? true : undefined;
        case 'email':
            return text === '' ? undefined : text;
        default:
            return text === '' ? undefined : raw;
    }
}

/**
 * @returns {{ok: true, settings: Record<string, unknown>, warnings: string[]} | {ok: false, problems: string[]}}
 */
export function readSettings(env = process.env) {
    const settings = {};
    const problems = [];
    const warnings = [];

    for (const variable of VARIABLES) {
        const raw = env[variable.env];

        if (raw === undefined || raw.trim() === '') {
            if (variable.optional) {
                settings[variable.env] = false;
            } else {
                problems.push(
                    `${variable.env} is not set (${variable.description})`,
                );
            }

            continue;
        }

        const value = parse(variable, raw);

        if (value === undefined) {
            problems.push(
                `${variable.env} is not valid (${variable.description})`,
            );
            continue;
        }

        settings[variable.env] = value;
    }

    const base = settings.DASHFLOW_LOAD_BASE_URL;

    if (typeof base === 'string' && !isLocalHost(new URL(base).hostname)) {
        if (settings.DASHFLOW_LOAD_ALLOW_REMOTE === true) {
            warnings.push(
                `${base} is outside loopback and private networks: allowed by DASHFLOW_LOAD_ALLOW_REMOTE=true. Development use only.`,
            );
        } else {
            problems.push(
                `DASHFLOW_LOAD_BASE_URL points outside loopback and private networks; set DASHFLOW_LOAD_ALLOW_REMOTE=true to allow it (development use only)`,
            );
        }
    }

    return problems.length > 0
        ? { ok: false, problems }
        : { ok: true, settings, warnings };
}
