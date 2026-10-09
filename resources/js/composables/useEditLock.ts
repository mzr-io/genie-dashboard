import { computed, onBeforeUnmount, onMounted, reactive } from 'vue';
import type {
    EditLockClient,
    Granted,
    LockAnswer,
    LockHolder,
} from '@/lib/editLock';
import { formatTime } from '@/lib/formatDate';
import type { EditLockLabelSet } from '@/locales/labels';
import { useI18n } from 'vue-i18n';

// The soft lock's client state machine (Story 2.8, shared with the Draft wizard in Story 3.6). The server decides who holds the lock; this
// only asks and shows: acquire, heartbeat, read-only with "Take over", the holder's flush, the taken-over notice and the release on leave.
//
// `off`: no lock (the soft lock is disabled, or the resource is new). `pending`: no first answer yet. `unavailable`: the lock could not be
// asked (read-only, with Retry). `holding`: this tab holds it. `blocked`: someone else does. `waiting`: a take-over was requested.
// `ended`: this tab held it and lost it.
export type LockMode =
    | 'off'
    | 'pending'
    | 'unavailable'
    | 'holding'
    | 'blocked'
    | 'waiting'
    | 'ended';
export type TakenOver = Extract<LockAnswer, { status: 'taken_over' }>;

export type EditLockOptions = {
    client: EditLockClient;
    // The locked resource's id; null or undefined while there is nothing to lock yet.
    id: () => string | null | undefined;
    labels: EditLockLabelSet;
    // True when the first answer is expected (the resource is opened by id), so the page is read-only until it comes.
    expectLock: () => boolean;
    // A failed call that ended the session redirects to sign-in and returns true.
    authExpired: (error: unknown) => boolean;
    // Loads the resource again after a take-over (the holder's flush is in it).
    // False when it failed: the lock is then not taken (the caller shows its own failure and retry).
    reload: () => Promise<boolean | void>;
    // Takes the lock again after a page restored from the back-forward cache.
    restart: () => Promise<void>;
    // The holder's flush when a take-over was requested: save what is valid and not secret. True when it is safe to acknowledge.
    flush: () => Promise<boolean>;
    // False while a save is in flight or the resource is not loaded: the flush waits for the next heartbeat.
    canFlush?: () => boolean;
    // The lock was lost: clear what must not be kept (a typed secret).
    onEnded?: () => void;
    // The take-over completed and the resource was reloaded.
    onReloaded?: () => void;
    // With true, a heartbeat is only sent while the person has been active within the TTL, so an idle holder lets the lock lapse.
    activityGated?: boolean;
};

const LOCK_POLL_MS = 1000;
const ACTIVITY_EVENTS = ['keydown', 'pointerdown', 'input', 'change'] as const;

