import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useBlockedAction } from '@/composables/useBlockedAction';
import { useConnection } from '@/composables/useConnection';

// The offline helper (UX-DR-251): Save, Fetch, Test and Publish bind `blocked` and `reason`
// to a Button (`:blocked`, `:blocked-reason`) and point aria-describedby at a BlockedReason.
// Back online clears the block, so the controls restore on their own.
export function useOfflineAction() {
    const { t } = useI18n();
    const { offline } = useConnection();
    const reason = computed(() =>
        offline.value ? t('offline-editing') : undefined,
    );
    const { guard } = useBlockedAction({ blocked: offline, reason });

    return { offline, reason, guard };
}
