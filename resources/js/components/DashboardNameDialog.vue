<script setup lang="ts">
import { computed, nextTick, ref, useId, watch } from 'vue';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useDialogSlot } from '@/composables/useDialogSlot';
import { manageDashboardLabels as labels } from '@/locales/labels';

// The name of a dashboard (Story 4.16): "New dashboard" and "Rename". Empty or over-long is refused here at once and by the server as the field
// error; the field keeps what was typed. Enter submits, Escape closes and focus goes back to what opened it.
const props = defineProps<{
    open: boolean;
    mode: 'create' | 'rename';
    name?: string;
    busy?: boolean;
    // The server refused the name (422 `dashboards.invalid_name`).
    refused?: boolean;
    invoker?: HTMLElement | null;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'submit', name: string): void;
}>();

const value = ref('');
const touched = ref(false);
const inputId = useId();

const { isOpen, restoreFocus } = useDialogSlot({
    open: () => props.open,
    onRefused: () => emit('update:open', false),
    invoker: () => props.invoker,
});

const shown = computed(() => props.open && isOpen());
const problem = computed(() => {
    const text = value.value.trim();
    const invalid = text === '' || text.length > 120;

    return (touched.value && invalid) || props.refused
        ? labels.nameInvalid
        : null;
});

watch(
    () => props.open,
    (opened) => {
        if (opened) {
            value.value = props.mode === 'rename' ? (props.name ?? '') : '';
            touched.value = false;
        }
    },
    { immediate: true },
);

function submit(): void {
    touched.value = true;
    const text = value.value.trim();

    if (text === '' || text.length > 120 || props.busy) {
        return;
    }

    emit('submit', text);
}

async function onOpenAutoFocus(event: Event): Promise<void> {
    event.preventDefault();
    await nextTick();
    const input = document.getElementById(inputId) as HTMLInputElement | null;

    input?.focus();
    input?.select();
}

function onCloseAutoFocus(event: Event): void {
    event.preventDefault();
    restoreFocus();
}
</script>

<template>
    <Dialog :open="shown" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent
            :show-close-button="false"
            data-slot="dashboard-name-dialog"
            @open-auto-focus="onOpenAutoFocus"
            @close-auto-focus="onCloseAutoFocus"
        >
            <DialogTitle class="type-title-md">
                {{
                    mode === 'create' ? labels.createTitle : labels.renameTitle
                }}
            </DialogTitle>
            <DialogDescription class="sr-only">{{
                labels.nameHelper
            }}</DialogDescription>
            <form class="grid gap-4" novalidate @submit.prevent="submit">
                <FormField
                    :id="inputId"
                    :label="labels.nameLabel"
                    :helper="labels.nameHelper"
                    :error="problem"
                    required
                >
                    <template #default="{ field }">
                        <Input
                            v-bind="field"
                            v-model="value"
                            type="text"
                            maxlength="120"
                            autocomplete="off"
                            data-test="dashboard-name"
                            @input="touched = true"
                        />
                    </template>
                </FormField>
                <div
                    class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"
                >
                    <Button
                        type="button"
                        variant="secondary"
                        data-test="name-cancel"
                        @click="emit('update:open', false)"
                    >
                        {{ labels.cancel }}
                    </Button>
                    <Button
                        type="submit"
                        :aria-busy="busy ? 'true' : undefined"
                        data-test="name-submit"
                    >
                        {{ mode === 'create' ? labels.create : labels.save }}
                    </Button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
