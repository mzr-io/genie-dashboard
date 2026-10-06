import { router } from '@inertiajs/vue3';
import en from '@/locales/en';
import { useToasts } from '@/stores/toasts';
import type { FlashToast } from '@/types/ui';

export function initializeFlashToast(): void {
    router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash;
        const data = flash?.toast as FlashToast | undefined;

        // Signed back in after the session idled out (Story 1.15): msg:session-expired.
        if (flash?.session_expired) {
            useToasts().add({
                kind: 'info',
                message: en['session-expired'],
            });
        }

        if (!data) {
            return;
        }

        useToasts().add({ kind: data.type, message: data.message });
    });
}
