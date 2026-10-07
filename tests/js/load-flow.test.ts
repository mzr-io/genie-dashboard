// @vitest-environment node
import { createServer } from 'node:http';
import type { IncomingMessage, Server, ServerResponse } from 'node:http';
import type { AddressInfo } from 'node:net';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import {
    CookieJar,
    createPacer,
    runPageFlow,
    signIn,
} from '../../load/page-flow.mjs';

// A local server standing in for the stack: XSRF cookie on GET /login, POST /login needs the matching
// X-XSRF-TOKEN, pages need the session cookie.
const TOKEN = 'tok%3Den';
let server: Server;
let base = '';
const calls: Record<string, number> = {};
const open: ServerResponse[] = [];

function cookies(request: IncomingMessage): Record<string, string> {
    return Object.fromEntries(
        (request.headers.cookie ?? '')
            .split(';')
            .map((pair) => pair.trim().split('='))
            .filter(([name]) => name),
    );
}

beforeAll(async () => {
    server = createServer((request, response) => {
        const path = (request.url ?? '').split('?')[0];
        calls[path] = (calls[path] ?? 0) + 1;
        const jar = cookies(request);

        if (request.method === 'GET' && path === '/login') {
            response.setHeader('Set-Cookie', `XSRF-TOKEN=${TOKEN}; Path=/`);
            response.end('login');

            return;
        }

        if (request.method === 'POST' && path === '/login') {
            let body = '';
            request.on('data', (chunk) => (body += chunk));
            request.on('end', () => {
                const { password } = JSON.parse(body);

                if (
                    request.headers['x-xsrf-token'] !==
                        decodeURIComponent(TOKEN) ||
                    jar['XSRF-TOKEN'] !== TOKEN
                ) {
                    response.statusCode = 419;
                } else if (password === 'wrong') {
                    response.statusCode = 422;
                } else if (password === 'bounce') {
                    response.statusCode = 302;
                    response.setHeader('Location', '/login');
                } else if (password !== 'nosession') {
                    response.setHeader('Set-Cookie', 'app_session=s1; Path=/');
                }

                response.end('{}');
            });

            return;
        }

        if (jar.app_session !== 's1') {
            response.statusCode = 302;
            response.setHeader('Location', '/login');
            response.end();

            return;
        }

        if (path === '/missing') {
            response.statusCode = 404;
            response.end('nope');
        } else if (path === '/expire' && calls[path] > 1) {
            // The session disappears after the preflight.
            response.setHeader('Set-Cookie', 'app_session=; Max-Age=0; Path=/');
            response.statusCode = 302;
            response.setHeader('Location', '/login');
            response.end();
        } else if (path === '/hang-after-first' && calls[path] > 1) {
            open.push(response);
        } else if (path === '/hang') {
            open.push(response);
        } else {
            response.end('ok');
        }
    });
    await new Promise<void>((resolve) =>
        server.listen(0, '127.0.0.1', resolve),
    );
    base = `http://127.0.0.1:${(server.address() as AddressInfo).port}`;
});

afterAll(async () => {
    open.forEach((response) => response.destroy());
    server.closeAllConnections();
    await new Promise((resolve) => server.close(resolve));
});

const credentials = (password = 'right') => ({
    email: 'a@example.test',
    password,
    role: 'admin',
    timeoutMs: 1000,
});

const settings = (over: Record<string, unknown> = {}) => ({
    DASHFLOW_LOAD_BASE_URL: base,
    DASHFLOW_LOAD_EMAIL: 'a@example.test',
    DASHFLOW_LOAD_PASSWORD: 'right',
    DASHFLOW_LOAD_ROLE: 'admin',
    DASHFLOW_LOAD_CONCURRENCY: 2,
    DASHFLOW_LOAD_DURATION_SECONDS: 0.4,
    DASHFLOW_LOAD_RATE_CEILING: 50,
    DASHFLOW_LOAD_P95_TARGET_MS: 500,
    DASHFLOW_LOAD_REQUEST_TIMEOUT_MS: 150,
    ...over,
});

const response = (...cookieLines: string[]) =>
    ({ headers: { getSetCookie: () => cookieLines } }) as unknown as Response;

