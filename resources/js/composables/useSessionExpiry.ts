import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { announce, resetOnce } from '@/lib/announce';
import { clearFormDrafts, saveFormDrafts } from '@/lib/formDrafts';
import {
    SIGN_IN_URL,
    SessionEnded,
    extendSession,
    fetchSessionStatus,
} from '@/lib/session';
import type { SessionStatus } from '@/lib/session';

// The warning opens two minutes before the idle limit (UX-DR-248); the countdown is announced at
// 2:00, 1:00 and 0:30 (UX-DR-249).
export const WARN_SECONDS = 120;
export const ANNOUNCE_MARKS = [120, 60, 30] as const;
// Low-rate background poll; the status call never extends the session.
export const POLL_MS = 60_000;
// Retry delays when the confirming call at zero cannot reach the server.
export const RETRY_BASE_MS = 1000;
export const RETRY_MAX_MS = 15_000;
// A mark is spoken by its own label when the tick is this close to it; later joins hear the real time.
const MARK_TOLERANCE_S = 5;

export function formatCountdown(seconds: number): string {
    const whole = Math.max(0, Math.floor(seconds));

    return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, '0')}`;
}

type Options = {
    // Receives the announce text for a time; the dialog supplies the catalogue message.
    message: (time: string) => string;
    // Where the person goes once the session is over.
    leave?: () => void;
};

export function useSessionExpiry(options: Options) {
    const deadline = ref<number | null>(null);
    const now = ref(Date.now());
    const open = ref(false);
    const error = ref(false);
    const extending = ref(false);
    let ticker: ReturnType<typeof setInterval> | null = null;
    let poller: ReturnType<typeof setInterval> | null = null;
    let retry: ReturnType<typeof setTimeout> | null = null;
    let confirming = false;
    let ended = false;
    let signingOut = false;
    let unmounted = false;
    // Bumped by every call that decides the deadline: an older response never overwrites a newer one.
    let sequence = 0;
    // The idle limit, known exactly only from an extend answer (the whole limit); 0 until then.
    let knownLimit = 0;
    const announced = new Set<number>();
    let offs: Array<() => void> = [];

    const remaining = computed(() =>
        deadline.value === null
            ? null
            : Math.max(0, Math.ceil((deadline.value - now.value) / 1000)),
    );
    const time = computed(() => formatCountdown(remaining.value ?? 0));

    // With a limit of two minutes or less the window would cover the whole session.
    function warnWindow(): number {
        return knownLimit > 0 && knownLimit <= WARN_SECONDS
            ? Math.max(0, knownLimit - 1)
            : WARN_SECONDS;
    }

    function resetAnnouncements(): void {
        announced.clear();
        ANNOUNCE_MARKS.forEach((mark) => resetOnce(`session-warning-${mark}`));
    }

    function stopTicker(): void {
        if (ticker) {
            clearInterval(ticker);
            ticker = null;
        }
    }

    function startTicker(): void {
        stopTicker();
        ticker = setInterval(() => {
            now.value = Date.now();
            evaluate();
        }, 1000);
    }

    function leave(): void {
        if (options.leave) {
            options.leave();
        } else {
            window.location.assign(SIGN_IN_URL);
        }
    }

    // The session is over: keep what registered forms hold (not after a deliberate sign-out), then go to
    // sign-in. The server already stored the page the person was on.
    function end(): void {
        if (ended) {
            return;
        }

        ended = true;
        open.value = false;
        stopTicker();

        if (!signingOut) {
            saveFormDrafts();
        }

        leave();
    }

    function apply(status: SessionStatus): void {
        deadline.value = Date.now() + status.remaining_seconds * 1000;
        now.value = Date.now();
        evaluate();
    }

    async function sync(): Promise<void> {
        const mine = ++sequence;

        try {
            const status = await fetchSessionStatus();

            // An extend (or a newer sync) decided the deadline while this call was in flight.
            if (mine === sequence) {
                apply(status);
            }
        } catch (e) {
            if (e instanceof SessionEnded) {
                end();
            }
            // Offline or a server error: keep the last known deadline.
        }
    }

    // At zero another tab may have extended the session: ask the server. Only a 401 or a confirmed zero
    // ends the session; a failed call is retried with a growing delay.
    async function confirmEnd(): Promise<void> {
        confirming = true;
        let attempt = 0;

        try {
            while (!ended && !unmounted && (remaining.value ?? 1) <= 0) {
                const mine = ++sequence;

                try {
                    const status = await fetchSessionStatus();

                    if (mine !== sequence) {
                        return;
                    }

                    if (status.remaining_seconds <= 0) {
                        end();
                    } else {
                        apply(status);
                    }

                    return;
                } catch (e) {
                    if (e instanceof SessionEnded) {
                        end();

                        return;
                    }

                    const delay = Math.min(
                        RETRY_BASE_MS * 2 ** attempt,
                        RETRY_MAX_MS,
                    );

                    attempt += 1;
                    await new Promise<void>((resolve) => {
                        retry = setTimeout(resolve, delay);
                    });
                }
            }
        } finally {
            confirming = false;
        }
    }

    function evaluate(): void {
        const left = remaining.value;

        if (left === null || ended || signingOut) {
            return;
        }

        if (left <= 0) {
            if (!confirming) {
                void confirmEnd();
            }

            return;
        }

        if (left > warnWindow()) {
            if (open.value) {
                open.value = false;
            }

            resetAnnouncements();

            return;
        }

        open.value = true;

        // One announcement per tick at most: the latest mark crossed. Marks above it are marked as spoken so
        // joining mid-countdown does not read 2:00, 1:00 and 0:30 together.
        const crossed = ANNOUNCE_MARKS.filter((mark) => left <= mark);
        const latest = crossed.length > 0 ? Math.min(...crossed) : null;

        if (latest !== null && !announced.has(latest)) {
            crossed.forEach((mark) => announced.add(mark));

            const spoken = latest - left <= MARK_TOLERANCE_S ? latest : left;

            announce(
                options.message(formatCountdown(spoken)),
                'once',
                `session-warning-${latest}`,
            );
        }
    }

    async function extend(): Promise<void> {
        if (extending.value) {
            return;
        }

        extending.value = true;
        error.value = false;
        // Anything already in flight started before this extend and must not win.
        sequence += 1;

        try {
            const status = await extendSession();

            sequence += 1;
            resetAnnouncements();
            // The extend answer is the whole limit.
            knownLimit = status.remaining_seconds;
            apply(status);
            open.value = false;
        } catch (e) {
            if (e instanceof SessionEnded) {
                end();
            } else {
                // The dialog stays open and shows the error.
                error.value = true;
            }
        } finally {
            extending.value = false;
        }
    }

    function signOut(): void {
        signingOut = true;
        open.value = false;
        stopTicker();
        clearFormDrafts();
        router.flushAll();

        const failed = (): void => {
            // Not signed out after all: the countdown resumes.
            signingOut = false;
            startTicker();
            evaluate();
        };

        router.post(
            '/logout',
            {},
            {
                onHttpException: (response) => {
                    if (response.status === 401 || response.status === 419) {
                        // Already signed out, or the token went stale with the session.
                        end();

                        return false;
                    }

                    failed();
                },
                onNetworkError: failed,
            },
        );
    }

    function onVisible(): void {
        if (document.visibilityState === 'visible') {
            void sync();
        }
    }

    watch(open, (value) => {
        if (!value) {
            error.value = false;
        }
    });

    onMounted(() => {
        void sync();
        startTicker();
        poller = setInterval(() => {
            if (!open.value) {
                void sync();
            }
        }, POLL_MS);
        document.addEventListener('visibilitychange', onVisible);

        offs = [
            // A user-initiated visit resets the server's clock: read it again.
            router.on('success', () => void sync()),
            // A 401 from any page request means the session ended while the tab sat idle.
            router.on('httpException', (event) => {
                if (event.detail.response.status === 401) {
                    event.preventDefault();
                    end();
                }
            }),
        ];
    });

    onBeforeUnmount(() => {
        unmounted = true;
        stopTicker();
        if (poller) clearInterval(poller);
        if (retry) clearTimeout(retry);
        document.removeEventListener('visibilitychange', onVisible);
        offs.forEach((off) => off());
        offs = [];
    });

    return { open, remaining, time, error, extending, extend, signOut };
}
