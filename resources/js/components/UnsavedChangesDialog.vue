<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { useDialogSlot } from '@/composables/useDialogSlot';
import { overlayLabels } from '@/locales/labels';

// Leaving with unsaved changes (UX-DR-253): msg:unsaved-changes with Save, Discard changes and
// Keep editing. Focus lands on the safe choice, Keep editing.
const props = withDefaults(
    defineProps<{
        open: boolean;
        // "Save draft" in the wizard; "Save" in generic forms.
        saveLabel?: string;
        invoker?: HTMLElement | null;
        fallback?: () => HTMLElement | null | undefined;
    }>(),
    { saveLabel: overlayLabels.save },
);

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'save'): void;
    (e: 'discard'): void;
    (e: 'keep'): void;
    (e: 'refused'): void;
}>();

const { t } = useI18n();
const keepButton = ref<InstanceType<typeof Button> | null>(null);

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

function keepEditing(): void {
    emit('keep');
    emit('update:open', false);
}

function onOpenChange(value: boolean): void {
    // Esc and the close button keep the person where they were.
    if (!value) {
        emit('keep');
    }

    emit('update:open', value);
}

function onOpenAutoFocus(event: Event): void {
    event.preventDefault();
    (keepButton.value?.$el as HTMLElement | undefined)?.focus();
}

function onCloseAutoFocus(event: Event): void {
    event.preventDefault();
    restoreFocus();
}
</script>

<template>
    <Dialog :open="shown" @update:open="onOpenChange">
        <DialogContent
            :show-close-button="false"
            @open-auto-focus="onOpenAutoFocus"
            @close-auto-focus="onCloseAutoFocus"
        >
            <DialogTitle>{{ overlayLabels.unsavedTitle }}</DialogTitle>
            <DialogDescription>
                {{ t('unsaved-changes') }}
            </DialogDescription>
            <DialogFooter>
                <Button
                    variant="secondary"
                    @click="
                        emit('discard');
                        emit('update:open', false);
                    "
                >
                    {{ overlayLabels.discardChanges }}
                </Button>
                <Button
                    ref="keepButton"
                    variant="secondary"
                    @click="keepEditing"
                >
                    {{ overlayLabels.keepEditing }}
                </Button>
                <Button
                    @click="
                        emit('save');
                        emit('update:open', false);
                    "
                >
                    {{ saveLabel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
