<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CircleHelp, LogOut } from '@lucide/vue';
import {
    SidebarGroup,
    SidebarGroupContent,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useShell } from '@/composables/useShell';
import { useSignOut } from '@/composables/useSignOut';
import { shellLabels } from '@/locales/labels';

// Help & support and Sign out (UX-DR-79); no theme control is rendered in the MVP (UX-DR-286).
const { shell, current } = useShell();
const { setOpenMobile } = useSidebar();
const { signOut, signingOut } = useSignOut(
    () => shell.value?.sign_out_href ?? '/logout',
    () => setOpenMobile(false),
);

const itemClass =
    'type-title-sm h-9 text-text-secondary hover:text-text-primary data-[active=true]:text-text-primary group-data-[collapsible=icon]:[&>svg]:size-5';
</script>

<template>
    <SidebarGroup class="p-0 group-data-[collapsible=icon]:p-0">
        <SidebarGroupContent>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        as-child
                        :tooltip="shellLabels.help"
                        :is-active="current?.key === 'help'"
                        :class="itemClass"
                    >
                        <Link
                            :href="shell?.help_href ?? '/help'"
                            data-test="footer-help"
                            @click="setOpenMobile(false)"
                        >
                            <CircleHelp aria-hidden="true" />
                            <span class="truncate">{{ shellLabels.help }}</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        type="button"
                        :tooltip="shellLabels.signOut"
                        :class="itemClass"
                        data-test="sign-out-button"
                        :aria-disabled="signingOut ? 'true' : undefined"
                        @click="signOut"
                    >
                        <LogOut aria-hidden="true" />
                        <span class="truncate">{{ shellLabels.signOut }}</span>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarGroupContent>
    </SidebarGroup>
</template>
