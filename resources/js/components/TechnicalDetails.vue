<script setup lang="ts">
import { ChevronRight } from '@lucide/vue';
import { computed, ref, useId } from 'vue';
import { Button } from '@/components/ui/button';
import { copyText } from '@/lib/clipboard';
import { technicalDetailsLabels as labels } from '@/locales/labels';

// Admin-only error details (UX-DR-162). Any other area renders nothing, so the User area never
// holds an HTTP status, field path or request ID in its DOM.
type Props = {
    area: 'admin' | 'user';
    status?: number | null;
    path?: string | null;
    requestId?: string | null;
    // A failed call to a source (Story 2.5): the host that was called and the collapsed reason code.
    host?: string | null;
    reason?: string | null;
    // Whether the panel has its own Copy request ID button (a card that has its own turns it off).
    copy?: boolean;
};

const props = withDefaults(defineProps<Props>(), { copy: true });

// Each row shows only when it has a value; with none at all there is nothing to disclose.
const hasStatus = computed(
    () => props.status !== undefined && props.status !== null,
);
const hasPath = computed(() => !!props.path);
const hasRequestId = computed(() => !!props.requestId);
const hasHost = computed(() => !!props.host);
const hasReason = computed(() => !!props.reason);
const hasDetails = computed(
    () =>
        hasStatus.value ||
        hasPath.value ||
        hasRequestId.value ||
        hasHost.value ||
        hasReason.value,
);

const open = ref(false);
const copyState = ref<'idle' | 'copied' | 'failed'>('idle');
const panelId = useId();

async function copyId(requestId: string): Promise<void> {
    // On failure the request ID stays visible in the panel, so it can be selected by hand.
    copyState.value = (await copyText(requestId)) ? 'copied' : 'failed';
}
</script>

<template>
    <div v-if="area === 'admin' && hasDetails" class="text-sm">
        <button
            type="button"
            class="inline-flex items-center gap-1 rounded-sm font-medium text-text-secondary underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            :aria-expanded="open"
            :aria-controls="panelId"
            @click="open = !open"
        >
            <ChevronRight
                class="size-4 transition-transform"
                :class="{ 'rotate-90': open }"
                aria-hidden="true"
            />
            {{ labels.title }}
        </button>
        <div
            :id="panelId"
            :hidden="!open"
            class="mt-2 rounded-md border border-border-default bg-surface-sunken p-3"
        >
            <dl class="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1">
                <template v-if="hasStatus">
                    <dt class="text-text-muted">{{ labels.status }}</dt>
                    <dd data-field="status" class="font-mono">{{ status }}</dd>
                </template>
                <template v-if="hasPath">
                    <dt class="text-text-muted">{{ labels.path }}</dt>
                    <dd data-field="path" class="font-mono break-all">
                        {{ path }}
                    </dd>
                </template>
                <template v-if="hasHost">
                    <dt class="text-text-muted">{{ labels.host }}</dt>
                    <dd data-field="host" class="font-mono break-all">
                        {{ host }}
                    </dd>
                </template>
                <template v-if="hasReason">
                    <dt class="text-text-muted">{{ labels.reason }}</dt>
                    <dd data-field="reason" class="font-mono break-all">
                        {{ reason }}
                    </dd>
                </template>
                <template v-if="hasRequestId">
                    <dt class="text-text-muted">{{ labels.requestId }}</dt>
                    <dd data-field="request-id" class="font-mono break-all">
                        {{ requestId }}
                    </dd>
                </template>
            </dl>
            <div
                v-if="hasRequestId && copy"
                class="mt-2 flex items-center gap-3"
            >
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    @click="copyId(requestId as string)"
                >
                    {{ labels.copy }}
                </Button>
                <span role="status" class="text-text-muted">
                    <template v-if="copyState === 'copied'">{{
                        labels.copied
                    }}</template>
                    <template v-else-if="copyState === 'failed'">{{
                        labels.copyFailed
                    }}</template>
                </span>
            </div>
        </div>
    </div>
</template>
