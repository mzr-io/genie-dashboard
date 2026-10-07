import { onScopeDispose, ref, toValue, watch } from 'vue';
import type { MaybeRefOrGetter } from 'vue';

export const STILL_LOADING_MS = 5000;

// True once `active` has stayed on for `ms` (UX-DR-36): shimmer stops and a caption appears.
export function useStillLoading(
    active: MaybeRefOrGetter<boolean>,
    ms: number = STILL_LOADING_MS,
) {
    const stalled = ref(false);
    let timer: ReturnType<typeof setTimeout> | undefined;

    function clear() {
        if (timer !== undefined) {
            clearTimeout(timer);
            timer = undefined;
        }
    }

    watch(
        () => toValue(active),
        (on) => {
            clear();
            stalled.value = false;

            if (on) {
                timer = setTimeout(() => {
                    stalled.value = true;
                }, ms);
            }
        },
        { immediate: true },
    );

    onScopeDispose(clear);

    return { stalled };
}
