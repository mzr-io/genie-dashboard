import { toValue } from 'vue';
import type { MaybeRefOrGetter } from 'vue';
import { announce } from '@/lib/announce';

type Options = {
    blocked: MaybeRefOrGetter<boolean>;
    reason: MaybeRefOrGetter<string | undefined>;
    // The first blocking control; defaults to the blocked control itself.
    firstBlocker?: () => HTMLElement | null | undefined;
};

// Blocked controls stay focusable and keep aria-disabled (UX-DR-22, 272). Activating one runs
// nothing: it focuses the first blocker and announces the reason. `guard` returns true when
// the action may run.
export function useBlockedAction(options: Options) {
    function guard(event: Event): boolean {
        if (!toValue(options.blocked)) {
            return true;
        }

        event.preventDefault();
        event.stopPropagation();

        const target =
            options.firstBlocker?.() ??
            (event.currentTarget as HTMLElement | null);

        target?.focus();

        const reason = toValue(options.reason);

        if (reason) {
            announce(reason);
        }

        return false;
    }

    return { guard };
}
