import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { clearFormDrafts } from '@/lib/formDrafts';
import { shellLabels } from '@/locales/labels';
import { useToasts } from '@/stores/toasts';

// Switching is a POST with the Workspace ID in the body (Story 1.17). The server checks the membership
// itself: a 403 (forged, stale or deactivated) leaves the person where they are with an error toast and no
// Inertia error modal. The draft store and the page cache belong to the old Workspace and go on success.
export function useWorkspaceSwitch(url: () => string, onSwitched?: () => void) {
    const switching = ref(false);

    function switchTo(workspaceId: string): void {
        if (switching.value) {
            return;
        }

        switching.value = true;

        const failed = (): false => {
            useToasts().add({
                kind: 'error',
                message: shellLabels.switchFailed,
            });

            return false;
        };

        router.post(
            url(),
            { workspace_id: workspaceId },
            {
                onSuccess: () => {
                    clearFormDrafts();
                    router.flushAll();
                    onSwitched?.();
                },
                onHttpException: (response) => {
                    if (response.status === 401 || response.status === 419) {
                        window.location.assign('/login');

                        return false;
                    }

                    if (response.status === 403) {
                        // The refused Workspace may still be in the list: read the shell again.
                        router.reload({ only: ['shell'] });
                    }

                    return failed();
                },
                onNetworkError: () => failed(),
                onError: () => {
                    failed();
                },
                onFinish: () => {
                    switching.value = false;
                },
            },
        );
    }

    return { switchTo, switching };
}
