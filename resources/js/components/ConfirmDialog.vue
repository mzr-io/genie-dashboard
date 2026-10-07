<script setup lang="ts">
import { computed } from 'vue';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { useDialogSlot } from '@/composables/useDialogSlot';
import { overlayLabels } from '@/locales/labels';

// Destructive confirmation (UX-DR-271): alertdialog, labelled by the title and described by the
// impact, initial focus on Cancel, Esc cancels, the destructive button repeats the object name.
const props = defineProps<{
    open: boolean;
    title: string;
    // The impact, e.g. msg:access-impact.
    description: string;
    // The thing being changed; the destructive button reads "<verb> <object>".
    objectName: string;
    verb?: string;
    // Focus returns here when the dialog closes; defaults to the element focused at open.
    invoker?: HTMLElement | null;
    // The invoker's row, used when the invoker is gone.
    fallback?: () => HTMLElement | null | undefined;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'confirm'): void;
    (e: 'cancel'): void;
    (e: 'refused'): void;
}>();

const { isOpen, restoreFocus } = useDialogSlot({
    open: () => props.open,
    onRefused: () => {
        emit('update:open', false);
        emit('refused');
    },
    invoker: () => props.invoker,
    fallback: () => props.fallback,
});

const shown = computed(() => props.open && isOpen());

const confirmLabel = computed(() =>
    props.verb === undefined
        ? overlayLabels.deleteAction(props.objectName)
        : `${props.verb} ${props.objectName}`,
);

let confirmed = false;

function onOpenChange(value: boolean): void {
    // The action button also closes the dialog; that is a confirmation, not a cancel.
    if (!value) {
        // Wait a tick: the action's own click handler may run after this one.
        queueMicrotask(() => {
            if (!confirmed) {
                emit('cancel');
            }

            confirmed = false;
        });
    }

    emit('update:open', value);
}

function confirm(): void {
    confirmed = true;
    emit('confirm');
    emit('update:open', false);
}

function onCloseAutoFocus(event: Event): void {
    event.preventDefault();
    restoreFocus();
}
</script>

<template>
    <AlertDialog :open="shown" @update:open="onOpenChange">
        <AlertDialogContent @close-auto-focus="onCloseAutoFocus">
            <AlertDialogTitle class="type-title-md">
                {{ title }}
            </AlertDialogTitle>
            <AlertDialogDescription class="type-body-sm text-text-secondary">
                {{ description }}
            </AlertDialogDescription>
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <AlertDialogCancel as-child>
                    <Button variant="secondary">{{
                        overlayLabels.cancel
                    }}</Button>
                </AlertDialogCancel>
                <AlertDialogAction as-child>
                    <Button variant="destructive" @click="confirm">
                        {{ confirmLabel }}
                    </Button>
                </AlertDialogAction>
            </div>
        </AlertDialogContent>
    </AlertDialog>
</template>
