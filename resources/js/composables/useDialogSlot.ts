import { onBeforeUnmount, ref, toValue, watch } from 'vue';
import type { MaybeRefOrGetter } from 'vue';
import { useDialogGuard } from '@/stores/dialogs';

type Options = {
    open: MaybeRefOrGetter<boolean>;
    // Called when the open request is refused because another dialog is open.
    onRefused: () => void;
    invoker?: MaybeRefOrGetter<HTMLElement | null | undefined>;
    // The row (or other stable element) that takes focus when the invoker is gone.
    fallback?: MaybeRefOrGetter<
        HTMLElement | null | undefined | (() => HTMLElement | null | undefined)
    >;
};

// One dialog at a time (UX-DR-271) and focus return (UX-DR-267): the invoker is remembered
// when the dialog opens; on close focus goes back to it or, when it is gone, to the fallback.
export function useDialogSlot(options: Options) {
    const guard = useDialogGuard();
    const token = ref<string | null>(null);
    let invoker: HTMLElement | null = null;

    watch(
        () => toValue(options.open),
        (open) => {
            if (open) {
                token.value = guard.claim();

                if (token.value === null) {
                    options.onRefused();

                    return;
                }

                invoker =
                    toValue(options.invoker) ??
                    (document.activeElement as HTMLElement | null);
            } else if (token.value !== null) {
                guard.release(token.value);
                token.value = null;
            }
        },
        { immediate: true, flush: 'sync' },
    );

    onBeforeUnmount(() => guard.release(token.value));

    function restoreFocus(): void {
        const fallback = toValue(options.fallback);
        const row = typeof fallback === 'function' ? fallback() : fallback;
        const target = invoker?.isConnected
            ? invoker
            : row?.isConnected
              ? row
              : null;

        invoker = null;
        (target ?? document.getElementById('main-content'))?.focus();
    }

    return { isOpen: () => token.value !== null, token, restoreFocus };
}
