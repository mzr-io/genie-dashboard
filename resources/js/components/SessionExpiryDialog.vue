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
import { useSessionExpiry } from '@/composables/useSessionExpiry';
import { sessionLabels } from '@/locales/labels';

// The session-expiry warning (UX-DR-248): msg:session-warning with a live countdown, "Stay signed in"
// (primary, initial focus) and "Sign out". It cannot be dismissed without a choice. The countdown is
// announced by the shared announcer at 2:00, 1:00 and 0:30, so the visible text is not a live region.
const { t } = useI18n();
const stayButton = ref<InstanceType<typeof Button> | null>(null);

const { open, time, error, extending, extend, signOut } = useSessionExpiry({
    message: (value) => t('session-warning.announce', { time: value }),
});

const { isOpen, restoreFocus } = useDialogSlot({
    open: () => open.value,
    // Another dialog is open: the warning waits and tries again on the next tick.
    onRefused: () => {
        open.value = false;
    },
});

const shown = computed(() => open.value && isOpen());

function onOpenAutoFocus(event: Event): void {
    event.preventDefault();
    (stayButton.value?.$el as HTMLElement | undefined)?.focus();
}

function onCloseAutoFocus(event: Event): void {
    event.preventDefault();
    restoreFocus();
}
</script>

<template>
    <Dialog :open="shown">
        <DialogContent
            :show-close-button="false"
            data-session-warning
            @open-auto-focus="onOpenAutoFocus"
            @close-auto-focus="onCloseAutoFocus"
            @escape-key-down.prevent
            @pointer-down-outside.prevent
            @interact-outside.prevent
        >
            <DialogTitle>{{ sessionLabels.title }}</DialogTitle>
            <DialogDescription data-session-countdown>
                {{ t('session-warning.visible', { time }) }}
            </DialogDescription>
            <p v-if="error" role="alert" class="type-body-sm text-error-text">
                {{ sessionLabels.extendFailed }}
            </p>
            <DialogFooter>
                <Button variant="secondary" @click="signOut">
                    {{ sessionLabels.signOut }}
                </Button>
                <Button ref="stayButton" :disabled="extending" @click="extend">
                    {{ sessionLabels.stay }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
