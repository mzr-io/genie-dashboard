// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Realtime } from '../../resources/js/lib/realtime';

// Story 4.13: the small Pusher-protocol client of the Reverb socket. It authorises each private channel against the server, answers pings, reports
// state, reconnects with backoff and resubscribes, and treats a 403 as a refused channel.
class FakeSocket {
    static last: FakeSocket | null = null;
    readyState = 1;
    sent: Array<{ event: string; data: Record<string, unknown> }> = [];
    onmessage: ((m: { data: string }) => void) | null = null;
    onclose: (() => void) | null = null;
    onerror: (() => void) | null = null;

    constructor(public url: string) {
        FakeSocket.last = this;
    }

    send(raw: string): void {
        this.sent.push(JSON.parse(raw));
    }

    close(): void {
        this.onclose?.();
    }

    emit(frame: Record<string, unknown>): void {
        this.onmessage?.({ data: JSON.stringify(frame) });
    }
}

const config = {
    key: 'app-key',
    host: 'rt.example.test',
    port: 8443,
    scheme: 'https',
};
const make = (url: string) => new FakeSocket(url) as unknown as WebSocket;

beforeEach(() => {
    FakeSocket.last = null;
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => ({
            ok: true,
            status: 200,
            json: async () => ({ auth: 'app-key:signature' }),
        })),
    );
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('Realtime', () => {
    it('connects to the app on the configured host and reports its state', () => {
        const states: string[] = [];
        const client = new Realtime(
            config,
            (s) => states.push(s),
            () => {},
            make,
        );

        client.connect();
        FakeSocket.last!.emit({
            event: 'pusher:connection_established',
            data: JSON.stringify({ socket_id: '1.2' }),
        });

        expect(FakeSocket.last!.url).toBe(
            'wss://rt.example.test:8443/app/app-key?protocol=7&client=dashflow&version=1',
        );
        expect(states).toEqual(['connecting', 'connected']);
    });

    it('authorises a private channel with the socket id, then subscribes with the signature', async () => {
        const client = new Realtime(
            config,
            () => {},
            () => {},
            make,
        );

        client.subscribe('workspace.w.block.b', () => {});
        client.connect();
        FakeSocket.last!.emit({
            event: 'pusher:connection_established',
            data: JSON.stringify({ socket_id: '1.2' }),
        });
        await vi.advanceTimersByTimeAsync(0);

        const call = (
            fetch as unknown as {
                mock: {
                    calls: Array<
                        [
                            string,
                            { body: string; headers: Record<string, string> },
                        ]
                    >;
                };
            }
        ).mock.calls[0];

        expect(call[0]).toBe('/broadcasting/auth');
        expect(JSON.parse(call[1].body)).toEqual({
            socket_id: '1.2',
            channel_name: 'private-workspace.w.block.b',
        });
        expect(call[1].headers['X-Background']).toBe('1');
        expect(FakeSocket.last!.sent).toContainEqual({
            event: 'pusher:subscribe',
            data: {
                channel: 'private-workspace.w.block.b',
                auth: 'app-key:signature',
            },
        });
    });

    it('hands an event to its channel, answers pings and ignores other channels', () => {
        const got: unknown[] = [];
        const client = new Realtime(
            config,
            () => {},
            () => {},
            make,
        );

        client.subscribe('workspace.w.block.b', (event, data) =>
            got.push([event, data]),
        );
        client.connect();
        FakeSocket.last!.emit({
            event: 'pusher:connection_established',
            data: JSON.stringify({ socket_id: '1.2' }),
        });
        FakeSocket.last!.emit({
            event: 'result.updated',
            channel: 'private-workspace.w.block.b',
            data: JSON.stringify({ block_id: 'b', result_etag: 'e' }),
        });
        FakeSocket.last!.emit({
            event: 'result.updated',
            channel: 'private-workspace.w.block.other',
            data: '{}',
        });
        FakeSocket.last!.emit({ event: 'pusher:ping', data: '{}' });

        expect(got).toEqual([
            ['result.updated', { block_id: 'b', result_etag: 'e' }],
        ]);
        expect(FakeSocket.last!.sent).toContainEqual({
            event: 'pusher:pong',
            data: {},
        });
    });

    it('reports a refused channel and does not retry it', async () => {
        (fetch as unknown as ReturnType<typeof vi.fn>).mockResolvedValue({
            ok: false,
            status: 403,
            json: async () => ({}),
        });
        const denied: string[] = [];
        const client = new Realtime(
            config,
            () => {},
            (c) => denied.push(c),
            make,
        );

        client.subscribe('workspace.w.block.b', () => {});
        client.connect();
        FakeSocket.last!.emit({
            event: 'pusher:connection_established',
            data: JSON.stringify({ socket_id: '1.2' }),
        });
        await vi.advanceTimersByTimeAsync(0);

        expect(denied).toEqual(['workspace.w.block.b']);
        expect(
            FakeSocket.last!.sent.filter((f) => f.event === 'pusher:subscribe'),
        ).toEqual([]);
    });

    it('reconnects with a growing wait, resubscribes after it, and stops after close()', async () => {
        const states: string[] = [];
        const client = new Realtime(
            config,
            (s) => states.push(s),
            () => {},
            make,
        );

        client.subscribe('workspace.w.block.b', () => {});
        client.connect();
        const first = FakeSocket.last!;
        first.emit({
            event: 'pusher:connection_established',
            data: JSON.stringify({ socket_id: '1.2' }),
        });
        first.close();

        expect(states.at(-1)).toBe('disconnected');

        await vi.advanceTimersByTimeAsync(1000);

        expect(FakeSocket.last).not.toBe(first);

        FakeSocket.last!.emit({
            event: 'pusher:connection_established',
            data: JSON.stringify({ socket_id: '3.4' }),
        });
        await vi.advanceTimersByTimeAsync(0);

        expect(states.at(-1)).toBe('connected');
        expect(
            FakeSocket.last!.sent.some((f) => f.event === 'pusher:subscribe'),
        ).toBe(true);

        const second = FakeSocket.last!;
        client.close();
        await vi.advanceTimersByTimeAsync(60_000);

        expect(FakeSocket.last).toBe(second);
    });

    it('survives a socket that cannot be created', () => {
        const states: string[] = [];
        const client = new Realtime(
            config,
            (s) => states.push(s),
            () => {},
            () => {
                throw new Error('blocked');
            },
        );

        client.connect();

        expect(states.at(-1)).toBe('disconnected');
    });
});