export function useEditLock(options: EditLockOptions) {
    const { t } = useI18n();
    const lock = reactive({
        mode: (options.expectLock() ? 'pending' : 'off') as LockMode,
        token: null as string | null,
        epoch: null as number | null,
        csrf: null as string | null,
        holder: null as LockHolder | null,
        // Who took the lock over, when, and whether this tab's flush was confirmed first; null when it was simply lost.
        ended: null as {
            by: string | null;
            at: string | null;
            saved: boolean;
        } | null,
        takeoverFailed: false,
    });
    const readOnly = computed(
        () =>
            options.id() !== null &&
            options.id() !== undefined &&
            lock.mode !== 'off' &&
            lock.mode !== 'holding',
    );

    let heartbeatTimer: ReturnType<typeof setInterval> | null = null;
    let pollTimer: ReturnType<typeof setInterval> | null = null;
    let takerToken: string | null = null;
    let beating = false;
    let polling = false;
    let flushing = false;
    let ttlMs = 0;
    let lastActivity = 0;
    let suspended = false;

    const idOf = (): string => options.id() as string;

    function stopTimers(): void {
        if (heartbeatTimer) {
            clearInterval(heartbeatTimer);
            heartbeatTimer = null;
        }

        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    // What a save says about the lock; the server checks it against the lock itself and never trusts it.
    function claim(): { epoch: number; token: string } | null {
        return lock.mode === 'holding' && lock.token && lock.epoch !== null
            ? { epoch: lock.epoch, token: lock.token }
            : null;
    }

    async function acquire(): Promise<void> {
        try {
            applyHeld(await options.client.acquire(idOf()));
        } catch (error) {
            if (!options.authExpired(error)) {
                lock.mode = 'unavailable';
            }
        }
    }

    // The lock could not be asked: read-only until it can (the server would refuse a save without it anyway).
    async function retryAcquire(): Promise<void> {
        lock.mode = 'pending';
        await acquire();
    }

    function applyHeld(answer: LockAnswer): void {
        if (!answer.enabled) {
            stopTimers();
            lock.mode = 'off';

            return;
        }

        if (answer.status === 'granted') {
            hold(answer);
        } else if (answer.status === 'held') {
            lock.holder = answer.holder;
            lock.mode = 'blocked';
        } else {
            lock.mode = 'unavailable';
        }
    }

    function hold(answer: Granted): void {
        stopTimers();
        lock.mode = 'holding';
        lock.token = answer.token;
        lock.epoch = answer.epoch;
        lock.csrf = answer.csrf_token ?? lock.csrf;
        lock.holder = null;
        lock.ended = null;
        suspended = false;
        lastActivity = Date.now();
        // A heartbeat three times within the TTL, so one missed call does not lose the lock.
        ttlMs = answer.ttl_seconds * 1000;
        heartbeatTimer = setInterval(
            () => void tick(),
            Math.max(1, Math.floor(ttlMs / 3)),
        );
    }

    // With the activity gate, an idle holder stops renewing: past the TTL the lock lapses, as for a closed tab.
    async function tick(): Promise<void> {
        if (options.activityGated && Date.now() - lastActivity >= ttlMs) {
            suspended = true;

            return;
        }

        await heartbeat();
    }

    // Activity after a lapse takes the lock again: it is free unless someone else took it, and the epoch did not move.
    async function onActivity(): Promise<void> {
        lastActivity = Date.now();

        if (!suspended || lock.mode !== 'holding') {
            return;
        }

        suspended = false;
        const token = lock.token;

        try {
            const answer = await options.client.heartbeat(
                idOf(),
                token as string,
            );

            if (lock.token !== token) {
                return;
            }

            if (answer.enabled && answer.status === 'lost') {
                applyHeld(await options.client.acquire(idOf()));

                if ((lock.mode as LockMode) === 'blocked') {
                    endLock(null);
                }
            } else {
                await handleBeat(answer);
            }
        } catch (error) {
            options.authExpired(error);
        }
    }

    async function heartbeat(): Promise<void> {
        const token = lock.token;

        if (!token || beating) {
            return;
        }

        beating = true;

        try {
            const answer = await options.client.heartbeat(idOf(), token);

            if (lock.token !== token) {
                return;
            }

            await handleBeat(answer);
        } catch (error) {
            // A failed call is not a verdict: the next tick asks again.
            options.authExpired(error);
        } finally {
            beating = false;
        }
    }

    async function handleBeat(answer: LockAnswer): Promise<void> {
        if (!answer.enabled) {
            stopTimers();
            lock.mode = 'off';
        } else if (answer.status === 'taken_over') {
            endLock(answer);
        } else if (answer.status === 'lost') {
            endLock(null);
        } else if (answer.status === 'granted' && answer.flush_requested) {
            await flush();
        }
    }

    // The lock is gone: the page is read-only with the notice.
    function endLock(answer: TakenOver | null): void {
        stopTimers();
        lock.token = null;
        lock.epoch = null;
        lock.mode = 'ended';
        lock.ended = answer
            ? {
                  by: answer.taken_over_by?.name ?? null,
                  at: answer.taken_over_by?.at ?? null,
                  saved: answer.flush_acknowledged,
              }
            : null;
        options.onEnded?.();
    }

    // Someone asked to take over: save what is valid and not secret, then say so. Nothing is acknowledged unless it was saved, so a
    // failed save leaves the taker to wait for the flush timeout rather than lose this tab's work silently.
    async function flush(): Promise<void> {
        const token = lock.token;

        if (flushing || !token || options.canFlush?.() === false) {
            return;
        }

        flushing = true;

        try {
            if (!(await options.flush())) {
                return;
            }

            const answer = await options.client.acknowledgeFlush(idOf(), token);

            if (answer.enabled && answer.status === 'taken_over') {
                endLock(answer);
            } else if (answer.enabled && answer.status === 'lost') {
                endLock(null);
            }
        } catch (error) {
            options.authExpired(error);
        } finally {
            flushing = false;
        }
    }

    async function takeOver(): Promise<void> {
        if (lock.mode !== 'blocked') {
            return;
        }

        lock.takeoverFailed = false;
        lock.mode = 'waiting';

        try {
            const answer = await options.client.requestTakeover(idOf());

            if (!answer.enabled) {
                lock.mode = 'off';
                await options.reload();
            } else if (answer.status === 'granted') {
                await becomeHolder(answer);
            } else if (answer.status === 'waiting') {
                takerToken = answer.token;
                pollTimer = setInterval(() => void poll(), LOCK_POLL_MS);
            } else {
                applyHeld(answer);
                lock.mode = 'blocked';
            }
        } catch (error) {
            if (options.authExpired(error)) {
                return;
            }

            lock.mode = 'blocked';
            lock.takeoverFailed = true;
        }
    }

    async function poll(): Promise<void> {
        const token = takerToken;

        if (!token || polling) {
            return;
        }

        polling = true;

        try {
            const answer = await options.client.pollTakeover(idOf(), token);

            if (takerToken !== token) {
                return;
            }

            if (!answer.enabled) {
                stopTimers();
                takerToken = null;
                lock.mode = 'off';
                await options.reload();
            } else if (answer.status === 'granted') {
                await becomeHolder(answer);
            } else if (answer.status === 'held') {
                stopTimers();
                takerToken = null;
                applyHeld(answer);
            } else if (answer.status === 'free') {
                // The holder let go: a poll never grants, so take the lock the ordinary way.
                stopTimers();
                takerToken = null;

                const fresh = await options.client.acquire(idOf());

                if (fresh.enabled && fresh.status === 'granted') {
                    await becomeHolder(fresh);
                } else {
                    applyHeld(fresh);
                }
            }
        } catch (error) {
            // A failed poll is asked again; only an ended session stops it.
            options.authExpired(error);
        } finally {
            polling = false;
        }
    }

    // The take-over completed: reload the resource (the holder's flush is in it) and edit.
    async function becomeHolder(answer: Granted): Promise<void> {
        stopTimers();
        takerToken = null;

        if ((await options.reload()) === false) {
            // Never edit on stale data: hand the lock back and let the page's own failure and retry take over.
            options.client.release(
                idOf(),
                answer.token,
                answer.csrf_token ?? null,
            );
            lock.mode = 'unavailable';

            return;
        }

        hold(answer);
        options.onReloaded?.();
    }

    // A page restored from the back-forward cache has no lock (it was released on pagehide): take it again.
    function onPageShow(event: PageTransitionEvent): void {
        if (event.persisted && options.expectLock()) {
            stopTimers();
            lock.token = null;
            lock.epoch = null;
            lock.mode = 'pending';
            void options.restart();
        }
    }

    // Hidden tabs throttle timers, so a short TTL can pass unnoticed: ask at once when the tab is seen again.
    function onVisible(): void {
        if (document.visibilityState === 'visible' && lock.mode === 'holding') {
            void tick();
        }
    }

    // Closing the tab or leaving the page frees the lock (or withdraws a pending take-over) by beacon.
    function releaseNow(): void {
        const id = options.id();

        if (!id) {
            return;
        }

        const token = lock.mode === 'holding' ? lock.token : takerToken;

        if (token) {
            options.client.release(id, token, lock.csrf);

            if (lock.mode === 'holding') {
                lock.token = null;
            }

            takerToken = null;
        }
    }

    const notice = computed((): string | null => {
        if (lock.mode === 'blocked' && lock.holder) {
            return t('draft-locked', {
                user: lock.holder.name,
                time: formatTime(lock.holder.since),
            });
        }

        if (lock.mode === 'unavailable') {
            return options.labels.unavailable;
        }

        if (lock.mode === 'waiting') {
            return options.labels.waiting(
                lock.holder?.name ?? options.labels.someone,
            );
        }

        if (lock.mode === 'ended') {
            const ended = lock.ended;

            if (!ended) {
                return options.labels.lost;
            }

            const user = ended.by ?? options.labels.someone;
            const time = ended.at ? formatTime(ended.at) : '';

            return ended.saved
                ? t('draft-taken-over', { user, time })
                : options.labels.takenOverUnsaved(user, time);
        }

        return null;
    });

    onMounted(() => {
        window.addEventListener('pagehide', releaseNow);
        window.addEventListener('pageshow', onPageShow);
        document.addEventListener('visibilitychange', onVisible);

        if (options.activityGated) {
            for (const name of ACTIVITY_EVENTS) {
                document.addEventListener(name, onActivity, true);
            }
        }
    });

    onBeforeUnmount(() => {
        window.removeEventListener('pagehide', releaseNow);
        window.removeEventListener('pageshow', onPageShow);
        document.removeEventListener('visibilitychange', onVisible);

        for (const name of ACTIVITY_EVENTS) {
            document.removeEventListener(name, onActivity, true);
        }

        stopTimers();
        releaseNow();
    });

    return {
        lock,
        readOnly,
        notice,
        claim,
        acquire,
        retryAcquire,
        takeOver,
        endLock,
        stopTimers,
    };
}
