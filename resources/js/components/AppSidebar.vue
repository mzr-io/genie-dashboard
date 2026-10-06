<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
} from '@/components/ui/sidebar';
import { useInitials } from '@/composables/useInitials';
import { useShell } from '@/composables/useShell';
import { shellLabels } from '@/locales/labels';

// The shell's sidebar (UX-DR-79): full at 1280px and wider, the 64px icon rail at tablet widths, the overlay
// sheet below 640px. The Workspace switcher is Story 1.17; this slot shows the current Workspace name only.
const { shell, items } = useShell();
const { getInitials } = useInitials();

const workspace = computed(() => shell.value?.workspace ?? null);
const home = computed(() => items.value[0]?.href ?? '/dashboard');
</script>

<template>
    <Sidebar collapsible="icon" variant="sidebar">
        <SidebarHeader
            class="h-16 shrink-0 flex-row items-center border-b border-border-default px-4 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:px-0"
        >
            <Link
                :href="home"
                :aria-label="shellLabels.product"
                class="flex min-w-0 flex-1 items-center gap-1 rounded-md group-data-[collapsible=icon]:flex-none"
            >
                <AppLogo />
            </Link>
        </SidebarHeader>

        <div class="flex min-h-0 flex-1 flex-col">
            <div
                v-if="workspace"
                data-slot="workspace-slot"
                class="mx-2 mt-3 flex items-center gap-2 rounded-lg border border-border-default bg-surface-sunken p-2 group-data-[collapsible=icon]:mx-auto group-data-[collapsible=icon]:size-10 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:p-1"
            >
                <!-- Workspace avatars are rounded squares, user avatars circles (UX-DR-9). -->
                <span
                    aria-hidden="true"
                    class="type-caption inline-flex size-[30px] shrink-0 items-center justify-center rounded-md bg-brand font-bold text-on-accent"
                    >{{ getInitials(workspace.name) }}</span
                >
                <span
                    class="grid min-w-0 flex-1 text-left leading-tight group-data-[collapsible=icon]:sr-only"
                >
                    <span class="type-caption text-text-muted">{{
                        shellLabels.workspace
                    }}</span>
                    <span
                        class="type-title-sm truncate text-text-primary"
                        data-slot="workspace-name"
                        >{{ workspace.name }}</span
                    >
                </span>
            </div>

            <SidebarContent>
                <!-- The navigation landmark wraps the items only, not the Workspace card, footer or user menu. -->
                <nav :aria-label="shellLabels.navigation">
                    <NavMain />
                </nav>
            </SidebarContent>

            <SidebarFooter class="border-t border-border-default">
                <NavFooter />
                <NavUser />
            </SidebarFooter>
        </div>
    </Sidebar>
    <slot />
</template>
