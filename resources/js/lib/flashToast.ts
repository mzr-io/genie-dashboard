import { router } from '@inertiajs/vue3';
import { useToasts } from '@/stores/toasts';
import type { FlashToast } from '@/types/ui';

export function initializeFlashToast(): void {
    router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash;
        const data = flash?.toast as FlashToast | undefined;

        if (!data) {
            return;
        }

        useToasts().add({ kind: data.type, message: data.message });
    });
}
