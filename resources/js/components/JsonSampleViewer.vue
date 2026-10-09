<script setup lang="ts">
import { ref, useId } from 'vue';
import { Button } from '@/components/ui/button';
import { copyText } from '@/lib/clipboard';
import { endpointLabels as labels } from '@/locales/labels';

// The Sample Response of an Endpoint test (Story 2.10; UX-DR-25): the body in a mono `<pre>` inside a focusable
// `role="region"` with an accessible name, so a keyboard user can scroll it. The text is shown exactly as it was received
// (no re-formatting, so every number keeps its lexeme) and is only ever rendered as text. The status and latency sit above it,
// and Copy puts the body on the clipboard. Nothing here stores the body.
defineProps<{ body: string; status: number; latencyMs: number }>();

const headingId = useId();
const copied = ref<'idle' | 'copied' | 'failed'>('idle');

async function copy(text: string): Promise<void> {
    copied.value = (await copyText(text)) ? 'copied' : 'failed';
}
</script>

<template>
    <section
        :aria-labelledby="headingId"
        data-test="sample-viewer"
        class="grid gap-2"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="grid gap-0.5">
                <h3
                    :id="headingId"
                    class="type-title-sm text-text-primary"
                    data-test="sample-heading"
                >
                    {{ labels.sampleHeading }}
                </h3>
                <p
                    class="type-caption text-text-secondary tabular-nums"
                    data-test="sample-meta"
                >
                    {{ labels.sampleStatus(status, latencyMs) }}
                </p>
            </div>
            <div class="flex items-center gap-3">
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    data-test="sample-copy"
                    @click="copy(body)"
                >
                    {{ labels.sampleCopy }}
                </Button>
                <span
                    role="status"
                    class="type-caption text-text-secondary"
                    data-test="sample-copy-status"
                >
                    <template v-if="copied === 'copied'">{{
                        labels.sampleCopied
                    }}</template>
                    <template v-else-if="copied === 'failed'">{{
                        labels.sampleCopyFailed
                    }}</template>
                </span>
            </div>
        </div>
        <div
            role="region"
            tabindex="0"
            :aria-label="labels.sampleRegion"
            data-slot="json-viewer"
            data-test="sample-region"
            class="max-h-[28rem] overflow-auto rounded-md border border-border-default bg-surface-sunken p-3 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
            <pre
                class="font-mono text-sm break-words whitespace-pre-wrap text-text-primary"
                data-test="sample-body"
                >{{ body }}</pre>
        </div>
    </section>
</template>
