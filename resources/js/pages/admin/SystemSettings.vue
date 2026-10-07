<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '@/components/PageHeader.vue';
import { hostAllowlist } from '@/routes/admin/settings';
import { settingsLabels as labels } from '@/locales/labels';

// System settings (Story 2.1): the Admin item that groups the workspace-wide settings. For now it links to the Host
// allowlist; later settings join it here. The page is reached with `settings.manage`.
</script>

<template>
    <Head :title="labels.pageTitle" />

    <div class="flex flex-col gap-6 px-4 py-6 sm:px-7">
        <PageHeader :title="labels.pageTitle" :subtitle="labels.pageSubtitle" />

        <nav :aria-label="labels.sections">
            <ul class="flex flex-col gap-3">
                <li>
                    <Link
                        :href="hostAllowlist().url"
                        class="flex flex-col gap-1 rounded-lg border border-border-default bg-surface-card p-4 hover:border-border-control"
                        data-test="open-host-allowlist"
                    >
                        <span class="type-title-sm text-text-primary">{{
                            labels.hostAllowlist
                        }}</span>
                        <span class="type-body-sm text-text-secondary">{{
                            labels.hostAllowlistSummary
                        }}</span>
                    </Link>
                </li>
            </ul>
        </nav>
    </div>
</template>
