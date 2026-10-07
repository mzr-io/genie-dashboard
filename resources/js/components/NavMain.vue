<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Lock } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useShell } from '@/composables/useShell';
import { announce } from '@/lib/announce';

// The area's navigation (UX-DR-79, 159). An item the Admin lacks the permission for stays in the list,
// focusable and `aria-disabled`, with msg:perm-denied as its description: it is never hidden (UX-DR-22).
const { t } = useI18n();
const { items, current, sectionLabel } = useShell();
const { setOpenMobile } = useSidebar();

const labelId = 'shell-section-label';

const itemClass =
    'type-title-sm h-9 text-text-secondary hover:text-text-primary data-[active=true]:text-text-primary group-data-[collapsible=icon]:[&>svg]:size-5';

function blocked(event: Event): void {
    event.preventDefault();
    announce(t('perm-denied'));
}
</script>

<template>
    <SidebarGroup class="px-2 py-3" :aria-labelledby="labelId">
        <SidebarGroupLabel
            :id="labelId"
            class="type-label-caps text-text-muted"
            >{{ sectionLabel }}</SidebarGroupLabel
        >
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.key">
                <SidebarMenuButton
                    v-if="item.allowed"
                    as-child
                    :is-active="current?.key === item.key"
                    :tooltip="item.title"
                    :class="itemClass"
                >
                    <Link
                        :href="item.href"
                        :aria-current="
                            current?.key === item.key ? 'page' : undefined
                        "
                        :data-nav-item="item.key"
                        @click="setOpenMobile(false)"
                    >
                        <component :is="item.icon" aria-hidden="true" />
                        <span class="truncate">{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
                <template v-else>
                    <SidebarMenuButton
                        type="button"
                        aria-disabled="true"
                        :aria-describedby="`shell-reason-${item.key}`"
                        :aria-current="
                            current?.key === item.key ? 'page' : undefined
                        "
                        :is-active="current?.key === item.key"
                        :tooltip="`${item.title}. ${t('perm-denied')}`"
                        :data-nav-item="item.key"
                        :class="`${itemClass} aria-disabled:pointer-events-auto aria-disabled:cursor-not-allowed aria-disabled:opacity-45`"
                        @click="blocked"
                    >
                        <component :is="item.icon" aria-hidden="true" />
                        <span class="truncate">{{ item.title }}</span>
                        <Lock
                            class="ml-auto size-3.5! group-data-[collapsible=icon]:hidden"
                            aria-hidden="true"
                        />
                    </SidebarMenuButton>
                    <span :id="`shell-reason-${item.key}`" class="sr-only">{{
                        t('perm-denied')
                    }}</span>
                </template>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
