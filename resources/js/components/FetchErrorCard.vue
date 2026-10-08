<script setup lang="ts">
import { ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import TechnicalDetails from '@/components/TechnicalDetails.vue';
import { Button } from '@/components/ui/button';
import { copyText } from '@/lib/clipboard';
import { formatBytes } from '@/lib/format';
import {
    dataSourceLabels,
    shellLabels,
    technicalDetailsLabels,
} from '@/locales/labels';

// The error card of a failed call to a source (Story 2.5, 2.6; UX-DR-135, 70). It tells the Admin one thing (the
// catalogue's `host-not-allowlisted`, `blocked-address`, `fetch-failed`, `not-json` or `response-too-large`, and `auth-failed` from the labels) and keeps the rest behind "Technical details"
// (status, host, request ID, the collapsed reason code): never a resolved address, which the server never sends. Retry runs
// the test again; Copy request ID copies the ID for support. Focus moves to the title when the card appears (`focus()`).
const props = defineProps<{
    code:
        | 'host-not-allowlisted'
        | 'blocked-address'
        | 'fetch-failed'
        | 'not-json'
        | 'response-too-large'
        | 'auth-failed';
    // The name of the source, for `fetch-failed`.
    source: string;
    // The bytes read and the limit, for `response-too-large`.
    sizeBytes?: number | null;
    limitBytes?: number | null;
    status?: number | null;
    requestId?: string | null;
    host?: string | null;
    reason?: string | null;
}>();

const emit = defineEmits<{ (e: 'retry'): void }>();

const { t } = useI18n();
const titleId = useId();
const title = ref<HTMLElement | null>(null);
const copied = ref<'idle' | 'copied' | 'failed'>('idle');

// The catalogue messages end with their own "Technical details" cue: the card renders the disclosure itself.
function message(): string {
    // Story 2.7: the canonical catalogue is closed to EXPERIENCE.md's rows, which have none for this, so it is a label.
    if (props.code === 'auth-failed') {
        return dataSourceLabels.authFailed(props.source);
    }

    return t(props.code, {
        source: props.source,
        size: formatBytes(props.sizeBytes ?? 0),
        limit: formatBytes(props.limitBytes ?? 0),
    }).replace(/\s*▸.*$/u, '');
}

async function copyId(): Promise<void> {
    copied.value = (await copyText(props.requestId ?? ''))
        ? 'copied'
        : 'failed';
}

defineExpose({ focus: () => title.value?.focus() });
</script>

<template>
    <section
        role="group"
        :aria-labelledby="titleId"
        data-test="fetch-error-card"
        class="grid gap-3 rounded-md border-l-[3px] border-error bg-error-soft p-4"
    >
        <h3
            :id="titleId"
            ref="title"
            tabindex="-1"
            data-test="fetch-error-title"
            class="type-title-sm text-error-text focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
            {{ dataSourceLabels.testFailedTitle }}
        </h3>
        <p class="type-body-sm text-error-text" data-test="fetch-error-message">
            {{ message() }}
        </p>
        <TechnicalDetails
            area="admin"
            :status="status"
            :host="host"
            :reason="reason"
            :request-id="requestId"
            :copy="false"
        />
        <div class="flex flex-wrap items-center gap-3">
            <Button
                type="button"
                variant="secondary"
                size="sm"
                data-test="fetch-retry"
                @click="emit('retry')"
            >
                {{ shellLabels.retry }}
            </Button>
            <Button
                v-if="requestId"
                type="button"
                variant="secondary"
                size="sm"
                data-test="copy-request-id"
                @click="copyId"
            >
                {{ technicalDetailsLabels.copy }}
            </Button>
            <span role="status" class="type-caption text-text-secondary">
                <template v-if="copied === 'copied'">{{
                    technicalDetailsLabels.copied
                }}</template>
                <template v-else-if="copied === 'failed'">{{
                    technicalDetailsLabels.copyFailed
                }}</template>
            </span>
        </div>
    </section>
</template>
