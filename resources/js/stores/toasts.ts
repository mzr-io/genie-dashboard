import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { announce } from '@/lib/announce';

export type ToastKind =
    | 'success'
    | 'info'
    | 'warning'
    | 'error'
    | 'action'
    | 'rollback';

export type ToastAction = {
    label: string;
    // Business logic (Undo, Show me) stays with the caller.
    run: () => void;
};

export type ToastInput = {
    kind?: ToastKind;
    message: string;
    // Screen-reader text; defaults to the message. Action toasts name the Undo route here.
    announce?: string;
    actions?: ToastAction[];
};

export type ToastItem = Required<Pick<ToastInput, 'message'>> & {
    id: number;
    kind: ToastKind;
    announce: string;
    actions: ToastAction[];
};

// Action toasts stay at least 10 s (UX-DR-280); plain success and info toasts carry no action
// and stay at least 5 s. Error and rollback toasts never auto-dismiss.
export const ACTION_TOAST_MS = 10_000;
export const PLAIN_TOAST_MS = 5_000;

export function isPersistent(kind: ToastKind): boolean {
    return kind === 'error' || kind === 'rollback';
}

export const useToasts = defineStore('toasts', () => {
    const items = ref<ToastItem[]>([]);
    const timers = new Map<number, ReturnType<typeof setTimeout>>();
    const holds = new Map<number, Set<string>>();
    let nextId = 1;

    // Sheets that host their own toast region (mobile): the layout region steps aside.
    const sheetHosts = ref(0);

    const visible = computed(() => items.value.length > 0);

    function duration(toast: ToastItem): number {
        return toast.actions.length > 0 || toast.kind === 'action'
            ? ACTION_TOAST_MS
            : PLAIN_TOAST_MS;
    }

    function clearTimer(id: number): void {
        const timer = timers.get(id);

        if (timer !== undefined) {
            clearTimeout(timer);
            timers.delete(id);
        }
    }

    function arm(toast: ToastItem): void {
        clearTimer(toast.id);

        if (isPersistent(toast.kind) || (holds.get(toast.id)?.size ?? 0) > 0) {
            return;
        }

        timers.set(
            toast.id,
            setTimeout(() => dismiss(toast.id), duration(toast)),
        );
    }

    function add(input: ToastInput): number {
        const kind = input.kind ?? 'info';
        const toast: ToastItem = {
            id: nextId++,
            kind,
            message: input.message,
            announce: input.announce ?? input.message,
            actions: input.actions ?? [],
        };

        items.value.push(toast);
        arm(toast);

        // Error and rollback toasts carry role="alert" themselves; the rest are spoken politely.
        if (!isPersistent(kind)) {
            announce(toast.announce, 'polite');
        }

        return toast.id;
    }

    function dismiss(id: number): void {
        clearTimer(id);
        holds.delete(id);
        items.value = items.value.filter((toast) => toast.id !== id);
    }

    // Hover and focus each hold a toast open; the timer restarts in full when the last hold ends.
    function hold(id: number, reason: string): void {
        const set = holds.get(id) ?? new Set<string>();

        set.add(reason);
        holds.set(id, set);
        clearTimer(id);
    }

    function release(id: number, reason: string): void {
        const set = holds.get(id);

        if (!set) {
            return;
        }

        set.delete(reason);

        const toast = items.value.find((item) => item.id === id);

        if (toast && set.size === 0) {
            arm(toast);
        }
    }

    function clear(): void {
        for (const timer of timers.values()) {
            clearTimeout(timer);
        }

        timers.clear();

        holds.clear();
        items.value = [];
    }

    return { items, visible, sheetHosts, add, dismiss, hold, release, clear };
});
