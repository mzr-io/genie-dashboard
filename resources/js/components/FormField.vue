<script setup lang="ts">
import { CircleAlert } from '@lucide/vue';
import { computed, useId } from 'vue';
import { Label } from '@/components/ui/label';
import { controlLabels as labels } from '@/locales/labels';

// Label above, helper below, error with a leading icon and a hidden "Error:" (UX-DR-23).
// The control receives `field` from the slot: v-bind it to the input.
type Props = {
    label: string;
    id?: string;
    helper?: string;
    error?: string | null;
    required?: boolean;
};

const props = defineProps<Props>();

const auto = useId();
const fieldId = computed(() => props.id ?? auto);
const helperId = computed(() => `${fieldId.value}-helper`);
const errorId = computed(() => `${fieldId.value}-error`);

const describedBy = computed(
    () =>
        [
            props.helper ? helperId.value : null,
            props.error ? errorId.value : null,
        ]
            .filter(Boolean)
            .join(' ') || undefined,
);

const field = computed(() => ({
    id: fieldId.value,
    required: props.required || undefined,
    'aria-invalid': props.error ? ('true' as const) : undefined,
    'aria-describedby': describedBy.value,
}));
</script>

<template>
    <div data-slot="form-field" class="grid gap-1.5">
        <Label :for="fieldId">
            {{ label }}
            <span
                v-if="required"
                aria-hidden="true"
                class="text-error-text"
                data-slot="required-marker"
                >*</span
            >
        </Label>
        <slot :field="field" />
        <p v-if="helper" :id="helperId" class="type-caption text-text-muted">
            {{ helper }}
        </p>
        <p
            v-if="error"
            :id="errorId"
            data-slot="field-error"
            class="type-caption flex items-start gap-1 text-error-text"
        >
            <CircleAlert class="mt-px size-3.5 shrink-0" aria-hidden="true" />
            <span
                ><span class="sr-only">{{ labels.errorPrefix }} </span
                >{{ error }}</span
            >
        </p>
    </div>
</template>
