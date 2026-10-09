<script setup lang="ts">
import { computed, nextTick, ref, useAttrs, useId, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDay } from '@/lib/formatDate';
import { controlLabels as labels } from '@/locales/labels';

// A write-only secret (UX-DR-29). A saved secret is never given to this component, so it
// cannot reach the DOM: only its saved date is shown. "Replace" clears the field and a new
// value is then required. Nothing here autosaves.
defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        modelValue?: string;
        savedAt?: string | Date | null;
        replacing?: boolean;
    }>(),
    // Without a default Vue reads an absent boolean as false, which would show "saved" for a secret that was never set.
    { replacing: undefined },
);

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
    (e: 'update:replacing', value: boolean): void;
}>();

const attrs = useAttrs();
const replacing = ref(props.replacing ?? !props.savedAt);
const readId = useId();
const input = ref<{ $el: HTMLInputElement } | null>(null);

watch(
    () => props.replacing,
    (value) => {
        if (value !== undefined) {
            replacing.value = value;
        }
    },
);

// Compared by timestamp, so a fresh Date for the same moment keeps a replacement in progress.
const savedTime = computed(() =>
    props.savedAt ? new Date(props.savedAt).getTime() : null,
);

watch(savedTime, (time, previous) => {
    if (time !== previous) {
        replacing.value = !time;
    }
});

const date = computed(() => (props.savedAt ? formatDay(props.savedAt) : ''));

async function replace(): Promise<void> {
    replacing.value = true;
    emit('update:modelValue', '');
    emit('update:replacing', true);
    await nextTick();
    input.value?.$el.focus();
}
</script>

<template>
    <div
        v-if="!replacing"
        :id="attrs.id as string | undefined"
        role="group"
        :aria-labelledby="readId"
        :aria-describedby="attrs['aria-describedby'] as string | undefined"
        :aria-invalid="attrs['aria-invalid'] as 'true' | 'false' | undefined"
        data-slot="secret-field"
        data-state="saved"
        class="flex min-h-9 items-center gap-2 text-sm text-text-secondary"
    >
        <span aria-hidden="true"
            >{{ labels.secretMask }} · {{ labels.secretSetShort(date) }} ·</span
        >
        <span :id="readId" class="sr-only">{{ labels.secretSetOn(date) }}</span>
        <Button
            type="button"
            variant="link"
            :aria-label="labels.replaceToken"
            @click="replace"
        >
            {{ labels.replace }}
        </Button>
    </div>
    <Input
        v-else
        ref="input"
        v-bind="attrs"
        type="password"
        autocomplete="new-password"
        required
        data-slot="secret-field"
        data-state="replacing"
        :model-value="modelValue"
        @update:model-value="emit('update:modelValue', String($event))"
    />
</template>
