import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { clearFormDrafts } from '@/lib/formDrafts';
import { shellLabels } from '@/locales/labels';
import { useToasts } from '@/stores/toasts';

// Sign out is a POST (Story 1.16): the server rotates then destroys the session and lands on sign-in. The
// form-draft store and the page cache are cleared only once the sign-out succeeded, so a failed request
// loses nothing: the shell resumes with an error toast and no Inertia error modal. `onSignedOut` runs
// after success (the mobile sheet closes).
export function useSignOut(
    url: () => string = () => '/logout',
    onSignedOut?: () => void,
) {
    const signingOut = ref(false);

    function signOut(): void {
        if (signingOut.value) {
            return;
        }

        signingOut.value = true;

        const failed = (): false => {
            useToasts().add({
                kind: 'error',
                message: shellLabels.signOutFailed,
            });

            return false;
        };

        router.post(
            url(),
            {},
            {
                onSuccess: () => {
                    clearFormDrafts();
                    router.flushAll();
                    onSignedOut?.();
                },
                onHttpException: (response) => {
                    // The session was already over: the sign-in page is where this ends up.
                    if (response.status === 401 || response.status === 419) {
                        window.location.assign('/login');

                        return false;
                    }

                    return failed();
                },
                onNetworkError: () => failed(),
                onError: () => {
                    failed();
                },
                onFinish: () => {
                    signingOut.value = false;
                },
            },
        );
    }

    return { signOut, signingOut };
}
