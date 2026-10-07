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

        // A switch to a Workspace where the person is only a User: msg:workspace-role lands them on the User
        // Overview. The name is plain text in a text node, never markup.
        const role = flash?.workspace_role as
            | { workspace?: string }
            | undefined;

        if (role?.workspace) {
            useToasts().add({
                kind: 'info',
                // A function replacer: `$&`, `$1` and `$'` in a name stay literal.
                message: en['workspace-role'].replace(
                    '{workspace}',
                    () => role.workspace as string,
                ),
            });
        }

        if (!data) {
            return;
        }

        useToasts().add({ kind: data.type, message: data.message });
    });
}
