<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Ellipsis } from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import UserInfo from '@/components/UserInfo.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useShell } from '@/composables/useShell';
import { shellLabels } from '@/locales/labels';

// The user row: circle avatar, name, role and ⋯. It opens the profile menu; Esc closes it and focus
// returns here (UX-DR-267).
const page = usePage();
const user = computed(() => page.props.auth.user);
const { roleLabel } = useShell();
const { isMobile, state, setOpenMobile } = useSidebar();
</script>

<template>
    <SidebarMenu v-if="user">
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton
                        size="lg"
                        class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                        :aria-label="shellLabels.profileMenu(user.name)"
                        data-test="sidebar-menu-button"
                    >
                        <UserInfo :user="user" :detail="roleLabel" />
                        <Ellipsis
                            class="ml-auto size-4 group-data-[collapsible=icon]:hidden"
                            aria-hidden="true"
                        />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="w-(--reka-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                    :side="
                        isMobile
                            ? 'bottom'
                            : state === 'collapsed'
                              ? 'right'
                              : 'top'
                    "
                    align="end"
                    :side-offset="4"
                >
                    <UserMenuContent
                        :user="user"
                        :close="() => setOpenMobile(false)"
                    />
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
