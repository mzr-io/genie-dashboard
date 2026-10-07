<script setup lang="ts">
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import BlockedReason from '@/components/BlockedReason.vue';
import { Button } from '@/components/ui/button';
import { useShell } from '@/composables/useShell';
import type { ButtonVariants } from '@/components/ui/button';

// An action the person may not do (Story 1.19; UX-DR-22, 272): it stays rendered, focusable and
// `aria-disabled`, with its reason inline (`perm-denied` unless `reason` says otherwise, for example
// `perm-publish` for Publish), and runs nothing. It is fed by the `can` map of the shared `shell` prop.
// Draft actions that need no permission simply do not use this component and stay enabled. The server
// still refuses the request: this only explains it.
const props = defineProps<{
    // The permission key the action needs (`blocks.publish`).
    permission: string;
    // Overrides the inline reason (the catalogue message already translated).
    reason?: string;
    variant?: ButtonVariants['variant'];
}>();

const emit = defineEmits<{ (e: 'click', event: MouseEvent): void }>();

const { t } = useI18n();
const { can } = useShell();
const reasonId = useId();

const allowed = computed(() => can.value[props.permission] === true);
const text = computed(() => props.reason || t('perm-denied'));
</script>

<template>
    <span class="inline-flex flex-col items-start gap-1">
        <Button
            type="button"
            :variant="variant"
            :blocked="!allowed"
            :blocked-reason="text"
            :aria-describedby="allowed ? undefined : reasonId"
            data-slot="gated-action"
            @click="emit('click', $event)"
        >
            <slot />
        </Button>
        <BlockedReason v-if="!allowed" :id="reasonId">{{ text }}</BlockedReason>
    </span>
</template>