describe('CookieJar', () => {
    it('stores cookies, replaces them and deletes on Max-Age<=0 or a past Expires', () => {
        const jar = new CookieJar();
        jar.store(response('a=1; Path=/', 'b=2; Max-Age=60', 'c=3'));
        expect(jar.header()).toBe('a=1; b=2; c=3');

        jar.store(
            response(
                'a=9',
                'b=2; Max-Age=0',
                'c=3; Expires=Thu, 01 Jan 1970 00:00:00 GMT',
            ),
        );
        expect(jar.header()).toBe('a=9');
    });

    it('skips pairs without "=" and deletes an emptied cookie', () => {
        const jar = new CookieJar();
        jar.store(response('garbage; Path=/', '=x', 'k=v'));
        expect(jar.names()).toEqual(['k']);

        jar.store(response('k=; Path=/'));
        expect(jar.names()).toEqual([]);
    });
});

describe('signIn', () => {
    it('signs in with the CSRF cookie and keeps a session cookie', async () => {
        const jar = new CookieJar();
        await signIn(base, jar, credentials());
        expect(jar.get('app_session')).toBe('s1');
    });

    it('refuses wrong credentials, a bounce to /login and a missing session cookie', async () => {
        await expect(
            signIn(base, new CookieJar(), credentials('wrong')),
        ).rejects.toThrow('HTTP 422');
        await expect(
            signIn(base, new CookieJar(), credentials('bounce')),
        ).rejects.toThrow('redirected back to /login');
        await expect(
            signIn(base, new CookieJar(), credentials('nosession')),
        ).rejects.toThrow('no session cookie');
    });
});

describe('createPacer', () => {
    it('spaces slots by the rate and refuses a slot at or past the deadline', async () => {
        let clock = 0;
        const slept: number[] = [];
        const pace = createPacer(10, 250, {
            now: () => clock,
            sleep: async (ms: number) => {
                slept.push(ms);
                clock += ms;
            },
        });

        const results = [];
        for (let i = 0; i < 5; i++) {
            results.push(await pace());
        }

        // Slots at 0, 100, 200 start; 300 is past the 250 ms deadline, and stays refused.
        expect(results).toEqual([true, true, true, false, false]);
        expect(slept).toEqual([100, 100]);
    });
});

describe('runPageFlow', () => {
    it('reports successful requests and no errors on a healthy page list', async () => {
        const report = await runPageFlow(settings(), {
            pages: ['/ok', '/ok2'],
        });

        expect(report.errors).toBe(0);
        expect(report.ok_requests).toBeGreaterThan(5);
        expect(report.p95_ms).not.toBeNull();
        expect(report.seconds).toBeLessThan(1.5);
    });

    it('fails before the run naming a page that is not 200 for the role', async () => {
        await expect(
            runPageFlow(settings(), { pages: ['/ok', '/missing'] }),
        ).rejects.toThrow(
            'Misconfigured page list: /missing answered HTTP 404',
        );
    });

    it('fails the preflight of a hung page with a timeout, within the timeout', async () => {
        const began = Date.now();
        await expect(
            runPageFlow(settings(), { pages: ['/hang'] }),
        ).rejects.toThrow('Misconfigured page list: /hang answered a timeout');
        expect(Date.now() - began).toBeLessThan(1500);
    });

    it('counts a session lost mid-run apart from other errors, outside the percentiles', async () => {
        const report = await runPageFlow(settings(), { pages: ['/expire'] });

        expect(report.session_lost).toBeGreaterThan(0);
        expect(report.errors).toBe(report.session_lost);
        expect(report.ok_requests).toBe(0);
        expect(report.p95_ms).toBeNull();
        expect(report.p95_within_target).toBeNull();
    });

    it('keeps timed-out requests out of p50 and p95', async () => {
        const report = await runPageFlow(
            settings({ DASHFLOW_LOAD_CONCURRENCY: 2 }),
            { pages: ['/hang-after-first'] },
        );

        expect(report.errors).toBeGreaterThan(0);
        expect(report.error_p95_ms).toBeGreaterThanOrEqual(140);
        expect(report.ok_requests).toBe(0);
        expect(report.p95_ms).toBeNull();
    });
});
