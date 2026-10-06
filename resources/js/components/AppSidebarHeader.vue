<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Bell, Menu, Search, Settings } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import TopbarPlaceholder from '@/components/TopbarPlaceholder.vue';
import { Button } from '@/components/ui/button';
import { useSidebar } from '@/components/ui/sidebar';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useShell } from '@/composables/useShell';
import { announce } from '@/lib/announce';
import { toUrl } from '@/lib/utils';
import { shellLabels } from '@/locales/labels';
import type { BreadcrumbItem } from '@/types';

const props = withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

// The top bar (UX-DR-83, 85): a 64px `banner` with the back button and breadcrumb, then the ⌘K search and
// the notifications bell (disabled placeholders until Epic 8) and the settings gear.
const { t } = useI18n();
const { isMobile, setOpenMobile } = useSidebar();
const { settingsItem } = useShell();

// The back button goes one level up: the breadcrumb before the current page.
function parent(): BreadcrumbItem | null {
    return props.breadcrumbs.length > 1
        ? props.breadcrumbs[props.breadcrumbs.length - 2]
        : null;
}

function gearBlocked(event: Event): void {
    event.preventDefault();
    announce(t('perm-denied'));
}
</script>

<template>
    <header
        data-slot="top-bar"
        class="sticky top-0 z-20 flex h-(--df-topbar-height) shrink-0 items-center gap-2 border-b border-border-default bg-surface-card px-4 sm:px-7"
    >
        <Button
            v-if="isMobile"
            type="button"
            variant="ghost"
            size="icon-lg"
            class="-ml-2 size-(--df-target-touch)"
            data-test="open-navigation"
            @click="setOpenMobile(true)"
        >
            <Menu class="size-5" aria-hidden="true" />
            <span class="sr-only">{{ shellLabels.openNavigation }}</span>
        </Button>

        <Button
            v-if="parent()"
            variant="secondary"
            size="icon-sm"
            as-child
            class="size-8 min-h-8"
        >
            <Link
                :href="parent()!.href"
                :aria-label="shellLabels.back"
                data-test="back-button"
            >
                <ArrowLeft
                    class="size-4 text-text-secondary"
                    aria-hidden="true"
                />
            </Link>
        </Button>

        <div class="min-w-0 flex-1 truncate">
            <Breadcrumbs
                v-if="breadcrumbs.length > 0"
                :breadcrumbs="breadcrumbs"
            />
        </div>

        <div class="flex shrink-0 items-center gap-1">
            <TopbarPlaceholder
                id="topbar-search"
                :icon="Search"
                :label="shellLabels.search"
                :shortcut="shellLabels.searchShortcut"
                :reason="shellLabels.searchReason"
            />
            <TopbarPlaceholder
                id="topbar-notifications"
                :icon="Bell"
                :label="shellLabels.notifications"
                :reason="shellLabels.notificationsReason"
            />

            <template v-if="settingsItem">
                <Tooltip v-if="settingsItem.allowed">
                    <TooltipTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            as-child
                            class="size-9 text-text-muted"
                        >
                            <Link
                                :href="toUrl(settingsItem.href)!"
                                :aria-label="shellLabels.settings"
                                data-test="topbar-settings"
                            >
                                <Settings
                                    class="size-[18px]"
                                    aria-hidden="true"
                                />
                            </Link>
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>{{ shellLabels.settings }}</TooltipContent>
                </Tooltip>
                <template v-else>
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="size-9 text-text-muted aria-disabled:pointer-events-auto"
                                aria-disabled="true"
                                aria-describedby="topbar-settings-reason"
                                data-test="topbar-settings"
                                @click="gearBlocked"
                            >
                                <Settings
                                    class="size-[18px]"
                                    aria-hidden="true"
                                />
                                <span class="sr-only">{{
                                    shellLabels.settings
                                }}</span>
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>{{
                            `${shellLabels.settings}. ${t('perm-denied')}`
                        }}</TooltipContent>
                    </Tooltip>
                    <span id="topbar-settings-reason" class="sr-only">{{
                        t('perm-denied')
                    }}</span>
                </template>
            </template>
        </div>
    </header>
</template>
