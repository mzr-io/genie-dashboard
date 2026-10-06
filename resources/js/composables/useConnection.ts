import { computed, getCurrentScope, onScopeDispose, ref, watch } from 'vue';
import { announce, DEBOUNCE_AGGREGATE_MS, resetOnce } from '@/lib/announce';

// One shared view of the browser connection (UX-DR-251, 252).
const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine);
let listeners = 0;

function goOffline(): void {
    online.value = false;
}

function goOnline(): void {
    online.value = true;
}

export function useConnection() {
    if (typeof window !== 'undefined' && getCurrentScope()) {
        if (listeners === 0) {
            online.value = navigator.onLine;
            window.addEventListener('offline', goOffline);
            window.addEventListener('online', goOnline);
        }

        listeners += 1;

        onScopeDispose(() => {
            listeners -= 1;

            if (listeners === 0) {
                window.removeEventListener('offline', goOffline);
                window.removeEventListener('online', goOnline);
            }
        });
    }

    return { online, offline: computed(() => !online.value) };
}

// Dashboard shell (UX-DR-252): transitions settle for ~2 s before anything is spoken, so a flap
// (offline, online, offline, online inside the window) is silent. `reconnecting` is spoken once
// per settled outage and `back-online` once per settled recovery.
export function useConnectionAnnouncements(messages: {
    reconnecting: string;
    backOnline: string;
}) {
    const { online: isOnline, offline } = useConnection();
    let spokenDown = !isOnline.value;
    let timer: ReturnType<typeof setTimeout> | undefined;

    function settle(): void {
        if (!isOnline.value && !spokenDown) {
            spokenDown = true;
            resetOnce('reconnecting');
            announce(messages.reconnecting, 'once', 'reconnecting');
        } else if (isOnline.value && spokenDown) {
            spokenDown = false;
            resetOnce('back-online');
            announce(messages.backOnline, 'once', 'back-online');
        }
    }

    const stop = watch(isOnline, () => {
        clearTimeout(timer);
        timer = setTimeout(settle, DEBOUNCE_AGGREGATE_MS);
    });

    if (getCurrentScope()) {
        onScopeDispose(() => {
            stop();
            clearTimeout(timer);
        });
    }

    return { online: isOnline, offline };
}

// Test hook: put the shared state back to a known value.
export function setConnectionForTest(value: boolean): void {
    online.value = value;
}
