<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import WorkspaceSwitcher from '@/components/WorkspaceSwitcher.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
} from '@/components/ui/sidebar';
import { useShell } from '@/composables/useShell';
import { shellLabels } from '@/locales/labels';

// The shell's sidebar (UX-DR-79): full at 1280px and wider, the 64px icon rail at tablet widths, the overlay
// sheet below 640px.
const { items } = useShell();
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
            <WorkspaceSwitcher />

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
