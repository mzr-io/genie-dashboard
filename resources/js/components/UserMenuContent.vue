<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { LogOut, Settings } from '@lucide/vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { useShell } from '@/composables/useShell';
import { useSignOut } from '@/composables/useSignOut';
import { shellLabels } from '@/locales/labels';
import type { User } from '@/types';

// The profile menu (Story 1.16): name and role for the active Workspace, Profile & settings, Sign out.
const props = defineProps<{
    user: User;
    // Runs after the person navigated or signed out (the mobile sheet closes).
    close?: () => void;
}>();

const { shell, roleLabel } = useShell();
const { signOut } = useSignOut(
    () => shell.value?.sign_out_href ?? '/logout',
    () => props.close?.(),
);

// Profile & settings is a User-area page whichever area the person is in.
const profileHref = () => shell.value?.profile_href ?? '/settings/profile';

function detail(): string | null {
    const workspace = shell.value?.workspace?.name;

    if (roleLabel.value && workspace) {
        return shellLabels.roleIn(roleLabel.value, workspace);
    }

    return roleLabel.value;
}
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal" data-slot="profile-menu-header">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :detail="detail()" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link
                class="block w-full cursor-pointer"
                :href="profileHref()"
                data-test="profile-menu-settings"
                @click="close?.()"
            >
                <Settings class="mr-2 h-4 w-4" aria-hidden="true" />
                {{ shellLabels.profileSettings }}
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem
        class="cursor-pointer"
        data-test="profile-menu-sign-out"
        @select="signOut"
    >
        <LogOut class="mr-2 h-4 w-4" aria-hidden="true" />
        {{ shellLabels.signOut }}
    </DropdownMenuItem>
</template>
