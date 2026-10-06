<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import Banner from '@/components/Banner.vue';

// The five banners of UX-DR-138, each with its catalogue message.
defineProps<{
    preset:
        | 'reconnecting'
        | 'offline-editing'
        | 'api-changed'
        | 'live-shape-mismatch'
        | 'map-small-screen';
    // api-changed: the field that disappeared and the slot that becomes Unavailable.
    field?: string;
    slotName?: string;
    returnFocus?: () => HTMLElement | null | undefined;
}>();

const { t } = useI18n();
</script>

<template>
    <Banner
        v-if="preset === 'reconnecting'"
        variant="info"
        data-preset="reconnecting"
    >
        {{ t('reconnecting') }}
    </Banner>
    <Banner
        v-else-if="preset === 'offline-editing'"
        variant="info"
        data-preset="offline-editing"
    >
        {{ t('offline-editing') }}
    </Banner>
    <Banner
        v-else-if="preset === 'api-changed'"
        variant="warning"
        data-preset="api-changed"
    >
        {{ t('api-changed', { field: field ?? '', slot: slotName ?? '' }) }}
    </Banner>
    <Banner
        v-else-if="preset === 'live-shape-mismatch'"
        variant="error"
        data-preset="live-shape-mismatch"
    >
        {{ t('live-shape-unreachable') }}
    </Banner>
    <Banner
        v-else
        variant="info"
        dismissible
        data-preset="map-small-screen"
        :return-focus="returnFocus"
    >
        {{ t('map-small-screen') }}
    </Banner>
</template>
