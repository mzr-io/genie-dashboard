import { usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, watchEffect } from 'vue';
import { configureFormatting } from '@/lib/format';
import { installShortcuts, setSingleKeyShortcuts } from '@/lib/shortcuts';
import type { User } from '@/types';

// Applies the signed-in person's saved settings to the client (Story 1.18): the locale and time zone behind
// `lib/format.ts` and the Keyboard shortcuts switch behind `lib/shortcuts.ts`. It runs in the app layout, so
// a saved profile takes effect as soon as the page props change.
export function useUserPreferences(): void {
    const page = usePage();

    watchEffect(() => {
        const user = (page.props.auth as { user: User | null } | undefined)
            ?.user;

        configureFormatting({
            locale: user?.locale,
            timeZone: user?.timezone,
        });
        // Default On: only an explicit false turns the single-key shortcuts off.
        setSingleKeyShortcuts(user?.keyboard_shortcuts !== false);
    });

    let stop: (() => void) | null = null;

    onMounted(() => {
        stop = installShortcuts();
    });
    onBeforeUnmount(() => {
        stop?.();
        // Nothing of one person's settings outlives the layout (a sign-out must not leave them behind).
        configureFormatting({});
        setSingleKeyShortcuts(true);
    });
}
