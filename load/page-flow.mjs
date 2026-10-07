// The scripted flow: sign in through the real sign-in page with the CSRF cookie, then request the
// authenticated pages of the chosen area from `concurrency` virtual users until the duration ends.
// All virtual users share the one signed-in session (the sign-in throttle would refuse many sign-ins
// of one account), and the harness never sends more than the request-rate ceiling in total.
import { summarise } from './stats.mjs';

export const PAGES = {
    user: [
        '/dashboard',
        '/dashboards',
        '/templates',
        '/settings/profile',
        '/help',
    ],
    admin: [
        '/admin',
        '/admin/users',
        '/admin/users/groups',
        '/settings/profile',
        '/help',
    ],
};

export class CookieJar {
    #cookies = new Map();

    store(response) {
        for (const line of response.headers.getSetCookie()) {
            const [pair, ...attributes] = line.split(';');
            const index = pair.indexOf('=');

            if (index < 1) {
                continue;
            }

            const name = pair.slice(0, index).trim();
            const value = pair.slice(index + 1).trim();
            let expired = value === '';

            for (const attribute of attributes) {
                const [key, ...rest] = attribute.split('=');
                const setting = rest.join('=').trim();

                if (key.trim().toLowerCase() === 'max-age') {
                    expired ||= !(Number(setting) > 0);
                } else if (key.trim().toLowerCase() === 'expires') {
                    const when = Date.parse(setting);
                    expired ||= !Number.isNaN(when) && when <= Date.now();
                }
            }

            if (expired) {
                this.#cookies.delete(name);
            } else {
                this.#cookies.set(name, value);
            }
        }
    }

    header() {
        return [...this.#cookies]
            .map(([name, value]) => `${name}=${value}`)
            .join('; ');
    }

    get(name) {
        return this.#cookies.get(name);
    }

    names() {
        return [...this.#cookies.keys()];
    }
}

async function drain(response) {
    await response.arrayBuffer();
}

const locationIsLogin = (response, base) => {
    const location = response.headers.get('location');

    if (location === null) {
        return false;
    }

    try {
        return new URL(location, base).pathname === '/login';
    } catch {
        return false;
    }
};

export async function signIn(base, jar, { email, password, role, timeoutMs }) {
    const page = await fetch(`${base}/login`, {
        redirect: 'manual',
        signal: AbortSignal.timeout(timeoutMs),
        headers: { Accept: 'text/html', Cookie: jar.header() },
    });
    jar.store(page);
    await drain(page);

    const token = jar.get('XSRF-TOKEN');

    if (page.status !== 200 || token === undefined) {
        throw new Error(
            `The sign-in page answered ${page.status} with no CSRF cookie.`,
        );
    }

    const response = await fetch(`${base}/login`, {
        method: 'POST',
        redirect: 'manual',
        signal: AbortSignal.timeout(timeoutMs),
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            Cookie: jar.header(),
            Origin: base,
            Referer: `${base}/login`,
            'X-XSRF-TOKEN': decodeURIComponent(token),
        },
        body: JSON.stringify({ email, password, role }),
    });
    jar.store(response);
    await drain(response);

    if (response.status >= 400) {
        throw new Error(
            `Sign in as ${role} was refused with HTTP ${response.status}.`,
        );
    }

    if (locationIsLogin(response, base)) {
        throw new Error(
            `Sign in as ${role} redirected back to /login: no session was established.`,
        );
    }

    if (jar.names().every((name) => name === 'XSRF-TOKEN')) {
        throw new Error(
            `Sign in as ${role} answered HTTP ${response.status} but set no session cookie.`,
        );
    }
}

/**
 * Spaces requests so the shared rate never exceeds `perSecond`. The returned function resolves to false,
 * without reserving a slot, when the next slot would start at or after `deadline`.
 */
export function createPacer(
    perSecond,
    deadline = Infinity,
    {
        now = () => performance.now(),
        sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms)),
    } = {},
) {
    const gap = 1000 / perSecond;
    let next = now();

    return async () => {
        const current = now();
        const slot = Math.max(current, next);

        if (slot >= deadline) {
            return false;
        }

        next = slot + gap;

        if (slot > current) {
            await sleep(slot - current);
        }

        return true;
    };
}

async function request(base, path, jar, timeoutMs) {
    const begin = performance.now();

    try {
        const response = await fetch(`${base}${path}`, {
            redirect: 'manual',
            signal: AbortSignal.timeout(timeoutMs),
            headers: { Accept: 'text/html', Cookie: jar.header() },
        });
        jar.store(response);
        await drain(response);

        return {
            status: response.status,
            lost: locationIsLogin(response, base),
            ms: performance.now() - begin,
        };
    } catch (error) {
        return {
            status: 0,
            lost: false,
            ms: performance.now() - begin,
            failure:
                error instanceof Error && error.name === 'TimeoutError'
                    ? 'timeout'
                    : 'network',
        };
    }
}

export async function runPageFlow(settings, { pages } = {}) {
    const base = settings.DASHFLOW_LOAD_BASE_URL;
    const role = settings.DASHFLOW_LOAD_ROLE;
    const timeoutMs = settings.DASHFLOW_LOAD_REQUEST_TIMEOUT_MS;
    const jar = new CookieJar();
    const list = pages ?? PAGES[role];

    await signIn(base, jar, {
        email: settings.DASHFLOW_LOAD_EMAIL,
        password: settings.DASHFLOW_LOAD_PASSWORD,
        role,
        timeoutMs,
    });

    // Every page must answer 200 for this role before the clock starts; a wrong list is a setup error,
    // not a load result.
    for (const path of list) {
        const probe = await request(base, path, jar, timeoutMs);

        if (probe.status !== 200) {
            const answer = probe.lost
                ? 'a redirect to /login'
                : probe.failure
                  ? `a ${probe.failure}`
                  : `HTTP ${probe.status}`;

            throw new Error(
                `Misconfigured page list: ${path} answered ${answer} for role ${role}.`,
            );
        }
    }

    const pace = createPacer(
        settings.DASHFLOW_LOAD_RATE_CEILING,
        performance.now() + settings.DASHFLOW_LOAD_DURATION_SECONDS * 1000,
    );
    const latencies = [];
    const errorLatencies = [];
    let sessionLost = 0;
    const started = performance.now();

    async function user(index) {
        for (let step = index; await pace(); step++) {
            const result = await request(
                base,
                list[step % list.length],
                jar,
                timeoutMs,
            );

            if (result.status === 200) {
                latencies.push(result.ms);
            } else {
                errorLatencies.push(result.ms);

                if (result.lost) {
                    sessionLost++;
                }
            }
        }
    }

    await Promise.all(
        Array.from({ length: settings.DASHFLOW_LOAD_CONCURRENCY }, (_, index) =>
            user(index),
        ),
    );

    return {
        scenario: 'page-flow',
        role,
        concurrency: settings.DASHFLOW_LOAD_CONCURRENCY,
        rate_ceiling: settings.DASHFLOW_LOAD_RATE_CEILING,
        pages: list,
        ...summarise({
            latencies,
            errorLatencies,
            sessionLost,
            seconds: (performance.now() - started) / 1000,
            p95TargetMs: settings.DASHFLOW_LOAD_P95_TARGET_MS,
        }),
    };
}
