<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import BannerPreset from '@/components/BannerPreset.vue';
import {
    useConnection,
    useConnectionAnnouncements,
} from '@/composables/useConnection';

// Layout banner slot. `editing` (forms and admin lists) shows msg:offline-editing; `dashboard`
// shows msg:reconnecting and speaks reconnecting then back-online once each (UX-DR-251, 252).
const props = withDefaults(defineProps<{ mode?: 'editing' | 'dashboard' }>(), {
    mode: 'editing',
});

const { t } = useI18n();
const { offline } = useConnection();

if (props.mode === 'dashboard') {
    useConnectionAnnouncements({
        reconnecting: t('reconnecting'),
        backOnline: t('back-online'),
    });
}
</script>

<template>
    <div data-slot="banner-area" class="empty:hidden">
        <BannerPreset
            v-if="offline"
            :preset="mode === 'dashboard' ? 'reconnecting' : 'offline-editing'"
        />
    </div>
</template>
