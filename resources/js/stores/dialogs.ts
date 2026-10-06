import { defineStore } from 'pinia';
import { ref } from 'vue';

// Dialogs never stack more than one deep (UX-DR-271): a second open is refused and the first
// stays. Each dialog claims the slot while open and gives it back on close.
export const useDialogGuard = defineStore('dialogs', () => {
    const current = ref<string | null>(null);
    let counter = 0;

    function claim(): string | null {
        if (current.value !== null) {
            return null;
        }

        counter += 1;
        current.value = `dialog-${counter}`;

        return current.value;
    }

    function release(token: string | null): void {
        if (token !== null && current.value === token) {
            current.value = null;
        }
    }

    return { current, claim, release };
});
