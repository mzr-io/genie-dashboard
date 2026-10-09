<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Banner from '@/components/Banner.vue';
import { Button } from '@/components/ui/button';
import type { LockMode } from '@/composables/useEditLock';
import type { EditLockLabelSet } from '@/locales/labels';

// The soft lock's banner (Story 2.8, shared with the Draft wizard in Story 3.6): who is editing, "Take over editing", Retry and Close.
defineProps<{
    mode: LockMode;
    notice: string | null;
    takeoverFailed: boolean;
    labels: EditLockLabelSet;
    closeHref: string;
}>();

defineEmits<{ takeOver: []; retry: [] }>();
</script>

<template>
    <section
        v-if="notice"
        role="status"
        aria-live="polite"
        class="grid max-w-2xl gap-3"
        :data-mode="mode"
        data-test="lock-banner"
    >
        <Banner :variant="mode === 'blocked' ? 'info' : 'warning'">
            <span data-test="lock-notice">{{ notice }}</span>
        </Banner>
        <div
            v-if="
                mode === 'blocked' || mode === 'ended' || mode === 'unavailable'
            "
            class="flex flex-wrap items-center gap-3"
        >
            <Button
                v-if="mode === 'blocked'"
                type="button"
                variant="secondary"
                data-test="take-over"
                @click="$emit('takeOver')"
            >
                {{ labels.takeOver }}
            </Button>
            <Button
                v-if="mode === 'unavailable'"
                type="button"
                variant="secondary"
                data-test="lock-retry"
                @click="$emit('retry')"
            >
                {{ labels.retry }}
            </Button>
            <Button as-child variant="secondary">
                <Link :href="closeHref" data-test="lock-close">{{
                    labels.close
                }}</Link>
            </Button>
        </div>
        <p
            v-if="takeoverFailed"
            role="alert"
            class="type-body-sm text-error-text"
            data-test="takeover-failed"
        >
            {{ labels.takeOverFailed }}
        </p>
    </section>
</template>
