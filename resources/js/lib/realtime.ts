import { xsrfToken } from '@/lib/session';

// A small client of the Reverb (Pusher protocol) socket for the dashboard (Story 4.13; AD-16). It subscribes to private channels, authorises each
// one against `/broadcasting/auth` (which re-checks the database), answers pings, and reconnects with backoff, resubscribing what it had. What
// arrives is only an event name and IDs: the page refetches over `/api/v1`. It never throws into the page: a socket that cannot connect is
// reported through `onState` and the page falls back to polling.
export type RealtimeConfig = {
    key: string;
    host: string;
    port: number;
    scheme: string;
};

export type RealtimeState = 'connecting' | 'connected' | 'disconnected';

type Handler = (event: string, data: unknown) => void;

export const AUTH_URL = '/broadcasting/auth';

export class Realtime {
    private socket: WebSocket | null = null;
    private socketId: string | null = null;
    private readonly channels = new Map<string, Handler>();
    private readonly denied = new Set<string>();
    private attempts = 0;
    private timer: ReturnType<typeof setTimeout> | null = null;
    private closed = false;
    state: RealtimeState = 'disconnected';

    constructor(
        private readonly config: RealtimeConfig,
        private readonly onState: (state: RealtimeState) => void = () => {},
        private readonly onDenied: (channel: string) => void = () => {},
        private readonly make: (url: string) => WebSocket = (url) =>
            new WebSocket(url),
    ) {}

    connect(): void {
        if (this.closed || this.socket !== null) {
            return;
        }

        this.set('connecting');

        const scheme = this.config.scheme === 'http' ? 'ws' : 'wss';
        const url = `${scheme}://${this.config.host}:${this.config.port}/app/${encodeURIComponent(this.config.key)}?protocol=7&client=dashflow&version=1`;

        try {
            this.socket = this.make(url);
        } catch {
            this.lost();

            return;
        }

        this.socket.onmessage = (message) => this.receive(String(message.data));
        this.socket.onclose = () => this.lost();
        this.socket.onerror = () => this.socket?.close();
    }

    // Private channel `workspace.x.block.y` is `private-workspace.x.block.y` on the wire.
    subscribe(channel: string, handler: Handler): void {
        const name = `private-${channel}`;

        this.channels.set(name, handler);

        if (this.state === 'connected') {
            void this.join(name);
        }
    }

    unsubscribe(channel: string): void {
        const name = `private-${channel}`;

        this.channels.delete(name);
        this.denied.delete(name);

        if (this.state === 'connected') {
            this.send({ event: 'pusher:unsubscribe', data: { channel: name } });
        }
    }

    close(): void {
        this.closed = true;

        if (this.timer !== null) {
            clearTimeout(this.timer);
            this.timer = null;
        }

        this.socket?.close();
        this.socket = null;
        this.set('disconnected');
    }

    private set(state: RealtimeState): void {
        if (this.state !== state) {
            this.state = state;
            this.onState(state);
        }
    }

    private send(payload: unknown): void {
        if (this.socket?.readyState === 1) {
            this.socket.send(JSON.stringify(payload));
        }
    }

    private receive(raw: string): void {
        let frame: { event?: string; channel?: string; data?: unknown };

        try {
            frame = JSON.parse(raw) as typeof frame;
        } catch {
            return;
        }

        switch (frame.event) {
            case 'pusher:connection_established': {
                const data = this.parse(frame.data) as { socket_id?: string };

                this.socketId = data.socket_id ?? null;
                this.attempts = 0;
                this.set('connected');
                this.channels.forEach((_handler, name) => void this.join(name));
                break;
            }
            case 'pusher:ping':
                this.send({ event: 'pusher:pong', data: {} });
                break;
            case 'pusher:error':
            case 'pusher_internal:subscription_succeeded':
                break;
            default:
                if (frame.channel !== undefined && frame.event !== undefined) {
                    this.channels.get(frame.channel)?.(
                        frame.event,
                        this.parse(frame.data),
                    );
                }
        }
    }

    private parse(data: unknown): unknown {
        if (typeof data !== 'string') {
            return data ?? {};
        }

        try {
            return JSON.parse(data);
        } catch {
            return {};
        }
    }

    private async join(name: string): Promise<void> {
        if (this.socketId === null || this.denied.has(name)) {
            return;
        }

        try {
            const response = await fetch(AUTH_URL, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                    // Authorising a channel is not the person using the app: it must not extend the idle session.
                    'X-Background': '1',
                },
                body: JSON.stringify({
                    socket_id: this.socketId,
                    channel_name: name,
                }),
            });

            if (response.status === 403) {
                this.denied.add(name);
                this.onDenied(name.replace(/^private-/, ''));

                return;
            }

            if (!response.ok) {
                return;
            }

            const body = (await response.json()) as { auth?: string };

            if (typeof body.auth === 'string') {
                this.send({
                    event: 'pusher:subscribe',
                    data: { channel: name, auth: body.auth },
                });
            }
        } catch {
            // The next reconnect tries again.
        }
    }

    private lost(): void {
        this.socket = null;
        this.socketId = null;
        this.set('disconnected');

        if (this.closed) {
            return;
        }

        const wait = Math.min(30_000, 1000 * 2 ** Math.min(this.attempts, 5));

        this.attempts += 1;
        this.timer = setTimeout(() => {
            this.timer = null;
            this.connect();
        }, wait);
    }
}
