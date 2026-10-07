<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import ListStates from '@/components/ListStates.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { useShell } from '@/composables/useShell';
import { announce } from '@/lib/announce';
import { shellLabels, shellPages } from '@/locales/labels';
import type { ShellPageKey } from '@/locales/labels';

// The placeholder every navigation target of the two-area shell routes to (Story 1.16): the page title,
// the `msg:page-subtitles` text where one exists and the generic empty state. The features replace it
// story by story.
const props = defineProps<{ page: ShellPageKey }>();

const { t } = useI18n();
const { items } = useShell();

const config = computed(() => shellPages[props.page]);

const subtitle = computed(() =>
    props.page === 'overview'
        ? t('page-subtitles.overview')
        : props.page === 'admin-overview'
          ? t('page-subtitles.admin')
          : undefined,
);

// Admin overview's primary action is "+ Create block" (UX-DR-86); without the permission it is disabled
// with its reason, not hidden.
const createBlock = computed(
    () => items.value.find((item) => item.key === 'create-block') ?? null,
);

function blocked(event: Event): void {
    event.preventDefault();
    announce(t('perm-denied'));
}
</script>

<template>
    <Head :title="config.title" />

    <div class="flex flex-col gap-6 px-4 py-6 sm:px-7">
        <PageHeader :title="config.title" :subtitle="subtitle">
            <template v-if="page === 'admin-overview' && createBlock">
                <Button v-if="createBlock.allowed" as-child>
                    <Link :href="createBlock.href">{{
                        shellLabels.createBlock
                    }}</Link>
                </Button>
                <Button
                    v-else
                    type="button"
                    aria-disabled="true"
                    aria-describedby="create-block-reason"
                    @click="blocked"
                >
                    {{ shellLabels.createBlock }}
                </Button>
                <span
                    v-if="!createBlock.allowed"
                    id="create-block-reason"
                    class="sr-only"
                    >{{ t('perm-denied') }}</span
                >
            </template>
        </PageHeader>

        <ListStates
            state="empty"
            :items="config.items"
            :action="config.action"
        />
    </div>
</template>
