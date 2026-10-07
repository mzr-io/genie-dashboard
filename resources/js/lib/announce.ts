// The shared announcer (UX-DR-276). It owns every live region and enforces the policy:
//   polite    role="status", aria-live="polite": drawer counts, add/remove, action toasts
//   once      role="status": one message per key until the key is reset (reconnecting, back online)
//   assertive role="alert": error and rollback toasts
//   never     nothing is spoken: routine refreshes, KPI values, chart data
// The regions are created before the first message and the text is set on a later tick, so a
// screen reader that watches for new nodes still sees the change.
export type AnnouncePolicy = 'polite' | 'once' | 'assertive' | 'never';

// Debounce windows of UX-DR-276.
export const DEBOUNCE_COUNT_MS = 500;
export const DEBOUNCE_AGGREGATE_MS = 2000;

type Channel = 'polite' | 'once' | 'assertive';

const regions: Partial<Record<Channel, HTMLElement>> = {};
const spoken = new Set<string>();
const timers = new Map<string, ReturnType<typeof setTimeout>>();

function make(channel: Channel): HTMLElement {
    const node = document.createElement('div');

    node.setAttribute('role', channel === 'assertive' ? 'alert' : 'status');
    node.setAttribute(
        'aria-live',
        channel === 'assertive' ? 'assertive' : 'polite',
    );
    node.setAttribute('aria-atomic', 'true');
    node.setAttribute('data-announcer', channel);
    node.className = 'sr-only';
    document.body.appendChild(node);

    return node;
}

// Creates all three regions together, so they exist before the first message is set.
export function initAnnouncer(): void {
    if (typeof document === 'undefined') {
        return;
    }

    const stale = Object.values(regions).some((node) => !node?.isConnected);

    if (stale) {
        resetAnnouncer();
    }

    if (!regions.polite) {
        regions.polite = make('polite');
        regions.once = make('once');
        regions.assertive = make('assertive');
    }
}

function speak(channel: Channel, message: string): void {
    initAnnouncer();

    const node = regions[channel];

    if (!node) {
        return;
    }

    // Later tick: the node is already in the document when its text changes. Repeating the
    // same text is only re-read when the content differs.
    setTimeout(() => {
        node.textContent =
            node.textContent === message ? `${message} ` : message;
    }, 0);
}

export function announce(
    message: string,
    policy: AnnouncePolicy = 'polite',
    key?: string,
): boolean {
    if (policy === 'never' || typeof document === 'undefined' || !message) {
        return false;
    }

    if (policy === 'once') {
        const id = key ?? message;

        if (spoken.has(id)) {
            return false;
        }

        spoken.add(id);
        speak('once', message);

        return true;
    }

    speak(policy, message);

    return true;
}

// Forget one once-key (or all) so the message can be spoken again.
export function resetOnce(key?: string): void {
    if (key === undefined) {
        spoken.clear();
    } else {
        spoken.delete(key);
    }
}

// Collapses a burst into one message: the last one wins after `ms` of quiet (UX-DR-276).
export function announceDebounced(
    key: string,
    message: string,
    ms: number = DEBOUNCE_COUNT_MS,
    policy: AnnouncePolicy = 'polite',
): void {
    const pending = timers.get(key);

    if (pending !== undefined) {
        clearTimeout(pending);
    }

    timers.set(
        key,
        setTimeout(() => {
            timers.delete(key);
            announce(message, policy);
        }, ms),
    );
}

export function announceAggregated(key: string, message: string): void {
    announceDebounced(key, message, DEBOUNCE_AGGREGATE_MS);
}

export function resetAnnouncer(): void {
    for (const timer of timers.values()) {
        clearTimeout(timer);
    }

    timers.clear();
    spoken.clear();

    for (const channel of ['polite', 'once', 'assertive'] as const) {
        regions[channel]?.remove();
        delete regions[channel];
    }
}
