<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { invitationLabels as labels } from '@/locales/labels';

defineOptions({
    layout: {
        title: labels.expiredTitle,
        description: labels.expiredDescription,
    },
});

const { t } = useI18n();
</script>

<template>
    <Head :title="labels.expiredTitle" />

    <div class="grid gap-4 text-center">
        <p role="alert" data-test="invitation-expired">
            {{ t('reset-expired') }}
        </p>
        <Link href="/login" class="text-sm underline underline-offset-4">{{
            labels.goToSignIn
        }}</Link>
    </div>
</template>
