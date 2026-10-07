import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { currentItem, navItems } from '@/lib/shell';
import { shellLabels } from '@/locales/labels';
import type { Shell } from '@/types/auth';

// The shell's navigation model for the page being shown, with titles and icons filled in.
export function useShell() {
    const page = usePage();

    const shell = computed<Shell | null>(
        () => (page.props.shell as Shell | null | undefined) ?? null,
    );
    const area = computed(() => shell.value?.area ?? 'user');
    // Permission key => held in the active Admin area (Story 1.19); empty when nothing is known.
    const can = computed<Record<string, boolean>>(() => shell.value?.can ?? {});
    // The person's own membership ID from the server; null when unknown (the editor then fails closed).
    const membershipId = computed<string | null>(
        () => shell.value?.membership_id ?? null,
    );
    const items = computed(() => navItems(shell.value?.items ?? []));
    const path = computed(
        () =>
            new URL(
                page.url,
                typeof window !== 'undefined'
                    ? window.location.origin
                    : 'http://localhost',
            ).pathname,
    );
    // Help & support and Profile & settings are User-area pages the Admin area links to as well, so the
    // current item is resolved against them too.
    const known = computed(() => {
        const extras = navItems(
            [
                { key: 'profile', href: shell.value?.profile_href },
                { key: 'help', href: shell.value?.help_href },
            ]
                .filter(
                    (extra) =>
                        extra.href &&
                        !items.value.some((item) => item.key === extra.key),
                )
                .map((extra) => ({
                    key: extra.key,
                    href: extra.href as string,
                    permission: null,
                    allowed: true,
                })),
        );

        return [...items.value, ...extras];
    });
    const current = computed(() => currentItem(known.value, path.value));
    const sectionLabel = computed(() =>
        area.value === 'admin'
            ? shellLabels.sectionAdmin
            : shellLabels.sectionUser,
    );
    // The gear opens Profile & settings in the User area and System settings in the Admin area.
    const settingsItem = computed(
        () =>
            items.value.find(
                (item) =>
                    item.key ===
                    (area.value === 'admin' ? 'system-settings' : 'profile'),
            ) ?? null,
    );
    const roleLabel = computed(() => {
        const role = shell.value?.role;

        return role === 'admin'
            ? shellLabels.roleAdmin
            : role === 'user'
              ? shellLabels.roleUser
              : null;
    });

    return {
        shell,
        area,
        can,
        membershipId,
        items,
        current,
        sectionLabel,
        settingsItem,
        roleLabel,
    };
}
