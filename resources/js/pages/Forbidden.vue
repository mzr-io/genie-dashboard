<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { shellLabels } from '@/locales/labels';
import { overview } from '@/routes';

// The 403 page of the Admin gate (Story 1.19): `msg:perm-denied` and a way back, and nothing of the page
// that was asked for (the server sends no props for it).
const { t } = useI18n();
</script>

<template>
    <Head :title="shellLabels.forbiddenTitle" />

    <div class="flex flex-col gap-6 px-4 py-6 sm:px-7">
        <PageHeader :title="shellLabels.forbiddenTitle" />
        <p
            role="alert"
            data-slot="perm-denied"
            class="type-body text-text-secondary"
        >
            {{ t('perm-denied') }}
        </p>
        <div>
            <Button as-child variant="secondary">
                <Link :href="overview.url()">{{
                    shellLabels.forbiddenBack
                }}</Link>
            </Button>
        </div>
    </div>
</template>
